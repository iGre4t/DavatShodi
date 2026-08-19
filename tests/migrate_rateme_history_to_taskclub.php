<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SOURCE_TAG = '001';
const DESTINATION_TAG = '009';

function migrationFail(string $message): never
{
    throw new RuntimeException($message);
}

function readJsonFile(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) migrationFail("Unable to read JSON: {$path}");
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) migrationFail("Invalid JSON: {$path}");
    return $decoded;
}

function readTaskRegistry(string $path, string $variable): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) migrationFail("Unable to read task registry: {$path}");
    $pattern = '/^\s*window\.' . preg_quote($variable, '/') . '\s*=\s*(\[.*\])\s*;?\s*$/s';
    if (!preg_match($pattern, $raw, $matches)) migrationFail("Unexpected task registry format: {$path}");
    $tasks = json_decode($matches[1], true);
    if (!is_array($tasks)) migrationFail("Invalid task registry JSON: {$path}");
    return $tasks;
}

function writeTaskRegistry(string $path, string $variable, array $tasks): void
{
    $json = json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, "window.{$variable} = {$json};\n", LOCK_EX) === false) {
        migrationFail("Unable to write task registry: {$path}");
    }
}

function readCsvFile(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) migrationFail("Unable to read CSV: {$path}");
    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) $rows[] = $row;
    fclose($handle);
    return $rows;
}

function writeCsvFile(string $path, array $rows): void
{
    $handle = @fopen($path, 'wb');
    if ($handle === false) migrationFail("Unable to write CSV: {$path}");
    foreach ($rows as $row) {
        if (fputcsv($handle, array_values(is_array($row) ? $row : []), ',', '"', '\\') === false) {
            fclose($handle);
            migrationFail("Unable to write a CSV row: {$path}");
        }
    }
    if (!fclose($handle)) migrationFail("Unable to close CSV: {$path}");
}

function findHeaderIndex(array $header, string $name): int
{
    $wanted = strtolower(trim($name));
    foreach ($header as $index => $value) {
        if (strtolower(trim((string)$value)) === $wanted) return (int)$index;
    }
    return -1;
}

function ensureColumn(array &$rows, string $name): int
{
    if (!$rows) $rows = [[]];
    $index = findHeaderIndex($rows[0], $name);
    if ($index >= 0) return $index;
    $index = count($rows[0]);
    $rows[0][] = $name;
    for ($i = 1; $i < count($rows); $i++) $rows[$i][$index] = '';
    return $index;
}

function normalizeRow(array $row, int $length): array
{
    if (count($row) < $length) return array_pad($row, $length, '');
    return array_slice($row, 0, $length);
}

function parseIdList(string $value): array
{
    $result = [];
    foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $id) {
        $id = trim($id);
        if ($id !== '' && !in_array($id, $result, true)) $result[] = $id;
    }
    return $result;
}

function parseScoreMap(string $value): array
{
    $result = [];
    foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $entry) {
        $parts = explode(':', $entry, 2);
        $id = trim((string)($parts[0] ?? ''));
        if ($id !== '') $result[$id] = max(0, (int)($parts[1] ?? 0));
    }
    return $result;
}

function serializeScoreMap(array $map): string
{
    $parts = [];
    foreach ($map as $id => $score) {
        $id = trim((string)$id);
        if ($id !== '') $parts[] = $id . ':' . max(0, (int)$score);
    }
    return implode(',', $parts);
}

function copyDirectory(string $source, string $destination): void
{
    if (!is_dir($source)) return;
    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        migrationFail("Unable to create directory: {$destination}");
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) continue;
        $target = $destination . DIRECTORY_SEPARATOR . $item->getFilename();
        if ($item->isDir()) copyDirectory($item->getPathname(), $target);
        elseif (!copy($item->getPathname(), $target)) migrationFail("Unable to copy: {$target}");
    }
}

function removeMigrationDirectory(string $path, string $tasksDirectory): void
{
    $parent = realpath(dirname($path));
    $expectedParent = realpath($tasksDirectory);
    if ($parent === false || $expectedParent === false || $parent !== $expectedParent) return;
    if (!is_dir($path) || !str_starts_with(basename($path), '.rateme-migration-')) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}

