<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-games.php';

/** @return array{code:string,title:string} */
function egmRefMonitorActivePeriod(PDO $pdo, string $eventCode): array
{
    $state = egmCheckInResolveEgmPeriodState(
        egmInstanceReadPeriods($pdo, $eventCode),
        new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'))
    );
    if (($state['result'] ?? '') !== 'active' || !is_array($state['period'] ?? null)) {
        throw new InvalidArgumentException(($state['result'] ?? '') === 'multiple_active_periods'
            ? 'چند بازه هم‌زمان فعال هستند. تا رفع هم‌پوشانی، عملیات بازی ممکن نیست.'
            : 'در حال حاضر بازهٔ فعالی وجود ندارد. عملیات بازی ممکن نیست.');
    }
    $period = $state['period'];
    $code = egmCheckInPeriodCode($period);
    return ['code' => $code, 'title' => trim((string)($period['title'] ?? '')) ?: $code];
}

function egmRefMonitorGameTable(PDO $pdo, string $eventCode, string $periodCode, string $gameId): string
{
    $context = ['pdo' => $pdo, 'code' => $eventCode];
    $periodCode = egmGamesValidatePeriod($context, $periodCode);
    $state = egmGamesState($context);
    if (!in_array($gameId, array_column($state['games'], 'id'), true)
        || !in_array($gameId, $state['enabled'][$periodCode] ?? [], true)) {
        throw new InvalidArgumentException('این بازی در بازهٔ انتخاب‌شده فعال نیست.');
    }
    return egmGamesEnsureTable($context, $periodCode, $gameId);
}

/** @return array<int, array{id:string,name:string}> */
function egmRefMonitorGameLevels(PDO $pdo, string $eventCode, string $gameId): array
{
    foreach (egmGamesState(['pdo' => $pdo, 'code' => $eventCode])['games'] as $game) {
        if ($game['id'] === $gameId) return egmGamesPlayableLevels($game);
    }
    throw new InvalidArgumentException('بازی پیدا نشد.');
}

function egmRefMonitorGameConfig(PDO $pdo, string $eventCode, string $gameId): array
{
    foreach (egmGamesState(['pdo' => $pdo, 'code' => $eventCode])['games'] as $game) {
        if ($game['id'] === $gameId) return $game;
    }
    throw new InvalidArgumentException('بازی پیدا نشد.');
}

/** @return array{min_players:int,max_players:int,gender_mode:string} */
function egmRefMonitorGameLimits(PDO $pdo, string $eventCode, string $gameId): array
{
    foreach (egmGamesState(['pdo' => $pdo, 'code' => $eventCode])['games'] as $game) {
        if ($game['id'] === $gameId) return ['min_players' => $game['min_players'], 'max_players' => $game['max_players'], 'gender_mode' => $game['gender_mode']];
    }
    throw new InvalidArgumentException('بازی پیدا نشد.');
}

function egmRefMonitorName(string $name): string
{
    $name = trim($name);
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 100) {
        throw new InvalidArgumentException('نام تیم باید بین ۱ تا ۱۰۰ نویسه باشد.');
    }
    return $name;
}

/** @return array<int, array<string, mixed>> */
function egmRefMonitorSearchInvitees(PDO $pdo, string $eventCode, string $periodCode, string $query): array
{
    $query = trim(strtr($query, ['۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9']));
    if ((function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2 || strlen($query) > 128) {
        throw new InvalidArgumentException('حداقل دو رقم از کد ملی یا کد پرسنلی را وارد کنید.');
    }
    $tables = egmInstanceTableNames($eventCode);
    $usersTable = $tables['users'];
    $periodsTable = $tables['user_periods'];
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    $statement = $pdo->prepare(
        "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`work_id`, u.`national_id`, u.`gender` FROM `{$usersTable}` u "
        . "INNER JOIN `{$periodsTable}` p ON p.`user_id` = u.`id` AND p.`period_code` = :period_code "
        . "WHERE u.`is_active` = 1 "
        . "AND (u.`work_id` LIKE :work_id ESCAPE '!' OR u.`national_id` LIKE :national_id ESCAPE '!' "
        . "OR CONCAT_WS(' ', u.`first_name`, u.`last_name`) LIKE :full_name ESCAPE '!') "
        . 'ORDER BY u.`work_id`, u.`id` LIMIT 20'
    );
    $statement->execute([':period_code' => $periodCode, ':work_id' => $like, ':national_id' => $like, ':full_name' => $like]);
    return array_map(static fn(array $row): array => egmRefMonitorPerson($row), $statement->fetchAll(PDO::FETCH_ASSOC));
}

/** @return array{id:int,name:string,work_id:string,national_id:string,gender:string} */
function egmRefMonitorPerson(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
        'work_id' => (string)$row['work_id'],
        'national_id' => (string)($row['national_id'] ?? ''),
        'gender' => egmGamesNormalizeGender((string)($row['gender'] ?? '')),
    ];
}

