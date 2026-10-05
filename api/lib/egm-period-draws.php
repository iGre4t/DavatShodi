<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-period-exports.php';

function egmPeriodDrawKey(string $period): string
{
    return 'period_draws:' . hash('sha256', $period);
}

function egmPeriodDrawActiveCode(array $context): string
{
    $state = egmCheckInResolveEgmPeriodState(
        egmInstanceReadPeriods($context['pdo'], $context['code']),
        new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'))
    );
    if (($state['result'] ?? '') !== 'active' || !is_array($state['period'] ?? null)) {
        throw new InvalidArgumentException(($state['result'] ?? '') === 'multiple_active_periods'
            ? 'چند بازه هم‌زمان فعال هستند؛ قرعه‌کشی تا رفع هم‌پوشانی ممکن نیست.'
            : 'در حال حاضر بازهٔ فعالی برای قرعه‌کشی وجود ندارد.');
    }
    return egmCheckInPeriodCode($state['period']);
}

function egmPeriodDrawPotLevel(array $context, string $levelId): ?array
{
    $path = $context['mission_dir'] . '/EGM Prize Levels.json';
    $levels = egmDbIsFile($path) ? json_decode((string)egmDbFileGetContents($path), true) : null;
    foreach (is_array($levels) ? $levels : [] as $level) {
        if (!is_array($level) || (string)($level['id'] ?? '') !== $levelId || (string)($level['type'] ?? '') !== 'pot') continue;
        $pot = is_array($level['potSettings'] ?? null) ? $level['potSettings'] : [];
        $name = trim((string)($level['name'] ?? '')) ?: 'قرعه‌کشی';
        return [
            'name' => $name,
            'title' => trim((string)($pot['title'] ?? '')) ?: $name,
            'prizeName' => trim((string)($pot['prizeName'] ?? '')),
            'description' => trim((string)($pot['description'] ?? '')),
            'winnerLimit' => max(1, min(1000, (int)($pot['winnerLimit'] ?? 1))),
            'includeEntered' => true,
            'includeWalkIns' => true,
        ];
    }
    return null;
}

function egmPeriodDrawCanAccess(array $context, array $user, string $period): bool
{
    $canManage = userHasPermissionId($user, 'event-guest-manager:manage-tasks')
        || userHasPermissionId($user, 'event-guest-manager:main');
    if ($canManage) return true;
    $path = $context['mission_dir'] . '/tasks/task-access.json';
    $rules = egmDbIsFile($path) ? json_decode((string)egmDbFileGetContents($path), true) : null;
    foreach (($rules['users'] ?? []) as $code => $entry) {
        if (!is_array($entry) || strcasecmp(trim((string)$code), trim((string)($user['code'] ?? ''))) !== 0) continue;
        if (egmInstanceBoolValue($entry['allowManageTasksTab'] ?? $entry['allow_manage_tasks_tab'] ?? false)) return true;
        foreach (egmPeriodInvitesPeriods($context) as $item) {
            if ((string)($item['tagCode'] ?? $item['code'] ?? '') !== $period) continue;
            $rule = null;
            foreach (($entry['tasks'] ?? []) as $taskId => $taskRule) {
                if (strcasecmp(trim((string)$taskId), trim((string)($item['id'] ?? ''))) === 0) $rule = $taskRule;
            }
            return is_array($rule) && egmInstanceBoolValue($rule['enabled'] ?? true)
                && egmInstanceBoolValue($rule['panes']['draws'] ?? true);
        }
        return false;
    }
    return $canManage;
}

function egmPeriodDrawSettings(array $input): array
{
    $settings = [];
    foreach (['name', 'title', 'prizeName', 'description'] as $key) {
        $settings[$key] = tctPeriodInviteClean($input[$key] ?? '', $key === 'description' ? 4000 : 160);
    }
    if ($settings['name'] === '') throw new InvalidArgumentException('نام قرعه‌کشی را وارد کنید.');
    $settings['title'] = $settings['title'] ?: $settings['name'];
    $settings['winnerLimit'] = filter_var($input['winnerLimit'] ?? 1, FILTER_VALIDATE_INT);
    if (!$settings['winnerLimit'] || $settings['winnerLimit'] < 1 || $settings['winnerLimit'] > 1000) throw new InvalidArgumentException('تعداد برندگان باید بین ۱ و ۱۰۰۰ باشد.');
    $settings['includeEntered'] = !empty($input['includeEntered']);
    $settings['includeWalkIns'] = !empty($input['includeWalkIns']);
    if (!$settings['includeEntered'] && !$settings['includeWalkIns']) throw new InvalidArgumentException('حداقل یک گروه مهمان را انتخاب کنید.');
    return $settings;
}

