using System.Diagnostics;
using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

namespace RiversoPrintHub;

/// <summary>Ícono junto al reloj: verde conectado, rojo sin conexión o token inválido, gris sin configurar.</summary>
internal sealed class TrayApp : ApplicationContext
{
    private readonly SynchronizationContext _ui;
    private readonly NotifyIcon _icon;
    private readonly ToolStripMenuItem _statusItem;
    private readonly ToolStripMenuItem _lastJobItem;
    private readonly ToolStripMenuItem _startupItem;
    private readonly ToolStripMenuItem _usbItem;
    private readonly Dictionary<HubState, Icon> _icons = new();
    private HubConfig _config;
    private HubService _service;

    public TrayApp()
    {
        _ui = SynchronizationContext.Current ?? new WindowsFormsSynchronizationContext();
        _config = HubConfig.Load();
        Log.Cleanup();

        _statusItem = new ToolStripMenuItem("Iniciando…") { Enabled = false };
        _lastJobItem = new ToolStripMenuItem("Sin trabajos todavía") { Enabled = false };
        _startupItem = new ToolStripMenuItem("Iniciar con Windows", null, (_, _) => ToggleStartup()) { Checked = Startup.IsEnabled };
        _usbItem = new ToolStripMenuItem("Avisar si la impresora USB está desconectada", null, (_, _) => ToggleUsbCheck())
        {
            Checked = _config.UsbPresenceCheck,
        };
        var menu = new ContextMenuStrip();
        menu.Items.Add(_statusItem);
        menu.Items.Add(_lastJobItem);
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Configurar…", null, (_, _) => Configure());
        menu.Items.Add("Abrir panel de impresión", null, (_, _) => OpenPanel());
        menu.Items.Add("Abrir registro", null, (_, _) => OpenLog());
        menu.Items.Add("Diagnóstico USB…", null, (_, _) => OpenUsbDiagnostic());
        menu.Items.Add(_usbItem);
        menu.Items.Add(_startupItem);
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Salir", null, (_, _) => ExitHub());

        _icon = new NotifyIcon
        {
            Icon = IconFor(HubState.NotConfigured),
            Text = "Riverso Print Hub",
            ContextMenuStrip = menu,
            Visible = true,
        };
        _icon.DoubleClick += (_, _) => Configure();

        _service = CreateService();
        if (_config.IsConfigured)
        {
            _service.Start();
        }
        else
        {
            ApplyStatus(_service.Status);
            _ui.Post(_ => Configure(), null);
        }
    }

    private HubService CreateService()
    {
        var service = new HubService(_config);
        service.StatusChanged += st => _ui.Post(_ => ApplyStatus(st), null);
        service.JobFinished += r => _ui.Post(_ => ApplyJob(r), null);
        return service;
    }

    private void ApplyStatus(HubStatus st)
    {
        string text = st.State switch
        {
            HubState.Connected => "● Conectado" + (st.AgentName.Length > 0 ? " como «" + st.AgentName + "»" : ""),
            HubState.Connecting => "Conectando…",
            HubState.AuthError => "● Token inválido: usa Configurar…",
            HubState.Disconnected => "● Sin conexión con el servidor (reintentando)",
            _ => "Sin configurar: usa Configurar…",
        };
        _statusItem.Text = text;
        _icon.Icon = IconFor(st.State);
        var tip = "Riverso Print Hub · " + text.TrimStart('●', ' ');
        _icon.Text = tip.Length > 120 ? tip[..120] : tip;
        if (st.State == HubState.AuthError)
        {
            _icon.ShowBalloonTip(8000, "Riverso Print Hub", "El servidor rechazó el token. Genera uno nuevo en Facturación → Impresión y pégalo en Configurar.", ToolTipIcon.Error);
        }
    }

