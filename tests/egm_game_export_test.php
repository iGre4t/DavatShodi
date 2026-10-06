<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-period-exports.php';
require_once dirname(__DIR__) . '/api/lib/egm-game-exports.php';
function gameCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class GameReportPdo extends PDO {
    public array $data=[]; public array $periods=[]; public array $tables=[]; public array $profiles=[]; public array $menus=[]; public bool $granted=true;
    public function __construct() {}
    public function exec(string $statement):int|false{return 0;}
    public function prepare(string $query, array $options=[]):PDOStatement|false{return new GameReportStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new GameReportStatement($this,$query);}
}
class GameReportStatement extends PDOStatement {
    private GameReportPdo $pdo; private string $sql; private array $params=[];
    public function __construct(GameReportPdo $pdo,string $sql){$this->pdo=$pdo;$this->sql=$sql;}
    public function execute(?array $params=null):bool{
        $this->params=$params??[];
        if(preg_match('/^INSERT INTO egm_(telegram|bale)_export_menus/',$this->sql))$this->pdo->menus[]=json_decode($this->params[3],true);
        return true;
    }
    public function fetchColumn(int $column=0):mixed {
        if(str_contains($this->sql,'information_schema'))return isset($this->pdo->tables[$this->params[':table']])?1:0;
        if(str_contains($this->sql,'`periods`'))return json_encode($this->pdo->periods);
        return isset($this->pdo->data[$this->params[':data_key']??''])?json_encode($this->pdo->data[$this->params[':data_key']]):false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if(preg_match('/egm_(telegram|bale)_grants/',$this->sql))return $this->pdo->granted?[['egm_code'=>'12345','pin_fingerprint'=>hash('sha256','test-pin'),'name'=>'Test']]:[];
        if(str_contains($this->sql,'JOIN `egm_12345_users`')||str_contains($this->sql,'FROM `egm_12345_users`'))return $this->pdo->profiles;
        preg_match('/FROM `(egm_game_[a-f0-9]+)`/',$this->sql,$match);
        return $this->pdo->tables[$match[1]??'']??[];
    }
}
$pdo=new GameReportPdo();$cache=&egmInstanceEnsureCache();$cache[spl_object_id($pdo).':12345']=true;
$game=['id'=>'1234567890abcdef','name'=>'بازی / دیجیتال','has_levels'=>true,'levels'=>[['id'=>'level1','name'=>'مرحله اول'],['id'=>'level2','name'=>'مرحله دوم']],'rooms'=>[],'no_score_needed'=>false];
foreach([['01','2026-09-01'],['02','2026-10-07'],['03','2026-10-08']]as[$code,$date])$pdo->periods[]=['tagCode'=>$code,'taskType'=>'period','duration'=>true,'startDate'=>$date,'startTime'=>'09:00','endDate'=>$date,'endTime'=>'22:00'];
$pdo->data[EGM_GAMES_KEY]=['games'=>[$game],'enabled'=>['01'=>[$game['id']],'02'=>[$game['id']],'03'=>[$game['id']]]];
$pdo->tables[egmGamesTableName('12345','01',$game['id'])]=[
 json_encode(['type'=>'team','started_at'=>'2026-09-01 10:00','members'=>[['id'=>1]],'scores'=>[]]),
 json_encode(['type'=>'team','members'=>[['id'=>2]],'scores'=>[]]),
];
$pdo->tables[egmGamesTableName('12345','03',$game['id'])]=[json_encode(['type'=>'team','started_at'=>'2026-09-01 10:00','members'=>[['id'=>3]],'scores'=>[]])];
$past=egmGamePastParticipants($pdo,'12345','02',$game['id']);
gameCheck(isset($past[1])&&!isset($past[2])&&!isset($past[3]),'Past-game detection includes unstarted teams or future periods');
$profile=['first_name'=>'علی','last_name'=>'رضایی','work_id'=>'0007','national_id'=>'0012345678','phone_number'=>'09120000001','period_is_uninvited_guest'=>1,'invitation_source'=>'walk_in','entered_date'=>'2026-10-07','entered_time'=>'10:00','should_get_gift'=>0];
gameCheck(egmGamePrizeState($profile,false)['prize_warning_tone']==='walk-in','Pending/rejected walk-in warning missing');
gameCheck(egmGamePrizeState($profile+['draw_eligible'=>1],false)['prize_warning_tone']==='walk-in','Draw-only approval incorrectly clears gift warning');
$approved=array_replace($profile,['should_get_gift'=>1]);
gameCheck(egmGamePrizeState($approved,false)['prize_warning']==='','Approved gift still warns');
gameCheck(egmGamePrizeState($approved,true)['prize_warning']===EGM_GAME_PAST_WARNING,'Previous game warning lost after gift approval');
gameCheck(egmGamePrizeState(['invitation_source'=>'custom'],false)['prize_warning']==='','Invited user incorrectly marked walk-in');
$team=egmRefMonitorTeamView(['id'=>8,'created_at'=>'2026-10-07'],['name'=>'=SUM(1,1)','members'=>[['id'=>1,'name'=>'علی رضایی','previous_game_participation'=>true]],'created_by'=>'facilitator:123','creator'=>['name'=>'تسهیلگر','username'=>'ref01','code'=>'facilitator:123','type'=>'facilitator'],'scores'=>['level1'=>['score'=>15,'submitted_by'=>'facilitator:123','submitted_at'=>'2026-10-07 11:00','room_name'=>'آفتاب']]]);
$records=egmGameExportRecords($game,[$team],[1=>$profile],'02','بازه دوم');
gameCheck($records[0]['نام کاربری سازنده']==='ref01'&&$records[0]['سازنده تیم']==='تسهیلگر','Creator missing');
gameCheck($records[0]['کد ملی عضو']==='0012345678'&&$records[0]['کد پرسنلی عضو']==='0007','Identifiers lost leading zeros');
gameCheck($records[0]['نوع ورود']==='مهمان ناخوانده'&&$records[0]['ورود ثبت شده']==='بله','Walk-in and entry conflated');
gameCheck($records[0]['نتیجه: 1 · مرحله اول']==='15'&&$records[0]['نتیجه: 2 · مرحله دوم']==='ثبت نشده','Stage results missing');
gameCheck($records[0]['هشدار جایزه']===EGM_GAME_PAST_WARNING,'Prize reason missing');
$noScore=$game;$noScore['no_score_needed']=true;$team['scores']['level1']=['score'=>null,'completion_only'=>true];
$completion=egmGameExportRecords($noScore,[$team],[1=>$approved],'02','بازه دوم');
gameCheck($completion[0]['امتیاز کل تیم']==='بدون امتیاز'&&$completion[0]['نتیجه: 1 · مرحله اول']==='پایان‌یافته','No-score export fabricated scores');
$xml=egmGameExportXml([['name'=>$game['name'],'records'=>$records],['name'=>$game['name'],'records'=>$completion],['name'=>'بازی خالی','records'=>[]]]);
$doc=new DOMDocument();gameCheck($doc->loadXML($xml),'Invalid workbook XML');
$xpath=new DOMXPath($doc);$xpath->registerNamespace('ss','urn:schemas-microsoft-com:office:spreadsheet');
gameCheck($xpath->query('//ss:Worksheet')->length===3,'Each game needs its own sheet');
gameCheck(!str_contains($xml,'<Formula')&&str_contains($xml,'ss:Type="String">=SUM(1,1)'),'Export text interpreted as a formula');
$xlsx=appXlsxFromSpreadsheetXml($xml);gameCheck(str_starts_with($xlsx,"PK\x03\x04"),'Not a real XLSX');
gameCheck(isset(egmPeriodExportTypes()['games']),'Telegram export type missing');
if(isset($argv[1]))file_put_contents($argv[1],$xlsx);
// Test the existing Telegram menu and document flow with an in-memory transport.
require_once dirname(__DIR__).'/api/lib/system-telegram.php';
$GLOBALS['systemBotProvider']=getenv('EGM_TEST_BOT_PROVIDER')==='bale'?'bale':'telegram';
$pdo->data['settings']=['adminPasscode'=>['hash'=>'test-pin']];
$pdo->profiles=[['id'=>1,'user_id'=>1]+$profile];
$pdo->tables[egmGamesTableName('12345','02',$game['id'])]=[['id'=>8,'created_at'=>'2026-10-07','payload'=>json_encode(['type'=>'team','name'=>'Team','members'=>[['id'=>1,'name'=>'علی رضایی']],'scores'=>[],'created_by'=>'facilitator:123','creator'=>['name'=>'تسهیلگر','username'=>'ref01','type'=>'facilitator']])]];
$context=['pdo'=>$pdo,'code'=>'12345','tables'=>egmInstanceTableNames('12345'),'name'=>'Test'];
$sent=[];
$GLOBALS[systemBotProvider()==='bale'?'systemBaleTestTransport':'systemTelegramTestTransport']=static function(string $method,array $payload)use(&$sent):array{
    if($method==='sendDocument')$sent[]=file_get_contents($payload['document']->getFilename());
    return ['message_id'=>1];
};
systemTelegramExportChoose($pdo,'admin','chat',['kind'=>'types','code'=>'12345','period'=>'02'],$context);
gameCheck(count(array_filter($pdo->menus,fn($menu)=>($menu['type']??'')==='games'))===1,'Telegram game export button missing');
systemTelegramExportChoose($pdo,'admin','chat',['kind'=>'file','code'=>'12345','period'=>'02','type'=>'games'],$context);
gameCheck(count($sent)===1&&str_starts_with($sent[0],"PK\x03\x04"),'Telegram did not send game workbook');
$pdo->granted=false;
try{systemTelegramExportChoose($pdo,'admin','chat',['kind'=>'file','code'=>'12345','period'=>'02','type'=>'games'],$context);throw new RuntimeException('Unauthorized Telegram export accepted');}
catch(InvalidArgumentException $expected){}
gameCheck(count($sent)===1,'Unauthorized export sent a file');
echo "Game history, gift decisions, creator/member information, attendance, stage results, no-score results and multi-sheet XLSX passed with a PDO fixture.\n";
echo "Telegram game export menu, XLSX document transport and authorization passed with an isolated transport.\n";