/** @return array{0:string,1:string}|null Inclusive entry minutes, or no restriction. */
function egmPeriodDrawEntryWindow(array $period): ?array
{
    if (!egmCheckInBool($period['prizeEntryWindowEnabled'] ?? ($period['prize_entry_window_enabled'] ?? false))) return null;
    $values = [
        (string)($period['prizeEntryStartDate'] ?? ($period['prize_entry_start_date'] ?? '')),
        (string)($period['prizeEntryStartTime'] ?? ($period['prize_entry_start_time'] ?? '')),
        (string)($period['prizeEntryEndDate'] ?? ($period['prize_entry_end_date'] ?? '')),
        (string)($period['prizeEntryEndTime'] ?? ($period['prize_entry_end_time'] ?? '')),
    ];
    if (in_array('', array_map('trim', $values), true)) throw new InvalidArgumentException('زمان واجدان شرایط قرعه‌کشی این بازه کامل نیست.');
    $timezone = new DateTimeZone('Asia/Tehran');
    $start = egmCheckInDateTime($values[0], $values[1], false, $timezone);
    $end = egmCheckInDateTime($values[2], $values[3], true, $timezone);
    if (!$start || !$end || $end <= $start) throw new InvalidArgumentException('زمان واجدان شرایط قرعه‌کشی این بازه معتبر نیست.');
    return [$start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')];
}

function egmPeriodDrawEligible(array $rows, array $draw, array $period = []): array
{
    $entryWindow = egmPeriodDrawEntryWindow($period);
    $won = array_fill_keys(array_column($draw['winners'] ?? [], 'participantKey'), true);
    $result = [];
    foreach ($rows as $row) {
        if (trim((string)($row['entered_date'] ?? '')) === '' || trim((string)($row['entered_time'] ?? '')) === '') continue;
        if ($entryWindow !== null) {
            $entryMinute = trim((string)$row['entered_date']) . ' ' . substr(trim((string)$row['entered_time']), 0, 5);
            if ($entryMinute < $entryWindow[0] || $entryMinute > $entryWindow[1]) continue;
        }
        // Classification belongs to this period, never to a user's history in other periods.
        $walkIn = !empty($row['period_is_uninvited_guest']) || strtolower(trim((string)($row['invitation_source'] ?? ''))) === 'walk_in';
        if (empty($draw[$walkIn ? 'includeWalkIns' : 'includeEntered'])) continue;
        $key = (string)$row['user_id'];
        if (isset($won[$key]) || isset($result[$key])) continue;
        $work = (string)($row['work_id'] ?? '');
        $guestNumber = trim((string)($row['guest_number'] ?? ''));
        if ($guestNumber === '') continue;
        $result[$key] = [
            'key' => $key, 'workId' => $work,
            'code' => $guestNumber,
            'fullName' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $work,
            'guestNumber' => $guestNumber,
            'nationalId' => (string)($row['national_id'] ?? ''), 'phoneNumber' => (string)($row['phone_number'] ?? ''),
            'guestType' => $walkIn ? 'walk_in' : 'entered',
        ];
    }
    return array_values($result);
}

function egmPeriodDrawPublic(array $draw): array
{
    unset($draw['pending']);
    return $draw;
}

/** State machine used under a per-instance, per-period database lock. */
function egmPeriodDrawAction(array &$draws, string $action, array $input, array $rows, string $actor, array $period = []): array
{
    if ($action === 'list') return ['draws' => array_values(array_map('egmPeriodDrawPublic', $draws))];
    $id = (string)($input['levelId'] ?? $input['id'] ?? '');
    if ($action === 'ensure_pot') {
        $potLevel = $input['potLevel'] ?? null;
        if (!is_array($potLevel) || $id === '') throw new InvalidArgumentException('سطح جایزهٔ قرعه‌کشی پیدا نشد.');
        if (!isset($draws[$id])) $draws[$id] = $potLevel + ['id' => $id, 'locked' => false, 'winners' => [], 'pending' => []];
        return ['draw' => egmPeriodDrawPublic($draws[$id])];
    }
    if ($action === 'create') {
        $settings = egmPeriodDrawSettings($input);
        $id = bin2hex(random_bytes(16));
        $draws[$id] = $settings + ['id' => $id, 'locked' => false, 'winners' => [], 'pending' => []];
        return ['draw' => egmPeriodDrawPublic($draws[$id])];
    }
    if (!isset($draws[$id])) throw new InvalidArgumentException('قرعه‌کشی پیدا نشد.');
    $draw =& $draws[$id];
    if ($action === 'unlock') { $draw['locked'] = false; return ['draw' => egmPeriodDrawPublic($draw)]; }
    if (!empty($draw['locked']) && !in_array($action, ['state', 'export', 'reset'], true)) throw new InvalidArgumentException('قرعه‌کشی قفل است؛ ابتدا قفل را باز کنید.');
    if ($action === 'save') {
        $settings = egmPeriodDrawSettings($input);
        if ($settings['winnerLimit'] < count($draw['winners'])) throw new InvalidArgumentException('تعداد مجاز از برندگان ثبت‌شده کمتر است.');
        if ($draw['winners'] && ($settings['includeEntered'] !== $draw['includeEntered'] || $settings['includeWalkIns'] !== $draw['includeWalkIns'])) throw new InvalidArgumentException('برای تغییر گروه‌ها، ابتدا برندگان را بازنشانی کنید.');
        $draw = array_replace($draw, $settings, ['pending' => []]);
    } elseif ($action === 'delete') {
        unset($draws[$id]);
        return [];
    } elseif ($action === 'reset') {
        $draw['winners'] = []; $draw['pending'] = []; $draw['locked'] = false;
    } elseif ($action === 'lock') {
        if (!$draw['winners'] || $draw['prizeName'] === '') throw new InvalidArgumentException('برای نهایی‌سازی، نام جایزه و حداقل یک برنده لازم است.');
        $draw['locked'] = true; $draw['pending'] = [];
    } elseif ($action === 'roll' || $action === 'confirm') {
        if (count($draw['winners']) >= $draw['winnerLimit']) throw new InvalidArgumentException('ظرفیت برندگان تکمیل شده است.');
        $eligible = egmPeriodDrawEligible($rows, $draw, $period);
        if (!$eligible) throw new InvalidArgumentException('مهمان واجد شرایط باقی نمانده است.');
        if ($action === 'roll') {
            $candidate = $eligible[random_int(0, count($eligible) - 1)];
            $draw['pending'][$actor] = ['key' => $candidate['key'], 'expires' => time() + 3600];
            return ['participant' => $candidate];
        }
        $pending = $draw['pending'][$actor] ?? [];
        if (($pending['expires'] ?? 0) < time()) throw new InvalidArgumentException('انتخاب منقضی شده؛ دوباره قرعه‌کشی کنید.');
        if ((string)($input['candidateKey'] ?? '') !== (string)($pending['key'] ?? '')) throw new InvalidArgumentException('انتخاب در صفحه دیگری تغییر کرده؛ دوباره قرعه‌کشی کنید.');
        $candidate = null;
        foreach ($eligible as $item) if ($item['key'] === ($pending['key'] ?? null)) $candidate = $item;
        if (!$candidate) throw new InvalidArgumentException('این مهمان دیگر واجد شرایط نیست یا قبلاً برنده شده است.');
        $draw['winners'][] = $candidate + ['participantKey' => $candidate['key'], 'selectedAt' => gmdate('c'), 'selectedBy' => $actor];
        unset($draw['pending'][$actor]);
    } elseif (!in_array($action, ['state', 'export'], true)) throw new InvalidArgumentException('عملیات نامعتبر است.');
    $eligible = egmPeriodDrawEligible($rows, $draw, $period);
    return ['draw' => egmPeriodDrawPublic($draw), 'level' => ['name' => $draw['name'], 'potSettings' => egmPeriodDrawPublic($draw)],
        'winners' => $draw['winners'], 'eligibleParticipants' => $eligible, 'eligibleCount' => count($eligible)];
}

function egmPeriodDrawRun(array $context, string $period, string $action, array $input, string $actor): array
{
    if (empty($context['code']) || empty($context['tables'])) throw new RuntimeException('This EGM must use database storage.');
    $pdo = $context['pdo'];
    $key = egmPeriodDrawKey($period);
    $lock = 'egm_draw_' . substr(hash('sha256', $context['code'] . ':' . $period), 0, 48);
    $query = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
    $query->execute([':name' => $lock]);
    if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Draw is busy. Retry.');
    try {
        $draws = egmInstanceReadData($pdo, $context['code'], $key, []);
        if ($action === 'ensure_pot') {
            $input['potLevel'] = egmPeriodDrawPotLevel($context, (string)($input['levelId'] ?? ''));
        }
        $periodSettings = [];
        if (in_array($action, ['state', 'roll', 'confirm', 'export'], true)) {
            foreach (egmPeriodInvitesPeriods($context) as $item) {
                if (egmCheckInPeriodCode($item) === $period) { $periodSettings = $item; break; }
            }
            egmInstanceAssignGuestNumbers($pdo, $context['code']);
            $rows = egmPeriodExportGuestRows($context, $period);
        } else {
            $rows = [];
        }
        $result = egmPeriodDrawAction($draws, $action, $input, $rows, $actor, $periodSettings);
        if (!in_array($action, ['list', 'state', 'export'], true)) egmInstanceWriteData($pdo, $context['code'], $key, $draws);
        return $result;
    } finally {
        $query = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $query->execute([':name' => $lock]);
    }
}
