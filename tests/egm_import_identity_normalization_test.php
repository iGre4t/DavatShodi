<?php
require_once dirname(__DIR__).'/api/lib/egm-period-invites.php';
require_once dirname(__DIR__).'/api/lib/egm-oeu-import-review.php';
function identityCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
foreach(['12345678','012345678','0012345678','۰۰۱۲۳۴۵۶۷۸',12345678] as $value)identityCheck(egmPeriodInvitesValidNationalId($value)==='0012345678','Missing leading zeros not normalized');
foreach(['-',' -- ','—','–','_','ندارد','N/A',''] as $value)identityCheck(egmPeriodInvitesWorkId($value)==='','Placeholder treated as work identity');
identityCheck(egmPeriodInvitesWorkId('02')==='02','Valid short work ID changed');
$one=egmPeriodInvitesNormalizeExcelRow(['national_id'=>'12345678','work_id'=>'-'],2);
$two=egmPeriodInvitesNormalizeExcelRow(['national_id'=>'23456789','work_id'=>'-'],3);
identityCheck($one['national_id']==='0012345678'&&$one['work_id']===''&&$one['can_invite'],'Missing work ID prevented national-ID invitation');
identityCheck(egmPeriodInvitesIdentityKey($one)!==egmPeriodInvitesIdentityKey($two),'Unrelated placeholder guests merged');
identityCheck(!egmPeriodInvitesIsAlreadyInvited($one,['w:-'=>true,'n:0023456789'=>true]),'Placeholder caused false already-invited match');
identityCheck(egmPeriodInvitesIsAlreadyInvited($one,['n:0012345678'=>true]),'Padded ID did not skip prior invitation');
$candidate=['national_id'=>'12345678','work_id'=>'02','source'=>'custom'];
$synced=egmPeriodInvitesSyncMatchedEgmNationalId(['code'=>''],$candidate,'0012345678',0);
identityCheck($synced['national_id']==='0012345678','Equivalent stored/Excel IDs conflicted');
try {egmPeriodInvitesSyncMatchedEgmNationalId(['code'=>''],$candidate,'0023456789',0);throw new RuntimeException('Real identity conflict ignored');}catch(InvalidArgumentException $expected){}
$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE organizational_event_users(id INTEGER PRIMARY KEY,work_id TEXT,first_name TEXT,last_name TEXT,national_id TEXT,phone_number TEXT,deputy TEXT,general_department TEXT,department TEXT,gender TEXT,postal_level TEXT)');
$pdo->exec("INSERT INTO organizational_event_users(id,work_id,first_name,national_id) VALUES(1,'-','First','0012345678'),(2,'-','Second','0023456789')");
$review=egmOeuImportPreview(['pdo'=>$pdo,'code'=>'test'],'01',[['national_id'=>'0012345678','work_id'=>'-','first_name'=>'Changed']]);
identityCheck(count($review['updates'])===1 && (int)$review['updates'][0]['id']===1 && $review['issues']===[],'OEU review matched unrelated placeholder owners');
identityCheck(!isset($review['updates'][0]['changes']['national_id']) && !isset($review['updates'][0]['changes']['work_id']),'Equivalent IDs produced fake profile changes');
identityCheck(egmPeriodInvitesExcelProfileChanges(['national_id'=>'0012345678','work_id'=>'-'],['national_id'=>'12345678','work_id'=>'-'])===[],'Repeated Excel created a false changed-user row');
echo "PASS: Persian/numeric national IDs padded, placeholder work IDs ignored, distinct guests preserved, OEU preview and genuine conflicts protected\n";
