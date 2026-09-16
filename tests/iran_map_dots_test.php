<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/mini apps/taskclub-templates/iran-map/iran-map-store.php';
const TASKS_JS_STORE_PATH = 'test';
const TASKS_DIR_PATH = 'test';
class MapTestStatement extends PDOStatement {
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return 1; }
}
class MapTestDatabase extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MapTestStatement(); }
}
function tcDatabaseRuntimeContextForPath(string $path): array { return ['pdo' => new MapTestDatabase(), 'code' => $GLOBALS['club'] ?? 'one']; }
function tcInstanceReadData($pdo, $code, $key, $fallback) { return $GLOBALS['saved'][$code][$key] ?? $fallback; }
function tcInstanceWriteData($pdo, $code, $key, $value): void { $GLOBALS['saved'][$code][$key] = $value; }
function loadTaskRecords($store, $dir): array { return [['id'=>'positive'], ['id'=>'zero'], ['id'=>'pending']]; }
function readTaskUserProgress($task, $file, $mapping, $user): array {
    return ['completed' => $task['id'] !== 'pending', 'score' => $task['id'] === 'zero' ? 0 : 10];
}
function mapAssert($ok, $message): void { if (!$ok) throw new RuntimeException($message); }
$first = tcIranMapState('alice', '', '');
mapAssert(count($first) === 1 && $first[0]['taskId'] === 'positive', 'Only completed positive-score tasks earn dots.');
mapAssert(!$first[0]['revealed'], 'Dot must await visible reveal.');
mapAssert(tcIranMapState('alice', '', '') === $first, 'Retry must preserve coordinates without duplicates.');
$ack = tcIranMapState('alice', '', '', ['positive', 'invented']);
mapAssert(count($ack) === 1 && $ack[0]['revealed'] && $ack[0]['x'] === $first[0]['x'], 'Acknowledgement must preserve position and reject invented dots.');
mapAssert(!tcIranMapState('bob', '', '')[0]['revealed'], 'Users have separate maps.');
$GLOBALS['club'] = 'two';
mapAssert(!tcIranMapState('alice', '', '')[0]['revealed'], 'Clubs have separate maps.');
$rings = tcIranMapRings();
for ($i = 0; $i < 300; $i++) {
    $point = tcIranMapNewPoint($rings);
    mapAssert(tcIranMapContains($rings, $point['x'], $point['y']), 'Dot must stay inside Iran.');
}
echo "Iran Map persistence, isolation, scoring and geometry tests passed.\n";
