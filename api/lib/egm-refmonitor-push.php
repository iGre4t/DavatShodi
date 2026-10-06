<?php
declare(strict_types=1);
require_once __DIR__.'/tc-password-vault.php';

final class EgmRefPushSetupException extends RuntimeException {}

function egmRefPushRuntime(): void
{
    if(PHP_VERSION_ID<80200)throw new EgmRefPushSetupException('اعلان گوشی به PHP 8.2 یا بالاتر نیاز دارد؛ نسخهٔ هاست '.PHP_VERSION.' است. نسخهٔ PHP را در کنترل‌پنل هاست تغییر دهید.');
    foreach(['openssl','curl','mbstring'] as $extension)if(!extension_loaded($extension))throw new EgmRefPushSetupException('افزونهٔ '.$extension.' برای اعلان گوشی روی هاست فعال نیست. آن را در تنظیمات PHP فعال کنید.');
    foreach(['openssl_pkey_new','openssl_pkey_get_details','openssl_encrypt','openssl_decrypt','curl_init'] as $function)if(!function_exists($function))throw new EgmRefPushSetupException('تابع '.$function.' روی هاست غیرفعال است؛ پشتیبانی هاست باید اجرای آن را برای اعلان گوشی فعال کند.');
    $autoload=dirname(__DIR__).'/vendor/egm-web-push/vendor/autoload.php';
    if(!is_file($autoload))throw new EgmRefPushSetupException('فایل‌های اعلان کامل آپلود نشده‌اند. پوشهٔ api/vendor/egm-web-push را کامل از بستهٔ به‌روزرسانی استخراج کنید.');
    // Composer may print its platform error before throwing. Never expose that as HTML in an API response.
    ob_start();
    try {
        require_once $autoload;
        if(!class_exists(\Minishlink\WebPush\WebPush::class))throw new RuntimeException('WebPush class missing');
    }catch(Throwable $error){throw new EgmRefPushSetupException('کتابخانهٔ اعلان روی هاست بارگذاری نشد؛ پوشهٔ api/vendor/egm-web-push و نسخهٔ PHP را بررسی کنید.',0,$error);}
    finally{ob_end_clean();}
}

function egmRefPushCreateKeys(): array
{
    if(!function_exists('openssl_pkey_new')||!function_exists('openssl_pkey_get_details'))throw new EgmRefPushSetupException('ساخت کلید اعلان روی هاست غیرفعال است؛ اجرای توابع OpenSSL را با پشتیبانی هاست بررسی کنید.');
    $options=['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1'];
    foreach([getenv('OPENSSL_CONF')?:'',dirname(PHP_BINARY).'/extras/ssl/openssl.cnf','/etc/ssl/openssl.cnf','/etc/pki/tls/openssl.cnf'] as $path){
        if($path!==''&&is_file($path)){$options['config']=$path;break;}
    }
    $key=@openssl_pkey_new($options);
    $details=$key!==false?openssl_pkey_get_details($key):false;
    if(!is_array($details)||!isset($details['ec']['x'],$details['ec']['y'],$details['ec']['d']))throw new EgmRefPushSetupException('ساخت کلید اعلان توسط OpenSSL هاست ناموفق بود. پشتیبانی EC و تنظیمات openssl.cnf را از پشتیبانی هاست بررسی کنید.');
    $encode=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    return ['publicKey'=>$encode("\x04".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT)),
        'privateKey'=>$encode(str_pad($details['ec']['d'],32,"\0",STR_PAD_LEFT))];
}

