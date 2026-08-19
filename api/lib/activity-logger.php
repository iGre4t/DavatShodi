<?php
declare(strict_types=1);

require_once __DIR__ . '/activity-log-storage.php';

const ACTIVITY_LOGGER_CHANNEL_PANEL = 'panel';
const ACTIVITY_LOGGER_AUDIT_TABLES = [
    ACTIVITY_LOGGER_CHANNEL_PANEL => 'panel_user_activity_logs'
];

function activityLoggerProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

function activityLoggerNormalizeChannel(string $channel): string
{
    return ACTIVITY_LOGGER_CHANNEL_PANEL;
}

function activityLoggerBaseDirectory(string $channel = ACTIVITY_LOGGER_CHANNEL_PANEL): string
{
    return activityLoggerProjectRoot()
        . DIRECTORY_SEPARATOR . 'system'
        . DIRECTORY_SEPARATOR . 'useractivitylogs';
}

function activityLoggerLogDirectory(string $channel = ACTIVITY_LOGGER_CHANNEL_PANEL): string
{
    return activityLoggerBaseDirectory($channel) . DIRECTORY_SEPARATOR . 'logs';
}

function panelActivityLogDirectory(): string
{
    return activityLoggerLogDirectory(ACTIVITY_LOGGER_CHANNEL_PANEL);
}

function activityLoggerNormalizeNullableString($value): ?string
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

function activityLoggerCurrentSessionId(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $sessionId = session_id();
    return $sessionId !== '' ? $sessionId : null;
}

function activityLoggerCurrentUserId(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $user = $_SESSION['user'] ?? null;
    if (is_array($user)) {
        foreach (['code', 'id', 'user_id', 'username'] as $key) {
            $value = activityLoggerNormalizeNullableString($user[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
    }
    return null;
}

function activityLoggerClientIpAddress(): ?string
{
    $remoteAddr = activityLoggerNormalizeNullableString($_SERVER['REMOTE_ADDR'] ?? null);
    if ($remoteAddr !== null) {
        return $remoteAddr;
    }
    $forwardedFor = activityLoggerNormalizeNullableString($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);
    if ($forwardedFor === null) {
        return null;
    }
    $parts = array_map('trim', explode(',', $forwardedFor));
    return $parts[0] ?? null;
}

function activityLoggerUserAgent(): ?string
{
    $userAgent = activityLoggerNormalizeNullableString($_SERVER['HTTP_USER_AGENT'] ?? null);
    if ($userAgent === null) {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($userAgent, 0, 512, 'UTF-8');
    }
    return substr($userAgent, 0, 512);
}

function activityLoggerNormalizeLogValue($value, int $depth = 0)
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
                $normalized[$key] = activityLoggerNormalizeLogValue($item, $depth + 1);
            }
        }
        return $normalized;
    }
    if (is_object($value)) {
        return activityLoggerNormalizeLogValue(get_object_vars($value), $depth + 1);
    }
    return (string)gettype($value);
}

function activityLoggerTimestamp($timestamp = null): DateTimeImmutable
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
            error_log('Activity logger timestamp parse failed: ' . $err->getMessage());
        }
    }
    return new DateTimeImmutable('now');
}

function activityLoggerBuildLogEntry(array $event): array
{
    $timestamp = activityLoggerTimestamp($event['timestamp'] ?? null);
    $level = strtolower((string)($event['level'] ?? 'info'));
    if (!in_array($level, ['info', 'warning', 'error', 'critical'], true)) {
        $level = 'info';
    }
    $action = activityLoggerNormalizeNullableString($event['action'] ?? null) ?? 'unspecified';
    $status = activityLoggerNormalizeNullableString($event['status'] ?? null) ?? 'success';
    $metadata = $event['metadata'] ?? [];
    if (!is_array($metadata)) {
        $metadata = ['value' => $metadata];
    }

    return [
        'timestamp' => $timestamp->format(DATE_ATOM),
        'level' => $level,
        'user_id' => activityLoggerNormalizeNullableString($event['user_id'] ?? $event['userId'] ?? null) ?? activityLoggerCurrentUserId(),
        'session_id' => activityLoggerNormalizeNullableString($event['session_id'] ?? $event['sessionId'] ?? null) ?? activityLoggerCurrentSessionId(),
        'action' => $action,
        'entity_type' => activityLoggerNormalizeNullableString($event['entity_type'] ?? $event['entityType'] ?? null),
        'entity_id' => activityLoggerNormalizeNullableString($event['entity_id'] ?? $event['entityId'] ?? null),
        'ip_address' => activityLoggerNormalizeNullableString($event['ip_address'] ?? $event['ip'] ?? null) ?? activityLoggerClientIpAddress(),
        'user_agent' => activityLoggerNormalizeNullableString($event['user_agent'] ?? $event['userAgent'] ?? null) ?? activityLoggerUserAgent(),
        'status' => $status,
        'message' => activityLoggerNormalizeNullableString($event['message'] ?? null),
        'metadata' => activityLoggerNormalizeLogValue($metadata)
    ];
}

