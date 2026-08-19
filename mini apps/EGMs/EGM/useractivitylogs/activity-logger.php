<?php
declare(strict_types=1);


require_once dirname(__DIR__) . '/egm-database-runtime.php';
const EGM_ACTIVITY_AUDIT_TABLE = 'taskclub_user_activity_logs';

function egmActivityBaseDirectory(): string
{
    return __DIR__;
}

function egmActivityLogDirectory(): string
{
    return egmActivityBaseDirectory() . DIRECTORY_SEPARATOR . 'logs';
}

function egmActivityNormalizeNullableString($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value)) {
        $normalized = trim((string)$value);
        return $normalized === '' ? null : $normalized;
    }
    return null;
}

function egmActivityCurrentSessionId(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $sessionId = session_id();
    return $sessionId !== '' ? $sessionId : null;
}

function egmActivityCurrentUserId(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $workId = egmActivityNormalizeNullableString($_SESSION['egm_work_id'] ?? null);
    if ($workId !== null) {
        return $workId;
    }
    $user = $_SESSION['user'] ?? null;
    if (is_array($user)) {
        foreach (['code', 'id', 'user_id', 'username'] as $key) {
            $value = egmActivityNormalizeNullableString($user[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
    }
    return null;
}

function egmActivityClientIpAddress(): ?string
{
    $remoteAddr = egmActivityNormalizeNullableString($_SERVER['REMOTE_ADDR'] ?? null);
    if ($remoteAddr !== null) {
        return $remoteAddr;
    }
    $forwardedFor = egmActivityNormalizeNullableString($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);
    if ($forwardedFor === null) {
        return null;
    }
    $parts = array_map('trim', explode(',', $forwardedFor));
    return $parts[0] ?? null;
}

function egmActivityUserAgent(): ?string
{
    $userAgent = egmActivityNormalizeNullableString($_SERVER['HTTP_USER_AGENT'] ?? null);
    if ($userAgent === null) {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($userAgent, 0, 512, 'UTF-8');
    }
    return substr($userAgent, 0, 512);
}

function egmActivityNormalizeLogValue($value, int $depth = 0)
{
    if ($depth > 8) {
        return '[max_depth]';
    }
    if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format(DATE_ATOM);
    }
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_int($key) || is_string($key)) {
                $normalized[$key] = egmActivityNormalizeLogValue($item, $depth + 1);
            }
        }
        return $normalized;
    }
    if (is_object($value)) {
        return egmActivityNormalizeLogValue(get_object_vars($value), $depth + 1);
    }
    return (string)gettype($value);
}

function egmActivityTimestamp($timestamp = null): DateTimeImmutable
{
    if ($timestamp instanceof DateTimeImmutable) {
        return $timestamp;
    }
    if ($timestamp instanceof DateTimeInterface) {
        return new DateTimeImmutable($timestamp->format(DATE_ATOM));
    }
    if (is_string($timestamp) && trim($timestamp) !== '') {
        try {
            return new DateTimeImmutable($timestamp);
        } catch (Throwable $err) {
            error_log('Event Guest Manager activity timestamp parse failed: ' . $err->getMessage());
        }
    }
    return new DateTimeImmutable('now');
}

function egmActivityBuildLogEntry(array $event): array
{
    $timestamp = egmActivityTimestamp($event['timestamp'] ?? null);
    $level = strtolower((string)($event['level'] ?? 'info'));
    if (!in_array($level, ['info', 'warning', 'error', 'critical'], true)) {
        $level = 'info';
    }
    $metadata = $event['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = ['value' => $metadata];
    }

    return [
        'timestamp' => $timestamp->format(DATE_ATOM),
        'level' => $level,
        'user_id' => egmActivityNormalizeNullableString($event['user_id'] ?? $event['userId'] ?? null) ?? egmActivityCurrentUserId(),
        'session_id' => egmActivityNormalizeNullableString($event['session_id'] ?? $event['sessionId'] ?? null) ?? egmActivityCurrentSessionId(),
        'action' => egmActivityNormalizeNullableString($event['action'] ?? null) ?? 'taskclub.unspecified',
        'entity_type' => egmActivityNormalizeNullableString($event['entity_type'] ?? $event['entityType'] ?? null),
        'entity_id' => egmActivityNormalizeNullableString($event['entity_id'] ?? $event['entityId'] ?? null),
        'ip_address' => egmActivityNormalizeNullableString($event['ip_address'] ?? $event['ip'] ?? null) ?? egmActivityClientIpAddress(),
        'user_agent' => egmActivityNormalizeNullableString($event['user_agent'] ?? $event['userAgent'] ?? null) ?? egmActivityUserAgent(),
        'status' => egmActivityNormalizeNullableString($event['status'] ?? null) ?? 'success',
        'message' => egmActivityNormalizeNullableString($event['message'] ?? null),
        'metadata' => egmActivityNormalizeLogValue($metadata)
    ];
}

