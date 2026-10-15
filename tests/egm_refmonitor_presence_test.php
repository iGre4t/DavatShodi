<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='egm_presence_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");$code='9876543213';$tables=ensureEgmInstanceTables($pdo,$code);
    for($id=1;$id<=8;$id++) $pdo->exec("INSERT INTO `{$tables['users']}` (`work_id`,`national_id`,`first_name`,`is_active`) VALUES ('100$id','000000000$id','Guest$id'," . ($id===8?'0':'1') . ')');
    $insert=$pdo->prepare("INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`entered_date`,`entered_time`,`quit_date`,`quit_time`,`is_uninvited_guest`,`invitation_source`) VALUES (?,?,?,?,?,?,?,?)");
    $insert->execute([1,'01','2026-10-09','08:00',null,null,0,'custom']);
    $insert->execute([2,'01',null,null,null,null,0,'custom']);
    $insert->execute([3,'01','2026-10-09','08:00','2026-10-09','09:00',0,'custom']);
    $insert->execute([4,'02','2026-10-09','08:00',null,null,0,'custom']);
    $insert->execute([5,'01','2026-10-09','08:00',null,'09:00',0,'custom']);
    $insert->execute([6,'01','2026-10-09','08:00',null,null,1,'walk_in']);
    $insert->execute([7,'01','2026-10-09',null,null,null,0,'custom']);
    $insert->execute([8,'01','2026-10-09','08:00',null,null,0,'custom']);
    $search=egmRefMonitorSearchInvitees($pdo,$code,'01','Guest');
    if(array_column($search,'id')!==[1,6]) throw new Exception('Search exposed someone who is not currently inside this period');
    $pdo->exec("UPDATE `{$tables['users']}` SET first_name='محسن',last_name='مرادي  مقدم' WHERE id=1");
    $pdo->exec("UPDATE `{$tables['users']}` SET first_name='محبوبه',last_name='جراحي' WHERE id=6");
    foreach (['محسن مرادی مقدم', 'محسن مرادي مقدم', 'محسن‌مرادی مقدم', '۱۰۰۱'] as $query) {
        if(array_column(egmRefMonitorSearchInvitees($pdo,$code,'01',$query),'id')!==[1]) throw new Exception('Name or work ID normalization failed');
    }
    if(array_column(egmRefMonitorSearchInvitees($pdo,$code,'01','محبوبه جراحی'),'id')!==[6]) throw new Exception('Arabic yeh name failed');
    egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','active'=>true]]);
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
    $gameId='1234567890abcdef';
    egmGamesWrite($context,['games'=>[['id'=>$gameId,'name'=>'Test','has_levels'=>false]],'enabled'=>['01'=>[$gameId]]]);
    $gameTable=egmRefMonitorGameTable($pdo,$code,'01',$gameId);
    $pdo->prepare("INSERT INTO `$gameTable` (`user_id`,`payload`) VALUES (NULL,?)")->execute([json_encode(['type'=>'team','members'=>[['id'=>1]],'created_by'=>'other-referee'])]);
    $found=egmRefMonitorSearchInvitees($pdo,$code,'01','1001',$gameId);
    if(count($found)!==1 || !$found[0]['already_in_team']) throw new Exception('Existing membership must be visible with a reason');
    try {egmRefMonitorAssertMembersAvailable($pdo,$gameTable,$found);throw new Exception('Duplicate membership accepted');}
    catch(InvalidArgumentException $expected) {}
    if(count(egmRefMonitorResolveMembers($pdo,$code,'01',[1,6],20))!==2) throw new Exception('Entered invited guests and walk-ins should be allowed');
    foreach([2,3,4,5,7,8] as $id) {
        try {egmRefMonitorResolveMembers($pdo,$code,'01',[$id],20);throw new Exception('Ineligible member accepted');}
        catch(InvalidArgumentException $error) { /* Expected server-side rejection. */ }
    }
    $pdo->exec("UPDATE `{$tables['user_periods']}` SET quit_date='2026-10-09',quit_time='10:00' WHERE user_id=1 AND period_code='01'");
    try {egmRefMonitorResolveMembers($pdo,$code,'01',[1],20);throw new Exception('Guest who exited after searching was accepted');}
    catch(InvalidArgumentException $error) { /* Attendance is rechecked at submission. */ }
    echo "PASS: active-period presence, entered walk-ins, invited-only/other-period/exited/inactive exclusions, checkout after searching.\n";
} finally {$pdo->exec("DROP DATABASE `$database`");}
