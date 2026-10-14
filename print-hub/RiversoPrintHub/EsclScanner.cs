using System.Net;
using System.Text;
using System.Xml.Linq;

namespace RiversoPrintHub;

/// <summary>El escaneo no se pudo completar; Code es el motivo que entiende el servidor.</summary>
internal sealed class ScanException : Exception
{
    public string Code { get; }
    public string Detail { get; }

    public ScanException(string code, string detail = "") : base(detail.Length > 0 ? detail : code)
    {
        Code = code;
        Detail = detail;
    }
}

/// <summary>Páginas escaneadas (un JPEG por página) y si quedaron hojas por el tope.</summary>
internal sealed record ScanResult(List<byte[]> Pages, bool Truncated, bool FromFeeder);

/// <summary>
/// Escáner de red por eSCL (AirPrint/Mopria Scan): HTTP directo a la multifuncional, sin driver.
/// Se pide un JPEG por página para informar el avance y armar el PDF en el hub.
/// </summary>
internal sealed class EsclScanner : IDisposable
{
    private const string ScanNs = "http://schemas.hp.com/imaging/escl/2011/05/03";
    private const string PwgNs = "http://www.pwg.org/schemas/2010/12/sm";

    // eSCL mide en 1/300 de pulgada.
    private const int WidthUnits = 2550;      // 8.5"
    private const int LetterUnits = 3300;     // 11"
    private const int LegalUnits = 4200;      // 14"
    private const int PlatenMaxUnits = 3508;  // 11.69"

    private static readonly TimeSpan BusyLimit = TimeSpan.FromSeconds(60);

    private readonly HttpClient _http;
    private readonly string _root;