function egmActivityLogFilePath(array $entry): string
{
    $timestamp = egmActivityTimestamp((string)($entry['timestamp'] ?? ''));
    return egmActivityLogDirectory() . DIRECTORY_SEPARATOR . $timestamp->format('Y-m-d') . '.log';
}

function egmActivityWriteJsonLine(array $entry): bool
{
    try {
        return egmDatabaseRuntimeAppendActivity(dirname(__DIR__), $entry);
    } catch (Throwable $err) {
        error_log('Event Guest Manager activity logger database write failed: ' . $err->getMessage());
        return false;
    }
}

function egmActivityProjectRoot(): string
{
    return dirname(__DIR__, 3);
}

function egmActivityLoadJsonFile(string $path): array
{
    if (!egmDbIsFile($path)) {
        return [];
    }
    $content = egmDbFileGetContents($path);
    if (!is_string($content) || trim($content) === '') {
        return [];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function egmActivityLoadDatabaseConfig(): array
{
    $config = [];
    $configFile = egmActivityProjectRoot() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php';
    if (egmDbIsFile($configFile)) {
        $loaded = include $configFile;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }
    $overrideFile = egmActivityProjectRoot() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'db-config.json';
    $overrides = egmActivityLoadJsonFile($overrideFile);
    if ($overrides) {
        $allowed = array_fill_keys(['host', 'port', 'dbname', 'user', 'password', 'charset', 'logs_host', 'logs_port', 'logs_dbname', 'logs_user', 'logs_password'], true);
        $config = array_merge($config, array_intersect_key($overrides, $allowed));
    }
    return $config;
}

function egmActivityPdo(): ?PDO
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo instanceof PDO ? $pdo : null;
    }
    $config = egmActivityLoadDatabaseConfig();
    try {
        $pdo = connectActivityLogDatabase($config);
        return $pdo;
    } catch (Throwable $err) {
        error_log('Event Guest Manager activity logger DB connection failed: ' . $err->getMessage());
        $pdo = null;
        return null;
    }
}

function egmActivityEnsureAuditTable(PDO $pdo): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    try {
        $table = EGM_ACTIVITY_AUDIT_TABLE;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `$table` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `timestamp` DATETIME NOT NULL,
              `level` VARCHAR(16) NOT NULL,
              `user_id` VARCHAR(64) NULL,
              `session_id` VARCHAR(128) NULL,
              `action` VARCHAR(128) NOT NULL,
              `entity_type` VARCHAR(64) NULL,
              `entity_id` VARCHAR(128) NULL,
              `ip_address` VARCHAR(45) NULL,
              `user_agent` VARCHAR(512) NULL,
              `status` VARCHAR(32) NOT NULL,
              `message` TEXT NULL,
              `metadata_json` LONGTEXT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_activity_timestamp` (`timestamp`),
              KEY `idx_activity_user_action` (`user_id`, `action`),
              KEY `idx_activity_entity` (`entity_type`, `entity_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ready = true;
        return true;
    } catch (Throwable $err) {
        error_log('Event Guest Manager activity logger failed to ensure audit table: ' . $err->getMessage());
        return false;
    }
}

function egmActivityWriteAuditLog(PDO $pdo, array $entry): bool
{
    try {
        if (!egmActivityEnsureAuditTable($pdo)) {
            return false;
        }
        $metadataJson = json_encode($entry['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }
        $table = EGM_ACTIVITY_AUDIT_TABLE;
        $stmt = $pdo->prepare("
            INSERT INTO `$table`
              (`timestamp`, `level`, `user_id`, `session_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `status`, `message`, `metadata_json`)
            VALUES
              (:timestamp, :level, :user_id, :session_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :status, :message, :metadata_json)
        ");
        return $stmt->execute([
            ':timestamp' => egmActivityTimestamp((string)($entry['timestamp'] ?? ''))->format('Y-m-d H:i:s'),
            ':level' => $entry['level'],
            ':user_id' => $entry['user_id'],
            ':session_id' => $entry['session_id'],
            ':action' => $entry['action'],
            ':entity_type' => $entry['entity_type'],
            ':entity_id' => $entry['entity_id'],
            ':ip_address' => $entry['ip_address'],
            ':user_agent' => $entry['user_agent'],
            ':status' => $entry['status'],
            ':message' => $entry['message'],
            ':metadata_json' => $metadataJson
        ]);
    } catch (Throwable $err) {
        error_log('Event Guest Manager activity logger DB write failed: ' . $err->getMessage());
        return false;
    }
}

function egmActivityLogUserActivity(array $event, ?PDO $pdo = null): bool
{
    try {
        $entry = egmActivityBuildLogEntry($event);
        $fileWritten = egmActivityWriteJsonLine($entry);
        if (!empty($event['audit'])) {
            $auditPdo = $pdo instanceof PDO ? $pdo : egmActivityPdo();
            if ($auditPdo instanceof PDO) {
                egmActivityWriteAuditLog($auditPdo, $entry);
            }
        }
        return $fileWritten;
    } catch (Throwable $err) {
        error_log('Event Guest Manager activity logger failed: ' . $err->getMessage());
        return false;
    }
}
