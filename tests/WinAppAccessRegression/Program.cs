using System.Reflection;
using System.Runtime.CompilerServices;
using DavatShodi.GuestManager.Models;
using DavatShodi.GuestManager.Views;
using DavatShodi.GuestManager.Services;
var tracker=new ScanInputTracker();
foreach(var i in Enumerable.Range(0,10))tracker.Record(i*8);Check(tracker.Capture(10).Method=="scanner","Fast scanner not recognized");
tracker.Reset();foreach(var i in Enumerable.Range(0,10))tracker.Record(i*120);Check(tracker.Capture(10).Method=="typed","Manual typing misclassified");
tracker.MarkPaste();Check(tracker.Capture(10).Method=="paste","Paste misclassified as scanner");
tracker.Reset();foreach(var i in Enumerable.Range(0,10))tracker.Record(i*8);tracker.MarkEdited();Check(tracker.Capture(10).Method=="typed","Edited input misclassified");
tracker.Reset();Check(tracker.Capture(10).Method=="unknown","Programmatic input invented timing evidence");
Check(ScanInputTracker.ShouldAutoSubmit(10,"typed") && ScanInputTracker.ShouldAutoSubmit(10,"scanner"),"10-digit input failed automatic submission");
Check(ScanInputTracker.ShouldAutoSubmit(4,"scanner") && !ScanInputTracker.ShouldAutoSubmit(4,"typed"),"Short work ID auto submission ignored scanner detection");
Check(!ScanInputTracker.ShouldAutoSubmit(11,"typed"),"11-digit typo prematurely submitted");
foreach(var json in new[]{"{\"input_timing\":[]}","{\"input_timing\":null}","{}"}) Check(System.Text.Json.JsonSerializer.Deserialize<AttendanceLog>(json)!.SourceInput.DurationMs==0,"Legacy timing broke Windows log parsing");
Check(System.Text.Json.JsonSerializer.Deserialize<AttendanceLog>("{\"input_timing\":{\"duration_ms\":1200}}")!.SourceInput.DurationMs==1200,"Modern timing lost its evidence");
var role = new WinAppAccessInfo {Level="scan_and_actions",CanViewUserInfo=false,CanManageScanActions=true,CanManageSettings=false};
var router=new ConcurrentScanInput();
double clock=0;
foreach(var digit in "0012345678") { router.Push(digit.ToString(),clock);clock+=130; }
foreach(var digit in "9876543210") { router.Push(digit.ToString(),clock);clock+=8; }
var pair=router.Flush();
Check(pair is {TypedCode:"0012345678",ScannedCode:"9876543210"},"Manual code merged with a sudden scanner burst");
Check(pair!.ScannerInput.Method=="scanner" && pair.ScannerInput.KeyCount==10,"Scanner timing lost after splitting");
router.Reset(); clock=0;
foreach(var digit in "0012345678") { router.Push(digit.ToString(),clock);clock+=130; }
var manual=router.Flush();Check(manual is {TypedCode:"0012345678",ScannedCode:""},"Manual-only code changed to scanner");
router.Reset();clock=0;
foreach(var digit in "9876543210") { router.Push(digit.ToString(),clock);clock+=8; }
Check(router.Flush(true) is {TypedCode:"",ScannedCode:"9876543210"},"Scanner-only code failed");
router.Reset("0012");clock=0;
foreach(var digit in "9876543210") { router.Push(digit.ToString(),clock);clock+=8; }
Check(router.Flush() is {TypedCode:"0012",ScannedCode:"9876543210"},"Incomplete typing was lost when scanner interrupted");
router.Reset("001234567");router.Push("8",0);clock=30;
foreach(var digit in "9876543210") { router.Push(digit.ToString(),clock);clock+=8; }
Check(router.Flush(true) is {TypedCode:"0012345678",ScannedCode:"9876543210"},"Human final digit was swallowed by the scanner burst");
router.Reset();router.Push("10",0);Check(router.Flush() is null && router.DisplayText=="10","Partial manual draft discarded");
Check(router.Flush(true) is {TypedCode:"10",ScannedCode:""},"Manual Enter failed for short work ID");
Check(role.CanViewLogs && !role.CanOpenGuestDetails && !role.CanResetGuestEntry,"New level exposed details/reset or hid logs");
var guest=new AttendanceLog {NationalId="2290123123",MaskNationalId=true};
Check(guest.IdentifierLabel.Contains("229****123"),"National ID not masked");
Check(guest.GuestCode=="2290123123","Masking changed the scan action identifier");
guest.MaskNationalId=false; Check(guest.IdentifierLabel.Contains(guest.NationalId),"Existing role identifiers changed");
var type=typeof(MainWindow); var window=RuntimeHelpers.GetUninitializedObject(type);
void Set(string name,object? value)=>type.GetField(name,BindingFlags.NonPublic|BindingFlags.Instance)!.SetValue(window,value);
bool Has()=>(bool)type.GetMethod("HasSettingsAccess",BindingFlags.NonPublic|BindingFlags.Instance)!.Invoke(window,null)!;
Set("_settingsPasscode","");Check(!Has(),"Settings access without PIN");
Set("_settingsPasscode","test-pin");Set("_settingsAwaySinceUtc",null);Check(Has(),"Settings should remain open while being used");
Set("_settingsAwaySinceUtc",DateTime.UtcNow.AddSeconds(-179));Check(Has(),"Settings locked before three minutes outside");
Set("_settingsAwaySinceUtc",DateTime.UtcNow.AddSeconds(-181));Check(!Has(),"Settings still open after three minutes outside");
Set("_settingsAwaySinceUtc",null);Check(Has(),"Returning to settings did not clear outside timer");
Set("_settingsPasscode","");Check(!Has(),"Manual PIN-grant lock failed");
Console.WriteLine("PASS: action-only level, masked log ID, unchanged action ID, settings grant and three-minute outside timeout");
void Check(bool condition,string message){if(!condition)throw new Exception(message);}
