namespace RiversoPrintHub;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        bool autostart = args.Contains(Startup.AutoStartArg, StringComparer.OrdinalIgnoreCase);
        using var mutex = new Mutex(true, @"Local\RiversoPrintHub", out bool first);
        if (!first)
        {
            if (autostart)
            {
                return;
            }
            MessageBox.Show("Riverso Print Hub ya está abierto (ícono junto al reloj).", "Riverso Print Hub",
                MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        ApplicationConfiguration.Initialize();
        Application.ThreadException += (_, e) => Log.Error("Error no controlado: " + e.Exception);
        AppDomain.CurrentDomain.UnhandledException += (_, e) => Log.Error("Error fatal: " + e.ExceptionObject);
        // Queda en el registro si abrió solo o a mano: así se sabe si el inicio automático funciona.
        Log.Info((autostart ? "Abierto por Windows al iniciar sesión" : "Abierto a mano") + ": " + Environment.ProcessPath);
        Application.Run(new TrayApp());
    }
}
