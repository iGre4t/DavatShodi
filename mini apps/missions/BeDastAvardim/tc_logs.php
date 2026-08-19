<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/useractivitylogs/activity-logger.php';

header('Content-Type: application/json; charset=utf-8');

$tcLogsSessionUser = requireTabPermissionFromSession('task-club', true);
if (!userHasPermissionId($tcLogsSessionUser, 'task-club:logs')) {
  denyPanelAccess(403, 'You do not have permission to access Task Club logs.', true);
}

function tcLogsJsonResponse(array $payload, int $status = 200): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function tcLogsAvailableDays(): array
{
  return tcDatabaseRuntimeActivityDays(__DIR__);
}

function tcLogsSafeDay(string $day, array $availableDays): string
{
  $trimmed = trim($day);
  if ($trimmed !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) && in_array($trimmed, $availableDays, true)) {
    return $trimmed;
  }
  return $availableDays[0] ?? date('Y-m-d');
}

function tcLogsReadLinesReverse(string $path, int $maxLines = 2000): array
{
  if (!tcDbIsFile($path)) {
    return [];
  }
  $content = tcDbFileGetContents($path);
  if (!is_string($content)) {
    return [];
  }
  $lines = preg_split('/\R/u', $content, -1, PREG_SPLIT_NO_EMPTY);
  if (!is_array($lines)) return [];
  $lines = array_slice($lines, -max(1, $maxLines));
  return array_reverse($lines);
}

function tcLogsEntrySearchText(array $entry): string
{
  $metadata = $entry['metadata'] ?? [];
  $parts = [
    $entry['user_id'] ?? '',
    $entry['session_id'] ?? '',
    $entry['action'] ?? '',
    $entry['entity_type'] ?? '',
    $entry['entity_id'] ?? '',
    $entry['ip_address'] ?? '',
    $entry['status'] ?? '',
    $entry['message'] ?? ''
  ];
  if (is_array($metadata)) {
    foreach (['username', 'full_name', 'work_id', 'reason'] as $key) {
      if (array_key_exists($key, $metadata)) {
        $parts[] = is_scalar($metadata[$key]) ? (string)$metadata[$key] : '';
      }
    }
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
      $parts[] = $encoded;
    }
  }
  $text = implode(' ', array_map(static fn ($value): string => trim((string)$value), $parts));
  return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function tcLogsNormalizeEntry(array $entry): array
{
  $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
  $user = trim((string)($entry['user_id'] ?? ''));
  if ($user === '') {
    $user = trim((string)($metadata['username'] ?? $metadata['work_id'] ?? ''));
  }
  return [
    'timestamp' => trim((string)($entry['timestamp'] ?? '')),
    'level' => trim((string)($entry['level'] ?? 'info')),
    'user_id' => $user,
    'username' => trim((string)($metadata['username'] ?? '')),
    'action' => trim((string)($entry['action'] ?? '')),
    'entity_type' => trim((string)($entry['entity_type'] ?? '')),
    'entity_id' => trim((string)($entry['entity_id'] ?? '')),
    'ip_address' => trim((string)($entry['ip_address'] ?? '')),
    'status' => trim((string)($entry['status'] ?? '')),
    'message' => trim((string)($entry['message'] ?? '')),
    'metadata' => $metadata
  ];
}

$days = tcLogsAvailableDays();
$day = tcLogsSafeDay((string)($_GET['day'] ?? ''), $days);
$query = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 100);
$limit = max(1, min(300, $limit));
$needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
$items = [];
foreach (tcDatabaseRuntimeActivityEntriesForDay(__DIR__, $day, 2000) as $decoded) {
  if ($needle !== '') {
    $haystack = tcLogsEntrySearchText($decoded);
    $contains = function_exists('mb_strpos')
      ? mb_strpos($haystack, $needle, 0, 'UTF-8') !== false
      : strpos($haystack, $needle) !== false;
    if (!$contains) {
      continue;
    }
  }
  $items[] = tcLogsNormalizeEntry($decoded);
  if (count($items) >= $limit) {
    break;
  }
}

tcLogsJsonResponse([
  'status' => 'ok',
  'days' => $days,
  'day' => $day,
  'query' => $query,
  'items' => $items
]);