$root = dirname(__DIR__);
$rateRoot = $root . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'RateMe';
$taskClubRoot = $root . DIRECTORY_SEPARATOR . 'mini apps' . DIRECTORY_SEPARATOR . 'Task Club';
$rateTasksDirectory = $rateRoot . DIRECTORY_SEPARATOR . 'tasks';
$taskClubTasksDirectory = $taskClubRoot . DIRECTORY_SEPARATOR . 'tasks';
$sourceTaskDirectory = $rateTasksDirectory . DIRECTORY_SEPARATOR . SOURCE_TAG;
$destinationTaskDirectory = $taskClubTasksDirectory . DIRECTORY_SEPARATOR . DESTINATION_TAG;
$rateRegistryPath = $rateTasksDirectory . DIRECTORY_SEPARATOR . 'tasks.js';
$taskClubRegistryPath = $taskClubTasksDirectory . DIRECTORY_SEPARATOR . 'tasks.js';
$rateInviteesPath = $rateRoot . DIRECTORY_SEPARATOR . 'rms Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$taskClubInviteesPath = $taskClubRoot . DIRECTORY_SEPARATOR . 'TC Event' . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';

$sourceTasks = readTaskRegistry($rateRegistryPath, 'RMS_TASKS');
if (count($sourceTasks) !== 1 || !is_array($sourceTasks[0])) migrationFail('Expected exactly one RateMe task.');
$sourceTask = $sourceTasks[0];
$sourceTaskId = trim((string)($sourceTask['id'] ?? ''));
if ($sourceTaskId === '') migrationFail('The RateMe task has no ID.');

$destinationTasks = readTaskRegistry($taskClubRegistryPath, 'TC_TASKS');
$alreadyInstalled = false;
$installedTask = null;
foreach ($destinationTasks as $task) {
    $sameId = (string)($task['id'] ?? '') === $sourceTaskId;
    $sameTag = (string)($task['tagCode'] ?? '') === DESTINATION_TAG;
    if ($sameId && $sameTag && (string)($task['taskType'] ?? '') === 'shared_answers_quiz') {
        $alreadyInstalled = true;
        $installedTask = $task;
        continue;
    }
    if ($sameId) migrationFail("Task {$sourceTaskId} is already registered with a different tag or type.");
    if ($sameTag) migrationFail('Task Club tag 009 is already in use by another task.');
}
if ($alreadyInstalled && !is_dir($destinationTaskDirectory)) migrationFail('The migrated task is registered but its directory is missing.');
if (!$alreadyInstalled && file_exists($destinationTaskDirectory)) migrationFail('Task Club task directory 009 already exists.');

$rateRows = readCsvFile($rateInviteesPath);
$taskClubRows = readCsvFile($taskClubInviteesPath);
if (!$rateRows || !$taskClubRows) migrationFail('One of the invitee tables is empty.');

$rateHeader = $rateRows[0];
$taskClubWorkIdIndex = 0;
$rateWorkIdIndex = 0;
$destinationColumns = [];
foreach (['password', 'logins counts', 'logins', 'answers', 'inner score', 'score', 'Answered', 'task completed ids', 'task score map', 'info tasks'] as $column) {
    $destinationColumns[$column] = ensureColumn($taskClubRows, $column);
}
$destinationHeaderLength = count($taskClubRows[0]);
for ($i = 1; $i < count($taskClubRows); $i++) $taskClubRows[$i] = normalizeRow($taskClubRows[$i], $destinationHeaderLength);

$rateColumns = [];
foreach (['password', 'logins counts', 'logins', 'answers', 'inner score', 'score', 'Answered', 'task completed ids', 'task score map', 'info tasks'] as $column) {
    $rateColumns[$column] = findHeaderIndex($rateHeader, $column);
}

$destinationById = [];
for ($i = 1; $i < count($taskClubRows); $i++) {
    $id = trim((string)($taskClubRows[$i][$taskClubWorkIdIndex] ?? ''));
    if ($id !== '') $destinationById[$id] = $i;
}

