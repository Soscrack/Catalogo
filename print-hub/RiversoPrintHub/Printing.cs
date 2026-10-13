using System.ComponentModel;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Printing;

namespace RiversoPrintHub;

/// <summary>Error de impresión con código para el servidor (ver mensajes en class-print-module.php).</summary>
internal sealed class PrintJobException : Exception
{
    public string Code { get; }
    public string Detail { get; }

    public PrintJobException(string code, string detail) : base(code + ": " + detail)
    {
        Code = code;
        Detail = detail;
    }
}

/// <summary>Impresión a través del driver de Windows, con papel, escala, color, dúplex y copias del preset.</summary>
internal static class DriverPrinter
{
    /// <param name="printToFile">Solo diagnóstico: escribe la salida del driver a un archivo.</param>
    public static void Print(IPageSource pages, JobSettings s, string docName, string? printToFile = null)
    {
        var settings = new PrinterSettings { PrinterName = s.Printer };
        if (!settings.IsValid)
        {
            throw new PrintJobException("printer_missing", s.Printer);
        }
        if (printToFile is not null)
        {
            settings.PrintToFile = true;
            settings.PrintFileName = printToFile;
        }
        using var doc = new PrintDocument
        {
            DocumentName = docName,
            PrinterSettings = settings,
            PrintController = new StandardPrintController(),
            OriginAtMargins = false,
        };
        if (!string.IsNullOrWhiteSpace(s.Papel))
        {
            var paper = settings.PaperSizes.Cast<PaperSize>()
                .FirstOrDefault(p => string.Equals(p.PaperName?.Trim(), s.Papel.Trim(), StringComparison.OrdinalIgnoreCase));
            if (paper is null)
            {
                throw new PrintJobException("paper_missing", s.Papel);
            }
            doc.DefaultPageSettings.PaperSize = paper;
        }
        doc.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);
        doc.DefaultPageSettings.Color = s.Color && settings.SupportsColor;
        settings.Copies = (short)Math.Clamp(s.Copias, 1, 20);
        settings.Collate = true;

        bool landscapeFirst = WantsLandscape(s, pages.SizeInches(0));
        doc.DefaultPageSettings.Landscape = landscapeFirst;
        if (settings.CanDuplex)
        {
            settings.Duplex = s.Duplex switch
            {
                // «Borde largo» en vertical = Duplex.Vertical; en horizontal se invierte.
                "largo" => landscapeFirst ? Duplex.Horizontal : Duplex.Vertical,
                "corto" => landscapeFirst ? Duplex.Vertical : Duplex.Horizontal,
                _ => Duplex.Simplex,
            };
        }

        int index = 0;
        doc.QueryPageSettings += (_, e) =>
        {
            if (index < pages.Count)
            {
                e.PageSettings.Landscape = WantsLandscape(s, pages.SizeInches(index));
            }
        };
        doc.PrintPage += (_, e) =>
        {
            var g = e.Graphics!;
            // Área imprimible en centésimas de pulgada, ya orientada.
            RectangleF area = g.VisibleClipBounds;
            SizeF src = pages.SizeInches(index);
            float srcW = src.Width * 100f;
            float srcH = src.Height * 100f;
            float scale = s.EscalaModo switch
            {
                "real" => 1f,
                "porcentaje" => Math.Clamp(s.EscalaPct, 10, 400) / 100f,
                // Ajustar: achica para que la página quepa completa (no agranda, igual que Chrome).
                _ => Math.Min(1f, Math.Min(area.Width / srcW, area.Height / srcH)),
            };
            float w = srcW * scale;
            float h = srcH * scale;
            float x = area.X + Math.Max(0f, (area.Width - w) / 2f);
            float y = area.Y;

            int dpi = e.PageSettings.PrinterResolution.X;
            dpi = dpi is >= 150 and <= 1200 ? Math.Min(dpi, 300) : 300;
            int widthPx = (int)Math.Round(w / 100f * dpi);
            using (var bmp = pages.Render(index, widthPx))
            {
                g.InterpolationMode = InterpolationMode.HighQualityBicubic;
                g.PixelOffsetMode = PixelOffsetMode.HighQuality;
                g.DrawImage(bmp, new RectangleF(x, y, w, h));
            }
            index++;
            e.HasMorePages = index < pages.Count;
        };
        try
        {
            doc.Print();
        }
        catch (InvalidPrinterException)
        {
            throw new PrintJobException("printer_missing", s.Printer);
        }
        catch (Win32Exception ex)
        {
            throw new PrintJobException("print_failed", ex.Message);
        }
    }

    private static bool WantsLandscape(JobSettings s, SizeF size) => s.Orientacion switch
    {
        "horizontal" => true,
        "vertical" => false,
        _ => size.Width > size.Height * 1.05f,
    };
}

/// <summary>
/// Térmicas ESC/POS: la página se convierte en imagen de 1 bit del ancho exacto del cabezal
/// (384 puntos = 58 mm) y se envía en crudo con GS v 0. No depende del papel ni de la escala del driver.
/// </summary>
internal static class EscPosPrinter
{
    private const float Dpi = 203f;
    private const int BandRows = 128;
    private const int InkThreshold = 165;

