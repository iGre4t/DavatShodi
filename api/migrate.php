<?php
declare(strict_types=1);

/**
 * One-time deployment migration page.
 *
 * Open this page after uploading a release. It is safe to run more than once:
 * the schema functions only create missing tables, columns, and indexes.
 */
session_start();
if (empty($_SESSION['authenticated']) || !is_array($_SESSION['user'] ?? null)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Log in to the main panel before running database migrations.');
}

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/tc-registry.php';
require_once __DIR__ . '/lib/tc-instance-storage.php';
require_once __DIR__ . '/lib/egm-registry.php';
require_once __DIR__ . '/lib/egm-instance-storage.php';
require_once __DIR__ . '/lib/egm-invite-card-routes.php';
require_once __DIR__ . '/lib/activity-log-storage.php';
require_once __DIR__ . '/lib/database-instance-materializer.php';

$report = [
    'mainTables' => [],
    'instances' => [],
    'restoredShells' => [],
    'errors' => [],
];

try {
    $config = loadConfig(__DIR__ . '/config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Unable to connect to the configured main database.');
    }

    ensureStoreTable($pdo, resolveTableName($config));
    ensureUsersExtendedColumns($pdo);
    ensureTcRegistryTable($pdo);
    ensureEgmRegistryTable($pdo);
    ensureEgmInviteCardRoutesTable($pdo);
    $report['mainTables'] = [
        resolveTableName($config), 'tc', 'tc_sequence', 'egm', 'egm_sequence', EGM_INVITE_CARD_ROUTES_TABLE,
    ];

    $logsPdo = connectActivityLogDatabase($config);
    if (!$logsPdo instanceof PDO) {
        throw new RuntimeException('Unable to connect to the configured activity logs database.');
    }
    ensureActivityLogRegistry($logsPdo);

    foreach (listTcRegistry($pdo) as $record) {
        $code = normalizeTcInstanceCode($record['code'] ?? '');
        if ($code === '') continue;
        ensureTcInstanceTables($pdo, $code);
        $logTable = ensureActivityLogTable($logsPdo, 'TC', $code);
        $report['instances'][] = ['type' => 'Task Club', 'code' => $code, 'name' => (string)($record['name'] ?? ''), 'logTable' => $logTable];
    }
    foreach (listEgmRegistry($pdo) as $record) {
        $code = normalizeEgmInstanceCode($record['code'] ?? '');
        if ($code === '') continue;
        ensureEgmInstanceTables($pdo, $code);
        $logTable = ensureActivityLogTable($logsPdo, 'EGM', $code);
        $report['instances'][] = ['type' => 'Event Guest Manager', 'code' => $code, 'name' => (string)($record['name'] ?? ''), 'logTable' => $logTable];
    }

    $report['restoredShells'] = materializeDatabaseBackedInstances($pdo, dirname(__DIR__));
} catch (Throwable $error) {
    $report['errors'][] = $error->getMessage();
    error_log('Database migration failed: ' . $error->getMessage());
}

$ok = $report['errors'] === [];
// Keep this page at HTTP 200 even when a migration fails. The production CDN
// replaces upstream 5xx bodies with its own generic page, which would hide the
// actionable database error from the administrator running this tool.
http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Database migration</title>
  <style>body{font:16px/1.5 system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px;background:#f7f8fa;color:#172033}code{background:#e9edf3;padding:2px 5px;border-radius:4px}li{margin:5px 0}.ok{color:#087a3e}.error{color:#b42318}pre{white-space:pre-wrap;background:#fff;border:1px solid #d8dee9;border-radius:8px;padding:16px}</style>
</head>
<body>
  <h1 class="<?= $ok ? 'ok' : 'error' ?>"><?= $ok ? 'Migration completed' : 'Migration failed' ?></h1>
  <?php if ($ok): ?>
    <p>All current database migrations were run. Reopening this page is safe.</p>
    <p>Main tables ensured: <?= htmlspecialchars(implode(', ', $report['mainTables']), ENT_QUOTES, 'UTF-8') ?></p>
    <h2>Instances migrated (<?= count($report['instances']) ?>)</h2>
    <ul><?php foreach ($report['instances'] as $instance): ?><li><?= htmlspecialchars($instance['type'] . ' ' . $instance['code'] . ($instance['name'] !== '' ? ' — ' . $instance['name'] : '') . ' (' . $instance['logTable'] . ')', ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    <p>Database-backed code shells restored or refreshed: <?= count($report['restoredShells']) ?></p>
  <?php else: ?>
    <p>Nothing was rolled back. Fix the reported connection or schema issue, then open this page again.</p>
    <pre><?= htmlspecialchars(implode("\n", $report['errors']), ENT_QUOTES, 'UTF-8') ?></pre>
  <?php endif; ?>
</body>
</html>
