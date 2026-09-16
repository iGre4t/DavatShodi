<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/tc-database-runtime.php';
require_once __DIR__ . '/activity-logger.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ok = tcActivityLogUserActivity([
    'level' => 'info',
    'user_id' => 'taskclub-verification',
    'action' => 'taskclub.logger.verification',
    'entity_type' => 'logger',
    'entity_id' => 'jsonl',
    'status' => 'success',
    'message' => 'Task Club activity logger verification event.',
    'metadata' => ['source' => 'verify-logger.php']
]);

$entries = tcDatabaseRuntimeActivityEntriesForDay(dirname(__DIR__), date('Y-m-d'), 1);
$last = $entries[0] ?? null;
if (!$ok || !is_array($last)) {
    fwrite(STDERR, "Task Club activity logger verification failed.\n");
    exit(1);
}

if (($last['action'] ?? '') !== 'taskclub.logger.verification') {
    fwrite(STDERR, "Task Club activity logger wrote an invalid JSONL entry.\n");
    exit(1);
}

echo "Task Club relational activity logger verification passed.\n";
