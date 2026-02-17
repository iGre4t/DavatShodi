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
