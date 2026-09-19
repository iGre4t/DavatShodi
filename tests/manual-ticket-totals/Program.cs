using DavatShodi.GuestManager.Services;

static ManualTicketRecord Row(string id, string quantity, string status = "submitted", string eventCode = "A") =>
    new() { EventCode = eventCode, TicketId = id, Title = id, Quantity = quantity, Status = status };

var rows = new[] {
    Row("food", "6"), Row("food", "9"), Row("food", "5"),
    Row("gift", "0003"), Row("gift", "7"),
    Row("food", "100", "failed"), Row("food", "100", "pending"),
    Row("food", "999", eventCode: "B"),
    Row("large", "99999999999999999999999999999999"), Row("large", "1")
};
var totals = ManualTicketStore.Summarize(rows, "A").ToDictionary(t => t.Title, t => t.Sum);
if (totals.Count != 3 || totals["food"] != "20" || totals["gift"] != "10" ||
    totals["large"] != "100000000000000000000000000000000")
    throw new Exception("Manual sums or status/event isolation failed.");
if (ManualTicketStore.Summarize(rows, "missing").Count != 0)
    throw new Exception("Empty-event totals failed.");
Console.WriteLine("PASS: exact sums, leading zeros, large integers, event isolation, failed/pending exclusion.");
