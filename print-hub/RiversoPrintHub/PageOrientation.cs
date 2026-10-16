using System.Text.RegularExpressions;
using Windows.Graphics.Imaging;
using Windows.Media.Ocr;
using Windows.Storage.Streams;

namespace RiversoPrintHub;

/// <summary>
/// Decide si cada página escaneada va derecha o dada vuelta (0 | 180) leyéndola con el OCR de
/// Windows en los dos sentidos: al revés el OCR devuelve casi pura basura. Ante la duda (página en
/// blanco, poco texto, puntajes parecidos) deja el giro base.
/// </summary>
internal static class PageOrientation
{
    // Con facturas reales a 200 ppp el sentido correcto dio unas 2× palabras válidas (253 vs 95, 136 vs 68).
    private const double Margin = 1.5;
    private const int MinWords = 15;
    // Más resolución no mejora la decisión y hace más lento el OCR.
    private const int MaxSide = 2200;

    private static readonly Regex ValidWord = new(@"^([A-Za-zÁÉÍÓÚÑáéíóúñ]{3,}[.:,]?|\$?\d[\d.,/-]*\d)$", RegexOptions.Compiled);

    /// <summary>Giro final (0 | 180) de cada página; si no hay OCR, todas quedan con baseRotation.</summary>
    public static async Task<int[]> DecideAsync(List<byte[]> jpegs, int baseRotation, CancellationToken ct)
    {
        var result = Enumerable.Repeat(baseRotation, jpegs.Count).ToArray();
        OcrEngine? engine;
        try
        {
            engine = OcrEngine.TryCreateFromUserProfileLanguages();
        }
        catch (Exception ex)
        {
            Log.Error("Orientación: el OCR de Windows no está disponible: " + ex.Message);
            return result;
        }
        if (engine is null)
        {
            Log.Info("Orientación: Windows no tiene un idioma de OCR instalado; se usa el giro fijo.");
            return result;
        }
        for (int i = 0; i < jpegs.Count; i++)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                int upright = await ScoreAsync(engine, jpegs[i], BitmapRotation.None);
                int flipped = await ScoreAsync(engine, jpegs[i], BitmapRotation.Clockwise180Degrees);
                int? pick = Pick(upright, flipped);
                result[i] = pick ?? baseRotation;
                Log.Info($"Orientación página {i + 1}: {upright} palabras derecha, {flipped} al revés → {result[i]}°{(pick is null ? " (sin decisión, giro fijo)" : "")}");
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                Log.Error($"Orientación página {i + 1}: {ex.Message}; se usa el giro fijo.");
            }
        }
        return result;
    }

    private static int? Pick(int upright, int flipped)
    {
        if (Math.Max(upright, flipped) < MinWords)
        {
            return null;
        }
        if (flipped >= upright * Margin)
        {
            return 180;
        }
        if (upright >= flipped * Margin)
        {
            return 0;
        }
        return null;
    }

    /// <summary>Cuántas palabras con pinta de texto real (palabras o montos) lee el OCR en ese sentido.</summary>
    private static async Task<int> ScoreAsync(OcrEngine engine, byte[] jpeg, BitmapRotation rotation)
    {
        using var bitmap = await DecodeAsync(jpeg, rotation);
        var ocr = await engine.RecognizeAsync(bitmap);
        return ocr.Lines.SelectMany(l => l.Words).Count(w => ValidWord.IsMatch(w.Text));
    }

    private static async Task<SoftwareBitmap> DecodeAsync(byte[] jpeg, BitmapRotation rotation)
    {
        using var stream = new InMemoryRandomAccessStream();
        using (var writer = new DataWriter(stream.GetOutputStreamAt(0)))
        {
            writer.WriteBytes(jpeg);
            await writer.StoreAsync();
            await writer.FlushAsync();
            writer.DetachStream();
        }
        stream.Seek(0);
        var decoder = await BitmapDecoder.CreateAsync(stream);
        var transform = new BitmapTransform { Rotation = rotation };
        int maxSide = Math.Min(MaxSide, (int)OcrEngine.MaxImageDimension);
        uint longest = Math.Max(decoder.PixelWidth, decoder.PixelHeight);
        if (longest > maxSide)
        {
            double f = (double)maxSide / longest;
            transform.ScaledWidth = (uint)Math.Max(1, decoder.PixelWidth * f);
            transform.ScaledHeight = (uint)Math.Max(1, decoder.PixelHeight * f);
        }
        return await decoder.GetSoftwareBitmapAsync(BitmapPixelFormat.Bgra8, BitmapAlphaMode.Premultiplied, transform,
            ExifOrientationMode.IgnoreExifOrientation, ColorManagementMode.DoNotColorManage);
    }
}
