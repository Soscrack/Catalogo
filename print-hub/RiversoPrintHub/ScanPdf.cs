using System.Drawing.Imaging;
using System.Globalization;
using System.Text;

namespace RiversoPrintHub;

/// <summary>
/// Arma un PDF con una página por JPEG escaneado. El JPEG se incrusta tal cual (DCTDecode),
/// sin recodificar, salvo que el total pase del tamaño que acepta el servidor.
/// </summary>
internal static class ScanPdf
{
    private static readonly long[] FallbackQualities = { 60, 40, 25 };

    public static byte[] Build(List<byte[]> jpegs, int dpi, int maxBytes)
    {
        var pages = jpegs;
        foreach (var quality in FallbackQualities)
        {
            if (pages.Sum(p => (long)p.Length) + 1024L * pages.Count <= maxBytes)
            {
                break;
            }
            pages = jpegs.Select(j => Recompress(j, quality)).ToList();
        }
        var pdf = Write(pages, dpi);
        if (pdf.Length > maxBytes)
        {
            throw new ScanException("too_large");
        }
        return pdf;
    }

    private static byte[] Recompress(byte[] jpeg, long quality)
    {
        using var input = new MemoryStream(jpeg);
        using var image = Image.FromStream(input);
        var codec = ImageCodecInfo.GetImageEncoders().First(c => c.FormatID == ImageFormat.Jpeg.Guid);
        using var parameters = new EncoderParameters(1);
        parameters.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, quality);
        using var output = new MemoryStream();
        image.Save(output, codec, parameters);
        return output.ToArray();
    }

    private static byte[] Write(List<byte[]> jpegs, int dpi)
    {
        using var ms = new MemoryStream();
        var offsets = new List<long>();
        int count = jpegs.Count;

        void Text(string s)
        {
            var b = Encoding.Latin1.GetBytes(s);
            ms.Write(b, 0, b.Length);
        }
        void Begin(int number)
        {
            while (offsets.Count < number)
            {
                offsets.Add(0);
            }
            offsets[number - 1] = ms.Position;
            Text(number + " 0 obj\n");
        }
        static string Num(double v) => v.ToString("0.##", CultureInfo.InvariantCulture);

        // Objetos: 1 catálogo, 2 árbol de páginas y, por página i, 3+3i página, 4+3i contenido, 5+3i imagen.
        Text("%PDF-1.4\n%âãÏÓ\n");
        Begin(1);
        Text("<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");
        Begin(2);
        Text("<< /Type /Pages /Count " + count + " /Kids [" + string.Join(" ", Enumerable.Range(0, count).Select(i => (3 + 3 * i) + " 0 R")) + "] >>\nendobj\n");

        for (int i = 0; i < count; i++)
        {
            var jpeg = jpegs[i];
            var (width, height, components) = JpegInfo(jpeg);
            string w = Num(width * 72.0 / dpi);
            string h = Num(height * 72.0 / dpi);
            int pageObj = 3 + 3 * i;

            Begin(pageObj);
            Text("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " + w + " " + h + "] /Contents " + (pageObj + 1)
                + " 0 R /Resources << /XObject << /Im0 " + (pageObj + 2) + " 0 R >> >> >>\nendobj\n");

            string content = "q " + w + " 0 0 " + h + " 0 0 cm /Im0 Do Q\n";
            Begin(pageObj + 1);
            Text("<< /Length " + content.Length + " >>\nstream\n" + content + "endstream\nendobj\n");

            string colorSpace = components switch { 1 => "/DeviceGray", 4 => "/DeviceCMYK", _ => "/DeviceRGB" };
            Begin(pageObj + 2);
            Text("<< /Type /XObject /Subtype /Image /Width " + width + " /Height " + height + " /ColorSpace " + colorSpace
                + " /BitsPerComponent 8 /Filter /DCTDecode /Length " + jpeg.Length + " >>\nstream\n");
            ms.Write(jpeg, 0, jpeg.Length);
            Text("\nendstream\nendobj\n");
        }

        long xref = ms.Position;
        Text("xref\n0 " + (offsets.Count + 1) + "\n0000000000 65535 f \n");
        foreach (var offset in offsets)
        {
            Text(offset.ToString("D10", CultureInfo.InvariantCulture) + " 00000 n \n");
        }
        Text("trailer\n<< /Size " + (offsets.Count + 1) + " /Root 1 0 R >>\nstartxref\n" + xref + "\n%%EOF\n");
        return ms.ToArray();
    }

    /// <summary>Ancho, alto y cantidad de componentes de color, leídos del encabezado SOF del JPEG.</summary>
    private static (int Width, int Height, int Components) JpegInfo(byte[] d)
    {
        if (d.Length < 4 || d[0] != 0xFF || d[1] != 0xD8)
        {
            throw new ScanException("scan_failed", "el escáner entregó una página que no es JPEG");
        }
        int i = 2;
        while (i + 9 < d.Length)
        {
            if (d[i] != 0xFF)
            {
                i++;
                continue;
            }
            byte marker = d[i + 1];
            if (marker == 0xFF)
            {
                i++; // Relleno.
                continue;
            }
            if (marker is 0xD8 or 0x01 or (>= 0xD0 and <= 0xD7))
            {
                i += 2; // Marcadores sin datos.
                continue;
            }
            int length = (d[i + 2] << 8) | d[i + 3];
            if (marker is >= 0xC0 and <= 0xCF && marker is not (0xC4 or 0xC8 or 0xCC))
            {
                return ((d[i + 7] << 8) | d[i + 8], (d[i + 5] << 8) | d[i + 6], d[i + 9]);
            }
            i += 2 + length;
        }
        throw new ScanException("scan_failed", "no se pudo leer el tamaño de una página escaneada");
    }
}
