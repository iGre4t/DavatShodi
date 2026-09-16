using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Input;

namespace DavatShodi.GuestManager.Views;

public partial class TicketNumberWindow : Window
{
    private static readonly Regex DigitsOnly = new("^[0-9۰-۹٠-٩]+$", RegexOptions.Compiled);
    public string TicketNumber { get; private set; } = "";

    public TicketNumberWindow(string guestName, string ticketTitle)
    {
        InitializeComponent();
        var title = string.IsNullOrWhiteSpace(ticketTitle) ? "Custom Number Ticket" : ticketTitle.Trim();
        Title = $"Number of Ticket - {title}";
        TicketTitleText.Text = title;
        GuestText.Text = $"شماره «{title}» برای {guestName} را وارد کنید؛ سپس Enter بزنید.";
        Loaded += (_, _) => TicketNumberBox.Focus();
    }

    private void TicketNumberBox_PreviewTextInput(object sender, TextCompositionEventArgs e) => e.Handled = !DigitsOnly.IsMatch(e.Text);

    private void Confirm_Click(object sender, RoutedEventArgs e)
    {
        var value = NormalizeDigits(TicketNumberBox.Text);
        if (value.Length is < 1 or > 32 || value.Any(character => character is < '0' or > '9'))
        {
            ErrorText.Text = "یک عدد معتبر وارد کنید.";
            ErrorText.Visibility = Visibility.Visible;
            TicketNumberBox.Focus();
            return;
        }
        TicketNumber = value;
        DialogResult = true;
    }

    private static string NormalizeDigits(string value) => value
        .Replace('۰', '0').Replace('۱', '1').Replace('۲', '2').Replace('۳', '3').Replace('۴', '4')
        .Replace('۵', '5').Replace('۶', '6').Replace('۷', '7').Replace('۸', '8').Replace('۹', '9')
        .Replace('٠', '0').Replace('١', '1').Replace('٢', '2').Replace('٣', '3').Replace('٤', '4')
        .Replace('٥', '5').Replace('٦', '6').Replace('٧', '7').Replace('٨', '8').Replace('٩', '9');
}
