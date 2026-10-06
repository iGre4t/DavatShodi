<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once __DIR__.'/lib/system-telegram.php';
$command=$argv[1] ?? 'status';
try {
    if($command==='diagnose'){
        $diagnostic=systemTelegramDiagnose();
        echo json_encode($diagnostic,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
        exit(!empty($diagnostic['checks'][$diagnostic['active_route']]['ok'])?0:1);
    }
    if ($command === 'status') {
        $bot=systemTelegramCall('getMe');$webhook=systemTelegramCall('getWebhookInfo');
        echo json_encode(['bot'=>$bot['username'] ?? '', 'webhook'=>$webhook['url'] ?? ''],JSON_UNESCAPED_UNICODE).PHP_EOL;exit;
    }
    if ($command === 'set-webhook') {
        $url=$argv[2] ?? '';$secret=systemTelegramConfig()['webhook_secret'];
        if (!filter_var($url,FILTER_VALIDATE_URL) || !str_starts_with($url,'https://') || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret)) throw new RuntimeException('HTTPS webhook URL and configured random secret are required.');
        systemTelegramCall('setWebhook',['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>['message','callback_query']]);echo "Webhook configured.\n";exit;
    }
    if ($command !== 'poll') throw new RuntimeException('Use diagnose, status, set-webhook URL, or poll.');
    $webhook=systemTelegramCall('getWebhookInfo');if (!empty($webhook['url'])) throw new RuntimeException('A webhook is already active; polling was not started.');
    $pdo=connectDatabase(loadConfig(__DIR__.'/config.php'));if(!$pdo)throw new RuntimeException('Database unavailable');
    systemTelegramEnsure($pdo);$offset=0;
    echo "Telegram bot polling started.\n";
    while (true) {
        try {
            $updates=systemTelegramCall('getUpdates',['offset'=>$offset,'timeout'=>25,'allowed_updates'=>['message','callback_query']]);
            foreach ($updates as $update) {systemTelegramProcess($pdo,$update);$offset=(int)$update['update_id']+1;}
            systemTelegramRetry($pdo);
        } catch (Throwable $error) {fwrite(STDERR,"Telegram processing will retry.\n");sleep(5);}
    }
} catch (Throwable $error) {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
