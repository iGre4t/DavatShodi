<?php
declare(strict_types=1);
$directory=sys_get_temp_dir().'/egm-prize-codes-'.bin2hex(random_bytes(6));mkdir($directory,0700,true);
define('EGM_PRIZE_INVENTORY_ALLOWED_DIRECTORY',$directory);
require_once dirname(__DIR__).'/mini apps/Event Guest Manager/prize_inventory_store.php';
function prizeCodeCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$path=$directory.'/EGM Prizes.json';
try {
 $legacy=[['id'=>'existing-code','name'=>'کارت هدیه','quantity'=>10,'last'=>6,'rank'=>1,'pendingAwardIds'=>['saved-award']],['name'=>'کارت هدیه','quantity'=>3,'last'=>2,'rank'=>2]];
 file_put_contents($path,json_encode($legacy));
 $rows=egmPrizeInventoryReadSnapshot($path);
 prizeCodeCheck(count($rows)===2&&$rows[0]['id']==='existing-code','Existing prize identity changed');
 prizeCodeCheck($rows[1]['id']!==''&&$rows[1]['id']!==$rows[0]['id'],'Legacy same-name prize not assigned unique ID');
 prizeCodeCheck($rows[0]['last']===6&&$rows[0]['pendingAwardIds']===['saved-award']&&$rows[1]['last']===2,'Migration changed inventory/award markers');
 prizeCodeCheck(array_column(egmPrizeInventoryReadSnapshot($path),'id')===array_column($rows,'id'),'IDs not persisted on read');
 $ids=array_column($rows,'id');
 $edited=[$rows[1],$rows[0]];$edited[0]['name']='نام دیگر';
 $version=egmPrizeInventoryVersion($rows);$saved=null;$actual=null;$failure=null;
 prizeCodeCheck(egmPrizeInventoryReplaceIfVersion($path,$edited,$version,$actual,$saved,$failure),'Reordering duplicate names failed');
 prizeCodeCheck($saved[0]['id']===$ids[1]&&$saved[1]['id']===$ids[0],'Reorder/rename changed identity');
 $edited=$saved;$edited[]=['id'=>'new-code','name'=>'کارت هدیه','quantity'=>1,'last'=>1,'rank'=>1];
 prizeCodeCheck(egmPrizeInventoryReplaceIfVersion($path,$edited,$actual,$actual,$saved,$failure),'New same-name prize failed');
 $locked=egmPrizeInventoryReadForUpdate($path);
 foreach($locked as &$row)if($row['id']==='new-code')$row['last']--;unset($row);
 prizeCodeCheck(egmPrizeInventoryCommit($path,$locked),'Stock update failed');
 $after=egmPrizeInventoryReadSnapshot($path);
 prizeCodeCheck($after[1]['last']===6&&$after[2]['last']===0,'Same-name stock update affected other prize');
 $invalid=$after;$invalid[2]['id']=$invalid[1]['id'];$migration=false;
 prizeCodeCheck(!egmPrizeInventoryValidateRecords($invalid,false,$migration),'Duplicate codes accepted');
 echo "PASS: old IDs preserved, missing IDs persisted, duplicate names allowed, reorder/rename stable, stock and pending award markers isolated, duplicate IDs rejected.\n";
}finally{egmPrizeInventoryEnd($path);foreach(glob($directory.'/*')?:[]as $file)unlink($file);rmdir($directory);}
