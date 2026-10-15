<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-game-monitor.php';
function monitorCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function monitorReject(callable $work): void { try { $work(); } catch (InvalidArgumentException $expected) { return; } throw new RuntimeException('Invalid change accepted'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database = 'egm_monitor_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `{$database}`");
    $code='9876543294'; $tables=ensureEgmInstanceTables($pdo,$code);
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
    egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','active'=>true]]);
    $gameId='1234567890abcdef'; $disabledId='abcdef1234567890'; $roomId='aaaaaaaaaaaaaaaa';
    $game=['id'=>$gameId,'name'=>'Game','gender_mode'=>'separated','has_levels'=>false,'auto_room_manager'=>true,'min_players'=>1,'max_players'=>3,'rooms'=>[['id'=>$roomId,'name'=>'Room','gender'=>'both']]];
    egmGamesWrite($context,['games'=>[$game,['id'=>$disabledId,'name'=>'Disabled']],'enabled'=>['01'=>[$gameId]]]);
    for ($id=1;$id<=5;$id++) {
        $gender=$id===4 ? 'female' : 'male';
        $pdo->prepare("INSERT INTO `{$tables['users']}` (`id`,`work_id`,`first_name`,`gender`,`is_active`) VALUES (?,?,?, ?,1)")->execute([$id,'100'.$id,'Guest'.$id,$gender]);
        $pdo->prepare("INSERT INTO `{$tables['user_periods']}` (`user_id`,`period_code`,`entered_date`,`entered_time`) VALUES (?,'01','2026-10-11','08:00')")->execute([$id]);
    }
    $table=egmRefMonitorGameTable($pdo,$code,'01',$gameId);
    $score=['score'=>10,'submitted_at'=>'original','submitted_by'=>'ref'];
    $payload=['type'=>'team','name'=>'Scored team','members'=>[['id'=>1,'gender'=>'male','name'=>'Guest1']],'scores'=>['game_total'=>$score],'started_at'=>'2026-01-01T00:00:00Z','room_assignment'=>['room_id'=>$roomId,'level_id'=>'game_total','room_name'=>'Room','assigned_at'=>'2026-10-11T08:00:00+03:30']];
    $pdo->prepare("INSERT INTO `{$table}` (`user_id`,`payload`) VALUES(NULL,?)")->execute([json_encode($payload)]); $teamId=(int)$pdo->lastInsertId();
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,2]));
    $team=egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,2],'admin',[1]);
    monitorCheck(count($team['members'])===2 && $team['scores']===$payload['scores'] && $team['room_assignment']===$payload['room_assignment'],'Admin update lost score or room state');
    $persisted=egmRefMonitorReadTeam($pdo,$table,$teamId)['payload'];
    monitorCheck($persisted['member_corrections'][0]['by']==='admin' && $persisted['member_corrections'][0]['before']===[1],'Membership audit missing');
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,3],'admin',[1]));
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[],'admin',[1,2]));
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,4],'admin',[1,2]));
    $other=['type'=>'team','name'=>'Other','members'=>[['id'=>3,'gender'=>'male']],'scores'=>[]];
    $pdo->prepare("INSERT INTO `{$table}` (`user_id`,`payload`) VALUES(NULL,?)")->execute([json_encode($other)]);
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,3],'admin',[1,2]));
    $pdo->exec("UPDATE `{$tables['user_periods']}` SET quit_time='09:00' WHERE user_id=5");
    monitorReject(fn()=>egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[1,5],'admin',[1,2]));
    $snapshot=egmGameMonitorSnapshot($context,'01');
    monitorCheck(count($snapshot['games'])===2 && $snapshot['games'][1]['teams']===[],'Disabled game lost or acquired teams');
    $found=array_values(array_filter($snapshot['games'][0]['teams'],fn($team)=>$team['id']===$teamId))[0];
    monitorCheck($found['gender']==='male' && $found['room_assignment']['assigned_at']===$payload['room_assignment']['assigned_at'],'Monitoring gender or room time missing');
    monitorCheck(egmGameMonitorSnapshot($context,'01')['revision']===$snapshot['revision'],'Unchanged snapshot revision changed');
    egmGamesCorrectTeamScores($context,'01',$gameId,$teamId,[['level_id'=>'game_total','index'=>0,'score'=>25,'expected_score'=>10]],'admin');
    monitorCheck(egmGameMonitorSnapshot($context,'01')['revision']!==$snapshot['revision'],'Score change did not change snapshot revision');
    egmRefMonitorUpdateMembers($pdo,$code,'01',$gameId,$teamId,[2],'admin',[1,2]);
    monitorCheck(count(egmRefMonitorReadTeam($pdo,$table,$teamId)['payload']['members'])===1,'Member removal failed');
    echo "PASS: monitoring snapshots/revisions and audited admin membership corrections; referee limits, conflicts, attendance and gender guards preserved\n";
} finally { $pdo->exec("DROP DATABASE `{$database}`"); }
