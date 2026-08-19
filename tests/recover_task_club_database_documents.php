<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require_once $root . '/api/lib/common.php';
require_once $root . '/api/lib/tc-registry.php';
require_once $root . '/api/lib/tc-instance-storage.php';
require_once $root . '/api/lib/tc-database-runtime.php';

$backupDir = dirname($root) . DIRECTORY_SEPARATOR . 'DavatShodi-backups';
$archives = glob($backupDir . DIRECTORY_SEPARATOR . 'taskclub-runtime-before-db-only-*.zip') ?: [];
usort($archives, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
$archivePath = (string)($archives[0] ?? '');
if ($archivePath === '' || !is_file($archivePath)) throw new RuntimeException('TaskClub recovery archive was not found.');

$extractedRoot = trim((string)($argv[1] ?? ''));
if ($extractedRoot === '' || !is_dir($extractedRoot)) {
    throw new RuntimeException('Pass the verified extracted recovery directory as the first argument.');
}
$pdo = connectDatabase(loadConfig($root . '/api/config.php'));
if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable.');
$registries = [];
foreach (listTcRegistry($pdo) as $registry) {
    $directory = normalizeTcRegistryDirectory($registry['directory'] ?? '');
    if ($directory !== '') $registries[$directory] = $registry;
}
$report = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractedRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $item) {
    if (!$item->isFile()) continue;
    $entry = trim(str_replace('\\', '/', substr($item->getPathname(), strlen(rtrim($extractedRoot, DIRECTORY_SEPARATOR)) + 1)), '/');
    if ($entry === '') continue;
    foreach ($registries as $directory => $registry) {
        $prefix = trim($directory, '/') . '/';
        if (!str_starts_with($entry, $prefix)) continue;
        $relative = tcInstanceNormalizeRuntimeRelativePath(substr($entry, strlen($prefix)));
        if ($relative === '' || !tcInstanceIsManagedRuntimeFile($relative)) break;
        $missionDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        $path = $missionDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (tcDatabaseRuntimeRead($path) !== null) break;
        $content = file_get_contents($item->getPathname());
        if (!is_string($content)) throw new RuntimeException('Unable to read archived document ' . $entry);
        if (!tcDatabaseRuntimeWrite($path, $content, (int)$item->getMTime())) {
            throw new RuntimeException('Unable to restore database document ' . $entry);
        }
        $database = tcDatabaseRuntimeRead($path);
        if (!is_array($database)) throw new RuntimeException('Restored document is unreadable: ' . $entry);
        $report[(string)$registry['code']][] = $relative;
        break;
    }
}
echo json_encode(['archive' => $archivePath, 'restored' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
if (($argv[2] ?? '') === '--cleanup') {
    $resolvedCleanup = realpath($extractedRoot);
    $allowedPrefix = str_replace('\\', '/', realpath('C:/xampp/tmp') ?: 'C:/xampp/tmp') . '/tc-recovery-';
    $normalizedCleanup = is_string($resolvedCleanup) ? str_replace('\\', '/', $resolvedCleanup) : '';
    if ($normalizedCleanup === '' || !str_starts_with(strtolower($normalizedCleanup), strtolower($allowedPrefix))) {
        throw new RuntimeException('Refusing unexpected recovery cleanup target.');
    }
    $cleanup = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedCleanup, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($cleanup as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($resolvedCleanup);
}
