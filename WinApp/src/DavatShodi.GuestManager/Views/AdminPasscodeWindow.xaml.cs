using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Input;

namespace DavatShodi.GuestManager.Views;

public partial class AdminPasscodeWindow : Window
{
    private static readonly Regex DigitsOnly = new("^[0-9۰-۹٠-٩]+$", RegexOptions.Compiled);
    public string Passcode { get; private set; } = "";

    public AdminPasscodeWindow(string prompt)
    {
        InitializeComponent();
        PromptText.Text = prompt;
        Loaded += (_, _) => PasscodeBox.Focus();
    }

    private void PasscodeBox_PreviewTextInput(object sender, TextCompositionEventArgs e) => e.Handled = !DigitsOnly.IsMatch(e.Text);

    private void Confirm_Click(object sender, RoutedEventArgs e)
    {
        var value = NormalizeDigits(PasscodeBox.Password);
        if (value.Length is < 4 or > 6 || value.Any(character => character is < '0' or > '9'))
        {
            ErrorText.Text = "کد باید ۴ تا ۶ رقم باشد.";
            ErrorText.Visibility = Visibility.Visible;
            PasscodeBox.Focus();
            return;
        }
        Passcode = value;
        DialogResult = true;
    }

    private static string NormalizeDigits(string value) => value
        .Replace('۰', '0').Replace('۱', '1').Replace('۲', '2').Replace('۳', '3').Replace('۴', '4')
        .Replace('۵', '5').Replace('۶', '6').Replace('۷', '7').Replace('۸', '8').Replace('۹', '9')
        .Replace('٠', '0').Replace('١', '1').Replace('٢', '2').Replace('٣', '3').Replace('٤', '4')
        .Replace('٥', '5').Replace('٦', '6').Replace('٧', '7').Replace('٨', '8').Replace('٩', '9');
}
