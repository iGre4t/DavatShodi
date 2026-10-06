<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-check-in.php';
require_once dirname(__DIR__).'/api/lib/egm-games.php';
require_once dirname(__DIR__).'/api/lib/egm-refmonitor-push.php';
function periodResetCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
class GameResetSqlitePdo extends PDO {
    public array $locks=[];
    public function __construct(){parent::__construct('sqlite::memory:');$this->sqliteCreateFunction('GET_LOCK',function($name,$wait){$this->locks[$name]=true;return 1;});$this->sqliteCreateFunction('RELEASE_LOCK',function($name){unset($this->locks[$name]);return 1;});$this->sqliteCreateFunction('IS_FREE_LOCK',fn($name)=>isset($this->locks[$name])?0:1);}
    private function sql(string $sql):string{
        if(str_contains($sql,'`information_schema`.`tables`'))return 'SELECT COUNT(*) FROM sqlite_master WHERE name=:table';
        $sql=str_replace([' FOR UPDATE',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',' ENGINE=InnoDB',' ON UPDATE CURRENT_TIMESTAMP'],['','','','',''],$sql);
        $sql=preg_replace('/`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/','`id` INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
        $sql=str_replace('id BIGINT AUTO_INCREMENT PRIMARY KEY','id INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
        $sql=preg_replace('/,\s*(?:KEY|INDEX)\s+`?\w+`?\s*\([^)]*\)/','',$sql);
        $sql=str_replace('DATE_ADD(NOW(),INTERVAL 1 DAY)',"datetime('now','+1 day')",$sql);$sql=str_replace('NOW()','CURRENT_TIMESTAMP',$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `updated_at` = CURRENT_TIMESTAMP','ON CONFLICT(data_key) DO UPDATE SET payload=excluded.payload,updated_at=CURRENT_TIMESTAMP',$sql);
        if(str_starts_with($sql,'DELETE o FROM egm_ref_push_outbox'))return 'DELETE FROM egm_ref_push_outbox WHERE device_id IN(SELECT id FROM egm_ref_push_devices WHERE event_code=? AND period_code=?)';
        return $sql;
    }
    public function exec(string $sql):int|false{return parent::exec($this->sql($sql));}
    public function prepare(string $sql,array $options=[]):PDOStatement|false{return parent::prepare($this->sql($sql),$options);}
    public function query(string $sql,?int $mode=null,mixed ...$args):PDOStatement|false{return $mode===null?parent::query($this->sql($sql)):parent::query($this->sql($sql),$mode,...$args);}
}
$mysql=getenv('EGM_TEST_MYSQL_PORT');
$pdo=$mysql?new PDO('mysql:host=127.0.0.1;port='.$mysql.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]):new GameResetSqlitePdo();
$database='egm_reset_test_'.bin2hex(random_bytes(6));if($mysql)$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try{
    if($mysql)$pdo->exec("USE `{$database}`");$code='12345';$tables=egmInstanceTableNames($code);
    $cache=&egmInstanceEnsureCache();$cache[spl_object_id($pdo).':'.$code]=true;
    $pdo->exec("CREATE TABLE `{$tables['data']}`(data_key VARCHAR(128) PRIMARY KEY,payload LONGTEXT,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->prepare("INSERT INTO `{$tables['data']}`(data_key,payload) VALUES(?,?)")->execute([EGM_INSTANCE_SCHEMA_VERSION_KEY,'{}']);
    $pdo->exec("CREATE TABLE `{$tables['users']}`(id BIGINT PRIMARY KEY,work_id VARCHAR(128),source_row INT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE `{$tables['user_periods']}`(user_id BIGINT,period_code VARCHAR(128),entered_date DATE,entered_time TIME,quit_date DATE,quit_time TIME,correct_presence TINYINT DEFAULT 0,fake_presence TINYINT DEFAULT 0,should_get_gift TINYINT DEFAULT 0,attendance_state VARCHAR(32),last_control_condition VARCHAR(32),last_control_action VARCHAR(32),last_control_message TEXT,last_control_at DATETIME,number_of_ticket VARCHAR(64),ticket_number_recorded_at DATETIME,seat_assignment_json TEXT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE `{$tables['activity_logs']}`(id BIGINT AUTO_INCREMENT PRIMARY KEY,action VARCHAR(64),entity_id VARCHAR(128),occurred_at DATETIME) ENGINE=InnoDB");
    $context=['pdo'=>$pdo,'logs_pdo'=>$pdo,'code'=>$code,'tables'=>$tables,'periods'=>[['tagCode'=>'P1'],['tagCode'=>'P2']]];
    $games=[['id'=>str_repeat('a',16),'name'=>'Game A'],['id'=>str_repeat('b',16),'name'=>'Disabled game B']];
    egmGamesWrite($context,['games'=>$games,'enabled'=>['P1'=>[$games[0]['id']],'P2'=>[$games[0]['id']]]]);
    $first=egmGamesEnsureTable($context,'P1',$games[0]['id']);$disabled=egmGamesEnsureTable($context,'P1',$games[1]['id']);
    $other=egmGamesEnsureTable($context,'P2',$games[0]['id']);$otherEvent=egmGamesEnsureTable(['pdo'=>$pdo,'code'=>'67890'],'P1',$games[0]['id']);
    $add=static function(string $table,array $payload,?int $user=null)use($pdo):void{$pdo->prepare("INSERT INTO `{$table}`(user_id,payload) VALUES(?,?)")->execute([$user,json_encode($payload)]);};
    foreach([[],['started_at'=>'now','room_assignment'=>['room_id'=>'room1']],['ended_at'=>'now','scores'=>['level1'=>['score'=>20]]]]as $state)$add($first,['type'=>'team']+$state);
    $add($disabled,['type'=>'team']);$add($other,['type'=>'team']);$add($otherEvent,['type'=>'team']);
    $add($first,['type'=>'metadata']);$add($first,['type'=>'individual_score'],1);
    $pdo->exec("INSERT INTO `{$tables['users']}`(id,work_id,source_row) VALUES(1,'1',1)");
    $pdo->exec("INSERT INTO `{$tables['user_periods']}`(user_id,period_code,entered_date,attendance_state) VALUES(1,'P1','2026-10-07','entered'),(1,'P2','2026-10-07','entered')");
    $pdo->prepare("INSERT INTO `{$tables['activity_logs']}`(action,entity_id,occurred_at) VALUES(?,?,NOW()),(?,?,NOW())")->execute([EGM_CHECK_IN_ACTION,'P1',EGM_CHECK_IN_ACTION,'P2']);
    egmRefPushEnsure($pdo);
    foreach(['P1','P2']as $period){$id=hash('sha256',$period);$pdo->prepare('INSERT INTO egm_ref_push_devices(id,event_code,user_code,subscription,monitor_url,period_code,game_id,team_id,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$id,$code,'ref','{}','https://example.test/RefMonitor.php',$period,$games[0]['id'],1]);$pdo->prepare('INSERT INTO egm_ref_push_outbox(device_id,payload) VALUES(?,?)')->execute([$id,'{}']);}
    $result=egmCheckInResetAttendanceRecords($context,'P1');
    periodResetCheck($result['team_records']===4,'Ready/started/ended/disabled-game teams were not all removed');
    periodResetCheck((int)$pdo->query("SELECT COUNT(*) FROM `{$first}`")->fetchColumn()===2,'Non-team rows were removed');
    periodResetCheck((int)$pdo->query("SELECT COUNT(*) FROM `{$disabled}`")->fetchColumn()===0,'Disabled-game teams survived');
    foreach([$other,$otherEvent]as $table)periodResetCheck((int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()===1,'Reset crossed period/event boundaries');
    periodResetCheck($pdo->query("SELECT entered_date FROM `{$tables['user_periods']}` WHERE period_code='P1'")->fetchColumn()===null,'Attendance was not reset');
    periodResetCheck($pdo->query("SELECT entered_date FROM `{$tables['user_periods']}` WHERE period_code='P2'")->fetchColumn()==='2026-10-07','Other-period attendance changed');
    periodResetCheck((int)$pdo->query('SELECT COUNT(*) FROM egm_ref_push_outbox')->fetchColumn()===1,'Pending alerts were not scoped to the reset period');
    periodResetCheck((int)$pdo->query("SELECT team_id FROM egm_ref_push_devices WHERE id='".hash('sha256','P1')."'")->fetchColumn()===0,'Removed-team device watch survived');
    periodResetCheck(count(egmGamesState($context)['games'])===2,'Game settings were removed');
    // A failure after deleting the first game's teams must roll back both teams and attendance.
    $add($first,['type'=>'team']);$add($disabled,['type'=>'team']);
    $pdo->exec("UPDATE `{$tables['user_periods']}` SET entered_date='2026-10-07' WHERE period_code='P1'");
    $pdo->exec($mysql?"CREATE TRIGGER fail_reset BEFORE DELETE ON `{$disabled}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated reset failure'":"CREATE TRIGGER fail_reset BEFORE DELETE ON `{$disabled}` BEGIN SELECT RAISE(ABORT,'Isolated reset failure'); END");
    try{egmCheckInResetAttendanceRecords($context,'P1');throw new RuntimeException('Reset failure ignored');}catch(PDOException $expected){}
    periodResetCheck((int)$pdo->query("SELECT COUNT(*) FROM `{$first}`")->fetchColumn()===3,'Failed reset did not roll back teams');
    periodResetCheck($pdo->query("SELECT entered_date FROM `{$tables['user_periods']}` WHERE period_code='P1'")->fetchColumn()==='2026-10-07','Failed reset did not roll back attendance');
    foreach($games as $game){$lock=$pdo->prepare('SELECT IS_FREE_LOCK(?)');$lock->execute([egmGamesLockName($code,$game['id'])]);periodResetCheck((int)$lock->fetchColumn()===1,'Reset failure leaked a game lock');}
    echo "Period reset clears all team states and disabled games, attendance and queued alerts; preserves other periods/events/settings/non-team rows; rolls back failures and releases locks.\n";
}finally{if($mysql)$pdo->exec("DROP DATABASE `{$database}`");}
