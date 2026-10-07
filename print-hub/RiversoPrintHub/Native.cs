using System.ComponentModel;
using System.Runtime.InteropServices;

namespace RiversoPrintHub;

/// <summary>Llamadas a la cola de impresión de Windows (winspool.drv).</summary>
internal static class Native
{
    public const uint PRINTER_ENUM_LOCAL = 0x2;
    public const uint PRINTER_ENUM_CONNECTIONS = 0x4;

    // PRINTER_INFO_2.Status
    public const uint PRINTER_STATUS_PAUSED = 0x1;
    public const uint PRINTER_STATUS_ERROR = 0x2;
    public const uint PRINTER_STATUS_PAPER_JAM = 0x8;
    public const uint PRINTER_STATUS_PAPER_OUT = 0x10;
    public const uint PRINTER_STATUS_OFFLINE = 0x80;
    public const uint PRINTER_STATUS_NOT_AVAILABLE = 0x1000;
    public const uint PRINTER_STATUS_NO_TONER = 0x40000;
    public const uint PRINTER_STATUS_TONER_LOW = 0x20000;
    public const uint PRINTER_STATUS_USER_INTERVENTION = 0x100000;
    public const uint PRINTER_STATUS_DOOR_OPEN = 0x400000;
    public const uint PRINTER_ATTRIBUTE_WORK_OFFLINE = 0x400;

    // JOB_INFO_1.Status
    public const uint JOB_STATUS_PAUSED = 0x1;
    public const uint JOB_STATUS_ERROR = 0x2;
    public const uint JOB_STATUS_OFFLINE = 0x20;
    public const uint JOB_STATUS_PAPEROUT = 0x40;
    public const uint JOB_STATUS_PRINTED = 0x80;
    public const uint JOB_STATUS_DELETED = 0x100;
    public const uint JOB_STATUS_BLOCKED_DEVQ = 0x200;
    public const uint JOB_STATUS_USER_INTERVENTION = 0x400;
    public const uint JOB_STATUS_COMPLETE = 0x1000;

    public const uint JOB_CONTROL_DELETE = 5;
    public const int ERROR_INVALID_DATATYPE = 1804;

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct PRINTER_INFO_2
    {
        public string? pServerName;
        public string? pPrinterName;
        public string? pShareName;
        public string? pPortName;
        public string? pDriverName;
        public string? pComment;
        public string? pLocation;
        public IntPtr pDevMode;
        public string? pSepFile;
        public string? pPrintProcessor;
        public string? pDatatype;
        public string? pParameters;
        public IntPtr pSecurityDescriptor;
        public uint Attributes;
        public uint Priority;
        public uint DefaultPriority;
        public uint StartTime;
        public uint UntilTime;
        public uint Status;
        public uint cJobs;
        public uint AveragePPM;
    }