/** @return array<int, array<string, mixed>> */
function egmRefMonitorResolveMembers(PDO $pdo, string $eventCode, string $periodCode, array $memberIds, int $maxPlayers): array
{
    if (count($memberIds) < 1 || count($memberIds) > $maxPlayers) throw new InvalidArgumentException("تیم باید بین ۱ تا {$maxPlayers} عضو داشته باشد.");
    $ids = [];
    foreach ($memberIds as $value) {
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) throw new InvalidArgumentException('عضو انتخاب‌شده معتبر نیست.');
        $ids[] = (int)$value;
    }
    if (count(array_unique($ids)) !== count($ids)) throw new InvalidArgumentException('یک دعوت‌شده بیش از یک بار انتخاب شده است.');
    $tables = egmInstanceTableNames($eventCode);
    $usersTable = $tables['users'];
    $periodsTable = $tables['user_periods'];
    $statement = $pdo->prepare(
        "SELECT u.`id`, u.`first_name`, u.`last_name`, u.`work_id`, u.`national_id`, u.`gender` FROM `{$usersTable}` u "
        . "INNER JOIN `{$periodsTable}` p ON p.`user_id` = u.`id` AND p.`period_code` = ? "
        . "WHERE u.`is_active` = 1 "
        . 'AND u.`id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $statement->execute(array_merge([$periodCode], $ids));
    $found = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(int)$row['id']] = egmRefMonitorPerson($row);
    if (count($found) !== count($ids)) throw new InvalidArgumentException('یکی از افراد به این بازه دعوت نشده یا غیرفعال است.');
    return array_map(static fn(int $id): array => $found[$id], $ids);
}

function egmRefMonitorAssertGenderMode(array $members, string $mode): void
{
    if ($mode !== 'separated') return;
    $teamGender = '';
    foreach ($members as $member) {
        $gender = (string)($member['gender'] ?? '');
        if ($gender === '') throw new InvalidArgumentException('برای بازی تفکیک‌شده، جنسیت همهٔ اعضای تیم باید مشخص باشد.');
        if ($teamGender !== '' && $gender !== $teamGender) throw new InvalidArgumentException('تیم بازی تفکیک‌شده باید فقط اعضای مرد یا فقط اعضای زن داشته باشد.');
        $teamGender = $gender;
    }
}

function egmRefMonitorWithLock(PDO $pdo, string $eventCode, string $gameId, callable $callback)
{
    $lockName = egmGamesLockName($eventCode, $gameId);
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('ثبت تغییرات مشغول است. دوباره تلاش کنید.');
    try {
        return $callback();
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lockName]);
    }
}

