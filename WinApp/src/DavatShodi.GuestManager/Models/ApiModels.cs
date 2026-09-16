using System.Text.Json.Serialization;

namespace DavatShodi.GuestManager.Models;

public class ApiResponse
{
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("message")] public string Message { get; set; } = "";
    [JsonPropertyName("code")] public string Code { get; set; } = "";
}

public sealed class LoginResponse : ApiResponse
{
    [JsonPropertyName("csrf")] public string Csrf { get; set; } = "";
    [JsonPropertyName("user")] public UserInfo? User { get; set; }
    [JsonPropertyName("branding")] public BrandingInfo? Branding { get; set; }
}

public sealed class BrandingResponse : ApiResponse
{
    [JsonPropertyName("branding")] public BrandingInfo? Branding { get; set; }
}

public sealed class BrandingInfo
{
    [JsonPropertyName("panel_name")] public string PanelName { get; set; } = "DavatShodi";
    [JsonPropertyName("primary_color")] public string PrimaryColor { get; set; } = "#1d96e1";
    [JsonPropertyName("logo_url")] public string LogoUrl { get; set; } = "";
}

public sealed class UserInfo
{
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("username")] public string Username { get; set; } = "";
}

public sealed class EventInfo
{
    [JsonPropertyName("code")] public string Code { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("primary_color")] public string PrimaryColor { get; set; } = "";
    [JsonPropertyName("logo_url")] public string LogoUrl { get; set; } = "";
    [JsonPropertyName("access")] public WinAppAccessInfo Access { get; set; } = new();
    public string DisplayName => Name;
}

public sealed class WinAppAccessInfo
{
    [JsonPropertyName("level")] public string Level { get; set; } = "full_access";
    [JsonPropertyName("can_scan")] public bool CanScan { get; set; } = true;
    [JsonPropertyName("can_view_user_info")] public bool CanViewUserInfo { get; set; } = true;
    [JsonPropertyName("can_view_event_info")] public bool CanViewEventInfo { get; set; } = true;
    [JsonPropertyName("can_manage_scan_actions")] public bool CanManageScanActions { get; set; } = true;
    [JsonPropertyName("can_manage_settings")] public bool CanManageSettings { get; set; } = true;
    [JsonPropertyName("can_use_printer")] public bool CanUsePrinter { get; set; } = true;
}

public sealed class EventsResponse : ApiResponse
{
    [JsonPropertyName("events")] public List<EventInfo> Events { get; set; } = [];
}

public sealed class ReprintOptionsResponse : ApiResponse
{
    [JsonPropertyName("print_profile")] public PrintProfile PrintProfile { get; set; } = new();
    [JsonPropertyName("ticket_numbers")] public Dictionary<string, string> TicketNumbers { get; set; } = [];
}

public sealed class AdminSecurityInfo
{
    [JsonPropertyName("configured")] public bool Configured { get; set; }
    [JsonPropertyName("page_locks")] public Dictionary<string, bool> PageLocks { get; set; } = [];

    public bool IsLocked(string pageKey) => PageLocks.TryGetValue(pageKey, out var locked) && locked;
}

public sealed class AdminSecurityResponse : ApiResponse
{
    [JsonPropertyName("admin_security")] public AdminSecurityInfo AdminSecurity { get; set; } = new();
}

public sealed class EventState
{
    [JsonPropertyName("code")] public string Code { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("primary_color")] public string PrimaryColor { get; set; } = "";
    [JsonPropertyName("logo_url")] public string LogoUrl { get; set; } = "";
    [JsonPropertyName("can_scan")] public bool CanScan { get; set; }
    [JsonPropertyName("period_code")] public string PeriodCode { get; set; } = "";
    [JsonPropertyName("period_title")] public string PeriodTitle { get; set; } = "";
    [JsonPropertyName("phase")] public string Phase { get; set; } = "";
}

