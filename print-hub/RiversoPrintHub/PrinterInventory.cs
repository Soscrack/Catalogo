using System.Drawing.Printing;
using System.Net.Sockets;
using System.Text.RegularExpressions;
using Microsoft.Win32;

namespace RiversoPrintHub;

/// <summary>Estado actual de una impresora de Windows.</summary>
internal sealed record PrinterState(string Status, string Detail);

/// <summary>
/// Impresoras instaladas en este PC, su estado y sus papeles. El estado combina lo que informa
/// la cola de Windows con dos chequeos propios: puerto USB sin dispositivo conectado e impresora
/// de red que no responde en su IP. Se refresca en segundo plano para no demorar la consulta a la cola.
/// </summary>
internal sealed partial class PrinterInventory
{
    private static readonly TimeSpan PaperRefresh = TimeSpan.FromMinutes(10);
    private static readonly int[] NetworkPorts = { 631, 80, 9100, 443 };

    private readonly HubConfig _config;
    private readonly object _gate = new();
    private List<PrinterReport> _current = new();
    private readonly Dictionary<string, (DateTime at, List<string> papers)> _papers = new(StringComparer.OrdinalIgnoreCase);
    private Dictionary<string, string> _manualHosts = new(StringComparer.OrdinalIgnoreCase);
    private bool _papersPending = true;

    public PrinterInventory(HubConfig config)
    {
        _config = config;
    }

    public void ApplyManualHosts(Dictionary<string, string>? hosts)
    {
        lock (_gate)
        {
            _manualHosts = new Dictionary<string, string>(hosts ?? new(), StringComparer.OrdinalIgnoreCase);
        }
    }

    /// <summary>Último reporte para el servidor. Los papeles solo viajan cuando cambian o cada 10 minutos.</summary>
    public List<PrinterReport> Snapshot(out bool includesPapers)
    {
        lock (_gate)
        {
            bool withPapers = _papersPending;
            includesPapers = withPapers;
            _papersPending = false;
            return _current.Select(p => new PrinterReport
            {
                Name = p.Name,
                Driver = p.Driver,
                Port = p.Port,
                Status = p.Status,
                Detail = p.Detail,
                IsDefault = p.IsDefault,
                IsVirtual = p.IsVirtual,
                Host = p.Host,
                Papers = withPapers ? p.Papers : null,
            }).ToList();
        }
    }

    /// <summary>El envío con papeles falló: se reenvían en el próximo reporte.</summary>
    public void MarkPapersPending()
    {
        lock (_gate)
        {
            _papersPending = true;
        }
    }

    public bool HasData
    {
        get
        {
            lock (_gate)
            {
                return _current.Count > 0;
            }
        }
    }

    /// <summary>Vuelve a leer impresoras y estados (se llama cada ~10 s desde un hilo de fondo).</summary>
    public async Task RefreshAsync(CancellationToken ct)
    {
        var infos = Native.ListPrinters();
        string defaultName = "";
        try
        {
            defaultName = new PrinterSettings().PrinterName;
        }
        catch
        {
        }
        Dictionary<string, string> manual;
        lock (_gate)
        {
            manual = new Dictionary<string, string>(_manualHosts, StringComparer.OrdinalIgnoreCase);
        }
        var usbPresence = _config.UsbPresenceCheck ? ReadUsbPresence() : new Dictionary<string, bool>();

        var reports = new List<PrinterReport>();
        var paperChanged = false;
        foreach (var info in infos)
        {
            var name = info.pPrinterName ?? "";
            if (name.Length == 0)
            {
                continue;
            }
            var port = info.pPortName ?? "";
            var driver = info.pDriverName ?? "";
            var isVirtual = IsVirtual(name, port, driver);
            var host = manual.TryGetValue(name, out var m) && m.Length > 0 ? m : DetectHost(port);
            var state = FromSpooler(info);

            if (!isVirtual && state.Status == "listo")
            {
                // La cola de Windows suele decir «Lista» aunque la impresora esté apagada:
                // se confirma con el puerto USB o con la IP.
                var portKey = port.Trim().TrimEnd(':');
                if (usbPresence.TryGetValue(portKey, out bool present) && !present)
                {
                    state = new PrinterState("offline", UsbMissing);
                }
                else if (host.Length > 0 && !await RespondsAsync(host, ct))
                {
                    state = new PrinterState("offline", NoNetwork(host));
                }
            }

            var papers = PapersFor(name, isVirtual, ref paperChanged);
            reports.Add(new PrinterReport
            {
                Name = name,
                Driver = driver,
                Port = port,
                Status = state.Status,
                Detail = state.Detail,
                IsDefault = string.Equals(name, defaultName, StringComparison.OrdinalIgnoreCase),
                IsVirtual = isVirtual,
                Host = host,
                Papers = papers,
            });
        }
        lock (_gate)
        {
            if (paperChanged || reports.Count != _current.Count)
            {
                _papersPending = true;
            }
            _current = reports;
        }
    }

