# DavatShodi EGM Guest Manager for Windows

A small WPF/.NET 8 desktop client for the existing EGM guest-control backend. It provides account login, EGM selection, active-period status, attendance totals, national/work ID scanning, scan feedback, and recent activity.

## Configure

Edit `src/DavatShodi.GuestManager/appsettings.json` and set `ApiUrl` to the public HTTPS URL of `api/winapp.php` on the DavatShodi installation.

```json
{
  "ApiUrl": "https://example.com/DavatShodi/api/winapp.php"
}
```

The user must have the `event-guest-manager:main` permission. Authentication uses the existing DavatShodi users table and a secure PHP session cookie; attendance changes additionally require the session CSRF token returned at login.

## Build and run

```powershell
dotnet build .\DavatShodi.GuestManager.sln
dotnet run --project .\src\DavatShodi.GuestManager\DavatShodi.GuestManager.csproj
```

## Publish a standalone Windows build

```powershell
dotnet publish .\src\DavatShodi.GuestManager\DavatShodi.GuestManager.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true
```

USB barcode/card readers that act as keyboards work directly. A 10-digit national ID submits automatically; shorter work IDs submit when the scanner sends Enter.
