namespace RiversoPrintHub;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        using var mutex = new Mutex(true, @"Local\RiversoPrintHub", out bool first);
        if (!first)
        {
            MessageBox.Show("Riverso Print Hub ya está abierto (ícono junto al reloj).", "Riverso Print Hub",
                MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }
        ApplicationConfiguration.Initialize();
        Application.ThreadException += (_, e) => Log.Error("Error no controlado: " + e.Exception);
        AppDomain.CurrentDomain.UnhandledException += (_, e) => Log.Error("Error fatal: " + e.ExceptionObject);
        Application.Run(new TrayApp());
    }
}