/** @return array{row:array<string,mixed>,payload:array<string,mixed>} */
function egmRefMonitorReadTeam(PDO $pdo, string $table, int $teamId): array
{
    if ($teamId < 1) throw new InvalidArgumentException('تیم نامعتبر است.');
    $statement = $pdo->prepare(
        "SELECT `id`, `payload`, `created_at`, TIMESTAMPDIFF(SECOND, `created_at`, NOW()) AS `age_seconds` "
        . "FROM `{$table}` WHERE `id` = :id AND `user_id` IS NULL LIMIT 1"
    );
    $statement->execute([':id' => $teamId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $payload = is_array($row) ? json_decode((string)($row['payload'] ?? ''), true) : null;
    if (!is_array($row) || !is_array($payload) || ($payload['type'] ?? '') !== 'team') throw new InvalidArgumentException('تیم پیدا نشد.');
    return ['row' => $row, 'payload' => $payload];
}

/** @return array<string,mixed> */
function egmRefMonitorTeamView(array $row, array $payload): array
{
    $scores = is_array($payload['scores'] ?? null) ? $payload['scores'] : [];
    $ended = !empty($payload['ended_at']);
    $startedAt = trim((string)($payload['started_at'] ?? ''));
    $startedSeconds = $startedAt !== '' ? strtotime($startedAt) : false;
    $secondsLeft = $startedSeconds !== false ? max(0, 900 - max(0, time() - $startedSeconds)) : 0;
    $canEditMembers = !$ended && $scores === [] && ($startedAt === '' || $secondsLeft > 0);
    $total = 0.0;
    foreach ($scores as $score) if (is_array($score)) $total += (float)($score['score'] ?? 0);
    return [
        'id' => (int)$row['id'],
        'name' => trim((string)($payload['name'] ?? '')) ?: ('تیم ' . $row['id']),
        'members' => array_values((array)($payload['members'] ?? [])),
        'scores' => $scores,
        'room_assignment' => is_array($payload['room_assignment'] ?? null) ? $payload['room_assignment'] : null,
        'waiting_for_room' => !$ended && $startedAt !== '' && !empty($payload['queue_since']) && empty($payload['room_assignment']),
        'total_score' => $total,
        'created_at' => (string)$row['created_at'],
        'started_at' => $startedAt !== '' ? $startedAt : null,
        'ended_at' => $ended ? (string)$payload['ended_at'] : null,
        'end_reason' => (string)($payload['end_reason'] ?? ''),
        'can_edit_members' => $canEditMembers,
        'member_edit_seconds_left' => $startedAt !== '' ? $secondsLeft : null,
    ];
}

function egmRefMonitorWriteTeam(PDO $pdo, string $table, int $teamId, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare("UPDATE `{$table}` SET `payload` = :payload WHERE `id` = :id AND `user_id` IS NULL");
    $statement->execute([':payload' => $json, ':id' => $teamId]);
}

function egmRefMonitorTeamGender(array $members): string
{
    $gender = '';
    foreach ($members as $member) {
        $memberGender = egmGamesNormalizeGender((string)($member['gender'] ?? ''));
        if ($memberGender === '') return '';
        if ($gender !== '' && $gender !== $memberGender) return 'mixed';
        $gender = $memberGender;
    }
    return $gender;
}

function egmRefMonitorHydrateMemberGenders(PDO $pdo, string $eventCode, array $members): array
{
    $usersTable = egmInstanceTableNames($eventCode)['users'];
    $lookup = null;
    foreach ($members as &$member) {
        if (!is_array($member) || !empty($member['gender'])) continue;
        if ($lookup === null) $lookup = $pdo->prepare("SELECT `gender` FROM `{$usersTable}` WHERE `id` = :id LIMIT 1");
        $lookup->execute([':id' => (int)($member['id'] ?? 0)]);
        $member['gender'] = egmGamesNormalizeGender((string)$lookup->fetchColumn());
    }
    unset($member);
    return $members;
}

function egmRefMonitorQueueTime(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP');
}

function egmRefMonitorRoomEligible(string $teamGender, string $roomGender, string $mode): bool
{
    if ($mode === 'separated') return $teamGender !== '' && $teamGender === $roomGender;
    return $roomGender === 'both' || ($teamGender !== '' && $teamGender !== 'mixed' && $teamGender === $roomGender);
}

function egmRefMonitorAssertPlayableRooms(array $game, array $members): void
{
    if (!$game['auto_room_manager']) return;
    $teamGender = egmRefMonitorTeamGender($members);
    foreach (egmGamesPlayableLevels($game) as $level) {
        $eligible = false;
        foreach ($level['rooms'] as $room) {
            if (egmRefMonitorRoomEligible($teamGender, $room['gender'], $game['gender_mode'])) { $eligible = true; break; }
        }
        if (!$eligible) throw new InvalidArgumentException('برای ترکیب اعضای این تیم در مرحلهٔ «' . $level['name'] . '» اتاق مناسبی تعریف نشده است.');
    }
}

/** Assign available rooms to waiting teams. Caller holds the game lock. */
function egmRefMonitorDispatchRooms(PDO $pdo, string $eventCode, string $table, array $game): void
{
    if (!$game['auto_room_manager']) return;
    $levels = egmGamesPlayableLevels($game);
    $rows = $pdo->query("SELECT `id`, `payload` FROM `{$table}` WHERE `user_id` IS NULL ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
    $busy = [];
    $waiting = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'team' || empty($payload['started_at']) || !empty($payload['ended_at'])) continue;
        $assignment = $payload['room_assignment'] ?? null;
        if (is_array($assignment) && !empty($assignment['room_id'])) {
            $busy[(string)$assignment['room_id']] = true;
            continue;
        }
        $scores = (array)($payload['scores'] ?? []);
        if (!array_diff(array_column($levels, 'id'), array_keys($scores))) continue;
        if (empty($payload['queue_since'])) {
            $payload['queue_since'] = (string)$payload['started_at'];
            egmRefMonitorWriteTeam($pdo, $table, (int)$row['id'], $payload);
        }
        $waiting[] = ['id' => (int)$row['id'], 'payload' => $payload];
    }
    usort($waiting, static function (array $a, array $b): int {
        $aTime = (string)($a['payload']['queue_since'] ?? $a['payload']['started_at'] ?? '');
        $bTime = (string)($b['payload']['queue_since'] ?? $b['payload']['started_at'] ?? '');
        return strcmp($aTime, $bTime) ?: ($a['id'] <=> $b['id']);
    });
    foreach ($waiting as $entry) {
        $payload = $entry['payload'];
        $payload['members'] = egmRefMonitorHydrateMemberGenders($pdo, $eventCode, (array)($payload['members'] ?? []));
        $teamGender = egmRefMonitorTeamGender((array)($payload['members'] ?? []));
        $scores = (array)($payload['scores'] ?? []);
        $chosen = null;
        foreach ($levels as $level) {
            if (array_key_exists($level['id'], $scores)) continue;
            $rooms = $level['rooms'];
            if ($teamGender === 'male' || $teamGender === 'female') {
                usort($rooms, static fn(array $a, array $b): int => (($a['gender'] === $teamGender) ? 0 : 1) <=> (($b['gender'] === $teamGender) ? 0 : 1));
            }
            foreach ($rooms as $room) {
                if (isset($busy[$room['id']]) || !egmRefMonitorRoomEligible($teamGender, $room['gender'], $game['gender_mode'])) continue;
                $chosen = ['level_id' => $level['id'], 'level_name' => $level['name'], 'room_id' => $room['id'], 'room_name' => $room['name'], 'assigned_at' => date('c')];
                break 2;
            }
        }
        if ($chosen === null) continue;
        $payload['room_assignment'] = $chosen;
        $busy[$chosen['room_id']] = true;
        egmRefMonitorWriteTeam($pdo, $table, $entry['id'], $payload);
    }
}

function egmRefMonitorAssertMembersAvailable(PDO $pdo, string $table, array $members, int $exceptTeamId = 0): void
{
    $ids = array_column($members, 'id');
    $rows = $pdo->query("SELECT `id`, `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if ((int)$row['id'] === $exceptTeamId) continue;
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'team') continue;
        foreach ((array)($payload['members'] ?? []) as $member) {
            if (is_array($member) && in_array((int)($member['id'] ?? 0), $ids, true)) {
                throw new InvalidArgumentException('یکی از دعوت‌شدگان قبلاً عضو تیم دیگری در این بازی و بازه شده است.');
            }
        }
    }
}