    /// <summary>Estado al momento de imprimir (sin esperar el refresco periódico).</summary>
    public async Task<PrinterState?> CheckNowAsync(string printerName, CancellationToken ct)
    {
        var info = Native.ListPrinters().FirstOrDefault(p => string.Equals(p.pPrinterName, printerName, StringComparison.OrdinalIgnoreCase));
        if (info.pPrinterName is null)
        {
            return null;
        }
        var state = FromSpooler(info);
        if (state.Status != "listo")
        {
            return state;
        }
        var port = (info.pPortName ?? "").Trim().TrimEnd(':');
        if (_config.UsbPresenceCheck && ReadUsbPresence().TryGetValue(port, out bool present) && !present)
        {
            return new PrinterState("offline", UsbMissing);
        }
        string host;
        lock (_gate)
        {
            host = _manualHosts.TryGetValue(printerName, out var m) && m.Length > 0 ? m : DetectHost(info.pPortName ?? "");
        }
        if (host.Length > 0 && !await RespondsAsync(host, ct))
        {
            return new PrinterState("offline", NoNetwork(host));
        }
        return state;
    }

    private const string UsbMissing = "Sin conexión USB";

    private static string NoNetwork(string host) => "Sin respuesta en " + host;

    private List<string> PapersFor(string printer, bool isVirtual, ref bool changed)
    {
        lock (_gate)
        {
            if (_papers.TryGetValue(printer, out var cached) && DateTime.Now - cached.at < PaperRefresh)
            {
                return cached.papers;
            }
        }
        var list = new List<string>();
        if (!isVirtual)
        {
            try
            {
                var ps = new PrinterSettings { PrinterName = printer };
                foreach (PaperSize size in ps.PaperSizes)
                {
                    if (!string.IsNullOrWhiteSpace(size.PaperName))
                    {
                        list.Add(size.PaperName.Trim());
                    }
                }
            }
            catch (Exception ex)
            {
                Log.Warn($"No se pudieron leer los papeles de «{printer}»: {ex.Message}");
            }
        }
        lock (_gate)
        {
            if (!_papers.TryGetValue(printer, out var old) || !old.papers.SequenceEqual(list))
            {
                changed = true;
            }
            _papers[printer] = (DateTime.Now, list);
        }
        return list;
    }

    private static PrinterState FromSpooler(Native.PRINTER_INFO_2 info)
    {
        uint s = info.Status;
        if ((info.Attributes & Native.PRINTER_ATTRIBUTE_WORK_OFFLINE) != 0)
        {
            return new("offline", "Marcada «Usar impresora sin conexión» en Windows");
        }
        if ((s & (Native.PRINTER_STATUS_OFFLINE | Native.PRINTER_STATUS_NOT_AVAILABLE)) != 0)
        {
            return new("offline", "Fuera de línea");
        }
        if ((s & Native.PRINTER_STATUS_PAPER_OUT) != 0)
        {
            return new("error", "Sin papel");
        }
        if ((s & Native.PRINTER_STATUS_PAPER_JAM) != 0)
        {
            return new("error", "Papel atascado");
        }
        if ((s & Native.PRINTER_STATUS_DOOR_OPEN) != 0)
        {
            return new("error", "Tapa abierta");
        }
        if ((s & Native.PRINTER_STATUS_NO_TONER) != 0)
        {
            return new("error", "Sin tinta");
        }
        if ((s & Native.PRINTER_STATUS_USER_INTERVENTION) != 0)
        {
            return new("error", "Requiere atención");
        }
        if ((s & Native.PRINTER_STATUS_PAUSED) != 0)
        {
            return new("error", "Cola en pausa en Windows");
        }
        if ((s & Native.PRINTER_STATUS_ERROR) != 0)
        {
            return new("error", "Error de impresora");
        }
        return new("listo", (s & Native.PRINTER_STATUS_TONER_LOW) != 0 ? "Tinta baja" : "");
    }

