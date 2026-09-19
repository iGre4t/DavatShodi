using System.ComponentModel;
using System.Media;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using DavatShodi.GuestManager.Models;
using DavatShodi.GuestManager.Services;

namespace DavatShodi.GuestManager.Views;

public partial class MainWindow : Window
{
    private static readonly Regex NonDigit = new("[^0-9]+", RegexOptions.Compiled);
    private readonly ApiClient _api;
    private readonly BrandingInfo _branding;
    private WinAppAccessInfo _access = new();
    private List<AttendanceLog> _logs = [];
    private bool _busy;
    private bool _switchingWindows;
    private LocalPrinterOptions _printerOptions = new();
    private bool _loadingPrinters;
    private bool _loadingPrintSettings;
    private PrintProfile _printProfile = new();
    private List<string> _installedPrinters = [];
    private readonly Dictionary<string, ComboBox> _ticketPrinterCombos = [];
    private AdminSecurityInfo _adminSecurity = new();
    private string _currentPeriodCode = "";
    private DateTime _adminUnlockUntilUtc = DateTime.MinValue;
    private string _temporaryAdminPasscode = "";
    private System.Windows.Forms.NotifyIcon? _trayIcon;
    private bool _fullyQuitting;
    private readonly System.Windows.Threading.DispatcherTimer _liveRefreshTimer = new() { Interval = TimeSpan.FromSeconds(2) };
    private bool _liveRefreshInFlight;
    private long _foregroundRevision;
    private bool _fullscreen;
    private WindowStyle _previousWindowStyle;
    private ResizeMode _previousResizeMode;
    private WindowState _previousWindowState;

    public MainWindow(ApiClient api, string displayName, BrandingInfo branding)
    {
        InitializeComponent();
        _api = api;
        _branding = branding;
        UserText.Text = string.IsNullOrWhiteSpace(displayName) ? "اپراتور سامانه" : displayName;
        Loaded += MainWindow_Loaded;
        PreviewKeyDown += (_, e) =>
        {
            if (e.Key != Key.F11) return;
            e.Handled = true;
            if (!_fullscreen)
            {
                _previousWindowStyle = WindowStyle;
                _previousResizeMode = ResizeMode;
                _previousWindowState = WindowState;
                WindowState = WindowState.Normal;
                WindowStyle = WindowStyle.None;
                ResizeMode = ResizeMode.NoResize;
                WindowState = WindowState.Maximized;
            }
            else
            {
                WindowState = WindowState.Normal;
                WindowStyle = _previousWindowStyle;
                ResizeMode = _previousResizeMode;
                WindowState = _previousWindowState;
            }
            _fullscreen = !_fullscreen;
        };
        _liveRefreshTimer.Tick += async (_, _) => await RefreshLiveAsync();
        Closed += (_, _) => { _liveRefreshTimer.Stop(); _trayIcon?.Dispose(); };
        Activated += async (_, _) => await RefreshLiveAsync();
    }

