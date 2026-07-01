<?php
declare(strict_types=1);

require_once __DIR__ . '/campaign-redirects.php';

const UTM_BRANCH_VISITOR_COOKIE = 'utm_visitor_id';
const UTM_BRANCH_SESSION_COOKIE = 'utm_session_id';
const UTM_BRANCH_VISITOR_TTL = 31536000;
const UTM_BRANCH_SESSION_TTL = 1800;

function utmBranchesStoragePath(): string
{
    return utmBranchesDataDirectory() . DIRECTORY_SEPARATOR . 'utm-branches.json';
}

function utmBranchesLogDirectory(): string
{
    return utmBranchesDataDirectory() . DIRECTORY_SEPARATOR . 'logs';
}

function utmBranchesModuleDirectory(): string
{
    return dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'modules'
        . DIRECTORY_SEPARATOR . 'minor'
        . DIRECTORY_SEPARATOR . 'UTM';
}

function utmBranchesDataDirectory(): string
{
    return utmBranchesModuleDirectory() . DIRECTORY_SEPARATOR . 'data';
}

function utmBranchesLegacyStoragePath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'campaigns' . DIRECTORY_SEPARATOR . 'utm-branches.json';
}

function utmBranchesLegacyLogDirectory(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'campaigns' . DIRECTORY_SEPARATOR . 'utm-logs';
}

function utmBranchesNormalizeBranch($value): string
{
    $branch = trim((string)$value);
    $path = parse_url($branch, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        $branch = $path;
    }
    $branch = rawurldecode($branch);
    $branch = str_replace('\\', '/', $branch);
    $branch = trim($branch, " \t\n\r\0\x0B/");
    return preg_replace('/\s+/', '-', $branch) ?? $branch;
}

function utmBranchesBranchError(string $branch): string
{
    if ($branch === '') {
        return 'Branch path is required.';
    }
    if (strlen($branch) > 80) {
        return 'Branch path is too long.';
    }
    if (strpos($branch, '/') !== false || strpos($branch, '?') !== false || strpos($branch, '#') !== false) {
        return 'Branch path must be a single URL segment.';
    }
    if ($branch === '.' || $branch === '..') {
        return 'Branch path contains an invalid segment.';
    }
    if (!preg_match('/^[A-Za-z0-9._~-]+$/', $branch)) {
        return 'Branch path can only contain letters, numbers, dot, underscore, tilde, and dash.';
    }
    return '';
}

function utmBranchesNormalizeText($value, int $maxLength): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }
    return substr($text, 0, $maxLength);
}

function utmBranchesNormalizeBoolean($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    return !in_array($normalized, ['0', 'false', 'no', 'off'], true);
}

function utmBranchesKey(string $parentPath, string $branch): string
{
    return strtolower(campaignRedirectsNormalizePath($parentPath)) . "\n" . strtolower(utmBranchesNormalizeBranch($branch));
}

function utmBranchesLoggerRedirectMap(): array
{
    $map = [];
    foreach (campaignRedirectsList() as $redirect) {
        $path = (string)($redirect['path'] ?? '');
        $type = (string)($redirect['redirect_type'] ?? campaignRedirectsTypeForTarget((string)($redirect['target'] ?? '')));
        if ($path !== '' && $type === 'logger') {
            $map[$path] = $redirect;
        }
    }
    return $map;
}

function utmBranchesNormalizeRow(array $row): ?array
{
    $parentPath = campaignRedirectsNormalizePath($row['parent_path'] ?? $row['parentPath'] ?? $row['campaign_path'] ?? '');
    if (campaignRedirectsPathError($parentPath) !== '') {
        return null;
    }
    $branch = utmBranchesNormalizeBranch($row['branch'] ?? $row['branch_path'] ?? $row['branchPath'] ?? '');
    if (utmBranchesBranchError($branch) !== '') {
        return null;
    }
    return [
        'parent_path' => $parentPath,
        'branch' => $branch,
        'label' => utmBranchesNormalizeText($row['label'] ?? '', 120),
        'notes' => utmBranchesNormalizeText($row['notes'] ?? '', 500),
        'active' => array_key_exists('active', $row) ? utmBranchesNormalizeBoolean($row['active']) : true,
        'created_at' => campaignRedirectsCleanTimestamp($row['created_at'] ?? $row['createdAt'] ?? ''),
        'updated_at' => campaignRedirectsCleanTimestamp($row['updated_at'] ?? $row['updatedAt'] ?? '')
    ];
}

function utmBranchesReadPayload(): array
{
    $path = utmBranchesStoragePath();
    if (!is_file($path)) {
        $path = utmBranchesLegacyStoragePath();
    }
    if (!is_file($path)) {
        return ['branches' => []];
    }
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return ['branches' => []];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : ['branches' => []];
}

