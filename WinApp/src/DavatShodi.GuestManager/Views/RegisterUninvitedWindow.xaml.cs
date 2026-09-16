using System.Windows;
using DavatShodi.GuestManager.Models;

namespace DavatShodi.GuestManager.Views;

public partial class RegisterUninvitedWindow : Window
{
    public WalkInRegistration Registration { get; private set; } = new();

    public RegisterUninvitedWindow(AttendanceLog source, UninvitedOptions options)
    {
        InitializeComponent();
        FirstNameBox.Text = source.FirstName;
        LastNameBox.Text = source.LastName;
        NationalIdBox.Text = source.NationalId;
        WorkIdBox.Text = source.WorkId;
        PhoneBox.Text = source.PhoneNumber;
        OutsideOrganizationCheck.IsChecked = source.OutsideOrganization;
        DeputyCombo.ItemsSource = options.Deputies;
        GeneralDepartmentCombo.ItemsSource = options.GeneralDepartments;
        DepartmentCombo.ItemsSource = options.Departments;
        GenderCombo.ItemsSource = options.Genders.Count > 0 ? options.Genders : ["مرد", "زن"];
        PostalLevelCombo.ItemsSource = options.PostalLevels;
        DeputyCombo.Text = source.Deputy;
        GeneralDepartmentCombo.Text = source.GeneralDepartment;
        DepartmentCombo.Text = source.Department;
        GenderCombo.Text = source.Gender;
        PostalLevelCombo.Text = source.PostalLevel;
        UpdateOrganizationFields();
    }

    private void OutsideOrganizationCheck_Changed(object sender, RoutedEventArgs e) => UpdateOrganizationFields();

    private void UpdateOrganizationFields()
    {
        if (DeputyCombo is null) return;
        var enabled = OutsideOrganizationCheck.IsChecked != true;
        WorkIdBox.IsEnabled = enabled;
        DeputyCombo.IsEnabled = enabled;
        GeneralDepartmentCombo.IsEnabled = enabled;
        DepartmentCombo.IsEnabled = enabled;
    }

    private void SaveButton_Click(object sender, RoutedEventArgs e)
    {
        var outside = OutsideOrganizationCheck.IsChecked == true;
        var nationalId = NormalizeDigits(NationalIdBox.Text);
        if (string.IsNullOrWhiteSpace(FirstNameBox.Text) || string.IsNullOrWhiteSpace(LastNameBox.Text))
        {
            ValidationText.Text = "نام و نام خانوادگی مهمان الزامی است.";
            return;
        }
        if (nationalId.Length != 10 || nationalId.Any(character => !char.IsDigit(character)))
        {
            ValidationText.Text = "کد ملی مهمان باید دقیقاً ۱۰ رقم باشد.";
            return;
        }
        if (!outside && (string.IsNullOrWhiteSpace(WorkIdBox.Text) || string.IsNullOrWhiteSpace(DeputyCombo.Text) || string.IsNullOrWhiteSpace(GeneralDepartmentCombo.Text) || string.IsNullOrWhiteSpace(DepartmentCombo.Text)))
        {
            ValidationText.Text = "برای مهمان سازمانی، کد پرسنلی، معاونت، اداره کل و اداره الزامی است.";
            return;
        }
        Registration = new WalkInRegistration
        {
            FirstName = FirstNameBox.Text.Trim(), LastName = LastNameBox.Text.Trim(), NationalId = nationalId,
            WorkId = outside ? "" : WorkIdBox.Text.Trim(), PhoneNumber = PhoneBox.Text.Trim(),
            Deputy = outside ? "" : DeputyCombo.Text.Trim(), GeneralDepartment = outside ? "" : GeneralDepartmentCombo.Text.Trim(),
            Department = outside ? "" : DepartmentCombo.Text.Trim(), Gender = GenderCombo.Text.Trim(),
            PostalLevel = PostalLevelCombo.Text.Trim(), OutsideOrganization = outside
        };
        DialogResult = true;
    }

    private static string NormalizeDigits(string value) => value
        .Replace('۰', '0').Replace('۱', '1').Replace('۲', '2').Replace('۳', '3').Replace('۴', '4')
        .Replace('۵', '5').Replace('۶', '6').Replace('۷', '7').Replace('۸', '8').Replace('۹', '9')
        .Replace('٠', '0').Replace('١', '1').Replace('٢', '2').Replace('٣', '3').Replace('٤', '4')
        .Replace('٥', '5').Replace('٦', '6').Replace('٧', '7').Replace('٨', '8').Replace('٩', '9');
}
