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

$config = loadConfig(dirname(__DIR__, 2) . '/api/config.php');
$pdo = connectActivityLogDatabase($config);
if (!$ok || !$pdo instanceof PDO) {
    fwrite(STDERR, "Panel activity logger verification failed.\n");
    exit(1);
}
$statement = $pdo->query("SELECT `action` FROM `panel_user_activity_logs` ORDER BY `id` DESC LIMIT 1");
if ((string)$statement->fetchColumn() !== 'panel.logger.verification') {
    fwrite(STDERR, "Panel activity logger wrote an invalid database entry.\n");
    exit(1);
}
echo "Panel activity logger database verification passed.\n";

