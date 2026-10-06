<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/system-telegram.php';
systemBotWithProvider('bale', static function(): void {
    $config = systemTelegramConfig();
    // Bale documents a URL-only webhook API, so authenticate with a secret URL key.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $config['webhook_secret'] === '' || !hash_equals($config['webhook_secret'], (string)($_GET['key'] ?? ''))) {
        http_response_code(403); exit;
    }
    try {
        $pdo = connectDatabase(loadConfig(__DIR__ . '/config.php'));
        if (!$pdo) throw new RuntimeException('Database unavailable');
        $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
        if (!is_string($raw) || strlen($raw) > 1024 * 1024) {http_response_code(413); exit;}
        $update = json_decode($raw, true);
        if (!is_array($update)) {http_response_code(400); exit;}
        systemTelegramEnsure($pdo);
        systemTelegramProcess($pdo, $update);
        systemTelegramRetry($pdo);
        echo 'ok';
    } catch (Throwable $error) { error_log('Bale webhook processing failed'); http_response_code(500); echo 'retry'; }
});