    private void ApplyJob(JobResult r)
    {
        _lastJobItem.Text = (r.Ok ? "✓ " : "✗ ") + DateTime.Now.ToString("HH:mm") + " " + r.Titulo;
        if (!r.Ok)
        {
            _icon.ShowBalloonTip(8000, "No se imprimió " + r.Titulo, r.Message, ToolTipIcon.Warning);
        }
    }

    private void Configure()
    {
        using var form = new ConfigForm(_config);
        if (form.ShowDialog() != DialogResult.OK)
        {
            return;
        }
        _service.Stop();
        _config = HubConfig.Load();
        _service = CreateService();
        _service.Start();
        if (!Startup.IsEnabled)
        {
            Startup.Set(true);
            _startupItem.Checked = true;
        }
    }

    private void ToggleStartup()
    {
        Startup.Set(!Startup.IsEnabled);
        _startupItem.Checked = Startup.IsEnabled;
    }

    /// <summary>
    /// Sin este aviso, una térmica apagada se detecta igual al imprimir (el trabajo queda con
    /// error en la cola de Windows y se cancela), solo que unos segundos más tarde.
    /// </summary>
    private void ToggleUsbCheck()
    {
        _config.UsbPresenceCheck = !_config.UsbPresenceCheck;
        _config.Save();
        _usbItem.Checked = _config.UsbPresenceCheck;
        Log.Info("Aviso de impresora USB desconectada: " + (_config.UsbPresenceCheck ? "activado" : "desactivado"));
    }

    private static void OpenUsbDiagnostic()
    {
        Directory.CreateDirectory(Log.LogDir);
        var file = Path.Combine(Log.LogDir, "diagnostico-usb-" + DateTime.Now.ToString("yyyyMMdd-HHmmss") + ".txt");
        File.WriteAllText(file, UsbPorts.Diagnose());
        Process.Start(new ProcessStartInfo(file) { UseShellExecute = true });
    }

    private void OpenPanel()
    {
        var url = _config.ServerUrl.TrimEnd('/') + "/interno/facturacion/?vista=impresion";
        Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
    }

    private static void OpenLog()
    {
        Directory.CreateDirectory(Log.LogDir);
        var target = File.Exists(Log.TodayFile) ? Log.TodayFile : Log.LogDir;
        Process.Start(new ProcessStartInfo(target) { UseShellExecute = true });
    }

    private void ExitHub()
    {
        var answer = MessageBox.Show(
            "Si cierras el hub, «Imprimir Ya!» dejará de funcionar en todos los dispositivos hasta que lo vuelvas a abrir.\n\n¿Cerrar Riverso Print Hub?",
            "Riverso Print Hub", MessageBoxButtons.YesNo, MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2);
        if (answer != DialogResult.Yes)
        {
            return;
        }
        _service.Stop();
        _icon.Visible = false;
        _icon.Dispose();
        ExitThread();
    }

    private Icon IconFor(HubState state)
    {
        if (_icons.TryGetValue(state, out var cached))
        {
            return cached;
        }
        Color fill = state switch
        {
            HubState.Connected => Color.FromArgb(22, 163, 74),
            HubState.Connecting => Color.FromArgb(37, 99, 235),
            HubState.NotConfigured => Color.FromArgb(107, 114, 128),
            _ => Color.FromArgb(220, 38, 38),
        };
        using var bmp = new Bitmap(32, 32);
        using (var g = Graphics.FromImage(bmp))
        {
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.Clear(Color.Transparent);
            using var brush = new SolidBrush(fill);
            g.FillEllipse(brush, 1, 1, 30, 30);
            using var font = new Font("Segoe UI", 15f, FontStyle.Bold, GraphicsUnit.Pixel);
            var size = g.MeasureString("P", font);
            g.DrawString("P", font, Brushes.White, (32 - size.Width) / 2 + 0.5f, (32 - size.Height) / 2);
        }
        IntPtr handle = bmp.GetHicon();
        var icon = (Icon)Icon.FromHandle(handle).Clone();
        DestroyIcon(handle);
        _icons[state] = icon;
        return icon;
    }