    public static byte[] Build(IPageSource pages, JobSettings s)
    {
        int widthDots = Math.Clamp(s.AnchoPuntos, 128, 1024) / 8 * 8;
        var images = new List<bool[,]>();
        for (int i = 0; i < pages.Count; i++)
        {
            images.Add(RasterizePage(pages, i, s, widthDots));
        }

        using var ms = new MemoryStream();
        for (int copy = 0; copy < Math.Clamp(s.Copias, 1, 20); copy++)
        {
            ms.Write(new byte[] { 0x1B, 0x40 }); // ESC @ : reinicia la impresora
            foreach (var img in images)
            {
                WriteRaster(ms, img, widthDots);
            }
            // ESC J n: avanza n puntos (8 puntos ≈ 1 mm) para dejar el final pasado la barra de corte.
            int feed = Math.Clamp(s.AvanceMm, 0, 100) * 8;
            while (feed > 0)
            {
                int n = Math.Min(255, feed);
                ms.Write(new byte[] { 0x1B, 0x4A, (byte)n });
                feed -= n;
            }
        }
        return ms.ToArray();
    }

    private static bool[,] RasterizePage(IPageSource pages, int index, JobSettings s, int widthDots)
    {
        SizeF size = pages.SizeInches(index);
        Bitmap content;
        if (s.EscalaModo == "ajustar")
        {
            // Se renderiza al doble, se recortan los márgenes blancos y el contenido ocupa todo el ancho.
            int renderW = (int)Math.Clamp(Math.Max(widthDots * 2, size.Width * 300), 256, 3000);
            using var big = pages.Render(index, renderW);
            var crop = InkBounds(big);
            if (crop.Width <= 0 || crop.Height <= 0)
            {
                return new bool[1, widthDots];
            }
            int outH = (int)Math.Max(1, Math.Round(crop.Height * (double)widthDots / crop.Width));
            content = Resize(big, crop, widthDots, outH);
        }
        else
        {
            float pct = s.EscalaModo == "porcentaje" ? Math.Clamp(s.EscalaPct, 10, 400) / 100f : 1f;
            int w = (int)Math.Max(16, Math.Round(size.Width * Dpi * pct));
            using var rendered = pages.Render(index, w * 2);
            int h = (int)Math.Max(1, Math.Round(rendered.Height / 2.0));
            content = Resize(rendered, new Rectangle(0, 0, rendered.Width, rendered.Height), w, h);
        }
        using (content)
        {
            return Threshold(content, widthDots);
        }
    }

    private static Bitmap Resize(Bitmap src, Rectangle from, int w, int h)
    {
        var dst = new Bitmap(w, h, PixelFormat.Format24bppRgb);
        using var g = Graphics.FromImage(dst);
        g.Clear(Color.White);
        g.InterpolationMode = InterpolationMode.HighQualityBicubic;
        g.PixelOffsetMode = PixelOffsetMode.HighQuality;
        g.DrawImage(src, new Rectangle(0, 0, w, h), from, GraphicsUnit.Pixel);
        return dst;
    }

