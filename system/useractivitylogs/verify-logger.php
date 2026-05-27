<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/activity-logger.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ok = panelLogUserActivity([
    'level' => 'info',
    'user_id' => 'panel-verification',
    'action' => 'panel.logger.verification',
    'entity_type' => 'logger',
    'entity_id' => 'jsonl',
    'status' => 'success',
    'message' => 'Panel activity logger verification event.',
    'metadata' => ['source' => 'verify-logger.php']
]);

$path = panelActivityLogDirectory() . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
if (!$ok || !is_file($path)) {
    fwrite(STDERR, "Panel activity logger verification failed.\n");
    exit(1);
}

$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$last = is_array($lines) ? end($lines) : false;
$decoded = is_string($last) ? json_decode($last, true) : null;
if (!is_array($decoded) || ($decoded['action'] ?? '') !== 'panel.logger.verification') {
    fwrite(STDERR, "Panel activity logger wrote an invalid JSONL entry.\n");
    exit(1);
}

echo "Panel activity logger verification passed: {$path}\n";

