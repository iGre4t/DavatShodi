<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-manager.php';
function managerCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function managerReject(callable $work): void {try{$work();}catch(InvalidArgumentException $expected){return;}throw new RuntimeException('Invalid manager operation accepted');}
$pdo=new PDO('mysql:host=127.0.0.1;port='.(getenv('EGM_TEST_DB_PORT')?:'3306').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='egm_ref_manager_'.bin2hex(random_bytes(5));$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");$code='9876543299';$tables=ensureEgmInstanceTables($pdo,$code);$ctx=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
    ensureEgmRegistryTable($pdo);$pdo->prepare('INSERT INTO egm(code,name,directory) VALUES (?,?,?)')->execute([$code,'Manager test','mini apps/EGMs/Test']);
    $pdo->exec("CREATE TABLE users (code VARCHAR(64),username VARCHAR(64),fullname VARCHAR(64),phone VARCHAR(64),work_id VARCHAR(64),id_number VARCHAR(64),email VARCHAR(64),password_hash VARCHAR(255),permissions TEXT)");
    egmInstanceWritePeriods($pdo,$code,[['tagCode'=>'01','active'=>true],['tagCode'=>'02','active'=>false]]);
    egmInstanceWriteData($pdo,$code,EGM_FACILITATORS_KEY,[['id'=>'one','username'=>'ref1','fullname'=>'داور اول','event_role'=>'referee'],['id'=>'two','username'=>'ref2','fullname'=>'داور دوم','event_role'=>'referee']]);
    $gid='1234567890abcdef';$a='1111111111111111';$b='2222222222222222';$ra='aaaaaaaaaaaaaaaa';$rb='bbbbbbbbbbbbbbbb';
    egmGamesWrite($ctx,['games'=>[['id'=>$gid,'name'=>'Test','has_levels'=>true,'score_count'=>2,'gender_mode'=>'normal','auto_room_manager'=>true,'min_players'=>1,'max_players'=>5,'levels'=>[['id'=>$a,'name'=>'A','rooms'=>[['id'=>$ra,'name'=>'A1','gender'=>'both']]],['id'=>$b,'name'=>'B','rooms'=>[['id'=>$rb,'name'=>'B1','gender'=>'both']]]]]],'enabled'=>['01'=>[$gid]]]);
    $table=egmRefMonitorGameTable($pdo,$code,'01',$gid);
    $pdo->exec("INSERT INTO `{$tables['users']}`(id,work_id,first_name,gender,source_row) VALUES (1,'101','Event guest','male',1),(2,'102','Other guest','male',2)");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}`(user_id,period_code) VALUES(1,'02'),(2,'01')");
    $pdo->exec("CREATE TABLE organizational_event_users LIKE `{$tables['users']}`");
    $pdo->exec("INSERT INTO organizational_event_users(id,work_id,first_name,gender,source_row) VALUES(7,'107','OEU guest','male',7)");
    $base=['type'=>'team','name'=>'First','created_by'=>'facilitator:one','members'=>[['id'=>1,'name'=>'Event guest','gender'=>'male']],'scores'=>[],'score_attempts'=>[]];
    $pdo->prepare("INSERT INTO `$table`(user_id,payload) VALUES(NULL,?)")->execute([json_encode($base)]);$id=(int)$pdo->lastInsertId();
    $other=$base;$other['name']='Second';$other['created_by']='facilitator:two';$other['members']=[['id'=>2,'name'=>'Other guest','gender'=>'male']];
    $pdo->prepare("INSERT INTO `$table`(user_id,payload) VALUES(NULL,?)")->execute([json_encode($other)]);$otherId=(int)$pdo->lastInsertId();
    $actor=['code'=>'manager','event_role'=>'system_manager'];
    foreach(['referee'] as $role)managerReject(fn()=>egmRefManagerRequest($pdo,$code,['event_role'=>$role],['method'=>'games']));
    $head=['code'=>'head','event_role'=>'head_referee'];
    foreach(['settings','save_level','delete_level','search','details','members','disband','start','reopen','end','score'] as $method) {
        managerReject(fn()=>egmRefManagerRequest($pdo,$code,$head,['method'=>$method,'game_id'=>$gid,'period_code'=>'01']));
    }
    foreach([true,1,'false',null] as $enabledValue) managerReject(fn()=>egmRefManagerRequest($pdo,$code,$head,['method'=>'room','enabled'=>$enabledValue]));
    managerCheck(count(egmRefManagerRequest($pdo,$code,$head,['method'=>'games'])['games'])===1,'Head cannot read games');
    $headList=egmRefManagerRequest($pdo,$code,$head,['method'=>'teams','game_id'=>$gid,'period_code'=>'01']);
    managerCheck(count($headList['teams'])===2 && $headList['owners']===[],'Head all-team visibility incorrect');
    managerCheck(egmRefManagerRequest($pdo,$code,$head,['method'=>'team','game_id'=>$gid,'period_code'=>'01','team_id'=>$id])['team']['name']==='First','Head cannot read team status');
    $list=egmRefManagerRequest($pdo,$code,$actor,['method'=>'teams','game_id'=>$gid,'period_code'=>'01']);
    managerCheck(count($list['teams'])===2&&count($list['owners'])===2,'Manager did not see all owners and teams');
    managerReject(fn()=>egmRefManagerRequest($pdo,$code,$actor,['method'=>'teams','game_id'=>$gid,'period_code'=>'02']));
    $team=egmRefManagerTeam($ctx,'01',$gid,$id);
    $change=static function(string $method,array $fields=[])use($ctx,$gid,$id,&$team):array{
        return $team=egmRefManagerChangeTeam($ctx,'01',$gid,$id,['method'=>$method,'revision'=>$team['revision']]+$fields,'manager');
    };
    $change('members',['members'=>['e:1','o:7']]);
    managerCheck(count($team['members'])===2,'OEU/other-period member import failed');
    $member=$pdo->query("SELECT * FROM `{$tables['user_periods']}` WHERE user_id=".$team['members'][1]['id']." AND period_code='01'")->fetch(PDO::FETCH_ASSOC);
    managerCheck(empty($member['entered_date'])&&(int)$member['should_get_gift']===0,'Manager import fabricated attendance or gift approval');
    managerCheck(egmRefMonitorSearchInvitees($pdo,$code,'01','Event')===[],'Unentered manager member appeared in ordinary referee search');
    $change('start');managerCheck($team['room_assignment']['room_id']===$ra,'Manager could not start imported team');
    $change('score',['level_id'=>$a,'index'=>0,'score'=>'10','expected_score'=>null]);
    managerCheck(empty($team['scores'])&&$team['room_assignment']['room_id']===$ra,'Partial score released room');
    $stale=$team['revision'];$change('score',['level_id'=>$a,'index'=>0,'score'=>'15','expected_score'=>10]);
    managerReject(fn()=>egmRefManagerChangeTeam($ctx,'01',$gid,$id,['method'=>'score','revision'=>$stale,'level_id'=>$a,'index'=>0,'score'=>'99','expected_score'=>10],'manager'));
    $change('score',['level_id'=>$a,'index'=>1,'score'=>'20','expected_score'=>null]);
    managerCheck((float)$team['scores'][$a]['score']===35.0&&$team['room_assignment']['level_id']===$b,'Final score did not aggregate/move room');
    $result=egmRefManagerRequest($pdo,$code,$head,['method'=>'room','game_id'=>$gid,'level_id'=>$b,'room_id'=>$rb,'enabled'=>false]);managerCheck($result['requires_confirmation'],'Occupied room did not require confirmation');
    egmRefManagerRequest($pdo,$code,$head,['method'=>'room','game_id'=>$gid,'level_id'=>$b,'room_id'=>$rb,'enabled'=>false,'confirmed'=>true]);
    $team=egmRefManagerTeam($ctx,'01',$gid,$id);managerCheck($team['waiting_for_room'],'Disabled room did not move team to wait');
    egmGamesSetRoomEnabled($ctx,$gid,$b,$rb,true,false,'manager');$team=egmRefManagerTeam($ctx,'01',$gid,$id);
    $change('score',['level_id'=>$b,'index'=>0,'score'=>'5','expected_score'=>null]);$change('score',['level_id'=>$b,'index'=>1,'score'=>'7','expected_score'=>null]);
    managerCheck($team['ended_at']!==null&&(float)$team['total_score']===47.0,'Completed team total incorrect');
    $change('score',['level_id'=>$a,'index'=>1,'score'=>'25','expected_score'=>20]);
    managerCheck((float)$team['total_score']===52.0,'Post-completion score correction failed');
    $change('members',['members'=>['o:7']]);managerCheck(count($team['members'])===1,'Post-completion member edit failed');
    $change('details',['name'=>'Renamed','owner'=>'facilitator:two','cover_color'=>'']);
    managerCheck($team['created_by']==='facilitator:two'&&$team['name']==='Renamed','Owner/name transfer failed');
    managerReject(fn()=>egmRefMonitorAssertTeamOwner(['created_by'=>$team['created_by']],'facilitator:one'));
    $change('disband',['confirmed'=>true]);
    managerCheck(count(egmRefMonitorListTeams($pdo,$code,'01',$gid))===1,'Disbanded team remained visible');
    $saved=json_decode($pdo->query("SELECT payload FROM `$table` WHERE id=$id")->fetchColumn(),true);
    managerCheck($saved['type']==='disbanded_team'&&count($saved['manager_changes'])>=10,'Disband deleted history or audit missing');
    managerCheck(!isset(egmRefMonitorOccupiedMemberIds($pdo,$table)[$member['user_id']]),'Disbanded members remained reserved');
    $game=egmGamesState($ctx)['games'][0];
    $input=$game;$input['revision']=egmRefManagerRevision($game);$input['name']='New name';$input['max_players']=0;
    managerReject(fn()=>egmRefManagerSaveSettings($ctx,$gid,$input));
    managerCheck(egmGamesState($ctx)['games'][0]===$game,'Failed settings save partially committed');
    $input['max_players']=8;egmRefManagerSaveSettings($ctx,$gid,$input);
    managerCheck(egmGamesState($ctx)['games'][0]['name']==='New name','Batch settings save failed');
    echo "PASS: manager-only access, all-team visibility, period isolation, OEU invitations without fake attendance, room retention/evacuation, score corrections, ownership transfer, unrestricted-time member edits, audited disband and atomic settings.\n";
} finally {$pdo->exec("DROP DATABASE `$database`");}
