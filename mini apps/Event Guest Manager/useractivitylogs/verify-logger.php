<?php
declare(strict_types=1);

require_once __DIR__ . '/activity-logger.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ok = egmActivityLogUserActivity([
    'level' => 'info',
    'user_id' => 'taskclub-verification',
    'action' => 'taskclub.logger.verification',
    'entity_type' => 'logger',
    'entity_id' => 'jsonl',
    'status' => 'success',
    'message' => 'Event Guest Manager activity logger verification event.',
    'metadata' => ['source' => 'verify-logger.php']
]);

$path = egmActivityLogDirectory() . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
if (!$ok || !is_file($path)) {
    fwrite(STDERR, "Event Guest Manager activity logger verification failed.\n");
    exit(1);
}

$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$last = is_array($lines) ? end($lines) : false;
$decoded = is_string($last) ? json_decode($last, true) : null;
if (!is_array($decoded) || ($decoded['action'] ?? '') !== 'taskclub.logger.verification') {
    fwrite(STDERR, "Event Guest Manager activity logger wrote an invalid JSONL entry.\n");
    exit(1);
}

echo "Event Guest Manager activity logger verification passed: {$path}\n";
