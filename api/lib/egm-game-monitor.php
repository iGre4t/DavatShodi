<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-refmonitor-teams.php';

function egmGameMonitorSnapshot(array $context, string $period): array
{
    $state = egmGamesState($context);
    $games = [];
    foreach ($state['games'] as $game) {
        $table = egmGamesTableName((string)$context['code'], $period, $game['id']);
        $teams = in_array($game['id'], $state['enabled'][$period] ?? [], true) && egmInstanceTableExists($context['pdo'], $table) ? egmRefMonitorListTeams($context['pdo'], (string)$context['code'], $period, $game['id']) : [];
        foreach ($teams as &$team) {
            $team['members'] = egmRefMonitorHydrateMemberGenders($context['pdo'], (string)$context['code'], $team['members']);
            $team['gender'] = egmRefMonitorTeamGender($team['members']);
            // Time-dependent referee editing permissions are not part of the snapshot revision.
            unset($team['member_edit_seconds_left']);
        }
        unset($team);
        $game['teams'] = $teams;
        $games[] = $game;
    }
    $content = ['games'=>$games, 'enabled'=>$state['enabled'][$period] ?? []];
    return ['status'=>'ok', 'revision'=>hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'server_time'=>gmdate('c')] + $content;
}
