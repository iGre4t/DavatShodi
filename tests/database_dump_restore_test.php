<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/api/lib/common.php';

function dumpRestoreAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param list<string> $command */
function dumpRestoreRun(array $command, ?string $stdinPath = null, ?string $stdoutPath = null): void
{
    $descriptors = [
        0 => $stdinPath !== null ? ['file', $stdinPath, 'rb'] : ['pipe', 'r'],
        1 => $stdoutPath !== null ? ['file', $stdoutPath, 'wb'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes);
    dumpRestoreAssert(is_resource($process), 'Unable to start MySQL portability process.');
    if ($stdinPath === null && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    $stdout = '';
    if ($stdoutPath === null && isset($pipes[1]) && is_resource($pipes[1])) {
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
    $exit = proc_close($process);
    dumpRestoreAssert($exit === 0, 'MySQL portability process failed: ' . trim($stdout . ' ' . $stderr));
}

function dumpRestorePdo(array $config, ?string $database): PDO
{
    $host = trim((string)($config['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
    $port = max(1, (int)($config['port'] ?? 3306));
    $charset = trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
    $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    if ($database !== null) $dsn .= ';dbname=' . $database;
    return new PDO($dsn, (string)($config['user'] ?? ''), (string)($config['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function dumpRestoreTables(PDO $pdo, string $database): array
{
    $statement = $pdo->prepare(
        "SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema`=:schema AND `table_type`='BASE TABLE' ORDER BY `table_name`"
    );
    $statement->execute([':schema' => $database]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<string> $tables
 *  @return array<string,int>
 */
function dumpRestoreCounts(PDO $pdo, array $tables): array
{
    $counts = [];
    foreach ($tables as $table) {
        if (preg_match('/^(?:EGM|TC)(?:_|$)/i', $table) !== 1
            && !in_array(strtolower($table), ['egm_invite_card_routes', 'taskclub_user_activity_logs'], true)) continue;
        $safe = str_replace('`', '``', (string)$table);
        $counts[(string)$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$safe}`")->fetchColumn();
    }
    return $counts;
}

$config = loadConfig($root . '/api/config.php');
$database = trim((string)($config['dbname'] ?? ''));
dumpRestoreAssert($database !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $database) === 1, 'Configured database name is not portable.');
$mysqlBin = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin';
if (!is_dir($mysqlBin)) $mysqlBin = 'C:\\xampp\\mysql\\bin';
$dumpExe = $mysqlBin . DIRECTORY_SEPARATOR . 'mysqldump.exe';
$mysqlExe = $mysqlBin . DIRECTORY_SEPARATOR . 'mysql.exe';
dumpRestoreAssert(is_file($dumpExe) && is_file($mysqlExe), 'XAMPP MySQL dump tools are unavailable.');

$temporaryDatabase = 'codex_portability_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
dumpRestoreAssert(preg_match('/^codex_portability_[A-Za-z0-9_]+$/D', $temporaryDatabase) === 1, 'Unsafe temporary database name.');
$dumpPath = tempnam(sys_get_temp_dir(), 'database-portability-');
dumpRestoreAssert(is_string($dumpPath), 'Unable to allocate temporary dump file.');
$server = null;

try {
    $host = trim((string)($config['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
    $port = (string)max(1, (int)($config['port'] ?? 3306));
    $user = (string)($config['user'] ?? '');
    $password = (string)($config['password'] ?? '');
    $connectionArgs = ["--host={$host}", "--port={$port}", "--user={$user}"];
    if ($password !== '') $connectionArgs[] = "--password={$password}";

    dumpRestoreRun(array_merge([$dumpExe], $connectionArgs, [
        '--single-transaction', '--quick', '--routines', '--triggers', '--events',
        '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4', $database,
    ]), null, $dumpPath);
    dumpRestoreAssert(filesize($dumpPath) > 1024, 'Database dump is unexpectedly empty.');

    $server = dumpRestorePdo($config, null);
    $server->exec("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    dumpRestoreRun(array_merge([$mysqlExe], $connectionArgs, ['--default-character-set=utf8mb4', $temporaryDatabase]), $dumpPath, null);

    $source = dumpRestorePdo($config, $database);
    $restored = dumpRestorePdo($config, $temporaryDatabase);
    $sourceTables = dumpRestoreTables($source, $database);
    $restoredTables = dumpRestoreTables($restored, $temporaryDatabase);
    dumpRestoreAssert($sourceTables === $restoredTables, 'Restored database table inventory differs from the source dump.');
    $sourceCounts = dumpRestoreCounts($source, $sourceTables);
    $restoredCounts = dumpRestoreCounts($restored, $restoredTables);
    dumpRestoreAssert($sourceCounts === $restoredCounts, 'Restored database table or row counts differ from the source dump.');
    $lowerTables = array_map('strtolower', $restoredTables);
    foreach (['EGM', 'EGM_sequence', 'TC', 'TC_sequence'] as $requiredTable) {
        dumpRestoreAssert(in_array(strtolower($requiredTable), $lowerTables, true), "Restored database is missing {$requiredTable}.");
    }
    $dynamicTables = array_filter(array_keys($restoredCounts), static fn(string $table): bool => preg_match('/^(?:EGM|TC)_[0-9]+(?:_|$)/i', $table) === 1);
    dumpRestoreAssert(count($dynamicTables) >= 50, 'Restored database is missing per-instance history tables.');

    echo json_encode([
        'status' => 'dump_restore_verified',
        'tables' => count($restoredTables),
        'dynamic_instance_tables' => count($dynamicTables),
        'rows' => array_sum($restoredCounts),
        'dump_bytes' => filesize($dumpPath),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    if ($server instanceof PDO && preg_match('/^codex_portability_[A-Za-z0-9_]+$/D', $temporaryDatabase) === 1) {
        $server->exec("DROP DATABASE IF EXISTS `{$temporaryDatabase}`");
    }
    if (is_file($dumpPath)) unlink($dumpPath);
}
