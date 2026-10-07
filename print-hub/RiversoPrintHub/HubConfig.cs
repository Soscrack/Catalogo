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

/// <summary>Arranque automático al iniciar sesión (HKCU\...\Run).</summary>
internal static class Startup
{
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string ValueName = "RiversoPrintHub";

    public static bool IsEnabled
    {
        get
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey);
            return key?.GetValue(ValueName) is string;
        }
    }

    public static void Set(bool enabled)
    {
        using var key = Registry.CurrentUser.CreateSubKey(RunKey);
        if (enabled && Environment.ProcessPath is string exe)
        {
            key.SetValue(ValueName, "\"" + exe + "\"");
        }
        else
        {
            key.DeleteValue(ValueName, false);
        }
    }
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