$baseColumnMap = [
    0 => 0, 1 => 5, 2 => 2, 3 => 3, 4 => 4, 5 => 6, 6 => 7, 7 => 8, 8 => 9,
    9 => 10, 10 => 11, 11 => 12, 12 => 13, 13 => 14, 14 => 15, 15 => 16,
    16 => 18, 17 => 22, 18 => 23, 19 => 30, 20 => 21, 21 => 1, 22 => 28
];
$rateById = [];
$addedInvitees = 0;
foreach ($rateRows as $rowIndex => $sourceRow) {
    if ($rowIndex === 0) continue;
    $workId = trim((string)($sourceRow[$rateWorkIdIndex] ?? ''));
    if ($workId === '') continue;
    $rateById[$workId] = $sourceRow;
    if (isset($destinationById[$workId])) continue;

    $newRow = array_fill(0, $destinationHeaderLength, '');
    foreach ($baseColumnMap as $sourceIndex => $destinationIndex) {
        if ($destinationIndex < $destinationHeaderLength) $newRow[$destinationIndex] = (string)($sourceRow[$sourceIndex] ?? '');
    }
    foreach (['password', 'logins counts', 'logins'] as $column) {
        $sourceIndex = $rateColumns[$column];
        $destinationIndex = $destinationColumns[$column];
        if ($sourceIndex >= 0 && $destinationIndex >= 0) $newRow[$destinationIndex] = (string)($sourceRow[$sourceIndex] ?? '');
    }
    $taskClubRows[] = $newRow;
    $destinationById[$workId] = count($taskClubRows) - 1;
    $addedInvitees++;
}

$answerRows = readCsvFile($sourceTaskDirectory . DIRECTORY_SEPARATOR . 'Answers.csv');
$answersById = [];
for ($i = 1; $i < count($answerRows); $i++) {
    $workId = trim((string)($answerRows[$i][0] ?? ''));
    if ($workId !== '') $answersById[$workId] = [
        'answers' => (string)($answerRows[$i][1] ?? ''),
        'innerScore' => (string)($answerRows[$i][2] ?? '')
    ];
}
$resultsById = readJsonFile($sourceTaskDirectory . DIRECTORY_SEPARATOR . 'response-results.json');

$awardedCompletions = 0;
$globalAnswersSynced = 0;
$globalResultsSynced = 0;
foreach ($destinationById as $workId => $destinationIndex) {
    $row =& $taskClubRows[$destinationIndex];
    if (isset($answersById[$workId])) {
        $row[$destinationColumns['answers']] = $answersById[$workId]['answers'];
        $answerInnerScore = trim($answersById[$workId]['innerScore']);
        $row[$destinationColumns['inner score']] = $answerInnerScore !== ''
            ? $answerInnerScore
            : (isset($resultsById[$workId]) && is_array($resultsById[$workId])
                ? (string)max(0, (int)($resultsById[$workId]['innerScore'] ?? 0))
                : '');
        $globalAnswersSynced++;
    } elseif (isset($resultsById[$workId]) && is_array($resultsById[$workId])) {
        $row[$destinationColumns['inner score']] = (string)max(0, (int)($resultsById[$workId]['innerScore'] ?? 0));
    }
    if (isset($resultsById[$workId])) $globalResultsSynced++;
    if (!isset($rateById[$workId])) continue;

    $sourceRow = $rateById[$workId];
    $sourceCompleted = $rateColumns['task completed ids'] >= 0
        ? parseIdList((string)($sourceRow[$rateColumns['task completed ids']] ?? ''))
        : [];
    $sourceScoreMap = $rateColumns['task score map'] >= 0
        ? parseScoreMap((string)($sourceRow[$rateColumns['task score map']] ?? ''))
        : [];
    if (!in_array($sourceTaskId, $sourceCompleted, true) && !array_key_exists($sourceTaskId, $sourceScoreMap)) continue;

    $destinationCompleted = parseIdList((string)($row[$destinationColumns['task completed ids']] ?? ''));
    $destinationScoreMap = parseScoreMap((string)($row[$destinationColumns['task score map']] ?? ''));
    if (!array_key_exists($sourceTaskId, $destinationScoreMap)) {
        $award = max(0, (int)($sourceScoreMap[$sourceTaskId] ?? 0));
        $currentTotal = max(0, (int)($row[$destinationColumns['score']] ?? 0));
        $row[$destinationColumns['score']] = (string)($currentTotal + $award);
        $destinationScoreMap[$sourceTaskId] = $award;
        $awardedCompletions++;
    }
    if (!in_array($sourceTaskId, $destinationCompleted, true)) $destinationCompleted[] = $sourceTaskId;
    $row[$destinationColumns['task completed ids']] = implode(',', $destinationCompleted);
    $row[$destinationColumns['task score map']] = serializeScoreMap($destinationScoreMap);
    unset($row);
}