public sealed class AttendanceStats
{
    [JsonPropertyName("total")] public int Total { get; set; }
    [JsonPropertyName("invited_total")] public int InvitedTotal { get; set; }
    [JsonPropertyName("invited_entered")] public int InvitedEntered { get; set; }
    [JsonPropertyName("other_period_entered")] public int OtherPeriodEntered { get; set; }
    [JsonPropertyName("walk_in_entered")] public int WalkInEntered { get; set; }
    [JsonPropertyName("overall_total")] public int OverallTotal { get; set; }
    [JsonPropertyName("overall_entered")] public int OverallEntered { get; set; }
    [JsonPropertyName("entered")] public int Entered { get; set; }
    [JsonPropertyName("inside")] public int Inside { get; set; }
    [JsonPropertyName("quit")] public int Quit { get; set; }
    [JsonPropertyName("waiting")] public int Waiting { get; set; }
    [JsonPropertyName("entry_percent")] public double EntryPercent { get; set; }
    [JsonPropertyName("gender")] public AttendanceGenderStats Gender { get; set; } = new();
}

public sealed class AttendanceGenderStats
{
    [JsonPropertyName("male")] public AttendanceGroupStats Male { get; set; } = new();
    [JsonPropertyName("female")] public AttendanceGroupStats Female { get; set; } = new();
    [JsonPropertyName("unspecified")] public AttendanceGroupStats Unspecified { get; set; } = new();
}

public sealed class AttendanceGroupStats
{
    [JsonPropertyName("total")] public int Total { get; set; }
    [JsonPropertyName("entered")] public int Entered { get; set; }
    [JsonPropertyName("waiting")] public int Waiting { get; set; }
    [JsonPropertyName("inside")] public int Inside { get; set; }
    [JsonPropertyName("quit")] public int Quit { get; set; }
}