    [DllImport("user32.dll")]
    private static extern bool DestroyIcon(IntPtr handle);
}

/// <summary>Servidor + token del hub, con prueba de conexión antes de guardar.</summary>
internal sealed class ConfigForm : Form
{
    private readonly TextBox _server = new() { Width = 360 };
    private readonly TextBox _token = new() { Width = 360 };
    private readonly Label _result = new() { AutoSize = false, Width = 360, Height = 40 };
    private readonly Button _save = new() { Text = "Probar y guardar", Width = 130 };
    private readonly HubConfig _config;

    public ConfigForm(HubConfig config)
    {
        _config = config;
        Text = "Riverso Print Hub · Configurar";
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        AutoSize = true;
        AutoSizeMode = AutoSizeMode.GrowAndShrink;
        Padding = new Padding(14);
        TopMost = true;

        _server.Text = config.ServerUrl;
        _token.Text = config.Token;
        var cancel = new Button { Text = "Cancelar", DialogResult = DialogResult.Cancel, Width = 90 };
        _save.Click += async (_, _) => await TestAndSave();

        var layout = new TableLayoutPanel { ColumnCount = 1, AutoSize = true, Dock = DockStyle.Fill };
        layout.Controls.Add(new Label
        {
            Text = "Crea el hub en riverso.cl → Facturación → Impresión → «Agregar hub»\ny pega aquí el servidor y el token que aparecen.",
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 10),
        });
        layout.Controls.Add(new Label { Text = "Servidor", AutoSize = true });
        layout.Controls.Add(_server);
        layout.Controls.Add(new Label { Text = "Token", AutoSize = true, Margin = new Padding(0, 8, 0, 0) });
        layout.Controls.Add(_token);
        layout.Controls.Add(_result);
        var buttons = new FlowLayoutPanel { FlowDirection = FlowDirection.RightToLeft, AutoSize = true, Dock = DockStyle.Fill };
        buttons.Controls.Add(_save);
        buttons.Controls.Add(cancel);
        layout.Controls.Add(buttons);
        Controls.Add(layout);
        AcceptButton = _save;
        CancelButton = cancel;
    }

    private async Task TestAndSave()
    {
        var server = _server.Text.Trim().TrimEnd('/');
        var token = _token.Text.Trim();
        if (!Uri.TryCreate(server, UriKind.Absolute, out var uri) || (uri.Scheme != "https" && uri.Scheme != "http"))
        {
            ShowResult("El servidor debe ser una dirección web, ej: https://riverso.cl", false);
            return;
        }
        if (token.Length < 20)
        {
            ShowResult("Pega el token completo.", false);
            return;
        }
        _save.Enabled = false;
        ShowResult("Probando conexión…", true);
        try
        {
            using var api = new HubApi(server, token);
            using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var resp = await api.PollAsync(new PollRequest { Probe = true }, cts.Token);
            _config.ServerUrl = server;
            _config.Token = token;
            _config.Save();
            Log.Info("Configuración guardada; hub «" + (resp.Agent?.Nombre ?? "") + "»");
            MessageBox.Show(this, "Conectado como «" + (resp.Agent?.Nombre ?? "hub") + "». El hub queda funcionando junto al reloj y se abrirá al iniciar Windows.",
                "Riverso Print Hub", MessageBoxButtons.OK, MessageBoxIcon.Information);
            DialogResult = DialogResult.OK;
            Close();
        }
        catch (HubAuthException ex)
        {
            ShowResult("Token rechazado: " + ex.Message, false);
        }
        catch (Exception ex)
        {
            ShowResult("No se pudo conectar: " + ex.Message, false);
        }
        finally
        {
            _save.Enabled = true;
        }
    }

    private void ShowResult(string text, bool ok)
    {
        _result.Text = text;
        _result.ForeColor = ok ? SystemColors.ControlText : Color.FromArgb(185, 28, 28);
    }
}
