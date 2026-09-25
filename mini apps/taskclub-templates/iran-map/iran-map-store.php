<?php
declare(strict_types=1);

function tcIranMapRings(): array
{
    $svg = file_get_contents(__DIR__ . '/iran-map.svg');
    preg_match('/<path\s+d="([^"]+)"/', $svg, $match);
    $rings = [];
    foreach (explode('M', $match[1] ?? '') as $part) {
        preg_match_all('/([\d.]+),([\d.]+)/', $part, $points, PREG_SET_ORDER);
        if ($points) $rings[] = array_map(static fn($p) => [(float)$p[1], (float)$p[2]], $points);
    }
    if (!$rings) throw new RuntimeException('Map geometry is unavailable.');
    return $rings;
}

function tcIranMapContains(array $rings, float $x, float $y): bool
{
    $inside = false;
    foreach ($rings as $ring) {
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i]; [$xj, $yj] = $ring[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) $inside = !$inside;
        }
    }
    return $inside;
}

function tcIranMapNewPoint(array $rings): array
{
    $points = array_merge(...$rings);
    $xs = array_column($points, 0); $ys = array_column($points, 1);
    for ($attempt = 0; $attempt < 10000; $attempt++) {
        $x = min($xs) + (max($xs) - min($xs)) * random_int(0, 1000000) / 1000000;
        $y = min($ys) + (max($ys) - min($ys)) * random_int(0, 1000000) / 1000000;
        // Keep the whole dot inside the outline.
        foreach ([[0,0],[5,0],[-5,0],[0,5],[0,-5]] as [$dx,$dy]) {
            if (!tcIranMapContains($rings, $x + $dx, $y + $dy)) continue 2;
        }
        return ['x' => round($x, 2), 'y' => round($y, 2)];
    }
    throw new RuntimeException('Unable to place map dot.');
}

function tcIranMapShouldHaveDot(array $task, array $progress): bool
{
    $taskType = strtolower(trim((string)($task['taskType'] ?? $task['type'] ?? '')));
    $completed = !empty($progress['completed']);
    $score = (int)($progress['score'] ?? 0);

    // Written submissions earn a provisional dot while they await review. A
    // completed zero-score review is the existing rejection signal.
    if (in_array($taskType, ['describe_photo', 'write_letter', 'iran_map_letter'], true)) {
        if (empty($progress['describeSubmitted'])) return false;
        return !$completed || $score > 0;
    }

    // Direct Donations have an explicit review status. Payroll Donations are
    // completed immediately and continue through the normal score rule below.
    if ($taskType === 'donation') {
        $status = strtolower(trim((string)($progress['donationStatus'] ?? '')));
        if ($status === 'rejected') return false;
        if (in_array($status, ['pending', 'approved'], true)) return true;
    }

    return $completed && $score > 0;
}

function tcIranMapState(string $workId, string $inviteesFile, string $mappingFile, array $acknowledged = []): array
{
    $context = tcDatabaseRuntimeContextForPath(__DIR__ . '/Setting.json');
    if (!$context) throw new RuntimeException('Map database unavailable.');
    $pdo = $context['pdo']; $code = $context['code'];
    $key = 'iran_map_' . hash('sha256', $workId);
    $lock = 'tcmap:' . substr(hash('sha256', $code . ':' . $workId), 0, 50);
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $statement->execute([$lock]);
    if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException('Map is busy.');
    try {
        $dots = tcInstanceReadData($pdo, $code, $key, []);
        if (!is_array($dots)) throw new RuntimeException('Invalid map state.');
        $original = $dots;
        $tasks = loadTaskRecords(TASKS_JS_STORE_PATH, TASKS_DIR_PATH);
        $knownTaskIds = [];
        foreach ($tasks as $task) {
            $id = (string)($task['id'] ?? '');
            if ($id === '') continue;
            $knownTaskIds[$id] = true;
            $progress = readTaskUserProgress($task, $inviteesFile, $mappingFile, $workId);
            if (!tcIranMapShouldHaveDot($task, $progress)) {
                unset($dots[$id]);
                continue;
            }
            if (!isset($dots[$id])) {
                $dots[$id] = ['taskId' => $id, 'revealed' => false] + tcIranMapNewPoint(tcIranMapRings());
            }
        }
        foreach (array_keys($dots) as $id) {
            if (!isset($knownTaskIds[(string)$id])) unset($dots[$id]);
        }
        foreach ($acknowledged as $id) {
            if (is_string($id) && isset($dots[$id])) $dots[$id]['revealed'] = true;
        }
        if ($original !== $dots) tcInstanceWriteData($pdo, $code, $key, $dots);
        return array_values($dots);
    } finally {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$lock]);
    }
}
