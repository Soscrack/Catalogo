using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace RiversoPrintHub;

internal sealed class PrinterReport
{
    public string Name { get; set; } = "";
    public string Driver { get; set; } = "";
    public string Port { get; set; } = "";
    /// <summary>listo | offline | error | desconocido</summary>
    public string Status { get; set; } = "desconocido";
    public string Detail { get; set; } = "";
    public List<string>? Papers { get; set; }
    public bool IsDefault { get; set; }
    public bool IsVirtual { get; set; }
    public string Host { get; set; } = "";
    /// <summary>Solo si la impresora también escanea por red (eSCL).</summary>
    public ScannerCaps? Scanner { get; set; }
    /// <summary>Hojas en el alimentador del escáner: con_hojas | vacio | «» si no se sabe.</summary>
    public string AdfState { get; set; } = "";
}

/// <summary>Lo que ofrece el escáner de una multifuncional: alimentador y doble cara.</summary>
internal sealed record ScannerCaps(bool Adf, bool Duplex);

internal sealed class PollRequest
{
    public string Hostname { get; set; } = Environment.MachineName;
    public string Version { get; set; } = HubApi.Version;
    public List<PrinterReport>? Printers { get; set; }
    public bool PrintersFull { get; set; }
    /// <summary>Solo probar conexión: el servidor no entrega trabajos.</summary>
    public bool Probe { get; set; }
}

internal sealed class JobSettings
{
    public string Printer { get; set; } = "";
    public string PrinterLabel { get; set; } = "";
    public string Preset { get; set; } = "";
    /// <summary>driver | escpos</summary>
    public string Modo { get; set; } = "driver";
    public string? Papel { get; set; }
    /// <summary>ajustar | porcentaje | real</summary>
    public string EscalaModo { get; set; } = "ajustar";
    public int EscalaPct { get; set; } = 100;
    public int Copias { get; set; } = 1;
    public bool Color { get; set; } = true;
    /// <summary>no | largo | corto</summary>
    public string Duplex { get; set; } = "no";
    /// <summary>auto | vertical | horizontal</summary>
    public string Orientacion { get; set; } = "auto";
    public int AnchoPuntos { get; set; } = 384;
    public int AvanceMm { get; set; }
    public int TimeoutS { get; set; } = 60;

    // Escaneo (kind = scan). Papel es carta | oficio y Color indica color o gris.
    /// <summary>auto | alimentador | vidrio</summary>
    public string Fuente { get; set; } = "auto";
    public bool DosCaras { get; set; }
    public int Dpi { get; set; } = 200;
    public int MaxPaginas { get; set; } = 15;
    public int MaxBytes { get; set; } = 12 * 1024 * 1024;
}

internal sealed class HubJob
{
    public long Id { get; set; }
    /// <summary>pdf | prueba | scan</summary>
    public string Kind { get; set; } = "pdf";
    public string Titulo { get; set; } = "";
    public JobSettings? Settings { get; set; }
}

internal sealed class PollAgent
{
    public long Id { get; set; }
    public string Nombre { get; set; } = "";
}

internal sealed class PollResponse
{
    public bool Ok { get; set; }
    public PollAgent? Agent { get; set; }
    public int PollMs { get; set; } = 2000;
    public Dictionary<string, string>? Hosts { get; set; }
    public HubJob? Job { get; set; }
}

internal sealed class FileResponse
{
    public bool Ok { get; set; }
    public string? Code { get; set; }
    public string? Message { get; set; }
    public string? PdfBase64 { get; set; }
}

/// <summary>El servidor rechazó el token (revocado o mal copiado).</summary>
internal sealed class HubAuthException : Exception
{
    public HubAuthException(string message) : base(message) { }
}

/// <summary>Resultado de pedir el PDF de un trabajo.</summary>
internal sealed record PdfFetch(byte[]? Bytes, string Code, string Message);

/// <summary>Cliente de la cola de impresión de riverso.cl (/wp-json/riverso/v1/print-hub/).</summary>
internal sealed class HubApi : IDisposable
{
    public static readonly string Version = typeof(HubApi).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";

