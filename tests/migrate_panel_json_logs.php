<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/activity-logger.php';

if (PHP_SAPI !== 'cli') exit(1);
$config = loadConfig(dirname(__DIR__) . '/api/config.php');
$pdo = connectActivityLogDatabase($config);
if (!$pdo instanceof PDO || !activityLoggerEnsureAuditTable($pdo, ACTIVITY_LOGGER_CHANNEL_PANEL)) {
    throw new RuntimeException('Panel activity logs database is unavailable.');
}
$directory = dirname(__DIR__) . '/system/useractivitylogs/logs';
$exists = $pdo->prepare(
    'SELECT COUNT(*) FROM `panel_user_activity_logs` WHERE `timestamp`=:timestamp AND `action`=:action '
    . 'AND `user_id` <=> :user_id AND `session_id` <=> :session_id AND `entity_type` <=> :entity_type '
    . 'AND `entity_id` <=> :entity_id AND `status`=:status AND `message` <=> :message'
);
$scanned = 0;
$alreadyPresent = 0;
$inserted = 0;
foreach (glob($directory . '/*.log') ?: [] as $path) {
    $handle = fopen($path, 'rb');
    if ($handle === false) continue;
    while (($line = fgets($handle)) !== false) {
        $entry = json_decode(trim($line), true);
        if (!is_array($entry)) continue;
        $scanned++;
        $entry = activityLoggerBuildLogEntry($entry);
        $params = [
            ':timestamp' => activityLoggerTimestamp($entry['timestamp'] ?? null)->format('Y-m-d H:i:s'),
            ':action' => $entry['action'], ':user_id' => $entry['user_id'], ':session_id' => $entry['session_id'],
            ':entity_type' => $entry['entity_type'], ':entity_id' => $entry['entity_id'],
            ':status' => $entry['status'], ':message' => $entry['message'],
        ];
        $exists->execute($params);
        if ((int)$exists->fetchColumn() > 0) {
            $alreadyPresent++;
            continue;
        }
        if (!activityLoggerWriteAuditLog($pdo, $entry, ACTIVITY_LOGGER_CHANNEL_PANEL)) {
            throw new RuntimeException('Failed to import panel log entry from ' . basename($path));
        }
        $inserted++;
    }
    fclose($handle);
}
echo json_encode(compact('scanned', 'alreadyPresent', 'inserted'), JSON_PRETTY_PRINT), PHP_EOL;
