<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';

function priorityCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'egm_room_priority_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `{$database}`");
    $code = '9876543293';
    $context = ['pdo' => $pdo, 'code' => $code, 'tables' => ensureEgmInstanceTables($pdo, $code)];
    egmInstanceWritePeriods($pdo, $code, [['tagCode' => '01', 'active' => true]]);
    $gameId = '1234567890abcdef';
    $game = ['id' => $gameId, 'name' => 'Priority test', 'gender_mode' => 'separated', 'has_levels' => false, 'auto_room_manager' => false, 'rooms' => [], 'levels' => []];
    egmGamesWrite($context, ['games' => [$game], 'enabled' => ['01' => [$gameId]]]);
    egmGamesSaveRoom($context, $gameId, 'game_total', '', 'Priority with Female', 'priority_female');
    egmGamesSaveRoom($context, $gameId, 'game_total', '', 'Priority with Male', 'priority_male');
    $game = egmGamesState($context)['games'][0];
    priorityCheck(array_column($game['rooms'], 'gender') === ['priority_female', 'priority_male'], 'Priority labels did not persist');
    $game['auto_room_manager'] = true;
    $table = egmRefMonitorGameTable($pdo, $code, '01', $gameId);
    $insert = function (string $gender, int $position, ?array $assignment = null) use ($pdo, $table): int {
        $payload = ['type' => 'team', 'name' => $gender . $position, 'started_at' => '2026-01-01T00:00:00Z', 'queue_since' => sprintf('2026-01-01T00:00:%02dZ', $position), 'ended_at' => null, 'members' => [['id' => $position + 1, 'gender' => $gender]], 'scores' => [], 'room_assignment' => $assignment];
        $pdo->prepare("INSERT INTO `{$table}` (`user_id`, `payload`) VALUES (NULL, ?)")->execute([json_encode($payload)]);
        return (int)$pdo->lastInsertId();
    };
    $roomFor = function (int $id) use ($pdo, $table): ?string {
        return egmRefMonitorReadTeam($pdo, $table, $id)['payload']['room_assignment']['room_id'] ?? null;
    };
    foreach (['female', 'male'] as $preferred) {
        $other = $preferred === 'female' ? 'male' : 'female';
        $priorityRoom = array_values(array_filter($game['rooms'], fn($room) => $room['gender'] === 'priority_' . $preferred))[0];
        $single = array_replace($game, ['rooms' => [$priorityRoom]]);
        $pdo->exec("DELETE FROM `{$table}`");
        $olderOther = $insert($other, 0);
        $preferredTeam = $insert($preferred, 1);
        egmRefMonitorDispatchRooms($pdo, $code, $table, $single);
        priorityCheck($roomFor($preferredTeam) === $priorityRoom['id'] && $roomFor($olderOther) === null, 'Preferred team must get priority over older opposite-gender team');
        $pdo->exec("DELETE FROM `{$table}`");
        $otherTeam = $insert($other, 0);
        egmRefMonitorDispatchRooms($pdo, $code, $table, $single);
        priorityCheck($roomFor($otherTeam) === $priorityRoom['id'], 'Other gender cannot borrow unused priority room');
        $latePreferred = $insert($preferred, 1);
        egmRefMonitorDispatchRooms($pdo, $code, $table, $single);
        priorityCheck($roomFor($otherTeam) === $priorityRoom['id'] && $roomFor($latePreferred) === null, 'Priority must not displace a playing team');
        $pdo->exec("DELETE FROM `{$table}`");
        $first = $insert($preferred, 0);
        $second = $insert($preferred, 1);
        egmRefMonitorDispatchRooms($pdo, $code, $table, $single);
        priorityCheck($roomFor($first) !== null && $roomFor($second) === null, 'Same-priority queue order changed');
        foreach (['normal', 'separated'] as $mode) {
            priorityCheck(egmRefMonitorRoomEligible($other, $priorityRoom['gender'], $mode), 'Priority room must allow both genders');
        }
        priorityCheck(!egmRefMonitorRoomEligible('mixed', $priorityRoom['gender'], 'separated'), 'Separated mode accepted mixed team');
        priorityCheck(!egmRefMonitorRoomEligible('', $priorityRoom['gender'], 'separated'), 'Separated mode accepted unknown gender');
        priorityCheck(egmRefMonitorRoomEligible('mixed', $priorityRoom['gender'], 'normal'), 'Normal mixed team cannot use shared priority room');
    }
    $pdo->exec("DELETE FROM `{$table}`");
    $male = $insert('male', 0);
    $female = $insert('female', 1);
    egmRefMonitorDispatchRooms($pdo, $code, $table, $game);
    priorityCheck($roomFor($male) === $game['rooms'][1]['id'] && $roomFor($female) === $game['rooms'][0]['id'], 'Teams must use their preferred rooms');
    $pdo->exec("DELETE FROM `{$table}`");
    $sharedRoom = ['id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Both', 'gender' => 'both'];
    $femaleOnly = ['id' => 'bbbbbbbbbbbbbbbb', 'name' => 'Female only', 'gender' => 'female'];
    $female = $insert('female', 0);
    $male = $insert('male', 1);
    $exclusive = array_replace($game, ['rooms' => [$game['rooms'][0], $sharedRoom, $femaleOnly]]);
    egmRefMonitorDispatchRooms($pdo, $code, $table, $exclusive);
    priorityCheck($roomFor($female) === $femaleOnly['id'] && $roomFor($male) === $sharedRoom['id'], 'Use exclusive and both rooms before borrowing opposite priority room');
    $pdo->exec("DELETE FROM `{$table}`");
    $disabled = array_replace($game, ['rooms' => [array_replace($game['rooms'][0], ['enabled' => false])]]);
    $female = $insert('female', 0);
    egmRefMonitorDispatchRooms($pdo, $code, $table, $disabled);
    priorityCheck($roomFor($female) === null, 'Disabled priority room assigned');
    echo "PASS: priority label persistence, preferred gender, fallback, queue order, occupied/disabled rooms, exclusive/shared room ordering and gender separation\n";
} finally {
    $pdo->exec("DROP DATABASE `{$database}`");
}
