<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$storePath = $projectRoot . '/data/guests.json';
$eventsRoot = $projectRoot . '/events';

function getEventDirName(array $event): string
{
    $code = trim((string)($event['code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $fallback = trim((string)($event['slug'] ?? ''));
    return $fallback !== '' ? $fallback : 'event';
}

function getEventGuestStorePath(string $eventsRoot, string $eventCode): string
{
    $cleanCode = trim($eventCode);
    if ($cleanCode === '') {
        $cleanCode = 'event';
    }
    return rtrim($eventsRoot, '/\\') . DIRECTORY_SEPARATOR . $cleanCode . DIRECTORY_SEPARATOR . 'eventguests.json';
}

function getEventGuestStorePathForEvent(array $event, string $eventsRoot): string
{
    return getEventGuestStorePath($eventsRoot, getEventDirName($event));
}

if (!is_file($storePath)) {
    fwrite(STDERR, "Guest store not found at {$storePath}.\n");
    exit(1);
}

$content = file_get_contents($storePath);
if ($content === false) {
    fwrite(STDERR, "Unable to read guest store at {$storePath}.\n");
    exit(1);
}

$decoded = json_decode($content, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Guest store is not valid JSON.\n");
    exit(1);
}

if (!empty($decoded['event_store_migrated'])) {
    echo "Migration already completed. No changes were made.\n";
    exit(0);
}

$events = is_array($decoded['events'] ?? null) ? array_values($decoded['events']) : [];
$migrated = [];
$skipped = [];
$failed = [];

foreach ($events as $event) {
    if (!is_array($event)) {
        $failed[] = ['event' => 'unknown', 'reason' => 'Invalid event data'];
        continue;
    }
    $eventDirName = getEventDirName($event);
    $eventFilePath = getEventGuestStorePathForEvent($event, $eventsRoot);
    if (is_file($eventFilePath)) {
        $skipped[] = $eventDirName;
        continue;
    }
    $eventDir = dirname($eventFilePath);
    if (!is_dir($eventDir) && !mkdir($eventDir, 0755, true) && !is_dir($eventDir)) {
        $failed[] = ['event' => $eventDirName, 'reason' => 'Unable to create event directory'];
        continue;
    }
    $encoded = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        $failed[] = ['event' => $eventDirName, 'reason' => 'Unable to encode event data'];
        continue;
    }
    if (file_put_contents($eventFilePath, $encoded) === false) {
        $failed[] = ['event' => $eventDirName, 'reason' => 'Unable to write eventguests.json'];
        continue;
    }
    $migrated[] = $eventDirName;
}

if (empty($failed)) {
    $decoded['event_store_migrated'] = true;
    $encodedStore = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encodedStore === false || file_put_contents($storePath, $encodedStore) === false) {
        $failed[] = ['event' => 'store', 'reason' => 'Unable to write migration marker'];
    }
}

echo "Migration summary:\n";
echo "- Migrated events: " . count($migrated) . "\n";
if ($migrated) {
    echo "  - " . implode(', ', $migrated) . "\n";
}
echo "- Skipped events: " . count($skipped) . "\n";
if ($skipped) {
    echo "  - " . implode(', ', $skipped) . "\n";
}
echo "- Failed events: " . count($failed) . "\n";
if ($failed) {
    foreach ($failed as $failure) {
        echo "  - {$failure['event']}: {$failure['reason']}\n";
    }
}

exit(empty($failed) ? 0 : 1);