    private EventInfo? SelectedEvent => EventCombo.SelectedItem as EventInfo;

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        await ApplyBrandingAsync();
        var response = await _api.GetEventsAsync();
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            return;
        }
        EventCombo.ItemsSource = response.Events;
        if (response.Events.Count > 0)
        {
            var rememberedCode = AppPreferenceStore.LoadSelectedEventCode();
            EventCombo.SelectedItem = response.Events.FirstOrDefault(item =>
                string.Equals(item.Code, rememberedCode, StringComparison.OrdinalIgnoreCase)) ?? response.Events[0];
        }
        else ShowResult("هیچ رویداد EGM در دسترس نیست.", "error");
        _liveRefreshTimer.Start();
    }

    private async Task ApplyBrandingAsync()
    {
        await ApplyWindowBrandingAsync(_branding);
        ShowPage("scan");
    }

    private async Task ApplyEventBrandingAsync(EventInfo selected)
    {
        var eventBranding = new BrandingInfo
        {
            PanelName = string.IsNullOrWhiteSpace(selected.Name) ? "رویداد" : selected.Name,
            PrimaryColor = string.IsNullOrWhiteSpace(selected.PrimaryColor) ? _branding.PrimaryColor : selected.PrimaryColor,
            LogoUrl = string.IsNullOrWhiteSpace(selected.LogoUrl) ? _branding.LogoUrl : selected.LogoUrl
        };
        await ApplyWindowBrandingAsync(eventBranding);
        SelectedEventNameText.Text = eventBranding.PanelName;
        EventHeroNameText.Text = eventBranding.PanelName;
        HeaderEventText.Text = $"رویداد انتخاب‌شده: {eventBranding.PanelName}";
        var logo = await BrandingManager.LoadLogoAsync(_api, eventBranding);
        SelectedEventLogoImage.Source = logo;
        SelectedEventLogoImage.Visibility = logo is null ? Visibility.Collapsed : Visibility.Visible;
        SelectedEventLogoText.Visibility = logo is null ? Visibility.Visible : Visibility.Collapsed;
        SelectedEventLogoBorder.Background = logo is null ? (Brush)Application.Current.Resources["PrimaryBrush"] : Brushes.Transparent;
    }

    private async Task ApplyWindowBrandingAsync(BrandingInfo branding)
    {
        BrandingManager.ApplyPrimaryColor(branding);
        var panelName = string.IsNullOrWhiteSpace(branding.PanelName) ? "DavatShodi" : branding.PanelName;
        Title = "MCI Event Guest Manager";
        var logo = await BrandingManager.LoadLogoAsync(_api, branding);
        SidebarLogoImage.Source = logo;
        SidebarLogoImage.Visibility = logo is null ? Visibility.Collapsed : Visibility.Visible;
        SidebarLogoText.Visibility = logo is null ? Visibility.Visible : Visibility.Collapsed;
        SidebarLogoBorder.Background = logo is null ? (Brush)Application.Current.Resources["PrimaryBrush"] : Brushes.Transparent;
        SetNavigationState(ScanNavButton, ScanPage.Visibility == Visibility.Visible);
        SetNavigationState(EventInfoNavButton, EventInfoPage.Visibility == Visibility.Visible);
        SetNavigationState(PrinterNavButton, PrinterPage.Visibility == Visibility.Visible);
        SetNavigationState(SettingsNavButton, SettingsPage.Visibility == Visibility.Visible);
    }

    private async void EventCombo_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (SelectedEvent is not null)
        {
            _adminUnlockUntilUtc = DateTime.MinValue;
            _temporaryAdminPasscode = "";
            AppPreferenceStore.SaveSelectedEventCode(SelectedEvent.Code);
            await ApplyEventBrandingAsync(SelectedEvent);
            LoadPrinterOptions(SelectedEvent.Code);
            await RefreshEventAsync();
            await TryShowPageAsync(CurrentPageKey());
        }
    }

    private async Task RefreshEventAsync()
    {
        var selected = SelectedEvent;
        if (selected is null) return;
        SetBusy(true);
        var response = await _api.GetEventStatusAsync(selected.Code);
        SetBusy(false);
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            return;
        }
        ApplyStatus(response);
        ShowResult(response.Event?.CanScan == true ? "شناسه مهمان را اسکن کنید." : "در حال حاضر بازه فعالی برای اسکن وجود ندارد.", response.Event?.CanScan == true ? "idle" : "error");
        ScanBox.Focus();
    }

    private async Task RefreshLiveAsync()
    {
        if (_liveRefreshInFlight || _busy || _switchingWindows || _fullyQuitting || !IsVisible || SelectedEvent is null) return;
        _liveRefreshInFlight = true;
        var eventCode = SelectedEvent.Code;
        var revision = _foregroundRevision;
        try
        {
            var response = await _api.GetEventUpdatesAsync(eventCode);
            if (_busy || revision != _foregroundRevision || SelectedEvent?.Code != eventCode) return;
            if (response.Status != "ok")
            {
                LiveSyncText.Text = $"همگام‌سازی قطع شد؛ تلاش مجدد — {response.Message}";
                _liveRefreshTimer.Interval = TimeSpan.FromSeconds(5);
                return;
            }
            response.PrintProfile = _printProfile;
            ApplyStatus(response, liveRefresh: true);
            LiveSyncText.Text = $"به‌روز: {DateTime.Now:HH:mm:ss} • همگام‌سازی هر ۲ ثانیه";
            _liveRefreshTimer.Interval = TimeSpan.FromSeconds(2);
        }
        catch (Exception error) { LiveSyncText.Text = $"همگام‌سازی ناموفق؛ تلاش مجدد — {error.Message}"; }
        finally { _liveRefreshInFlight = false; }
    }

    private async Task SubmitScanAsync()
    {
        if (_busy || SelectedEvent is null) return;
        var code = ToEnglishDigits(ScanBox.Text);
        if (code.Length is < 4 or > 10)
        {
            ShowResult("شناسه باید بین ۴ تا ۱۰ رقم باشد.", "error");
            return;
        }
        ScanBox.Clear();
        SetBusy(true);
        ShowResult($"در حال بررسی شناسه {code}...", "loading");
        var response = await _api.ScanAsync(SelectedEvent.Code, code);
        SetBusy(false);
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            SystemSounds.Hand.Play();
        }
        else
        {
            ApplyStatus(response);
            var tone = response.Result is "success" or "quit_success" ? "success" : response.Result.Contains("duplicate", StringComparison.OrdinalIgnoreCase) ? "duplicate" : "error";
            ShowResult(response.Message, tone);
            if (tone == "success") SystemSounds.Asterisk.Play(); else SystemSounds.Exclamation.Play();
            if (response.Result is "success" or "force_entry_success" && response.PrintGuest is not null)
            {
                try
                {
                    await HandleSuccessfulEntryPrintAsync(response.PrintProfile, response.PrintGuest);
                }
                catch (Exception error)
                {
                    ShowResult($"ورود ثبت شد، اما چاپ کارت ناموفق بود: {error.Message}", "error");
                    SystemSounds.Exclamation.Play();
                }
            }
        }
        ScanBox.Focus();
    }

    private async Task HandleSuccessfulEntryPrintAsync(PrintProfile profile, AttendanceLog guest)
    {
        if (SelectedEvent is null) return;
        if (!profile.AutoPrint) return;
        if (!profile.TicketActive)
        {
            await PrintCardService.PrintAsync(_api, profile, guest, _printerOptions);
            return;
        }

        var tickets = profile.Tickets.Count > 0
            ? profile.Tickets
            : [new NumberTicketDefinition { Id = "default", Title = "Custom Number Ticket", Configured = profile.TicketConfigured, Card = profile.TicketCard }];
        await PrintCardService.PrintEntryCardForTicketFlowAsync(_api, profile, guest, _printerOptions);
        var printed = 0;
        foreach (var ticket in tickets)
        {
            var ticketDialog = new TicketNumberWindow(guest.DisplayName, ticket.Title) { Owner = this };
            if (ticketDialog.ShowDialog() != true)
            {
                ShowResult($"ورود ثبت شد؛ چاپ «{ticket.Title}» لغو شد.", "duplicate");
                return;
            }
            var recorded = await _api.RecordTicketNumberAsync(SelectedEvent.Code, guest.GuestCode, ticket.Id, ticketDialog.TicketNumber);
            if (recorded.Status != "ok") throw new InvalidOperationException(recorded.Message);
            guest.NumberOfTicket = ticketDialog.TicketNumber;
            guest.TicketTitle = ticket.Title;
            await PrintCardService.PrintNumberTicketAsync(_api, ticket, guest, _printerOptions);
            printed++;
        }
        ShowResult($"{printed} بلیت شماره‌دار ثبت و چاپ شد.", "success");
    }

    private void ApplyAccess(WinAppAccessInfo access)
    {
        _access = access;
        var eventPageWasOpen = EventInfoPage.Visibility == Visibility.Visible;
        var printerPageWasOpen = PrinterPage.Visibility == Visibility.Visible;
        var settingsPageWasOpen = SettingsPage.Visibility == Visibility.Visible;
        EventInfoNavButton.Visibility = access.CanViewEventInfo ? Visibility.Visible : Visibility.Collapsed;
        PrinterNavButton.Visibility = access.CanUsePrinter ? Visibility.Visible : Visibility.Collapsed;
        ManualTicketButton.Visibility = access.CanUsePrinter ? Visibility.Visible : Visibility.Collapsed;
        SettingsNavButton.Visibility = access.CanManageSettings ? Visibility.Visible : Visibility.Collapsed;
        LogsPanel.Visibility = access.CanViewUserInfo ? Visibility.Visible : Visibility.Collapsed;
        HeaderEventText.Text = access.CanViewEventInfo
            ? $"رویداد انتخاب‌شده: {SelectedEvent?.Name ?? "EGM"}"
            : "حالت اسکن محدود";
        if ((eventPageWasOpen && !access.CanViewEventInfo)
            || (printerPageWasOpen && !access.CanUsePrinter)
            || (settingsPageWasOpen && !access.CanManageSettings))
        {
            ShowPage("scan");
        }
        UpdatePageLockButtons();
    }

    private void ApplyStatus(EventStatusResponse response, bool liveRefresh = false)
    {
        if (response.AdminSecurity is not null)
        {
            _adminSecurity = response.AdminSecurity;
            UpdatePageLockButtons();
        }
        _printProfile = response.PrintProfile ?? new PrintProfile();
        if (!liveRefresh) ApplyPrintSettings(_printProfile);
        ApplyAccess(response.Access);
        var state = response.Event;
        _currentPeriodCode = state?.PeriodCode ?? "";
        EventHeroNameText.Text = string.IsNullOrWhiteSpace(state?.Name) ? "EGM" : state.Name;
        PeriodTitleText.Text = string.IsNullOrWhiteSpace(state?.PeriodTitle) ? "بدون بازه فعال" : state.PeriodTitle;
        PhaseText.Text = $"وضعیت فعلی: {PhaseLabel(state?.Phase ?? "")}";
        var canScan = state?.CanScan == true;
        PhasePillBorder.Background = new SolidColorBrush(canScan ? Color.FromRgb(236, 253, 243) : Color.FromRgb(242, 244, 247));
        PhasePillBorder.BorderBrush = new SolidColorBrush(canScan ? Color.FromRgb(171, 239, 198) : Color.FromRgb(208, 213, 221));
        PhasePillDot.Fill = new SolidColorBrush(canScan ? Color.FromRgb(8, 116, 67) : Color.FromRgb(152, 162, 179));
        PhaseText.Foreground = new SolidColorBrush(canScan ? Color.FromRgb(8, 116, 67) : Color.FromRgb(102, 112, 133));
        ScanBox.IsEnabled = state?.CanScan == true;
        var stats = response.Stats ?? new AttendanceStats();
        TicketTotalsItems.ItemsSource = stats.TicketTotals;
        RefreshManualTicketTotals();
        GroupProgressItems.ItemsSource = stats.Groups;
        TicketTotalsEmpty.Visibility = stats.TicketTotals.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        GroupProgressEmpty.Visibility = stats.Groups.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        var invitedTotal = stats.InvitedTotal > 0 ? stats.InvitedTotal : stats.Total;
        var invitedEntered = Math.Min(invitedTotal, Math.Max(0, stats.InvitedEntered));
        EntrySummaryText.Text = $"{invitedEntered:N0} از {invitedTotal:N0} نفر دعوت‌شده";
        EntryProgressBar.Maximum = Math.Max(1, invitedTotal);
        EntryProgressBar.Value = invitedEntered;
        EntryCompositionText.Text = $"دعوت همین بازه {invitedEntered:N0}  •  دعوت بازه دیگر {stats.OtherPeriodEntered:N0}  •  بدون دعوت {stats.WalkInEntered:N0}";
        InsideText.Text = stats.Inside.ToString("N0");
        QuitText.Text = stats.Quit.ToString("N0");
        WaitingText.Text = stats.Waiting.ToString("N0");
        var male = stats.Gender.Male;
        MaleProgressBar.Maximum = Math.Max(1, male.Total);
        MaleProgressBar.Value = Math.Min(male.Total, male.Entered);
        MaleStatsText.Text = $"{male.Entered:N0} از {male.Total:N0}";
        var female = stats.Gender.Female;
        FemaleProgressBar.Maximum = Math.Max(1, female.Total);
        FemaleProgressBar.Value = Math.Min(female.Total, female.Entered);
        FemaleStatsText.Text = $"{female.Entered:N0} از {female.Total:N0}";
        var walkInStatuses = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "not_found", "not_invited", "invited_other_period", "attended_previous_period"
        };
        var registeredWalkIns = response.Logs
            .Where(log => log.Status == "walk_in_registered")
            .Select(log => $"{log.NationalId}|{log.PeriodTitle}")
            .ToHashSet(StringComparer.OrdinalIgnoreCase);
        foreach (var log in response.Logs)
        {
            log.CanRegisterUninvited = canScan
                && _access.CanManageScanActions
                && walkInStatuses.Contains(log.Status)
                && !string.IsNullOrWhiteSpace(log.NationalId)
                && !registeredWalkIns.Contains($"{log.NationalId}|{log.PeriodTitle}");
            log.CanPrintCard = _access.CanUsePrinter
                && log.Status is "success" or "force_entry_success";
        }
        _logs = response.Logs;
        ApplyLogFilter();
    }

    private void ApplyLogFilter()
    {
        var query = ToEnglishDigits(LogsSearchBox.Text.Trim());
        var filtered = string.IsNullOrWhiteSpace(query)
            ? _logs
            : _logs.Where(log => ToEnglishDigits(log.SearchText).Contains(query, StringComparison.CurrentCultureIgnoreCase)).ToList();
        LogsItemsControl.ItemsSource = filtered;
        LogsCountText.Text = $"{filtered.Count:N0} نتیجه";
        LogsEmptyText.Text = string.IsNullOrWhiteSpace(query) ? "هنوز موردی بررسی نشده است." : "موردی مطابق جستجو پیدا نشد.";
        LogsEmptyText.Visibility = filtered.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
    }

    private void SetBusy(bool busy)
    {
        if (busy) _foregroundRevision++;
        _busy = busy;
        EventCombo.IsEnabled = !busy;
        ManualTicketButton.IsEnabled = !busy && SelectedEvent is not null && _access.CanUsePrinter;
        ScanBox.IsEnabled = !busy && (SelectedEvent is not null);
    }

    private void RefreshManualTicketTotals()
    {
        try
        {
            var totals = ManualTicketStore.Totals(SelectedEvent?.Code ?? "");
            ManualTicketTotalsItems.ItemsSource = totals;
            ManualTicketTotalsEmpty.Text = "هنوز بلیت دستی ثبت نشده است.";
            ManualTicketTotalsEmpty.Visibility = totals.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        }
        catch (Exception error)
        {
            ManualTicketTotalsItems.ItemsSource = null;
            ManualTicketTotalsEmpty.Text = $"خواندن آمار محلی ناموفق بود: {error.Message}";
            ManualTicketTotalsEmpty.Visibility = Visibility.Visible;
        }
    }

    private async void ManualTicketButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || SelectedEvent is null || !_access.CanUsePrinter) return;
        var eventCode = SelectedEvent.Code;
        SetBusy(true);
        try
        {
            // Read the event-wide definitions, not the last scanned guest's group-filtered profile.
            var response = await _api.GetEventStatusAsync(eventCode);
            if (response.Status != "ok") throw new InvalidOperationException(response.Message);
            if (!response.Access.CanUsePrinter) throw new InvalidOperationException("اجازه چاپ ندارید.");
            var profile = response.PrintProfile ?? new PrintProfile();
            var tickets = profile.Tickets.Count > 0 ? profile.Tickets :
                new List<NumberTicketDefinition> { new() { Id = "default", Title = "Custom Number Ticket", Configured = profile.TicketConfigured, Card = profile.TicketCard } };
            tickets = tickets.Where(t => t.Configured && t.Card is not null).ToList();
            if (tickets.Count == 0) throw new InvalidOperationException("هیچ طرح بلیت شماره‌دار آماده‌ای برای این رویداد موجود نیست.");
            var content = new StackPanel { Margin = new Thickness(24), FlowDirection = FlowDirection.RightToLeft };
            content.Children.Add(new TextBlock { Text = "چاپ دستی بلیت مهمان", FontSize = 22, FontWeight = FontWeights.Bold });
            content.Children.Add(new TextBlock { Text = "نوع بلیت را انتخاب کنید. نام: مهمان — QR: 000000000\nاین چاپ فقط در آمار محلی این دستگاه ثبت می‌شود.", TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0,12,0,16) });
            var selection = new ComboBox { ItemsSource = tickets, DisplayMemberPath = "Title", SelectedIndex = 0, MinHeight = 44 };
            content.Children.Add(selection);
            var actions = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0,20,0,0) };
            var proceed = new Button { Content = "ورود عدد و چاپ", MinHeight = 44, Padding = new Thickness(16,8,16,8), IsDefault = true };
            var dialog = new Window { Owner = this, Title = "چاپ دستی بلیت", Width = 460, SizeToContent = SizeToContent.Height, WindowStartupLocation = WindowStartupLocation.CenterOwner, ResizeMode = ResizeMode.NoResize, ShowInTaskbar = false, Content = content };
            proceed.Click += (_, _) => dialog.DialogResult = true;
            actions.Children.Add(proceed);
            actions.Children.Add(new Button { Content = "انصراف", MinHeight = 44, IsCancel = true, Margin = new Thickness(10,0,0,0), Padding = new Thickness(16,8,16,8) });
            content.Children.Add(actions);
            if (dialog.ShowDialog() != true || selection.SelectedItem is not NumberTicketDefinition ticket) return;
            var numberDialog = new TicketNumberWindow("مهمان", ticket.Title) { Owner = this };
            if (numberDialog.ShowDialog() != true) return;
            var guest = new AttendanceLog { FullName = "مهمان", FirstName = "مهمان", NationalId = "000000000", NumberOfTicket = numberDialog.TicketNumber, TicketTitle = ticket.Title };
            var record = ManualTicketStore.Begin(eventCode, ticket.Id, ticket.Title, numberDialog.TicketNumber);
            try
            {
                await PrintCardService.PrintNumberTicketAsync(_api, ticket, guest, _printerOptions);
            }
            catch
            {
                ManualTicketStore.SetStatus(record.Id, "failed");
                throw;
            }
            try { ManualTicketStore.SetStatus(record.Id, "submitted"); }
            catch (Exception error)
            {
                throw new InvalidOperationException($"بلیت به صف چاپ ارسال شد، اما ذخیره جمع محلی ناموفق بود؛ دوباره چاپ نکنید. {error.Message}");
            }
            RefreshManualTicketTotals();
            ShowResult($"«{ticket.Title}» با عدد {numberDialog.TicketNumber} به صف چاپ ارسال و در آمار محلی ثبت شد.", "success");
        }
        catch (Exception error) { ShowResult($"چاپ دستی: {error.Message}", "error"); }
        finally { SetBusy(false); ScanBox.Focus(); }
    }

    private void ShowResult(string message, string tone)
    {
        ResultText.Text = string.IsNullOrWhiteSpace(message) ? "پاسخی دریافت نشد." : message;
        (ResultBorder.Background, ResultBorder.BorderBrush, ResultText.Foreground) = tone switch
        {
            "success" => (Brushes.Honeydew, new SolidColorBrush(Color.FromRgb(24, 165, 102)), new SolidColorBrush(Color.FromRgb(8, 116, 67))),
            "duplicate" => (new SolidColorBrush(Color.FromRgb(255, 250, 240)), new SolidColorBrush(Color.FromRgb(213, 154, 18)), new SolidColorBrush(Color.FromRgb(128, 89, 0))),
            "error" => (new SolidColorBrush(Color.FromRgb(255, 245, 246)), new SolidColorBrush(Color.FromRgb(223, 64, 82)), new SolidColorBrush(Color.FromRgb(161, 36, 50))),
            "loading" => (new SolidColorBrush(Color.FromRgb(255, 251, 235)), new SolidColorBrush(Color.FromRgb(230, 167, 0)), new SolidColorBrush(Color.FromRgb(128, 89, 0))),
            _ => (new SolidColorBrush(Color.FromRgb(248, 250, 252)), new SolidColorBrush(Color.FromRgb(203, 213, 225)), new SolidColorBrush(Color.FromRgb(52, 64, 84)))
        };
    }

    private void ShowPage(string page)
    {
        if ((page == "event-info" && !_access.CanViewEventInfo)
            || (page == "printer" && !_access.CanUsePrinter)
            || (page == "settings" && !_access.CanManageSettings))
        {
            page = "scan";
        }
        ScanPage.Visibility = page == "scan" ? Visibility.Visible : Visibility.Collapsed;
        EventInfoPage.Visibility = page == "event-info" ? Visibility.Visible : Visibility.Collapsed;
        PrinterPage.Visibility = page == "printer" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPage.Visibility = page == "settings" ? Visibility.Visible : Visibility.Collapsed;

        SetNavigationState(ScanNavButton, page == "scan");
        SetNavigationState(EventInfoNavButton, page == "event-info");
        SetNavigationState(PrinterNavButton, page == "printer");
        SetNavigationState(SettingsNavButton, page == "settings");
        HeaderLockButton.Tag = page;
        HeaderLockButton.Visibility = string.IsNullOrEmpty(page) ? Visibility.Collapsed : Visibility.Visible;
        UpdatePageLockButtons();
        if (page == "scan") ScanBox.Focus();
    }

    private string CurrentPageKey()
    {
        if (EventInfoPage.Visibility == Visibility.Visible) return "event-info";
        if (PrinterPage.Visibility == Visibility.Visible) return "printer";
        if (SettingsPage.Visibility == Visibility.Visible) return "settings";
        return "scan";
    }

    private bool HasTemporaryAdminAccess()
    {
        if (DateTime.UtcNow < _adminUnlockUntilUtc && !string.IsNullOrWhiteSpace(_temporaryAdminPasscode)) return true;
        _adminUnlockUntilUtc = DateTime.MinValue;
        _temporaryAdminPasscode = "";
        return false;
    }

    private bool IsPageLocked(string pageKey) => _adminSecurity.Configured && _adminSecurity.IsLocked(pageKey);

    private string FirstUnlockedAccessiblePage()
    {
        if (!IsPageLocked("scan")) return "scan";
        if (_access.CanViewEventInfo && !IsPageLocked("event-info")) return "event-info";
        if (_access.CanUsePrinter && !IsPageLocked("printer")) return "printer";
        if (_access.CanManageSettings && !IsPageLocked("settings")) return "settings";
        return "";
    }

    private async Task<bool> TryShowPageAsync(string pageKey)
    {
        if ((pageKey == "event-info" && !_access.CanViewEventInfo)
            || (pageKey == "printer" && !_access.CanUsePrinter)
            || (pageKey == "settings" && !_access.CanManageSettings)) return false;
        if (!IsPageLocked(pageKey) || HasTemporaryAdminAccess())
        {
            ShowPage(pageKey);
            return true;
        }

        ShowPage("");
        var passcodeWindow = new AdminPasscodeWindow("این صفحه قفل است. Admin Passcode را وارد کنید؛ دسترسی یک دقیقه فعال می‌ماند.") { Owner = this };
        if (passcodeWindow.ShowDialog() != true)
        {
            ShowPage(FirstUnlockedAccessiblePage());
            return false;
        }
        if (SelectedEvent is null) return false;
        var response = await _api.VerifyAdminPasscodeAsync(SelectedEvent.Code, passcodeWindow.Passcode);
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            ShowPage(FirstUnlockedAccessiblePage());
            return false;
        }
        _adminSecurity = response.AdminSecurity;
        _temporaryAdminPasscode = passcodeWindow.Passcode;
        _adminUnlockUntilUtc = DateTime.UtcNow.AddMinutes(1);
        UpdatePageLockButtons();
        ShowPage(pageKey);
        return true;
    }

    private void UpdatePageLockButtons()
    {
        var buttons = new (Button Button, TextBlock Icon, string Key)[]
        {
            (HeaderLockButton, HeaderLockIcon, Convert.ToString(HeaderLockButton.Tag) ?? "scan")
        };
        foreach (var (button, icon, key) in buttons)
        {
            var locked = IsPageLocked(key);
            icon.Text = locked ? "\uE72E" : "\uE785";
            button.ToolTip = !_adminSecurity.Configured
                ? "ابتدا Admin Passcode را در پنل تنظیم کنید"
                : locked ? "صفحه قفل است؛ برای بازکردن دائمی کلیک کنید" : "صفحه باز است؛ برای قفل‌کردن کلیک کنید";
            button.IsEnabled = SelectedEvent is not null;
        }
    }

    private async void PageLockButton_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button button || SelectedEvent is null) return;
        if (!_access.CanManageSettings)
        {
            MessageBox.Show(this, "حساب شما اجازه تغییر قفل صفحات را ندارد.", "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        button.IsEnabled = false;
        try
        {
        var eventCode = SelectedEvent.Code;
        var latest = await _api.GetEventStatusAsync(eventCode);
        if (SelectedEvent?.Code != eventCode) return;
        if (latest.Status != "ok")
        {
            MessageBox.Show(this, latest.Message, "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        if (latest.AdminSecurity is not null) _adminSecurity = latest.AdminSecurity;
        if (!_adminSecurity.Configured)
        {
            MessageBox.Show(this, "ابتدا Admin Passcode را در کنترل پنل همین EGM وارد کنید و دکمه ذخیره کد را بزنید.", "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }
        var pageKey = Convert.ToString(button.Tag) ?? "";
        var passcode = HasTemporaryAdminAccess() ? _temporaryAdminPasscode : "";
        if (passcode == "")
        {
            var passcodeWindow = new AdminPasscodeWindow("برای تغییر وضعیت قفل این صفحه، Admin Passcode را وارد کنید.") { Owner = this };
            if (passcodeWindow.ShowDialog() != true) return;
            passcode = passcodeWindow.Passcode;
        }
        var lockPage = !IsPageLocked(pageKey);
        var response = await _api.SetPageLockAsync(SelectedEvent.Code, pageKey, lockPage, passcode);
        if (response.Status != "ok")
        {
            MessageBox.Show(this, response.Message, "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        _adminSecurity = response.AdminSecurity;
        _temporaryAdminPasscode = passcode;
        _adminUnlockUntilUtc = DateTime.UtcNow.AddMinutes(1);
        UpdatePageLockButtons();
        ShowResult(response.Message, "success");
        MessageBox.Show(this, response.Message, "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Information);
        }
        catch (Exception error)
        {
            MessageBox.Show(this, $"تغییر قفل ناموفق بود: {error.Message}", "قفل صفحه", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            UpdatePageLockButtons();
        }
    }

    private static void SetNavigationState(Button button, bool active)
    {
        button.Background = active
            ? (Brush)Application.Current.Resources["PrimaryBrush"]
            : Brushes.Transparent;
        button.Foreground = active
            ? Brushes.White
            : new SolidColorBrush(Color.FromRgb(152, 162, 179));
    }

    private async void ScanNavButton_Click(object sender, RoutedEventArgs e) => await TryShowPageAsync("scan");

    private async void EventInfoNavButton_Click(object sender, RoutedEventArgs e) => await TryShowPageAsync("event-info");

    private async void PrinterNavButton_Click(object sender, RoutedEventArgs e)
    {
        if (!await TryShowPageAsync("printer")) return;
        if (SelectedEvent is not null)
        {
            var response = await _api.GetEventStatusAsync(SelectedEvent.Code);
            if (response.Status == "ok")
            {
                _printProfile = response.PrintProfile ?? new PrintProfile();
                ApplyPrintSettings(_printProfile);
            }
        }
    }

    private async void SettingsNavButton_Click(object sender, RoutedEventArgs e) => await TryShowPageAsync("settings");

    private void LoadPrinterOptions(string eventCode)
    {
        _loadingPrinters = true;
        _printerOptions = PrintCardService.LoadOptions(eventCode);
        if (_installedPrinters.Count == 0) _installedPrinters = PrintCardService.GetInstalledPrinters();
        PrimaryPrinterCombo.ItemsSource = _installedPrinters;
        SecondaryPrinterCombo.ItemsSource = _installedPrinters;
        PrimaryPrinterCombo.SelectedItem = _installedPrinters.Contains(_printerOptions.PrimaryPrinter) ? _printerOptions.PrimaryPrinter : _installedPrinters.FirstOrDefault();
        SecondaryPrinterCombo.SelectedItem = _installedPrinters.Contains(_printerOptions.SecondaryPrinter) ? _printerOptions.SecondaryPrinter : _installedPrinters.Skip(1).FirstOrDefault() ?? _installedPrinters.FirstOrDefault();
        UseSecondPrinterCheck.IsChecked = _printerOptions.UseSecondPrinter;
        SecondaryPrinterCombo.IsEnabled = _printerOptions.UseSecondPrinter;
        _loadingPrinters = false;
        RebuildTicketPrinterControls(_printProfile);
        SavePrinterOptions();
    }

    private async void RefreshPrintersButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loadingPrinters) return;
        SavePrinterOptions();
        RefreshPrintersButton.IsEnabled = false;
        PrinterRefreshStatus.Foreground = new SolidColorBrush(Color.FromRgb(102, 112, 133));
        PrinterRefreshStatus.Text = "در حال جستجوی چاپگرهای متصل...";
        try
        {
            var refreshed = await Task.Run(PrintCardService.GetInstalledPrinters);
            _installedPrinters = refreshed;
            _loadingPrinters = true;
            PrimaryPrinterCombo.ItemsSource = null;
            SecondaryPrinterCombo.ItemsSource = null;
            PrimaryPrinterCombo.ItemsSource = _installedPrinters;
            SecondaryPrinterCombo.ItemsSource = _installedPrinters;
            PrimaryPrinterCombo.SelectedItem = _installedPrinters.Contains(_printerOptions.PrimaryPrinter)
                ? _printerOptions.PrimaryPrinter
                : _installedPrinters.FirstOrDefault();
            SecondaryPrinterCombo.SelectedItem = _installedPrinters.Contains(_printerOptions.SecondaryPrinter)
                ? _printerOptions.SecondaryPrinter
                : _installedPrinters.Skip(1).FirstOrDefault() ?? _installedPrinters.FirstOrDefault();
            SecondaryPrinterCombo.IsEnabled = UseSecondPrinterCheck.IsChecked == true;
            RebuildTicketPrinterControls(_printProfile);
            _loadingPrinters = false;
            SavePrinterOptions();

            if (_installedPrinters.Count == 0)
            {
                PrinterRefreshStatus.Foreground = new SolidColorBrush(Color.FromRgb(180, 35, 24));
                PrinterRefreshStatus.Text = "چاپگری در Windows پیدا نشد.";
            }
            else
            {
                PrinterRefreshStatus.Foreground = new SolidColorBrush(Color.FromRgb(8, 116, 67));
                PrinterRefreshStatus.Text = $"{_installedPrinters.Count} چاپگر پیدا شد.";
            }
        }
        catch (Exception error)
        {
            PrinterRefreshStatus.Foreground = new SolidColorBrush(Color.FromRgb(180, 35, 24));
            PrinterRefreshStatus.Text = $"به‌روزرسانی چاپگرها ناموفق بود: {error.Message}";
        }
        finally
        {
            _loadingPrinters = false;
            RefreshPrintersButton.IsEnabled = true;
        }
    }

    private void RebuildTicketPrinterControls(PrintProfile profile)
    {
        _loadingPrinters = true;
        TicketPrintersPanel.Children.Clear();
        _ticketPrinterCombos.Clear();
        var tickets = profile.Tickets.Count > 0 ? profile.Tickets : [new NumberTicketDefinition()];
        foreach (var ticket in tickets)
        {
            TicketPrintersPanel.Children.Add(new TextBlock { Text = ticket.Title, Margin = new Thickness(0, 4, 0, 5), FontWeight = FontWeights.SemiBold });
            var combo = new ComboBox { Height = 44, Margin = new Thickness(0, 0, 0, 10), FlowDirection = FlowDirection.LeftToRight, HorizontalContentAlignment = HorizontalAlignment.Right, ItemsSource = _installedPrinters };
            var legacy = string.IsNullOrWhiteSpace(_printerOptions.TicketPrinter) ? _installedPrinters.FirstOrDefault() : _printerOptions.TicketPrinter;
            var saved = _printerOptions.TicketPrinters.TryGetValue(ticket.Id, out var selected) ? selected : legacy;
            combo.SelectedItem = _installedPrinters.Contains(saved ?? "") ? saved : _installedPrinters.FirstOrDefault();
            combo.SelectionChanged += PrinterSelectionChanged;
            _ticketPrinterCombos[ticket.Id] = combo;
            TicketPrintersPanel.Children.Add(combo);
        }
        _loadingPrinters = false;
    }

    private void PrinterSelectionChanged(object sender, RoutedEventArgs e)
    {
        if (_loadingPrinters) return;
        SecondaryPrinterCombo.IsEnabled = UseSecondPrinterCheck.IsChecked == true;
        SavePrinterOptions();
    }

    private void SavePrinterOptions()
    {
        _printerOptions.PrimaryPrinter = PrimaryPrinterCombo.SelectedItem as string ?? "";
        _printerOptions.SecondaryPrinter = SecondaryPrinterCombo.SelectedItem as string ?? "";
        _printerOptions.UseSecondPrinter = UseSecondPrinterCheck.IsChecked == true;
        foreach (var entry in _ticketPrinterCombos)
            _printerOptions.TicketPrinters[entry.Key] = entry.Value.SelectedItem as string ?? "";
        if (SelectedEvent is not null) PrintCardService.SaveOptions(SelectedEvent.Code, _printerOptions);
    }

    private void ApplyPrintSettings(PrintProfile profile)
    {
        _loadingPrintSettings = true;
        AutoPrintCheck.IsChecked = profile.AutoPrint;
        DoublePrintCheck.IsChecked = profile.DoublePrint;
        TicketActiveCheck.IsChecked = profile.TicketActive;
        TicketOnlyCheck.IsChecked = profile.TicketOnly;
        RebuildTicketPrinterControls(profile);
        _loadingPrintSettings = false;
    }

    private async void SavePrintSettingsButton_Click(object sender, RoutedEventArgs e)
    {
        if (_loadingPrintSettings || _busy || SelectedEvent is null) return;
        SavePrintSettingsButton.IsEnabled = false;
        ShowResult("در حال ذخیره تنظیمات چاپ...", "loading");
        try
        {
            var response = await _api.SavePrintSettingsAsync(
                SelectedEvent.Code,
                AutoPrintCheck.IsChecked == true,
                DoublePrintCheck.IsChecked == true,
                TicketActiveCheck.IsChecked == true,
                TicketOnlyCheck.IsChecked == true);
            if (response.Status != "ok") throw new InvalidOperationException(response.Message);
            _printProfile = response.PrintProfile ?? new PrintProfile();
            ApplyPrintSettings(_printProfile);
            ShowResult(response.Message, "success");
        }
        catch (Exception error)
        {
            ShowResult($"ذخیره تنظیمات چاپ ناموفق بود: {error.Message}", "error");
        }
        finally
        {
            SavePrintSettingsButton.IsEnabled = true;
        }
    }

    private static string PhaseLabel(string phase) => phase switch
    {
        "entry_time" => "فعال / زمان ورود",
        "quit_time" => "فعال / زمان خروج",
        "flexible_attendance" => "فعال / ورود و خروج شناور",
        "immune_time" => "فعال / زمان ایمن",
        "upcoming" => "در انتظار شروع",
        "multiple_active_periods" => "هم‌پوشانی بازه‌ها",
        "invalid_schedule" => "زمان‌بندی نامعتبر",
        _ => "بدون بازه فعال"
    };

    private static string ToEnglishDigits(string value) => value
        .Replace('۰', '0').Replace('۱', '1').Replace('۲', '2').Replace('۳', '3').Replace('۴', '4')
        .Replace('۵', '5').Replace('۶', '6').Replace('۷', '7').Replace('۸', '8').Replace('۹', '9')
        .Replace('٠', '0').Replace('١', '1').Replace('٢', '2').Replace('٣', '3').Replace('٤', '4')
        .Replace('٥', '5').Replace('٦', '6').Replace('٧', '7').Replace('٨', '8').Replace('٩', '9');

    private void ScanBox_PreviewTextInput(object sender, TextCompositionEventArgs e) => e.Handled = NonDigit.IsMatch(ToEnglishDigits(e.Text));

    private void LogsSearchBox_TextChanged(object sender, TextChangedEventArgs e) => ApplyLogFilter();

    private void GuestDetailsButton_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement { DataContext: AttendanceLog guest }) return;
        new GuestDetailsWindow(guest) { Owner = this }.ShowDialog();
    }

    private async void RegisterUninvitedButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || SelectedEvent is null || sender is not FrameworkElement { DataContext: AttendanceLog guest }) return;
        SetBusy(true);
        var optionsResponse = await _api.GetUninvitedOptionsAsync(SelectedEvent.Code);
        SetBusy(false);
        if (optionsResponse.Status != "ok")
        {
            MessageBox.Show(optionsResponse.Message, "ثبت مهمان ناخوانده", MessageBoxButton.OK, MessageBoxImage.Error);
            return;
        }
        var dialog = new RegisterUninvitedWindow(guest, optionsResponse.Options) { Owner = this };
        if (dialog.ShowDialog() != true) return;
        SetBusy(true);
        ShowResult("در حال ثبت مهمان ناخوانده...", "loading");
        var response = await _api.RegisterUninvitedAsync(SelectedEvent.Code, dialog.Registration);
        SetBusy(false);
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            MessageBox.Show(response.Message, "ثبت مهمان ناخوانده", MessageBoxButton.OK, MessageBoxImage.Error);
            return;
        }
        ApplyStatus(response);
        ShowResult(response.Message, "success");
        SystemSounds.Asterisk.Play();
        ScanBox.Focus();
    }

    private async void ForceAttendanceButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || SelectedEvent is null || sender is not FrameworkElement { DataContext: AttendanceLog guest } || !guest.CanForceAttendance) return;
        var actionName = guest.ForceAction == "quit" ? "خروج اجباری" : "ورود اجباری";
        var confirmation = MessageBox.Show(
            $"{actionName} برای «{guest.DisplayName}» ثبت شود؟\nاین عملیات حضور را غیرعادی علامت می‌زند.",
            actionName,
            MessageBoxButton.YesNo,
            MessageBoxImage.Warning);
        if (confirmation != MessageBoxResult.Yes) return;
        SetBusy(true);
        ShowResult("در حال ثبت عملیات اجباری...", "loading");
        var response = await _api.ForceAttendanceAsync(SelectedEvent.Code, guest.GuestCode, guest.ForceAction);
        SetBusy(false);
        if (response.Status != "ok")
        {
            ShowResult(response.Message, "error");
            MessageBox.Show(response.Message, actionName, MessageBoxButton.OK, MessageBoxImage.Error);
            return;
        }
        ApplyStatus(response);
        ShowResult(response.Message, "success");
        if (guest.ForceAction == "entry")
        {
            var printGuest = response.Logs.FirstOrDefault(item => item.Status == "force_entry_success" && item.GuestCode == guest.GuestCode);
            if (printGuest is not null)
            {
                try { await HandleSuccessfulEntryPrintAsync(response.PrintProfile, printGuest); }
                catch (Exception error) { ShowResult($"ورود ثبت شد، اما فرآیند رسید ناموفق بود: {error.Message}", "error"); }
            }
        }
        SystemSounds.Asterisk.Play();
        ScanBox.Focus();
    }

    private async void PrintCardButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy || sender is not FrameworkElement { DataContext: AttendanceLog guest } || !guest.CanPrintCard) return;
        try
        {
            SetBusy(true);
            if (SelectedEvent is null) return;
            var options = await _api.GetReprintOptionsAsync(SelectedEvent.Code, guest.GuestCode, _currentPeriodCode);
            if (options.Status != "ok") throw new InvalidOperationException(options.Message);
            var profile = options.PrintProfile;
            var choices = new List<(CheckBox Check, NumberTicketDefinition? Ticket)>();
            var content = new StackPanel { Margin = new Thickness(24), FlowDirection = FlowDirection.RightToLeft };
            content.Children.Add(new TextBlock { Text = $"چاپ مجدد برای {guest.DisplayName}", FontSize = 18, FontWeight = FontWeights.Bold, TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0,0,0,16) });
            if (!profile.TicketOnly && profile.Configured && profile.Card is not null)
            {
                var check = new CheckBox { Content = "کارت دعوت", Margin = new Thickness(0,8,0,8) };
                choices.Add((check, null)); content.Children.Add(check);
            }
            if (profile.TicketActive)
            foreach (var ticket in profile.Tickets.Where(ticket => ticket.Configured && ticket.Card is not null))
            {
                var available = options.TicketNumbers.TryGetValue(ticket.Id, out var number) && !string.IsNullOrWhiteSpace(number);
                var check = new CheckBox { Content = available ? $"{ticket.Title} — {number}" : $"{ticket.Title} — شماره‌ای ثبت نشده", IsEnabled = available, Margin = new Thickness(0,8,0,8) };
                choices.Add((check, ticket)); content.Children.Add(check);
            }
            if (choices.Count == 0) throw new InvalidOperationException("هیچ کارت یا بلیت مجازی برای این مهمان تنظیم نشده است.");
            var dialog = new Window { Owner = this, Title = "چاپ مجدد", Width = 460, SizeToContent = SizeToContent.Height, WindowStartupLocation = WindowStartupLocation.CenterOwner, ResizeMode = ResizeMode.NoResize, ShowInTaskbar = false, Content = content };
            var errorText = new TextBlock { Foreground = Brushes.Firebrick, TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0,12,0,0) };
            content.Children.Add(errorText);
            var actions = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0,18,0,0) };
            var print = new Button { Content = "چاپ انتخاب‌شده‌ها", MinHeight = 44, IsDefault = true };
            print.Click += (_, _) =>
            {
                if (!choices.Any(choice => choice.Check.IsChecked == true)) { errorText.Text = "حداقل یک مورد انتخاب کنید."; return; }
                dialog.DialogResult = true;
            };
            actions.Children.Add(print);
            actions.Children.Add(new Button { Content = "انصراف", IsCancel = true, MinHeight = 44, Margin = new Thickness(10,0,0,0) });
            content.Children.Add(actions);
            if (dialog.ShowDialog() != true) return;
            var previousNumber = guest.NumberOfTicket;
            try
            {
                foreach (var choice in choices.Where(choice => choice.Check.IsChecked == true))
                {
                    if (choice.Ticket is null) await PrintCardService.ReprintAsync(_api, profile, guest, _printerOptions);
                    else
                    {
                        guest.NumberOfTicket = options.TicketNumbers[choice.Ticket.Id];
                        await PrintCardService.PrintNumberTicketAsync(_api, choice.Ticket, guest, _printerOptions);
                    }
                }
            }
            finally { guest.NumberOfTicket = previousNumber; }
            ShowResult("موارد انتخاب‌شده به چاپگرهای تنظیم‌شده ارسال شدند.", "success");
            SystemSounds.Asterisk.Play();
        }
        catch (Exception error)
        {
            ShowResult($"چاپ مجدد ناموفق بود: {error.Message}", "error");
            SystemSounds.Exclamation.Play();
        }
        finally
        {
            SetBusy(false);
            ScanBox.Focus();
        }
    }

    private async void ScanBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            e.Handled = true;
            await SubmitScanAsync();
        }
    }

    private async void ScanBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        var normalized = ToEnglishDigits(ScanBox.Text);
        if (normalized != ScanBox.Text)
        {
            ScanBox.Text = normalized;
            ScanBox.CaretIndex = normalized.Length;
            return;
        }
        if (normalized.Length == 10) await SubmitScanAsync();
    }

    private async void LogoutButton_Click(object sender, RoutedEventArgs e)
    {
        await _api.LogoutAsync();
        _switchingWindows = true;
        var login = new LoginWindow(_api);
        Application.Current.MainWindow = login;
        login.Show();
        Close();
    }

    protected override void OnClosing(CancelEventArgs e)
    {
        if (!_switchingWindows && !_fullyQuitting)
        {
            e.Cancel = true;
            if (_trayIcon is null)
            {
                var resource = Application.GetResourceStream(new Uri("/DavatShodi.GuestManager;component/Assets/Brand/HamrahIcon.ico", UriKind.Relative));
                var menu = new System.Windows.Forms.ContextMenuStrip();
                menu.Items.Add("باز کردن", null, (_, _) => Dispatcher.Invoke(RestoreFromTray));
                menu.Items.Add("خروج کامل", null, (_, _) => Dispatcher.Invoke(() =>
                {
                    _fullyQuitting = true;
                    _trayIcon?.Dispose();
                    Application.Current.Shutdown();
                }));
                _trayIcon = new System.Windows.Forms.NotifyIcon
                {
                    Icon = resource is null ? System.Drawing.SystemIcons.Application : new System.Drawing.Icon(resource.Stream),
                    Text = "MCI Event Guest Manager", ContextMenuStrip = menu
                };
                _trayIcon.DoubleClick += (_, _) => Dispatcher.Invoke(RestoreFromTray);
            }
            _trayIcon.Visible = true;
            Hide();
        }
        base.OnClosing(e);
    }

    private void RestoreFromTray()
    {
        Show();
        if (WindowState == WindowState.Minimized) WindowState = WindowState.Normal;
        Activate();
        if (_trayIcon is not null) _trayIcon.Visible = false;
    }
}
