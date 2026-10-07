using System.Collections.Concurrent;
using System.ComponentModel;

namespace RiversoPrintHub;

internal enum HubState
{
    NotConfigured,
    Connecting,
    Connected,
    Disconnected,
    AuthError,
}

internal sealed record HubStatus(HubState State, string Message, string AgentName);

internal sealed record JobResult(long JobId, string Titulo, bool Ok, string Message);

/// <summary>
/// Ciclo del hub: consulta la cola cada ~2 s (enviando el estado de las impresoras cada ~10 s)
/// y ejecuta los trabajos en paralelo con la consulta, en orden por impresora.
/// </summary>
internal sealed class HubService
{
    private static readonly TimeSpan PrinterReportEvery = TimeSpan.FromSeconds(10);
    private static readonly TimeSpan PdfWaitLimit = TimeSpan.FromSeconds(30);

    private readonly HubConfig _config;
    private readonly PrinterInventory _inventory;
    private readonly ConcurrentDictionary<string, SemaphoreSlim> _printerLocks = new(StringComparer.OrdinalIgnoreCase);
    private CancellationTokenSource? _cts;
    private Task? _loop;

    public event Action<HubStatus>? StatusChanged;
    public event Action<JobResult>? JobFinished;

    public HubStatus Status { get; private set; } = new(HubState.NotConfigured, "Sin configurar", "");

    public HubService(HubConfig config)
    {
        _config = config;
        _inventory = new PrinterInventory(config);
    }

    public void Start()
    {
        Stop();
        if (!_config.IsConfigured)
        {
            SetStatus(HubState.NotConfigured, "Falta configurar servidor y token", "");
            return;
        }
        _cts = new CancellationTokenSource();
        var ct = _cts.Token;
        _loop = Task.Run(() => RunAsync(ct));
        _ = Task.Run(() => RefreshPrintersLoopAsync(ct));
    }

    public void Stop()
    {
        if (_cts is null)
        {
            return;
        }
        _cts.Cancel();
        try
        {
            _loop?.Wait(TimeSpan.FromSeconds(3));
        }
        catch
        {
        }
        _cts.Dispose();
        _cts = null;
        _loop = null;
    }

    private void SetStatus(HubState state, string message, string agent)
    {
        var next = new HubStatus(state, message, agent);
        if (next == Status)
        {
            return;
        }
        Status = next;
        StatusChanged?.Invoke(next);
    }

