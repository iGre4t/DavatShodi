using System.IO;
using System.Text.Json;
using System.Windows;
using DavatShodi.GuestManager.Services;
using DavatShodi.GuestManager.Views;

namespace DavatShodi.GuestManager;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        var apiUrl = LoadApiUrl();
        var api = new ApiClient(apiUrl);
        var login = new LoginWindow(api);
        MainWindow = login;
        login.Show();
    }

    private static string LoadApiUrl()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            var value = document.RootElement.GetProperty("ApiUrl").GetString();
            if (Uri.TryCreate(value, UriKind.Absolute, out _)) return value!;
        }
        catch (Exception)
        {
            // The actionable URL and file name are included in the error below.
        }
        MessageBox.Show($"ApiUrl در فایل زیر معتبر نیست:\n{path}", "تنظیمات برنامه", MessageBoxButton.OK, MessageBoxImage.Error);
#if ONLINE_RELEASE
        return "https://davatshodi.ir/api/winapp.php";
#else
        return "http://localhost/DavatShodi/api/winapp.php";
#endif
    }
}
