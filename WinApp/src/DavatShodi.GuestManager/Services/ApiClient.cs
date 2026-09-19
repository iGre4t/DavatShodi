using System.Net;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using DavatShodi.GuestManager.Models;

namespace DavatShodi.GuestManager.Services;

public sealed class ApiClient : IDisposable
{
    private readonly HttpClient _http;
    private readonly JsonSerializerOptions _json = new(JsonSerializerDefaults.Web);
    private string _csrf = "";

    public ApiClient(string endpoint)
    {
        var handler = new HttpClientHandler { CookieContainer = new CookieContainer(), UseCookies = true };
        _http = new HttpClient(handler) { BaseAddress = new Uri(endpoint), Timeout = TimeSpan.FromSeconds(20) };
    }

    public async Task<LoginResponse> LoginAsync(string username, string password)
    {
        var response = await PostAsync<LoginResponse>(new { action = "login", username, password });
        if (response.Status == "ok") _csrf = response.Csrf;
        return response;
    }

    public Task<BrandingResponse> GetBrandingAsync() => PostAsync<BrandingResponse>(new { action = "branding" });

    public async Task<byte[]?> GetAssetBytesAsync(string url)
    {
        if (string.IsNullOrWhiteSpace(url)) return null;
        try
        {
            if (url.StartsWith("data:", StringComparison.OrdinalIgnoreCase))
            {
                var comma = url.IndexOf(',');
                if (comma < 0) return null;
                var metadata = url[..comma];
                var payload = url[(comma + 1)..];
                return metadata.Contains(";base64", StringComparison.OrdinalIgnoreCase)
                    ? Convert.FromBase64String(payload)
                    : System.Text.Encoding.UTF8.GetBytes(Uri.UnescapeDataString(payload));
            }
            return await _http.GetByteArrayAsync(new Uri(url, UriKind.Absolute));
        }
        catch (Exception)
        {
            return null;
        }
    }

    public Task<EventsResponse> GetEventsAsync() => PostAsync<EventsResponse>(new { action = "events" });

    public Task<EventStatusResponse> GetEventStatusAsync(string eventCode) =>
        PostAsync<EventStatusResponse>(new { action = "event_status", event_code = eventCode });

    public Task<AdminSecurityResponse> VerifyAdminPasscodeAsync(string eventCode, string passcode) =>
        PostAsync<AdminSecurityResponse>(new { action = "verify_admin_passcode", event_code = eventCode, passcode, csrf = _csrf });

    public Task<AdminSecurityResponse> SetPageLockAsync(string eventCode, string pageKey, bool locked, string passcode) =>
        PostAsync<AdminSecurityResponse>(new { action = "set_page_lock", event_code = eventCode, page_key = pageKey, locked, passcode, csrf = _csrf });

    public Task<EventStatusResponse> GetEventUpdatesAsync(string eventCode) =>
        PostAsync<EventStatusResponse>(new { action = "event_updates", event_code = eventCode });

    public Task<ReprintOptionsResponse> GetReprintOptionsAsync(string eventCode, string guestCode, string periodCode) =>
        PostAsync<ReprintOptionsResponse>(new { action = "reprint_options", event_code = eventCode, guest_code = guestCode, period_code = periodCode });

    public Task<ScanResponse> ScanAsync(string eventCode, string guestCode) =>
        PostAsync<ScanResponse>(new { action = "scan", event_code = eventCode, guest_code = guestCode, csrf = _csrf });

    public Task<ApiResponse> RecordTicketNumberAsync(string eventCode, string guestCode, string ticketId, string numberOfTicket) =>
        PostAsync<ApiResponse>(new { action = "record_ticket_number", event_code = eventCode, guest_code = guestCode, ticket_id = ticketId, number_of_ticket = numberOfTicket, csrf = _csrf });

    public Task<EventStatusResponse> SavePrintSettingsAsync(string eventCode, bool autoPrint, bool doublePrint, bool ticketActive, bool ticketOnly) =>
        PostAsync<EventStatusResponse>(new
        {
            action = "save_print_settings",
            event_code = eventCode,
            auto_print = autoPrint,
            double_print = doublePrint,
            ticket_active = ticketActive,
            ticket_only = ticketOnly,
            csrf = _csrf
        });

    public Task<UninvitedOptionsResponse> GetUninvitedOptionsAsync(string eventCode) =>
        PostAsync<UninvitedOptionsResponse>(new { action = "uninvited_options", event_code = eventCode });

    public Task<EventStatusResponse> RegisterUninvitedAsync(string eventCode, WalkInRegistration registration) =>
        PostAsync<EventStatusResponse>(new
        {
            action = "register_uninvited",
            event_code = eventCode,
            csrf = _csrf,
            first_name = registration.FirstName,
            last_name = registration.LastName,
            national_id = registration.NationalId,
            work_id = registration.WorkId,
            phone_number = registration.PhoneNumber,
            deputy = registration.Deputy,
            general_department = registration.GeneralDepartment,
            department = registration.Department,
            gender = registration.Gender,
            postal_level = registration.PostalLevel,
            outside_organization = registration.OutsideOrganization
        });

    public Task<EventStatusResponse> ForceAttendanceAsync(string eventCode, string guestCode, string attendanceAction) =>
        PostAsync<EventStatusResponse>(new
        {
            action = "force_attendance",
            event_code = eventCode,
            guest_code = guestCode,
            attendance_action = attendanceAction,
            csrf = _csrf
        });

    public async Task LogoutAsync()
    {
        await PostAsync<ApiResponse>(new { action = "logout", csrf = _csrf });
        _csrf = "";
    }

    private async Task<T> PostAsync<T>(object body) where T : ApiResponse, new()
    {
        try
        {
            using var httpResponse = await _http.PostAsJsonAsync("", body, _json);
            var response = await httpResponse.Content.ReadFromJsonAsync<T>(_json) ?? new T { Message = "پاسخ سرویس خالی بود." };
            if (!httpResponse.IsSuccessStatusCode && string.IsNullOrWhiteSpace(response.Message))
                response.Message = $"خطای سرویس ({(int)httpResponse.StatusCode})";
            return response;
        }
        catch (TaskCanceledException)
        {
            return new T { Status = "error", Message = "مهلت ارتباط با سرور تمام شد." };
        }
        catch (HttpRequestException)
        {
            return new T { Status = "error", Message = "اتصال به سرور برقرار نشد. آدرس API و شبکه را بررسی کنید." };
        }
        catch (JsonException)
        {
            return new T { Status = "error", Message = "پاسخ سرور معتبر نبود." };
        }
    }

    public void Dispose() => _http.Dispose();
}
