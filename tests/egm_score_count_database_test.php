<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';
function scoreCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function scoreReject(callable $work):void{try{$work();}catch(InvalidArgumentException $expected){return;}throw new RuntimeException('Invalid score operation accepted');}
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='egm_score_count_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try{
 $pdo->exec("USE `$database`");$code='9876543214';$tables=ensureEgmInstanceTables($pdo,$code);$context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
 egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','active'=>true]]);
 $gameId='1234567890abcdef';$roomId='abcdef1234567890';
 $game=['id'=>$gameId,'name'=>'Test','has_levels'=>false,'auto_room_manager'=>true,'gender_mode'=>'normal','rooms'=>[['id'=>$roomId,'name'=>'Room','gender'=>'both']],'levels'=>[],'min_players'=>1,'max_players'=>20];
 egmGamesWrite($context,['games'=>[$game],'enabled'=>['01'=>[$gameId]]]);
 scoreCheck(egmGamesState($context)['games'][0]['score_count']===1,'Existing games default to one score');
 egmGamesSaveLimits($context,$gameId,1,20,2);
 scoreCheck(egmGamesState($context)['games'][0]['score_count']===2,'Score count persisted');
 $table=egmRefMonitorGameTable($pdo,$code,'01',$gameId);
 $insert=$pdo->prepare("INSERT INTO `$table` (`user_id`,`payload`) VALUES (NULL,?)");
 $assignment=['level_id'=>'game_total','room_id'=>$roomId,'room_name'=>'Room','level_name'=>'Game'];
 $base=['type'=>'team','name'=>'First','started_at'=>date('c'),'created_by'=>'ref','members'=>[],'scores'=>[]];
 $insert->execute([json_encode($base+['room_assignment'=>$assignment])]);$first=(int)$pdo->lastInsertId();
 $insert->execute([json_encode(array_replace($base,['name'=>'Waiting','queue_since'=>date('c')]))]);$waiting=(int)$pdo->lastInsertId();
 egmGamesSaveLimits($context,$gameId,1,20,3);
 scoreCheck(egmGamesState($context)['games'][0]['score_count']===3,'Default can change while teams are active');
 scoreCheck(egmRefMonitorReadTeam($pdo,$table,$first)['payload']['score_count']===2,'Existing started team retains its score count');
 $team=egmRefMonitorSubmitScore($pdo,$code,'01',$gameId,$first,'game_total','10.25','ref',false,1);
 scoreCheck(!$team['scores'] && !$team['ended_at'] && $team['room_assignment']===$assignment,'First score must not complete level or release room');
 scoreCheck(count($team['score_attempts']['game_total'])===1 && !$team['can_edit_members'],'Partial score persisted and locks member editing');
 scoreCheck($team['score_submission']['completed']===false,'Partial result is reported to UI');
 scoreReject(fn()=>egmRefMonitorSubmitScore($pdo,$code,'01',$gameId,$first,'game_total','10.25','ref',false,1));
 scoreCheck(empty(egmRefMonitorReadTeam($pdo,$table,$waiting)['payload']['room_assignment']),'Waiting team cannot take occupied room');
 $team=egmRefMonitorSubmitScore($pdo,$code,'01',$gameId,$first,'game_total','14.50','ref',false,2);
 scoreCheck($team['scores']['game_total']['score']===24.75 && count($team['scores']['game_total']['attempts'])===2,'Final level score is exact sum with immutable attempts');
 scoreCheck($team['ended_at']!==null && $team['room_assignment']===null,'Last required score ends team and frees room');
 scoreCheck(egmRefMonitorReadTeam($pdo,$table,$waiting)['payload']['room_assignment']['room_id']===$roomId,'Room passes to waiting team immediately');
 scoreReject(fn()=>egmRefMonitorSubmitScore($pdo,$code,'01',$gameId,$first,'game_total','99','ref',false,3));
 // Manual rooms and more than two scores must use the same aggregation rule.
 $manualId='1111111111111111';$levelId='2222222222222222';$nextId='3333333333333333';
 $manual=['id'=>$manualId,'name'=>'Manual','has_levels'=>true,'score_count'=>3,'levels'=>[['id'=>$levelId,'name'=>'One','rooms'=>[]],['id'=>$nextId,'name'=>'Two','rooms'=>[]]]];
 $state=egmGamesState($context);$state['games'][]=$manual;$state['enabled']['01'][]=$manualId;egmGamesWrite($context,$state);
 $manualTable=egmRefMonitorGameTable($pdo,$code,'01',$manualId);
 $pdo->prepare("INSERT INTO `$manualTable` (`user_id`,`payload`) VALUES (NULL,?)")->execute([json_encode($base)]);$manualTeam=(int)$pdo->lastInsertId();
 for($i=1;$i<=3;$i++)$result=egmRefMonitorSubmitScore($pdo,$code,'01',$manualId,$manualTeam,$levelId,(string)$i,'ref',false,$i);
 scoreCheck($result['scores'][$levelId]['score']===6.0 && !$result['ended_at'],'Three scores complete only their level in manual mode');
 scoreReject(fn()=>egmGamesSaveLimits($context,$manualId,1,20,0));
 $singleId='4444444444444444';$state=egmGamesState($context);$state['games'][]=['id'=>$singleId,'name'=>'Legacy','has_levels'=>false];$state['enabled']['01'][]=$singleId;egmGamesWrite($context,$state);
 $singleTable=egmRefMonitorGameTable($pdo,$code,'01',$singleId);$pdo->prepare("INSERT INTO `$singleTable` (`user_id`,`payload`) VALUES (NULL,?)")->execute([json_encode($base)]);$singleTeam=(int)$pdo->lastInsertId();
 $result=egmRefMonitorSubmitScore($pdo,$code,'01',$singleId,$singleTeam,'game_total','7','ref');
 scoreCheck($result['ended_at']!==null && $result['scores']['game_total']['score']===7.0,'Default one-score flow remains unchanged');
 echo "PASS: score-count defaults/settings, immutable partial scores, duplicate retry rejection, room retention/reassignment, summed final scores and manual multi-level games.\n";
}finally{$pdo->exec("DROP DATABASE `$database`");}
