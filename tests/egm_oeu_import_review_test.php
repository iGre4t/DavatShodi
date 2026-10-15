<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-period-invites.php';
require_once dirname(__DIR__).'/api/lib/egm-oeu-import-review.php';
function reviewCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$database='egm_oeu_review_'.bin2hex(random_bytes(5));$pdo->exec("CREATE DATABASE `$database`");
try{
 $pdo->exec("USE `$database`");orgUsersEnsureTable($pdo);$code='9876543211';$tables=ensureEgmInstanceTables($pdo,$code);$context=['pdo'=>$pdo,'code'=>$code,'tables'=>$tables];
 $pdo->exec("INSERT INTO organizational_event_users(work_id,first_name,last_name,national_id,phone_number,department,source_row) VALUES('12','Old','One','0012345678','09120000000','Keep',2),('13','Old','Two','0098765432','09121111111','Keep',3)");
 $one=['excel_id'=>'x:2','work_id'=>'12','national_id'=>'12345678','first_name'=>'New','phone_number'=>'09123333333','mapped_fields'=>['national_id','work_id','first_name','phone_number']];
 $two=['excel_id'=>'x:3','work_id'=>'13','national_id'=>'0098765432','first_name'=>'New2','mapped_fields'=>['national_id','work_id','first_name']];
 $_SESSION=[];$preview=egmOeuImportPreview($context,'01',[$one,$two]);reviewCheck(count($preview['updates'])===2,'Two existing users with differences');reviewCheck(!isset($preview['updates'][0]['changes']['national_id']),'Padded national ID is not a change');reviewCheck(!isset($preview['updates'][0]['changes']['department']),'Unmapped fields preserved');reviewCheck($pdo->query('SELECT first_name FROM organizational_event_users WHERE id=1')->fetchColumn()==='Old','Preview does not mutate');
 reviewCheck(egmOeuImportApply($context,'01',$preview['token'],[1],'admin')===1,'Only selected user updates');$current=$pdo->query('SELECT * FROM organizational_event_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);reviewCheck($current['first_name']==='New'&&$current['phone_number']==='09123333333'&&$current['department']==='Keep','Approved fields updated');reviewCheck($pdo->query('SELECT first_name FROM organizational_event_users WHERE id=2')->fetchColumn()==='Old','Unselected user untouched');
 $stale=egmOeuImportPreview($context,'01',[$two]);$pdo->exec("UPDATE organizational_event_users SET phone_number='09129999999' WHERE id=2");try{egmOeuImportApply($context,'01',$stale['token'],[2],'admin');throw new RuntimeException('Stale review accepted');}catch(InvalidArgumentException $expected){}reviewCheck($pdo->query('SELECT first_name FROM organizational_event_users WHERE id=2')->fetchColumn()==='Old','Stale changes rejected');
 $conflict=$two;$conflict['work_id']='12';reviewCheck(egmOeuImportPreview($context,'01',[$conflict])['updates']===[],'National/work pointing to different users must not update');
 $duplicate=$two;$duplicate['first_name']='Different';reviewCheck(egmOeuImportPreview($context,'01',[$two,$duplicate])['updates']===[],'Inconsistent duplicates must not update');
 $pdo->exec("UPDATE organizational_event_users SET national_id='' WHERE id=2");$blank=egmOeuImportPreview($context,'01',[$two]);reviewCheck(isset($blank['updates'][0]['changes']['national_id']),'Missing national ID requires preview confirmation');egmPeriodInvitesSyncOeuNationalId($pdo,'13','0098765432',false);reviewCheck($pdo->query('SELECT national_id FROM organizational_event_users WHERE id=2')->fetchColumn()==='','Read-only matching does not fill declined ID');
 try{egmOeuImportApply($context,'02',$blank['token'],[2],'admin');throw new RuntimeException('Wrong period accepted');}catch(InvalidArgumentException $expected){}
 reviewCheck(egmOeuImportApply($context,'01',$blank['token'],[2],'admin')===1,'Confirmed national ID update');
 // Already-invited users are still included in OEU review.
 $pdo->exec("INSERT INTO `{$tables['users']}` (work_id,national_id,first_name,last_name,source_type,source_user_id,is_active) VALUES('12','0012345678','New','One','oeu',1,1)");$uid=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO `{$tables['user_periods']}` (user_id,period_code,status) VALUES(?,'01','invited')")->execute([$uid]);$one['first_name']='Newest';reviewCheck(count(egmOeuImportPreview($context,'01',[$one])['updates'])===1,'Already invited remains reviewable');
 echo "PASS: no preview mutation, selective confirmation, stale/identity/duplicate guards, mapped fields, national-ID padding and already-invited review\n";
}finally{$pdo->exec("DROP DATABASE `$database`");}