$newTask = is_array($installedTask) ? $installedTask : $sourceTask;
if (!$alreadyInstalled) {
    $newTask['tagCode'] = DESTINATION_TAG;
    $newTask['taskType'] = 'shared_answers_quiz';
    $newTask['active'] = false;
    $newTask['order'] = count($destinationTasks) + 1;
    $destinationTasks[] = $newTask;
}

$backupDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'DavatShodi-rateme-migration-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0777, true) && !is_dir($backupDirectory)) migrationFail('Unable to create migration backup directory.');
foreach ([$taskClubRegistryPath, $taskClubInviteesPath] as $path) {
    if (!copy($path, $backupDirectory . DIRECTORY_SEPARATOR . basename($path))) migrationFail("Unable to back up {$path}");
}

$stageDirectory = $taskClubTasksDirectory . DIRECTORY_SEPARATOR . '.rateme-migration-' . DESTINATION_TAG . '-' . getmypid();
$createdDestination = false;
try {
    if (!$alreadyInstalled) {
        if (!mkdir($stageDirectory, 0777, true) && !is_dir($stageDirectory)) migrationFail('Unable to create migration staging directory.');
        $fileMap = [
            'Answers.csv' => 'Answers.csv',
            'info-task-scores.json' => 'info-task-scores.json',
            'info-task.json' => 'info-task.json',
            'Invitees mapped.csv' => 'Invitees mapped.csv',
            'response-levels.json' => 'response-levels.json',
            'response-results.json' => 'response-results.json',
            'rmsQ code state.json' => 'TCQ code state.json',
            'rmsQ list.json' => 'TCQ list.json',
            'rmsQ settings.json' => 'TCQ settings.json',
            'task-score.json' => 'task-score.json',
            'team-challenges.json' => 'team-challenges.json',
            'team-runtime.json' => 'team-runtime.json',
            'team-settings.json' => 'team-settings.json'
        ];
        foreach ($fileMap as $sourceName => $destinationName) {
            $source = $sourceTaskDirectory . DIRECTORY_SEPARATOR . $sourceName;
            $destination = $stageDirectory . DIRECTORY_SEPARATOR . $destinationName;
            if (!is_file($source) || !copy($source, $destination)) migrationFail("Unable to stage {$sourceName}");
        }
        copyDirectory($sourceTaskDirectory . DIRECTORY_SEPARATOR . 'photos', $stageDirectory . DIRECTORY_SEPARATOR . 'photos');
        if (!rename($stageDirectory, $destinationTaskDirectory)) migrationFail('Unable to install Task Club task directory 009.');
        $createdDestination = true;
    }

    writeCsvFile($taskClubInviteesPath, $taskClubRows);
    writeTaskRegistry($taskClubRegistryPath, 'TC_TASKS', $destinationTasks);
} catch (Throwable $error) {
    @copy($backupDirectory . DIRECTORY_SEPARATOR . basename($taskClubRegistryPath), $taskClubRegistryPath);
    @copy($backupDirectory . DIRECTORY_SEPARATOR . basename($taskClubInviteesPath), $taskClubInviteesPath);
    if ($createdDestination && is_dir($destinationTaskDirectory)) {
        @rename($destinationTaskDirectory, $stageDirectory);
    }
    removeMigrationDirectory($stageDirectory, $taskClubTasksDirectory);
    throw $error;
}

echo json_encode([
    'status' => 'ok',
    'mode' => $alreadyInstalled ? 'reconciled' : 'installed',
    'task' => [
        'id' => $sourceTaskId,
        'tagCode' => DESTINATION_TAG,
        'title' => (string)($newTask['title'] ?? ''),
        'type' => (string)($newTask['taskType'] ?? '')
    ],
    'migrated' => [
        'questions' => count(readJsonFile($destinationTaskDirectory . DIRECTORY_SEPARATOR . 'TCQ list.json')),
        'answerRows' => count($answersById),
        'responseResults' => count($resultsById),
        'responseLevels' => count(readJsonFile($destinationTaskDirectory . DIRECTORY_SEPARATOR . 'response-levels.json')),
        'newInvitees' => $addedInvitees,
        'globalAnswersSynced' => $globalAnswersSynced,
        'globalResultsMatched' => $globalResultsSynced,
        'awardedCompletions' => $awardedCompletions
    ],
    'backupDirectory' => $backupDirectory
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
