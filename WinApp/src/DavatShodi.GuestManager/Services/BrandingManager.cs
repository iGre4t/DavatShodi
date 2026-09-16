using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using DavatShodi.GuestManager.Models;

namespace DavatShodi.GuestManager.Services;

public static class BrandingManager
{
    public static void ApplyPrimaryColor(BrandingInfo branding)
    {
        Application.Current.Resources["PrimaryBrush"] = new SolidColorBrush(Color.FromRgb(29, 150, 225));
    }

    public static Task<ImageSource?> LoadLogoAsync(ApiClient api, BrandingInfo branding)
    {
        try
        {
            var image = new BitmapImage();
            image.BeginInit();
            image.CacheOption = BitmapCacheOption.OnLoad;
            image.UriSource = new Uri("pack://application:,,,/DavatShodi.GuestManager;component/Assets/Brand/HamrahIcon.png", UriKind.Absolute);
            image.EndInit();
            image.Freeze();
            return Task.FromResult<ImageSource?>(image);
        }
        catch (Exception)
        {
            return Task.FromResult<ImageSource?>(null);
        }
    }
}
