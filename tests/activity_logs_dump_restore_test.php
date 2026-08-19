<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/activity-log-storage.php';

if (PHP_SAPI !== 'cli') exit(1);
$dumpFile = (string)($argv[1] ?? '');
if (!is_file($dumpFile)) throw new RuntimeException('Logs dump file was not found.');
$config = activityLogDatabaseConfig(loadConfig(dirname(__DIR__) . '/api/config.php'));
$host = trim((string)($config['host'] ?? '')) ?: 'localhost';
$port = max(1, (int)($config['port'] ?? 3306));
$user = (string)($config['user'] ?? '');
$password = (string)($config['password'] ?? '');
$sourceDatabase = sanitizeDatabaseIdentifier((string)($config['dbname'] ?? ''), 'MCI_logs');
$temporaryDatabase = 'codex_logs_restore_' . bin2hex(random_bytes(5));
if (preg_match('/^codex_logs_restore_[a-f0-9]+$/D', $temporaryDatabase) !== 1) {
    throw new RuntimeException('Unsafe temporary database name.');
}
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$server->exec("CREATE DATABASE `{$temporaryDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
    if (!is_file($mysql)) $mysql = 'mysql';
    $process = proc_open([
        $mysql, '--host=' . $host, '--port=' . $port, '--user=' . $user,
        '--password=' . $password, '--default-character-set=utf8mb4', $temporaryDatabase,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the MySQL restore client.');
    $gzip = gzopen($dumpFile, 'rb');
    if ($gzip === false) throw new RuntimeException('The logs dump is not a readable gzip stream.');
    while (!gzeof($gzip)) {
        $chunk = gzread($gzip, 1024 * 1024);
        if ($chunk === false) throw new RuntimeException('The gzip stream is corrupt.');
        $offset = 0;
        while ($offset < strlen($chunk)) {
            $written = fwrite($pipes[0], substr($chunk, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Restore input stream failed.');
            $offset += $written;
        }
    }
    gzclose($gzip);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) throw new RuntimeException('Logs restore failed: ' . trim((string)$stderr . (string)$stdout));

    $source = new PDO("mysql:host={$host};port={$port};dbname={$sourceDatabase};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $restored = new PDO("mysql:host={$host};port={$port};dbname={$temporaryDatabase};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $source->query(
        "SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema`=" . $source->quote($sourceDatabase)
        . " AND `table_type`='BASE TABLE' ORDER BY `table_name`"
    );
    $counts = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $table = (string)$table;
        $sourceCount = (int)$source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $targetCount = (int)$restored->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($sourceCount !== $targetCount) throw new RuntimeException("Restore count mismatch for {$table}.");
        $counts[$table] = $targetCount;
    }
    echo json_encode([
        'status' => 'restored',
        'tables' => count($counts),
        'rows' => array_sum($counts),
        'counts' => $counts,
        'bytes' => filesize($dumpFile),
        'sha256' => hash_file('sha256', $dumpFile),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$temporaryDatabase}`");
}
