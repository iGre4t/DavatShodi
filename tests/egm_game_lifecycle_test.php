<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-game-lifecycle.php';
function lifeCheck(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$pdo=new PDO('mysql:host=127.0.0.1;port=3312;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='egm_lifecycle_'.bin2hex(random_bytes(5));$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
try{
$pdo->exec("USE `$db`");$code='9876543288';$context=['pdo'=>$pdo,'code'=>$code,'tables'=>ensureEgmInstanceTables($pdo,$code)];
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));$game='1234567890abcdef';$room='aaaaaaaaaaaaaaaa';
$periods=[['tagCode'=>'01','ended'=>true,'endedAt'=>$now->modify('-2 hours')->format(DATE_ATOM)],['tagCode'=>'02','active'=>true,'duration'=>false],['tagCode'=>'03','ended'=>true,'endedAt'=>$now->modify('-30 minutes')->format(DATE_ATOM)]];
egmInstanceWritePeriods($pdo,$code,$periods);
egmGamesWrite($context,['games'=>[['id'=>$game,'name'=>'Test','has_levels'=>false,'auto_room_manager'=>true,'rooms'=>[['id'=>$room,'name'=>'Room','gender'=>'both']]]],'enabled'=>['01'=>[$game],'02'=>[$game],'03'=>[$game]]]);
$tables=[];$team=['type'=>'team','name'=>'Team','members'=>[['id'=>1,'gender'=>'male'],['id'=>2,'gender'=>'female']],'started_at'=>$now->modify('-3 hours')->format(DATE_ATOM),'scores'=>['game_total'=>['score'=>10]],'score_attempts'=>['game_total'=>[['score'=>10]]],'room_assignment'=>['room_id'=>$room],'queue_since'=>'old'];
foreach(['01','02','03']as $period){$tables[$period]=egmGamesEnsureTable($context,$period,$game);}
foreach(['01','03']as $period){$pdo->prepare("INSERT INTO `{$tables[$period]}`(user_id,payload)VALUES(NULL,?)")->execute([json_encode($team)]);}
$state=egmGamesState($context);
lifeCheck(!egmGamesHasActiveTeams($context,$state,$game),'Ended periods blocked settings');
egmGamesAssertSeparatedTeams($context,$state,$game);
egmGamesSaveGenderMode($context,$game,'separated');
$changes=egmGamesCleanupExpiredPeriods($context,false,$now);lifeCheck(count($changes)===1,'Grace period ignored');
$read=static fn($period)=>json_decode($pdo->query("SELECT payload FROM `{$tables[$period]}`")->fetchColumn(),true);
$old=$read('01');lifeCheck(!empty($old['ended_at'])&&$old['room_assignment']===null&&$old['queue_since']==='','Expired room not freed');lifeCheck($old['scores']===$team['scores']&&$old['members']===$team['members'],'Cleanup changed scores or members');
lifeCheck(empty($read('03')['ended_at']),'Team closed before one hour');
lifeCheck(egmGamesCleanupExpiredPeriods($context,false,$now)===[],'Cleanup not idempotent');
$current=$team;$current['members']=[['id'=>1,'gender'=>'male']];
$pdo->prepare("INSERT INTO `{$tables['02']}`(user_id,payload)VALUES(NULL,?)")->execute([json_encode($current)]);
lifeCheck(egmGamesHasActiveTeams($context,egmGamesState($context),$game),'Live team not protected');
egmGamesCleanupExpiredPeriods($context,true,$now);
lifeCheck(!empty($read('03')['ended_at']),'Forced expired-period cleanup failed');lifeCheck($read('02')===$current,'Forced cleanup touched current-period team');
echo "PASS: ended periods excluded, one-hour grace, safe room release, preserved scores/members, idempotency, live-team protection and forced historical cleanup.\n";
}finally{$pdo->exec("DROP DATABASE `$db`");}
