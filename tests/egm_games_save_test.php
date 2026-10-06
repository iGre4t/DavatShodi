<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-games.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'egm_games_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `{$database}`");
    $code = '9876543210';
    $tables = ensureEgmInstanceTables($pdo, $code);
    $context = ['pdo' => $pdo, 'code' => $code, 'tables' => $tables];
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
    echo "Games form input, room validation, automatic/manual saves passed.\n";
} finally {
    $pdo->exec("DROP DATABASE `{$database}`");
}
