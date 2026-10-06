<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-period-invites.php';

const EGM_GAMES_KEY = 'games_catalog';
const EGM_GAME_TEAM_MAX = 100;
const EGM_GAME_SINGLE_SCORE_ID = 'game_total';

function egmGamesState(array $context): array
{
    $stored = egmInstanceReadData($context['pdo'], (string)$context['code'], EGM_GAMES_KEY, []);
    $games = [];
    foreach ((array)($stored['games'] ?? []) as $game) {
        if (!is_array($game) || !preg_match('/^[a-f0-9]{16}$/D', (string)($game['id'] ?? ''))) continue;
        $name = trim((string)($game['name'] ?? ''));
        if ($name === '') continue;
        $levels = [];
        foreach ((array)($game['levels'] ?? []) as $level) {
            if (!is_array($level) || !preg_match('/^[a-f0-9]{16}$/D', (string)($level['id'] ?? ''))) continue;
            $levelName = trim((string)($level['name'] ?? ''));
            if ($levelName === '') continue;
            $levels[] = ['id' => $level['id'], 'name' => $levelName, 'rooms' => egmGamesNormalizeRooms((array)($level['rooms'] ?? []))];
        }
        $minPlayers = (int)($game['min_players'] ?? 1);
        $maxPlayers = (int)($game['max_players'] ?? 20);
        if ($minPlayers < 1 || $maxPlayers < $minPlayers || $maxPlayers > EGM_GAME_TEAM_MAX) {
            $minPlayers = 1;
            $maxPlayers = 20;
        }
        $genderMode = (string)($game['gender_mode'] ?? 'normal');
        $games[] = ['id' => $game['id'], 'name' => $name, 'has_levels' => (bool)($game['has_levels'] ?? ($levels !== [])), 'gender_mode' => in_array($genderMode, ['normal', 'separated'], true) ? $genderMode : 'normal', 'auto_room_manager' => (bool)($game['auto_room_manager'] ?? false), 'rooms' => egmGamesNormalizeRooms((array)($game['rooms'] ?? [])), 'levels' => $levels, 'min_players' => $minPlayers, 'max_players' => $maxPlayers];
    }
    $enabled = [];
    foreach ((array)($stored['enabled'] ?? []) as $period => $ids) {
        if (!is_string($period) || !is_array($ids)) continue;
        $enabled[$period] = array_values(array_filter($ids, static fn($id): bool => is_string($id) && preg_match('/^[a-f0-9]{16}$/D', $id) === 1));
    }
    return ['games' => $games, 'enabled' => $enabled];
}

function egmGamesNormalizeRooms(array $stored): array
{
    $rooms = [];
    foreach ($stored as $room) {
        if (!is_array($room) || !preg_match('/^[a-f0-9]{16}$/D', (string)($room['id'] ?? ''))) continue;
        $name = trim((string)($room['name'] ?? ''));
        $gender = (string)($room['gender'] ?? 'both');
        if ($name === '' || !in_array($gender, ['male', 'female', 'both'], true)) continue;
        $rooms[] = ['id' => $room['id'], 'name' => $name, 'gender' => $gender];
    }
    return $rooms;
}

function egmGamesPlayableLevels(array $game): array
{
    return $game['has_levels'] ? $game['levels'] : [['id' => EGM_GAME_SINGLE_SCORE_ID, 'name' => 'امتیاز بازی', 'rooms' => $game['rooms']]];
}

function egmGamesWrite(array $context, array $state): void
{
    egmInstanceWriteData($context['pdo'], (string)$context['code'], EGM_GAMES_KEY, $state);
}

function egmGamesTableName(string $eventCode, string $periodCode, string $gameId): string
{
    return 'egm_game_' . substr(hash('sha256', $eventCode . ':' . $periodCode . ':' . $gameId), 0, 40);
}

function egmGamesLockName(string $eventCode, string $gameId): string
{
    return 'egm-game:' . substr(hash('sha256', $eventCode . ':' . $gameId), 0, 40);
}

function egmGamesValidatePeriod(array $context, string $periodCode): string
{
    $periodCode = egmPeriodInvitesValidatePeriod($context, $periodCode);
    foreach (egmPeriodInvitesPeriods($context) as $period) {
        if ((string)($period['tagCode'] ?? ($period['code'] ?? '')) !== $periodCode) continue;
        if (strtolower((string)($period['taskType'] ?? ($period['task_type'] ?? 'period'))) !== 'period') break;
        return $periodCode;
    }
    throw new InvalidArgumentException('Selected item is not a period.');
}

