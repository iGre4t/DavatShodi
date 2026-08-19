<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/egm-database-runtime.php';
require_once __DIR__ . '/activity-logger.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ok = egmActivityLogUserActivity([
    'level' => 'info',
    'user_id' => 'taskclub-verification',
    'action' => 'egm.logger.verification',
    'entity_type' => 'logger',
    'entity_id' => 'jsonl',
    'status' => 'success',
    'message' => 'Event Guest Manager activity logger verification event.',
    'metadata' => ['source' => 'verify-logger.php']
]);

$entries = egmDatabaseRuntimeActivityEntriesForDay(dirname(__DIR__), date('Y-m-d'), 1);
$last = $entries[0] ?? null;
if (!$ok || !is_array($last)) {
    fwrite(STDERR, "Event Guest Manager activity logger verification failed.\n");
    exit(1);
}

if (($last['action'] ?? '') !== 'egm.logger.verification') {
    fwrite(STDERR, "Event Guest Manager activity logger wrote an invalid JSONL entry.\n");
    exit(1);
}

echo "Event Guest Manager relational activity logger verification passed.\n";
