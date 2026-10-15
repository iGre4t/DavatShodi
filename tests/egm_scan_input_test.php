<?php
require_once dirname(__DIR__).'/api/lib/egm-check-in.php';
function scanInputCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$rows=[['id'=>1,'national_id'=>'0012345678','first_name'=>'First','last_name'=>'Guest'],['id'=>2,'national_id'=>'9876543210','first_name'=>'Other','last_name'=>'Guest']];
foreach(['0012345679','0012345689','00112345678','0012435678','12345678'] as $typed)scanInputCheck((egmScanNearCandidates($typed,$rows)[0]['user_id']??0)===1,'Near national ID missed: '.$typed);
scanInputCheck(egmScanNearCandidates('12',$rows)===[],'Work ID caused broad national-ID suggestions');
scanInputCheck(egmScanNearCandidates('7777777777',$rows)===[],'Distant guest suggested');
$input=egmScanInputNormalize(['method'=>'typed','key_count'=>10,'duration_ms'=>1800,'corrected_from'=>'0012345679']);
scanInputCheck($input['input_method']==='typed'&&$input['corrected_from']==='0012345679','Input metadata normalization failed');
scanInputCheck(egmScanInputNormalize([])['input_method']==='unknown','Legacy input invented a method');
foreach ([null, [], ['duration_ms'=>1800], 'invalid'] as $oldTiming) {
    $wireTiming=egmScanInputNormalize(is_array($oldTiming)?$oldTiming:[])['input_timing'];
    scanInputCheck(str_starts_with(json_encode($wireTiming),'{'), 'Legacy timing serialized as an array instead of a Windows-compatible object');
    scanInputCheck(isset($wireTiming['key_count'],$wireTiming['duration_ms'],$wireTiming['max_gap_ms'],$wireTiming['avg_gap_ms']), 'Timing object lost required defaults');
}
scanInputCheck(egmScanInputNormalize(['method'=>['bad'],'duration_ms'=>-20])['input_timing']['duration_ms']===0,'Malformed input not bounded');
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,first_name TEXT,last_name TEXT,national_id TEXT,is_active INTEGER)');
$pdo->exec('CREATE TABLE periods(user_id INTEGER,period_code TEXT,is_uninvited_guest INTEGER,invitation_source TEXT,entered_date TEXT,quit_date TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'First','Guest','0012345678',1),(2,'Wrong','Period','0012345679',1),(3,'Walk','In','0012345677',1),(4,'Inactive','Guest','0012345676',0)");
$pdo->exec("INSERT INTO periods VALUES(1,'active',0,'period_excel',NULL,NULL),(2,'other',0,'period_excel',NULL,NULL),(3,'active',1,'walk_in',NULL,NULL),(4,'active',0,'period_excel',NULL,NULL)");
$context=['pdo'=>$pdo,'period'=>['tagCode'=>'active'],'tables'=>['users'=>'users','user_periods'=>'periods','activity_logs'=>'logs'],'scan_input'=>$input];
$matches=egmScanSuggestions($context,'0012345679');
scanInputCheck(array_column($matches,'user_id')===[1],'Suggestion exposed another period, walk-in or inactive guest');
$context['scan_input']=egmScanInputNormalize(['method'=>'scanner']);scanInputCheck(egmScanSuggestions($context,'0012345679')===[],'Scanner input got typing suggestions');
$context['scan_input']=$input;
$pdo->exec('CREATE TABLE logs(id INTEGER PRIMARY KEY AUTOINCREMENT,source_key TEXT,user_id INTEGER,work_id TEXT,session_id TEXT,level TEXT,action TEXT,entity_type TEXT,entity_id TEXT,ip_address TEXT,user_agent TEXT,status TEXT,message TEXT,metadata_json TEXT,occurred_at TEXT)');
$logId=egmCheckInWriteLog($context,null,'0012345679','not_found','Not found',['tagCode'=>'active','title'=>'Period'],new DateTimeImmutable('2026-10-13',new DateTimeZone('Asia/Tehran')));
$metadata=json_decode($pdo->query('SELECT metadata_json FROM logs WHERE id='.(int)$logId)->fetchColumn(),true);
scanInputCheck($metadata['input_method']==='typed'&&$metadata['input_timing']['duration_ms']==1800&&$metadata['submitted_code']==='0012345679','Database log lost source/timing/submitted ID');
echo "PASS: typed/scanner audit persistence, one/two-digit errors, adjacent duplication/transposition, and current-period invited-only suggestions\n";
class ScanLookupSqlite extends PDO {
    public function prepare(string $query, array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$query),$options); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false { $query=str_replace(' FOR UPDATE','',$query); return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args); }
}
$lookup=new ScanLookupSqlite('sqlite::memory:');
$lookup->exec('CREATE TABLE guests(id INTEGER PRIMARY KEY,national_id TEXT,work_id TEXT)');
$lookup->exec("INSERT INTO guests VALUES(1,'12345678','101'),(2,'912345678','102'),(3,'9876543210','12345678')");
scanInputCheck((int)(egmCheckInFindUser($lookup,'guests','0012345678')['id']??0)===1,'Suggested padded ID failed to resolve its stored eight-digit identity');
scanInputCheck((int)(egmCheckInFindUser($lookup,'guests','0912345678')['id']??0)===2,'Suggested padded ID failed to resolve its stored nine-digit identity');
scanInputCheck((int)(egmCheckInFindUser($lookup,'guests','12345678')['id']??0)===3,'National padding changed work ID lookup');
echo "PASS: selected suggestions resolve legacy missing-zero IDs without changing work-ID identity\n";
$lookup->exec("ALTER TABLE guests ADD COLUMN is_active INTEGER DEFAULT 1; ALTER TABLE guests ADD COLUMN first_name TEXT DEFAULT 'Guest'; ALTER TABLE guests ADD COLUMN last_name TEXT DEFAULT ''");
$lookup->exec('CREATE TABLE invitations(user_id INTEGER,period_code TEXT,entered_date TEXT,quit_date TEXT,is_uninvited_guest INTEGER DEFAULT 0,invitation_source TEXT DEFAULT \'period_excel\')');
$lookup->exec("INSERT INTO invitations(user_id,period_code) VALUES(1,'active'),(2,'other'),(3,'active')");
$lookupContext=['pdo'=>$lookup,'period'=>['tagCode'=>'active'],'tables'=>['users'=>'guests','user_periods'=>'invitations']];
$pair=egmScanLookupCandidates($lookupContext,'0012345678','9876543210');
scanInputCheck(array_column($pair['candidates'],'user_id')===[1,3],'Concurrent lookup lost either invited guest');
scanInputCheck(array_column($pair['candidates'],'input_method')===['typed','scanner'],'Concurrent source labels lost');
scanInputCheck(egmScanLookupCandidates($lookupContext,'','0912345678')['candidates']===[],'Other-period scanner guest accepted');
$near=egmScanLookupCandidates($lookupContext,'0012345679','9876543210');
scanInputCheck(array_column($near['candidates'],'user_id')===[1,3],'Typo candidate/scanner pair failed');
scanInputCheck((int)$lookup->query('SELECT COUNT(*) FROM invitations WHERE entered_date IS NOT NULL')->fetchColumn()===0,'Candidate lookup changed attendance before confirmation');
echo "PASS: read-only dual-source lookup, current-period filtering and typo candidates without attendance mutation\n";
