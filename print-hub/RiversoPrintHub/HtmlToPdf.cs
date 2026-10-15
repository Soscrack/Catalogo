using System.Diagnostics;
using Microsoft.Win32;

namespace RiversoPrintHub;

/// <summary>
/// Convierte a PDF los documentos que el servidor entrega como HTML (cotizaciones), con
/// Microsoft Edge sin ventana. Edge viene con Windows 10/11 y usa el mismo motor que Chrome,
/// así que el resultado es igual al «Guardar como PDF» del navegador (tamaño y márgenes del
/// @page del documento).
/// </summary>
internal static class HtmlToPdf
{
    private static readonly TimeSpan Timeout = TimeSpan.FromSeconds(60);
    /// <summary>El navegador puede terminar antes de soltar el archivo: se espera un poco.</summary>
    private static readonly TimeSpan OutputGrace = TimeSpan.FromSeconds(5);

    private sealed record Attempt(string Browser, string Headless);

    public static async Task<byte[]> ConvertAsync(string html, CancellationToken ct)
    {
        var browsers = FindBrowsers();
        if (browsers.Count == 0)
        {
            throw new PrintJobException("render_failed", "No se encontró Microsoft Edge ni Google Chrome en este PC");
        }
        // Modo nuevo primero; el antiguo sirve en versiones o configuraciones que no lo aceptan.
        var attempts = browsers.SelectMany(b => new[] { new Attempt(b, "--headless=new"), new Attempt(b, "--headless") }).ToList();

        string dir = Path.Combine(WorkRoot(), "html-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        try
        {
            string input = Path.Combine(dir, "documento.html");
            await File.WriteAllTextAsync(input, html, new System.Text.UTF8Encoding(false), ct);
            string last = "";
            for (int i = 0; i < attempts.Count; i++)
            {
                string output = Path.Combine(dir, "documento-" + i + ".pdf");
                var (bytes, detail) = await RunAsync(attempts[i], input, output, Path.Combine(dir, "perfil-" + i), ct);
                if (bytes is not null)
                {
                    if (i > 0)
                    {
                        Log.Info($"HTML a PDF resuelto con {Path.GetFileName(attempts[i].Browser)} {attempts[i].Headless}");
                    }
                    return bytes;
                }
                last = detail;
                Log.Warn($"HTML a PDF falló con {attempts[i].Browser} {attempts[i].Headless}: {detail}");
            }
            throw new PrintJobException("render_failed", last);
        }
        finally
        {
            try
            {
                Directory.Delete(dir, recursive: true);
            }
            catch
            {
                // El navegador puede tardar en soltar el perfil; se limpia en la próxima conversión.
            }
        }
    }

    /// <returns>Bytes del PDF, o null y el motivo.</returns>
    private static async Task<(byte[]? Bytes, string Detail)> RunAsync(Attempt attempt, string input, string output, string profile, CancellationToken ct)
    {
        var psi = new ProcessStartInfo(attempt.Browser)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = Path.GetDirectoryName(input)!,
        };
        // Perfil propio: con Edge abierto en el PC, sin esto la orden se entrega a esa
        // ventana y no se genera el PDF.
        foreach (var arg in new[]
        {
            attempt.Headless,
            "--disable-gpu",
            "--no-first-run",
            "--no-default-browser-check",
            "--disable-extensions",
            "--disable-sync",
            "--disable-features=msEdgeStartupBoost,msImplicitSignin",
            "--user-data-dir=" + profile,
            "--no-pdf-header-footer",
            "--print-to-pdf-no-header",
            "--print-to-pdf=" + output,
            new Uri(input).AbsoluteUri,
        })
        {
            psi.ArgumentList.Add(arg);
        }

        Process? proc;
        try
        {
            proc = Process.Start(psi);
        }
        catch (Exception ex)
        {
            return (null, "No se pudo abrir " + Path.GetFileName(attempt.Browser) + ": " + ex.Message);
        }
        if (proc is null)
        {
            return (null, "No se pudo abrir " + Path.GetFileName(attempt.Browser));
        }
        using (proc)
        {
            // Se leen las dos salidas para que el navegador no se bloquee con el búfer lleno.
            var stdout = proc.StandardOutput.ReadToEndAsync(ct);
            var stderr = proc.StandardError.ReadToEndAsync(ct);
            using var limit = CancellationTokenSource.CreateLinkedTokenSource(ct);
            limit.CancelAfter(Timeout);
            try
            {
                await proc.WaitForExitAsync(limit.Token);
            }
            catch (OperationCanceledException)
            {
                try
                {
                    proc.Kill(entireProcessTree: true);
                }
                catch
                {
                }
                ct.ThrowIfCancellationRequested();
                return (null, "El navegador tardó demasiado en generar el PDF");
            }

            var until = DateTime.UtcNow + OutputGrace;
            while (!HasOutput(output) && DateTime.UtcNow < until)
            {
                await Task.Delay(250, ct);
            }
            if (HasOutput(output))
            {
                await Task.Delay(200, ct);
                return (await File.ReadAllBytesAsync(output, ct), "");
            }

            string text = "";
            try
            {
                text = ((await stdout) + "\n" + (await stderr)).Trim();
            }
            catch
            {
            }
            return (null, "El navegador no generó el PDF (código " + proc.ExitCode + ")" + Summary(text));
        }
    }

