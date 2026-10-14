using System.Text.Json;
using Microsoft.Win32;

namespace RiversoPrintHub;

/// <summary>Servidor y token del hub. Se guarda en %LOCALAPPDATA%\RiversoPrintHub\config.json.</summary>
internal sealed class HubConfig
{
    public string ServerUrl { get; set; } = "https://riverso.cl";
    public string Token { get; set; } = "";

    /// <summary>Revisa si los puertos USB tienen la impresora conectada (para avisar «apagada» antes de imprimir).</summary>
    public bool UsbPresenceCheck { get; set; } = true;

    /// <summary>Abrir solo al iniciar sesión. Solo cambia si alguien lo desmarca en el menú del ícono.</summary>
    public bool AutoStart { get; set; } = true;

    public static string Dir => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "RiversoPrintHub");
    private static string FilePath => Path.Combine(Dir, "config.json");

    public bool IsConfigured => !string.IsNullOrWhiteSpace(ServerUrl) && !string.IsNullOrWhiteSpace(Token);

    public static HubConfig Load()
    {
        try
        {
            if (File.Exists(FilePath))
            {
                return JsonSerializer.Deserialize<HubConfig>(File.ReadAllText(FilePath)) ?? new HubConfig();
            }
        }
        catch (Exception ex)
        {
            Log.Warn("No se pudo leer config.json: " + ex.Message);
        }
        return new HubConfig();
    }

    public void Save()
    {
        Directory.CreateDirectory(Dir);
        File.WriteAllText(FilePath, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
    }
}

/// <summary>
/// Arranque automático al iniciar sesión (HKCU\...\Run). Se revisa en cada inicio porque se
/// rompe en silencio si el .exe se mueve o se reemplaza por otro en otra carpeta, si Windows lo
/// desactiva en «Aplicaciones de arranque» o si el .exe quedó marcado como descargado de internet.
/// </summary>
internal static class Startup
{
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string ApprovedKey = @"Software\Microsoft\Windows\CurrentVersion\Explorer\StartupApproved\Run";
    private const string ValueName = "RiversoPrintHub";
    public const string AutoStartArg = "--autostart";

    private static string? Command => Environment.ProcessPath is string exe ? "\"" + exe + "\" " + AutoStartArg : null;

    public static bool IsEnabled
    {
        get
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey);
            return key?.GetValue(ValueName) is string && !DisabledByWindows;
        }
    }

    /// <summary>Desactivado en Administrador de tareas → Aplicaciones de arranque (o en Configuración).</summary>
    public static bool DisabledByWindows
    {
        get
        {
            using var key = Registry.CurrentUser.OpenSubKey(ApprovedKey);
            // Primer byte par = activado; impar = desactivado.
            return key?.GetValue(ValueName) is byte[] { Length: > 0 } b && (b[0] & 1) == 1;
        }
    }

    /// <summary>Elección explícita del usuario en el menú: también reactiva lo que Windows haya desactivado.</summary>
    public static void Set(bool enabled)
    {
        using var key = Registry.CurrentUser.CreateSubKey(RunKey);
        if (enabled && Command is string cmd)
        {
            key.SetValue(ValueName, cmd);
            using var approved = Registry.CurrentUser.CreateSubKey(ApprovedKey);
            approved.SetValue(ValueName, new byte[] { 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0 }, RegistryValueKind.Binary);
        }
        else
        {
            key.DeleteValue(ValueName, false);
        }
    }

    /// <summary>
    /// Al abrir: deja la entrada apuntando a este .exe y le quita la marca «descargado de internet»
    /// (con ella Windows puede pedir confirmación al iniciar y el hub queda esperando). No toca
    /// lo que el usuario desactivó en Windows: solo lo informa. Devuelve un aviso o null.
    /// </summary>
    public static string? Repair(bool wanted)
    {
        string? warning = null;
        try
        {
            if (Environment.ProcessPath is string exe && DeleteFileW(exe + ":Zone.Identifier"))
            {
                Log.Info("Se quitó la marca «descargado de internet» de " + exe);
            }
            if (!wanted)
            {
                return null;
            }
            using var key = Registry.CurrentUser.CreateSubKey(RunKey);
            var current = key.GetValue(ValueName) as string;
            if (Command is string cmd && !string.Equals(current, cmd, StringComparison.OrdinalIgnoreCase))
            {
                key.SetValue(ValueName, cmd);
                Log.Info(current is null
                    ? "Inicio automático registrado: " + cmd
                    : "Inicio automático corregido: apuntaba a " + current + " y ahora a " + cmd);
            }
            if (DisabledByWindows)
            {
                warning = "Windows tiene desactivado el inicio automático. Actívalo en el menú del ícono → «Iniciar con Windows», o en Administrador de tareas → Aplicaciones de arranque.";
                Log.Warn("Inicio automático desactivado en Windows (Aplicaciones de arranque).");
            }
        }
        catch (Exception ex)
        {
            Log.Warn("No se pudo revisar el inicio automático: " + ex.Message);
        }
        return warning;
    }

    [System.Runtime.InteropServices.DllImport("kernel32.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode, SetLastError = true)]
    private static extern bool DeleteFileW(string path);
}

/// <summary>Registro diario en %LOCALAPPDATA%\RiversoPrintHub\logs (se guardan 14 días).</summary>
internal static class Log
{
    private static readonly object Gate = new();
    public static string LogDir => Path.Combine(HubConfig.Dir, "logs");
    public static string TodayFile => Path.Combine(LogDir, "hub-" + DateTime.Now.ToString("yyyyMMdd") + ".log");

    public static void Info(string msg) => Write("INFO", msg);
    public static void Warn(string msg) => Write("WARN", msg);
    public static void Error(string msg) => Write("ERROR", msg);

    private static void Write(string level, string msg)
    {
        try
        {
            lock (Gate)
            {
                Directory.CreateDirectory(LogDir);
                File.AppendAllText(TodayFile, DateTime.Now.ToString("HH:mm:ss") + " " + level + " " + msg + Environment.NewLine);
            }
        }
        catch
        {
            // Sin registro no se detiene la impresión.
        }
    }

    public static void Cleanup()
    {
        try
        {
            foreach (var f in Directory.EnumerateFiles(LogDir, "hub-*.log"))
            {
                if (File.GetLastWriteTime(f) < DateTime.Now.AddDays(-14))
                {
                    File.Delete(f);
                }
            }
        }
        catch
        {
        }
    }
}
