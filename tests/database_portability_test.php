<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/common.php';
require_once $root . '/api/lib/egm-registry.php';
require_once $root . '/api/lib/egm-instance-storage.php';
require_once $root . '/api/lib/egm-database-runtime.php';
require_once $root . '/api/lib/tc-registry.php';
require_once $root . '/api/lib/tc-instance-storage.php';
require_once $root . '/api/lib/tc-database-runtime.php';
require_once $root . '/api/lib/database-instance-materializer.php';

function portabilityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{documents:int,chunks:int,bytes:int} */
function portabilityValidateDocuments(PDO $pdo, string $table, string $label): array
{
    $headers = $pdo->query(
        "SELECT `file_path`,`payload`,`file_data`,`content_sha256`,`file_size` FROM `{$table}` "
        . "WHERE `storage_kind`='runtime_file' ORDER BY `file_path`"
    )->fetchAll(PDO::FETCH_ASSOC);
    $headerPaths = [];
    $chunkCount = 0;
    $byteCount = 0;
    $chunkQuery = $pdo->prepare(
        "SELECT `file_chunk`,`file_data`,`content_sha256` FROM `{$table}` "
        . "WHERE `storage_kind`='runtime_chunk' AND `file_path`=:path ORDER BY `file_chunk`"
    );
    foreach ($headers as $header) {
        $path = trim(str_replace('\\', '/', (string)($header['file_path'] ?? '')), '/');
        portabilityAssert($path !== '' && preg_match('/^(?:[A-Za-z]:|\/)/', $path) !== 1, "{$label} has a non-portable document path.");
        $headerPaths[$path] = true;
        $content = $header['file_data'] ?? null;
        if (is_resource($content)) $content = stream_get_contents($content);
        if (!is_string($content)) {
            $content = '';
            $chunkQuery->execute([':path' => $path]);
            $index = 0;
            while ($chunk = $chunkQuery->fetch(PDO::FETCH_ASSOC)) {
                portabilityAssert((int)$chunk['file_chunk'] === $index, "{$label} has a missing or reordered chunk for {$path}.");
                $bytes = $chunk['file_data'] ?? null;
                if (is_resource($bytes)) $bytes = stream_get_contents($bytes);
                portabilityAssert(is_string($bytes), "{$label} has a missing blob chunk for {$path}.");
                portabilityAssert(hash('sha256', $bytes) === (string)$chunk['content_sha256'], "{$label} has a corrupt blob chunk for {$path}.");
                $content .= $bytes;
                $index++;
                $chunkCount++;
            }
            $metadata = json_decode((string)($header['payload'] ?? ''), true);
            $expectedChunks = is_array($metadata) ? max(1, (int)($metadata['chunks'] ?? 1)) : 1;
            portabilityAssert($index === $expectedChunks, "{$label} has incomplete chunks for {$path}.");
        }
        portabilityAssert(strlen($content) === (int)$header['file_size'], "{$label} has the wrong stored size for {$path}.");
        portabilityAssert(hash('sha256', $content) === (string)$header['content_sha256'], "{$label} failed document hash validation for {$path}.");
        $byteCount += strlen($content);
    }
    $chunkPaths = $pdo->query("SELECT DISTINCT `file_path` FROM `{$table}` WHERE `storage_kind`='runtime_chunk'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($chunkPaths as $path) portabilityAssert(isset($headerPaths[(string)$path]), "{$label} has orphan document chunks.");
    return ['documents' => count($headers), 'chunks' => $chunkCount, 'bytes' => $byteCount];
}

function portabilityValidateInstance(PDO $pdo, PDO $logsPdo, string $kind, array $registry, string $root): array
{
    $isEgm = $kind === 'EGM';
    $code = $isEgm ? normalizeEgmInstanceCode($registry['code'] ?? '') : normalizeTcInstanceCode($registry['code'] ?? '');
    $directory = $isEgm ? normalizeEgmRegistryDirectory($registry['directory'] ?? '') : normalizeTcRegistryDirectory($registry['directory'] ?? '');
    portabilityAssert($code !== '' && $directory !== '', "{$kind} has an invalid registry record.");
    portabilityAssert(preg_match('/^(?:[A-Za-z]:|\/)/', $directory) !== 1, "{$kind} {$code} registry directory is machine-specific.");
    $tables = $isEgm ? ensureEgmInstanceTables($pdo, $code) : ensureTcInstanceTables($pdo, $code);
    foreach ($tables as $key => $table) {
        $tablePdo = $key === 'activity_logs' ? $logsPdo : $pdo;
        $exists = $isEgm ? egmInstanceTableExists($tablePdo, $table) : tcInstanceTableExists($tablePdo, $table);
        portabilityAssert($exists, "{$kind} {$code} is missing table {$table}.");
    }
    $read = $isEgm ? 'egmInstanceReadData' : 'tcInstanceReadData';
    foreach (['metadata', 'settings', 'periods', 'invitee_mapping', 'database_sync', 'storage_mode'] as $key) {
        portabilityAssert($read($pdo, $code, $key, null) !== null, "{$kind} {$code} is missing portable data key {$key}.");
    }
    $mode = $read($pdo, $code, 'storage_mode', []);
    portabilityAssert(is_array($mode) && ($mode['mode'] ?? '') === 'database_only', "{$kind} {$code} is not database-only.");
    $periods = $read($pdo, $code, 'periods', null);
    portabilityAssert(is_array($periods), "{$kind} {$code} periods are not portable.");
    $metadata = $read($pdo, $code, 'metadata', []);
    portabilityAssert(is_array($metadata) && (string)($metadata['code'] ?? '') === $code, "{$kind} {$code} metadata does not identify its registry record.");

    $missionDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    $panelFile = $isEgm ? 'EGM Panel.php' : 'TC Panel.php';
    portabilityAssert(is_file($missionDir . DIRECTORY_SEPARATOR . $panelFile), "{$kind} {$code} has no executable code shell.");
    $runtimeFiles = $isEgm ? egmInstanceScanRuntimeFiles($missionDir) : tcInstanceScanRuntimeFiles($missionDir);
    portabilityAssert($runtimeFiles === [], "{$kind} {$code} still depends on local runtime state.");

    $documents = portabilityValidateDocuments($pdo, $tables['data'], "{$kind} {$code}");
    $counts = [];
    foreach ($tables as $key => $table) {
        $tablePdo = $key === 'activity_logs' ? $logsPdo : $pdo;
        $counts[$key] = (int)$tablePdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    foreach (['user_periods', 'answers', 'team_members', 'photo_submissions', 'prize_awards', 'login_attempts', 'activity_logs'] as $key) {
        if (!isset($tables[$key]) || !isset($tables['users'])) continue;
        if ($key === 'activity_logs') {
            $userIds = array_fill_keys(array_map('intval', $pdo->query("SELECT `id` FROM `{$tables['users']}`")->fetchAll(PDO::FETCH_COLUMN)), true);
            $orphans = 0;
            $logIds = $logsPdo->query("SELECT DISTINCT `user_id` FROM `{$tables[$key]}` WHERE `user_id` IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($logIds as $userId) if (!isset($userIds[(int)$userId])) $orphans++;
        } else {
            $orphans = (int)$pdo->query(
                "SELECT COUNT(*) FROM `{$tables[$key]}` child LEFT JOIN `{$tables['users']}` parent ON parent.`id`=child.`user_id` "
                . "WHERE child.`user_id` IS NOT NULL AND parent.`id` IS NULL"
            )->fetchColumn();
        }
        portabilityAssert($orphans === 0, "{$kind} {$code} has orphan {$key} history.");
    }
    return ['kind' => $kind, 'code' => $code, 'name' => (string)$registry['name'], 'periods' => count($periods), 'rows' => $counts, 'stored' => $documents];
}

$pdo = connectDatabase(loadConfig($root . '/api/config.php'));
portabilityAssert($pdo instanceof PDO, 'Database connection failed.');
$logsPdo = connectActivityLogDatabase(loadConfig($root . '/api/config.php'));
portabilityAssert($logsPdo instanceof PDO, 'Logs database connection failed.');
$restored = materializeDatabaseBackedInstances($pdo, $root);
portabilityAssert($restored === [], 'Existing deployment unexpectedly needed code-shell restoration.');
$report = [];
foreach (listEgmRegistry($pdo) as $registry) $report[] = portabilityValidateInstance($pdo, $logsPdo, 'EGM', $registry, $root);
foreach (listTcRegistry($pdo) as $registry) $report[] = portabilityValidateInstance($pdo, $logsPdo, 'TC', $registry, $root);
portabilityAssert(count($report) === 5, 'Expected two EGM and three TaskClub database instances.');

if (egmInstanceTableExists($pdo, 'egm_invite_card_routes')) {
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM `egm_invite_card_routes` routes LEFT JOIN `EGM` registry ON registry.`code`=routes.`egm_code` WHERE registry.`code` IS NULL')->fetchColumn();
    portabilityAssert($orphans === 0, 'Invite-card routes reference missing EGM registry records.');
}

echo json_encode(['status' => 'portable', 'instances' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