    private static bool HasOutput(string path)
    {
        try
        {
            return File.Exists(path) && new FileInfo(path).Length > 0;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>Última línea útil de la salida del navegador (el servidor guarda 255 caracteres).</summary>
    private static string Summary(string text)
    {
        var line = text.Split('\n').Select(l => l.Trim()).LastOrDefault(l => l.Length > 0) ?? "";
        if (line.Length > 140)
        {
            line = line[..140] + "…";
        }
        return line.Length > 0 ? ": " + line : "";
    }

    /// <summary>
    /// Carpeta de trabajo con ruta sin espacios ni tildes cuando se puede (C:\ProgramData), por
    /// si el nombre de usuario de Windows complica la ruta de %TEMP%; si no, %TEMP%.
    /// </summary>
    private static string WorkRoot()
    {
        foreach (var root in new[]
        {
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "RiversoPrintHub"),
            Path.Combine(Path.GetTempPath(), "RiversoPrintHub"),
        })
        {
            try
            {
                Directory.CreateDirectory(root);
                string probe = Path.Combine(root, ".escritura");
                File.WriteAllText(probe, "");
                File.Delete(probe);
                CleanOld(root);
                return root;
            }
            catch
            {
            }
        }
        return Path.GetTempPath();
    }

    /// <summary>Restos de conversiones anteriores que el navegador no dejó borrar.</summary>
    private static void CleanOld(string root)
    {
        foreach (var d in Directory.EnumerateDirectories(root, "html-*"))
        {
            try
            {
                if (DateTime.UtcNow - Directory.GetCreationTimeUtc(d) > TimeSpan.FromHours(1))
                {
                    Directory.Delete(d, recursive: true);
                }
            }
            catch
            {
            }
        }
    }

    /// <summary>Edge primero (siempre está en Windows 11); Chrome como respaldo.</summary>
    internal static List<string> FindBrowsers()
    {
        var found = new List<string>();
        void Add(string? path)
        {
            if (!string.IsNullOrWhiteSpace(path))
            {
                path = path.Trim().Trim('"');
                if (File.Exists(path) && !found.Contains(path, StringComparer.OrdinalIgnoreCase))
                {
                    found.Add(path);
                }
            }
        }
        foreach (var exe in new[] { "msedge.exe", "chrome.exe" })
        {
            foreach (var hive in new[] { Registry.LocalMachine, Registry.CurrentUser })
            {
                try
                {
                    using var key = hive.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\" + exe);
                    Add(key?.GetValue(null) as string);
                }
                catch
                {
                }
            }
            if (exe == "msedge.exe")
            {
                Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), @"Microsoft\Edge\Application\msedge.exe"));
                Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), @"Microsoft\Edge\Application\msedge.exe"));
            }
            else
            {
                Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), @"Google\Chrome\Application\chrome.exe"));
                Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), @"Google\Chrome\Application\chrome.exe"));
                Add(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), @"Google\Chrome\Application\chrome.exe"));
            }
        }
        return found;
    }
}