    private static bool IsVirtual(string name, string port, string driver)
    {
        var p = port.Trim().ToUpperInvariant();
        if (p is "PORTPROMPT:" or "NUL:" or "SHRFAX:" or "FILE:" or "XPSPORT:" || p.StartsWith("MICROSOFT.OFFICE.ONENOTE"))
        {
            return true;
        }
        var d = driver.ToUpperInvariant();
        return d.Contains("PRINT TO PDF") || d.Contains("XPS DOCUMENT") || d.Contains("ONENOTE")
            || Fax().IsMatch(driver) || Fax().IsMatch(name);
    }

    [GeneratedRegex(@"\bfax\b", RegexOptions.IgnoreCase)]
    private static partial Regex Fax();

    /// <summary>
    /// Puertos USBnnn con dispositivo conectado. Windows registra cada interfaz de impresora USB en
    /// DeviceClasses\{GUID_DEVINTERFACE_USBPRINT}; la subclave volátil «#\Control» con Linked=1 solo
    /// existe mientras el dispositivo está presente. Si no hay datos para un puerto, no se informa nada.
    /// </summary>
    private static Dictionary<string, bool> ReadUsbPresence()
    {
        var result = new Dictionary<string, bool>(StringComparer.OrdinalIgnoreCase);
        try
        {
            using var root = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Control\DeviceClasses\{28d78fad-5a12-11d1-ae5b-0000f803a8c2}");
            if (root is null)
            {
                return result;
            }
            foreach (var sub in root.GetSubKeyNames())
            {
                using var parms = root.OpenSubKey(sub + @"\#\Device Parameters");
                if (parms?.GetValue("Port Number") is not int number)
                {
                    continue;
                }
                var baseName = parms.GetValue("Base Name") as string ?? "USB";
                var portName = baseName + number.ToString("000");
                using var control = root.OpenSubKey(sub + @"\#\Control");
                bool linked = control?.GetValue("Linked") is int l && l == 1;
                // Puede haber varias entradas por puerto (dispositivos antiguos): basta una conectada.
                result[portName] = (result.TryGetValue(portName, out bool prev) && prev) || linked;
            }
        }
        catch (Exception ex)
        {
            Log.Warn("No se pudo revisar la conexión USB: " + ex.Message);
        }
        return result;
    }

    [GeneratedRegex(@"\b((25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(25[0-5]|2[0-4]\d|1?\d?\d)\b")]
    private static partial Regex Ipv4();

    /// <summary>IP de una impresora de red según la configuración de su puerto en Windows (si la hay).</summary>
    private static string DetectHost(string port)
    {
        if (string.IsNullOrWhiteSpace(port) || port.StartsWith("USB", StringComparison.OrdinalIgnoreCase))
        {
            return "";
        }
        var ipInName = Ipv4().Match(port);
        if (ipInName.Success)
        {
            return ipInName.Value;
        }
        try
        {
            using var monitors = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\Print\Monitors");
            if (monitors is null)
            {
                return "";
            }
            foreach (var monitor in monitors.GetSubKeyNames())
            {
                using var key = monitors.OpenSubKey(monitor + @"\Ports\" + port);
                if (key is null)
                {
                    continue;
                }
                foreach (var valueName in new[] { "HostName", "IPAddress", "IPAddr", "Address" })
                {
                    if (key.GetValue(valueName) is string v && v.Trim().Length > 0)
                    {
                        return v.Trim();
                    }
                }
                foreach (var valueName in key.GetValueNames())
                {
                    if (key.GetValue(valueName) is string v && Ipv4().Match(v) is { Success: true } m)
                    {
                        return m.Value;
                    }
                }
            }
        }
        catch
        {
        }
        return "";
    }

    private static async Task<bool> RespondsAsync(string host, CancellationToken ct)
    {
        foreach (var port in NetworkPorts)
        {
            try
            {
                using var client = new TcpClient();
                using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
                cts.CancelAfter(TimeSpan.FromMilliseconds(1200));
                await client.ConnectAsync(host, port, cts.Token);
                return true;
            }
            catch
            {
                // Siguiente puerto.
            }
        }
        return false;
    }
}
