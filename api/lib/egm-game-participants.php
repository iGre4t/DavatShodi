<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-facilitators.php';
require_once __DIR__ . '/users.php';

const EGM_GAME_WALK_IN_WARNING = 'به دلیل ورود خارج از برنامه ممکن است این فرد شامل جایزه نشود';
const EGM_GAME_PAST_WARNING = 'به دلیل حضور در مسابقات گذشته شامل جایزه نمی شود';

function egmGameCreator(PDO $pdo, string $eventCode, string $code, array $fallback = []): array
{
    $user = null; $type = 'admin';
    if (str_starts_with($code, 'facilitator:')) {
        $type = 'facilitator';
        foreach (egmFacilitatorsRead($pdo, $eventCode) as $row) if ('facilitator:' . $row['id'] === $code) { $user = $row; break; }
    } elseif ($code !== '') $user = loadUserByCode($pdo, $code);
    if (!$user) return $fallback + ['code' => $code, 'name' => 'حساب حذف‌شده یا نامشخص', 'username' => '', 'type' => $type];
    return ['code' => $code, 'name' => (string)($user['fullname'] ?? $user['username']), 'username' => (string)$user['username'],
        'type' => $type, 'phone' => (string)($user['phone'] ?? ''), 'email' => (string)($user['email'] ?? ''),
        'work_id' => (string)($user['work_id'] ?? ''), 'id_number' => (string)($user['id_number'] ?? '')];
}

function egmGamePastParticipants(PDO $pdo, string $eventCode, string $periodCode, string $gameId): array
{
    if ($gameId === '') return [];
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $eventCode . ':' . $periodCode . ':' . $gameId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $context = ['pdo' => $pdo, 'code' => $eventCode];
    $periods = egmInstanceReadPeriods($pdo, $eventCode); $starts = [];
    $timezone = new DateTimeZone('Asia/Tehran');
    foreach ($periods as $period) {
        $code = egmCheckInPeriodCode($period);
        $starts[$code] = egmCheckInPeriodWindow($period, $timezone)['start'];
    }
    $cutoff = $starts[$periodCode] ?? new DateTimeImmutable('now', $timezone);
    $ids = [];
    foreach (egmGamesState($context)['enabled'] as $otherPeriod => $games) {
        if ((string)$otherPeriod === $periodCode || !in_array($gameId, $games, true)) continue;
        if (isset($starts[$otherPeriod]) && $starts[$otherPeriod] >= $cutoff) continue;
        $table = egmGamesTableName($eventCode, (string)$otherPeriod, $gameId);
        if (!egmInstanceTableExists($pdo, $table)) continue;
        foreach ($pdo->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $team = json_decode((string)$json, true);
            if (!is_array($team) || ($team['type'] ?? '') !== 'team' || (empty($team['started_at']) && empty($team['scores']))) continue;
            $started = strtotime((string)($team['started_at'] ?? ''));
            if ($started !== false && $started >= $cutoff->getTimestamp()) continue;
            foreach ((array)($team['members'] ?? []) as $member) if ((int)($member['id'] ?? 0) > 0) $ids[(int)$member['id']] = true;
        }
    }
    return $cache[$cacheKey] = $ids;
}

function egmGamePrizeState(array $profile, bool $past): array
{
    $walkIn = !empty($profile['period_is_uninvited_guest']) || strtolower((string)($profile['invitation_source'] ?? '')) === 'walk_in';
    $giftApproved = isset($profile['should_get_gift']) && (int)$profile['should_get_gift'] === 1;
    $warning = $past ? EGM_GAME_PAST_WARNING : ($walkIn && !$giftApproved ? EGM_GAME_WALK_IN_WARNING : '');
    return ['walk_in' => $walkIn, 'entry_recorded' => !empty($profile['entered_date']) && !empty($profile['entered_time']),
        'gift_approved' => $giftApproved, 'previous_game_participation' => $past,
        'prize_warning' => $warning, 'prize_warning_tone' => $past ? 'past' : ($warning !== '' ? 'walk-in' : '')];
}

function egmGameEnrichMembers(PDO $pdo, string $eventCode, string $periodCode, string $gameId, array $members): array
{
    if (!$members) return [];
    $ids = array_values(array_unique(array_map(static fn(array $member): int => (int)$member['id'], $members)));
    $tables = egmInstanceTableNames($eventCode);
    $statement = $pdo->prepare("SELECT u.*, p.`invitation_source`, p.`is_uninvited_guest` AS `period_is_uninvited_guest`, p.`entered_date`, p.`entered_time`, p.`should_get_gift`, p.`draw_eligible` FROM `{$tables['users']}` u LEFT JOIN `{$tables['user_periods']}` p ON p.`user_id`=u.`id` AND p.`period_code`=? WHERE u.`id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $statement->execute([$periodCode, ...$ids]); $profiles = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $profile) $profiles[(int)$profile['id']] = $profile;
    $past = egmGamePastParticipants($pdo, $eventCode, $periodCode, $gameId);
    return array_map(static fn(array $member): array => array_replace($member, egmGamePrizeState($profiles[$member['id']] ?? [], isset($past[$member['id']]))), $members);
}

function egmGameEnrichTeam(PDO $pdo, string $eventCode, string $periodCode, string $gameId, array $team): array
{
    $team['creator'] = egmGameCreator($pdo, $eventCode, (string)($team['created_by'] ?? ''), (array)($team['creator'] ?? []));
    $team['members'] = egmGameEnrichMembers($pdo, $eventCode, $periodCode, $gameId, $team['members']);
    return $team;
}
