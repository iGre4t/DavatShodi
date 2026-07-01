<?php
declare(strict_types=1);

function campaignRedirectsStoragePath(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'campaigns' . DIRECTORY_SEPARATOR . 'redirects.json';
}

function campaignRedirectsDefaultRows(): array
{
    return [
        [
            'path' => 'zendegi',
            'target' => '/mini%20apps/RateMe/index.php',
            'status_code' => 302,
            'created_at' => '',
            'updated_at' => ''
        ],
        [
            'path' => 'dastavard',
            'target' => '/mini%20apps/Task%20Club/index.php',
            'status_code' => 302,
            'created_at' => '',
            'updated_at' => ''
        ]
    ];
}

function campaignRedirectsAllowedStatusCodes(): array
{
    return [301, 302, 307, 308];
}

function campaignRedirectsNormalizePath($value): string
{
    $path = trim((string)$value);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/^[a-z][a-z0-9+.-]*:\/\/[^\/]+\/campaigns\//i', '', $path) ?? $path;
    $path = preg_replace('/^\/?campaigns\//i', '', $path) ?? $path;
    $path = trim($path, " \t\n\r\0\x0B/");
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return $path;
}

function campaignRedirectsPathError(string $path): string
{
    if ($path === '') {
        return 'Campaign path is required.';
    }
    if (strlen($path) > 180) {
        return 'Campaign path is too long.';
    }
    if (strpos($path, '?') !== false || strpos($path, '#') !== false) {
        return 'Campaign path cannot include query strings or fragments.';
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return 'Campaign path contains an invalid segment.';
        }
    }
    if (!preg_match('/^[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*$/', $path)) {
        return 'Campaign path can only contain letters, numbers, slash, dot, underscore, tilde, and dash.';
    }
    return '';
}

function campaignRedirectsNormalizeTarget($value): string
{
    return trim((string)$value);
}

function campaignRedirectsTargetError(string $target): string
{
    if ($target === '') {
        return 'Redirect target is required.';
    }
    if (strlen($target) > 2048) {
        return 'Redirect target is too long.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
        return 'Redirect target contains invalid characters.';
    }
    if (strncmp($target, '/', 1) === 0 && strncmp($target, '//', 2) !== 0) {
        return '';
    }
    $parts = parse_url($target);
    if (!is_array($parts)) {
        return 'Redirect target must be an absolute site path or an http(s) URL.';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (($scheme === 'http' || $scheme === 'https') && trim((string)($parts['host'] ?? '')) !== '') {
        return '';
    }
    return 'Redirect target must start with / or use http(s).';
}

function campaignRedirectsNormalizeStatusCode($value): int
{
    $code = (int)$value;
    return in_array($code, campaignRedirectsAllowedStatusCodes(), true) ? $code : 302;
}

function campaignRedirectsStatusCodeError($value): string
{
    $code = (int)$value;
    if (in_array($code, campaignRedirectsAllowedStatusCodes(), true)) {
        return '';
    }
    return 'Redirect status must be 301, 302, 307, or 308.';
}

function campaignRedirectsCleanTimestamp($value): string
{
    $timestamp = trim((string)$value);
    return strlen($timestamp) > 64 ? substr($timestamp, 0, 64) : $timestamp;
}

function campaignRedirectsNormalizeTargetForType(string $target): string
{
    $path = parse_url($target, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $target;
    }
    $path = rawurldecode($path);
    $path = str_replace('\\', '/', $path);
    $path = strtolower(trim($path, " \t\n\r\0\x0B/"));
    return preg_replace('/[^a-z0-9]+/', '', $path) ?? '';
}

function campaignRedirectsTypeForTarget(string $target): string
{
    $compactPath = campaignRedirectsNormalizeTargetForType($target);
    if ($compactPath === '') {
        return 'normal';
    }
    if (strncmp($compactPath, 'miniappstaskclub', 16) === 0) {
        return 'logger';
    }
    if (strncmp($compactPath, 'miniappsmissions', 16) === 0) {
        return 'logger';
    }
    return 'normal';
}

function campaignRedirectsTypeLabel(string $type): string
{
    return $type === 'logger' ? 'Logger Redirect' : 'Normal Redirect';
}

function campaignRedirectsNormalizeRow(array $row): ?array
{
    $path = campaignRedirectsNormalizePath($row['path'] ?? $row['slug'] ?? '');
    if (campaignRedirectsPathError($path) !== '') {
        return null;
    }
    $target = campaignRedirectsNormalizeTarget($row['target'] ?? $row['url'] ?? '');
    if (campaignRedirectsTargetError($target) !== '') {
        return null;
    }
    return [
        'path' => $path,
        'target' => $target,
        'redirect_type' => campaignRedirectsTypeForTarget($target),
        'status_code' => campaignRedirectsNormalizeStatusCode($row['status_code'] ?? $row['status'] ?? 302),
        'created_at' => campaignRedirectsCleanTimestamp($row['created_at'] ?? $row['createdAt'] ?? ''),
        'updated_at' => campaignRedirectsCleanTimestamp($row['updated_at'] ?? $row['updatedAt'] ?? '')
    ];
}

function campaignRedirectsReadPayload(): ?array
{
    $path = campaignRedirectsStoragePath();
    if (!is_file($path)) {
        return null;
    }
    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return ['redirects' => []];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : ['redirects' => []];
}

function campaignRedirectsList(): array
{
    $payload = campaignRedirectsReadPayload();
    $rows = $payload === null ? campaignRedirectsDefaultRows() : ($payload['redirects'] ?? []);
    if (!is_array($rows)) {
        $rows = [];
    }

    $redirects = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = campaignRedirectsNormalizeRow($row);
        if ($normalized === null) {
            continue;
        }
        $redirects[$normalized['path']] = $normalized;
    }

    ksort($redirects, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($redirects);
}

function campaignRedirectsMap(): array
{
    $map = [];
    foreach (campaignRedirectsList() as $redirect) {
        $map[$redirect['path']] = $redirect;
    }
    return $map;
}

function campaignRedirectsFind(string $path): ?array
{
    $normalizedPath = campaignRedirectsNormalizePath($path);
    if (campaignRedirectsPathError($normalizedPath) !== '') {
        return null;
    }
    $map = campaignRedirectsMap();
    return $map[$normalizedPath] ?? null;
}

function campaignRedirectsSaveList(array $redirects): bool
{
    $map = [];
    foreach ($redirects as $redirect) {
        if (!is_array($redirect)) {
            continue;
        }
        $normalized = campaignRedirectsNormalizeRow($redirect);
        if ($normalized === null) {
            continue;
        }
        $map[$normalized['path']] = $normalized;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

    $payload = [
        'updated_at' => gmdate('c'),
        'redirects' => array_values($map)
    ];
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    $path = campaignRedirectsStoragePath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}