    private async Task RefreshPrintersLoopAsync(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                await _inventory.RefreshAsync(ct);
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                Log.Warn("No se pudo leer el estado de las impresoras: " + ex.Message);
            }
            try
            {
                await Task.Delay(PrinterReportEvery, ct);
            }
            catch (OperationCanceledException)
            {
                return;
            }
        }
    }

    private async Task RunAsync(CancellationToken ct)
    {
        using var api = new HubApi(_config.ServerUrl, _config.Token);
        SetStatus(HubState.Connecting, "Conectando con " + _config.ServerUrl, "");
        Log.Info("Hub " + HubApi.Version + " iniciado; servidor " + _config.ServerUrl);
        var lastReport = DateTime.MinValue;
        int failures = 0;
        while (!ct.IsCancellationRequested)
        {
            int delayMs;
            bool sentPapers = false;
            try
            {
                var req = new PollRequest();
                if (_inventory.HasData && DateTime.UtcNow - lastReport >= PrinterReportEvery)
                {
                    req.Printers = _inventory.Snapshot(out sentPapers);
                    req.PrintersFull = true;
                }
                var resp = await api.PollAsync(req, ct);
                if (req.Printers is not null)
                {
                    lastReport = DateTime.UtcNow;
                }
                _inventory.ApplyManualHosts(resp.Hosts);
                if (failures > 0)
                {
                    Log.Info("Conexión recuperada");
                }
                failures = 0;
                SetStatus(HubState.Connected, "Conectado a " + _config.ServerUrl, resp.Agent?.Nombre ?? "");
                if (resp.Job is not null)
                {
                    var job = resp.Job;
                    _ = Task.Run(() => RunJobSerializedAsync(api, job, ct));
                    continue; // Puede haber más trabajos en cola: consultar de inmediato.
                }
                delayMs = Math.Clamp(resp.PollMs, 1000, 10000);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch (HubAuthException ex)
            {
                if (sentPapers)
                {
                    _inventory.MarkPapersPending();
                }
                SetStatus(HubState.AuthError, ex.Message, "");
                Log.Error("Servidor rechazó el token: " + ex.Message);
                delayMs = 30000;
            }
            catch (Exception ex)
            {
                if (sentPapers)
                {
                    _inventory.MarkPapersPending();
                }
                failures++;
                if (failures == 1 || failures % 30 == 0)
                {
                    Log.Warn("Sin conexión con el servidor: " + ex.Message);
                }
                SetStatus(HubState.Disconnected, "Sin conexión: " + ex.Message, Status.AgentName);
                delayMs = Math.Min(15000, 1000 * (1 << Math.Min(4, failures)));
            }
            try
            {
                await Task.Delay(delayMs, ct);
            }
            catch (OperationCanceledException)
            {
                return;
            }
        }
    }

    private async Task RunJobSerializedAsync(HubApi api, HubJob job, CancellationToken ct)
    {
        var printer = job.Settings?.Printer ?? "";
        var gate = _printerLocks.GetOrAdd(printer, _ => new SemaphoreSlim(1, 1));
        await gate.WaitAsync(ct);
        try
        {
            await RunJobAsync(api, job, ct);
        }
        catch (Exception ex)
        {
            Log.Error($"Trabajo #{job.Id}: error inesperado: {ex}");
        }
        finally
        {
            gate.Release();
        }
    }

    private async Task RunJobAsync(HubApi api, HubJob job, CancellationToken ct)
    {
        var s = job.Settings;
        Log.Info($"Trabajo #{job.Id} «{job.Titulo}» → {s?.Printer} ({s?.Modo}, {s?.Preset})");
        if (s is null || string.IsNullOrWhiteSpace(s.Printer))
        {
            await Fail(api, job, "printer_missing", "El trabajo no trae impresora");
            return;
        }
        var label = string.IsNullOrWhiteSpace(s.PrinterLabel) ? s.Printer : s.PrinterLabel;
        try
        {
            await api.ReportAsync(job.Id, "tomado", "Revisando " + label);
            var state = await _inventory.CheckNowAsync(s.Printer, ct);
            if (state is null)
            {
                await Fail(api, job, "printer_missing", s.Printer);
                return;
            }
            if (state.Status == "offline" || state.Status == "error")
            {
                await Fail(api, job, state.Status == "offline" ? "printer_offline" : "printer_error", state.Detail);
                return;
            }

            using IPageSource pages = job.Kind == "prueba"
                ? new TestPageSource(s, job.Titulo)
                : await PdfPageSource.LoadAsync(await FetchPdfAsync(api, job, ct));
            if (pages.Count == 0)
            {
                await Fail(api, job, "print_failed", "El PDF no tiene páginas");
                return;
            }

            await api.ReportAsync(job.Id, "imprimiendo", "Enviando a " + label);
            string docName = $"Riverso #{job.Id} {job.Titulo}";
            uint? spoolId = null;
            if (s.Modo == "escpos")
            {
                byte[] data = await Task.Run(() => EscPosPrinter.Build(pages, s), ct);
                try
                {
                    spoolId = (uint)Native.SendRaw(s.Printer, docName, data);
                }
                catch (Win32Exception ex) when (ex.NativeErrorCode == Native.ERROR_INVALID_DATATYPE)
                {
                    throw new PrintJobException("print_failed", "El driver no acepta ESC/POS directo; usa el modo Driver de Windows en el preset");
                }
            }
            else
            {
                await Task.Run(() => DriverPrinter.Print(pages, s, docName), ct);
            }

            var outcome = await SpoolWatcher.WaitAsync(s.Printer, spoolId, docName, TimeSpan.FromSeconds(Math.Clamp(s.TimeoutS, 10, 180)), ct);
            if (!outcome.Ok)
            {
                await Fail(api, job, outcome.Code, outcome.Detail);
                return;
            }
            await api.ReportAsync(job.Id, "impreso", "Impreso en " + label);
            Log.Info($"Trabajo #{job.Id} impreso en {s.Printer}");
            JobFinished?.Invoke(new JobResult(job.Id, job.Titulo, true, "Impreso en " + label));
        }
        catch (PrintJobException ex)
        {
            await Fail(api, job, ex.Code, ex.Detail);
        }
        catch (JobClosedException)
        {
            Log.Info($"Trabajo #{job.Id}: el servidor lo cerró (vencido o cancelado); no se imprime.");
        }
        catch (OperationCanceledException)
        {
            await Fail(api, job, "hub_lost", "El hub se cerró mientras imprimía");
        }
        catch (Exception ex)
        {
            Log.Error($"Trabajo #{job.Id}: {ex}");
            await Fail(api, job, "print_failed", ex.Message);
        }
    }

    private sealed class JobClosedException : Exception
    {
    }

    /// <summary>Descarga el PDF; si FACTO aún no lo genera (recién emitido) reintenta hasta 30 s.</summary>
    private static async Task<byte[]> FetchPdfAsync(HubApi api, HubJob job, CancellationToken ct)
    {
        var started = DateTime.UtcNow;
        bool told = false;
        while (true)
        {
            var r = await api.GetPdfAsync(job.Id, ct);
            if (r.Bytes is not null)
            {
                return r.Bytes;
            }
            if (r.Code == "job_closed")
            {
                throw new JobClosedException();
            }
            bool retryable = r.Code is "pdf_not_ready" or "pdf_unavailable" || r.Code.StartsWith("http_5");
            if (!retryable || DateTime.UtcNow - started > PdfWaitLimit)
            {
                throw new PrintJobException(r.Code == "source_missing" ? "source_missing" : "pdf_unavailable", r.Message);
            }
            if (!told)
            {
                await api.ReportAsync(job.Id, "tomado", "Esperando el PDF de FACTO");
                told = true;
            }
            await Task.Delay(2000, ct);
        }
    }

    private async Task Fail(HubApi api, HubJob job, string code, string detail)
    {
        Log.Warn($"Trabajo #{job.Id} no impreso: {code} {detail}");
        await api.ReportAsync(job.Id, "error", detail, code, detail);
        JobFinished?.Invoke(new JobResult(job.Id, job.Titulo, false, Humanize(code, detail)));
    }

    private static string Humanize(string code, string detail) => code switch
    {
        "printer_offline" => "Impresora apagada o desconectada" + (detail.Length > 0 ? " (" + detail + ")" : ""),
        "printer_error" => "Problema en la impresora" + (detail.Length > 0 ? ": " + detail : ""),
        "printer_missing" => "Impresora no encontrada en Windows: " + detail,
        "paper_missing" => "El papel «" + detail + "» no existe en la impresora",
        "print_timeout" => "La impresora no respondió; trabajo cancelado",
        "pdf_unavailable" => "No se pudo obtener el PDF de FACTO",
        _ => detail.Length > 0 ? detail : code,
    };
}
