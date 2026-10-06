<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-games.php';
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'egm_games_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `{$database}`");
    $code = '9876543210';
    $tables = ensureEgmInstanceTables($pdo, $code);
    $context = ['pdo' => $pdo, 'code' => $code, 'tables' => $tables];
    egmInstanceWritePeriods($pdo, $code, [['tagCode'=>'001', 'taskType'=>'period', 'title'=>'Test period']]);
    $_POST = ['payload' => json_encode(['action'=>'save_auto_mode', 'enabled'=>true, 'csrf'=>'test'])];
    if (egmGamesRequestInput('POST')['enabled'] !== true) throw new RuntimeException('Form transport lost boolean type');
    $_POST = ['payload' => 'bad'];
    try { egmGamesRequestInput('POST'); throw new RuntimeException('Malformed input accepted'); }
    catch (InvalidArgumentException $expected) {}
    $game = ['id'=>'1234567890abcdef', 'name'=>'تست', 'has_levels'=>false, 'gender_mode'=>'normal', 'auto_room_manager'=>false, 'rooms'=>[], 'levels'=>[], 'min_players'=>1, 'max_players'=>20];
    egmGamesWrite($context, ['games'=>[$game], 'enabled'=>[]]);
    try { egmGamesSaveAutoMode($context, $game['id'], true); throw new RuntimeException('Empty rooms accepted'); }
    catch (InvalidArgumentException $expected) {}
    if (egmGamesState($context)['games'][0]['auto_room_manager']) throw new RuntimeException('Rejected save changed mode');
    egmGamesSaveRoom($context, $game['id'], 'game_total', '', 'اتاق تست', 'both');
    $state = egmGamesState($context);
    $state['enabled']['001'] = [$game['id']];
    egmGamesWrite($context, $state);
    egmGamesEnsureTable($context, '001', $game['id']);
    egmGamesSaveAutoMode($context, $game['id'], true);
    if (!egmGamesState($context)['games'][0]['auto_room_manager']) throw new RuntimeException('Auto room mode did not persist');
    egmGamesSaveAutoMode($context, $game['id'], false);
    if (egmGamesState($context)['games'][0]['auto_room_manager']) throw new RuntimeException('Manual room mode did not persist');
    $pdo->exec("INSERT INTO `{$tables['users']}` (`first_name`, `last_name`, `work_id`, `source_row`, `is_active`) VALUES ('Test', 'Guest', 'test-1', 1, 1)");
    $memberId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO `{$tables['user_periods']}` (`user_id`, `period_code`) VALUES ({$memberId}, '001')");
    $team = egmRefMonitorCreateTeam($pdo, $code, '001', $game['id'], 'Test team', [$memberId], 'test-admin');
    egmGamesSaveCoverColorRequirement($context, $game['id'], true);
    if (!egmGamesState($context)['games'][0]['require_cover_color']) throw new RuntimeException('Cover color requirement did not persist');
    try { egmRefMonitorStartTeam($pdo, $code, '001', $game['id'], $team['id'], 'test-admin'); throw new RuntimeException('Team without required color started'); }
    catch (InvalidArgumentException $expected) {}
    $updated = egmRefMonitorUpdateCoverColor($pdo, $code, '001', $game['id'], $team['id'], 'آبی');
    if ($updated['cover_color'] !== 'آبی') throw new RuntimeException('Team color did not persist');
    $updated = egmRefMonitorUpdateDetails($pdo, $code, '001', $game['id'], $team['id'], 'Renamed team', 'قرمز');
    if ($updated['name'] !== 'Renamed team' || $updated['cover_color'] !== 'قرمز') throw new RuntimeException('Combined team edit did not persist');
    try { egmRefMonitorUpdateDetails($pdo, $code, '001', $game['id'], $team['id'], 'Invalid edit', 'نامعتبر'); throw new RuntimeException('Invalid dropdown color accepted'); }
    catch (InvalidArgumentException $expected) {}
    if (egmRefMonitorGetTeam($pdo, $code, '001', $game['id'], $team['id'])['name'] !== 'Renamed team') throw new RuntimeException('Failed edit partially changed team');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $started = egmRefMonitorStartTeam($pdo, $code, '001', $game['id'], $team['id'], 'test-admin');
    } finally {
        restore_error_handler();
    }
    if (empty($started['started_at']) || $started['members'][0]['id'] !== $memberId) throw new RuntimeException('Team did not start with its invited member');
    $persisted = egmRefMonitorGetTeam($pdo, $code, '001', $game['id'], $team['id']);
    if ($persisted['started_at'] !== $started['started_at']) throw new RuntimeException('Team start was not persisted');
    echo "RefMonitor team start and member validation passed.\n";
    try { egmRefMonitorCompleteLevel($pdo, $code, '001', $game['id'], $team['id'], 'game_total', 'test-admin'); throw new RuntimeException('Completion accepted for scored game'); }
    catch (InvalidArgumentException $expected) {}
    egmGamesSaveNoScoreMode($context, $game['id'], true);
    if (!egmGamesState($context)['games'][0]['no_score_needed']) throw new RuntimeException('No-score mode did not persist');
    try { egmRefMonitorSubmitScore($pdo, $code, '001', $game['id'], $team['id'], 'game_total', '10', 'test-admin'); throw new RuntimeException('Score accepted for no-score game'); }
    catch (InvalidArgumentException $expected) {}
    $completed = egmRefMonitorCompleteLevel($pdo, $code, '001', $game['id'], $team['id'], 'game_total', 'test-admin');
    if (empty($completed['ended_at']) || $completed['scores']['game_total']['score'] !== null || !$completed['scores']['game_total']['completion_only']) throw new RuntimeException('Single game completion failed');
    try { egmRefMonitorCompleteLevel($pdo, $code, '001', $game['id'], $team['id'], 'game_total', 'test-admin'); throw new RuntimeException('Duplicate completion accepted'); }
    catch (InvalidArgumentException $expected) {}
    try { egmGamesSaveNoScoreMode($context, $game['id'], false); throw new RuntimeException('Result mode changed after completion'); }
    catch (InvalidArgumentException $expected) {}
    $pdo->exec("UPDATE `{$tables['users']}` SET `gender` = 'male' WHERE `id` = {$memberId}");
    $multi = array_merge($game, ['id'=>'abcdef1234567890', 'has_levels'=>true, 'auto_room_manager'=>true, 'no_score_needed'=>true, 'levels'=>[
        ['id'=>'1111111111111111', 'name'=>'First', 'rooms'=>[['id'=>'3333333333333333', 'name'=>'Room 1', 'gender'=>'both']]],
        ['id'=>'2222222222222222', 'name'=>'Second', 'rooms'=>[['id'=>'4444444444444444', 'name'=>'Room 2', 'gender'=>'both']]],
    ]]);
    $state = egmGamesState($context);
    $state['games'][] = $multi;
    $state['enabled']['001'][] = $multi['id'];
    egmGamesWrite($context, $state);
    $multiTeam = egmRefMonitorCreateTeam($pdo, $code, '001', $multi['id'], 'Multi-stage team', [$memberId], 'test-admin');
    $multiTeam = egmRefMonitorStartTeam($pdo, $code, '001', $multi['id'], $multiTeam['id'], 'test-admin');
    if ($multiTeam['room_assignment']['level_id'] !== '1111111111111111') throw new RuntimeException('First room not assigned');
    try { egmRefMonitorCompleteLevel($pdo, $code, '001', $multi['id'], $multiTeam['id'], '2222222222222222', 'test-admin'); throw new RuntimeException('Wrong-room completion accepted'); }
    catch (InvalidArgumentException $expected) {}
    $multiTeam = egmRefMonitorCompleteLevel($pdo, $code, '001', $multi['id'], $multiTeam['id'], '1111111111111111', 'test-admin');
    if ($multiTeam['ended_at'] !== null || $multiTeam['room_assignment']['level_id'] !== '2222222222222222') throw new RuntimeException('Completion did not dispatch next room');
    try { egmRefMonitorCompleteLevel($pdo, $code, '001', $multi['id'], $multiTeam['id'], '1111111111111111', 'test-admin'); throw new RuntimeException('Completed stage reopened'); }
    catch (InvalidArgumentException $expected) {}
    $multiTeam = egmRefMonitorCompleteLevel($pdo, $code, '001', $multi['id'], $multiTeam['id'], '2222222222222222', 'test-admin');
    if (empty($multiTeam['ended_at']) || $multiTeam['room_assignment'] !== null || count($multiTeam['scores']) !== 2) throw new RuntimeException('Multi-stage completion did not finish game');
    echo "No-score persistence, mode guards, duplicate protection and room progression passed.\n";
    try { egmRefMonitorCreateTeam($pdo, $code, '001', $game['id'], 'Missing color', [$memberId], 'test-admin'); throw new RuntimeException('Missing required color accepted'); }
    catch (InvalidArgumentException $expected) {}
    echo "Games form input, room validation, automatic/manual saves passed.\n";
} finally {
    $pdo->exec("DROP DATABASE `{$database}`");
}