public sealed class AttendanceLog
{
    [JsonPropertyName("first_name")] public string FirstName { get; set; } = "";
    [JsonPropertyName("last_name")] public string LastName { get; set; } = "";
    [JsonPropertyName("full_name")] public string FullName { get; set; } = "";
    [JsonPropertyName("national_id")] public string NationalId { get; set; } = "";
    [JsonPropertyName("work_id")] public string WorkId { get; set; } = "";
    [JsonPropertyName("phone_number")] public string PhoneNumber { get; set; } = "";
    [JsonPropertyName("guest_number")] public string GuestNumber { get; set; } = "";
    [JsonPropertyName("deputy")] public string Deputy { get; set; } = "";
    [JsonPropertyName("general_department")] public string GeneralDepartment { get; set; } = "";
    [JsonPropertyName("department")] public string Department { get; set; } = "";
    [JsonPropertyName("gender")] public string Gender { get; set; } = "";
    [JsonPropertyName("postal_level")] public string PostalLevel { get; set; } = "";
    [JsonPropertyName("outside_organization")] public bool OutsideOrganization { get; set; }
    [JsonPropertyName("is_uninvited_guest")] public bool IsUninvitedGuest { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "";
    [JsonPropertyName("message")] public string Message { get; set; } = "";
    [JsonPropertyName("operation_date")] public string OperationDate { get; set; } = "";
    [JsonPropertyName("operation_time")] public string OperationTime { get; set; } = "";
    [JsonPropertyName("attempted_at")] public string AttemptedAt { get; set; } = "";
    [JsonPropertyName("attendance_action")] public string AttendanceAction { get; set; } = "";
    [JsonPropertyName("attendance_state")] public string AttendanceState { get; set; } = "";
    [JsonPropertyName("period_title")] public string PeriodTitle { get; set; } = "";
    [JsonPropertyName("entered_date")] public string EnteredDate { get; set; } = "";
    [JsonPropertyName("entered_time")] public string EnteredTime { get; set; } = "";
    [JsonPropertyName("quit_date")] public string QuitDate { get; set; } = "";
    [JsonPropertyName("quit_time")] public string QuitTime { get; set; } = "";
    [JsonPropertyName("correct_presence")] public bool CorrectPresence { get; set; }
    [JsonPropertyName("fake_presence")] public bool FakePresence { get; set; }
    [JsonPropertyName("number_of_ticket")] public string NumberOfTicket { get; set; } = "";
    [JsonIgnore] public string TicketTitle { get; set; } = "";
    [JsonPropertyName("force_action")] public string ForceAction { get; set; } = "";
    [JsonPropertyName("force_label")] public string ForceLabel { get; set; } = "";
    [JsonIgnore] public bool CanRegisterUninvited { get; set; }
    [JsonIgnore] public bool CanPrintCard { get; set; }
    public bool CanForceAttendance => ForceAction is "entry" or "quit";
    public string GuestCode => string.IsNullOrWhiteSpace(NationalId) ? WorkId : NationalId;
    public string OperationAt => string.IsNullOrWhiteSpace($"{OperationDate} {OperationTime}".Trim()) ? AttemptedAt : $"{OperationDate} {OperationTime}".Trim();
    public string DisplayName => string.IsNullOrWhiteSpace(FullName) ? "—" : FullName;
    public string IdentifierLabel => !string.IsNullOrWhiteSpace(NationalId)
        ? $"کد ملی: {NationalId}"
        : !string.IsNullOrWhiteSpace(WorkId) ? $"کد پرسنلی: {WorkId}" : "بدون شناسه";
    public string PeriodLabel => string.IsNullOrWhiteSpace(PeriodTitle) ? "—" : PeriodTitle;
    public string StatusTone => Status is "success" or "quit_success" or "force_entry_success" or "force_quit_success" or "walk_in_registered"
        ? "success"
        : Status is "duplicate" or "quit_duplicate" or "attended_previous_period" ? "duplicate" : "error";
    public string StatusLabel => Status switch
    {
        "success" => "ورود موفق",
        "quit_success" => "خروج موفق",
        "force_entry_success" => "ورود اجباری",
        "force_quit_success" => "خروج اجباری",
        "walk_in_registered" => "مهمان ناخوانده ثبت شد",
        "duplicate" => "قبلاً وارد شده",
        "quit_duplicate" => "قبلاً خارج شده",
        "attended_previous_period" => "حضور در بازه قبلی",
        "quit_without_entry" => "ورود ثبت نشده",
        "minimum_stay" => "حداقل مدت حضور کامل نشده",
        "entry_closed_quit_wave" => "ورود به‌دلیل موج خروج بسته است",
        "invalid_attendance_record" => "سابقه حضور ناسازگار",
        "invited_other_period" => "دعوت در بازه دیگر",
        "user_inactive" => "مهمان غیرفعال",
        "no_active_period" => "بدون بازه فعال",
        "multiple_active_periods" => "هم‌پوشانی بازه‌ها",
        "not_found" => "یافت نشد",
        "not_invited" => "دعوت نشده",
        "upcoming" => "در انتظار شروع",
        "immune_time" => "زمان ایمن",
        "ended" => "پایان‌یافته",
        "inactive" => "غیرفعال",
        "invalid_schedule" => "زمان‌بندی نامعتبر",
        _ => "ناموفق"
    };
    public string OperationLabel => AttendanceAction switch
    {
        "quit" => "خروج",
        "entry" => "ورود",
        "register" => "ثبت مهمان",
        _ => "بررسی"
    };
    public string AttendanceStateLabel => AttendanceState switch
    {
        "quit_completed" => "خروج ثبت شده",
        "entered" => "وارد شده",
        "invalid_quit_without_entry" => "خروج ناسازگار بدون ورود",
        _ => "هنوز وارد نشده"
    };
    public string GuestTypeLabel => IsUninvitedGuest ? "مهمان ناخوانده" : "دعوت‌شده";
    public string OrganizationLabel => OutsideOrganization ? "خارج از سازمان" : "داخل سازمان";
    public string PresenceLabel => FakePresence ? "حضور غیرعادی" : CorrectPresence ? "حضور صحیح" : "—";
    public string EntryAt => string.IsNullOrWhiteSpace($"{EnteredDate} {EnteredTime}".Trim()) ? "—" : $"{EnteredDate} {EnteredTime}".Trim();
    public string QuitAt => string.IsNullOrWhiteSpace($"{QuitDate} {QuitTime}".Trim()) ? "—" : $"{QuitDate} {QuitTime}".Trim();
    public string GenderPostalLabel => string.Join(" / ", new[] { Gender, PostalLevel }.Where(value => !string.IsNullOrWhiteSpace(value))) is var value && value.Length > 0 ? value : "—";
    public string ForceButtonLabel => ForceAction == "quit" ? "خروج اجباری" : "ورود اجباری";
    public string SearchText => string.Join(' ', FullName, NationalId, WorkId, NumberOfTicket, StatusLabel, Message, OperationAt, AttendanceStateLabel, PeriodTitle);
}

public sealed class UninvitedOptionsResponse : ApiResponse
{
    [JsonPropertyName("options")] public UninvitedOptions Options { get; set; } = new();
}

public sealed class UninvitedOptions
{
    [JsonPropertyName("deputy")] public List<string> Deputies { get; set; } = [];
    [JsonPropertyName("general_department")] public List<string> GeneralDepartments { get; set; } = [];
    [JsonPropertyName("department")] public List<string> Departments { get; set; } = [];
    [JsonPropertyName("gender")] public List<string> Genders { get; set; } = [];
    [JsonPropertyName("postal_level")] public List<string> PostalLevels { get; set; } = [];
}

public sealed class WalkInRegistration
{
    public string FirstName { get; set; } = "";
    public string LastName { get; set; } = "";
    public string NationalId { get; set; } = "";
    public string WorkId { get; set; } = "";
    public string PhoneNumber { get; set; } = "";
    public string Deputy { get; set; } = "";
    public string GeneralDepartment { get; set; } = "";
    public string Department { get; set; } = "";
    public string Gender { get; set; } = "";
    public string PostalLevel { get; set; } = "";
    public bool OutsideOrganization { get; set; }
}

public class EventStatusResponse : ApiResponse
{
    [JsonPropertyName("access")] public WinAppAccessInfo Access { get; set; } = new();
    [JsonPropertyName("event")] public EventState? Event { get; set; }
    [JsonPropertyName("stats")] public AttendanceStats? Stats { get; set; }
    [JsonPropertyName("logs")] public List<AttendanceLog> Logs { get; set; } = [];
    [JsonPropertyName("print_profile")] public PrintProfile PrintProfile { get; set; } = new();
    [JsonPropertyName("admin_security")] public AdminSecurityInfo? AdminSecurity { get; set; }
}

public sealed class ScanResponse : EventStatusResponse
{
    [JsonPropertyName("result")] public string Result { get; set; } = "";
    [JsonPropertyName("print_guest")] public AttendanceLog? PrintGuest { get; set; }
}

public sealed class PrintProfile
{
    [JsonPropertyName("auto_print")] public bool AutoPrint { get; set; }
    [JsonPropertyName("double_print")] public bool DoublePrint { get; set; }
    [JsonPropertyName("configured")] public bool Configured { get; set; }
    [JsonPropertyName("card")] public PrintCardConfig? Card { get; set; }
    [JsonPropertyName("ticket_active")] public bool TicketActive { get; set; }
    [JsonPropertyName("ticket_only")] public bool TicketOnly { get; set; }
    [JsonPropertyName("ticket_configured")] public bool TicketConfigured { get; set; }
    [JsonPropertyName("ticket_card")] public PrintCardConfig? TicketCard { get; set; }
    [JsonPropertyName("tickets")] public List<NumberTicketDefinition> Tickets { get; set; } = [];
}

public sealed class NumberTicketDefinition
{
    [JsonPropertyName("id")] public string Id { get; set; } = "default";
    [JsonPropertyName("title")] public string Title { get; set; } = "Custom Number Ticket";
    [JsonPropertyName("configured")] public bool Configured { get; set; }
    [JsonPropertyName("card")] public PrintCardConfig? Card { get; set; }
}

public sealed class PrintCardConfig
{
    [JsonPropertyName("imageData")] public string ImageData { get; set; } = "";
    [JsonPropertyName("fontData")] public string FontData { get; set; } = "";
    [JsonPropertyName("fontName")] public string FontName { get; set; } = "";
    [JsonPropertyName("text")] public string Text { get; set; } = "";
    [JsonPropertyName("textHtml")] public string TextHtml { get; set; } = "";
    [JsonPropertyName("qrRect")] public PercentRect? QrRect { get; set; }
    [JsonPropertyName("textRect")] public PercentRect? TextRect { get; set; }
    [JsonPropertyName("ticketCountRect")] public PercentRect? TicketCountRect { get; set; }
    [JsonPropertyName("textAreas")] public List<PrintTextArea> TextAreas { get; set; } = [];
}

public sealed class PrintTextArea
{
    [JsonPropertyName("id")] public string Id { get; set; } = "";
    [JsonPropertyName("text")] public string Text { get; set; } = "";
    [JsonPropertyName("textHtml")] public string TextHtml { get; set; } = "";
    [JsonPropertyName("rect")] public PercentRect? Rect { get; set; }
}

public sealed class PercentRect
{
    [JsonPropertyName("x")] public double X { get; set; }
    [JsonPropertyName("y")] public double Y { get; set; }
    [JsonPropertyName("width")] public double Width { get; set; }
    [JsonPropertyName("height")] public double Height { get; set; }
}