    public EsclScanner(string host)
    {
        _root = "http://" + host.Trim();
        // Una página a doble cara puede tardar bastante en salir del alimentador.
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(120) };
    }

    /// <summary>¿Hay un escáner eSCL en esta dirección? null si no responde o no lo es.</summary>
    public static async Task<ScannerCaps?> ProbeAsync(string host, CancellationToken ct)
    {
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(4) };
            using var resp = await http.GetAsync("http://" + host.Trim() + "/eSCL/ScannerCapabilities", ct);
            if (!resp.IsSuccessStatusCode)
            {
                return null;
            }
            var xml = XDocument.Parse(await resp.Content.ReadAsStringAsync(ct));
            if (xml.Root?.Name.LocalName != "ScannerCapabilities")
            {
                return null;
            }
            bool adf = xml.Descendants().Any(e => e.Name.LocalName == "AdfSimplexInputCaps");
            bool duplex = xml.Descendants().Any(e => e.Name.LocalName == "AdfDuplexInputCaps");
            return new ScannerCaps(adf, duplex);
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            return null;
        }
    }

    /// <summary>Estado general (Idle, Processing…) y del alimentador (ScannerAdfLoaded, ScannerAdfEmpty, ScannerAdfJam…).</summary>
    public async Task<(string State, string AdfState)> StatusAsync(CancellationToken ct)
    {
        using var resp = await _http.GetAsync(_root + "/eSCL/ScannerStatus", ct);
        resp.EnsureSuccessStatusCode();
        var xml = XDocument.Parse(await resp.Content.ReadAsStringAsync(ct));
        // El primer State es el del escáner; los siguientes son de trabajos anteriores.
        string state = xml.Root?.Elements().FirstOrDefault(e => e.Name.LocalName == "State")?.Value.Trim() ?? "";
        string adf = xml.Root?.Elements().FirstOrDefault(e => e.Name.LocalName == "AdfState")?.Value.Trim() ?? "";
        return (state, adf);
    }

    /// <summary>
    /// Escanea desde el alimentador si tiene hojas (o si se pide) y si no desde el vidrio.
    /// onPage recibe el número de cada página a medida que llega.
    /// </summary>
    public async Task<ScanResult> ScanAsync(JobSettings s, Func<int, Task> onPage, CancellationToken ct)
    {
        string state, adfState;
        try
        {
            (state, adfState) = await StatusAsync(ct);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException && !ct.IsCancellationRequested)
        {
            throw new ScanException("scanner_offline", "sin respuesta en " + _root[7..]);
        }
        if (state == "Processing")
        {
            throw new ScanException("scanner_busy");
        }
        if (adfState is "ScannerAdfJam" or "ScannerAdfMispick")
        {
            throw new ScanException("scanner_error", "papel atascado en el alimentador");
        }
        if (adfState == "ScannerAdfHatchOpen")
        {
            throw new ScanException("scanner_error", "tapa del alimentador abierta");
        }

        bool feeder = s.Fuente switch
        {
            "alimentador" => true,
            "vidrio" => false,
            _ => adfState == "ScannerAdfLoaded",
        };
        int maxPages = feeder ? Math.Clamp(s.MaxPaginas, 1, 100) : 1;
        string jobUrl = await StartJobAsync(BuildSettings(s, feeder), ct);
        var pages = new List<byte[]>();
        bool truncated = false;
        try
        {
            var waitingSince = DateTime.UtcNow;
            while (true)
            {
                using var resp = await _http.GetAsync(jobUrl + "/NextDocument", ct);
                if (resp.StatusCode is HttpStatusCode.NotFound or HttpStatusCode.Gone)
                {
                    break; // No quedan hojas.
                }
                if (resp.StatusCode == HttpStatusCode.ServiceUnavailable)
                {
                    // El escáner aún no tiene lista la página.
                    if (DateTime.UtcNow - waitingSince > BusyLimit)
                    {
                        throw Interrupted(pages.Count, "el escáner dejó de responder");
                    }
                    await Task.Delay(1000, ct);
                    continue;
                }
                if (!resp.IsSuccessStatusCode)
                {
                    throw Interrupted(pages.Count, await ProblemAsync(ct) ?? "HTTP " + (int)resp.StatusCode);
                }
                var bytes = await resp.Content.ReadAsByteArrayAsync(ct);
                if (bytes.Length == 0)
                {
                    break;
                }
                pages.Add(bytes);
                await onPage(pages.Count);
                waitingSince = DateTime.UtcNow;
                if (pages.Count >= maxPages)
                {
                    // En el vidrio hay una sola página; en el alimentador pueden quedar hojas.
                    truncated = feeder && (await SafeStatusAsync(ct)).AdfState == "ScannerAdfLoaded";
                    break;
                }
            }
        }
        catch (HttpRequestException ex)
        {
            await CancelJobAsync(jobUrl);
            throw Interrupted(pages.Count, ex.Message);
        }
        catch (TaskCanceledException) when (!ct.IsCancellationRequested)
        {
            await CancelJobAsync(jobUrl);
            throw Interrupted(pages.Count, "el escáner no respondió a tiempo");
        }
        catch
        {
            await CancelJobAsync(jobUrl);
            throw;
        }
        await CancelJobAsync(jobUrl);
        if (pages.Count == 0)
        {
            var problem = await ProblemAsync(ct);
            throw new ScanException(problem is null ? "scan_empty" : "scanner_error", problem ?? "");
        }
        return new ScanResult(pages, truncated, feeder);
    }

    private static ScanException Interrupted(int pagesDone, string why)
    {
        return new ScanException("scanner_error", pagesDone > 0 ? $"se interrumpió después de la página {pagesDone} ({why})" : why);
    }

    /// <summary>Motivo legible si el alimentador quedó con un problema; null si no hay ninguno.</summary>
    private async Task<string?> ProblemAsync(CancellationToken ct)
    {
        return (await SafeStatusAsync(ct)).AdfState switch
        {
            "ScannerAdfJam" or "ScannerAdfMispick" => "papel atascado en el alimentador",
            "ScannerAdfHatchOpen" => "tapa del alimentador abierta",
            "ScannerAdfDuplexPageTooShort" or "ScannerAdfDuplexPageTooLong" => "la hoja no sirve para doble cara",
            "ScannerAdfMultipickDetected" => "el alimentador tomó más de una hoja",
            _ => null,
        };
    }

    private async Task<(string State, string AdfState)> SafeStatusAsync(CancellationToken ct)
    {
        try
        {
            return await StatusAsync(ct);
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            return ("", "");
        }
    }

    private async Task<string> StartJobAsync(string settingsXml, CancellationToken ct)
    {
        var started = DateTime.UtcNow;
        while (true)
        {
            using var content = new StringContent(settingsXml, Encoding.UTF8, "text/xml");
            using var resp = await _http.PostAsync(_root + "/eSCL/ScanJobs", content, ct);
            if (resp.StatusCode == HttpStatusCode.Created && resp.Headers.Location is Uri location)
            {
                // La Canon responde con su nombre .local; se conserva solo la ruta y se usa la IP conocida.
                string path = location.IsAbsoluteUri ? location.AbsolutePath : location.OriginalString;
                return _root + "/" + path.TrimStart('/').TrimEnd('/');
            }
            if (resp.StatusCode == HttpStatusCode.ServiceUnavailable && DateTime.UtcNow - started < TimeSpan.FromSeconds(15))
            {
                await Task.Delay(1500, ct);
                continue;
            }
            if (resp.StatusCode == HttpStatusCode.ServiceUnavailable)
            {
                throw new ScanException("scanner_busy");
            }
            throw new ScanException("scanner_error", await ProblemAsync(ct) ?? "el escáner rechazó la orden (HTTP " + (int)resp.StatusCode + ")");
        }
    }

    private async Task CancelJobAsync(string jobUrl)
    {
        try
        {
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            using var resp = await _http.DeleteAsync(jobUrl, cts.Token);
        }
        catch
        {
            // Si el trabajo ya terminó, el escáner responde 404 o no responde: da lo mismo.
        }
    }

    private static string BuildSettings(JobSettings s, bool feeder)
    {
        int height = string.Equals(s.Papel, "oficio", StringComparison.OrdinalIgnoreCase) ? LegalUnits : LetterUnits;
        if (!feeder)
        {
            height = Math.Min(height, PlatenMaxUnits);
        }
        int dpi = s.Dpi is 75 or 100 or 150 or 200 or 300 or 600 ? s.Dpi : 200;
        XNamespace scan = ScanNs;
        XNamespace pwg = PwgNs;
        var root = new XElement(scan + "ScanSettings",
            new XAttribute(XNamespace.Xmlns + "scan", ScanNs),
            new XAttribute(XNamespace.Xmlns + "pwg", PwgNs),
            new XElement(pwg + "Version", "2.0"),
            new XElement(scan + "Intent", "Document"),
            new XElement(pwg + "ScanRegions",
                new XElement(pwg + "ScanRegion",
                    new XElement(pwg + "ContentRegionUnits", "escl:ThreeHundredthsOfInches"),
                    new XElement(pwg + "XOffset", 0),
                    new XElement(pwg + "YOffset", 0),
                    new XElement(pwg + "Width", WidthUnits),
                    new XElement(pwg + "Height", height))),
            new XElement(pwg + "InputSource", feeder ? "Feeder" : "Platen"),
            new XElement(scan + "ColorMode", s.Color ? "RGB24" : "Grayscale8"),
            new XElement(pwg + "DocumentFormat", "image/jpeg"),
            new XElement(scan + "DocumentFormatExt", "image/jpeg"),
            new XElement(scan + "XResolution", dpi),
            new XElement(scan + "YResolution", dpi));
        if (feeder)
        {
            root.Add(new XElement(scan + "Duplex", s.DosCaras ? "true" : "false"));
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>" + root.ToString(SaveOptions.DisableFormatting);
    }

    public void Dispose() => _http.Dispose();
}