function egmRefPushEnsure(PDO $pdo): void
{
    static $ready=[]; if(isset($ready[spl_object_id($pdo)]))return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_ref_push_keys (id TINYINT PRIMARY KEY,public_key VARCHAR(200) NOT NULL,private_key TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_ref_push_devices (id CHAR(64) PRIMARY KEY,event_code VARCHAR(64) NOT NULL,user_code VARCHAR(128) NOT NULL,subscription LONGTEXT NOT NULL,monitor_url TEXT NOT NULL,period_code VARCHAR(64) NOT NULL DEFAULT '',game_id VARCHAR(16) NOT NULL DEFAULT '',team_id BIGINT NOT NULL DEFAULT 0,expires_at DATETIME NOT NULL,INDEX watch_team(event_code,game_id,team_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_ref_push_outbox (id BIGINT AUTO_INCREMENT PRIMARY KEY,device_id CHAR(64) NOT NULL,payload LONGTEXT NOT NULL,attempts TINYINT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX due(next_attempt_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready[spl_object_id($pdo)]=true;
}

function egmRefPushKeys(PDO $pdo): array
{
    egmRefPushRuntime();
    egmRefPushEnsure($pdo); $row=$pdo->query('SELECT * FROM egm_ref_push_keys WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$row){
        $keys=egmRefPushCreateKeys();
        $statement=$pdo->prepare('INSERT IGNORE INTO egm_ref_push_keys(id,public_key,private_key) VALUES(1,?,?)');
        try{$encrypted=tcPasswordVaultEncrypt($keys['privateKey']);}
        catch(Throwable $error){throw new EgmRefPushSetupException('کلید رمزگذاری سامانه در دسترس نیست. فایل کلید موجود TaskClub و دسترسی خواندن/نوشتن آن را بررسی کنید؛ کلید موجود را حذف نکنید.',0,$error);}
        $statement->execute([$keys['publicKey'],$encrypted]);
        $row=$pdo->query('SELECT * FROM egm_ref_push_keys WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    }
    try{return ['publicKey'=>$row['public_key'],'privateKey'=>tcPasswordVaultDecrypt($row['private_key'])];}
    catch(Throwable $error){throw new EgmRefPushSetupException('خواندن کلید اعلان ناموفق بود؛ کلید رمزگذاری TaskClub باید همان کلید قبلی سامانه باشد. فایل کلید موجود را بازیابی کنید و آن را حذف نکنید.',0,$error);}
}

function egmRefPushSubscription(array $input): array
{
    $endpoint=(string)($input['endpoint']??'');$url=parse_url($endpoint);$host=strtolower((string)($url['host']??''));
    $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)||str_ends_with($host,'.notify.windows.com')||str_ends_with($host,'.push.services.mozilla.com');
    if(!$allowed||($url['scheme']??'')!=='https'||isset($url['user'])||isset($url['pass'])||isset($url['port'])||strlen($endpoint)>4096)throw new InvalidArgumentException('نشانی اعلان معتبر نیست.');
    $keys=(array)($input['keys']??[]);
    foreach(['p256dh'=>65,'auth'=>16] as $name=>$bytes){
        $encoded=(string)($keys[$name]??'');
        $raw=base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);
        if(!is_string($raw)||strlen($raw)!==$bytes)throw new InvalidArgumentException('کلید اعلان معتبر نیست.');
    }
    return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']],'contentEncoding'=>'aes128gcm'];
}

function egmRefPushRegister(PDO $pdo,string $eventCode,string $userCode,array $subscription,string $monitorUrl): string
{
    egmRefPushEnsure($pdo);$subscription=egmRefPushSubscription($subscription);$id=hash('sha256',$subscription['endpoint']);
    $statement=$pdo->prepare("INSERT INTO egm_ref_push_devices(id,event_code,user_code,subscription,monitor_url,expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE event_code=VALUES(event_code),user_code=VALUES(user_code),subscription=VALUES(subscription),monitor_url=VALUES(monitor_url),period_code='',game_id='',team_id=0,expires_at=VALUES(expires_at)");
    $statement->execute([$id,$eventCode,$userCode,json_encode($subscription,JSON_THROW_ON_ERROR),$monitorUrl]);return $id;
}

function egmRefPushWatch(PDO $pdo,string $eventCode,string $userCode,string $deviceId,string $periodCode,string $gameId,int $teamId): void
{
    egmRefPushEnsure($pdo);$statement=$pdo->prepare('UPDATE egm_ref_push_devices SET period_code=?,game_id=?,team_id=?,expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE id=? AND event_code=? AND user_code=?');
    $statement->execute([$periodCode,$gameId,$teamId,$deviceId,$eventCode,$userCode]);
}

function egmRefPushRemove(PDO $pdo,string $eventCode,string $userCode,string $deviceId=''):void
{
    egmRefPushEnsure($pdo);$statement=$pdo->prepare('DELETE FROM egm_ref_push_devices WHERE event_code=? AND user_code=?'.($deviceId!==''?' AND id=?':''));
    $statement->execute($deviceId!==''?[$eventCode,$userCode,$deviceId]:[$eventCode,$userCode]);
}

function egmRefPushRoomAssigned(PDO $pdo,string $eventCode,string $table,int $teamId,array $team,array $room):void
{
    // No push network call while the game lock is held. Queue for shutdown / cron.
    if(!egmInstanceTableExists($pdo,'egm_ref_push_devices'))return;
    $statement=$pdo->prepare('SELECT * FROM egm_ref_push_devices WHERE event_code=? AND team_id=? AND expires_at>NOW()');$statement->execute([$eventCode,$teamId]);
    foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $device){
        if(egmGamesTableName($eventCode,$device['period_code'],$device['game_id'])!==$table)continue;
        $payload=['event_code'=>$eventCode,'table'=>$table,'team_id'=>$teamId,'game_id'=>$device['game_id'],'period_code'=>$device['period_code'],'room_id'=>$room['room_id'],'assigned_at'=>$room['assigned_at'],
            'title'=>'اتاق تیم آماده شد','body'=>($team['name']??'تیم').' · '.$room['room_name'].' · '.$room['level_name'],
            'url'=>$device['monitor_url'].'?room_team='.$teamId.'&room_game='.rawurlencode($device['game_id']).'&room_period='.rawurlencode($device['period_code']),
            'tag'=>'ref-room-'.$teamId.'-'.$room['level_id']];
        $insert=$pdo->prepare('INSERT INTO egm_ref_push_outbox(device_id,payload) VALUES(?,?)');$insert->execute([$device['id'],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    static $scheduled=[];if(isset($scheduled[spl_object_id($pdo)]))return;$scheduled[spl_object_id($pdo)]=true;
    register_shutdown_function(static function()use($pdo):void{
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
        try{egmRefPushFlush($pdo);}catch(Throwable $error){error_log('RefMonitor push delivery failed.');}
    });
}

function egmRefPushFlush(PDO $pdo):void
{
    egmRefPushEnsure($pdo);$lock=$pdo->query("SELECT GET_LOCK('egm_ref_push_delivery',0)");if((int)$lock->fetchColumn()!==1)return;
    try{
        $jobs=$pdo->query('SELECT o.*,d.subscription,d.user_code,d.event_code,d.expires_at FROM egm_ref_push_outbox o LEFT JOIN egm_ref_push_devices d ON d.id=o.device_id WHERE o.next_attempt_at<=NOW() ORDER BY o.id LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
        if(!$jobs)return;
        $keys=egmRefPushKeys($pdo);$client=new \GuzzleHttp\Client(['timeout'=>3,'connect_timeout'=>2]);
        $push=new \Minishlink\WebPush\WebPush([],['TTL'=>180,'urgency'=>'high'],$client);
        foreach($jobs as $job){
            try {
            $payload=json_decode($job['payload'],true);$valid=!empty($job['subscription'])&&strtotime($job['expires_at'])>time()&&strtotime($job['created_at'])>time()-180;
            if($valid&&preg_match('/^egm_game_[a-f0-9]{40}$/D',(string)($payload['table']??''))){
                $team=egmRefMonitorReadTeam($pdo,$payload['table'],(int)$payload['team_id']);$assignment=$team['payload']['room_assignment']??[];
                $valid=empty($team['payload']['ended_at'])&&($assignment['room_id']??'')===$payload['room_id']&&($assignment['assigned_at']??'')===$payload['assigned_at'];
            }else $valid=false;
            if($valid&&str_starts_with($job['user_code'],'facilitator:')){
                $valid=false;foreach(egmFacilitatorsRead($pdo,$job['event_code'])as $account)if('facilitator:'.$account['id']===$job['user_code']){$valid=true;break;}
            }
            if($valid&&!str_starts_with($job['user_code'],'facilitator:')){$user=loadUserByCode($pdo,$job['user_code']);$valid=is_array($user)&&userHasPermissionId($user,'event-guest-manager');}
            if(!$valid){$pdo->prepare('DELETE FROM egm_ref_push_outbox WHERE id=?')->execute([$job['id']]);continue;}
            $subscription=\Minishlink\WebPush\Subscription::create(json_decode($job['subscription'],true));
            unset($payload['table'],$payload['event_code']);
            $url=parse_url($payload['url']);$auth=['VAPID'=>$keys+['subject'=>(($url['scheme']??'https').'://'.($url['host']??'example.com'))]];
            $report=$push->sendOneNotification($subscription,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),[],$auth);
            if($report->isSuccess()||$report->isSubscriptionExpired()||(int)$job['attempts']>=2)$pdo->prepare('DELETE FROM egm_ref_push_outbox WHERE id=?')->execute([$job['id']]);
            else $pdo->prepare('UPDATE egm_ref_push_outbox SET attempts=attempts+1,next_attempt_at=DATE_ADD(NOW(),INTERVAL 30 SECOND) WHERE id=?')->execute([$job['id']]);
            if($report->isSubscriptionExpired())$pdo->prepare('DELETE FROM egm_ref_push_devices WHERE id=?')->execute([$job['device_id']]);
            } catch(Throwable $error) {
                if((int)$job['attempts']>=2)$pdo->prepare('DELETE FROM egm_ref_push_outbox WHERE id=?')->execute([$job['id']]);
                else $pdo->prepare('UPDATE egm_ref_push_outbox SET attempts=attempts+1,next_attempt_at=DATE_ADD(NOW(),INTERVAL 30 SECOND) WHERE id=?')->execute([$job['id']]);
                error_log('RefMonitor push job failed.');
            }
        }
    }finally{$pdo->query("SELECT RELEASE_LOCK('egm_ref_push_delivery')");}
}
