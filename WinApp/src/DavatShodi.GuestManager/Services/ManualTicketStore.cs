using System.IO;
using System.Numerics;
using System.Text.Json;

namespace DavatShodi.GuestManager.Services;

public sealed class ManualTicketRecord
{
    public string Id { get; set; } = Guid.NewGuid().ToString("N");
    public string EventCode { get; set; } = "";
    public string TicketId { get; set; } = "";
    public string Title { get; set; } = "";
    public string Quantity { get; set; } = "";
    public DateTime CreatedUtc { get; set; } = DateTime.UtcNow;
    public string Status { get; set; } = "pending";
}

public sealed class ManualTicketTotal
{
    public string Title { get; set; } = "";
    public string Sum { get; set; } = "0";
}

public static class ManualTicketStore
{
    private static readonly string FilePath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "DavatShodi", "GuestManager", "manual-tickets.json");

    private static List<ManualTicketRecord> Read() => File.Exists(FilePath)
        ? JsonSerializer.Deserialize<List<ManualTicketRecord>>(File.ReadAllText(FilePath))
            ?? throw new InvalidDataException("فایل آمار چاپ دستی معتبر نیست.")
        : [];

    private static void Save(List<ManualTicketRecord> records)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(FilePath)!);
        var temporary = FilePath + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            File.WriteAllText(temporary, JsonSerializer.Serialize(records));
            File.Move(temporary, FilePath, true);
        }
        finally { if (File.Exists(temporary)) File.Delete(temporary); }
    }

    public static ManualTicketRecord Begin(string eventCode, string ticketId, string title, string quantity)
    {
        if (quantity.Length is < 1 or > 32 || quantity.Any(c => c is < '0' or > '9'))
            throw new ArgumentException("عدد بلیت معتبر نیست.");
        var records = Read();
        var record = new ManualTicketRecord { EventCode = eventCode, TicketId = ticketId, Title = title, Quantity = quantity };
        records.Add(record);
        Save(records);
        return record;
    }

    public static void SetStatus(string id, string status)
    {
        var records = Read();
        records.Single(r => r.Id == id).Status = status;
        Save(records);
    }

    public static List<ManualTicketTotal> Totals(string eventCode) => Summarize(Read(), eventCode);

    internal static List<ManualTicketTotal> Summarize(IEnumerable<ManualTicketRecord> records, string eventCode) => records
        .Where(r => r.EventCode == eventCode && r.Status == "submitted")
        .GroupBy(r => r.TicketId)
        .Select(g => new ManualTicketTotal
        {
            Title = g.Last().Title,
            Sum = g.Aggregate(BigInteger.Zero, (sum, r) => sum + BigInteger.Parse(r.Quantity,
                System.Globalization.CultureInfo.InvariantCulture)).ToString(System.Globalization.CultureInfo.InvariantCulture)
        }).ToList();
}
