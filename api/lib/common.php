<?php
declare(strict_types=1);

function getDatabaseConfigOverridesPath(): string
{
    return __DIR__ . '/../data/db-config.json';
}

function getDatabaseConfigAllowedKeys(): array
{
    return ['host', 'port', 'dbname', 'user', 'password', 'table', 'record'];
}

function loadDatabaseConfigOverrides(): array
{
    $path = getDatabaseConfigOverridesPath();
    if (!is_file($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function mergeDatabaseConfigOverrides(array $config): array
{
    $overrides = loadDatabaseConfigOverrides();
    if (empty($overrides)) {
        return $config;
    }
    $allowed = array_fill_keys(getDatabaseConfigAllowedKeys(), true);
    $filtered = array_intersect_key($overrides, $allowed);
    if (array_key_exists('port', $filtered)) {
        $filtered['port'] = (int)$filtered['port'];
    }
    return array_merge($config, $filtered);
}

function sanitizeDatabaseIdentifier(string $value, string $fallback): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $value) ?? '';
    return $clean !== '' ? $clean : $fallback;
}

function sanitizeDatabaseConfigPayload(array $payload): array
{
    $result = [];
    foreach (getDatabaseConfigAllowedKeys() as $key) {
        if ($key === 'port') {
            $port = (int)($payload['port'] ?? 0);
            $result['port'] = $port > 0 ? $port : 3306;
            continue;
        }
        $value = $payload[$key] ?? '';
        $trimmed = trim((string)$value);
        if ($key === 'dbname') {
            $result['dbname'] = sanitizeDatabaseIdentifier($trimmed, 'great_panel');
            continue;
        }
        if ($key === 'table') {
            $result['table'] = sanitizeDatabaseIdentifier($trimmed, 'great_panel_store');
            continue;
        }
        if ($key === 'record') {
            $result['record'] = sanitizeDatabaseIdentifier($trimmed, 'store');
            continue;
        }
        $result[$key] = $trimmed;
    }
    return $result;
}

function persistDatabaseConfigOverrides(array $payload): bool
{
    $sanitized = sanitizeDatabaseConfigPayload($payload);
    $path = getDatabaseConfigOverridesPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }
    $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($path, $encoded) !== false;
}

function getDatabaseConfigForResponse(array $config): array
{
    $result = [];
    foreach (getDatabaseConfigAllowedKeys() as $key) {
        $value = $config[$key] ?? '';
        if ($key === 'port') {
            $result['port'] = (int)$value;
            continue;
        }
        $result[$key] = trim((string)$value);
    }
    return $result;
}

function getInstallLockFilePath(): string
{
    return __DIR__ . '/../data/install.lock';
}

function isInstallComplete(): bool
{
    return is_file(getInstallLockFilePath());
}

function markInstallComplete(): bool
{
    $path = getInstallLockFilePath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }
    $payload = json_encode(['installed_at' => date('c')], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }
    return file_put_contents($path, $payload) !== false;
}

function databaseConfigIsComplete(): bool
{
    $config = loadDatabaseConfigOverrides();
    $required = ['host', 'dbname', 'user', 'table', 'record'];
    foreach ($required as $key) {
        if (!isset($config[$key]) || trim((string)$config[$key]) === '') {
            return false;
        }
    }
    return true;
}

function databaseConfigRequiresInstallation(): bool
{
    return !isInstallComplete() && !databaseConfigIsComplete();
}

function loadConfig(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $config = include $path;
    $config = is_array($config) ? $config : [];
    return mergeDatabaseConfigOverrides($config);
}

function connectDatabase(array $config): ?PDO
{
    $host = $config['host'] ?? '';
    $dbname = $config['dbname'] ?? '';
    if ($host === '' || $dbname === '') {
        return null;
    }
    $port = (int)($config['port'] ?? 3306);
    $charset = $config['charset'] ?? 'utf8mb4';
    $user = $config['user'] ?? '';
    $password = $config['password'] ?? '';

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        $pdo = new PDO($dsn, $user, $password, $options);
        ensureStoreTable($pdo, resolveTableName($config));
        return $pdo;
    } catch (PDOException $e) {
        error_log('MySQL connection failed: ' . $e->getMessage());
        return null;
    }
}