function egmGamesEnsureTable(array $context, string $periodCode, string $gameId): string
{
    $table = egmGamesTableName((string)$context['code'], $periodCode, $gameId);
    $context['pdo']->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` BIGINT UNSIGNED NULL,
        `payload` LONGTEXT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $table;
}

function egmGamesLevelHasScores(array $context, array $state, string $gameId, string $levelId): bool
{
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        $rows = $context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $json) {
            $payload = json_decode((string)$json, true);
            if (is_array($payload) && ($payload['type'] ?? '') === 'team' && array_key_exists($levelId, (array)($payload['scores'] ?? []))) return true;
        }
    }
    return false;
}

function egmGamesWithLock(array $context, string $gameId, callable $callback)
{
    $lockName = egmGamesLockName((string)$context['code'], $gameId);
    $lock = $context['pdo']->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Game update is busy. Please retry.');
    try {
        return $callback();
    } finally {
        $release = $context['pdo']->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lockName]);
    }
}

function egmGamesHasAnyScores(array $context, array $state, string $gameId): bool
{
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $payload = json_decode((string)$json, true);
            if (is_array($payload) && ($payload['type'] ?? '') === 'team' && !empty($payload['scores'])) return true;
        }
    }
    return false;
}

function egmGamesHasStartedTeams(array $context, array $state, string $gameId): bool
{
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $payload = json_decode((string)$json, true);
            if (is_array($payload) && ($payload['type'] ?? '') === 'team' && !empty($payload['started_at'])) return true;
        }
    }
    return false;
}

function egmGamesRoomInUse(array $context, array $state, string $gameId, string $roomId): bool
{
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $team = json_decode((string)$json, true);
            if (is_array($team) && ($team['type'] ?? '') === 'team' && empty($team['ended_at'])
                && (string)($team['room_assignment']['room_id'] ?? '') === $roomId) return true;
        }
    }
    return false;
}

function egmGamesHasActiveTeams(array $context, array $state, string $gameId): bool
{
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $team = json_decode((string)$json, true);
            if (is_array($team) && ($team['type'] ?? '') === 'team' && !empty($team['started_at']) && empty($team['ended_at'])) return true;
        }
    }
    return false;
}

function egmGamesNormalizeGender(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    if (in_array($value, ['مرد', 'مذکر', 'آقا', 'male', 'm'], true)) return 'male';
    if (in_array($value, ['زن', 'مونث', 'مؤنث', 'خانم', 'female', 'f'], true)) return 'female';
    return '';
}

function egmGamesAssertSeparatedTeams(array $context, array $state, string $gameId): void
{
    $usersTable = egmInstanceTableNames((string)$context['code'])['users'];
    $lookup = $context['pdo']->prepare("SELECT `gender` FROM `{$usersTable}` WHERE `id` = :id LIMIT 1");
    foreach ($state['enabled'] as $periodCode => $ids) {
        if (!in_array($gameId, $ids, true)) continue;
        $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
        if (!egmInstanceTableExists($context['pdo'], $table)) continue;
        foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $team = json_decode((string)$json, true);
            if (!is_array($team) || ($team['type'] ?? '') !== 'team') continue;
            $teamGender = '';
            foreach ((array)($team['members'] ?? []) as $member) {
                $memberId = (int)($member['id'] ?? 0);
                if ($memberId < 1) throw new InvalidArgumentException('جنسیت یکی از تیم‌های موجود مشخص نیست.');
                $lookup->execute([':id' => $memberId]);
                $gender = egmGamesNormalizeGender((string)$lookup->fetchColumn());
                if ($gender === '' || ($teamGender !== '' && $teamGender !== $gender)) {
                    throw new InvalidArgumentException('یکی از تیم‌های موجود ترکیبی است یا جنسیت نامشخص دارد.');
                }
                $teamGender = $gender;
            }
        }
    }
}

function egmGamesSaveMode(array $context, string $gameId, bool $hasLevels): void
{
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $hasLevels): void {
        $state = egmGamesState($context);
        $found = false;
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            $found = true;
            if ($game['has_levels'] === $hasLevels) break;
            if ($game['auto_room_manager']) throw new InvalidArgumentException('ابتدا مدیریت خودکار اتاق‌ها را خاموش کنید.');
            if (egmGamesHasStartedTeams($context, $state, $gameId)) throw new InvalidArgumentException('پس از شروع اولین تیم، نوع امتیازدهی بازی قابل تغییر نیست.');
            $game['has_levels'] = $hasLevels;
            if (!$hasLevels) $game['levels'] = [];
            break;
        }
        unset($game);
        if (!$found) throw new InvalidArgumentException('بازی پیدا نشد.');
        egmGamesWrite($context, $state);
    });
}

function egmGamesSaveGenderMode(array $context, string $gameId, string $mode): void
{
    if (!in_array($mode, ['normal', 'separated'], true)) throw new InvalidArgumentException('نوع بازی نامعتبر است.');
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $mode): void {
        $state = egmGamesState($context);
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            if ($game['gender_mode'] === $mode) return;
            if ($mode === 'separated') {
                if ($game['auto_room_manager'] && egmGamesHasActiveTeams($context, $state, $gameId)) {
                    throw new InvalidArgumentException('نوع جنسیتی بازی هنگام فعالیت تیم‌ها قابل تغییر نیست.');
                }
                foreach (egmGamesPlayableLevels($game) as $level) {
                    foreach ($level['rooms'] as $room) {
                        if ($room['gender'] === 'both') throw new InvalidArgumentException('ابتدا همهٔ اتاق‌های این بازی را مرد یا زن تعیین کنید.');
                    }
                }
                egmGamesAssertSeparatedTeams($context, $state, $gameId);
            }
            $game['gender_mode'] = $mode;
            egmGamesWrite($context, $state);
            return;
        }
        unset($game);
        throw new InvalidArgumentException('بازی پیدا نشد.');
    });
}

function egmGamesSaveAutoMode(array $context, string $gameId, bool $enabled): void
{
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $enabled): void {
        $state = egmGamesState($context);
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            if ($game['auto_room_manager'] === $enabled) return;
            if (!$enabled && egmGamesHasActiveTeams($context, $state, $gameId)) {
                throw new InvalidArgumentException('تا زمانی که تیمی در این بازی فعال است، مدیریت خودکار اتاق‌ها قابل خاموش‌کردن نیست.');
            }
            $playableLevels = egmGamesPlayableLevels($game);
            if ($enabled && $playableLevels === []) throw new InvalidArgumentException('ابتدا مراحل این بازی را تعریف کنید.');
            if ($enabled) foreach ($playableLevels as $level) {
                if ($level['rooms'] === []) throw new InvalidArgumentException('برای فعال‌کردن مدیریت خودکار، هر مرحله باید دست‌کم یک اتاق داشته باشد.');
            }
            if ($enabled) {
                require_once __DIR__ . '/egm-refmonitor-teams.php';
                $autoGame = $game;
                $autoGame['auto_room_manager'] = true;
                foreach ($state['enabled'] as $periodCode => $ids) {
                    if (!in_array($gameId, $ids, true)) continue;
                    $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
                    if (!egmInstanceTableExists($context['pdo'], $table)) continue;
                    foreach ($context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
                        $team = json_decode((string)$json, true);
                        if (!is_array($team) || ($team['type'] ?? '') !== 'team' || empty($team['started_at']) || !empty($team['ended_at'])) continue;
                        $members = egmRefMonitorHydrateMemberGenders($context['pdo'], (string)$context['code'], (array)($team['members'] ?? []));
                        egmRefMonitorAssertPlayableRooms($autoGame, $members);
                    }
                }
            }
            $game['auto_room_manager'] = $enabled;
            egmGamesWrite($context, $state);
            if ($enabled) {
                require_once __DIR__ . '/egm-refmonitor-teams.php';
                foreach ($state['enabled'] as $periodCode => $ids) {
                    if (!in_array($gameId, $ids, true)) continue;
                    $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
                    if (egmInstanceTableExists($context['pdo'], $table)) egmRefMonitorDispatchRooms($context['pdo'], (string)$context['code'], $table, $game);
                }
            }
            return;
        }
        unset($game);
        throw new InvalidArgumentException('بازی پیدا نشد.');
    });
}

function egmGamesTableHasTeams(array $context, string $periodCode, string $gameId): bool
{
    $table = egmGamesTableName((string)$context['code'], $periodCode, $gameId);
    if (!egmInstanceTableExists($context['pdo'], $table)) return false;
    $rows = $context['pdo']->query("SELECT `payload` FROM `{$table}` WHERE `user_id` IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $json) {
        $payload = json_decode((string)$json, true);
        if (is_array($payload) && ($payload['type'] ?? '') === 'team') return true;
    }
    return false;
}

function egmGamesSaveLevel(array $context, string $gameId, string $levelId, string $name): void
{
    $name = trim($name);
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 100) throw new InvalidArgumentException('نام مرحله باید بین ۱ تا ۱۰۰ نویسه باشد.');
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $levelId, $name): void {
        $state = egmGamesState($context);
        $found = false;
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            $found = true;
            if (!$game['has_levels']) throw new InvalidArgumentException('ابتدا امتیازدهی مرحله‌ای را برای این بازی فعال کنید.');
            if ($levelId === '') {
                if ($game['auto_room_manager']) throw new InvalidArgumentException('ابتدا مدیریت خودکار اتاق‌ها را خاموش کنید.');
                if (egmGamesHasAnyScores($context, $state, $gameId)) throw new InvalidArgumentException('پس از ثبت اولین امتیاز، افزودن مرحلهٔ جدید ممکن نیست.');
                $game['levels'][] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'rooms' => []];
            } else {
                $levelFound = false;
                foreach ($game['levels'] as &$level) {
                    if ($level['id'] === $levelId) { $level['name'] = $name; $levelFound = true; break; }
                }
                unset($level);
                if (!$levelFound) throw new InvalidArgumentException('مرحله پیدا نشد.');
            }
            break;
        }
        unset($game);
        if (!$found) throw new InvalidArgumentException('بازی پیدا نشد.');
        egmGamesWrite($context, $state);
    });
}

function egmGamesSaveRoom(array $context, string $gameId, string $levelId, string $roomId, string $name, string $gender): void
{
    $name = trim($name);
    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 100) {
        throw new InvalidArgumentException('نام اتاق باید بین ۱ تا ۱۰۰ نویسه باشد.');
    }
    if (!in_array($gender, ['male', 'female', 'both'], true)) throw new InvalidArgumentException('گروه اتاق نامعتبر است.');
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $levelId, $roomId, $name, $gender): void {
        $state = egmGamesState($context);
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            if ($game['gender_mode'] === 'separated' && $gender === 'both') {
                throw new InvalidArgumentException('اتاق بازی تفکیک‌شده باید مرد یا زن باشد.');
            }
            if ($game['has_levels']) {
                foreach ($game['levels'] as &$level) {
                    if ($level['id'] === $levelId) { $rooms = &$level['rooms']; break; }
                }
                unset($level);
            } elseif ($levelId === EGM_GAME_SINGLE_SCORE_ID) {
                $rooms = &$game['rooms'];
            }
            if (!isset($rooms)) throw new InvalidArgumentException('مرحله پیدا نشد.');
            if ($roomId !== '' && egmGamesRoomInUse($context, $state, $gameId, $roomId)) {
                throw new InvalidArgumentException('این اتاق اکنون در اختیار یک تیم است.');
            }
            $found = $roomId === '';
            foreach ($rooms as $room) {
                if ($room['id'] === $roomId) {
                    $found = true;
                    if ($game['auto_room_manager'] && $room['gender'] !== $gender && egmGamesHasActiveTeams($context, $state, $gameId)) {
                        throw new InvalidArgumentException('گروه اتاق هنگام فعالیت تیم‌ها قابل تغییر نیست.');
                    }
                }
                if ($room['id'] !== $roomId && $room['name'] === $name) throw new InvalidArgumentException('نام این اتاق در این مرحله تکراری است.');
            }
            if (!$found) throw new InvalidArgumentException('اتاق پیدا نشد.');
            if ($roomId === '') $rooms[] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'gender' => $gender];
            else foreach ($rooms as &$room) {
                if ($room['id'] === $roomId) { $room['name'] = $name; $room['gender'] = $gender; break; }
            }
            unset($room, $rooms);
            egmGamesWrite($context, $state);
            if ($game['auto_room_manager']) {
                require_once __DIR__ . '/egm-refmonitor-teams.php';
                foreach ($state['enabled'] as $periodCode => $ids) {
                    if (!in_array($gameId, $ids, true)) continue;
                    $table = egmGamesTableName((string)$context['code'], (string)$periodCode, $gameId);
                    if (egmInstanceTableExists($context['pdo'], $table)) egmRefMonitorDispatchRooms($context['pdo'], (string)$context['code'], $table, $game);
                }
            }
            return;
        }
        unset($game);
        throw new InvalidArgumentException('مرحله پیدا نشد.');
    });
}

function egmGamesDeleteRoom(array $context, string $gameId, string $levelId, string $roomId): void
{
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $levelId, $roomId): void {
        $state = egmGamesState($context);
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            if ($game['has_levels']) {
                foreach ($game['levels'] as &$level) {
                    if ($level['id'] === $levelId) { $rooms = &$level['rooms']; break; }
                }
                unset($level);
            } elseif ($levelId === EGM_GAME_SINGLE_SCORE_ID) {
                $rooms = &$game['rooms'];
            }
            if (!isset($rooms)) throw new InvalidArgumentException('مرحله پیدا نشد.');
            if (egmGamesRoomInUse($context, $state, $gameId, $roomId)) throw new InvalidArgumentException('این اتاق اکنون در اختیار یک تیم است.');
            if ($game['auto_room_manager'] && egmGamesHasActiveTeams($context, $state, $gameId)) throw new InvalidArgumentException('اتاق هنگام فعالیت تیم‌ها قابل حذف نیست.');
            if ($game['auto_room_manager'] && count($rooms) === 1) throw new InvalidArgumentException('برای این مرحله باید دست‌کم یک اتاق باقی بماند.');
            $before = count($rooms);
            $rooms = array_values(array_filter($rooms, static fn(array $room): bool => $room['id'] !== $roomId));
            if ($before === count($rooms)) throw new InvalidArgumentException('اتاق پیدا نشد.');
            unset($rooms);
            egmGamesWrite($context, $state);
            return;
        }
        unset($game);
        throw new InvalidArgumentException('مرحله پیدا نشد.');
    });
}

function egmGamesSaveLimits(array $context, string $gameId, $minValue, $maxValue): void
{
    if (!is_scalar($minValue) || !is_scalar($maxValue)
        || !ctype_digit((string)$minValue) || !ctype_digit((string)$maxValue)) {
        throw new InvalidArgumentException('حداقل و حداکثر اعضای تیم باید عدد صحیح باشند.');
    }
    $min = (int)$minValue;
    $max = (int)$maxValue;
    if ($min < 1 || $max < $min || $max > EGM_GAME_TEAM_MAX) {
        throw new InvalidArgumentException('حداقل باید ۱ یا بیشتر و حداکثر بین حداقل و ۱۰۰ نفر باشد.');
    }
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $min, $max): void {
        $state = egmGamesState($context);
        $found = false;
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            $game['min_players'] = $min;
            $game['max_players'] = $max;
            $found = true;
            break;
        }
        unset($game);
        if (!$found) throw new InvalidArgumentException('بازی پیدا نشد.');
        egmGamesWrite($context, $state);
    });
}

function egmGamesDeleteLevel(array $context, string $gameId, string $levelId): void
{
    egmGamesWithLock($context, $gameId, static function () use ($context, $gameId, $levelId): void {
        $state = egmGamesState($context);
        if (egmGamesHasAnyScores($context, $state, $gameId)) throw new InvalidArgumentException('پس از ثبت اولین امتیاز، حذف مرحله ممکن نیست.');
        $found = false;
        foreach ($state['games'] as &$game) {
            if ($game['id'] !== $gameId) continue;
            if ($game['auto_room_manager']) throw new InvalidArgumentException('ابتدا مدیریت خودکار اتاق‌ها را خاموش کنید.');
            $before = count($game['levels']);
            $game['levels'] = array_values(array_filter($game['levels'], static fn(array $level): bool => $level['id'] !== $levelId));
            $found = count($game['levels']) !== $before;
            break;
        }
        unset($game);
        if (!$found) throw new InvalidArgumentException('مرحله پیدا نشد.');
        egmGamesWrite($context, $state);
    });
}

function egmGamesJson(array $data, int $status = 200): never
{
    if (isset($GLOBALS['egmGamesResponseBufferLevel'])) {
        while (ob_get_level() >= $GLOBALS['egmGamesResponseBufferLevel']) ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function egmGamesRequestInput(string $method): array
{
    if ($method !== 'POST') return $_GET;
    // Keep JSON clients working; browser forms avoid host filters on JSON bodies.
    $body = $_POST['payload'] ?? file_get_contents('php://input');
    if (!is_string($body)) throw new InvalidArgumentException('درخواست بازی نامعتبر است.');
    $input = json_decode($body, true);
    if (!is_array($input)) throw new InvalidArgumentException('درخواست بازی نامعتبر است.');
    return $input;
}

function handleEgmGamesRequest(string $missionDir, bool $canManage, bool $canPeriods, string $actorCode = ''): never
{
    try {
        $context = egmPeriodInvitesContext($missionDir);
        if ($context['code'] === '') throw new RuntimeException('EGM must be registered before managing games.');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = egmGamesRequestInput($method);
        $action = (string)($input['action'] ?? 'list');
        $state = egmGamesState($context);
        if ($method === 'GET' && $action === 'list') {
            if (!$canManage && !$canPeriods) egmGamesJson(['status' => 'error', 'message' => 'Access denied.'], 403);
            $periodCode = trim((string)($input['period_code'] ?? ''));
            if ($periodCode !== '') {
                if (!$canPeriods) egmGamesJson(['status' => 'error', 'message' => 'Access denied.'], 403);
                $periodCode = egmGamesValidatePeriod($context, $periodCode);
            }
            egmGamesJson(['status' => 'ok', 'games' => $state['games'], 'enabled' => $periodCode !== '' ? ($state['enabled'][$periodCode] ?? []) : []]);
        }
        if ($method !== 'POST') egmGamesJson(['status' => 'error', 'message' => 'Unsupported request.'], 405);
        if (!egmSecurityIsValidCsrfToken(trim((string)($input['csrf'] ?? '')))) egmGamesJson(['status' => 'error', 'message' => 'Invalid security token.'], 403);
        if (in_array($action, ['save', 'delete', 'save_level', 'delete_level', 'save_room', 'delete_room', 'save_limits', 'save_mode', 'save_gender_mode', 'save_auto_mode', 'end_team'], true) && !$canManage) egmGamesJson(['status' => 'error', 'message' => 'Access denied.'], 403);
        if ($action === 'set_enabled' && !$canPeriods) egmGamesJson(['status' => 'error', 'message' => 'Access denied.'], 403);
        if ($action === 'save') {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) throw new InvalidArgumentException('Game name must be 1–100 characters.');
            $id = trim((string)($input['id'] ?? ''));
            if ($id === '') {
                $id = bin2hex(random_bytes(8));
                $state['games'][] = ['id' => $id, 'name' => $name, 'has_levels' => false, 'gender_mode' => 'normal', 'auto_room_manager' => false, 'rooms' => [], 'levels' => [], 'min_players' => 1, 'max_players' => 20];
            } else {
                $found = false;
                foreach ($state['games'] as &$game) {
                    if ($game['id'] === $id) { $game['name'] = $name; $found = true; break; }
                }
                unset($game);
                if (!$found) throw new InvalidArgumentException('Game not found.');
            }
            egmGamesWrite($context, $state);
        } elseif ($action === 'save_mode') {
            if (!is_bool($input['has_levels'] ?? null)) throw new InvalidArgumentException('نوع امتیازدهی نامعتبر است.');
            egmGamesSaveMode($context, trim((string)($input['game_id'] ?? '')), $input['has_levels']);
            $state = egmGamesState($context);
        } elseif ($action === 'save_gender_mode') {
            egmGamesSaveGenderMode($context, trim((string)($input['game_id'] ?? '')), (string)($input['gender_mode'] ?? ''));
            $state = egmGamesState($context);
        } elseif ($action === 'save_auto_mode') {
            if (!is_bool($input['enabled'] ?? null)) throw new InvalidArgumentException('تنظیم مدیریت خودکار نامعتبر است.');
            egmGamesSaveAutoMode($context, trim((string)($input['game_id'] ?? '')), $input['enabled']);
            $state = egmGamesState($context);
        } elseif ($action === 'save_limits') {
            egmGamesSaveLimits($context, trim((string)($input['game_id'] ?? '')), $input['min_players'] ?? null, $input['max_players'] ?? null);
            $state = egmGamesState($context);
        } elseif ($action === 'save_level') {
            egmGamesSaveLevel($context, trim((string)($input['game_id'] ?? '')), trim((string)($input['level_id'] ?? '')), (string)($input['name'] ?? ''));
            $state = egmGamesState($context);
        } elseif ($action === 'delete_level') {
            egmGamesDeleteLevel($context, trim((string)($input['game_id'] ?? '')), trim((string)($input['level_id'] ?? '')));
            $state = egmGamesState($context);
        } elseif ($action === 'save_room') {
            egmGamesSaveRoom($context, trim((string)($input['game_id'] ?? '')), trim((string)($input['level_id'] ?? '')), trim((string)($input['room_id'] ?? '')), (string)($input['name'] ?? ''), (string)($input['gender'] ?? ''));
            $state = egmGamesState($context);
        } elseif ($action === 'delete_room') {
            egmGamesDeleteRoom($context, trim((string)($input['game_id'] ?? '')), trim((string)($input['level_id'] ?? '')), trim((string)($input['room_id'] ?? '')));
            $state = egmGamesState($context);
        } elseif ($action === 'delete') {
            $id = trim((string)($input['id'] ?? ''));
            $before = count($state['games']);
            foreach ($state['enabled'] as $periodCode => $ids) {
                if (in_array($id, $ids, true) && egmGamesTableHasTeams($context, (string)$periodCode, $id)) {
                    throw new InvalidArgumentException('این بازی تیم ثبت‌شده دارد و قابل حذف نیست.');
                }
            }
            $state['games'] = array_values(array_filter($state['games'], static fn($game): bool => $game['id'] !== $id));
            if (count($state['games']) === $before) throw new InvalidArgumentException('Game not found.');
            foreach ($state['enabled'] as $periodCode => &$ids) {
                if (in_array($id, $ids, true)) {
                    $table = egmGamesTableName((string)$context['code'], $periodCode, $id);
                    $context['pdo']->exec("DROP TABLE IF EXISTS `{$table}`");
                }
                $ids = array_values(array_filter($ids, static fn($item): bool => $item !== $id));
            }
            unset($ids);
            egmGamesWrite($context, $state);
        } elseif ($action === 'set_enabled') {
            $period = egmGamesValidatePeriod($context, trim((string)($input['period_code'] ?? '')));
            $id = trim((string)($input['id'] ?? ''));
            if (!in_array($id, array_column($state['games'], 'id'), true)) throw new InvalidArgumentException('Game not found.');
            $enabled = ($input['enabled'] ?? false) === true;
            if (!$enabled && egmGamesTableHasTeams($context, $period, $id)) {
                throw new InvalidArgumentException('این بازی در این بازه تیم ثبت‌شده دارد و قابل غیرفعال‌سازی نیست.');
            }
            $ids = array_values(array_diff($state['enabled'][$period] ?? [], [$id]));
            if ($enabled) {
                egmGamesEnsureTable($context, $period, $id);
                $ids[] = $id;
            }
            $state['enabled'][$period] = $ids;
            egmGamesWrite($context, $state);
        } elseif ($action === 'end_team') {
            require_once __DIR__ . '/egm-refmonitor-teams.php';
            $period = egmGamesValidatePeriod($context, trim((string)($input['period_code'] ?? '')));
            $id = trim((string)($input['game_id'] ?? ''));
            $teamId = (int)($input['team_id'] ?? 0);
            $team = egmRefMonitorEndTeam($context['pdo'], (string)$context['code'], $period, $id, $teamId, $actorCode);
            egmGamesJson(['status' => 'ok', 'team' => $team]);
        } elseif ($action === 'list_teams') {
            if (!$canManage && !$canPeriods) egmGamesJson(['status' => 'error', 'message' => 'Access denied.'], 403);
            require_once __DIR__ . '/egm-refmonitor-teams.php';
            $period = egmGamesValidatePeriod($context, trim((string)($input['period_code'] ?? '')));
            $id = trim((string)($input['game_id'] ?? ''));
            egmGamesJson(['status' => 'ok', 'teams' => egmRefMonitorListTeams($context['pdo'], (string)$context['code'], $period, $id)]);
        } else {
            egmGamesJson(['status' => 'error', 'message' => 'Unsupported action.'], 400);
        }
        egmGamesJson(['status' => 'ok', 'games' => $state['games'], 'enabled' => $action === 'set_enabled' ? ($state['enabled'][$period] ?? []) : []]);
    } catch (InvalidArgumentException $error) {
        egmGamesJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        $reference = bin2hex(random_bytes(4));
        error_log('EGM games failed [' . $reference . ']: ' . (string)$error);
        egmGamesJson(['status' => 'error', 'message' => 'ذخیره بازی ناموفق بود. کد پیگیری: ' . $reference . '؛ گزارش خطای PHP هاست را بررسی کنید.'], 500);
    }
}