function utmBranchesList(): array
{
    $payload = utmBranchesReadPayload();
    $rows = $payload['branches'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }

    $branches = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = utmBranchesNormalizeRow($row);
        if ($normalized === null) {
            continue;
        }
        $branches[utmBranchesKey($normalized['parent_path'], $normalized['branch'])] = $normalized;
    }
    uasort($branches, static function (array $left, array $right): int {
        $parentCompare = strcasecmp((string)$left['parent_path'], (string)$right['parent_path']);
        if ($parentCompare !== 0) {
            return $parentCompare;
        }
        return strcasecmp((string)$left['branch'], (string)$right['branch']);
    });
    return array_values($branches);
}

function utmBranchesFind(string $parentPath, string $branch): ?array
{
    $key = utmBranchesKey($parentPath, $branch);
    foreach (utmBranchesList() as $row) {
        if (utmBranchesKey((string)$row['parent_path'], (string)$row['branch']) === $key) {
            return $row;
        }
    }
    return null;
}

function utmBranchesSaveList(array $branches): bool
{
    $map = [];
    foreach ($branches as $branch) {
        if (!is_array($branch)) {
            continue;
        }
        $normalized = utmBranchesNormalizeRow($branch);
        if ($normalized === null) {
            continue;
        }
        $map[utmBranchesKey($normalized['parent_path'], $normalized['branch'])] = $normalized;
    }
    uasort($map, static function (array $left, array $right): int {
        $parentCompare = strcasecmp((string)$left['parent_path'], (string)$right['parent_path']);
        if ($parentCompare !== 0) {
            return $parentCompare;
        }
        return strcasecmp((string)$left['branch'], (string)$right['branch']);
    });

    $payload = [
        'updated_at' => gmdate('c'),
        'branches' => array_values($map)
    ];
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return false;
    }

    $path = utmBranchesStoragePath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function utmBranchesBranchUrl(string $parentPath, string $branch): string
{
    return '/campaigns/' . campaignRedirectsNormalizePath($parentPath) . '/' . rawurlencode(utmBranchesNormalizeBranch($branch));
}

function utmBranchesResolveRequestPath(string $requestPath): ?array
{
    $path = campaignRedirectsNormalizePath($requestPath);
    $separator = strrpos($path, '/');
    if ($separator === false) {
        return null;
    }
    $parentPath = substr($path, 0, $separator);
    $branchPath = substr($path, $separator + 1);
    $branch = utmBranchesFind($parentPath, $branchPath);
    if ($branch === null || empty($branch['active'])) {
        return null;
    }
    $loggerRedirects = utmBranchesLoggerRedirectMap();
    $redirect = $loggerRedirects[(string)$branch['parent_path']] ?? null;
    if (!is_array($redirect)) {
        return null;
    }
    return [
        'branch' => $branch,
        'redirect' => $redirect
    ];
}

function utmBranchesNormalizeCookieToken($value): ?string
{
    $token = trim((string)$value);
    return preg_match('/^[a-f0-9]{32}$/i', $token) ? strtolower($token) : null;
}

function utmBranchesRandomToken(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $err) {
        return md5(uniqid('', true));
    }
}

function utmBranchesEnsureCookie(string $name, int $ttl): string
{
    $token = utmBranchesNormalizeCookieToken($_COOKIE[$name] ?? '');
    if ($token === null) {
        $token = utmBranchesRandomToken();
    }
    if (!headers_sent()) {
        setcookie($name, $token, time() + $ttl, '/', '', false, true);
    }
    $_COOKIE[$name] = $token;
    return $token;
}

function utmBranchesNullableHeaderValue($value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($normalized, 0, 512, 'UTF-8');
    }
    return substr($normalized, 0, 512);
}

function utmBranchesClientIpAddress(): ?string
{
    $remote = utmBranchesNullableHeaderValue($_SERVER['REMOTE_ADDR'] ?? null);
    if ($remote !== null) {
        return $remote;
    }
    $forwarded = utmBranchesNullableHeaderValue($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null);
    if ($forwarded === null) {
        return null;
    }
    $parts = array_map('trim', explode(',', $forwarded));
    return $parts[0] ?? null;
}

