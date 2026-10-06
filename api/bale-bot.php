<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(404); exit;}
require_once __DIR__ . '/lib/system-telegram.php';
$GLOBALS['systemBotProvider'] = 'bale';
$command = $argv[1] ?? 'diagnose';
try {
    if (in_array($command, ['diagnose','status'], true)) {
        $result = systemTelegramDiagnose();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        exit(!empty($result['checks'][$result['active_route']]['ok']) ? 0 : 1);
    }
    if ($command === 'set-webhook') {
        systemBaleSetWebhook((string)($argv[2] ?? ''));
        echo "Bale webhook configured.\n"; exit;
    }
    if ($command !== 'poll') throw new RuntimeException('Use diagnose, status, set-webhook URL, or poll.');
    $webhook = systemTelegramCall('getWebhookInfo');
    if (!empty($webhook['url'])) throw new RuntimeException('Webhook already active; polling was not started.');
    $pdo = connectDatabase(loadConfig(__DIR__ . '/config.php'));
    if (!$pdo) throw new RuntimeException('Database unavailable');
    systemTelegramEnsure($pdo); $offset = 0;
    echo "Bale bot polling started.\n";
    while (true) {
        try {
            $updates = systemTelegramCall('getUpdates', ['offset'=>$offset, 'timeout'=>25]);
            foreach ($updates as $update) {systemTelegramProcess($pdo, $update); $offset = (int)$update['update_id'] + 1;}
            systemTelegramRetry($pdo);
        } catch (Throwable $error) {fwrite(STDERR, "Bale processing will retry.\n"); sleep(5);}
    }
} catch (Throwable $error) {fwrite(STDERR, systemTelegramRedact($error->getMessage(), systemTelegramConfig()) . PHP_EOL); exit(1);}
