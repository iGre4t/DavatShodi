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
function loadTaskRecords($store, $dir): array {
    return [
        ['id'=>'positive', 'taskType'=>'quiz'],
        ['id'=>'zero', 'taskType'=>'quiz'],
        ['id'=>'pending', 'taskType'=>'quiz'],
        ['id'=>'letter-pending', 'taskType'=>'write_letter'],
        ['id'=>'letter-rejected', 'taskType'=>'iran_map_letter'],
        ['id'=>'donation-pending', 'taskType'=>'donation'],
        ['id'=>'donation-rejected', 'taskType'=>'donation'],
    ];
}
function readTaskUserProgress($task, $file, $mapping, $user): array {
    $id = $task['id'];
    if ($id === 'letter-pending' && !empty($GLOBALS['rejectPending'])) return ['completed'=>true, 'score'=>0, 'describeSubmitted'=>true];
    if ($id === 'donation-pending' && !empty($GLOBALS['rejectPending'])) return ['completed'=>false, 'score'=>0, 'donationStatus'=>'rejected'];
    if ($id === 'letter-pending') return ['completed'=>false, 'score'=>0, 'describeSubmitted'=>true];
    if ($id === 'letter-rejected') return ['completed'=>true, 'score'=>0, 'describeSubmitted'=>true];
    if ($id === 'donation-pending') return ['completed'=>false, 'score'=>0, 'donationStatus'=>'pending'];
    if ($id === 'donation-rejected') return ['completed'=>false, 'score'=>0, 'donationStatus'=>'rejected'];
    return ['completed' => $id !== 'pending', 'score' => $id === 'zero' ? 0 : 10];
}
function mapAssert($ok, $message): void { if (!$ok) throw new RuntimeException($message); }
$first = tcIranMapState('alice', '', '');
mapAssert(count($first) === 3, 'Positive and pending-review tasks must earn dots.');
$firstById = array_column($first, null, 'taskId');
mapAssert(isset($firstById['positive'], $firstById['letter-pending'], $firstById['donation-pending']), 'Eligible task dots are missing.');
mapAssert(!isset($firstById['zero'], $firstById['pending'], $firstById['letter-rejected'], $firstById['donation-rejected']), 'Rejected or incomplete tasks must not retain dots.');
mapAssert(!$firstById['positive']['revealed'], 'Dot must await visible reveal.');
mapAssert(tcIranMapState('alice', '', '') === $first, 'Retry must preserve coordinates without duplicates.');
$ack = tcIranMapState('alice', '', '', ['positive', 'invented']);
mapAssert(count($ack) === 3, 'Acknowledgement must not alter dot count.');
$ackById = array_column($ack, null, 'taskId');
mapAssert($ackById['positive']['revealed'] && $ackById['positive']['x'] === $firstById['positive']['x'], 'Acknowledgement must preserve position and reject invented dots.');
$GLOBALS['rejectPending'] = true;
$afterRejection = array_column(tcIranMapState('alice', '', ''), null, 'taskId');
mapAssert(array_keys($afterRejection) === ['positive'], 'Rejected review submissions must remove their stored dots.');
$GLOBALS['rejectPending'] = false;
$bob = array_column(tcIranMapState('bob', '', ''), null, 'taskId');
mapAssert(!$bob['positive']['revealed'], 'Users have separate maps.');
$GLOBALS['club'] = 'two';
$otherClub = array_column(tcIranMapState('alice', '', ''), null, 'taskId');
mapAssert(!$otherClub['positive']['revealed'], 'Clubs have separate maps.');
$rings = tcIranMapRings();
for ($i = 0; $i < 300; $i++) {
    $point = tcIranMapNewPoint($rings);
    mapAssert(tcIranMapContains($rings, $point['x'], $point['y']), 'Dot must stay inside Iran.');
}
echo "Iran Map persistence, isolation, scoring and geometry tests passed.\n";
