<?php
declare(strict_types=1);
require_once __DIR__ . '/egm_period_draws_test.php';
// Uses an isolated local database; never reads application database credentials.
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = 'egm_draw_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");
    $code='9876543210';
    $tables=ensureEgmInstanceTables($pdo,$code);
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
    $pdo->exec("INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`guest_number`) VALUES ('12345','Invited','1'),('98765','Walk-in','2'),('77777','Other period','3'),('88888','Waiting','4')");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`entered_date`,`entered_time`,`is_uninvited_guest`,`invitation_source`) VALUES (1,'01','2026-09-29','10:00',0,'custom'),(2,'01','2026-09-29','10:01',1,'walk_in'),(3,'02','2026-09-29','10:02',0,'custom'),(4,'01',NULL,NULL,0,'custom')");
    $settings=['name'=>'Draw','winnerLimit'=>1,'prizeName'=>'Gift','includeEntered'=>true,'includeWalkIns'=>false];
    $id=egmPeriodDrawRun($context,'01','create',$settings,'admin')['draw']['id'];
    checkDraw(count(egmPeriodDrawRun($context,'01','list',[],'admin')['draws'])===1,'Draw persisted');
    checkDraw(!egmPeriodDrawRun($context,'02','list',[],'admin')['draws'],'Other period isolated');
    $state=egmPeriodDrawRun($context,'01','state',['id'=>$id],'admin');
    checkDraw($state['eligibleCount']===1 && $state['eligibleParticipants'][0]['key']==='1','SQL scopes entered guests to period');
    egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','prizeEntryWindowEnabled'=>true,'prizeEntryStartDate'=>'2026-09-29','prizeEntryStartTime'=>'10:01','prizeEntryEndDate'=>'2026-09-29','prizeEntryEndTime'=>'10:02']]);
    checkDraw(egmPeriodDrawRun($context,'01','state',['id'=>$id],'admin')['eligibleCount']===0,'Database draw applies the period prize entry window');
    egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','prizeEntryWindowEnabled'=>false]]);
    egmPeriodDrawRun($context,'01','roll',['id'=>$id],'admin');
    $state=egmPeriodDrawRun($context,'01','confirm',['id'=>$id,'candidateKey'=>'1'],'admin');
    checkDraw(count($state['winners'])===1,'Confirmed winner persisted');
    rejectsDraw(fn()=>egmPeriodDrawRun($context,'01','roll',['id'=>$id],'admin'));
    checkDraw(count(egmPeriodDrawRun($context,'01','export',['id'=>$id],'admin')['winners'])===1,'Export reads stored winners');
    egmPeriodDrawRun($context,'01','reset',['id'=>$id],'admin');
    egmPeriodDrawRun($context,'01','save',array_replace($settings,['id'=>$id,'includeEntered'=>false,'includeWalkIns'=>true]),'admin');
    checkDraw(egmPeriodDrawRun($context,'01','roll',['id'=>$id],'admin')['participant']['key']==='2','Walk-in only filter reads DB');
    $pdo->exec("UPDATE `{$tables['user_periods']}` SET entered_date=NULL,entered_time=NULL WHERE user_id=2");
    rejectsDraw(fn()=>egmPeriodDrawRun($context,'01','confirm',['id'=>$id,'candidateKey'=>'2'],'admin'));
    checkDraw(!egmPeriodDrawRun($context,'01','state',['id'=>$id],'admin')['winners'],'Failed confirmation does not persist a winner');
    $otherContext=['pdo'=>$pdo,'code'=>'9876543211','tables'=>ensureEgmInstanceTables($pdo,'9876543211')];
    checkDraw(!egmPeriodDrawRun($otherContext,'01','list',[],'admin')['draws'],'EGM instances isolated');
    echo "EGM period draw database tests passed.\n";
} finally {
    $pdo->exec("DROP DATABASE `$database`");
}
