<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/activity-log-storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$target = trim((string)($argv[1] ?? ''));
if ($target === '') {
    fwrite(STDERR, "Usage: php tests/export_activity_logs_database.php <target.sql.gz>\n");
    exit(1);
}
$targetDirectory = dirname($target);
if (!is_dir($targetDirectory)) {
    fwrite(STDERR, "Target directory does not exist: {$targetDirectory}\n");
    exit(1);
}
$tables = array_values(array_filter(array_map('trim', array_slice($argv, 2)), static fn(string $table): bool => $table !== ''));
foreach ($tables as $table) {
    if (preg_match('/^[a-zA-Z0-9_]+$/D', $table) !== 1) {
        throw new InvalidArgumentException('Unsafe table name: ' . $table);
    }
}

$config = activityLogDatabaseConfig(loadConfig(dirname(__DIR__) . '/api/config.php'));
$database = sanitizeDatabaseIdentifier((string)($config['dbname'] ?? ''), 'MCI_logs');
$dump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
if (!is_file($dump)) $dump = 'mysqldump';
$command = [
    $dump,
    '--host=' . (trim((string)($config['host'] ?? '')) ?: 'localhost'),
    '--port=' . max(1, (int)($config['port'] ?? 3306)),
    '--user=' . (string)($config['user'] ?? ''),
    '--password=' . (string)($config['password'] ?? ''),
    '--default-character-set=utf8mb4',
    '--single-transaction',
    '--quick',
    '--skip-lock-tables',
    '--skip-comments',
    $database,
];
array_push($command, ...$tables);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
if (!is_resource($process)) throw new RuntimeException('Unable to start mysqldump.');
fclose($pipes[0]);
$gzip = gzopen($target, 'wb9');
if ($gzip === false) {
    proc_terminate($process);
    throw new RuntimeException('Unable to create the compressed logs dump.');
}
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1024 * 1024);
    if ($chunk === false) break;
    if ($chunk !== '' && gzwrite($gzip, $chunk) === false) {
        throw new RuntimeException('Failed while compressing the logs dump.');
    }
}
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
gzclose($gzip);
$exitCode = proc_close($process);
if ($exitCode !== 0) {
    @unlink($target);
    throw new RuntimeException('mysqldump failed: ' . trim((string)$stderr));
}
echo json_encode([
    'database' => $database,
    'file' => realpath($target) ?: $target,
    'bytes' => filesize($target),
    'sha256' => hash_file('sha256', $target),
    'tables' => $tables ?: ['*'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
