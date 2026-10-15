<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/egm-comp-'.bin2hex(random_bytes(6));mkdir($dir);
define('EGM_PRIZE_INVENTORY_ALLOWED_DIRECTORY', $dir);
require_once dirname(__DIR__).'/mini apps/Event Guest Manager/prize_inventory_store.php';
require_once dirname(__DIR__).'/api/lib/egm-competition-prizes.php';
function requireCompetition(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function rejectCompetition(callable $work):void { try{$work();}catch(InvalidArgumentException $expected){return;}throw new RuntimeException('Stale operation accepted'); }
$pdo=new PDO('mysql:host=127.0.0.1;port='.(getenv('EGM_TEST_DB_PORT')?:'3306').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='egm_comp_reset_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
try {
 $pdo->exec("USE `$db`");$code='9876543217';ensureEgmInstanceTables($pdo,$code);
 $context=['pdo'=>$pdo,'code'=>$code,'mission_dir'=>$dir];$path=$dir.'/EGM Prizes.json';
 file_put_contents($path,json_encode([['id'=>'prize1','name'=>'Gift','quantity'=>10,'last'=>10,'rank'=>2]]));
 egmInstanceWriteData($pdo,$code,egmCompetitionQueueKey(),[2,2]);
 $first=egmCompetitionRun($context,'01','roll',['card'=>8],'ref');
 $again=egmCompetitionRun($context,'01','roll',['card'=>3,'token'=>$first['pending']['token']],'ref');
 requireCompetition($again['position']===0 && $again['pending']['card']===3,'Reroll must keep queue position');
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','confirm',['token'=>$first['pending']['token']],'ref'));
 $done=egmCompetitionRun($context,'01','confirm',['token'=>$again['pending']['token']],'ref');
 requireCompetition($done['position']===1 && egmPrizeInventoryReadSnapshot($path)[0]['last']===9,'Only confirmation consumes a prize');
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','roll',['card'=>3],'ref'));
 requireCompetition(egmCompetitionRun($context,'01','state',[],'ref')['confirmed'][0]['card']===3,'Confirmed card must remain persisted');
 $other=egmCompetitionRun($context,'02','roll',['card'=>1],'ref');
 $stale=$done['resetVersion'];
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','roll',['card'=>1],'ref'));
 $stock=egmPrizeInventoryReadSnapshot($path);$stock[]=['id'=>'same-name','name'=>' gift ','quantity'=>50,'last'=>50,'rank'=>2];
 requireCompetition(egmPrizeInventoryCommit($path,$stock),'Preparing duplicate-name prize failed');
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','roll',['card'=>1],'ref'));
 $stock[]=['id'=>'different-name','name'=>'Other','quantity'=>1,'last'=>1,'rank'=>2];
 requireCompetition(egmPrizeInventoryCommit($path,$stock),'Preparing another name failed');
 $pending=egmCompetitionRun($context,'01','roll',['card'=>1],'ref');
 requireCompetition($pending['pending']['prizeId']==='different-name','Duplicate name selected after confirmation');
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','reset',['version'=>$stale],'ref'));
 $reset=egmCompetitionRun($context,'01','reset',['version'=>$pending['resetVersion']],'ref');
 requireCompetition($reset['position']===0 && $reset['confirmed']===[] && $reset['pending']===null && $reset['nextRank']===2,'Reset must clear current period and restart queue');
 requireCompetition(egmPrizeInventoryReadSnapshot($path)[0]['last']===10,'Reset must restore confirmed inventory');
 requireCompetition(egmCompetitionRun($context,'02','state',[],'ref')['pending']['token']===$other['pending']['token'],'Reset must preserve other period');
 egmCompetitionRun($context,'01','reset',['version'=>$reset['resetVersion']],'ref');
 requireCompetition(egmPrizeInventoryReadSnapshot($path)[0]['last']===10,'Repeated empty reset must not inflate inventory');
 requireCompetition(egmPrizeInventoryCommit($path,[egmPrizeInventoryReadSnapshot($path)[0]]),'Restoring fixture failed');
 $newRoll=egmCompetitionRun($context,'01','roll',['card'=>9],'ref');
 $newDone=egmCompetitionRun($context,'01','confirm',['token'=>$newRoll['pending']['token']],'ref');
 requireCompetition(egmPrizeInventoryCommit($path,[]),'Removing inventory prize failed');
 $removedReset=egmCompetitionRun($context,'01','reset',['version'=>$newDone['resetVersion']],'ref');
 requireCompetition($removedReset['confirmed']===[] && $removedReset['pending']===null && $removedReset['nextRank']===2,'Removed prize blocked reset');
 $audit=egmInstanceReadData($pdo,$code,egmCompetitionStateKey('01').':last_reset',[]);
 requireCompetition(($audit['removedPrizeReturns']['prize1']??0)===1 && count($audit['state']['confirmed'])===1,'Removed prize reset audit was lost');
 requireCompetition(egmPrizeInventoryReadSnapshot($path)===[],'Reset recreated a removed prize');
 requireCompetition(egmPrizeInventoryCommit($path,[['id'=>'prize3','name'=>'Third','quantity'=>2,'last'=>2,'rank'=>3]]),'Preparing stock failed');
 $off=egmCompetitionRun($context,'__settings__','save_queue',['queue'=>[], 'version'=>$removedReset['queueVersion'], 'queueEnabled'=>false],'ref');
 requireCompetition($off['queueEnabled']===false,'Queue switch did not save');
 $any=egmCompetitionRun($context,'01','roll',['card'=>1],'ref');
 requireCompetition($any['pending']['rank']===3 && $any['pending']['prizeId']==='prize3','Disabled queue did not choose available stock outside configured rank');
 $anyDone=egmCompetitionRun($context,'01','confirm',['token'=>$any['pending']['token']],'ref');
 requireCompetition($anyDone['position']===1 && egmPrizeInventoryReadSnapshot($path)[0]['last']===1,'Unqueued confirmation failed');
 egmCompetitionRun($context,'__settings__','save_queue',['queue'=>[2,2],'version'=>$anyDone['queueVersion'],'queueEnabled'=>true],'ref');
 rejectCompetition(fn()=>egmCompetitionRun($context,'01','roll',['card'=>2],'ref'));
 echo "PASS: reroll tokens, queue advancement, inventory restoration, stale reset protection and period isolation.\n";
} finally {
 $pdo->exec("DROP DATABASE `$db`");
 foreach(glob($dir.'/*') as $file)if(is_file($file))unlink($file);rmdir($dir);
}
