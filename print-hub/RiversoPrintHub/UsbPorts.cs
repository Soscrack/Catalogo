using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32;
using Microsoft.Win32.SafeHandles;

namespace RiversoPrintHub;

/// <summary>
/// Puertos USBnnn de impresora y si su dispositivo está conectado ahora, según la API de
/// Configuration Manager (cfgmgr32). Cada impresora USB expone una interfaz
/// GUID_DEVINTERFACE_USBPRINT; su clave «Device Parameters» guarda el número de puerto que usa
/// la cola de Windows (Base Name + Port Number = USB002). La lista «presentes» solo trae las
/// interfaces de dispositivos enchufados y encendidos.
/// </summary>
internal static class UsbPorts
{
    private static Guid UsbPrintInterface = new("28d78fad-5a12-11d1-ae5b-0000f803a8c2");

    private const uint CM_GET_DEVICE_INTERFACE_LIST_PRESENT = 0x0;
    private const uint CM_GET_DEVICE_INTERFACE_LIST_ALL_DEVICES = 0x1;
    private const int CR_SUCCESS = 0x0;
    private const int CR_BUFFER_SMALL = 0x1A;
    private const int KEY_READ = 0x20019;
    private const int RegDisposition_OpenExisting = 0x1;

    [DllImport("cfgmgr32.dll", CharSet = CharSet.Unicode)]
    private static extern int CM_Get_Device_Interface_List_SizeW(out uint length, ref Guid interfaceClass, string? deviceId, uint flags);

    [DllImport("cfgmgr32.dll", CharSet = CharSet.Unicode)]
    private static extern int CM_Get_Device_Interface_ListW(ref Guid interfaceClass, string? deviceId, char[] buffer, uint bufferLength, uint flags);

    [DllImport("cfgmgr32.dll", CharSet = CharSet.Unicode)]
    private static extern int CM_Open_Device_Interface_KeyW(string deviceInterface, int samDesired, int disposition, out IntPtr key, uint flags);

    internal sealed record UsbInterface(string SymbolicLink, string Port, bool Present);

    /// <summary>
    /// Puerto → conectado. Solo trae puertos que Windows conoce como USB de impresora; si la API
    /// falla devuelve un diccionario vacío (sin datos no se marca nada como desconectado).
    /// </summary>
    public static Dictionary<string, bool> ReadPresence()
    {
        var result = new Dictionary<string, bool>(StringComparer.OrdinalIgnoreCase);
        try
        {
            var all = Interfaces(CM_GET_DEVICE_INTERFACE_LIST_ALL_DEVICES);
            var present = Interfaces(CM_GET_DEVICE_INTERFACE_LIST_PRESENT);
            if (all is null || present is null)
            {
                return result;
            }
            foreach (var link in all)
            {
                if (PortOf(link) is string port && !result.ContainsKey(port))
                {
                    result[port] = false;
                }
            }
            foreach (var link in present)
            {
                if (PortOf(link) is string port)
                {
                    result[port] = true;
                }
            }
        }
        catch (Exception ex)
        {
            Log.Warn("No se pudo revisar la conexión USB: " + ex.Message);
            result.Clear();
        }
        return result;
    }