function utmBranchesBuildVisitEntry(array $branch, array $redirect): array
{
    $parentPath = (string)($branch['parent_path'] ?? '');
    $branchPath = (string)($branch['branch'] ?? '');
    return [
        'timestamp' => gmdate('c'),
        'parent_path' => $parentPath,
        'branch' => $branchPath,
        'branch_key' => utmBranchesKey($parentPath, $branchPath),
        'branch_url' => utmBranchesBranchUrl($parentPath, $branchPath),
        'target' => (string)($redirect['target'] ?? ''),
        'status_code' => (int)($redirect['status_code'] ?? 302),
        'visitor_id' => utmBranchesEnsureCookie(UTM_BRANCH_VISITOR_COOKIE, UTM_BRANCH_VISITOR_TTL),
        'session_id' => utmBranchesEnsureCookie(UTM_BRANCH_SESSION_COOKIE, UTM_BRANCH_SESSION_TTL),
        'ip_address' => utmBranchesClientIpAddress(),
        'user_agent' => utmBranchesNullableHeaderValue($_SERVER['HTTP_USER_AGENT'] ?? null),
        'referrer' => utmBranchesNullableHeaderValue($_SERVER['HTTP_REFERER'] ?? null),
        'request_uri' => utmBranchesNullableHeaderValue($_SERVER['REQUEST_URI'] ?? null),
        'query_string' => utmBranchesNullableHeaderValue($_SERVER['QUERY_STRING'] ?? null),
        'method' => utmBranchesNullableHeaderValue($_SERVER['REQUEST_METHOD'] ?? null) ?? 'GET',
        'accept_language' => utmBranchesNullableHeaderValue($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null),
        'forwarded_for' => utmBranchesNullableHeaderValue($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null)
    ];
}

function utmBranchesWriteVisitLog(array $branch, array $redirect): bool
{
    $entry = utmBranchesBuildVisitEntry($branch, $redirect);
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($line)) {
        return false;
    }
    $directory = utmBranchesLogDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }
    $path = $directory . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.log';
    return file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

function utmBranchesLogFiles(): array
{
    $files = [];
    foreach ([utmBranchesLogDirectory(), utmBranchesLegacyLogDirectory()] as $directory) {
        $matches = glob($directory . DIRECTORY_SEPARATOR . '*.log');
        if (is_array($matches)) {
            foreach ($matches as $match) {
                $files[$match] = $match;
            }
        }
    }
    $files = array_values($files);
    rsort($files, SORT_NATURAL);
    return $files;
}

function utmBranchesReadVisitLogs(int $limit = 500): array
{
    $limit = max(1, min($limit, 50000));
    $entries = [];
    foreach (utmBranchesLogFiles() as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $decoded = json_decode((string)$lines[$index], true);
            if (!is_array($decoded)) {
                continue;
            }
            $entries[] = $decoded;
            if (count($entries) >= $limit) {
                return $entries;
            }
        }
    }
    return $entries;
}

function utmBranchesSummaries(array $branches, int $logLimit = 50000): array
{
    $summaries = [];
    foreach ($branches as $branch) {
        if (!is_array($branch)) {
            continue;
        }
        $key = utmBranchesKey((string)($branch['parent_path'] ?? ''), (string)($branch['branch'] ?? ''));
        $summaries[$key] = [
            'entries' => 0,
            'unique_visitors' => 0,
            'unique_sessions' => 0,
            'last_visit_at' => '',
            'last_ip_address' => '',
            '_visitors' => [],
            '_sessions' => []
        ];
    }

    foreach (utmBranchesReadVisitLogs($logLimit) as $entry) {
        $key = (string)($entry['branch_key'] ?? utmBranchesKey((string)($entry['parent_path'] ?? ''), (string)($entry['branch'] ?? '')));
        if (!isset($summaries[$key])) {
            continue;
        }
        $summaries[$key]['entries']++;
        $visitorId = trim((string)($entry['visitor_id'] ?? ''));
        if ($visitorId !== '') {
            $summaries[$key]['_visitors'][$visitorId] = true;
        }
        $sessionId = trim((string)($entry['session_id'] ?? ''));
        if ($sessionId !== '') {
            $summaries[$key]['_sessions'][$sessionId] = true;
        }
        $timestamp = trim((string)($entry['timestamp'] ?? ''));
        if ($timestamp !== '' && ($summaries[$key]['last_visit_at'] === '' || strcmp($timestamp, $summaries[$key]['last_visit_at']) > 0)) {
            $summaries[$key]['last_visit_at'] = $timestamp;
            $summaries[$key]['last_ip_address'] = (string)($entry['ip_address'] ?? '');
        }
    }

    foreach ($summaries as $key => $summary) {
        $summaries[$key]['unique_visitors'] = count($summary['_visitors']);
        $summaries[$key]['unique_sessions'] = count($summary['_sessions']);
        unset($summaries[$key]['_visitors'], $summaries[$key]['_sessions']);
    }
    return $summaries;
}
