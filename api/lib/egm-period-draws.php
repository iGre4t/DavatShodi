<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-period-exports.php';

function egmPeriodDrawKey(string $period): string
{
    return 'period_draws:' . hash('sha256', $period);
}

function egmPeriodDrawCanAccess(array $context, array $user, string $period): bool
{
    $canManage = userHasPermissionId($user, 'event-guest-manager:manage-tasks');
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

function egmPeriodDrawEligible(array $rows, array $draw): array
{
    $won = array_fill_keys(array_column($draw['winners'] ?? [], 'participantKey'), true);
    $result = [];
    foreach ($rows as $row) {
        if (trim((string)($row['entered_date'] ?? '')) === '' || trim((string)($row['entered_time'] ?? '')) === '') continue;
        // Classification belongs to this period, never to a user's history in other periods.
        $walkIn = !empty($row['period_is_uninvited_guest']) || strtolower(trim((string)($row['invitation_source'] ?? ''))) === 'walk_in';
        if (empty($draw[$walkIn ? 'includeWalkIns' : 'includeEntered'])) continue;
        $key = (string)$row['user_id'];
        if (isset($won[$key]) || isset($result[$key])) continue;
        $work = (string)($row['work_id'] ?? '');
        $digits = strtr($work, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $digits = preg_replace('/\D/', '', $digits);
        $result[$key] = [
            'key' => $key, 'workId' => $work,
            'code' => str_pad(substr($digits !== '' ? $digits : (string)(($row['guest_number'] ?? '') ?: $key), -4), 4, '0', STR_PAD_LEFT),
            'fullName' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $work,
            'guestNumber' => (string)($row['guest_number'] ?? ''),
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
function egmPeriodDrawAction(array &$draws, string $action, array $input, array $rows, string $actor): array
{
    if ($action === 'list') return ['draws' => array_values(array_map('egmPeriodDrawPublic', $draws))];
    $id = (string)($input['levelId'] ?? $input['id'] ?? '');
    if ($action === 'create') {
        $settings = egmPeriodDrawSettings($input);
        $id = bin2hex(random_bytes(16));
        $draws[$id] = $settings + ['id' => $id, 'locked' => false, 'winners' => [], 'pending' => []];
        return ['draw' => egmPeriodDrawPublic($draws[$id])];
    }
    if (!isset($draws[$id])) throw new InvalidArgumentException('قرعه‌کشی پیدا نشد.');
    $draw =& $draws[$id];
    if ($action === 'unlock') { $draw['locked'] = false; return ['draw' => egmPeriodDrawPublic($draw)]; }
    if (!empty($draw['locked']) && !in_array($action, ['state', 'export'], true)) throw new InvalidArgumentException('قرعه‌کشی قفل است؛ ابتدا قفل را باز کنید.');
    if ($action === 'save') {
        $settings = egmPeriodDrawSettings($input);
        if ($settings['winnerLimit'] < count($draw['winners'])) throw new InvalidArgumentException('تعداد مجاز از برندگان ثبت‌شده کمتر است.');
        if ($draw['winners'] && ($settings['includeEntered'] !== $draw['includeEntered'] || $settings['includeWalkIns'] !== $draw['includeWalkIns'])) throw new InvalidArgumentException('برای تغییر گروه‌ها، ابتدا برندگان را بازنشانی کنید.');
        $draw = array_replace($draw, $settings, ['pending' => []]);
    } elseif ($action === 'delete') {
        unset($draws[$id]);
        return [];
    } elseif ($action === 'reset') {
        $draw['winners'] = []; $draw['pending'] = [];
    } elseif ($action === 'lock') {
        if (!$draw['winners'] || $draw['prizeName'] === '') throw new InvalidArgumentException('برای نهایی‌سازی، نام جایزه و حداقل یک برنده لازم است.');
        $draw['locked'] = true; $draw['pending'] = [];
    } elseif ($action === 'roll' || $action === 'confirm') {
        if (count($draw['winners']) >= $draw['winnerLimit']) throw new InvalidArgumentException('ظرفیت برندگان تکمیل شده است.');
        $eligible = egmPeriodDrawEligible($rows, $draw);
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
    $eligible = egmPeriodDrawEligible($rows, $draw);
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
        $rows = in_array($action, ['state', 'roll', 'confirm', 'export'], true) ? egmPeriodExportGuestRows($context, $period) : [];
        $result = egmPeriodDrawAction($draws, $action, $input, $rows, $actor);
        if (!in_array($action, ['list', 'state', 'export'], true)) egmInstanceWriteData($pdo, $context['code'], $key, $draws);
        return $result;
    } finally {
        $query = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $query->execute([':name' => $lock]);
    }
}
