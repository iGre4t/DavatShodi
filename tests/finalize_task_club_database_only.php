<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/lib/common.php';
require_once $root . '/api/lib/tc-registry.php';
require_once $root . '/api/lib/tc-instance-storage.php';
require_once $root . '/api/lib/tc-database-runtime.php';

$pdo = connectDatabase(loadConfig($root . '/api/config.php'));
if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable.');
$report = [];
foreach (listTcRegistry($pdo) as $registry) {
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    if ($directory === '') continue;
    $missionDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
    if (!is_dir($missionDir)) continue;
    $stored = 0;
    $deleted = 0;
    $metadataPath = $missionDir . DIRECTORY_SEPARATOR . 'mission.json';
    if (is_file($metadataPath)) {
        $content = file_get_contents($metadataPath);
        if (!is_string($content) || !tcDatabaseRuntimeWrite($metadataPath, $content, (int)filemtime($metadataPath))) {
            throw new RuntimeException('Unable to store mission metadata for ' . $directory);
        }
        $database = tcDatabaseRuntimeRead($metadataPath);
        if (!is_array($database) || hash('sha256', $content) !== hash('sha256', (string)$database['content'])) {
            throw new RuntimeException('Mission metadata verification failed for ' . $directory);
        }
        if (!unlink($metadataPath)) throw new RuntimeException('Unable to remove local mission metadata for ' . $directory);
        $stored++;
        $deleted++;
    }
    foreach (glob($missionDir . DIRECTORY_SEPARATOR . '*.lock') ?: [] as $lockPath) {
        $base = substr($lockPath, 0, -5);
        if (tcDatabaseRuntimeContextForPath($base) === null) continue;
        if (!unlink($lockPath)) throw new RuntimeException('Unable to remove local database lock sidecar: ' . $lockPath);
        $deleted++;
    }
    $report[(string)$registry['code']] = ['metadata_stored' => $stored, 'local_files_deleted' => $deleted];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
