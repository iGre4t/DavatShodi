using System.IO;
using System.Printing;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using DavatShodi.GuestManager.Models;
using QRCoder;

namespace DavatShodi.GuestManager.Services;

public sealed class LocalPrinterOptions
{
    public string PrimaryPrinter { get; set; } = "";
    public string SecondaryPrinter { get; set; } = "";
    public bool UseSecondPrinter { get; set; }
    public string TicketPrinter { get; set; } = "";
    public Dictionary<string, string> TicketPrinters { get; set; } = [];
}

public static class PrintCardService
{
    private static readonly string SettingsDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "DavatShodi", "GuestManager");

    private static string SettingsPath(string eventCode)
    {
        var safeCode = Regex.Replace(eventCode.Trim(), "[^A-Za-z0-9_-]+", "-").Trim('-');
        if (string.IsNullOrWhiteSpace(safeCode)) safeCode = "default";
        return Path.Combine(SettingsDirectory, $"printer-settings-{safeCode}.json");
    }

    public static List<string> GetInstalledPrinters()
    {
        try
        {
            using var server = new LocalPrintServer();
            return server.GetPrintQueues().Select(queue => queue.FullName)
                .OrderBy(name => name, StringComparer.CurrentCultureIgnoreCase).ToList();
        }
        catch { return []; }
    }

    public static LocalPrinterOptions LoadOptions(string eventCode)
    {
        try
        {
            var path = SettingsPath(eventCode);
            if (File.Exists(path))
            {
                var options = JsonSerializer.Deserialize<LocalPrinterOptions>(File.ReadAllText(path)) ?? new();
                options.TicketPrinters ??= [];
                return options;
            }
        }
        catch { }
        return new();
    }

    public static void SaveOptions(string eventCode, LocalPrinterOptions options)
    {
        try
        {
            Directory.CreateDirectory(SettingsDirectory);
            File.WriteAllText(SettingsPath(eventCode), JsonSerializer.Serialize(options, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { }
    }

    public static async Task PrintAsync(ApiClient api, PrintProfile profile, AttendanceLog guest, LocalPrinterOptions options)
    {
        if (!profile.AutoPrint || !profile.Configured || profile.Card is null) return;
        var visual = await BuildCardAsync(api, profile.Card, guest);
        var primary = string.IsNullOrWhiteSpace(options.PrimaryPrinter) ? null : options.PrimaryPrinter;
        PrintVisual(visual, primary, "EGM Print Card");
        if (profile.DoublePrint)
        {
            var second = options.UseSecondPrinter && !string.IsNullOrWhiteSpace(options.SecondaryPrinter)
                ? options.SecondaryPrinter : primary;
            PrintVisual(visual, second, "EGM Print Card - Copy 2");
        }
    }

    public static async Task ReprintAsync(ApiClient api, PrintProfile profile, AttendanceLog guest, LocalPrinterOptions options)
    {
        if (!profile.Configured || profile.Card is null)
            throw new InvalidOperationException("طرح Print Card برای این EGM کامل نشده است.");
        var visual = await BuildCardAsync(api, profile.Card, guest);
        var primary = string.IsNullOrWhiteSpace(options.PrimaryPrinter) ? null : options.PrimaryPrinter;
        PrintVisual(visual, primary, "EGM Print Card - Reprint");
    }

    public static async Task PrintEntryCardForTicketFlowAsync(ApiClient api, PrintProfile profile, AttendanceLog guest, LocalPrinterOptions options)
    {
        if (!profile.TicketOnly && (!profile.Configured || profile.Card is null))
            throw new InvalidOperationException("طرح Print Card برای این EGM کامل نشده است.");
        if (profile.TicketOnly) return;
        var primary = string.IsNullOrWhiteSpace(options.PrimaryPrinter) ? null : options.PrimaryPrinter;
        var printCard = await BuildCardAsync(api, profile.Card!, guest);
        PrintVisual(printCard, primary, "EGM Print Card");
        if (profile.DoublePrint)
        {
            var second = options.UseSecondPrinter && !string.IsNullOrWhiteSpace(options.SecondaryPrinter)
                ? options.SecondaryPrinter : primary;
            PrintVisual(printCard, second, "EGM Print Card - Copy 2");
        }
    }

    public static async Task PrintNumberTicketAsync(ApiClient api, NumberTicketDefinition definition, AttendanceLog guest, LocalPrinterOptions options)
    {
        if (!definition.Configured || definition.Card is null)
            throw new InvalidOperationException($"طرح «{definition.Title}» کامل نشده است.");
        if (string.IsNullOrWhiteSpace(guest.NumberOfTicket))
            throw new InvalidOperationException($"شماره «{definition.Title}» وارد نشده است.");
        var fallback = string.IsNullOrWhiteSpace(options.TicketPrinter) ? options.PrimaryPrinter : options.TicketPrinter;
        var selected = options.TicketPrinters.TryGetValue(definition.Id, out var printer) ? printer : fallback;
        var ticket = await BuildCardAsync(api, definition.Card, guest);
        PrintVisual(ticket, string.IsNullOrWhiteSpace(selected) ? null : selected, $"EGM {definition.Title}", 75d);
    }

    private static async Task<Canvas> BuildCardAsync(ApiClient api, PrintCardConfig card, AttendanceLog guest)
    {
        const double size = 2400;
        var cardFont = ResolveCardFont(card);
        var canvas = new Canvas { Width = size, Height = size, Background = Brushes.White };
        var backgroundBytes = await api.GetAssetBytesAsync(card.ImageData);
        if (backgroundBytes is { Length: > 0 })
        {
            var image = new Image { Width = size, Height = size, Stretch = Stretch.Fill };
            image.Source = Bitmap(backgroundBytes);
            canvas.Children.Add(image);
        }
        if (card.QrRect is not null)
        {
            using var generator = new QRCodeGenerator();
            using var data = generator.CreateQrCode(guest.NationalId, QRCodeGenerator.ECCLevel.M);
            var qrBytes = new PngByteQRCode(data).GetGraphic(12);
            var rect = card.QrRect;
            var side = Math.Min(rect.Width, rect.Height) * size / 100d;
            var qr = new Image { Source = Bitmap(qrBytes), Width = side, Height = side, Stretch = Stretch.Uniform };
            Canvas.SetLeft(qr, (rect.X + (rect.Width - Math.Min(rect.Width, rect.Height)) / 2d) * size / 100d);
            Canvas.SetTop(qr, (rect.Y + (rect.Height - Math.Min(rect.Width, rect.Height)) / 2d) * size / 100d);
            canvas.Children.Add(qr);
        }
        if (card.TextRect is not null)
        {
            AddTextBlock(canvas, card.TextRect, string.IsNullOrWhiteSpace(card.TextHtml) ? card.Text : card.TextHtml, guest, size, cardFont);
        }
        foreach (var area in card.TextAreas.Where(area => area.Rect is not null))
            AddTextBlock(canvas, area.Rect!, string.IsNullOrWhiteSpace(area.TextHtml) ? area.Text : area.TextHtml, guest, size, cardFont);
        if (card.TicketCountRect is not null)
            AddTextBlock(canvas, card.TicketCountRect, "[ticketcount]", guest, size, cardFont, true);
        canvas.Measure(new Size(size, size));
        canvas.Arrange(new Rect(0, 0, size, size));
        canvas.UpdateLayout();
        return canvas;
    }

    private static FontFamily ResolveCardFont(PrintCardConfig card)
    {
        if (card.FontName.Contains("IRANSans", StringComparison.OrdinalIgnoreCase))
        {
            return new FontFamily(
                new Uri("pack://application:,,,/DavatShodi.GuestManager;component/", UriKind.Absolute),
                "./Assets/Fonts/#IRANSansXFaNum");
        }
        return new FontFamily("PeydaFaNum, Segoe UI");
    }

    private static void AddTextBlock(Canvas canvas, PercentRect rect, string template, AttendanceLog guest, double size, FontFamily fontFamily, bool emphasize = false)
    {
        var resolved = ResolveText(template, guest);
        var width = rect.Width * size / 100d;
        var height = rect.Height * size / 100d;
        var lines = Math.Max(1, resolved.Split('\n').Length);
        var longestLine = Math.Max(1, resolved.Split('\n').Max(line => line.Length));
        var byHeight = height / (lines * 1.35d);
        var byWidth = width / (longestLine * 0.62d);
        var fontSize = Math.Max(24, Math.Min(360, Math.Min(byHeight, byWidth)));
        var isBold = emphasize || Regex.IsMatch(template, "<(?:strong|b)(?:\\s|>)", RegexOptions.IgnoreCase);
        var block = new TextBlock
        {
            Text = resolved,
            Width = width,
            TextWrapping = TextWrapping.Wrap,
            TextAlignment = TextAlignment.Center,
            FlowDirection = FlowDirection.RightToLeft,
            FontFamily = fontFamily,
            FontSize = fontSize,
            FontWeight = isBold ? FontWeights.Bold : FontWeights.SemiBold,
            VerticalAlignment = VerticalAlignment.Center,
            HorizontalAlignment = HorizontalAlignment.Stretch,
            LineHeight = fontSize * 1.18
        };
        var colorMatch = Regex.Match(template, "color\\s*:\\s*(#[0-9a-f]{3,8})", RegexOptions.IgnoreCase);
        if (colorMatch.Success)
        {
            try { block.Foreground = (Brush)new BrushConverter().ConvertFromString(colorMatch.Groups[1].Value)!; }
            catch { block.Foreground = Brushes.Black; }
        }
        var host = new Grid { Width = width, Height = height };
        host.Children.Add(block);
        Canvas.SetLeft(host, rect.X * size / 100d);
        Canvas.SetTop(host, rect.Y * size / 100d);
        canvas.Children.Add(host);
    }

    private static string ResolveText(string template, AttendanceLog guest)
    {
        var value = Regex.Replace(template, "<(?:br|/p|/div|/li)[^>]*>", "\n", RegexOptions.IgnoreCase);
        value = Regex.Replace(value, "<[^>]+>", "");
        value = System.Net.WebUtility.HtmlDecode(value);
        var fields = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["fullname"] = guest.FullName, ["firstname"] = guest.FirstName, ["lastname"] = guest.LastName,
            ["nationalid"] = guest.NationalId, ["workid"] = guest.WorkId, ["guestnumber"] = guest.GuestNumber,
            ["phonenumber"] = guest.PhoneNumber, ["deputy"] = guest.Deputy,
            ["generaldepartment"] = guest.GeneralDepartment, ["department"] = guest.Department,
            ["gender"] = guest.Gender, ["postallevel"] = guest.PostalLevel, ["score"] = "0"
            , ["ticketcount"] = guest.NumberOfTicket
            , ["tickettitle"] = guest.TicketTitle
        };
        foreach (var field in fields) value = Regex.Replace(value, $"\\[{Regex.Escape(field.Key)}\\]", field.Value ?? "", RegexOptions.IgnoreCase);
        return value.Trim();
    }

    private static BitmapImage Bitmap(byte[] bytes)
    {
        using var stream = new MemoryStream(bytes);
        var bitmap = new BitmapImage();
        bitmap.BeginInit();
        bitmap.CacheOption = BitmapCacheOption.OnLoad;
        bitmap.StreamSource = stream;
        bitmap.EndInit();
        bitmap.Freeze();
        return bitmap;
    }

    private static void PrintVisual(Visual visual, string? printerName, string title, double? paperWidthMillimeters = null)
    {
        var dialog = new PrintDialog();
        if (string.IsNullOrWhiteSpace(printerName)) printerName = dialog.PrintQueue.FullName;
        if (!string.IsNullOrWhiteSpace(printerName))
        {
            using var server = new LocalPrintServer();
            var queue = server.GetPrintQueue(printerName);
            dialog.PrintQueue = queue;
            var ticket = queue.DefaultPrintTicket.Clone();
            ticket.PageOrientation = PageOrientation.Portrait;
            ticket.PageScalingFactor = 100;
            if (paperWidthMillimeters is > 0)
            {
                var pageSize = paperWidthMillimeters.Value / 25.4d * 96d;
                ticket.PageMediaSize = new PageMediaSize(pageSize, pageSize);
            }
            try
            {
                dialog.PrintTicket = queue.MergeAndValidatePrintTicket(queue.DefaultPrintTicket, ticket).ValidatedPrintTicket;
            }
            catch
            {
                dialog.PrintTicket = queue.DefaultPrintTicket;
            }
        }
        var capabilities = dialog.PrintQueue.GetPrintCapabilities(dialog.PrintTicket);
        var imageable = capabilities.PageImageableArea;
        var width = imageable?.ExtentWidth ?? dialog.PrintableAreaWidth;
        var height = imageable?.ExtentHeight ?? dialog.PrintableAreaHeight;
        if (width <= 0 || height <= 0) throw new InvalidOperationException("ابعاد قابل چاپ چاپگر معتبر نیست؛ اندازه کاغذ را در تنظیمات چاپگر بررسی کنید.");
        var pageWidth = dialog.PrintTicket.PageMediaSize?.Width ?? width;
        var pageHeight = dialog.PrintTicket.PageMediaSize?.Height ?? height;
        var originX = imageable?.OriginWidth ?? 0;
        var originY = imageable?.OriginHeight ?? 0;
        if (paperWidthMillimeters is > 0)
        {
            // Roll drivers may validate a custom receipt back to a very long
            // default form. Never use that form's height as the document size.
            pageWidth = pageHeight = paperWidthMillimeters.Value / 25.4d * 96d;
            dialog.PrintTicket.PageMediaSize = new PageMediaSize(pageWidth, pageHeight);
            dialog.PrintTicket.PageScalingFactor = 100;
            width = Math.Min(width, pageWidth - 2 * originX);
            height = Math.Min(height, pageHeight - 2 * originY);
            if (width <= 0 || height <= 0) throw new InvalidOperationException("حاشیه چاپگر با رسید ۷۵×۷۵ میلی‌متر سازگار نیست؛ فرم کاغذ چاپگر را بررسی کنید.");
        }
        var area = Math.Min(width, height);
        var container = new Viewbox { Width = area, Height = area, Stretch = Stretch.Uniform, Child = (UIElement)visual };
        var page = new Canvas { Width = pageWidth, Height = pageHeight, Background = Brushes.White };
        Canvas.SetLeft(container, originX + (width - area) / 2);
        Canvas.SetTop(container, originY + (height - area) / 2);
        page.Children.Add(container);
        page.Measure(new Size(pageWidth, pageHeight));
        page.Arrange(new Rect(0, 0, pageWidth, pageHeight));
        if (paperWidthMillimeters is > 0)
        {
            var fixedPage = new System.Windows.Documents.FixedPage { Width = pageWidth, Height = pageHeight };
            fixedPage.Children.Add(page);
            var pageContent = new System.Windows.Documents.PageContent();
            ((System.Windows.Markup.IAddChild)pageContent).AddChild(fixedPage);
            var document = new System.Windows.Documents.FixedDocument();
            document.DocumentPaginator.PageSize = new Size(pageWidth, pageHeight);
            document.Pages.Add(pageContent);
            dialog.PrintDocument(document.DocumentPaginator, title);
        }
        else dialog.PrintVisual(page, title);
        container.Child = null;
    }
}