    private static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString,
    };

    private readonly HttpClient _http;
    private readonly HttpClient _upload;
    private readonly string _base;

    public HubApi(string serverUrl, string token)
    {
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(40) };
        _http.DefaultRequestHeaders.Add("X-Riverso-Hub-Token", token.Trim());
        _http.DefaultRequestHeaders.UserAgent.ParseAdd("RiversoPrintHub/" + Version);
        _base = serverUrl.Trim().TrimEnd('/') + "/wp-json/riverso/v1/print-hub/";
        _upload = new HttpClient { Timeout = TimeSpan.FromMinutes(10) };
        _upload.DefaultRequestHeaders.Add("X-Riverso-Hub-Token", token.Trim());
        _upload.DefaultRequestHeaders.UserAgent.ParseAdd("RiversoPrintHub/" + Version);
    }

    public async Task<PollResponse> PollAsync(PollRequest req, CancellationToken ct)
    {
        using var resp = await _http.PostAsJsonAsync(_base + "poll", req, Json, ct);
        await EnsureAuth(resp);
        resp.EnsureSuccessStatusCode();
        return await resp.Content.ReadFromJsonAsync<PollResponse>(Json, ct) ?? new PollResponse();
    }

    public async Task<PdfFetch> GetPdfAsync(long jobId, CancellationToken ct)
    {
        using var resp = await _http.GetAsync(_base + "jobs/" + jobId + "/file", ct);
        await EnsureAuth(resp);
        FileResponse? body = null;
        try
        {
            body = await resp.Content.ReadFromJsonAsync<FileResponse>(Json, ct);
        }
        catch (JsonException)
        {
            // Respuesta no JSON (proxy, error de PHP): se informa por el código HTTP.
        }
        if (resp.IsSuccessStatusCode && body?.PdfBase64 is string b64 && b64.Length > 0)
        {
            return new PdfFetch(Convert.FromBase64String(b64), "ok", "");
        }
        var code = body?.Code ?? ("http_" + (int)resp.StatusCode);
        return new PdfFetch(null, code, body?.Message ?? resp.ReasonPhrase ?? "");
    }

    public async Task ReportAsync(long jobId, string estado, string detalle = "", string? errorCode = null, string? errorMsg = null)
    {
        try
        {
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var payload = new Dictionary<string, string?>
            {
                ["estado"] = estado,
                ["detalle"] = detalle,
                ["error_code"] = errorCode,
                ["error_msg"] = errorMsg,
            };
            using var resp = await _http.PostAsJsonAsync(_base + "jobs/" + jobId + "/status", payload, Json, cts.Token);
            if (!resp.IsSuccessStatusCode)
            {
                Log.Warn($"Estado del trabajo #{jobId} ({estado}) rechazado: HTTP {(int)resp.StatusCode}");
            }
        }
        catch (Exception ex)
        {
            Log.Warn($"No se pudo informar el estado del trabajo #{jobId} ({estado}): {ex.Message}");
        }
    }

    /// <summary>Sube el PDF escaneado. Devuelve el código de error del servidor, o «ok».</summary>
    public async Task<(string Code, string Message)> UploadScanAsync(long jobId, byte[] pdf, int pages, bool truncated, CancellationToken ct)
    {
        using var req = new HttpRequestMessage(HttpMethod.Post, _base + "jobs/" + jobId + "/scan");
        req.Content = new ByteArrayContent(pdf);
        req.Content.Headers.ContentType = new System.Net.Http.Headers.MediaTypeHeaderValue("application/pdf");
        req.Headers.Add("X-Riverso-Scan-Pages", pages.ToString());
        if (truncated)
        {
            req.Headers.Add("X-Riverso-Scan-Truncated", "1");
        }
        // Un PDF de varios MB por una conexión lenta no cabe en los 40 s del cliente normal.
        using var resp = await _upload.SendAsync(req, ct);
        await EnsureAuth(resp);
        FileResponse? body = null;
        try
        {
            body = await resp.Content.ReadFromJsonAsync<FileResponse>(Json, ct);
        }
        catch (JsonException)
        {
        }
        if (resp.IsSuccessStatusCode && body?.Ok == true)
        {
            return ("ok", "");
        }
        if (resp.StatusCode == HttpStatusCode.RequestEntityTooLarge)
        {
            return ("too_large", "");
        }
        return (body?.Code ?? "upload_failed", body?.Message ?? "HTTP " + (int)resp.StatusCode);
    }

    private static async Task EnsureAuth(HttpResponseMessage resp)
    {
        if (resp.StatusCode == HttpStatusCode.Unauthorized)
        {
            string msg = "Token inválido o revocado.";
            try
            {
                var body = await resp.Content.ReadFromJsonAsync<FileResponse>(Json);
                if (!string.IsNullOrWhiteSpace(body?.Message))
                {
                    msg = body!.Message!;
                }
            }
            catch
            {
            }
            throw new HubAuthException(msg);
        }
    }

    public void Dispose()
    {
        _http.Dispose();
        _upload.Dispose();
    }
}
