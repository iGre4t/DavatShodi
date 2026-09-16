using System.Windows;
using DavatShodi.GuestManager.Models;

namespace DavatShodi.GuestManager.Views;

public partial class GuestDetailsWindow : Window
{
    public GuestDetailsWindow(AttendanceLog guest)
    {
        InitializeComponent();
        DataContext = guest;
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