    [StructLayout(LayoutKind.Sequential)]
    public struct SYSTEMTIME
    {
        public ushort wYear, wMonth, wDayOfWeek, wDay, wHour, wMinute, wSecond, wMilliseconds;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct JOB_INFO_1
    {
        public uint JobId;
        public string? pPrinterName;
        public string? pMachineName;
        public string? pUserName;
        public string? pDocument;
        public string? pDatatype;
        public string? pStatus;
        public uint Status;
        public uint Priority;
        public uint Position;
        public uint TotalPages;
        public uint PagesPrinted;
        public SYSTEMTIME Submitted;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private class DOC_INFO_1
    {
        public string pDocName = "";
        public string? pOutputFile;
        public string pDatatype = "RAW";
    }

    [DllImport("winspool.drv", EntryPoint = "EnumPrintersW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool EnumPrinters(uint flags, string? name, uint level, IntPtr pPrinterEnum, uint cbBuf, out uint pcbNeeded, out uint pcReturned);

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool OpenPrinter(string printerName, out IntPtr hPrinter, IntPtr pDefault);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern int StartDocPrinter(IntPtr hPrinter, int level, [In] DOC_INFO_1 docInfo);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr hPrinter, byte[] buf, int cbBuf, out int pcWritten);

    [DllImport("winspool.drv", EntryPoint = "EnumJobsW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool EnumJobs(IntPtr hPrinter, uint firstJob, uint noJobs, uint level, IntPtr pJob, uint cbBuf, out uint pcbNeeded, out uint pcReturned);

    [DllImport("winspool.drv", EntryPoint = "SetJobW", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool SetJob(IntPtr hPrinter, uint jobId, uint level, IntPtr pJob, uint command);

    public static List<PRINTER_INFO_2> ListPrinters()
    {
        var result = new List<PRINTER_INFO_2>();
        const uint flags = PRINTER_ENUM_LOCAL | PRINTER_ENUM_CONNECTIONS;
        EnumPrinters(flags, null, 2, IntPtr.Zero, 0, out uint needed, out _);
        if (needed == 0)
        {
            return result;
        }
        IntPtr buf = Marshal.AllocHGlobal((int)needed);
        try
        {
            if (!EnumPrinters(flags, null, 2, buf, needed, out _, out uint count))
            {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            int size = Marshal.SizeOf<PRINTER_INFO_2>();
            for (int i = 0; i < count; i++)
            {
                result.Add(Marshal.PtrToStructure<PRINTER_INFO_2>(buf + i * size));
            }
        }
        finally
        {
            Marshal.FreeHGlobal(buf);
        }
        return result;
    }

    /// <summary>Envía bytes tal cual (datatype RAW) a la impresora. Devuelve el ID del trabajo en la cola.</summary>
    public static int SendRaw(string printerName, string docName, byte[] data)
    {
        if (!OpenPrinter(printerName, out IntPtr h, IntPtr.Zero))
        {
            throw new Win32Exception(Marshal.GetLastWin32Error());
        }
        try
        {
            int jobId = StartDocPrinter(h, 1, new DOC_INFO_1 { pDocName = docName, pDatatype = "RAW" });
            if (jobId <= 0)
            {
                throw new Win32Exception(Marshal.GetLastWin32Error());
            }
            try
            {
                if (!StartPagePrinter(h))
                {
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                }
                if (!WritePrinter(h, data, data.Length, out int written) || written != data.Length)
                {
                    throw new Win32Exception(Marshal.GetLastWin32Error());
                }
                EndPagePrinter(h);
            }
            finally
            {
                EndDocPrinter(h);
            }
            return jobId;
        }
        finally
        {
            ClosePrinter(h);
        }
    }

    public static List<JOB_INFO_1> ListJobs(string printerName)
    {
        var result = new List<JOB_INFO_1>();
        if (!OpenPrinter(printerName, out IntPtr h, IntPtr.Zero))
        {
            return result;
        }
        try
        {
            EnumJobs(h, 0, 255, 1, IntPtr.Zero, 0, out uint needed, out _);
            if (needed == 0)
            {
                return result;
            }
            IntPtr buf = Marshal.AllocHGlobal((int)needed);
            try
            {
                if (!EnumJobs(h, 0, 255, 1, buf, needed, out _, out uint count))
                {
                    return result;
                }
                int size = Marshal.SizeOf<JOB_INFO_1>();
                for (int i = 0; i < count; i++)
                {
                    result.Add(Marshal.PtrToStructure<JOB_INFO_1>(buf + i * size));
                }
            }
            finally
            {
                Marshal.FreeHGlobal(buf);
            }
        }
        finally
        {
            ClosePrinter(h);
        }
        return result;
    }

    public static bool CancelJob(string printerName, uint jobId)
    {
        if (!OpenPrinter(printerName, out IntPtr h, IntPtr.Zero))
        {
            return false;
        }
        try
        {
            return SetJob(h, jobId, 0, IntPtr.Zero, JOB_CONTROL_DELETE);
        }
        finally
        {
            ClosePrinter(h);
        }
    }
}