function ensureStoreTable(PDO $pdo, string $tableName): void
{
    if ($tableName === '') {
        return;
    }
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `$tableName` (
  `id` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $pdo->exec($sql);
}

function resolveTableName(array $config): string
{
    $table = trim((string)($config['table'] ?? 'app_store'));
    $safe = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    return $safe ?: 'app_store';
}

function resolveRecordId(array $config): string
{
    $record = trim((string)($config['record'] ?? 'store'));
    return $record === '' ? 'store' : $record;
}

function loadDataFromDb(PDO $pdo, array $config): ?array
{
    $table = resolveTableName($config);
    if ($table === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT `payload` FROM `$table` WHERE `id` = :id LIMIT 1");
    $stmt->execute([':id' => resolveRecordId($config)]);
    $payload = $stmt->fetchColumn();
    if ($payload === false) {
        return null;
    }
    $decoded = json_decode((string)$payload, true);
    return is_array($decoded) ? $decoded : null;
}

function saveDataToDb(PDO $pdo, array $data, array $config): bool
{
    $table = resolveTableName($config);
    if ($table === '') {
        return false;
    }
    $stmt = $pdo->prepare("
        INSERT INTO `$table` (`id`, `payload`)
        VALUES (:id, :payload)
        ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `updated_at` = CURRENT_TIMESTAMP
    ");
    return $stmt->execute([
        ':id' => resolveRecordId($config),
        ':payload' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ]);
}

function getBackupDirectory(): string
{
    return __DIR__ . '/../data/backups';
}

function getBackupMetadataPath(): string
{
    return getBackupDirectory() . '/backups.json';
}

function loadBackupMetadata(): array
{
    $path = getBackupMetadataPath();
    if (!is_file($path)) {
        return [];
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values($decoded);
}

function saveBackupMetadata(array $metadata): bool
{
    $path = getBackupMetadataPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }
    $encoded = json_encode(array_values($metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }
    return file_put_contents($path, $encoded) !== false;
}

function deleteBackupFile(string $filename): void
{
    if ($filename === '') {
        return;
    }
    $path = getBackupDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function recordBackupPayload(string $content, string $type = 'manual', int $autoLimit = 0, string $extension = 'json'): ?array
{
    $directory = getBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $label = $type === 'auto' ? 'auto' : 'manual';
    $ext = preg_replace('/[^a-z0-9]+/i', '', $extension) ?: 'json';
    $filename = sprintf('backup-%s-%s.%s', $now->format('YmdHis'), $label, $ext);
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($path, $content) === false) {
        return null;
    }
    $entry = [
        'type' => $type,
        'filename' => $filename,
        'created_at' => $now->format(DATE_ATOM),
        'size' => filesize($path) ?: 0,
        'mime' => $ext === 'zip' ? 'application/zip' : 'application/json'
    ];
    $metadata = loadBackupMetadata();
    $metadata[] = $entry;
    if ($type === 'auto' && $autoLimit > 0) {
        $metadata = enforceAutoBackupLimit($metadata, $autoLimit);
    }
    if (!saveBackupMetadata($metadata)) {
        return $entry;
    }
    return $entry;
}

function enforceAutoBackupLimit(array $metadata, int $limit): array
{
    if ($limit <= 0) {
        return $metadata;
    }
    $autos = array_filter($metadata, function ($entry) {
        return isset($entry['type']) && $entry['type'] === 'auto';
    });
    if (count($autos) <= $limit) {
        return $metadata;
    }
    usort($autos, function ($a, $b) {
        $aTime = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $bTime = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $aTime <=> $bTime;
    });
    $excess = array_slice($autos, 0, count($autos) - $limit);
    $filenames = array_map(static fn ($entry) => $entry['filename'] ?? '', $excess);
    $metadata = array_values(array_filter($metadata, function ($entry) use ($filenames) {
        return !in_array($entry['filename'] ?? '', $filenames, true);
    }));
    foreach ($filenames as $filename) {
        deleteBackupFile($filename);
    }
    return $metadata;
}

function sanitizeBackupFilename(string $filename): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '', basename($filename)) ?? '';
    return $clean;
}

function getBackupFilePath(string $filename): string
{
    $safe = sanitizeBackupFilename($filename);
    if ($safe === '') {
        return '';
    }
    return getBackupDirectory() . DIRECTORY_SEPARATOR . $safe;
}

function deleteBackupMetadataEntry(string $filename): void
{
    $safe = sanitizeBackupFilename($filename);
    if ($safe === '') {
        return;
    }
    $metadata = loadBackupMetadata();
    $filtered = array_values(array_filter($metadata, function ($entry) use ($safe) {
        return ($entry['filename'] ?? '') !== $safe;
    }));
    saveBackupMetadata($filtered);
}
