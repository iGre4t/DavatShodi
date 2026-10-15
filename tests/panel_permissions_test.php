<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/users.php';
function permissionAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'permission_test_' . bin2hex(random_bytes(5));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
try {
 $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $GLOBALS['panelPermissionPdo'] = $pdo;
 $pdo->exec('CREATE TABLE egm (code VARCHAR(64), name VARCHAR(128), directory VARCHAR(512), sidebar_visible TINYINT)');
 $pdo->exec('CREATE TABLE tc (code VARCHAR(64), name VARCHAR(128), directory VARCHAR(512))');
 $pdo->exec("INSERT INTO egm VALUES ('1001','رویداد اول','mini apps/EGMs/first',1),('1002','رویداد مخفی','mini apps/EGMs/second',0)");
 $pdo->exec("INSERT INTO tc VALUES ('1001','باشگاه اول','mini apps/missions/club')");
 $pdo->exec('CREATE TABLE users (code VARCHAR(64), username VARCHAR(128), fullname VARCHAR(128), phone VARCHAR(32), email VARCHAR(128), id_number VARCHAR(32), work_id VARCHAR(32), password_hash VARCHAR(255), permissions LONGTEXT)');
 $catalog=getPanelPermissionTreeForFrontend();$ids=array_column($catalog,'id');
 permissionAssert(in_array('event-guest-manager-mission-1001',$ids,true)&&in_array('event-guest-manager-mission-1002',$ids,true),'Visible and hidden events are assignable');
 $children=getPanelChildTabIdsByParent();
 foreach(['games','groups','prizes','invite-card','print-card','custom-number-ticket','guest-reports','facilitators','scan'] as $suffix) {
  permissionAssert(in_array('event-guest-manager-mission-1001:'.$suffix,$children['event-guest-manager-mission-1001'],true),'Instance capability '.$suffix);
 }
 permissionAssert(normalizeTabPermissions([],true)===[]&&normalizeTabPermissions(['forged-id'],true)===[],'Explicit empty or forged permissions never become full access');
 permissionAssert(count(normalizeTabPermissions(null,true))>10,'Legacy unspecified administrator access preserved');
 $user=['permissions'=>['home','event-guest-manager-mission-1001','event-guest-manager-mission-1001:custom','event-guest-manager-mission-1001:games']];
 permissionAssert(resolveAllowedPanelTabsForUser($user)===['home','event-guest-manager-mission-1001'],'Scope shows only assigned event, no creator/global root');
 $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/mini apps/EGMs/first/games.php';
 permissionAssert(userHasPermissionId($user,'event-guest-manager')&&userHasPermissionId($user,'event-guest-manager:games'),'Requested instance resolves to scoped grant');
 permissionAssert(!userHasPermissionId($user,'event-guest-manager:main')&&!userHasPermissionId($user,'event-guest-manager:prizes'),'Sibling capabilities denied');
 permissionAssert(resolveAllowedPanelChildTabsForUser($user,'event-guest-manager')===['event-guest-manager:games'],'UI child scope matches server');
 $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/mini apps/EGMs/second/games.php';
 permissionAssert(!userHasPermissionId($user,'event-guest-manager'),'Other event denied');
 $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/mini apps/Event Guest Manager/games.php';
 permissionAssert(!userHasPermissionId($user,'event-guest-manager'),'Scoped account cannot reach development template');
 $global=['permissions'=>['event-guest-manager','event-guest-manager:custom','event-guest-manager:games']];
 $_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/mini apps/EGMs/second/games.php';
 permissionAssert(userHasPermissionId($global,'event-guest-manager:games')&&!userHasPermissionId($global,'event-guest-manager:prizes'),'Global grant inherits only selected capabilities');
 $legacy=['event-guest-manager'];foreach(['main','invitees','manage-tasks','task-access','monitoring','export','linker','logs'] as $key)$legacy[]='event-guest-manager:'.$key;
 permissionAssert(userHasPermissionId(['permissions'=>$legacy],'event-guest-manager:games'),'Old complete EGM administrators retain new sections');
 $pdo->exec("INSERT INTO egm VALUES ('1003','رویداد جدید','mini apps/EGMs/new',1)");unset($GLOBALS['panelPermissionInstanceCatalog']);
 permissionAssert(in_array('event-guest-manager-mission-1003',getAllPanelTabIds(),true),'New registry record appears without a hardcoded permission edit');
 permissionAssert(insertUserRecord($pdo,['code'=>'basic','username'=>'basic','name'=>'کاربر عادی','phone'=>'09000000000']),'New user created');
 $saved=$pdo->query("SELECT permissions FROM users WHERE code='basic'")->fetchColumn();
 permissionAssert(json_decode($saved,true)===['home','settings'],'Creation defaults to Home and Account Settings only');
 session_start();$_SESSION=['authenticated'=>true,'user'=>['code'=>'basic','permissions'=>['event-guest-manager']]];
 $fresh=requireAuthenticatedSessionUser(true);
 permissionAssert($fresh['permissions']===['home','settings']&&!userHasPermissionId($fresh,'event-guest-manager'),'Stale session privileges removed immediately');
 $pdo->exec("UPDATE users SET permissions='[]' WHERE code='basic'");
 permissionAssert(requireAuthenticatedSessionUser(true)['permissions']===[],'Full revocation takes effect without logout');
 // Execute the real endpoint in a separate process: a stale all-access session must not bypass revoked games permission.
 $limited = ['home','event-guest-manager-mission-1001','event-guest-manager-mission-1001:custom','event-guest-manager-mission-1001:invitees'];
 $statement=$pdo->prepare("UPDATE users SET permissions=? WHERE code='basic'");$statement->execute([json_encode($limited)]);
 $code = '$GLOBALS["panelPermissionPdo"]=new PDO('.var_export("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4",true).',"root","");'
  . 'session_start(); $_SESSION=["authenticated"=>true,"user"=>["code"=>"basic","permissions"=>["event-guest-manager"]]];'
  . '$_SERVER["SCRIPT_FILENAME"]='.var_export(dirname(__DIR__).'/mini apps/EGMs/first/games.php',true).';'
  . 'register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});'
  . 'require '.var_export(dirname(__DIR__).'/mini apps/Event Guest Manager/games.php',true).';';
 $process=proc_open([PHP_BINARY,'-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
 fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
 permissionAssert($error==='403' && (json_decode($output,true)['status']??'')==='error','Actual games API rejects missing capability before accessing game storage');
 echo "PASS: dynamic registry, hidden events, scoped UI/API isolation, least-privilege defaults, legacy compatibility and live revocation\n";
} finally { $server->exec("DROP DATABASE `$name`"); }
