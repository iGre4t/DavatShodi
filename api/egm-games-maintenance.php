<?php
declare(strict_types=1);
require_once __DIR__.'/lib/common.php';
require_once __DIR__.'/lib/egm-registry.php';
require_once __DIR__.'/lib/egm-game-lifecycle.php';
if(PHP_SAPI==='cli') {
    $pdo=connectDatabase(loadConfig(__DIR__.'/config.php'));
    if(!$pdo){fwrite(STDERR,"Database connection failed\n");exit(1);}
    foreach(listEgmRegistry($pdo) as $event) {
        try{egmGamesCleanupExpiredPeriods(['pdo'=>$pdo,'code'=>(string)$event['code']]);}
        catch(Throwable $error){error_log('EGM game maintenance failed: '.$error->getMessage());}
    }
    exit;
}
require_once __DIR__.'/lib/tab-permissions.php';
$user=requireAuthenticatedSessionUser(false);
$pdo=connectDatabase(loadConfig(__DIR__.'/config.php'));
$code=normalizeEgmRegistryCode((string)($_POST['event_code']??$_GET['event_code']??''));
$event=$pdo&&$code!==''?findEgmRegistryByCode($pdo,$code):null;
$permission='event-guest-manager-mission-'.$code.':games';
if(!$event || (!userHasPermissionId($user,$permission)&&!userHasPermissionId($user,'event-guest-manager:games'))){http_response_code(403);exit('دسترسی ندارید.');}
if(empty($_SESSION['games_maintenance_csrf']))$_SESSION['games_maintenance_csrf']=bin2hex(random_bytes(24));
header('Cache-Control: no-store');$context=['pdo'=>$pdo,'code'=>$code];$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!hash_equals($_SESSION['games_maintenance_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('توکن معتبر نیست.');}
    try {$changes=egmGamesCleanupExpiredPeriods($context,true);$count=array_sum(array_column($changes,'teams_ended_or_released'));$message='انجام شد؛ '.$count.' تیم از بازه‌های پایان‌یافته پایان داده یا از اتاق آزاد شد.';}
    catch(Throwable $error){$message='عملیات انجام نشد: '.$error->getMessage();}
}
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$status=egmGamesPeriodRoomStatus($context);
?>
<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>آزادسازی اتاق‌های بازه‌های پایان‌یافته</title><style>body{font:16px Tahoma;margin:40px;background:#f5f8fc;color:#20334c}main{max-width:950px;margin:auto;background:white;padding:25px;border-radius:20px}table{width:100%;border-collapse:collapse;margin:20px 0}td,th{padding:14px;border-bottom:1px solid #eee;text-align:right}button{font:inherit;padding:14px;border:0;border-radius:10px;background:#168bd2;color:white;cursor:pointer}.result{padding:18px;background:#e8f7ef;border-radius:12px}</style><main><h1><?= $esc($event['name']) ?></h1><h2>وضعیت بازی و اتاق</h2><?php if($message):?><p class="result"><?= $esc($message) ?></p><?php endif;?><table><thead><tr><th>بازه</th><th>وضعیت</th><th>تیم در حال بازی</th><th>اتاق اشغال‌شده</th></tr></thead><tbody><?php foreach($status as $row):?><tr><td><?= $esc($row['title']) ?></td><td><?= $row['ended']?'پایان‌یافته':'پایان نیافته' ?></td><td><?= $row['playing_teams'] ?></td><td><?= $row['occupied_rooms'] ?></td></tr><?php endforeach;?></tbody></table><form method="post"><input type="hidden" name="event_code" value="<?= $esc($code) ?>"><input type="hidden" name="csrf" value="<?= $esc($_SESSION['games_maintenance_csrf']) ?>"><button>پایان بازی‌ها و آزادسازی اتاق‌های بازه‌های پایان‌یافته</button></form><p>تیم‌های بازهٔ جاری و امتیازهای قبلی حفظ می‌شوند.</p></main></html>
