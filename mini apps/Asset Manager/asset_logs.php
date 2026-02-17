<?php
declare(strict_types=1);

if (!defined('ASSET_LOGS_DIR')) {
    define('ASSET_LOGS_DIR', __DIR__ . '/data/logs');
}

function assetLogsEnsureDir(): void
{
    if (!is_dir(ASSET_LOGS_DIR)) {
        @mkdir(ASSET_LOGS_DIR, 0755, true);
    }
}

function assetLogsGenerateId(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        return str_replace('.', '', uniqid('log', true));
    }
}

function assetLogsNormalizeMessage(string $message): string
{
    $trimmed = trim($message);
    return preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
}

function assetLogsBuildFilePath(string $timestamp): string
{
    $parsed = strtotime($timestamp);
    $safeDate = $parsed === false ? gmdate('Y-m-d') : gmdate('Y-m-d', $parsed);
    return ASSET_LOGS_DIR . '/' . $safeDate . '.jsonl';
}

function assetLogsAppend(array $entry): bool
{
    $message = assetLogsNormalizeMessage((string)($entry['message'] ?? ''));
    if ($message === '') {
        return false;
    }

    $timestamp = trim((string)($entry['timestamp'] ?? ''));
    if ($timestamp === '') {
        $timestamp = gmdate('c');
    }

    $record = $entry;
    $record['id'] = trim((string)($record['id'] ?? '')) ?: assetLogsGenerateId();
    $record['timestamp'] = $timestamp;
    $record['message'] = $message;

    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }

    assetLogsEnsureDir();
    $target = assetLogsBuildFilePath($timestamp);
    return @file_put_contents($target, $json . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function assetLogsListFilesDesc(): array
{
    assetLogsEnsureDir();
    $files = glob(ASSET_LOGS_DIR . '/*.jsonl');
    if (!is_array($files) || !$files) {
        return [];
    }
    usort(
        $files,
        static fn (string $a, string $b): int => strcmp(basename($b), basename($a))
    );
    return $files;
}

function assetLogsEncodeCursor(int $fileIndex, int $lineIndex): string
{
    $payload = json_encode(['f' => $fileIndex, 'l' => $lineIndex], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return '';
    }
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

function assetLogsDecodeCursor(string $cursor): array
{
    $trimmed = trim($cursor);
    if ($trimmed === '') {
        return ['file_index' => 0, 'line_index' => -1];
    }

    $padded = $trimmed;
    $padLength = strlen($trimmed) % 4;
    if ($padLength > 0) {
        $padded .= str_repeat('=', 4 - $padLength);
    }

    $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
    if (!is_string($decoded) || $decoded === '') {
        return ['file_index' => 0, 'line_index' => -1];
    }

    $parsed = json_decode($decoded, true);
    if (!is_array($parsed)) {
        return ['file_index' => 0, 'line_index' => -1];
    }

    $fileIndex = (int)($parsed['f'] ?? 0);
    $lineIndex = (int)($parsed['l'] ?? -1);
    if ($fileIndex < 0) {
        $fileIndex = 0;
    }

    return [
        'file_index' => $fileIndex,
        'line_index' => $lineIndex
    ];
}

function assetLogsNormalizeEntry(array $entry): ?array
{
    $message = assetLogsNormalizeMessage((string)($entry['message'] ?? ''));
    if ($message === '') {
        return null;
    }

    $timestamp = trim((string)($entry['timestamp'] ?? ''));
    if ($timestamp === '') {
        return null;
    }

    return [
        'id' => trim((string)($entry['id'] ?? '')),
        'timestamp' => $timestamp,
        'message' => $message,
        'action' => trim((string)($entry['action'] ?? ''))
    ];
}

function assetLogsParseEpoch(string $timestamp): ?int
{
    $parsed = strtotime($timestamp);
    if ($parsed === false) {
        return null;
    }
    return (int)$parsed;
}

function assetLogsFileEpochRange(string $path): ?array
{
    $name = basename($path, '.jsonl');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
        return null;
    }
    $start = strtotime($name . ' 00:00:00 UTC');
    if ($start === false) {
        return null;
    }
    $startEpoch = (int)$start;
    return [
        'start' => $startEpoch,
        'end' => $startEpoch + 86400
    ];
}

function assetLogsReadWindow(int $startEpoch, int $endEpoch): array
{
    if ($endEpoch <= $startEpoch) {
        return [];
    }

    $items = [];
    $files = assetLogsListFilesDesc();
    foreach ($files as $filePath) {
        $range = assetLogsFileEpochRange($filePath);
        if ($range !== null && ((int)$range['end'] <= $startEpoch || (int)$range['start'] >= $endEpoch)) {
            continue;
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || !$lines) {
            continue;
        }

        for ($lineIndex = count($lines) - 1; $lineIndex >= 0; $lineIndex--) {
            $rawLine = trim((string)$lines[$lineIndex]);
            if ($rawLine === '') {
                continue;
            }
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entry = assetLogsNormalizeEntry($decoded);
            if ($entry === null) {
                continue;
            }
            $epoch = assetLogsParseEpoch((string)$entry['timestamp']);
            if ($epoch === null || $epoch < $startEpoch || $epoch >= $endEpoch) {
                continue;
            }
            $items[] = $entry;
        }
    }

    return $items;
}

function assetLogsHasEntriesOlderThan(int $epochExclusive): bool
{
    $files = assetLogsListFilesDesc();
    foreach ($files as $filePath) {
        $range = assetLogsFileEpochRange($filePath);
        if ($range !== null) {
            if ((int)$range['end'] <= $epochExclusive) {
                return true;
            }
            if ((int)$range['start'] >= $epochExclusive) {
                continue;
            }
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || !$lines) {
            continue;
        }
        foreach ($lines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entry = assetLogsNormalizeEntry($decoded);
            if ($entry === null) {
                continue;
            }
            $entryEpoch = assetLogsParseEpoch((string)$entry['timestamp']);
            if ($entryEpoch !== null && $entryEpoch < $epochExclusive) {
                return true;
            }
        }
    }

    return false;
}

function assetLogsReadRecentDayWindow(int $dayOffset = 0): array
{
    $offset = max(0, $dayOffset);
    $now = time();
    $windowEnd = $now - ($offset * 86400);
    $windowStart = $windowEnd - 86400;
    $items = assetLogsReadWindow($windowStart, $windowEnd);

    return [
        'items' => $items,
        'has_more' => assetLogsHasEntriesOlderThan($windowStart),
        'next_day_offset' => $offset + 1
    ];
}

function assetLogsCountRange(int $startEpoch, int $endEpoch): int
{
    return count(assetLogsReadWindow($startEpoch, $endEpoch));
}

function assetLogsCountRecentHours(int $hours): int
{
    $boundedHours = max(1, min(24 * 31, $hours));
    $now = time();
    $windowStart = $now - ($boundedHours * 3600);
    return assetLogsCountRange($windowStart, $now);
}

function assetLogsReadPage(int $limit = 40, string $cursor = ''): array
{
    $boundedLimit = max(1, min(100, $limit));
    $files = assetLogsListFilesDesc();
    if (!$files) {
        return [
            'items' => [],
            'next_cursor' => ''
        ];
    }

    $cursorState = assetLogsDecodeCursor($cursor);
    $fileCount = count($files);
    $startFileIndex = (int)($cursorState['file_index'] ?? 0);
    $startLineIndex = (int)($cursorState['line_index'] ?? -1);
    if ($startFileIndex >= $fileCount) {
        return [
            'items' => [],
            'next_cursor' => ''
        ];
    }

    $items = [];
    $nextCursor = '';

    for ($fileIndex = $startFileIndex; $fileIndex < $fileCount; $fileIndex++) {
        $lines = @file($files[$fileIndex], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || !$lines) {
            $startLineIndex = -1;
            continue;
        }

        $lineCount = count($lines);
        $lineIndex = ($fileIndex === $startFileIndex && $startLineIndex >= 0)
            ? min($startLineIndex, $lineCount - 1)
            : $lineCount - 1;

        for (; $lineIndex >= 0; $lineIndex--) {
            $rawLine = trim((string)$lines[$lineIndex]);
            if ($rawLine === '') {
                continue;
            }
            $decoded = json_decode($rawLine, true);
            if (!is_array($decoded)) {
                continue;
            }
            $entry = assetLogsNormalizeEntry($decoded);
            if ($entry === null) {
                continue;
            }
            $items[] = $entry;

            if (count($items) >= $boundedLimit) {
                $nextFileIndex = $fileIndex;
                $nextLineIndex = $lineIndex - 1;
                if ($nextLineIndex < 0) {
                    $nextFileIndex = $fileIndex + 1;
                    $nextLineIndex = -1;
                }
                if ($nextFileIndex < $fileCount) {
                    $nextCursor = assetLogsEncodeCursor($nextFileIndex, $nextLineIndex);
                }
                break 2;
            }
        }

        $startLineIndex = -1;
    }

    return [
        'items' => $items,
        'next_cursor' => $nextCursor
    ];
}
