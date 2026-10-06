<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/system-telegram.php';
require_once dirname(__DIR__).'/api/lib/egm-period-draws.php';
function botAssert(bool $ok, string $message): void {if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='egm_bot_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
$calls=[]; $documents=[];
$GLOBALS['systemTelegramTestTransport']=static function(string $method,array $payload) use (&$calls,&$documents):array {
    $calls[]=[$method,$payload];
    if ($method==='sendDocument') $documents[]=['path'=>$payload['document']->getFilename(),'filename'=>$payload['document']->getPostFilename(),'content'=>file_get_contents($payload['document']->getFilename()),'chat'=>$payload['chat_id']];
    return ['message_id'=>count($calls)];
};
try {
    $pdo->exec("USE `{$database}`");ensureEgmRegistryTable($pdo);systemTelegramEnsure($pdo);
    $code='9876543210';$tables=ensureEgmInstanceTables($pdo,$code);
    insertEgmRegistry($pdo,$code,'آزمایشی','mini apps/EGMs/9876543210');
    $hash=password_hash('909090',PASSWORD_DEFAULT);
    egmInstanceWriteData($pdo,$code,'settings',['adminPasscode'=>['hash'=>$hash]]);
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables,'name'=>'آزمایشی'];
    $message=['chat'=>['type'=>'private','id'=>111],'from'=>['id'=>111],'text'=>'pin','message_id'=>1];
    systemTelegramUpdate($pdo,['message'=>$message]);
    $message['text']='909090';$message['message_id']=2;systemTelegramUpdate($pdo,['message'=>$message]);
    botAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_grants')->fetchColumn()===1,'Correct PIN did not grant access');
    botAssert(end($calls)[1]['reply_markup']['keyboard'][0][0]['text']==='خروجی', 'Manager did not receive bottom export button');
    $deleted=array_filter($calls,fn($call)=>$call[0]==='deleteMessage'&&$call[1]['message_id']===2);
    botAssert(count($deleted)===1,'PIN message was not deleted');
    $other=$message;$other['from']['id']=222;$other['chat']['id']=222;$other['text']='pin';systemTelegramUpdate($pdo,['message'=>$other]);
    for($attempt=0;$attempt<6;$attempt++) {$other['text']='000000';systemTelegramUpdate($pdo,['message'=>$other]);}
    botAssert((int)$pdo->query('SELECT blocked_until>NOW() FROM egm_telegram_sessions WHERE admin_id=222')->fetchColumn()===1,'PIN brute-force throttle missing');
    $pdo->exec("INSERT INTO `{$tables['users']}` (work_id,first_name,guest_number) VALUES('12','Invited','1'),('13','Walk-in','2')");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (user_id,period_code,entered_date,entered_time,is_uninvited_guest) VALUES(1,'01','2026-10-06','12:00',0),(2,'01','2026-10-06','12:00',1),(1,'02',NULL,NULL,0)");
    egmBenefitsApplyEntry($context,1,'01');egmBenefitsApplyEntry($context,2,'01');
    botAssert((int)$pdo->query("SELECT should_get_gift FROM `{$tables['user_periods']}` WHERE user_id=1 AND period_code='01'")->fetchColumn()===1,'Normal entry did not receive gift flag');
    botAssert((int)$pdo->query("SELECT should_get_gift FROM `{$tables['user_periods']}` WHERE user_id=2")->fetchColumn()===0,'Walk-in got gift automatically');
    $reportId=str_repeat('a',32);
    $snapshot=['full_name'=>'آزمایش','period_title'=>'بازه','status'=>'walk_in_registered','message'=>'این مهمان دعوت نشده','work_id'=>'13'];
    $insert=$pdo->prepare('INSERT INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot) VALUES(?,?,?,?,?,?,?)');
    $insert->execute([$reportId,$code,'01',1,2,'13',json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
    systemTelegramDeliver($pdo,$pdo->query('SELECT * FROM egm_telegram_reports')->fetch(PDO::FETCH_ASSOC));
    botAssert((int)$pdo->query('SELECT COUNT(*) FROM egm_telegram_deliveries')->fetchColumn()===1,'Report not delivered to PIN admin');
    systemTelegramDeliver($pdo,$pdo->query('SELECT * FROM egm_telegram_reports')->fetch(PDO::FETCH_ASSOC));
    botAssert(count(array_filter($calls,fn($call)=>$call[0]==='sendMessage'&&isset($call[1]['reply_markup']['inline_keyboard'])))===1,'Repeated report was delivered twice');
    try {systemTelegramDecide($pdo,$reportId,'both','222',$context);throw new RuntimeException('Cross-user authorization accepted');}catch(InvalidArgumentException $expected){}
    systemTelegramDecide($pdo,$reportId,'gift','111',$context);
    $period=$pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE user_id=2")->fetch(PDO::FETCH_ASSOC);
    botAssert((int)$period['should_get_gift']===1 && (int)$period['draw_eligible']===0,'Gift-only decision incorrect');
    try {systemTelegramDecide($pdo,$reportId,'both','111',$context);throw new RuntimeException('Already reviewed report accepted');}catch(InvalidArgumentException $expected){}
    egmBenefitsApplyEntry($context,2,'01');
    botAssert((int)$pdo->query("SELECT should_get_gift FROM `{$tables['user_periods']}` WHERE user_id=2")->fetchColumn()===1,'Entry overwrote manager decision');
    $unknownId=str_repeat('b',32);$insert->execute([$unknownId,$code,'01',2,null,'14',json_encode($snapshot)]);
    systemTelegramDecide($pdo,$unknownId,'draw','111',$context);
    $pdo->exec("INSERT INTO `{$tables['users']}` (work_id,first_name,guest_number) VALUES('14','New guest','3')");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (user_id,period_code,entered_date,entered_time,is_uninvited_guest) VALUES(3,'01','2026-10-06','12:01',1)");
    egmBenefitsApplyEntry($context,3,'01');
    $period=$pdo->query("SELECT *,is_uninvited_guest AS period_is_uninvited_guest FROM `{$tables['user_periods']}` WHERE user_id=3")->fetch(PDO::FETCH_ASSOC);
    botAssert((int)$period['should_get_gift']===0&&(int)$period['draw_eligible']===1,'Pending decision not applied to new walk-in');
    botAssert(egmBenefitsDrawAllowed($period),'Approved draw-only guest excluded');
    botAssert(!egmBenefitsDrawAllowed(['period_is_uninvited_guest'=>1]),'Unreviewed walk-in accepted');
    botAssert(!egmBenefitsDrawAllowed(['draw_eligible'=>0]),'Denied invited guest accepted');
    foreach(['both','gift','draw','deny'] as $choice) botAssert(count(egmBenefitsDecision($choice))===3,'Decision choice missing');
    botAssert((int)$pdo->query("SELECT should_get_gift FROM `{$tables['user_periods']}` WHERE user_id=1 AND period_code='02'")->fetchColumn()===0,'Other period changed');
    foreach(systemTelegramKeyboard($reportId)['inline_keyboard'] as $row)foreach($row as $button)botAssert(strlen($button['callback_data'])<=64,'Telegram callback too long');
    $window = ['tagCode'=>'01','prizeEntryWindowEnabled'=>true,'prizeEntryStartDate'=>'2026-10-06','prizeEntryStartTime'=>'08:00','prizeEntryEndDate'=>'2026-10-06','prizeEntryEndTime'=>'10:00'];
    $context['periods'] = [$window];
    $context['period_code'] = '01';
    ensureActivityLogTable($pdo, 'EGM', $code);
    $pdo->exec("ALTER TABLE `{$tables['activity_logs']}` AUTO_INCREMENT=100");
    egmCheckInWriteLog($context, ['id'=>1,'work_id'=>'12'], '12', 'success', 'ورود ثبت شد.', $window, new DateTimeImmutable('2026-10-06 12:00:00'));
    $lateLogId = (int)$pdo->query("SELECT MAX(id) FROM `{$tables['activity_logs']}`")->fetchColumn();
    $lateReport = systemTelegramCreateReport($context, $lateLogId, ['username'=>'admin']);
    $lateReportRow = $pdo->query("SELECT * FROM egm_telegram_reports WHERE log_id={$lateLogId}")->fetch(PDO::FETCH_ASSOC);
    botAssert(str_contains(systemTelegramReportText($lateReportRow), 'خارج از زمان مجاز'), 'Telegram report missing time exclusion reason');
    systemTelegramDecide($pdo, $lateReportRow['id'], 'both', '111', $context);
    botAssert(egmBenefitsEntryDrawState($context, 1, $window)['allowed'], 'Telegram approval does not override entry window');
    botAssert((int)$pdo->query("SELECT should_get_gift FROM `{$tables['user_periods']}` WHERE user_id=1 AND period_code='01'")->fetchColumn()===1, 'Time-window review lost gift approval');
    egmInstanceWritePeriods($pdo,$code,[array_replace($window,['title'=>'بازه فعلی','startDate'=>'2026-10-06']),['tagCode'=>'02','title'=>'بازه قبلی','startDate'=>'2026-10-05']]);
    systemTelegramExportChoose($pdo,'111','111',['kind'=>'periods','code'=>$code],$context);
    $periodMenu=end($calls)[1]['reply_markup']['inline_keyboard'];
    botAssert(count($periodMenu)===2 && str_contains($periodMenu[0][0]['text'],'فعال'), 'Export period chooser missing active or previous period');
    $callback=['id'=>'export-test','from'=>['id'=>111],'message'=>['chat'=>['id'=>111,'type'=>'private']],'data'=>$periodMenu[0][0]['callback_data']];
    systemTelegramExportCallback($pdo,$callback,$context);
    $typeMenu=end($calls)[1]['reply_markup']['inline_keyboard'];
    $fileButtons=array_merge(...array_slice($typeMenu,0,-1));
    botAssert(array_column($fileButtons,'text')===array_values(egmPeriodExportTypes()), 'Telegram export menu differs from panel');
    foreach ($fileButtons as $index=>$button) {
        botAssert(strlen($button['callback_data'])<=64,'Export callback too long');
        $callback['data']=$button['callback_data'];
        systemTelegramExportCallback($pdo,$callback,$context);
        $document=end($documents);
        $type=array_keys(egmPeriodExportTypes())[$index];
        $panel=egmPeriodExportBuild($context,'01',$type);
        botAssert($document['content']===$panel['content'] && $document['filename']===$panel['filename'], 'Telegram workbook differs from panel: '.$type);
        botAssert(str_starts_with($document['content'],'PK') && str_ends_with($document['filename'],'.xlsx'), 'Export is not genuine XLSX');
        botAssert(!is_file($document['path']), 'Temporary workbook not deleted after upload');
    }
    $before=count($documents);
    $callback['from']['id']=222;
    systemTelegramExportCallback($pdo,$callback,$context);
    botAssert(count($documents)===$before && !empty(end($calls)[1]['show_alert']), 'Another user could download a manager export');
    $callback['from']['id']=111;$callback['message']['chat']['id']=222;
    systemTelegramExportCallback($pdo,$callback,$context);
    botAssert(count($documents)===$before, 'Export sent to another chat');
    $callback['message']['chat']['id']=111;
    $expiredId=substr($callback['data'],5);
    $pdo->prepare('UPDATE egm_telegram_export_menus SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$expiredId]);
    systemTelegramExportCallback($pdo,$callback,$context);
    botAssert(count($documents)===$before && str_contains(end($calls)[1]['text'],'منقضی'), 'Expired menu remained downloadable');
    $forged=systemTelegramExportButton($pdo,'111','111','forged',['kind'=>'file','code'=>'55555','period'=>'01','type'=>'all_guests']);
    $callback['data']=$forged['callback_data'];
    systemTelegramExportCallback($pdo,$callback,$context);
    botAssert(count($documents)===$before, 'Manager exported another EGM');
    $fresh=systemTelegramExportButton($pdo,'111','111','fresh',['kind'=>'file','code'=>$code,'period'=>'01','type'=>'all_guests']);
    $callback['data']=$fresh['callback_data'];
    // Rotating the Windows PIN invalidates existing Telegram grants.
    egmInstanceWriteData($pdo,$code,'settings',['adminPasscode'=>['hash'=>password_hash('818181',PASSWORD_DEFAULT)]]);
    $third=str_repeat('c',32);$insert->execute([$third,$code,'01',3,1,'12',json_encode($snapshot)]);
    try {systemTelegramDecide($pdo,$third,'both','111',$context);throw new RuntimeException('Old PIN grant survived rotation');}catch(InvalidArgumentException $expected){}
    systemTelegramExportCallback($pdo,$callback,$context);
    botAssert(count($documents)===$before && str_contains(end($calls)[1]['text'],'دسترسی'), 'PIN rotation did not revoke Excel download');
    echo "Telegram authorization, reporting, decisions, eligibility, all panel Excel exports, download isolation and PIN rotation passed.\n";

} finally {unset($GLOBALS['systemTelegramTestTransport']);$pdo->exec("DROP DATABASE `{$database}`");}
