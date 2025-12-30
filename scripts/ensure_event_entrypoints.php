<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/event_entrypoints.php';

$storePath = __DIR__ . '/../data/guests.json';
$eventsRoot = __DIR__ . '/../events';

if (!is_file($storePath)) {
    echo "Guest store not found at {$storePath}.\n";
    exit(1);
}

$content = file_get_contents($storePath);
if ($content === false) {
    echo "Unable to read guest store at {$storePath}.\n";
    exit(1);
}
$decoded = json_decode($content, true);
if (!is_array($decoded)) {
    echo "Guest store is not a valid JSON object.\n";
    exit(1);
}

$events = is_array($decoded['events'] ?? null) ? $decoded['events'] : [];
$created = 0;
$failed = 0;

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $code = trim((string)($event['code'] ?? ''));
    $slug = trim((string)($event['slug'] ?? ''));
    $dirName = $code !== '' ? $code : ($slug !== '' ? $slug : 'event');
    $eventDir = rtrim($eventsRoot, '/\\') . DIRECTORY_SEPARATOR . $dirName;
    if (ensureEventEntryPoints($eventDir, $dirName)) {
        $created++;
    } else {
        $failed++;
        echo "Failed to write entry points for {$dirName}.\n";
    }
}

echo "Entry points ensured for {$created} event(s)";
if ($failed > 0) {
    echo ", {$failed} failed";
}
echo ".\n";