/** @return array<string,mixed> */
function egmRefMonitorCreateTeam(PDO $pdo, string $eventCode, string $periodCode, string $gameId, string $name, array $memberIds, string $creatorCode): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    $name = egmRefMonitorName($name);
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $eventCode, $periodCode, $gameId, $table, $name, $memberIds, $creatorCode): array {
        $limits = egmRefMonitorGameLimits($pdo, $eventCode, $gameId);
        $members = egmRefMonitorResolveMembers($pdo, $eventCode, $periodCode, $memberIds, $limits['max_players']);
        egmRefMonitorAssertGenderMode($members, $limits['gender_mode']);
        egmRefMonitorAssertMembersAvailable($pdo, $table, $members);
        $payload = ['type' => 'team', 'name' => $name, 'members' => $members, 'scores' => [], 'created_by' => $creatorCode];
        $insert = $pdo->prepare("INSERT INTO `{$table}` (`user_id`, `payload`) VALUES (NULL, :payload)");
        $insert->execute([':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $team = egmRefMonitorReadTeam($pdo, $table, (int)$pdo->lastInsertId());
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<string,mixed> */
function egmRefMonitorStartTeam(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId, string $adminCode): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $eventCode, $gameId, $table, $teamId, $adminCode): array {
        $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        if (!empty($team['payload']['ended_at'])) throw new InvalidArgumentException('بازی این تیم پایان یافته است.');
        if (!empty($team['payload']['started_at'])) throw new InvalidArgumentException('بازی این تیم قبلاً شروع شده است.');
        if (!empty($team['payload']['scores'])) throw new InvalidArgumentException('این تیم پیش‌تر امتیاز ثبت کرده است.');
        if (egmRefMonitorGameLevels($pdo, $eventCode, $gameId) === []) throw new InvalidArgumentException('ابتدا مراحل این بازی را در پنل EGM تعریف کنید.');
        $limits = egmRefMonitorGameLimits($pdo, $eventCode, $gameId);
        $count = count((array)($team['payload']['members'] ?? []));
        if ($count < $limits['min_players'] || $count > $limits['max_players']) {
            throw new InvalidArgumentException("برای شروع بازی، تیم باید بین {$limits['min_players']} تا {$limits['max_players']} عضو داشته باشد.");
        }
        $memberIds = array_column((array)$team['payload']['members'], 'id');
        $currentMembers = egmRefMonitorResolveMembers($pdo, $eventCode, $periodCode, $memberIds, $limits['max_players']);
        egmRefMonitorAssertGenderMode($currentMembers, $limits['gender_mode']);
        $team['payload']['members'] = $currentMembers;
        $team['payload']['started_at'] = date('c');
        $team['payload']['started_by'] = $adminCode;
        $game = egmRefMonitorGameConfig($pdo, $eventCode, $gameId);
        egmRefMonitorAssertPlayableRooms($game, $currentMembers);
        if ($game['auto_room_manager']) $team['payload']['queue_since'] = egmRefMonitorQueueTime();
        egmRefMonitorWriteTeam($pdo, $table, $teamId, $team['payload']);
        if ($game['auto_room_manager']) {
            egmRefMonitorDispatchRooms($pdo, $eventCode, $table, $game);
            $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        }
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<string,mixed> */
function egmRefMonitorGetTeam(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
    return egmRefMonitorTeamView($team['row'], $team['payload']);
}

/** @return array<string,mixed> */
function egmRefMonitorUpdateName(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId, string $name): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    $name = egmRefMonitorName($name);
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $table, $teamId, $name): array {
        $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        if (!empty($team['payload']['ended_at'])) throw new InvalidArgumentException('بازی این تیم پایان یافته و نام آن قابل تغییر نیست.');
        $team['payload']['name'] = $name;
        egmRefMonitorWriteTeam($pdo, $table, $teamId, $team['payload']);
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<string,mixed> */
function egmRefMonitorUpdateMembers(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId, array $memberIds): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $eventCode, $periodCode, $gameId, $table, $teamId, $memberIds): array {
        $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        if (!egmRefMonitorTeamView($team['row'], $team['payload'])['can_edit_members']) {
            throw new InvalidArgumentException('ویرایش اعضا فقط پیش از ثبت امتیاز و تا ۱۵ دقیقه پس از شروع بازی مجاز است.');
        }
        $limits = egmRefMonitorGameLimits($pdo, $eventCode, $gameId);
        $members = egmRefMonitorResolveMembers($pdo, $eventCode, $periodCode, $memberIds, $limits['max_players']);
        egmRefMonitorAssertGenderMode($members, $limits['gender_mode']);
        egmRefMonitorAssertMembersAvailable($pdo, $table, $members, $teamId);
        $game = egmRefMonitorGameConfig($pdo, $eventCode, $gameId);
        if ($game['auto_room_manager'] && !empty($team['payload']['started_at'])) egmRefMonitorAssertPlayableRooms($game, $members);
        $assignment = $team['payload']['room_assignment'] ?? null;
        if ($game['auto_room_manager'] && is_array($assignment)) {
            $eligible = false;
            foreach (egmGamesPlayableLevels($game) as $level) {
                if ($level['id'] !== ($assignment['level_id'] ?? '')) continue;
                foreach ($level['rooms'] as $room) {
                    if ($room['id'] === ($assignment['room_id'] ?? '') && egmRefMonitorRoomEligible(egmRefMonitorTeamGender($members), $room['gender'], $game['gender_mode'])) $eligible = true;
                }
            }
            if (!$eligible) throw new InvalidArgumentException('اعضای جدید با اتاق اختصاص‌یافته به این تیم سازگار نیستند.');
        }
        $team['payload']['members'] = $members;
        egmRefMonitorWriteTeam($pdo, $table, $teamId, $team['payload']);
        if ($game['auto_room_manager'] && !is_array($assignment) && !empty($team['payload']['started_at'])) {
            egmRefMonitorDispatchRooms($pdo, $eventCode, $table, $game);
            $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        }
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<string,mixed> */
function egmRefMonitorSubmitScore(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId, string $levelId, string $scoreText, string $adminCode): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    $scoreText = trim(strtr($scoreText, ['۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9']));
    if (preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $scoreText) !== 1) throw new InvalidArgumentException('امتیاز باید عددی نامنفی با حداکثر دو رقم اعشار باشد.');
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $eventCode, $gameId, $table, $teamId, $levelId, $scoreText, $adminCode): array {
        $game = egmRefMonitorGameConfig($pdo, $eventCode, $gameId);
        $levels = egmGamesPlayableLevels($game);
        $levelNames = array_column($levels, 'name', 'id');
        if (!isset($levelNames[$levelId])) throw new InvalidArgumentException('مرحله پیدا نشد.');
        $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        if (!empty($team['payload']['ended_at'])) throw new InvalidArgumentException('بازی این تیم پایان یافته است.');
        if (empty($team['payload']['started_at'])) throw new InvalidArgumentException('ابتدا بازی این تیم را شروع کنید.');
        $scores = is_array($team['payload']['scores'] ?? null) ? $team['payload']['scores'] : [];
        if (array_key_exists($levelId, $scores)) throw new InvalidArgumentException('امتیاز این مرحله قبلاً ثبت شده و قابل تغییر نیست.');
        $assignment = $team['payload']['room_assignment'] ?? null;
        if ($game['auto_room_manager'] && (!is_array($assignment) || ($assignment['level_id'] ?? '') !== $levelId)) {
            throw new InvalidArgumentException('امتیاز فقط برای مرحله و اتاق فعلی تیم قابل ثبت است.');
        }
        $scores[$levelId] = ['score' => (float)$scoreText, 'level_name' => $levelNames[$levelId], 'room_id' => $game['auto_room_manager'] ? $assignment['room_id'] : null, 'room_name' => $game['auto_room_manager'] ? $assignment['room_name'] : null, 'submitted_at' => date('c'), 'submitted_by' => $adminCode];
        $team['payload']['scores'] = $scores;
        if ($game['auto_room_manager']) {
            unset($team['payload']['room_assignment']);
            $team['payload']['queue_since'] = egmRefMonitorQueueTime();
        }
        if ($levels !== [] && count(array_intersect(array_column($levels, 'id'), array_keys($scores))) === count($levels)) {
            $team['payload']['ended_at'] = date('c');
            $team['payload']['ended_by'] = $adminCode;
            $team['payload']['end_reason'] = 'completed';
        }
        egmRefMonitorWriteTeam($pdo, $table, $teamId, $team['payload']);
        if ($game['auto_room_manager']) {
            egmRefMonitorDispatchRooms($pdo, $eventCode, $table, $game);
            $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        }
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<string,mixed> */
function egmRefMonitorEndTeam(PDO $pdo, string $eventCode, string $periodCode, string $gameId, int $teamId, string $adminCode): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    return egmRefMonitorWithLock($pdo, $eventCode, $gameId, static function () use ($pdo, $eventCode, $gameId, $table, $teamId, $adminCode): array {
        $team = egmRefMonitorReadTeam($pdo, $table, $teamId);
        if (empty($team['payload']['ended_at'])) {
            $team['payload']['ended_at'] = date('c');
            $team['payload']['ended_by'] = $adminCode;
            $team['payload']['end_reason'] = 'panel';
            unset($team['payload']['room_assignment'], $team['payload']['queue_since']);
            egmRefMonitorWriteTeam($pdo, $table, $teamId, $team['payload']);
            $game = egmRefMonitorGameConfig($pdo, $eventCode, $gameId);
            if ($game['auto_room_manager']) egmRefMonitorDispatchRooms($pdo, $eventCode, $table, $game);
        }
        return egmRefMonitorTeamView($team['row'], $team['payload']);
    });
}

/** @return array<int, array<string,mixed>> */
function egmRefMonitorListTeams(PDO $pdo, string $eventCode, string $periodCode, string $gameId): array
{
    $table = egmRefMonitorGameTable($pdo, $eventCode, $periodCode, $gameId);
    $rows = $pdo->query("SELECT `id`, `payload`, `created_at`, TIMESTAMPDIFF(SECOND, `created_at`, NOW()) AS `age_seconds` FROM `{$table}` WHERE `user_id` IS NULL ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
    $teams = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        if (!is_array($payload) || ($payload['type'] ?? '') !== 'team' || !is_array($payload['members'] ?? null)) continue;
        $teams[] = egmRefMonitorTeamView($row, $payload);
    }
    return $teams;
}
