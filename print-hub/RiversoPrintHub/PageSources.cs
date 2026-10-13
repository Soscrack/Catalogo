using System.Drawing.Drawing2D;
using System.Drawing.Text;
using Windows.Data.Pdf;
using Windows.Storage.Streams;

namespace RiversoPrintHub;

/// <summary>Páginas a imprimir: tamaño físico en pulgadas y render a un ancho en píxeles.</summary>
internal interface IPageSource : IDisposable
{
    int Count { get; }
    SizeF SizeInches(int page);
    Bitmap Render(int page, int widthPx);
}

/// <summary>PDF renderizado con Windows.Data.Pdf (incluido en Windows 10/11).</summary>
internal sealed class PdfPageSource : IPageSource
{
    private readonly PdfDocument _doc;
    private readonly InMemoryRandomAccessStream _stream;

    private PdfPageSource(PdfDocument doc, InMemoryRandomAccessStream stream)
    {
        _doc = doc;
        _stream = stream;
    }

    public static async Task<PdfPageSource> LoadAsync(byte[] pdf)
    {
        var stream = new InMemoryRandomAccessStream();
        using (var writer = new DataWriter(stream.GetOutputStreamAt(0)))
        {
            writer.WriteBytes(pdf);
            await writer.StoreAsync();
            await writer.FlushAsync();
            writer.DetachStream();
        }
        stream.Seek(0);
        var doc = await PdfDocument.LoadFromStreamAsync(stream);
        return new PdfPageSource(doc, stream);
    }

    public int Count => (int)_doc.PageCount;

    public SizeF SizeInches(int page)
    {
        using var p = _doc.GetPage((uint)page);
        // PdfPage.Size viene en DIPs (1/96 de pulgada) y ya considera rotación y CropBox.
        return new SizeF((float)(p.Size.Width / 96.0), (float)(p.Size.Height / 96.0));
    }

    public Bitmap Render(int page, int widthPx)
    {
        return RenderAsync(page, widthPx).GetAwaiter().GetResult();
    }

    private async Task<Bitmap> RenderAsync(int page, int widthPx)
    {
        using var p = _doc.GetPage((uint)page);
        widthPx = Math.Clamp(widthPx, 16, 6000);
        double ratio = p.Size.Height / Math.Max(1.0, p.Size.Width);
        var options = new PdfPageRenderOptions
        {
            DestinationWidth = (uint)widthPx,
            DestinationHeight = (uint)Math.Clamp(Math.Round(widthPx * ratio), 16, 30000),
            BackgroundColor = new Windows.UI.Color { A = 255, R = 255, G = 255, B = 255 },
        };
        using var output = new InMemoryRandomAccessStream();
        await p.RenderToStreamAsync(output, options);
        output.Seek(0);
        using var net = output.AsStreamForRead();
        using var decoded = new Bitmap(net);
        // Copia desacoplada del stream (GDI+ exige que el stream viva tanto como el Bitmap).
        return new Bitmap(decoded);
    }

    public void Dispose()
    {
        _stream.Dispose();
    }
}

/// <summary>
/// Página de prueba de 50 × 70 mm (el ancho de la boleta FACTO): muestra si la escala del preset
/// deja ver los cuatro bordes y la regla completa.
/// </summary>
internal sealed class TestPageSource : IPageSource
{
    private const float WidthMm = 50f;
    private const float HeightMm = 70f;
    private readonly string[] _lines;

    public TestPageSource(JobSettings settings, string titulo)
    {
        _lines = new[]
        {
            "RIVERSO PRINT HUB",
            "Prueba de impresión",
            "",
            "Preset: " + settings.Preset,
            "Impresora: " + settings.PrinterLabel,
            "Modo: " + (settings.Modo == "escpos" ? "ESC/POS directo" : "Driver de Windows"),
            "Escala: " + (settings.EscalaModo == "porcentaje" ? settings.EscalaPct + "%" : settings.EscalaModo),
            "Equipo: " + Environment.MachineName,
            DateTime.Now.ToString("dd-MM-yyyy HH:mm:ss"),
            "",
            "Si ves los 4 bordes y la regla",
            "de 0 a 50 mm, la escala está bien.",
        };
    }

    public int Count => 1;

    public SizeF SizeInches(int page) => new(WidthMm / 25.4f, HeightMm / 25.4f);

    public Bitmap Render(int page, int widthPx)
    {
        widthPx = Math.Max(64, widthPx);
        int heightPx = (int)Math.Round(widthPx * HeightMm / WidthMm);
        float pxPerMm = widthPx / WidthMm;
        var bmp = new Bitmap(widthPx, heightPx);
        using var g = Graphics.FromImage(bmp);
        g.Clear(Color.White);
        g.SmoothingMode = SmoothingMode.None;
        g.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;
        using var pen = new Pen(Color.Black, Math.Max(1f, pxPerMm * 0.4f));
        g.DrawRectangle(pen, pen.Width / 2, pen.Width / 2, widthPx - pen.Width, heightPx - pen.Width);

        // Regla superior: marca cada mm, larga cada 5 y número cada 10.
        using var thin = new Pen(Color.Black, Math.Max(1f, pxPerMm * 0.2f));
        using var small = new Font("Arial", Math.Max(5f, pxPerMm * 1.8f), FontStyle.Regular, GraphicsUnit.Pixel);
        for (int mm = 0; mm <= WidthMm; mm++)
        {
            float x = Math.Min(widthPx - 1, mm * pxPerMm);
            float len = mm % 10 == 0 ? 4f : mm % 5 == 0 ? 3f : 1.5f;
            g.DrawLine(thin, x, 0, x, len * pxPerMm);
            if (mm % 10 == 0 && mm > 0 && mm < WidthMm)
            {
                g.DrawString(mm.ToString(), small, Brushes.Black, x - pxPerMm, 4.2f * pxPerMm);
            }
        }

        using var bold = new Font("Arial", Math.Max(6f, pxPerMm * 3.2f), FontStyle.Bold, GraphicsUnit.Pixel);
        using var normal = new Font("Arial", Math.Max(5f, pxPerMm * 2.4f), FontStyle.Regular, GraphicsUnit.Pixel);
        float y = 9f * pxPerMm;
        for (int i = 0; i < _lines.Length; i++)
        {
            var font = i == 0 ? bold : normal;
            g.DrawString(_lines[i], font, Brushes.Black, new RectangleF(2.5f * pxPerMm, y, widthPx - 5f * pxPerMm, font.Height * 2));
            y += font.Height * (i == 0 ? 1.4f : 1.15f);
        }
        return bmp;
    }

    public void Dispose()
    {
    }
}
