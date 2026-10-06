<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/system-telegram.php';
$config=systemTelegramConfig();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $config['webhook_secret'] === '' || !hash_equals($config['webhook_secret'],(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);exit;
}
try {
    $pdo=connectDatabase(loadConfig(__DIR__.'/config.php'));
    if (!$pdo) throw new RuntimeException('Database unavailable');
    systemTelegramEnsure($pdo);
    $update=json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($update)) {http_response_code(400);exit;}
    systemTelegramProcess($pdo,$update);
    systemTelegramRetry($pdo);
    echo 'ok';
} catch (Throwable $error) {error_log('Telegram webhook processing failed');http_response_code(500);echo 'retry';}
