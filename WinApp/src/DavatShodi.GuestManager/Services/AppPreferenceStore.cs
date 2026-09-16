using System.IO;
using System.Text.Json;

namespace DavatShodi.GuestManager.Services;

public static class AppPreferenceStore
{
    private sealed class Preferences
    {
        public string SelectedEventCode { get; set; } = "";
    }

    private static readonly string PreferencesPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "DavatShodi", "GuestManager", "preferences.json");

    public static string LoadSelectedEventCode()
    {
        try
        {
            if (!File.Exists(PreferencesPath)) return "";
            var preferences = JsonSerializer.Deserialize<Preferences>(File.ReadAllText(PreferencesPath));
            return preferences?.SelectedEventCode?.Trim() ?? "";
        }
        catch
        {
            return "";
        }
    }

    public static void SaveSelectedEventCode(string eventCode)
    {
        var normalized = eventCode.Trim();
        if (normalized.Length == 0) return;
        try
        {
            var directory = Path.GetDirectoryName(PreferencesPath);
            if (!string.IsNullOrWhiteSpace(directory)) Directory.CreateDirectory(directory);
            var payload = new Preferences { SelectedEventCode = normalized };
            File.WriteAllText(PreferencesPath, JsonSerializer.Serialize(payload, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch
        {
            // A read-only local profile must not prevent the operator from using the app.
        }
    }
}
