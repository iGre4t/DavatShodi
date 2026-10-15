using System.Text.Json;
using DavatShodi.GuestManager.Models;
using DavatShodi.GuestManager.Services;

static class Program
{
    [STAThread]
    static void Main()
    {
        using var api = new ApiClient("https://example.test/api/winapp.php");
        var guest = new AttendanceLog();
        var printers = new LocalPrinterOptions();
        var legacy = JsonSerializer.Deserialize<PrintProfile>("""
            {"auto_print":true,"ticket_active":false,"ticket_only":true,"configured":false}
            """)!;
        Check(legacy.InviteCardEnabled, "Inactive number tickets must not suppress an invitation card.");
        ExpectIncomplete(() => PrintCardService.PrintAsync(api, legacy, guest, printers).GetAwaiter().GetResult());
        ExpectIncomplete(() => PrintCardService.ReprintAsync(api, legacy, guest, printers).GetAwaiter().GetResult());
        legacy.AutoPrint = false;
        PrintCardService.PrintAsync(api, legacy, guest, printers).GetAwaiter().GetResult();
        // Automatic printing off must not prevent manual reprinting.
        ExpectIncomplete(() => PrintCardService.ReprintAsync(api, legacy, guest, printers).GetAwaiter().GetResult());
        var ticketOnly = JsonSerializer.Deserialize<PrintProfile>("""
            {"auto_print":true,"ticket_active":true,"ticket_only":true,"configured":false}
            """)!;
        Check(!ticketOnly.InviteCardEnabled, "Legacy ticket-only events must retain their selected output.");
        PrintCardService.PrintEntryCardForTicketFlowAsync(api, ticketOnly, guest, printers).GetAwaiter().GetResult();
        var both = JsonSerializer.Deserialize<PrintProfile>("""
            {"auto_print":true,"card_active":true,"ticket_active":true,"ticket_only":true,"configured":false}
            """)!;
        Check(both.InviteCardEnabled, "Explicit invitation selection must take precedence over stale ticket-only.");
        ExpectIncomplete(() => PrintCardService.PrintEntryCardForTicketFlowAsync(api, both, guest, printers).GetAwaiter().GetResult());
        both.CardActive = false;
        PrintCardService.PrintAsync(api, both, guest, printers).GetAwaiter().GetResult();
        Console.WriteLine("PASS: old/new print profiles, independent output switches, auto-print off, manual reprinting, actionable incomplete-template errors. No printer jobs sent.");
    }
    static void ExpectIncomplete(Action action)
    {
        try { action(); throw new Exception("Incomplete invitation templates must not silently skip printing."); }
        catch (InvalidOperationException error) { Check(error.Message.Contains("طرح کارت دعوت"), error.Message); }
    }
    static void Check(bool value, string message) { if (!value) throw new Exception(message); }
}