    /// <summary>Informe de diagnóstico: interfaces USB de impresora, puertos y colas de Windows.</summary>
    public static string Diagnose()
    {
        var sb = new StringBuilder();
        sb.AppendLine("Riverso Print Hub " + HubApi.Version + " · diagnóstico USB · " + DateTime.Now.ToString("dd-MM-yyyy HH:mm:ss"));
        sb.AppendLine("Equipo: " + Environment.MachineName + " · Windows " + Environment.OSVersion.Version);
        sb.AppendLine();
        var all = Interfaces(CM_GET_DEVICE_INTERFACE_LIST_ALL_DEVICES);
        var present = Interfaces(CM_GET_DEVICE_INTERFACE_LIST_PRESENT);
        sb.AppendLine($"Interfaces USB de impresora: todas={(all is null ? "ERROR" : all.Count.ToString())} presentes={(present is null ? "ERROR" : present.Count.ToString())}");
        var presentSet = new HashSet<string>(present ?? new List<string>(), StringComparer.OrdinalIgnoreCase);
        foreach (var link in all ?? new List<string>())
        {
            sb.AppendLine($"  {(presentSet.Contains(link) ? "CONECTADA " : "ausente   ")} puerto={PortOf(link) ?? "?"}  {link}");
        }
        foreach (var link in presentSet.Where(l => all is null || !all.Contains(l, StringComparer.OrdinalIgnoreCase)))
        {
            sb.AppendLine($"  CONECTADA (solo en presentes) puerto={PortOf(link) ?? "?"}  {link}");
        }
        sb.AppendLine();
        sb.AppendLine("Resultado por puerto (lo que usa el hub):");
        foreach (var kv in ReadPresence().OrderBy(k => k.Key))
        {
            sb.AppendLine($"  {kv.Key}: {(kv.Value ? "conectada" : "desconectada")}");
        }
        sb.AppendLine();
        sb.AppendLine("Registro DeviceClasses (método anterior):");
        try
        {
            using var root = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\DeviceClasses\{28d78fad-5a12-11d1-ae5b-0000f803a8c2}");
            foreach (var sub in root?.GetSubKeyNames() ?? Array.Empty<string>())
            {
                string port = "?", linked = "(sin Control)", inst = "";
                try
                {
                    using var k = root!.OpenSubKey(sub);
                    inst = k?.GetValue("DeviceInstance") as string ?? "";
                    using var parms = root.OpenSubKey(sub + @"\#\Device Parameters");
                    if (parms?.GetValue("Port Number") is int n)
                    {
                        port = (parms.GetValue("Base Name") as string ?? "USB") + n.ToString("000");
                    }
                    using var control = root.OpenSubKey(sub + @"\#\Control");
                    if (control is not null)
                    {
                        linked = "Linked=" + (control.GetValue("Linked") ?? "(no existe)");
                    }
                }
                catch (Exception ex)
                {
                    linked = "ERROR " + ex.GetType().Name + ": " + ex.Message;
                }
                sb.AppendLine($"  puerto={port}  {linked}  {inst}");
            }
        }
        catch (Exception ex)
        {
            sb.AppendLine("  ERROR " + ex.Message);
        }
        sb.AppendLine();
        sb.AppendLine("Colas de Windows:");
        foreach (var p in Native.ListPrinters())
        {
            sb.AppendLine($"  {p.pPrinterName} | puerto={p.pPortName} | driver={p.pDriverName} | status=0x{p.Status:X} attr=0x{p.Attributes:X} trabajos={p.cJobs}");
        }
        return sb.ToString();
    }

    /// <returns>Enlaces simbólicos de las interfaces, o null si la API falló.</returns>
    private static List<string>? Interfaces(uint flags)
    {
        for (int attempt = 0; attempt < 3; attempt++)
        {
            var guid = UsbPrintInterface;
            if (CM_Get_Device_Interface_List_SizeW(out uint length, ref guid, null, flags) != CR_SUCCESS)
            {
                return null;
            }
            var buffer = new char[Math.Max(1, length)];
            int cr = CM_Get_Device_Interface_ListW(ref guid, null, buffer, (uint)buffer.Length, flags);
            if (cr == CR_BUFFER_SMALL)
            {
                continue; // Se conectó algo entre las dos llamadas.
            }
            if (cr != CR_SUCCESS)
            {
                return null;
            }
            return new string(buffer).Split('\0', StringSplitOptions.RemoveEmptyEntries).ToList();
        }
        return null;
    }

    /// <summary>Puerto de la cola (ej. USB002) guardado en la clave «Device Parameters» de la interfaz.</summary>
    private static string? PortOf(string symbolicLink)
    {
        if (CM_Open_Device_Interface_KeyW(symbolicLink, KEY_READ, RegDisposition_OpenExisting, out IntPtr h, 0) != CR_SUCCESS || h == IntPtr.Zero)
        {
            return null;
        }
        using var handle = new SafeRegistryHandle(h, true);
        using var key = RegistryKey.FromHandle(handle);
        if (key.GetValue("Port Number") is not int number)
        {
            return null;
        }
        return (key.GetValue("Base Name") as string ?? "USB") + number.ToString("000");
    }
}
