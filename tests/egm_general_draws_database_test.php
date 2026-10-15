<?php
declare(strict_types=1);
require_once __DIR__ . '/egm_period_draws_test.php';
require_once dirname(__DIR__) . '/api/lib/egm-general-draws.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'egm_general_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");
    $code = '9876543212'; $tables = ensureEgmInstanceTables($pdo, $code);
    $context = ['pdo' => $pdo, 'code' => $code, 'tables' => $tables];
    $pdo->exec("INSERT INTO `{$tables['users']}` (`work_id`,`first_name`,`guest_number`) VALUES ('12345','First','1'),('98765','Second','2'),('77777','Waiting','3')");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`entered_date`,`entered_time`,`is_uninvited_guest`,`invitation_source`) VALUES (1,'01','2026-10-09','10:00',0,'custom'),(2,'02','2026-10-09','10:00',0,'custom'),(3,'01',NULL,NULL,0,'custom')");
    $periods = [['tagCode'=>'01','active'=>true],['tagCode'=>'02','active'=>false]];
    egmInstanceWritePeriods($pdo,$code,$periods);
    $settings = ['name'=>'Permanent','winnerLimit'=>2,'prizeName'=>'Gift','includeEntered'=>true,'includeWalkIns'=>false];
    $id = egmGeneralDrawRun($context,'create',$settings,'admin')['draws'][0]['id'];
    $state = egmGeneralDrawRun($context,'state',['id'=>$id],'admin');
    checkDraw($state['periodCode']==='01' && $state['eligibleCount']===1,'Only entered guests of active period');
    $candidate=egmGeneralDrawRun($context,'roll',['id'=>$id,'period_code'=>'01'],'admin')['participant']['key'];
    egmGeneralDrawRun($context,'confirm',['id'=>$id,'period_code'=>'01','candidateKey'=>$candidate],'admin');
    $periods[0]['active']=false;$periods[1]['active']=true;egmInstanceWritePeriods($pdo,$code,$periods);
    rejectsDraw(fn()=>egmGeneralDrawRun($context,'confirm',['id'=>$id,'period_code'=>'01','candidateKey'=>$candidate],'admin'));
    $state=egmGeneralDrawRun($context,'state',['id'=>$id],'admin');
    checkDraw($state['periodCode']==='02' && !$state['winners'] && $state['eligibleParticipants'][0]['key']==='2','Same draw follows new period with separate winners');
    checkDraw(count(egmPeriodDrawRun($context,'01','state',['id'=>'general-'.$id],'admin')['winners'])===1,'Earlier winners remain in period history');
    egmGeneralDrawRun($context,'save',$settings+['id'=>$id],'admin');
    checkDraw(egmGeneralDrawRun($context,'list',[],'admin')['draws'][0]['id']===$id,'Editing never changes permanent link ID');
    $periods[1]['active']=false;egmInstanceWritePeriods($pdo,$code,$periods);
    rejectsDraw(fn()=>egmGeneralDrawRun($context,'roll',['id'=>$id],'admin'));
    echo "PASS: permanent draw IDs, active-period roster, isolated winner history, stale confirmation and inactive-period rejection.\n";
} finally { $pdo->exec("DROP DATABASE `$database`"); }