    /// <summary>Rectángulo con tinta (ignora el blanco de los márgenes).</summary>
    private static Rectangle InkBounds(Bitmap bmp)
    {
        using var copy = bmp.Clone(new Rectangle(0, 0, bmp.Width, bmp.Height), PixelFormat.Format24bppRgb);
        var data = copy.LockBits(new Rectangle(0, 0, copy.Width, copy.Height), ImageLockMode.ReadOnly, PixelFormat.Format24bppRgb);
        try
        {
            int stride = data.Stride;
            var buf = new byte[stride * copy.Height];
            System.Runtime.InteropServices.Marshal.Copy(data.Scan0, buf, 0, buf.Length);
            int minX = copy.Width, minY = copy.Height, maxX = -1, maxY = -1;
            for (int y = 0; y < copy.Height; y++)
            {
                int row = y * stride;
                for (int x = 0; x < copy.Width; x++)
                {
                    int o = row + x * 3;
                    if (Luma(buf[o + 2], buf[o + 1], buf[o]) < 235)
                    {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }
            if (maxX < 0)
            {
                return Rectangle.Empty;
            }
            return Rectangle.FromLTRB(minX, minY, maxX + 1, maxY + 1);
        }
        finally
        {
            copy.UnlockBits(data);
        }
    }

    /// <summary>Imagen → matriz de puntos negros del ancho del cabezal (centrada; si sobra, se recorta al centro).</summary>
    private static bool[,] Threshold(Bitmap content, int widthDots)
    {
        int h = content.Height;
        var dots = new bool[h, widthDots];
        var data = content.LockBits(new Rectangle(0, 0, content.Width, h), ImageLockMode.ReadOnly, PixelFormat.Format24bppRgb);
        try
        {
            int stride = data.Stride;
            var buf = new byte[stride * h];
            System.Runtime.InteropServices.Marshal.Copy(data.Scan0, buf, 0, buf.Length);
            int offset = (widthDots - content.Width) / 2;
            for (int y = 0; y < h; y++)
            {
                int row = y * stride;
                for (int x = 0; x < content.Width; x++)
                {
                    int dx = x + offset;
                    if (dx < 0 || dx >= widthDots)
                    {
                        continue;
                    }
                    int o = row + x * 3;
                    dots[y, dx] = Luma(buf[o + 2], buf[o + 1], buf[o]) < InkThreshold;
                }
            }
        }
        finally
        {
            content.UnlockBits(data);
        }
        return dots;
    }

    private static int Luma(byte r, byte g, byte b) => (r * 299 + g * 587 + b * 114) / 1000;

    /// <summary>GS v 0 en bandas para no desbordar el búfer de las térmicas económicas.</summary>
    private static void WriteRaster(Stream ms, bool[,] dots, int widthDots)
    {
        int height = dots.GetLength(0);
        int bytesPerRow = widthDots / 8;
        for (int start = 0; start < height; start += BandRows)
        {
            int rows = Math.Min(BandRows, height - start);
            ms.Write(new byte[]
            {
                0x1D, 0x76, 0x30, 0x00,
                (byte)(bytesPerRow & 0xFF), (byte)(bytesPerRow >> 8),
                (byte)(rows & 0xFF), (byte)(rows >> 8),
            });
            var band = new byte[bytesPerRow * rows];
            for (int y = 0; y < rows; y++)
            {
                for (int x = 0; x < widthDots; x++)
                {
                    if (dots[start + y, x])
                    {
                        band[y * bytesPerRow + (x >> 3)] |= (byte)(0x80 >> (x & 7));
                    }
                }
            }
            ms.Write(band);
        }
    }
}

/// <summary>Resultado de vigilar un trabajo en la cola de Windows.</summary>
internal sealed record SpoolOutcome(bool Ok, string Code, string Detail);

/// <summary>
/// Vigila el trabajo en la cola de Windows hasta que sale. Si la impresora reporta error o no
/// responde dentro del plazo, cancela el trabajo para que no se imprima por sorpresa más tarde.
/// </summary>
internal static class SpoolWatcher
{
    private const uint ErrorMask = Native.JOB_STATUS_ERROR | Native.JOB_STATUS_OFFLINE | Native.JOB_STATUS_PAPEROUT
        | Native.JOB_STATUS_BLOCKED_DEVQ | Native.JOB_STATUS_USER_INTERVENTION;

    public static async Task<SpoolOutcome> WaitAsync(string printer, uint? jobId, string docName, TimeSpan timeout, CancellationToken ct)
    {
        var started = DateTime.UtcNow;
        DateTime? errorSince = null;
        bool seen = false;
        uint knownId = jobId ?? 0;
        while (true)
        {
            var jobs = Native.ListJobs(printer);
            Native.JOB_INFO_1? job = null;
            foreach (var j in jobs)
            {
                if ((knownId != 0 && j.JobId == knownId) || (knownId == 0 && string.Equals(j.pDocument, docName, StringComparison.Ordinal)))
                {
                    job = j;
                }
            }
            if (job is null)
            {
                // Desapareció de la cola: ya se envió a la impresora. Si nunca se vio, se dio por
                // impreso al instante (cola vacía en la primera revisión tras 2 s).
                if (seen || DateTime.UtcNow - started > TimeSpan.FromSeconds(2))
                {
                    return new SpoolOutcome(true, "", "");
                }
            }
            else
            {
                seen = true;
                knownId = job.Value.JobId;
                uint st = job.Value.Status;
                if ((st & (Native.JOB_STATUS_PRINTED | Native.JOB_STATUS_COMPLETE)) != 0)
                {
                    return new SpoolOutcome(true, "", "");
                }
                if ((st & ErrorMask) != 0)
                {
                    errorSince ??= DateTime.UtcNow;
                    if (DateTime.UtcNow - errorSince > TimeSpan.FromSeconds(5))
                    {
                        Native.CancelJob(printer, knownId);
                        return new SpoolOutcome(false, (st & Native.JOB_STATUS_OFFLINE) != 0 ? "printer_offline" : "printer_error",
                            DescribeJobError(st, job.Value.pStatus));
                    }
                }
                else
                {
                    errorSince = null;
                }
            }
            if (DateTime.UtcNow - started > timeout)
            {
                if (knownId != 0)
                {
                    Native.CancelJob(printer, knownId);
                }
                return new SpoolOutcome(false, "print_timeout", "Sin respuesta en " + (int)timeout.TotalSeconds + " s");
            }
            await Task.Delay(500, ct);
        }
    }

    private static string DescribeJobError(uint st, string? text)
    {
        if (!string.IsNullOrWhiteSpace(text))
        {
            return text.Trim();
        }
        if ((st & Native.JOB_STATUS_PAPEROUT) != 0) return "Sin papel";
        if ((st & Native.JOB_STATUS_OFFLINE) != 0) return "Fuera de línea";
        if ((st & Native.JOB_STATUS_USER_INTERVENTION) != 0) return "Requiere atención (papel, tapa o atasco)";
        if ((st & Native.JOB_STATUS_BLOCKED_DEVQ) != 0) return "Cola de Windows bloqueada";
        return "Error de impresora";
    }
}
