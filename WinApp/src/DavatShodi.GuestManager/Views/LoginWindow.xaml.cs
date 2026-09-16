using System.ComponentModel;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using DavatShodi.GuestManager.Models;
using DavatShodi.GuestManager.Services;

namespace DavatShodi.GuestManager.Views;

public partial class LoginWindow : Window
{
    private readonly ApiClient _api;
    private BrandingInfo _branding = new();
    private bool _openingMainWindow;

    public LoginWindow(ApiClient api)
    {
        InitializeComponent();
        _api = api;
        Loaded += LoginWindow_Loaded;
    }

    private async void LoginWindow_Loaded(object sender, RoutedEventArgs e)
    {
        var brandingResponse = await _api.GetBrandingAsync();
        if (brandingResponse.Status == "ok" && brandingResponse.Branding is not null)
        {
            _branding = brandingResponse.Branding;
            await ApplyBrandingAsync();
        }
        var saved = CredentialStore.Load();
        if (saved is not null)
        {
            UsernameBox.Text = saved.Value.Username;
            PasswordBox.Password = saved.Value.Password;
            RememberPasswordCheckBox.IsChecked = true;
            PasswordBox.Focus();
        }
        else
        {
            UsernameBox.Focus();
        }
    }

    private async Task ApplyBrandingAsync()
    {
        BrandingManager.ApplyPrimaryColor(_branding);
        PanelNameText.Text = string.IsNullOrWhiteSpace(_branding.PanelName) ? "مدیریت مهمانان رویداد" : _branding.PanelName;
        Title = $"ورود | {PanelNameText.Text}";
        var logo = await BrandingManager.LoadLogoAsync(_api, _branding);
        BrandLogoImage.Source = logo;
        BrandLogoImage.Visibility = logo is null ? Visibility.Collapsed : Visibility.Visible;
        BrandLogoText.Visibility = logo is null ? Visibility.Visible : Visibility.Collapsed;
        BrandLogoBorder.Background = logo is null
            ? (Brush)Application.Current.Resources["PrimaryBrush"]
            : Brushes.Transparent;
    }

    private async void LoginButton_Click(object sender, RoutedEventArgs e) => await LoginAsync();

    private async Task LoginAsync()
    {
        if (!LoginButton.IsEnabled) return;
        ErrorText.Text = "";
        LoginButton.IsEnabled = false;
        LoginButton.Content = "در حال ورود...";
        var response = await _api.LoginAsync(UsernameBox.Text.Trim(), PasswordBox.Password);
        LoginButton.IsEnabled = true;
        LoginButton.Content = "ورود";
        if (response.Status != "ok")
        {
            ErrorText.Text = response.Message;
            PasswordBox.SelectAll();
            PasswordBox.Focus();
            return;
        }

        if (response.Branding is not null)
        {
            _branding = response.Branding;
            await ApplyBrandingAsync();
        }

        try
        {
            if (RememberPasswordCheckBox.IsChecked == true)
                CredentialStore.Save(UsernameBox.Text.Trim(), PasswordBox.Password);
            else
                CredentialStore.Delete();
        }
        catch (Exception)
        {
            MessageBox.Show(
                "ورود انجام شد، اما ویندوز نتوانست اطلاعات ورود را ذخیره کند.",
                "ذخیره اطلاعات ورود",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }

        _openingMainWindow = true;
        var main = new MainWindow(_api, response.User?.Name ?? response.User?.Username ?? "کاربر", _branding);
        Application.Current.MainWindow = main;
        main.Show();
        Close();
    }

    private void UsernameBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter) PasswordBox.Focus();
    }

    private async void PasswordBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter) await LoginAsync();
    }

    protected override void OnClosing(CancelEventArgs e)
    {
        base.OnClosing(e);
        if (!_openingMainWindow) Application.Current.Shutdown();
    }
}
