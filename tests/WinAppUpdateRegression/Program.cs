using DavatShodi.GuestManager.Services;
using System.Security.Cryptography;
using System.Text.Json;

static void Check(bool ok, string message) { if (!ok) throw new Exception(message); }
foreach (var code in new[]{402,403,404}) {
 using var response=new System.Net.Http.HttpResponseMessage((System.Net.HttpStatusCode)code);
 try { AppUpdateService.EnsureUpdateResponse(response,"دانلود فایل برنامه"); throw new Exception("Failed HTTP response accepted"); }
 catch(System.Net.Http.HttpRequestException error) { Check(error.StatusCode==(System.Net.HttpStatusCode)code && error.Message.Contains("دانلود فایل برنامه"),"Missing HTTP stage/status diagnostics"); }
}
Check(AppUpdateService.IsNewer("3.0.0", "2.1.0"), "Major update missing");
Check(AppUpdateService.IsNewer("2.10.0", "2.9.0"), "Version comparison must be numeric");
Check(!AppUpdateService.IsNewer("2.0.0", "3.0.0") && !AppUpdateService.IsNewer("3.0.0", "3.0.0"), "Downgrade/equal offered");
var release = new AppRelease { Version="3.0.0", Url="https://davatshodi.ir/downloads/windows/app.exe", Size=2048, Sha256=new string('a',64) };
AppUpdateService.Validate(release);
foreach(var bad in new[]{"http://davatshodi.ir/downloads/windows/a.exe", "https://evil.test/downloads/windows/a.exe", "https://davatshodi.ir/api/a.exe", "https://davatshodi.ir:123/downloads/windows/a.exe"}) {
 release.Url=bad; try { AppUpdateService.Validate(release); throw new Exception("Untrusted URL allowed"); } catch(InvalidDataException) { }
}
var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DavatShodi", "GuestManager", "Updates", "regression-"+Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(root);
var target = Path.Combine(Path.GetTempPath(), "egm-update-target-"+Guid.NewGuid().ToString("N")+".exe");
try {
 await File.WriteAllTextAsync(target,"old version");
 var payload=Path.Combine(root,"payload.exe"); await File.WriteAllTextAsync(payload,"corrupt download");
 var plan= new AppUpdateService.InstallPlan { Target=target, Payload=payload, Hash=new string('a',64), ProcessId=0 };
 var path=Path.Combine(root,"install.json"); await File.WriteAllTextAsync(path,JsonSerializer.Serialize(plan));
 try { await AppUpdateService.ApplyAsync(path); throw new Exception("Corrupt payload installed"); } catch(InvalidDataException) { }
 Check(await File.ReadAllTextAsync(target)=="old version","Failed verification changed the installed app");
 await AppUpdateService.VerifyAsync(payload,Convert.ToHexString(SHA256.HashData(await File.ReadAllBytesAsync(payload))));
} finally {File.Delete(target); Directory.Delete(root,true);}
if(args.Contains("--live")) {
 var current=await AppUpdateService.CheckAsync(); Check(current is not null,"Live feed empty");
 var path=await AppUpdateService.DownloadAsync(current!, new Progress<int>(),CancellationToken.None);
 await AppUpdateService.VerifyAsync(path,current!.Sha256);
 Console.WriteLine("PASS: live release "+current.Version+", download size and SHA-256 verified: "+path);
}
Console.WriteLine("PASS: numeric versions, trusted HTTPS downloads, checksum rejection and original-file preservation.");
