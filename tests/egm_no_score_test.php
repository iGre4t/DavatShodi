<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-refmonitor-teams.php';

$single = ['has_levels'=>false, 'no_score_needed'=>true, 'rooms'=>[]];
$levels = egmGamesPlayableLevels($single);
if ($levels[0]['id'] !== 'game_total' || $levels[0]['name'] !== 'بازی') throw new RuntimeException('No-score game has wrong stage identity');
$staged = ['has_levels'=>true, 'no_score_needed'=>true, 'levels'=>[['id'=>'first', 'name'=>'First', 'rooms'=>[]]]];
if (egmGamesPlayableLevels($staged) !== $staged['levels']) throw new RuntimeException('No-score mode altered stages');
$row = ['id'=>1, 'created_at'=>'2026-10-07'];
$payload = ['name'=>'Team', 'started_at'=>date('c'), 'queue_since'=>date('c'), 'scores'=>[
    'first'=>['score'=>null, 'completion_only'=>true, 'submitted_by'=>'referee', 'submitted_at'=>date('c')],
]];
$team = egmRefMonitorTeamView($row, $payload);
if ($team['scores']['first']['score'] !== null || !$team['scores']['first']['completion_only']) throw new RuntimeException('Completion result became a numeric score');
if ($team['can_edit_members'] || !$team['waiting_for_room']) throw new RuntimeException('Completed stage lost progress or queue state');
$payload['ended_at'] = date('c');
$payload['end_reason'] = 'completed';
$team = egmRefMonitorTeamView($row, $payload);
if ($team['waiting_for_room'] || $team['end_reason'] !== 'completed') throw new RuntimeException('Finished game still waiting for room');
echo "No-score stage identity, completion metadata, member lock and final queue state passed.\n";
