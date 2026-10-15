<?php
declare(strict_types=1);
putenv('DAVATSHODI_TELEGRAM_TOKEN=test-token');
putenv('DAVATSHODI_BALE_TOKEN=test-token');
require_once dirname(__DIR__).'/api/lib/egm-guest-reports.php';
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-teams.php';
function reportsAssert(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);}
$pdo=new PDO('mysql:host=127.0.0.1;port='.(getenv('EGM_TEST_DB_PORT')?:'3306').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='egm_reports_test_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
$calls=[];$offline=false;
foreach (['telegram','bale'] as $provider) $GLOBALS[$provider==='bale'?'systemBaleTestTransport':'systemTelegramTestTransport']=static function(string $method,array $payload) use (&$calls,&$offline,$provider):array {
    if ($offline) throw new RuntimeException('Test transport offline');
    $calls[]=[$provider,$method,$payload];return ['message_id'=>count($calls)];
};
try {
    $pdo->exec("USE `{$db}`");$code='9876543210';$tables=ensureEgmInstanceTables($pdo,$code);ensureActivityLogTable($pdo,'EGM',$code);
    $hash=password_hash('909090',PASSWORD_DEFAULT);
    $period=['tagCode'=>'01','title'=>'آزمایش','active'=>true,'duration'=>false,'quitRequired'=>false,'prizeEntryWindowEnabled'=>true,'prizeEntryStartDate'=>'2026-10-09','prizeEntryStartTime'=>'08:00','prizeEntryEndDate'=>'2026-10-09','prizeEntryEndTime'=>'10:00'];
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables,'name'=>'آزمایش','periods'=>[$period],'period'=>$period,'period_code'=>'01','period_state'=>['result'=>'active','period'=>$period]];
    egmInstanceWriteData($pdo,$code,'settings',['autoReport'=>false,'adminPasscode'=>['hash'=>$hash]]);
    foreach (['telegram','bale'] as $provider) systemBotWithProvider($provider,static function()use($pdo,$code,$hash):void {
        systemTelegramEnsure($pdo);
        systemBotPrepare($pdo,'INSERT INTO egm_telegram_grants(egm_code,admin_id,chat_id,pin_fingerprint) VALUES(?,?,?,?)')->execute([$code,'111','111',hash('sha256',$hash)]);
    });
    $actor=['code'=>'admin-code','username'=>'مدیر'];$now=new DateTimeImmutable('2026-10-09 12:00:00',new DateTimeZone('Asia/Tehran'));
    $result=egmCheckInProcess($context,'1234567890',$now,null,$actor);
    reportsAssert($result['result']==='not_found','Unknown scan failed incorrectly');
    reportsAssert(count($calls)===0,'Disabled auto-report sent a bot message');
    reportsAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_reports')->fetchColumn()===0,'Bare unknown scan created a report');
    foreach(['telegram','bale'] as $provider)systemBotWithProvider($provider,static fn()=>systemTelegramRetry($pdo));
    reportsAssert(count($calls)===0,'Retry sent a report while automatic reporting was disabled');
    egmInstanceWriteData($pdo,$code,'settings',['autoReport'=>true,'adminPasscode'=>['hash'=>$hash]]);
    egmCheckInProcess($context,'1234567891',$now,null,$actor);
    reportsAssert(count($calls)===0,'Bare unknown scan notified bots');
    $pdo->exec("INSERT INTO `{$tables['users']}`(work_id,national_id,first_name,last_name,guest_number,source_row) VALUES('12','1234567892','Guest','Late','1',1),('13','1234567893','Guest','Other','2',2)");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}`(user_id,period_code) VALUES(1,'01'),(2,'02')");
    $late=egmCheckInProcess($context,'1234567892',$now,null,$actor);
    reportsAssert($late['result']==='success','Late entered guest was rejected');
    reportsAssert(count($calls)===2,'Late entry outside pot window was not automatically reported');
    $lateId=$pdo->query('SELECT id FROM egm_telegram_reports WHERE user_id=1')->fetchColumn();
    $resolved=systemTelegramDecide($pdo,$lateId,'games','0',$context,$actor);
    egmGuestReportsRefreshBots($pdo,$resolved);
    $row=$pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE user_id=1")->fetch(PDO::FETCH_ASSOC);
    reportsAssert((int)$row['games_eligible']===1&&(int)$row['draw_eligible']===0&&(int)$row['should_get_gift']===0,'Games-only decision incorrect');
    foreach(array_slice($calls,-2) as $call) {
        reportsAssert($call[1]==='editMessageText'&&str_contains($call[2]['text'],'✅ حل شده'),'Resolved report message not edited');
        reportsAssert($call[2]['reply_markup']['inline_keyboard'][0][0]['text']==='برگرداندن به بررسی','Resolved button not replaced');
    }
    $reopened=systemTelegramDecide($pdo,$lateId,'reopen','111',$context);
    egmGuestReportsRefreshBots($pdo,$reopened);
    reportsAssert(!str_contains(end($calls)[2]['text'],'✅ حل شده'),'Reopening left resolved text');
    $row=$pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE user_id=1")->fetch(PDO::FETCH_ASSOC);
    reportsAssert($row['draw_eligible']===null&&$row['games_eligible']===null&&(int)$row['should_get_gift']===1,'Reopening did not restore normal eligibility');
    $denied=systemTelegramDecide($pdo,$lateId,'deny','0',$context,$actor);
    reportsAssert(egmRefMonitorSearchInvitees($pdo,$code,'01','Guest')===[],'Denied guest still searchable for games');
    try {egmRefMonitorResolveMembers($pdo,$code,'01',[1],10);throw new RuntimeException('Denied game member accepted');}catch(InvalidArgumentException $expected){}
    $offline=true;egmGuestReportsRefreshBots($pdo,$denied);
    reportsAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_deliveries WHERE needs_refresh=1')->fetchColumn()===1,'Failed edit not queued');
    $offline=false;systemTelegramRetry($pdo);
    reportsAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_deliveries WHERE needs_refresh=1')->fetchColumn()===0,'Failed edit did not retry');
    egmCheckInProcess($context,'1234567893',$now,null,$actor);
    reportsAssert((int)$pdo->query("SELECT COUNT(*) FROM egm_telegram_reports WHERE user_id=2")->fetchColumn()===1,'Other-period invitation not reported');
    $normalPeriod=$period;$normalPeriod['prizeEntryWindowEnabled']=false;$context['period']=$normalPeriod;$context['periods']=[$normalPeriod];
    $pdo->exec("INSERT INTO `{$tables['users']}`(work_id,national_id,first_name,guest_number,source_row) VALUES('14','1234567894','Normal','3',3)");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}`(user_id,period_code) VALUES(3,'01')");
    $before=(int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_reports')->fetchColumn();
    egmCheckInProcess($context,'1234567894',$now,null,$actor);
    reportsAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_reports')->fetchColumn()===$before,'Ordinary successful entry was reported');
    $walk=egmCheckInRegisterUninvited($context,['national_id'=>'1234567895','work_id'=>'15','first_name'=>'Walk','last_name'=>'Guest','gender'=>'male','outside_organization'=>true],$actor,$now);
    reportsAssert($walk['result']==='walk_in_registered','Walk-in registration failed');
    $walkReport=$pdo->query("SELECT * FROM egm_telegram_reports WHERE guest_code='1234567895'")->fetch(PDO::FETCH_ASSOC);
    reportsAssert((bool)$walkReport,'Walk-in did not create report');
    $approval=systemTelegramDecide($pdo,$walkReport['id'],'games_draw','0',$context,$actor);
    $row=$pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE user_id=".(int)$walk['user_id'])->fetch(PDO::FETCH_ASSOC);
    reportsAssert((int)$row['games_eligible']===1&&(int)$row['draw_eligible']===1&&(int)$row['should_get_gift']===0,'Games/draw independent permissions failed');
    $other=$context;$other['code']='9999999999';
    try {systemTelegramDecide($pdo,$walkReport['id'],'reopen','0',$other,$actor);throw new RuntimeException('Cross-EGM review accepted');}catch(InvalidArgumentException $expected){}
    $list=egmGuestReportsList($context,['state'=>'resolved','period'=>'01']);
    reportsAssert($list['total']===2,'Resolved report filter incorrect');
    $list=egmGuestReportsList($context,['state'=>'all','q'=>'1234567895']);
    reportsAssert($list['total']===1,'Search/duplicate backfill incorrect');
    reportsAssert($list['reports'][0]['summary']['reason']==='ورود بدون دعوت','Walk-in summary missing');
    $noActive=$context;$noActive['period_code']='';
    reportsAssert(egmGuestReportsList($noActive,['state'=>'all'])['total']===0,'No active period exposed old reports by default');
    reportsAssert(egmGuestReportsList($noActive,['state'=>'all','period'=>''])['total']>0,'All-period filter unavailable without an active period');
    reportsAssert(egmGuestReportSummary(['draw_time_excluded'=>true])['effect']==='عدم شرکت در قرعه‌کشی','Late-entry summary incorrect');
    reportsAssert(egmGuestReportSummary(['status'=>'force_quit_success','is_uninvited_guest'=>true])['effect']==='عدم دریافت هدیه','Forced-exit summary must take priority');
    $guest=$pdo->query("SELECT * FROM `{$tables['users']}` WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    foreach (['minimum_stay','immune_time','quit_duplicate','quit_success'] as $blocked) {
        $log=egmCheckInWriteLog($context,$guest,'1234567892',$blocked,'Blocked checkout',$period,$now,['attendance_action'=>'quit','force_action'=>'quit']);
        reportsAssert(egmGuestReportsStore($context,$log)===null,'Blocked/ordinary checkout produced a report: '.$blocked);
    }
    $forced=egmCheckInWriteLog($context,$guest,'1234567892','force_quit_success','Forced checkout',$period,$now,['attendance_action'=>'quit']);
    reportsAssert(egmGuestReportsStore($context,$forced)!==null,'Forced checkout did not produce a report');
    $legacyId=bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$legacyId,$code,'01',99999,1,'1234567892',json_encode(['status'=>'minimum_stay','attendance_action'=>'quit']),$now->format('Y-m-d H:i:s')]);
    $list=egmGuestReportsList($context,['state'=>'all']);
    reportsAssert(!in_array($legacyId,array_column($list['reports'],'id'),true),'Legacy blocked checkout remained visible');
    reportsAssert(in_array('force_quit_success',array_column(array_column($list['reports'],'guest'),'status'),true),'Forced checkout missing from panel');
    $source=$pdo->query("SELECT * FROM egm_telegram_reports WHERE log_id=".$forced)->fetch(PDO::FETCH_ASSOC);
    $copies=[];
    foreach([90001,90002]as $log){$copyId=bin2hex(random_bytes(16));$copies[]=$copyId;$pdo->prepare('INSERT INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot)VALUES(?,?,?,?,?,?,?)')->execute([$copyId,$code,'01',$log,1,$source['guest_code'],$source['snapshot']]);}
    $result=systemTelegramDecide($pdo,$source['id'],'deny','0',$context,$actor);
    reportsAssert($result['id']===$copies[1],'Duplicate link did not use canonical incident');
    $resolvedCopies=$pdo->query("SELECT COUNT(*) FROM egm_telegram_reports WHERE id IN ('".implode("','",[$source['id'],...$copies])."') AND decision='deny'")->fetchColumn();
    reportsAssert((int)$resolvedCopies===3,'Duplicate reports not solved together');
    $list=egmGuestReportsList($context,['state'=>'all']);$forcedGroups=array_values(array_filter($list['reports'],static fn($item)=>($item['guest']['status']??'')==='force_quit_success'));
    reportsAssert(count($forcedGroups)===1&&$forcedGroups[0]['repeat_count']===3,'Resolved duplicates remained separate');
    systemTelegramDecide($pdo,$source['id'],'reopen','0',$context,$actor);
    reportsAssert((int)$pdo->query("SELECT COUNT(*) FROM egm_telegram_reports WHERE id IN ('".implode("','",[$source['id'],...$copies])."') AND decision IS NULL")->fetchColumn()===3,'Group reopen failed');
    echo "PASS: auto-report off/on, failed scans, late pot entries, walk-ins, dual-bot edits/reopen/retry, independent games/draw/gift permissions, filters and event isolation.\n";
} finally {$pdo->exec("DROP DATABASE `{$db}`");}