function activityLoggerAuditTableName(string $channel): string
{
    return ACTIVITY_LOGGER_AUDIT_TABLES[ACTIVITY_LOGGER_CHANNEL_PANEL];
}

function activityLoggerLogFilePath(array $entry, string $channel): string
{
    $timestamp = activityLoggerTimestamp((string)($entry['timestamp'] ?? ''));
    return activityLoggerLogDirectory($channel) . DIRECTORY_SEPARATOR . $timestamp->format('Y-m-d') . '.log';
}

function activityLoggerWriteJsonLine(array $entry, string $channel): bool
{
    try {
        $config = loadConfig(activityLoggerProjectRoot() . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php');
        $logsPdo = connectActivityLogDatabase($config);
        return $logsPdo instanceof PDO && activityLoggerWriteAuditLog($logsPdo, $entry, $channel);
    } catch (Throwable $err) {
        error_log('Activity logger file write failed: ' . $err->getMessage());
        return false;
    }
}

function activityLoggerEnsureAuditTable(PDO $pdo, string $channel): bool
{
    static $ready = [];
    $table = activityLoggerAuditTableName($channel);
    $cacheKey = spl_object_id($pdo) . ':' . $table;
    if (!empty($ready[$cacheKey])) {
        return true;
    }
    try {
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
        $ready[$cacheKey] = true;
        return true;
    } catch (Throwable $err) {
        error_log('Activity logger failed to ensure audit table: ' . $err->getMessage());
        return false;
    }
}

function activityLoggerWriteAuditLog(PDO $pdo, array $entry, string $channel): bool
{
    try {
        if (!activityLoggerEnsureAuditTable($pdo, $channel)) {
            return false;
        }
        $timestamp = activityLoggerTimestamp((string)($entry['timestamp'] ?? ''))->format('Y-m-d H:i:s');
        $metadataJson = json_encode($entry['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }
        $table = activityLoggerAuditTableName($channel);
        $stmt = $pdo->prepare("
            INSERT INTO `$table`
              (`timestamp`, `level`, `user_id`, `session_id`, `action`, `entity_type`, `entity_id`, `ip_address`, `user_agent`, `status`, `message`, `metadata_json`)
            VALUES
              (:timestamp, :level, :user_id, :session_id, :action, :entity_type, :entity_id, :ip_address, :user_agent, :status, :message, :metadata_json)
        ");
        return $stmt->execute([
            ':timestamp' => $timestamp,
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
        error_log('Activity logger DB write failed: ' . $err->getMessage());
        return false;
    }
}

function logUserActivity(array $event, ?PDO $pdo = null): bool
{
    try {
        $channel = activityLoggerNormalizeChannel((string)($event['channel'] ?? ACTIVITY_LOGGER_CHANNEL_PANEL));
        $entry = activityLoggerBuildLogEntry($event);
        return activityLoggerWriteJsonLine($entry, $channel);
    } catch (Throwable $err) {
        error_log('Activity logger failed: ' . $err->getMessage());
        return false;
    }
}

function panelLogUserActivity(array $event, ?PDO $pdo = null): bool
{
    $event['channel'] = ACTIVITY_LOGGER_CHANNEL_PANEL;
    return logUserActivity($event, $pdo);
}
