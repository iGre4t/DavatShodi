<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/tab-permissions.php';
require_once __DIR__ . '/egm-instance-storage.php';

const EGM_CHECK_IN_ACTION = 'egm_period_check_in';

function egmCheckInNormalizeDigits($value): string
{
    $value = strtr(trim((string)$value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    return preg_match('/^[0-9]+$/D', $value) === 1 ? $value : '';
}

function egmCheckInNormalizeNationalId($value): string
{
    $value = egmCheckInNormalizeDigits($value);
    return strlen($value) === 10 ? $value : '';
}

function egmCheckInNormalizeGuestCode($value): string
{
    $value = egmCheckInNormalizeDigits($value);
    $length = strlen($value);
    return $length >= 4 && $length <= 10 ? $value : '';
}

function egmCheckInNormalizeWorkId($value): string
{
    $value = egmCheckInNormalizeDigits($value);
    $length = strlen($value);
    return $length >= 4 && $length <= 9 ? $value : '';
}

function egmCheckInBool($value): bool
{
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return (int)$value === 1;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function egmCheckInQuitTimelineRequired(array $period): bool
{
    return egmCheckInBool($period['quitTimelineRequired'] ?? ($period['quit_timeline_required'] ?? true));
}

function egmCheckInMinimumStayMinutes(array $period): int
{
    $raw = $period['minimumStayMinutes'] ?? ($period['minimum_stay_minutes'] ?? 1);
    $minutes = is_numeric($raw) ? (int)$raw : 1;
    return max(1, min(1440, $minutes));
}

function egmCheckInQuitWaveActive(int $invitedUsers, int $completedQuits): bool
{
    return $invitedUsers > 0 && $completedQuits > 0 && ($completedQuits * 100) > ($invitedUsers * 20);
}

/**
 * @return array{eligible:bool,action:?string,reason:string,remaining_minutes:int}
 */
function egmCheckInFlexibleAttendanceDecision(
    array $invitation,
    DateTimeImmutable $now,
    int $minimumStayMinutes,
    bool $quitWaveActive
): array {
    $enteredDate = trim((string)($invitation['entered_date'] ?? ''));
    $enteredTime = trim((string)($invitation['entered_time'] ?? ''));
    $quitDate = trim((string)($invitation['quit_date'] ?? ''));
    $quitTime = trim((string)($invitation['quit_time'] ?? ''));
    $hasEntryDate = $enteredDate !== '';
    $hasEntryTime = $enteredTime !== '';
    $hasQuitDate = $quitDate !== '';
    $hasQuitTime = $quitTime !== '';

    if ($hasEntryDate !== $hasEntryTime || $hasQuitDate !== $hasQuitTime) {
        return ['eligible' => false, 'action' => null, 'reason' => 'invalid_attendance_record', 'remaining_minutes' => 0];
    }
    if (!$hasEntryDate) {
        return $quitWaveActive
            ? ['eligible' => false, 'action' => null, 'reason' => 'entry_closed_quit_wave', 'remaining_minutes' => 0]
            : ['eligible' => true, 'action' => 'entry', 'reason' => 'flexible_entry', 'remaining_minutes' => 0];
    }
    if ($hasQuitDate) {
        return ['eligible' => true, 'action' => 'quit', 'reason' => 'flexible_quit', 'remaining_minutes' => 0];
    }
    if ($quitWaveActive) {
        return ['eligible' => true, 'action' => 'quit', 'reason' => 'quit_wave', 'remaining_minutes' => 0];
    }

    $enteredAt = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $enteredDate . ' ' . (strlen($enteredTime) === 5 ? $enteredTime . ':00' : $enteredTime),
        $now->getTimezone()
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (!$enteredAt || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        return ['eligible' => false, 'action' => null, 'reason' => 'invalid_attendance_record', 'remaining_minutes' => 0];
    }
    $minimumStayMinutes = max(1, min(1440, $minimumStayMinutes));
    $remainingSeconds = ($enteredAt->getTimestamp() + ($minimumStayMinutes * 60)) - $now->getTimestamp();
    if ($remainingSeconds > 0) {
        return [
            'eligible' => false,
            'action' => null,
            'reason' => 'minimum_stay',
            'remaining_minutes' => (int)ceil($remainingSeconds / 60),
        ];
    }
    return ['eligible' => true, 'action' => 'quit', 'reason' => 'flexible_quit', 'remaining_minutes' => 0];
}

function egmCheckInPeriodCode(array $period): string
{
    return trim((string)($period['tagCode'] ?? ($period['tag_code'] ?? '')));
}

function egmCheckInDateTime(string $date, string $time, bool $end, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = trim($date);
    $time = trim($time);
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) return null;
    if ($time === '') $time = $end ? '23:59' : '00:00';
    if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time) !== 1) return null;
    $value = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $date . ' ' . $time . ($end ? ':59' : ':00'),
        $timezone
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (!$value || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        return null;
    }
    return $value;
}

/**
 * Resolve the attendance phase for one period.
 *
 * @return array{eligible:bool,scheduled:bool,reason:string,action:?string,quit_required:bool,quit_timeline_required:bool}
 */
function egmCheckInPeriodAvailability(array $period, DateTimeImmutable $now): array
{
    $scheduled = egmCheckInBool($period['duration'] ?? false);
    $quitRequired = egmCheckInBool($period['quitRequired'] ?? ($period['quit_required'] ?? false));
    $quitTimelineRequired = egmCheckInQuitTimelineRequired($period);
    if (!$scheduled) {
        $active = !array_key_exists('active', $period) || egmCheckInBool($period['active']);
        return [
            'eligible' => $active && !$quitRequired,
            'scheduled' => false,
            'reason' => $active ? ($quitRequired ? 'invalid_schedule' : 'entry_time') : 'inactive',
            'action' => $active && !$quitRequired ? 'entry' : null,
            'quit_required' => $quitRequired,
            'quit_timeline_required' => $quitTimelineRequired,
        ];
    }

    $timezone = $now->getTimezone();
    $start = egmCheckInDateTime(
        (string)($period['startDate'] ?? ($period['start_date'] ?? '')),
        (string)($period['startTime'] ?? ($period['start_time'] ?? '')),
        false,
        $timezone
    );
    $end = egmCheckInDateTime(
        (string)($period['endDate'] ?? ($period['end_date'] ?? '')),
        (string)($period['endTime'] ?? ($period['end_time'] ?? '')),
        false,
        $timezone
    );
    if (!$start || !$end || $end <= $start) {
        return ['eligible' => false, 'scheduled' => true, 'reason' => 'invalid_schedule', 'action' => null, 'quit_required' => $quitRequired, 'quit_timeline_required' => $quitTimelineRequired];
    }
    if ($now < $start) {
        return ['eligible' => false, 'scheduled' => true, 'reason' => 'upcoming', 'action' => null, 'quit_required' => $quitRequired, 'quit_timeline_required' => $quitTimelineRequired];
    }
    if ($now >= $end) {
        return ['eligible' => false, 'scheduled' => true, 'reason' => 'ended', 'action' => null, 'quit_required' => $quitRequired, 'quit_timeline_required' => $quitTimelineRequired];
    }
    if (!$quitRequired) {
        return ['eligible' => true, 'scheduled' => true, 'reason' => 'entry_time', 'action' => 'entry', 'quit_required' => false, 'quit_timeline_required' => $quitTimelineRequired];
    }
    if (!$quitTimelineRequired) {
        return ['eligible' => true, 'scheduled' => true, 'reason' => 'flexible_attendance', 'action' => 'auto', 'quit_required' => true, 'quit_timeline_required' => false];
    }

    $enterDeadline = egmCheckInDateTime(
        (string)($period['enterDeadlineDate'] ?? ($period['enter_deadline_date'] ?? '')),
        (string)($period['enterDeadlineTime'] ?? ($period['enter_deadline_time'] ?? '')),
        false,
        $timezone
    );
    $quitOpening = egmCheckInDateTime(
        (string)($period['quitOpeningDate'] ?? ($period['quit_opening_date'] ?? '')),
        (string)($period['quitOpeningTime'] ?? ($period['quit_opening_time'] ?? '')),
        false,
        $timezone
    );
    if (!$enterDeadline || !$quitOpening || $enterDeadline <= $start || $quitOpening <= $enterDeadline || $quitOpening >= $end) {
        return ['eligible' => false, 'scheduled' => true, 'reason' => 'invalid_schedule', 'action' => null, 'quit_required' => true, 'quit_timeline_required' => true];
    }
    if ($now < $enterDeadline) {
        return ['eligible' => true, 'scheduled' => true, 'reason' => 'entry_time', 'action' => 'entry', 'quit_required' => true, 'quit_timeline_required' => true];
    }
    if ($now < $quitOpening) {
        return ['eligible' => false, 'scheduled' => true, 'reason' => 'immune_time', 'action' => null, 'quit_required' => true, 'quit_timeline_required' => true];
    }
    return ['eligible' => true, 'scheduled' => true, 'reason' => 'quit_time', 'action' => 'quit', 'quit_required' => true, 'quit_timeline_required' => true];
}

/** @return array{start:?DateTimeImmutable,end:?DateTimeImmutable} */
function egmCheckInPeriodWindow(array $period, DateTimeZone $timezone): array
{
    if (!egmCheckInBool($period['duration'] ?? false)) {
        return ['start' => null, 'end' => null];
    }
    return [
        'start' => egmCheckInDateTime(
            (string)($period['startDate'] ?? ($period['start_date'] ?? '')),
            (string)($period['startTime'] ?? ($period['start_time'] ?? '')),
            false,
            $timezone
        ),
        'end' => egmCheckInDateTime(
            (string)($period['endDate'] ?? ($period['end_date'] ?? '')),
            (string)($period['endTime'] ?? ($period['end_time'] ?? '')),
            false,
            $timezone
        ),
    ];
}

/**
 * Resolve one EGM-wide period from the clock, independently of guest invitations.
 *
 * @return array{result:string,period:?array,availability:?array,previous:?array,next:?array,active_count:int}
 */
function egmCheckInResolveEgmPeriodState(array $periods, DateTimeImmutable $now): array
{
    $active = [];
    $previous = null;
    $previousEnd = null;
    $next = null;
    $nextStart = null;
    foreach ($periods as $period) {
        if (!is_array($period) || egmCheckInPeriodCode($period) === '') continue;
        $availability = egmCheckInPeriodAvailability($period, $now);
        if (in_array((string)$availability['reason'], ['entry_time', 'immune_time', 'quit_time', 'flexible_attendance'], true)) {
            $active[] = ['period' => $period, 'availability' => $availability];
        }
        $window = egmCheckInPeriodWindow($period, $now->getTimezone());
        $start = $window['start'];
        $end = $window['end'];
        if (!$start || !$end || $end <= $start) continue;
        if ($end <= $now && (!$previousEnd || $end > $previousEnd)) {
            $previous = $period;
            $previousEnd = $end;
        }
        if ($start > $now && (!$nextStart || $start < $nextStart)) {
            $next = $period;
            $nextStart = $start;
        }
    }
    if (count($active) === 1) {
        return [
            'result' => 'active',
            'period' => $active[0]['period'],
            'availability' => $active[0]['availability'],
            'previous' => $previous,
            'next' => $next,
            'active_count' => 1,
        ];
    }
    return [
        'result' => count($active) > 1 ? 'multiple_active_periods' : 'no_active_period',
        'period' => null,
        'availability' => null,
        'previous' => $previous,
        'next' => $next,
        'active_count' => count($active),
    ];
}

/** @return array{invitation:?array,period:?array,result:string} */
function egmCheckInSelectInvitation(array $invitations, array $periods, DateTimeImmutable $now): array
{
    if (!$invitations) return ['invitation' => null, 'period' => null, 'result' => 'not_invited'];
    $periodMap = [];
    foreach ($periods as $period) {
        if (!is_array($period)) continue;
        $code = egmCheckInPeriodCode($period);
        if ($code !== '') $periodMap[$code] = $period;
    }
    $scheduled = [];
    $unscheduled = [];
    foreach ($invitations as $invitation) {
        $code = trim((string)($invitation['period_code'] ?? ''));
        $period = $periodMap[$code] ?? null;
        if (!is_array($period)) continue;
        $availability = egmCheckInPeriodAvailability($period, $now);
        if (!$availability['eligible']) continue;
        $candidate = ['invitation' => $invitation, 'period' => $period];
        if ($availability['scheduled']) $scheduled[] = $candidate;
        else $unscheduled[] = $candidate;
    }
    $eligible = $scheduled ?: $unscheduled;
    if (count($eligible) === 1) {
        return $eligible[0] + ['result' => 'eligible'];
    }
    if (count($eligible) > 1) {
        return ['invitation' => null, 'period' => null, 'result' => 'ambiguous'];
    }
    return ['invitation' => null, 'period' => null, 'result' => 'outside_schedule'];
}

function egmCheckInContext(
    string $projectRoot,
    string $missionDir,
    string $legacyPeriodCode = '',
    ?DateTimeImmutable $now = null
): array
{
    $config = loadConfig(rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/api/config.php');
    $pdo = connectDatabase($config);
    if (!$pdo instanceof PDO) throw new RuntimeException('اتصال به پایگاه داده برقرار نشد.');
    $registry = egmInstanceRegistryForDirectory($pdo, $missionDir);
    if (!is_array($registry)) throw new RuntimeException('این رویداد در پایگاه داده ثبت نشده است.');
    $code = normalizeEgmInstanceCode($registry['code'] ?? '');
    if ($code === '') throw new RuntimeException('شناسه رویداد نامعتبر است.');
    $tables = ensureEgmInstanceTables($pdo, $code);
    $logsPdo = connectActivityLogDatabase($config);
    if (!$logsPdo instanceof PDO) throw new RuntimeException('اتصال به پایگاه دادهٔ لاگ‌ها برقرار نشد.');
    ensureActivityLogTable($logsPdo, 'EGM', $code);
    egmInstanceEnrichUsersFromOeu($pdo, $code);
    $record = findEgmRegistryByCode($pdo, $code);
    $periods = egmInstanceReadPeriods($pdo, $code);
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $periodState = egmCheckInResolveEgmPeriodState($periods, $now);
    $selectedPeriod = is_array($periodState['period'] ?? null) ? $periodState['period'] : null;
    return [
        'pdo' => $pdo,
        'logs_pdo' => $logsPdo,
        'code' => $code,
        'name' => trim((string)($record['name'] ?? '')) ?: 'رویداد',
        'tables' => $tables,
        'periods' => $periods,
        'period' => $selectedPeriod,
        'period_code' => is_array($selectedPeriod) ? egmCheckInPeriodCode($selectedPeriod) : '',
        'logs_period_code' => '',
        'period_state' => $periodState,
        'previous_period' => $periodState['previous'] ?? null,
        'next_period' => $periodState['next'] ?? null,
        'can_scan' => ($periodState['result'] ?? '') === 'active',
    ];
}

function egmCheckInFindUser(PDO $pdo, string $usersTable, string $submittedCode): ?array
{
    $nationalId = egmCheckInNormalizeNationalId($submittedCode);
    if ($nationalId !== '') {
        $statement = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `national_id` = :national_id LIMIT 1 FOR UPDATE");
        $statement->execute([':national_id' => $nationalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) return $row;
        $fallback = $pdo->query(
            "SELECT * FROM `{$usersTable}` WHERE `national_id` IS NOT NULL AND TRIM(`national_id`) <> '' FOR UPDATE"
        );
        $match = null;
        foreach ($fallback ? $fallback->fetchAll(PDO::FETCH_ASSOC) : [] as $candidate) {
            if (egmCheckInNormalizeNationalId($candidate['national_id'] ?? '') !== $nationalId) continue;
            if (is_array($match)) throw new RuntimeException('کد ملی تکراری است و باید ابتدا تعارض کاربر برطرف شود.');
            $match = $candidate;
        }
        if (is_array($match)) return $match;
    }

    $workId = egmCheckInNormalizeWorkId($submittedCode);
    if ($workId === '') return null;
    $statement = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `work_id` = :work_id LIMIT 1 FOR UPDATE");
    $statement->execute([':work_id' => $workId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) return $row;
    $fallback = $pdo->query(
        "SELECT * FROM `{$usersTable}` WHERE `work_id` IS NOT NULL AND TRIM(`work_id`) <> '' FOR UPDATE"
    );
    $match = null;
    foreach ($fallback ? $fallback->fetchAll(PDO::FETCH_ASSOC) : [] as $candidate) {
        if (egmCheckInNormalizeWorkId($candidate['work_id'] ?? '') !== $workId) continue;
        if (is_array($match)) throw new RuntimeException('کد پرسنلی تکراری است و باید ابتدا تعارض کاربر برطرف شود.');
        $match = $candidate;
    }
    return $match;
}

function egmCheckInWriteLog(array $context, ?array $user, string $submittedCode, string $status, string $message, ?array $period, DateTimeImmutable $now, array $extra = []): void
{
    $table = (string)$context['tables']['activity_logs'];
    $periodCode = is_array($period) ? egmCheckInPeriodCode($period) : '';
    $periodTitle = is_array($period) ? trim((string)($period['title'] ?? '')) : '';
    $lookupType = strlen($submittedCode) === 10 ? 'national_id' : 'work_id';
    $nationalId = is_array($user)
        ? trim((string)($user['national_id'] ?? ''))
        : ($lookupType === 'national_id' ? $submittedCode : '');
    $workId = is_array($user)
        ? trim((string)($user['work_id'] ?? ''))
        : ($lookupType === 'work_id' ? $submittedCode : '');
    $metadata = json_encode([
        'national_id' => $nationalId,
        'work_id' => $workId,
        'submitted_code' => $submittedCode,
        'lookup_type' => $lookupType,
        'period_code' => $periodCode,
        'period_title' => $periodTitle,
    ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logsPdo = $context['logs_pdo'] ?? $context['pdo'];
    if (!$logsPdo instanceof PDO) throw new RuntimeException('Activity logs database is unavailable.');
    $statement = $logsPdo->prepare(
        "INSERT INTO `{$table}` (`source_key`, `user_id`, `work_id`, `session_id`, `level`, `action`, "
        . "`entity_type`, `entity_id`, `ip_address`, `user_agent`, `status`, `message`, `metadata_json`, `occurred_at`) "
        . "VALUES (:source_key, :user_id, :work_id, :session_id, :level, :action, 'period', :entity_id, "
        . ":ip_address, :user_agent, :status, :message, :metadata_json, :occurred_at)"
    );
    $statement->execute([
        ':source_key' => hash('sha256', random_bytes(24) . microtime(true)),
        ':user_id' => is_array($user) ? (int)($user['id'] ?? 0) ?: null : null,
        ':work_id' => $workId !== '' ? $workId : null,
        ':session_id' => session_id() ?: null,
        ':level' => in_array($status, ['success', 'quit_success', 'force_entry_success', 'force_quit_success', 'walk_in_registered'], true) ? 'info' : 'warning',
        ':action' => EGM_CHECK_IN_ACTION,
        ':entity_id' => $periodCode !== '' ? $periodCode : null,
        ':ip_address' => substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45) ?: null,
        ':user_agent' => substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 512) ?: null,
        ':status' => substr($status, 0, 32),
        ':message' => $message,
        ':metadata_json' => is_string($metadata) ? $metadata : '{}',
        ':occurred_at' => $now->format('Y-m-d H:i:s'),
    ]);
}

function egmCheckInSavePeriodCondition(
    array $context,
    int $invitationId,
    string $condition,
    string $action,
    string $message,
    DateTimeImmutable $now,
    ?string $attendanceState = null
): void {
    if ($invitationId < 1) return;
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    $sets = [
        '`last_control_condition` = :condition',
        '`last_control_action` = :action',
        '`last_control_message` = :message',
        '`last_control_at` = :controlled_at',
    ];
    $params = [
        ':condition' => substr($condition, 0, 32),
        ':action' => substr($action, 0, 16),
        ':message' => function_exists('mb_substr') ? mb_substr($message, 0, 1000, 'UTF-8') : substr($message, 0, 1000),
        ':controlled_at' => $now->format('Y-m-d H:i:s'),
        ':id' => $invitationId,
    ];
    if ($attendanceState !== null) {
        $sets[] = '`attendance_state` = :attendance_state';
        $params[':attendance_state'] = substr($attendanceState, 0, 32);
    }
    $statement = $context['pdo']->prepare(
        "UPDATE `{$userPeriodsTable}` SET " . implode(', ', $sets) . ' WHERE `id` = :id'
    );
    $statement->execute($params);
}

/**
 * Clears Guest Control attendance state without removing users, invitations,
 * periods, Invite Cards, or walk-in registration identity.
 *
 * @return array{scope:string,period_code:string,attendance_records:int,log_records:int,message:string}
 */
function egmCheckInResetAttendanceRecords(array $context, ?string $periodCode = null): array
{
    $periodCode = trim((string)$periodCode);
    $resetAll = $periodCode === '';
    if (!$resetAll) {
        $knownCodes = [];
        foreach ((array)($context['periods'] ?? []) as $period) {
            if (!is_array($period)) continue;
            $code = egmCheckInPeriodCode($period);
            if ($code !== '') $knownCodes[$code] = true;
        }
        if (!isset($knownCodes[$periodCode])) {
            throw new InvalidArgumentException('بازه انتخاب‌شده معتبر نیست.');
        }
    }

    $pdo = $context['pdo'] ?? null;
    $logsPdo = $context['logs_pdo'] ?? null;
    if (!$pdo instanceof PDO || !$logsPdo instanceof PDO) {
        throw new RuntimeException('پایگاه داده حضور یا گزارش‌ها در دسترس نیست.');
    }
    $userPeriodsTable = (string)($context['tables']['user_periods'] ?? '');
    $logsTable = (string)($context['tables']['activity_logs'] ?? '');
    if ($userPeriodsTable === '' || $logsTable === '') {
        throw new RuntimeException('جدول‌های حضور رویداد در دسترس نیستند.');
    }

    $where = $resetAll ? '' : ' WHERE `period_code` = :period_code';
    $update = $pdo->prepare(
        "UPDATE `{$userPeriodsTable}` SET "
        . '`entered_date`=NULL, `entered_time`=NULL, `quit_date`=NULL, `quit_time`=NULL, '
        . '`correct_presence`=0, `fake_presence`=0, '
        . "`attendance_state`='not_entered', `last_control_condition`=NULL, `last_control_action`=NULL, "
        . '`last_control_message`=NULL, `last_control_at`=NULL'
        . $where
    );
    $pdo->beginTransaction();
    try {
        $update->execute($resetAll ? [] : [':period_code' => $periodCode]);
        $attendanceRecords = $update->rowCount();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $deleteSql = "DELETE FROM `{$logsTable}` WHERE `action` = :action";
    if (!$resetAll) $deleteSql .= ' AND `entity_id` = :period_code';
    $delete = $logsPdo->prepare($deleteSql);
    $deleteParams = [':action' => EGM_CHECK_IN_ACTION];
    if (!$resetAll) $deleteParams[':period_code'] = $periodCode;
    $delete->execute($deleteParams);
    $logRecords = $delete->rowCount();

    return [
        'scope' => $resetAll ? 'all' : 'period',
        'period_code' => $resetAll ? '' : $periodCode,
        'attendance_records' => $attendanceRecords,
        'log_records' => $logRecords,
        'message' => $resetAll
            ? "سوابق حضور همه بازه‌ها بازنشانی شد ({$attendanceRecords} رکورد حضور و {$logRecords} گزارش)."
            : "سوابق حضور بازه {$periodCode} بازنشانی شد ({$attendanceRecords} رکورد حضور و {$logRecords} گزارش).",
    ];
}

function egmCheckInCleanText($value, int $maxLength): string
{
    $text = trim(is_scalar($value) ? (string)$value : '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength, 'UTF-8') : substr($text, 0, $maxLength);
}

/** @return array<string,array<int,string>> */
function egmCheckInUninvitedOptions(array $context): array
{
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $fields = ['deputy', 'general_department', 'department', 'gender', 'postal_level'];
    $result = [];
    foreach ($fields as $field) {
        $values = [];
        foreach ([$usersTable, 'organizational_event_users'] as $table) {
            if (!egmInstanceTableExists($pdo, $table)) continue;
            $rows = $pdo->query(
                "SELECT DISTINCT TRIM(`{$field}`) AS `value` FROM `{$table}` "
                . "WHERE TRIM(`{$field}`) <> '' ORDER BY `value` LIMIT 1000"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows ?: [] as $value) {
                $text = trim((string)$value);
                if ($text !== '') $values[$text] = $text;
            }
        }
        natcasesort($values);
        $result[$field] = array_values($values);
    }
    return $result;
}

/** @return array{result:string,message:string,user_id:int,guest_number:string} */
function egmCheckInRegisterUninvited(
    array $context,
    array $input,
    array $sessionUser,
    ?DateTimeImmutable $now = null
): array {
    $period = is_array($context['period'] ?? null) ? $context['period'] : null;
    if (!is_array($period) || (string)($context['period_state']['result'] ?? '') !== 'active') {
        throw new InvalidArgumentException('ثبت مهمان ناخوانده فقط هنگام فعال بودن دقیقاً یک بازه امکان‌پذیر است.');
    }
    $nationalId = egmCheckInNormalizeNationalId($input['national_id'] ?? '');
    if ($nationalId === '') throw new InvalidArgumentException('کد ملی مهمان باید دقیقاً ۱۰ رقم باشد.');
    $firstName = egmCheckInCleanText($input['first_name'] ?? '', 191);
    $lastName = egmCheckInCleanText($input['last_name'] ?? '', 191);
    if ($firstName === '' || $lastName === '') throw new InvalidArgumentException('نام و نام خانوادگی مهمان الزامی است.');
    $outsideOrganization = egmCheckInBool($input['outside_organization'] ?? false);
    $workId = egmCheckInCleanText($input['work_id'] ?? '', 128);
    $phoneNumber = egmCheckInCleanText($input['phone_number'] ?? '', 32);
    $deputy = $outsideOrganization ? '' : egmCheckInCleanText($input['deputy'] ?? '', 191);
    $generalDepartment = $outsideOrganization ? '' : egmCheckInCleanText($input['general_department'] ?? '', 191);
    $department = $outsideOrganization ? '' : egmCheckInCleanText($input['department'] ?? '', 191);
    $gender = egmCheckInCleanText($input['gender'] ?? '', 32);
    $postalLevel = egmCheckInCleanText($input['postal_level'] ?? '', 64);
    if (!$outsideOrganization && ($workId === '' || $deputy === '' || $generalDepartment === '' || $department === '')) {
        throw new InvalidArgumentException('برای مهمان سازمانی، کد پرسنلی، معاونت، اداره کل و اداره الزامی است.');
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $actor = egmCheckInCleanText(
        $sessionUser['username'] ?? ($sessionUser['fullname'] ?? ($sessionUser['code'] ?? 'admin')),
        191
    ) ?: 'admin';
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $periodCode = egmCheckInPeriodCode($period);
    $pdo->beginTransaction();
    try {
        $user = egmCheckInFindUser($pdo, $usersTable, $nationalId);
        if (is_array($user)) {
            $userId = (int)$user['id'];
            $statement = $pdo->prepare(
                "UPDATE `{$usersTable}` SET `first_name` = :first_name, `last_name` = :last_name, "
                . "`national_id` = :national_id, `work_id` = CASE WHEN :work_id <> '' THEN :work_id_value ELSE `work_id` END, "
                . "`phone_number` = CASE WHEN :phone_number <> '' THEN :phone_number_value ELSE `phone_number` END, "
                . "`deputy` = :deputy, `general_department` = :general_department, `department` = :department, "
                . "`gender` = CASE WHEN :gender <> '' THEN :gender_value ELSE `gender` END, "
                . "`postal_level` = CASE WHEN :postal_level <> '' THEN :postal_level_value ELSE `postal_level` END, "
                . "`is_active` = 1, `is_uninvited_guest` = 1, `outside_organization` = :outside_organization, "
                . "`uninvited_registered_at` = :registered_at, `uninvited_registered_by` = :registered_by WHERE `id` = :id"
            );
            $statement->execute([
                ':first_name' => $firstName, ':last_name' => $lastName, ':national_id' => $nationalId,
                ':work_id' => $workId, ':work_id_value' => $workId,
                ':phone_number' => $phoneNumber, ':phone_number_value' => $phoneNumber,
                ':deputy' => $deputy, ':general_department' => $generalDepartment, ':department' => $department,
                ':gender' => $gender, ':gender_value' => $gender,
                ':postal_level' => $postalLevel, ':postal_level_value' => $postalLevel,
                ':outside_organization' => $outsideOrganization ? 1 : 0,
                ':registered_at' => $now->format('Y-m-d H:i:s'), ':registered_by' => $actor, ':id' => $userId,
            ]);
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO `{$usersTable}` (`work_id`, `first_name`, `last_name`, `national_id`, `phone_number`, "
                . "`deputy`, `general_department`, `department`, `gender`, `postal_level`, `source_row`, `source_type`, "
                . "`is_active`, `is_uninvited_guest`, `outside_organization`, `uninvited_registered_at`, `uninvited_registered_by`) "
                . "VALUES (:work_id, :first_name, :last_name, :national_id, :phone_number, :deputy, :general_department, "
                . ":department, :gender, :postal_level, 0, 'walk_in', 1, 1, :outside_organization, :registered_at, :registered_by)"
            );
            $statement->execute([
                ':work_id' => $workId, ':first_name' => $firstName, ':last_name' => $lastName,
                ':national_id' => $nationalId, ':phone_number' => $phoneNumber, ':deputy' => $deputy,
                ':general_department' => $generalDepartment, ':department' => $department, ':gender' => $gender,
                ':postal_level' => $postalLevel, ':outside_organization' => $outsideOrganization ? 1 : 0,
                ':registered_at' => $now->format('Y-m-d H:i:s'), ':registered_by' => $actor,
            ]);
            $userId = (int)$pdo->lastInsertId();
        }
        $guestNumbers = egmInstanceAssignGuestNumbers($pdo, (string)$context['code'], [$userId]);
        $invitation = $pdo->prepare(
            "INSERT INTO `{$periodsTable}` (`user_id`, `period_code`, `status`, `invitation_source`, `invited_by`, `invited_at`, "
            . "`is_uninvited_guest`, `uninvited_registered_at`, `uninvited_registered_by`) "
            . "VALUES (:user_id, :period_code, 'invited', 'walk_in', :actor, :registered_at, 1, :registered_at_value, :actor_value) "
            . "ON DUPLICATE KEY UPDATE `invitation_source` = 'walk_in', `invited_by` = VALUES(`invited_by`), "
            . "`invited_at` = COALESCE(`invited_at`, VALUES(`invited_at`)), `is_uninvited_guest` = 1, "
            . "`uninvited_registered_at` = VALUES(`uninvited_registered_at`), `uninvited_registered_by` = VALUES(`uninvited_registered_by`)"
        );
        $registeredAt = $now->format('Y-m-d H:i:s');
        $invitation->execute([
            ':user_id' => $userId, ':period_code' => $periodCode, ':actor' => $actor,
            ':registered_at' => $registeredAt, ':registered_at_value' => $registeredAt, ':actor_value' => $actor,
        ]);
        $invitationIdStatement = $pdo->prepare(
            "SELECT `id`, `entered_date`, `entered_time`, `quit_date`, `quit_time` FROM `{$periodsTable}` "
            . "WHERE `user_id` = :user_id AND `period_code` = :period_code LIMIT 1"
        );
        $invitationIdStatement->execute([':user_id' => $userId, ':period_code' => $periodCode]);
        $storedInvitation = $invitationIdStatement->fetch(PDO::FETCH_ASSOC);
        $invitationId = (int)($storedInvitation['id'] ?? 0);
        $attendanceState = trim((string)($storedInvitation['quit_date'] ?? '')) !== ''
            && trim((string)($storedInvitation['quit_time'] ?? '')) !== ''
            ? 'quit_completed'
            : (trim((string)($storedInvitation['entered_date'] ?? '')) !== ''
                && trim((string)($storedInvitation['entered_time'] ?? '')) !== '' ? 'entered' : 'not_entered');
        $message = "مهمان ناخوانده {$firstName} {$lastName} به بازه فعال اضافه شد؛ برای ثبت ورود، کد ملی را دوباره اسکن کنید.";
        egmCheckInSavePeriodCondition($context, $invitationId, 'walk_in_registered', 'register', $message, $now, $attendanceState);
        $loggedUser = ['id' => $userId, 'work_id' => $workId];
        egmCheckInWriteLog($context, $loggedUser, $nationalId, 'walk_in_registered', $message, $period, $now, [
            'attendance_action' => 'register',
            'outside_organization' => $outsideOrganization,
            'registered_by' => $actor,
        ]);
        $pdo->commit();
        return [
            'result' => 'walk_in_registered',
            'message' => $message,
            'user_id' => $userId,
            'guest_number' => (string)($guestNumbers[$userId] ?? ''),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** @return array<int,string> */
function egmCheckInSearchStatusCodes(string $query): array
{
    $query = function_exists('mb_strtolower') ? mb_strtolower(trim($query), 'UTF-8') : strtolower(trim($query));
    if ($query === '') return [];
    $labels = [
        'success' => ['ورود موفق', 'ورود'],
        'quit_success' => ['خروج موفق', 'خروج'],
        'force_entry_success' => ['ورود اجباری', 'حضور نامعقول'],
        'force_quit_success' => ['خروج اجباری', 'حضور نامعقول'],
        'walk_in_registered' => ['مهمان ناخوانده', 'ثبت مهمان ناخوانده'],
        'duplicate' => ['قبلاً وارد شده', 'ورود تکراری'],
        'quit_duplicate' => ['قبلاً خارج شده', 'خروج تکراری'],
        'quit_without_entry' => ['ورود ثبت نشده', 'خروج بدون ورود'],
        'minimum_stay' => ['حداقل مدت حضور', 'خروج زودهنگام'],
        'entry_closed_quit_wave' => ['ورود بسته', 'موج خروج'],
        'invalid_attendance_record' => ['سابقه حضور ناسازگار'],
        'invited_other_period' => ['دعوت در بازه دیگر', 'بازه دیگر'],
        'user_inactive' => ['مهمان غیرفعال'],
        'no_active_period' => ['بدون بازه فعال'],
        'multiple_active_periods' => ['هم‌پوشانی بازه‌ها', 'چند بازه فعال'],
        'not_found' => ['یافت نشد', 'پیدا نشد'],
        'not_invited' => ['دعوت نشده'],
        'upcoming' => ['در انتظار شروع', 'آینده'],
        'immune_time' => ['زمان ایمن'],
        'ended' => ['پایان‌یافته', 'پایان یافته'],
        'inactive' => ['غیرفعال'],
        'invalid_schedule' => ['زمان‌بندی نامعتبر'],
    ];
    $matches = [];
    foreach ($labels as $status => $aliases) {
        foreach ($aliases as $alias) {
            $normalizedAlias = function_exists('mb_strtolower') ? mb_strtolower($alias, 'UTF-8') : strtolower($alias);
            if (str_contains($normalizedAlias, $query)) {
                $matches[] = $status;
                break;
            }
        }
    }
    return array_values(array_unique($matches));
}

function egmCheckInRecentLogs(array $context, int $limit = 50, string $query = ''): array
{
    $limit = max(1, min(200, $limit));
    $logsTable = (string)$context['tables']['activity_logs'];
    $usersTable = (string)$context['tables']['users'];
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    $logsPdo = $context['logs_pdo'] ?? $context['pdo'];
    if (!$logsPdo instanceof PDO) return [];
    $periodCode = trim((string)($context['logs_period_code'] ?? ($context['period_code'] ?? '')));
    $where = ["l.`action` = '" . EGM_CHECK_IN_ACTION . "'"];
    $params = [];
    if ($periodCode !== '') {
        $where[] = 'l.`entity_id` = :period_code';
        $params[':period_code'] = $periodCode;
    }
    $query = egmCheckInCleanText($query, 100);
    $fetchLimit = $query === '' ? $limit : 10000;
    $statement = $logsPdo->prepare(
        "SELECT l.`user_id`, l.`work_id` AS `log_work_id`, l.`status`, l.`message`, l.`entity_id`, l.`occurred_at`, l.`metadata_json` "
        . "FROM `{$logsTable}` l "
        . 'WHERE ' . implode(' AND ', $where)
        . " ORDER BY l.`occurred_at` DESC, l.`id` DESC LIMIT {$fetchLimit}"
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $userIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['user_id'] ?? 0),
        $rows
    ))));
    $users = [];
    $periodStates = [];
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userStatement = $context['pdo']->prepare("SELECT * FROM `{$usersTable}` WHERE `id` IN ({$placeholders})");
        $userStatement->execute($userIds);
        foreach ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $user) $users[(int)$user['id']] = $user;
        $periodStatement = $context['pdo']->prepare(
            "SELECT * FROM `{$userPeriodsTable}` WHERE `user_id` IN ({$placeholders})"
            . ($periodCode !== '' ? ' AND `period_code` = ?' : '')
        );
        $periodStatement->execute($periodCode !== '' ? [...$userIds, $periodCode] : $userIds);
        foreach ($periodStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $period) {
            $periodStates[(int)$period['user_id'] . ':' . (string)$period['period_code']] = $period;
        }
    }
    foreach ($rows as &$row) {
        $userId = (int)($row['user_id'] ?? 0);
        $user = $users[$userId] ?? [];
        $period = $periodStates[$userId . ':' . (string)($row['entity_id'] ?? '')] ?? [];
        $row = $row + $user + [
            'work_id' => (string)($user['work_id'] ?? $row['log_work_id'] ?? ''),
            'user_is_uninvited_guest' => $user['is_uninvited_guest'] ?? 0,
            'period_is_uninvited_guest' => $period['is_uninvited_guest'] ?? 0,
            'entered_date' => $period['entered_date'] ?? '', 'entered_time' => $period['entered_time'] ?? '',
            'quit_date' => $period['quit_date'] ?? '', 'quit_time' => $period['quit_time'] ?? '',
            'attendance_state' => $period['attendance_state'] ?? 'not_entered',
            'correct_presence' => $period['correct_presence'] ?? 0,
            'fake_presence' => $period['fake_presence'] ?? 0,
        ];
    }
    unset($row);
    if ($query !== '') {
        $statusCodes = egmCheckInSearchStatusCodes($query);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        $rows = array_values(array_filter($rows, static function (array $row) use ($needle, $statusCodes): bool {
            if (in_array((string)($row['status'] ?? ''), $statusCodes, true)) return true;
            $haystack = implode(' ', array_map('strval', array_filter($row, 'is_scalar')));
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            return str_contains($haystack, $needle);
        }));
    }
    $rows = array_slice($rows, 0, $limit);
    return array_map(static function (array $row): array {
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $status = (string)($row['status'] ?? '');
        $attendanceAction = (string)($metadata['attendance_action'] ?? '');
        if ($attendanceAction === '') {
            if (str_starts_with($status, 'quit_')) {
                $attendanceAction = 'quit';
            } elseif (in_array($status, ['success', 'duplicate'], true)) {
                $attendanceAction = 'entry';
            } else {
                $attendanceAction = 'check';
            }
        }
        $enteredDate = (string)($row['entered_date'] ?? ($metadata['entered_date'] ?? ''));
        $enteredTime = (string)($row['entered_time'] ?? ($metadata['entered_time'] ?? ''));
        $quitDate = (string)($row['quit_date'] ?? ($metadata['quit_date'] ?? ''));
        $quitTime = (string)($row['quit_time'] ?? ($metadata['quit_time'] ?? ''));
        $operationDate = $attendanceAction === 'quit' ? $quitDate : ($attendanceAction === 'entry' ? $enteredDate : '');
        $operationTime = $attendanceAction === 'quit' ? $quitTime : ($attendanceAction === 'entry' ? $enteredTime : '');
        $metadataForceAction = strtolower(trim((string)($metadata['force_action'] ?? '')));
        $currentForceOption = egmCheckInForceOption($row);
        $forceAction = in_array($metadataForceAction, ['entry', 'quit'], true)
            && (string)($currentForceOption['force_action'] ?? '') === $metadataForceAction
            ? $metadataForceAction
            : '';
        return [
            'status' => $status,
            'message' => (string)($row['message'] ?? ''),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'full_name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
            'national_id' => (string)($row['national_id'] ?? ($metadata['national_id'] ?? '')),
            'work_id' => (string)($row['work_id'] ?? ''),
            'phone_number' => (string)($row['phone_number'] ?? ''),
            'guest_number' => (string)($row['guest_number'] ?? ''),
            'deputy' => (string)($row['deputy'] ?? ''),
            'general_department' => (string)($row['general_department'] ?? ''),
            'department' => (string)($row['department'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'postal_level' => (string)($row['postal_level'] ?? ''),
            'outside_organization' => (int)($row['outside_organization'] ?? 0) === 1,
            'is_uninvited_guest' => (int)($row['period_is_uninvited_guest'] ?? $row['user_is_uninvited_guest'] ?? 0) === 1,
            'period_code' => (string)($row['entity_id'] ?? ($metadata['period_code'] ?? '')),
            'period_title' => (string)($metadata['period_title'] ?? ''),
            'entered_date' => $enteredDate,
            'entered_time' => $enteredTime,
            'quit_date' => $quitDate,
            'quit_time' => $quitTime,
            'attendance_state' => (string)($row['attendance_state'] ?? 'not_entered'),
            'correct_presence' => (int)($row['correct_presence'] ?? 0) === 1,
            'fake_presence' => (int)($row['fake_presence'] ?? 0) === 1,
            'attendance_action' => $attendanceAction,
            'force_action' => $forceAction,
            'force_label' => $forceAction !== ''
                ? (string)($metadata['force_label'] ?? ($forceAction === 'entry' ? 'Force Enter' : 'Force Quit'))
                : '',
            'operation_date' => $operationDate,
            'operation_time' => $operationTime,
            'attempted_at' => (string)($row['occurred_at'] ?? ''),
        ];
    }, $rows ?: []);
}

function egmCheckInLogsVersion(array $context): string
{
    $logsPdo = $context['logs_pdo'] ?? $context['pdo'];
    if (!$logsPdo instanceof PDO) return '0:0';
    $logsTable = (string)$context['tables']['activity_logs'];
    $periodCode = trim((string)($context['logs_period_code'] ?? ($context['period_code'] ?? '')));
    $sql = "SELECT COALESCE(MAX(`id`), 0) AS `max_id`, COUNT(*) AS `row_count` "
        . "FROM `{$logsTable}` WHERE `action` = :action";
    $params = [':action' => EGM_CHECK_IN_ACTION];
    if ($periodCode !== '') {
        $sql .= ' AND `entity_id` = :period_code';
        $params[':period_code'] = $periodCode;
    }
    $statement = $logsPdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    return (string)($row['max_id'] ?? '0') . ':' . (string)($row['row_count'] ?? '0');
}

/** @return array{invited_users:int,completed_quits:int,quit_wave_active:bool} */
function egmCheckInPeriodAttendanceStats(PDO $pdo, string $userPeriodsTable, string $periodCode): array
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS `invited_users`, "
        . "SUM(CASE WHEN `quit_date` IS NOT NULL AND `quit_time` IS NOT NULL THEN 1 ELSE 0 END) AS `completed_quits` "
        . "FROM `{$userPeriodsTable}` WHERE `period_code` = :period_code"
    );
    $statement->execute([':period_code' => $periodCode]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $invitedUsers = max(0, (int)($row['invited_users'] ?? 0));
    $completedQuits = max(0, (int)($row['completed_quits'] ?? 0));
    return [
        'invited_users' => $invitedUsers,
        'completed_quits' => $completedQuits,
        'quit_wave_active' => egmCheckInQuitWaveActive($invitedUsers, $completedQuits),
    ];
}

/** @return array{force_action:string,force_label:string}|array{} */
function egmCheckInForceOption(array $invitation): array
{
    $hasEntry = trim((string)($invitation['entered_date'] ?? '')) !== ''
        && trim((string)($invitation['entered_time'] ?? '')) !== '';
    $hasQuit = trim((string)($invitation['quit_date'] ?? '')) !== ''
        && trim((string)($invitation['quit_time'] ?? '')) !== '';
    if ($hasQuit) return [];
    return $hasEntry
        ? ['force_action' => 'quit', 'force_label' => 'Force Quit']
        : ['force_action' => 'entry', 'force_label' => 'Force Enter'];
}

function egmCheckInProcess(
    array $context,
    string $submittedCode,
    ?DateTimeImmutable $now = null,
    ?string $forcedAction = null,
    array $sessionUser = []
): array
{
    $submittedCode = egmCheckInNormalizeGuestCode($submittedCode);
    if ($submittedCode === '') throw new InvalidArgumentException('شناسه مهمان باید فقط شامل ۴ تا ۱۰ رقم باشد.');
    $forcedAction = $forcedAction === null ? null : strtolower(trim($forcedAction));
    if ($forcedAction !== null && !in_array($forcedAction, ['entry', 'quit'], true)) {
        throw new InvalidArgumentException('عملیات حضور اجباری معتبر نیست.');
    }
    $isForced = $forcedAction !== null;
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    $pdo->beginTransaction();
    try {
        $selectedPeriod = is_array($context['period'] ?? null) ? $context['period'] : null;
        if (!is_array($selectedPeriod)) {
            $periodState = (string)($context['period_state']['result'] ?? 'no_active_period');
            $result = $periodState === 'multiple_active_periods' ? 'multiple_active_periods' : 'no_active_period';
            $message = $result === 'multiple_active_periods'
                ? 'بیش از یک بازه در این رویداد هم‌زمان فعال است؛ ابتدا هم‌پوشانی زمان‌بندی را برطرف کنید.'
                : 'در حال حاضر هیچ بازه فعالی در این رویداد وجود ندارد.';
            egmCheckInWriteLog($context, null, $submittedCode, $result, $message, null, $now);
            $pdo->commit();
            return ['result' => $result, 'message' => $message];
        }
        $user = egmCheckInFindUser($pdo, $usersTable, $submittedCode);
        if (!is_array($user)) {
            $message = 'کاربری با این شناسه مهمان در این رویداد پیدا نشد.';
            egmCheckInWriteLog($context, null, $submittedCode, 'not_found', $message, $selectedPeriod, $now);
            $pdo->commit();
            return ['result' => 'not_found', 'message' => $message];
        }
        if (array_key_exists('is_active', $user) && (int)$user['is_active'] !== 1) {
            $message = 'حساب این مهمان در این رویداد غیرفعال است.';
            egmCheckInWriteLog($context, $user, $submittedCode, 'user_inactive', $message, $selectedPeriod, $now);
            $pdo->commit();
            return ['result' => 'user_inactive', 'message' => $message];
        }
        $columns = '`id`, `user_id`, `period_code`, `entered_date`, `entered_time`, `quit_date`, `quit_time`, `correct_presence`, `fake_presence`';
        $selectedCode = egmCheckInPeriodCode($selectedPeriod);
        $statement = $pdo->prepare(
            "SELECT {$columns} FROM `{$userPeriodsTable}` WHERE `user_id` = :user_id "
            . "AND `period_code` = :period_code LIMIT 1 FOR UPDATE"
        );
        $statement->execute([':user_id' => (int)$user['id'], ':period_code' => $selectedCode]);
        $invitation = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($invitation)) {
            $otherStatement = $pdo->prepare(
                "SELECT `period_code` FROM `{$userPeriodsTable}` WHERE `user_id` = :user_id ORDER BY `id` FOR UPDATE"
            );
            $otherStatement->execute([':user_id' => (int)$user['id']]);
            $otherCodes = array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $otherStatement->fetchAll(PDO::FETCH_COLUMN)
            ))));
            $periodTitles = [];
            foreach ((array)($context['periods'] ?? []) as $candidatePeriod) {
                if (!is_array($candidatePeriod)) continue;
                $candidateCode = egmCheckInPeriodCode($candidatePeriod);
                if (!in_array($candidateCode, $otherCodes, true)) continue;
                $periodTitles[] = trim((string)($candidatePeriod['title'] ?? '')) ?: $candidateCode;
            }
            $result = $otherCodes ? 'invited_other_period' : 'not_invited';
            $message = $otherCodes
                ? 'این مهمان به بازه فعال دعوت نشده و دعوت او مربوط به بازه دیگری است'
                    . ($periodTitles ? ': ' . implode('، ', $periodTitles) : '.')
                : 'این مهمان به هیچ بازه‌ای در این رویداد دعوت نشده است.';
            egmCheckInWriteLog($context, $user, $submittedCode, $result, $message, $selectedPeriod, $now, [
                'invited_period_codes' => $otherCodes,
            ]);
            $pdo->commit();
            return ['result' => $result, 'message' => $message];
        }
        $period = $selectedPeriod;
        $availability = egmCheckInPeriodAvailability($period, $now);
        if ($isForced && $forcedAction === 'quit' && empty($availability['quit_required'])) {
            throw new InvalidArgumentException('برای این بازه ثبت خروج الزامی نیست و Force Quit قابل استفاده نیست.');
        }
        if (!$isForced && !$availability['eligible']) {
            $result = (string)$availability['reason'];
            $messages = [
                'inactive' => 'این بازه غیرفعال است.',
                'upcoming' => 'زمان ورود این بازه هنوز شروع نشده است.',
                'immune_time' => 'اکنون زمان ایمن بازه است؛ ثبت ورود یا خروج امکان‌پذیر نیست.',
                'ended' => 'این بازه پایان یافته است.',
                'invalid_schedule' => 'زمان‌بندی ورود و خروج این بازه معتبر یا کامل نیست.',
            ];
            $message = $messages[$result] ?? 'در این زمان امکان ثبت ورود یا خروج وجود ندارد.';
            egmCheckInSavePeriodCondition(
                $context, (int)$invitation['id'], $result, 'check', $message, $now
            );
            $forceOption = !empty($availability['quit_required'])
                && in_array($result, ['upcoming', 'immune_time', 'ended'], true)
                ? egmCheckInForceOption($invitation)
                : [];
            egmCheckInWriteLog($context, $user, $submittedCode, $result, $message, $period, $now, [
                'attendance_phase' => $result,
            ] + $forceOption);
            $pdo->commit();
            return ['result' => $result, 'message' => $message] + $forceOption;
        }
        $attendanceAction = $isForced ? (string)$forcedAction : (string)($availability['action'] ?? 'entry');
        $flexibleMetadata = [];
        if ($attendanceAction === 'auto') {
            $attendanceStats = egmCheckInPeriodAttendanceStats($pdo, $userPeriodsTable, $selectedCode);
            $minimumStayMinutes = egmCheckInMinimumStayMinutes($period);
            $decision = egmCheckInFlexibleAttendanceDecision(
                $invitation,
                $now,
                $minimumStayMinutes,
                (bool)$attendanceStats['quit_wave_active']
            );
            $flexibleMetadata = [
                'flexible_attendance' => true,
                'minimum_stay_minutes' => $minimumStayMinutes,
                'invited_users' => $attendanceStats['invited_users'],
                'completed_quits' => $attendanceStats['completed_quits'],
                'quit_wave_active' => $attendanceStats['quit_wave_active'],
            ];
            if (!$decision['eligible']) {
                $result = (string)$decision['reason'];
                $remainingMinutes = max(0, (int)($decision['remaining_minutes'] ?? 0));
                $message = match ($result) {
                    'entry_closed_quit_wave' => 'موج خروج فعال شده است؛ ثبت ورود جدید برای این بازه بسته شده است.',
                    'minimum_stay' => "حداقل مدت حضور هنوز کامل نشده است؛ حدود {$remainingMinutes} دقیقه دیگر امکان ثبت خروج وجود دارد.",
                    default => 'سوابق ورود و خروج این مهمان ناسازگار است و باید توسط مدیر بررسی شود.',
                };
                $conditionAction = $result === 'minimum_stay' ? 'quit' : ($result === 'entry_closed_quit_wave' ? 'entry' : 'check');
                $attendanceState = $result === 'minimum_stay' ? 'entered' : null;
                egmCheckInSavePeriodCondition(
                    $context,
                    (int)$invitation['id'],
                    $result,
                    $conditionAction,
                    $message,
                    $now,
                    $attendanceState
                );
                $forceOption = $result === 'minimum_stay'
                    ? ['force_action' => 'quit', 'force_label' => 'Force Quit']
                    : [];
                egmCheckInWriteLog($context, $user, $submittedCode, $result, $message, $period, $now, $flexibleMetadata + [
                    'attendance_action' => $conditionAction,
                    'remaining_minutes' => $remainingMinutes,
                ] + $forceOption);
                $pdo->commit();
                return ['result' => $result, 'message' => $message] + $forceOption;
            }
            $attendanceAction = (string)($decision['action'] ?? 'entry');
        }

        $isQuit = $attendanceAction === 'quit';
        if ($isQuit) {
            $enteredDate = trim((string)($invitation['entered_date'] ?? ''));
            $enteredTime = trim((string)($invitation['entered_time'] ?? ''));
            if ($enteredDate === '' || $enteredTime === '') {
                $message = 'برای این مهمان هنوز ورود ثبت نشده است؛ ثبت خروج امکان‌پذیر نیست.';
                egmCheckInSavePeriodCondition(
                    $context, (int)$invitation['id'], 'quit_without_entry', 'quit', $message, $now, 'not_entered'
                );
                egmCheckInWriteLog($context, $user, $submittedCode, 'quit_without_entry', $message, $period, $now, [
                    'attendance_action' => 'quit',
                    'force_action' => 'entry',
                    'force_label' => 'Force Enter',
                ]);
                $pdo->commit();
                return ['result' => 'quit_without_entry', 'message' => $message]
                    + ['force_action' => 'entry', 'force_label' => 'Force Enter'];
            }
        }
        $dateColumn = $isQuit ? 'quit_date' : 'entered_date';
        $timeColumn = $isQuit ? 'quit_time' : 'entered_time';
        $storedDate = trim((string)($invitation[$dateColumn] ?? ''));
        $storedTime = trim((string)($invitation[$timeColumn] ?? ''));
        $duplicateResult = $isQuit ? 'quit_duplicate' : 'duplicate';
        if ($storedDate !== '' || $storedTime !== '') {
            $verb = $isQuit ? 'خارج شده' : 'وارد شده';
            $message = "این مهمان قبلاً در {$storedDate} ساعت {$storedTime} {$verb} است.";
            $storedAttendanceState = $isQuit
                || (trim((string)($invitation['quit_date'] ?? '')) !== '' && trim((string)($invitation['quit_time'] ?? '')) !== '')
                ? 'quit_completed'
                : 'entered';
            egmCheckInSavePeriodCondition(
                $context, (int)$invitation['id'], $duplicateResult, $attendanceAction, $message, $now, $storedAttendanceState
            );
            $forceOption = !$isQuit && !empty($availability['quit_required'])
                && trim((string)($invitation['quit_date'] ?? '')) === ''
                ? ['force_action' => 'quit', 'force_label' => 'Force Quit']
                : [];
            egmCheckInWriteLog($context, $user, $submittedCode, $duplicateResult, $message, $period, $now, $flexibleMetadata + [
                $dateColumn => $storedDate,
                $timeColumn => $storedTime,
                'attendance_action' => $attendanceAction,
            ] + $forceOption);
            $pdo->commit();
            return ['result' => $duplicateResult, 'message' => $message] + $forceOption;
        }
        $storedDate = $now->format('Y-m-d');
        $storedTime = $now->format('H:i:s');
        $presenceUpdate = $isForced
            ? ', `correct_presence` = 0, `fake_presence` = 1'
            : ($isQuit ? ', `correct_presence` = CASE WHEN `fake_presence` = 1 THEN 0 ELSE 1 END' : '');
        $update = $pdo->prepare(
            "UPDATE `{$userPeriodsTable}` SET `{$dateColumn}` = :event_date, `{$timeColumn}` = :event_time{$presenceUpdate} "
            . "WHERE `id` = :id AND `{$dateColumn}` IS NULL AND `{$timeColumn}` IS NULL"
        );
        $update->execute([':event_date' => $storedDate, ':event_time' => $storedTime, ':id' => (int)$invitation['id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('ثبت هم‌زمان انجام نشد؛ دوباره تلاش کنید.');
        $fullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        $periodTitle = trim((string)($period['title'] ?? '')) ?: egmCheckInPeriodCode($period);
        $successResult = $isForced
            ? ($isQuit ? 'force_quit_success' : 'force_entry_success')
            : ($isQuit ? 'quit_success' : 'success');
        $noun = $isQuit ? 'خروج' : 'ورود';
        $message = $isForced
            ? "{$noun} اجباری {$fullName} در بازه {$periodTitle} ثبت شد و حضور نامعقول علامت خورد."
            : "{$noun} {$fullName} در بازه {$periodTitle} با موفقیت ثبت شد.";
        egmCheckInSavePeriodCondition(
            $context,
            (int)$invitation['id'],
            $successResult,
            $isForced ? 'force_' . $attendanceAction : $attendanceAction,
            $message,
            $now,
            $isQuit ? 'quit_completed' : 'entered'
        );
        egmCheckInWriteLog($context, $user, $submittedCode, $successResult, $message, $period, $now, $flexibleMetadata + [
            $dateColumn => $storedDate,
            $timeColumn => $storedTime,
            'attendance_action' => $attendanceAction,
            'forced_presence' => $isForced,
            'forced_by' => trim((string)($sessionUser['code'] ?? ($sessionUser['username'] ?? ''))),
        ]);
        $pdo->commit();
        return [
            'result' => $successResult,
            'message' => $message,
            'correct_presence' => !$isForced && $isQuit && (int)($invitation['fake_presence'] ?? 0) !== 1,
            'fake_presence' => $isForced || (int)($invitation['fake_presence'] ?? 0) === 1,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmCheckInJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function egmCheckInRenderPage(array $context, string $csrf, string $nonce): never
{
    egmSecuritySendPageHeaders($nonce);
    $nameValue = trim((string)$context['name']) ?: 'رویداد';
    $name = htmlspecialchars($nameValue, ENT_QUOTES, 'UTF-8');
    $period = is_array($context['period'] ?? null) ? $context['period'] : [];
    $periodTitleValue = trim((string)($period['title'] ?? '')) ?: 'بدون عنوان';
    $periodTitle = htmlspecialchars($periodTitleValue, ENT_QUOTES, 'UTF-8');
    $periodState = is_array($context['period_state'] ?? null) ? $context['period_state'] : [];
    $phase = is_array($periodState['availability'] ?? null) ? $periodState['availability'] : [];
    $phaseReason = (string)($phase['reason'] ?? ($periodState['result'] ?? 'no_active_period'));
    $canScan = (bool)($context['can_scan'] ?? false);
    $hasActivePeriod = (string)($periodState['result'] ?? '') === 'active' && $period !== [];
    $phaseLabels = [
        'inactive' => 'غیرفعال',
        'upcoming' => 'در انتظار شروع',
        'entry_time' => 'فعال / زمان ورود',
        'immune_time' => 'فعال / زمان ایمن',
        'quit_time' => 'فعال / زمان خروج',
        'flexible_attendance' => 'فعال / ورود و خروج شناور',
        'ended' => 'پایان‌یافته',
        'invalid_schedule' => 'زمان‌بندی نامعتبر',
        'no_active_period' => 'بدون بازه فعال',
        'multiple_active_periods' => 'هم‌پوشانی بازه‌های فعال',
    ];
    $phaseLabel = htmlspecialchars($phaseLabels[$phaseReason] ?? 'نامشخص', ENT_QUOTES, 'UTF-8');
    $phaseClass = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($phaseReason)) ?: 'unknown';
    $periodSummary = static function ($value): array {
        if (!is_array($value)) return ['title' => 'وجود ندارد', 'meta' => '—'];
        $title = trim((string)($value['title'] ?? ''));
        $start = trim((string)($value['startDate'] ?? ($value['start_date'] ?? '')) . ' ' . (string)($value['startTime'] ?? ($value['start_time'] ?? '')));
        $end = trim((string)($value['endDate'] ?? ($value['end_date'] ?? '')) . ' ' . (string)($value['endTime'] ?? ($value['end_time'] ?? '')));
        return ['title' => $title !== '' ? $title : 'بدون عنوان', 'meta' => trim($start . ($end !== '' ? ' ← ' . $end : '')) ?: 'بدون زمان‌بندی'];
    };
    $previousSummary = $periodSummary($context['previous_period'] ?? null);
    $nextSummary = $periodSummary($context['next_period'] ?? null);
    $initialResult = $canScan
        ? 'شناسه مهمان را وارد یا اسکن کنید.'
        : ($phaseReason === 'multiple_active_periods'
            ? 'چند بازه هم‌زمان فعال هستند؛ تا رفع هم‌پوشانی امکان ثبت وجود ندارد.'
            : 'اکنون هیچ بازه فعالی وجود ندارد؛ ثبت ورود یا خروج متوقف است.');
    $scriptName = rawurldecode(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $miniAppsPosition = stripos($scriptName, '/mini apps/');
    $projectWebBase = $miniAppsPosition === false ? '' : rtrim(substr($scriptName, 0, $miniAppsPosition), '/');
    $generalSettingsUrl = htmlspecialchars($projectWebBase . '/General%20Setting/general-settings.js', ENT_QUOTES, 'UTF-8');
    $appearanceUrl = htmlspecialchars($projectWebBase . '/style/appearance.js', ENT_QUOTES, 'UTF-8');
    $panelStylesUrl = htmlspecialchars($projectWebBase . '/style/styles.css', ENT_QUOTES, 'UTF-8');
    $logsVersion = htmlspecialchars(egmCheckInLogsVersion($context), ENT_QUOTES, 'UTF-8');
    $logs = htmlspecialchars((string)json_encode(egmCheckInRecentLogs($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $csrfValue = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>پنل کنترل مهمان — <?= $name ?></title>
  <script src="<?= $generalSettingsUrl ?>"></script>
  <script src="<?= $appearanceUrl ?>"></script>
  <link rel="stylesheet" href="<?= $panelStylesUrl ?>" />
  <style nonce="<?= $nonce ?>">
    body.guest-control-page{min-height:100vh;background:#f7f8fa;color:var(--text,#111);overflow-x:hidden}
    .guest-control-page .guest-shell{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:28px 0 48px}
    .guest-control-page .guest-card{position:relative;margin:0 0 16px;padding:20px;background:#fff;border:1px solid var(--border,#e5e7eb);border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.04);overflow:hidden}
    .guest-control-page .guest-card::before{content:"";position:absolute;inset:0 0 auto;height:3px;background:var(--primary,#e11d2e)}
    .guest-control-page .hero-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;text-align:right}
    .guest-control-page .eyebrow{margin:0 0 4px;color:var(--muted,#6b7280);font-size:13px;font-weight:600}
    .guest-control-page .hero h1{margin:0;font-size:25px;line-height:1.35;color:var(--text,#111)}
    .guest-control-page .event-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:7px;color:var(--muted,#6b7280);font-size:13px}
    .guest-control-page .code{direction:ltr;unicode-bidi:isolate;font-family:Consolas,"SFMono-Regular",monospace;font-size:.94em}
    .guest-control-page .phase{--phase-color:#475569;--phase-bg:#f1f5f9;--phase-border:#cbd5e1;display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;min-height:34px;padding:6px 11px;border:1px solid var(--phase-border);border-radius:10px;background:var(--phase-bg);color:var(--phase-color);font-size:13px;font-weight:700}
    .guest-control-page .phase::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 0 3px rgba(100,116,139,.12)}
    .guest-control-page .phase--entry_time,.guest-control-page .phase--flexible_attendance{--phase-color:#087443;--phase-bg:#ecfdf3;--phase-border:#a7e5c3}
    .guest-control-page .phase--quit_time{--phase-color:#5b21b6;--phase-bg:#f5f3ff;--phase-border:#c4b5fd}
    .guest-control-page .phase--immune_time{--phase-color:#8a5a00;--phase-bg:#fff8e6;--phase-border:#f1ce75}
    .guest-control-page .phase--upcoming{--phase-color:#135ea8;--phase-bg:#eff6ff;--phase-border:#b7d7f8}
    .guest-control-page .phase--ended,.guest-control-page .phase--inactive,.guest-control-page .phase--no_active_period{--phase-color:#475569;--phase-bg:#f1f5f9;--phase-border:#cbd5e1}
    .guest-control-page .phase--invalid_schedule,.guest-control-page .phase--multiple_active_periods{--phase-color:#a12432;--phase-bg:#fff1f2;--phase-border:#f7b6bf}
    .guest-control-page .period-navigation{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:16px}
    .guest-control-page .period-neighbor{min-width:0;padding:11px 13px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:#fafbfc}.guest-control-page .period-neighbor-label{display:block;margin-bottom:3px;color:var(--muted,#6b7280);font-size:11px}.guest-control-page .period-neighbor strong{display:block;font-size:13px;overflow-wrap:anywhere}.guest-control-page .period-neighbor small{display:block;margin-top:3px;color:var(--muted,#6b7280);font-size:11px;direction:ltr;text-align:right;unicode-bidi:isolate}
    .guest-control-page .scan-grid{display:grid;grid-template-columns:minmax(280px,410px) minmax(300px,1fr);gap:24px;align-items:start;margin-top:20px;padding-top:18px;border-top:1px solid var(--border,#e5e7eb)}
    .guest-control-page .scanner,.guest-control-page .result-area{min-width:0}
    .guest-control-page .scanner-label,.guest-control-page .result-label{display:block;margin:0 0 7px;color:var(--text,#111);font-size:13px;font-weight:700}
    .guest-control-page .scanner input{display:block;width:100%;height:58px;padding:9px 14px;border:2px solid #cbd5e1;border-radius:10px;background:#fcfdff;color:#111827;text-align:center;direction:ltr;font-family:"OCR A Std","OCR A Extended","OCR-B","Lucida Console",Consolas,monospace;font-size:27px;font-weight:700;line-height:1;letter-spacing:.18em;outline:none;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
    .guest-control-page .scanner input:hover{border-color:#cbd0d8}
    .guest-control-page .scanner input:focus{border-color:var(--primary,#e11d2e);box-shadow:0 0 0 3px var(--primary-focus,rgba(225,29,46,.12))}
    .guest-control-page .scanner input:disabled{opacity:.62;background:#f5f5f5}
    .guest-control-page .hint{margin:7px 0 0;color:var(--muted,#6b7280);font-size:12px;font-weight:400}
    .guest-control-page .result{position:relative;min-height:50px;margin:0;padding:10px 38px 10px 14px;border:0;border-inline-start:3px solid #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:flex-start;text-align:right;background:#f8fafc;color:var(--muted,#6b7280);font-size:14px;font-weight:600;line-height:1.65;transition:background .18s ease,border-color .18s ease,color .18s ease}
    .guest-control-page .result::before{content:"";position:absolute;inset-inline-start:15px;top:50%;width:8px;height:8px;border-radius:50%;background:currentColor;transform:translateY(-50%);opacity:.78}
    .guest-control-page .result.idle{border-inline-start-color:#cbd5e1;background:#f8fafc;color:var(--muted,#6b7280)}
    .guest-control-page .result.loading{border-inline-start-color:#e6a700;background:#fffbeb;color:#805900}
    .guest-control-page .result.success{border-inline-start-color:#18a566;background:#f1fbf6;color:#087443}
    .guest-control-page .result.duplicate{border-inline-start-color:#d59a12;background:#fffaf0;color:#805900}
    .guest-control-page .result.error{border-inline-start-color:#df4052;background:#fff5f6;color:#a12432}
    .guest-control-page .force-attendance-action{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 9px;border:1px solid #b45309;border-radius:8px;background:#fff7ed;color:#9a3412;font:inherit;font-size:11px;font-weight:800;white-space:normal;cursor:pointer}.guest-control-page .force-attendance-action:hover{background:#b45309;color:#fff}.guest-control-page .force-attendance-action:disabled{opacity:.55;cursor:not-allowed}
    .guest-control-page .card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}
    .guest-control-page .card-head h2{margin:0;font-size:18px;color:var(--text,#111)}
    .guest-control-page .card-head .muted{font-size:12px}
    .guest-control-page .log-toolbar{display:grid;grid-template-columns:minmax(240px,1fr) auto;gap:10px;align-items:end;margin:0 0 12px}
    .guest-control-page .log-search{display:block;min-width:0}.guest-control-page .log-search span{display:block;margin-bottom:6px;color:var(--text,#111);font-size:12px;font-weight:700}.guest-control-page .log-search input{display:block;width:100%;height:42px;padding:8px 38px 8px 12px;border:1px solid var(--border,#e5e7eb);border-radius:9px;background:#fff;color:var(--text,#111);font:inherit;font-size:13px;outline:0}.guest-control-page .log-search{position:relative}.guest-control-page .log-search::after{content:"⌕";position:absolute;inset-inline-start:13px;bottom:8px;color:var(--muted,#6b7280);font-size:20px;line-height:1}.guest-control-page .log-search input:focus{border-color:var(--primary,#e11d2e);box-shadow:0 0 0 3px var(--primary-focus,rgba(225,29,46,.1))}
    .guest-control-page .log-search-meta{min-width:110px;padding-bottom:11px;color:var(--muted,#6b7280);font-size:12px;text-align:left}
    .guest-control-page .table-wrap{overflow:visible;border:0;background:transparent}
    .guest-control-page table{border-collapse:separate;border-spacing:0;width:100%;min-width:0;table-layout:fixed}
    .guest-control-page th,.guest-control-page td{padding:10px;text-align:right;white-space:normal;overflow-wrap:anywhere;word-break:normal;vertical-align:middle;font-size:13px}
    .guest-control-page th{border-block:1px solid #d9dee6;background:#f6f7f9;color:#667085;font-size:11px;font-weight:800}.guest-control-page th:first-child{border-inline-start:1px solid #d9dee6;border-start-start-radius:10px;border-end-start-radius:10px}.guest-control-page th:last-child{border-inline-end:1px solid #d9dee6;border-start-end-radius:10px;border-end-end-radius:10px}
    .guest-control-page th:nth-child(1){width:21%}.guest-control-page th:nth-child(2){width:28%}.guest-control-page th:nth-child(3){width:21%}.guest-control-page th:nth-child(4){width:17%}.guest-control-page th:nth-child(5){width:13%}
    .guest-control-page .log-card-row>td{padding:6px 0;border:0;background:transparent}
    .guest-control-page .log-card{overflow:hidden;border:1px solid #d9dee6;border-inline-start-width:5px;border-radius:11px;background:#fff;box-shadow:0 2px 7px rgba(16,24,40,.055);transition:border-color .15s ease,box-shadow .15s ease}.guest-control-page .log-card:hover{border-color:#c8ced8;box-shadow:0 4px 13px rgba(16,24,40,.085)}
    .guest-control-page .log-card.status-success{border-inline-start-color:#18a566}.guest-control-page .log-card.status-duplicate{border-inline-start-color:#d59a12}.guest-control-page .log-card.status-error{border-inline-start-color:#df4052}
    .guest-control-page .log-card-main{display:grid;grid-template-columns:21fr 28fr 21fr 17fr 13fr;align-items:center;min-width:0}
    .guest-control-page .log-card-cell{min-width:0;padding:13px 10px}
    .guest-control-page .log-status{display:flex;align-items:center;gap:7px;min-width:0}.guest-control-page .status-dot{flex:0 0 auto;width:8px;height:8px;border-radius:50%;background:#df4052;box-shadow:0 0 0 3px rgba(223,64,82,.12)}.guest-control-page .status-success .status-dot{background:#18a566;box-shadow:0 0 0 3px rgba(24,165,102,.13)}.guest-control-page .status-duplicate .status-dot{background:#d59a12;box-shadow:0 0 0 3px rgba(213,154,18,.15)}
    .guest-control-page .log-detail-line{display:flex;align-items:center;gap:8px 18px;min-width:0;padding:9px 12px;border-top:1px dashed #d9dee6;background:#f8fafc;color:#667085;font-size:11px;line-height:1.65}
    .guest-control-page .log-detail-line span{min-width:0}.guest-control-page .log-main-message{flex:1 1 auto;color:#344054;font-weight:600}.guest-control-page .log-context{flex:0 1 auto;white-space:nowrap}.guest-control-page .log-context strong{color:#475467;font-weight:700}
    .guest-control-page .cell-stack{display:grid;gap:3px;min-width:0}.guest-control-page .cell-stack small{color:#667085;font-size:10px;line-height:1.4}
    .guest-control-page .latest-operation strong{color:#1f2937;font-size:12px}.guest-control-page .attendance-state{color:#344054;font-weight:700}
    .guest-control-page .record-actions{display:flex;flex-wrap:wrap;gap:5px;align-items:flex-start}
    .guest-control-page .badge{display:inline-flex;max-width:100%;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;line-height:1.4}
    .guest-control-page .badge.success{background:#e8f8ef;color:#087443}.guest-control-page .badge.duplicate{background:#fff3d4;color:#805900}.guest-control-page .badge.error{background:#ffeaed;color:#a12432}
    .guest-control-page .guest-name-link{appearance:none;margin:0;padding:0;border:0;background:transparent;color:var(--primary,#e11d2e);font:inherit;font-weight:700;cursor:pointer;text-align:right;text-decoration:underline;text-decoration-color:transparent;text-underline-offset:4px;transition:color .15s ease,text-decoration-color .15s ease}
    .guest-control-page .guest-name-link:hover,.guest-control-page .guest-name-link:focus-visible{color:var(--primary-hover,#b91827);text-decoration-color:currentColor;outline:0}
    .guest-control-page .walk-in-action{appearance:none;padding:5px 9px;border:1px solid var(--primary-border-soft,rgba(225,29,46,.28));border-radius:8px;background:var(--primary-focus,rgba(225,29,46,.08));color:var(--primary,#e11d2e);font:inherit;font-size:11px;font-weight:700;white-space:nowrap;cursor:pointer}.guest-control-page .walk-in-action:hover{background:var(--primary,#e11d2e);color:#fff}
    .guest-control-page .empty{text-align:center;color:var(--muted,#6b7280);padding:26px}
    .guest-control-page .guest-dialog{width:min(620px,calc(100% - 28px));max-height:min(760px,calc(100vh - 40px));margin:auto;padding:0;border:1px solid var(--border,#e5e7eb);border-radius:14px;background:#fff;color:var(--text,#111);box-shadow:0 24px 70px rgba(17,24,39,.22);overflow:hidden}
    .guest-control-page .guest-dialog::backdrop{background:rgba(17,24,39,.34);backdrop-filter:blur(2px)}
    .guest-control-page .dialog-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;border-bottom:1px solid var(--border,#e5e7eb);background:#fff}
    .guest-control-page .dialog-title-wrap{min-width:0}.guest-control-page .dialog-kicker{display:block;margin-bottom:2px;color:var(--muted,#6b7280);font-size:11px}.guest-control-page .dialog-head h3{margin:0;font-size:18px;line-height:1.5;overflow-wrap:anywhere}
    .guest-control-page .dialog-close{flex:0 0 auto;width:34px;height:34px;padding:0;border:1px solid var(--border,#e5e7eb);border-radius:9px;background:#fff;color:var(--muted,#6b7280);font-size:22px;line-height:1;cursor:pointer}.guest-control-page .dialog-close:hover{background:#f7f8fa;color:var(--text,#111)}
    .guest-control-page .dialog-body{max-height:calc(100vh - 145px);padding:18px;overflow:auto}
    .guest-control-page .detail-section{margin:0 0 18px}.guest-control-page .detail-section:last-child{margin-bottom:0}.guest-control-page .detail-section-title{margin:0 0 9px;color:var(--muted,#6b7280);font-size:12px;font-weight:700}
    .guest-control-page .detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;background:var(--border,#e5e7eb);border:1px solid var(--border,#e5e7eb);border-radius:10px;overflow:hidden}
    .guest-control-page .detail-item{min-width:0;padding:10px 12px;background:#fff}.guest-control-page .detail-item.full{grid-column:1/-1}.guest-control-page .detail-label{display:block;margin-bottom:4px;color:var(--muted,#6b7280);font-size:11px}.guest-control-page .detail-value{display:block;font-size:13px;font-weight:600;line-height:1.7;overflow-wrap:anywhere}.guest-control-page .detail-message{padding:11px 12px;border-inline-start:3px solid var(--primary,#e11d2e);border-radius:6px;background:#f8fafc;font-size:13px;line-height:1.8}
    .guest-control-page .walk-in-form{margin:0}.guest-control-page .walk-in-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.guest-control-page .walk-in-field{display:block;min-width:0}.guest-control-page .walk-in-field.full{grid-column:1/-1}.guest-control-page .walk-in-field>span{display:block;margin-bottom:6px;color:var(--text,#111);font-size:12px;font-weight:700}.guest-control-page .walk-in-field input,.guest-control-page .walk-in-field select{display:block;width:100%;height:42px;padding:8px 10px;border:1px solid var(--border,#e5e7eb);border-radius:9px;background:#fff;color:var(--text,#111);font:inherit;font-size:13px;outline:0}.guest-control-page .walk-in-field input:focus,.guest-control-page .walk-in-field select:focus{border-color:var(--primary,#e11d2e);box-shadow:0 0 0 3px var(--primary-focus,rgba(225,29,46,.1))}.guest-control-page .walk-in-field select:disabled{background:#f3f4f6;color:#9ca3af}.guest-control-page .walk-in-check{display:flex;align-items:center;gap:8px;grid-column:1/-1;padding:10px 12px;border-radius:9px;background:#f8fafc;font-size:13px;font-weight:700}.guest-control-page .walk-in-check input{width:17px;height:17px;accent-color:var(--primary,#e11d2e)}.guest-control-page .dialog-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;margin-top:16px;padding-top:14px;border-top:1px solid var(--border,#e5e7eb)}.guest-control-page .dialog-action{min-height:38px;padding:8px 14px;border:1px solid var(--border,#e5e7eb);border-radius:9px;background:#fff;color:var(--text,#111);font:inherit;font-size:13px;font-weight:700;cursor:pointer}.guest-control-page .dialog-action.primary{border-color:var(--primary,#e11d2e);background:var(--primary,#e11d2e);color:#fff}.guest-control-page .dialog-action:disabled{opacity:.55;cursor:not-allowed}.guest-control-page .walk-in-status{min-height:20px;margin:10px 0 0;color:var(--muted,#6b7280);font-size:12px}.guest-control-page .walk-in-status.error{color:#a12432}.guest-control-page .walk-in-status.success{color:#087443}
    @media(max-width:760px){.guest-control-page .guest-shell{width:min(100% - 20px,1180px);padding:14px 0 28px}.guest-control-page .guest-card{padding:16px 8px}.guest-control-page .hero-head{display:block}.guest-control-page .phase{margin-top:12px}.guest-control-page .period-navigation{grid-template-columns:1fr}.guest-control-page .scan-grid{grid-template-columns:1fr;gap:16px}.guest-control-page .scanner input{height:54px;font-size:24px}.guest-control-page .card-head{align-items:flex-start}.guest-control-page .card-head .muted{display:none}.guest-control-page .log-toolbar{grid-template-columns:1fr}.guest-control-page .log-search-meta{padding:0;text-align:right}.guest-control-page th,.guest-control-page td{padding-inline:5px;font-size:10px}.guest-control-page th{font-size:9px}.guest-control-page .log-card-cell{padding:10px 5px}.guest-control-page .log-detail-line{align-items:flex-start;gap:4px 10px;flex-wrap:wrap}.guest-control-page .log-main-message{flex-basis:100%}.guest-control-page .detail-grid,.guest-control-page .walk-in-grid{grid-template-columns:1fr}.guest-control-page .detail-item.full,.guest-control-page .walk-in-field.full{grid-column:auto}}
  </style>
</head>
<body class="guest-control-page">
<main class="guest-shell" data-check-in-app data-csrf="<?= $csrfValue ?>" data-can-register-uninvited="<?= $canScan ? '1' : '0' ?>" data-logs-version="<?= $logsVersion ?>" data-initial-logs="<?= $logs ?>">
  <section class="hero guest-card">
    <div class="hero-head">
      <div>
        <p class="eyebrow">پنل کنترل مهمان</p>
        <h1><?= $name ?></h1>
        <?php if ($hasActivePeriod): ?><div class="event-meta"><span>بازه رویداد فعال: <b><?= $periodTitle ?></b></span></div><?php endif; ?>
      </div>
      <div class="phase phase--<?= htmlspecialchars($phaseClass, ENT_QUOTES, 'UTF-8') ?>">وضعیت فعلی: <?= $phaseLabel ?></div>
    </div>
    <?php if (!$hasActivePeriod): ?>
    <div class="period-navigation">
      <div class="period-neighbor"><span class="period-neighbor-label">بازه قبلی</span><strong><?= htmlspecialchars($previousSummary['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($previousSummary['meta'], ENT_QUOTES, 'UTF-8') ?></small></div>
      <div class="period-neighbor"><span class="period-neighbor-label">بازه بعدی</span><strong><?= htmlspecialchars($nextSummary['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($nextSummary['meta'], ENT_QUOTES, 'UTF-8') ?></small></div>
    </div>
    <?php endif; ?>
    <div class="scan-grid">
      <div class="scanner">
        <label class="scanner-label" for="guest-national-id">شناسه مهمان (کد ملی یا کد پرسنلی)</label>
        <input id="guest-national-id" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="10" autocomplete="off" autofocus aria-label="شناسه مهمان ۴ تا ۱۰ رقمی" data-national-id<?= $canScan ? '' : ' disabled aria-disabled="true"' ?> />
        <p class="hint">کد ملی ۱۰ رقمی خودکار بررسی می‌شود؛ کد پرسنلی ۴ تا ۹ رقمی را اسکن کنید یا پس از ورود دستی Enter بزنید.</p>
      </div>
      <div class="result-area">
        <span class="result-label">نتیجه بررسی</span>
        <div class="result <?= $canScan ? 'idle' : 'error' ?>" data-result aria-live="polite"><?= htmlspecialchars($initialResult, ENT_QUOTES, 'UTF-8') ?></div>
        <p class="hint">نتیجه آخرین بررسی در این قسمت نمایش داده می‌شود.</p>
      </div>
    </div>
  </section>
  <section class="guest-card">
    <div class="card-head"><h2>گزارش کل مهمانان</h2><span class="muted">جدیدترین موارد در بالا</span></div>
    <div class="log-toolbar">
      <label class="log-search"><span>جستجو در همه گزارش‌ها</span><input type="search" autocomplete="off" placeholder="نام، کد ملی، کد پرسنلی، شماره مهمان، وضعیت، بازه، واحد یا تاریخ" data-log-search /></label>
      <span class="log-search-meta" data-log-search-meta aria-live="polite"></span>
    </div>
    <div class="table-wrap"><table><thead><tr><th>وضعیت</th><th>مهمان</th><th>آخرین عملیات</th><th>وضعیت حضور</th><th>عملیات</th></tr></thead><tbody data-logs></tbody></table></div>
  </section>
  <dialog class="guest-dialog" data-guest-dialog aria-labelledby="guest-dialog-title">
    <div class="dialog-head">
      <div class="dialog-title-wrap"><span class="dialog-kicker">اطلاعات مهمان و حضور</span><h3 id="guest-dialog-title" data-dialog-title>جزئیات مهمان</h3></div>
      <button type="button" class="dialog-close" data-dialog-close aria-label="بستن">×</button>
    </div>
    <div class="dialog-body" data-dialog-content></div>
  </dialog>
  <dialog class="guest-dialog" data-walk-in-dialog aria-labelledby="walk-in-dialog-title">
    <div class="dialog-head">
      <div class="dialog-title-wrap"><span class="dialog-kicker">ثبت در رویداد و بازه فعال</span><h3 id="walk-in-dialog-title">افزودن مهمان ناخوانده</h3></div>
      <button type="button" class="dialog-close" data-walk-in-close aria-label="بستن">×</button>
    </div>
    <div class="dialog-body">
      <form class="walk-in-form" data-walk-in-form>
        <div class="walk-in-grid">
          <label class="walk-in-field"><span>نام *</span><input name="first_name" type="text" maxlength="191" required /></label>
          <label class="walk-in-field"><span>نام خانوادگی *</span><input name="last_name" type="text" maxlength="191" required /></label>
          <label class="walk-in-field"><span>کد ملی *</span><input name="national_id" type="text" inputmode="numeric" maxlength="10" required /></label>
          <label class="walk-in-field"><span>کد پرسنلی</span><input name="work_id" type="text" maxlength="128" /></label>
          <label class="walk-in-field"><span>شماره همراه</span><input name="phone_number" type="text" inputmode="tel" maxlength="32" /></label>
          <label class="walk-in-field"><span>جنسیت</span><select name="gender" data-walk-in-option="gender"></select></label>
          <label class="walk-in-check"><input name="outside_organization" type="checkbox" data-outside-organization /><span>خارج از سازمان / اداره نامشخص</span></label>
          <label class="walk-in-field"><span>معاونت *</span><select name="deputy" data-organization-field data-walk-in-option="deputy"></select></label>
          <label class="walk-in-field"><span>اداره کل *</span><select name="general_department" data-organization-field data-walk-in-option="general_department"></select></label>
          <label class="walk-in-field"><span>اداره *</span><select name="department" data-organization-field data-walk-in-option="department"></select></label>
          <label class="walk-in-field"><span>سطح پستی</span><select name="postal_level" data-walk-in-option="postal_level"></select></label>
        </div>
        <p class="walk-in-status" data-walk-in-status aria-live="polite"></p>
        <div class="dialog-actions"><button type="button" class="dialog-action" data-walk-in-cancel>انصراف</button><button type="submit" class="dialog-action primary" data-walk-in-submit>ثبت مهمان ناخوانده</button></div>
      </form>
    </div>
  </dialog>
</main>
<script nonce="<?= $nonce ?>">
(() => {
  const app=document.querySelector('[data-check-in-app]'); if(!app)return;
   const input=app.querySelector('[data-national-id]'),result=app.querySelector('[data-result]'),tbody=app.querySelector('[data-logs]'),logSearch=app.querySelector('[data-log-search]'),logSearchMeta=app.querySelector('[data-log-search-meta]'),dialog=app.querySelector('[data-guest-dialog]'),dialogTitle=app.querySelector('[data-dialog-title]'),dialogContent=app.querySelector('[data-dialog-content]'),walkInDialog=app.querySelector('[data-walk-in-dialog]'),walkInForm=app.querySelector('[data-walk-in-form]'),walkInStatus=app.querySelector('[data-walk-in-status]');
  const canRegisterUninvited=app.dataset.canRegisterUninvited==='1';
  const SCANNER_MAX_KEY_GAP_MS=50,SCANNER_MIN_FAST_GAPS=3,SCANNER_COMPLETION_DELAY_MS=90;
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize=(v)=>String(v??'').replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-0x06f0)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-0x0660)).replace(/\D/g,'').slice(0,10);
  const label=(s)=>s==='success'?'ورود موفق':s==='quit_success'?'خروج موفق':s==='force_entry_success'?'ورود اجباری':s==='force_quit_success'?'خروج اجباری':s==='walk_in_registered'?'مهمان ناخوانده ثبت شد':s==='duplicate'?'قبلاً وارد شده':s==='quit_duplicate'?'قبلاً خارج شده':s==='quit_without_entry'?'ورود ثبت نشده':s==='minimum_stay'?'حداقل مدت حضور کامل نشده':s==='entry_closed_quit_wave'?'ورود به‌دلیل موج خروج بسته است':s==='invalid_attendance_record'?'سابقه حضور ناسازگار':s==='invited_other_period'?'دعوت در بازه دیگر':s==='user_inactive'?'مهمان غیرفعال':s==='no_active_period'?'بدون بازه فعال':s==='multiple_active_periods'?'هم‌پوشانی بازه‌ها':s==='not_found'?'یافت نشد':s==='not_invited'?'دعوت نشده':s==='upcoming'?'در انتظار شروع':s==='immune_time'?'زمان ایمن':s==='ended'?'پایان‌یافته':s==='inactive'?'غیرفعال':s==='invalid_schedule'?'زمان‌بندی نامعتبر':'ناموفق';
  const cls=(s)=>(s==='success'||s==='quit_success'||s==='force_entry_success'||s==='force_quit_success'||s==='walk_in_registered')?'success':(s==='duplicate'||s==='quit_duplicate')?'duplicate':'error';
  const operationLabel=(row)=>row.attendance_action==='quit'?'خروج':row.attendance_action==='entry'?'ورود':row.attendance_action==='register'?'ثبت مهمان':'بررسی';
  const attendanceStateLabel=(state)=>state==='quit_completed'?'خروج ثبت شده':state==='entered'?'وارد شده':state==='invalid_quit_without_entry'?'خروج ناسازگار بدون ورود':'هنوز وارد نشده';
  const dateTime=(date,time,fallback='')=>[date,time].filter(Boolean).join(' ')||fallback||'—';
  const walkInStatuses=new Set(['not_found','not_invited','invited_other_period']);
  const presenceMark=(value)=>value?'بله':'—';
  const render=(items)=>{
    const rows=Array.isArray(items)?items:[];
    if(logSearchMeta)logSearchMeta.textContent=`${rows.length.toLocaleString('fa-IR')} نتیجه`;
    const registeredWalkIns=new Set(rows.filter(row=>row.status==='walk_in_registered').map(row=>`${row.national_id||''}|${row.period_code||''}`));
    const actionRows=new Set();
    tbody.innerHTML=rows.length?rows.map((row,index)=>{
      const name=String(row.full_name||'').trim();
      const identity=String(row.national_id||row.work_id||row.phone_number||name||index);
      const actionKey=`${identity}|${row.period_code||''}`;
      const guestCode=normalize(row.national_id||row.work_id||'');
      const registrationKey=`${row.national_id||''}|${row.period_code||''}`;
      const actions=[];
      if(canRegisterUninvited&&walkInStatuses.has(String(row.status||''))&&!registeredWalkIns.has(registrationKey))actions.push(`<button type="button" class="walk-in-action" data-register-uninvited="${index}">ثبت مهمان ناخوانده</button>`);
      if(!actionRows.has(actionKey)&&guestCode.length>=4&&['entry','quit'].includes(String(row.force_action||''))){
        actionRows.add(actionKey);
        actions.push(`<button type="button" class="force-attendance-action" data-force-log="${index}" data-force-action="${esc(row.force_action)}">${esc(row.force_label||(`Force ${row.force_action==='entry'?'Enter':'Quit'}`))}</button>`);
      }
      const identifier=row.national_id?`کد ملی: ${esc(row.national_id)}`:(row.work_id?`کد پرسنلی: ${esc(row.work_id)}`:'بدون شناسه');
      const statusClass=cls(row.status);
      const latestOperation=`<div class="cell-stack latest-operation"><strong>${esc(operationLabel(row))}</strong><small class="code">${esc(dateTime(row.operation_date,row.operation_time,row.attempted_at))}</small></div>`;
      return `<tr class="log-card-row"><td colspan="5"><article class="log-card status-${statusClass}"><div class="log-card-main"><div class="log-card-cell"><div class="log-status"><span class="status-dot" aria-hidden="true"></span><span class="badge ${statusClass}">${esc(label(row.status))}</span></div></div><div class="log-card-cell"><div class="cell-stack">${name?`<button type="button" class="guest-name-link" data-guest-detail="${index}">${esc(name)}</button>`:'—'}<small class="code">${identifier}</small></div></div><div class="log-card-cell">${latestOperation}</div><div class="log-card-cell"><span class="attendance-state">${esc(attendanceStateLabel(row.attendance_state))}</span></div><div class="log-card-cell"><div class="record-actions">${actions.join('')||'—'}</div></div></div><div class="log-detail-line"><span class="log-main-message">${esc(row.message||'—')}</span><span class="log-context">بازه: <strong>${esc(row.period_title||'—')}</strong></span><span class="log-context">بررسی: <strong class="code">${esc(row.attempted_at||'—')}</strong></span></div></article></td></tr>`;
    }).join(''):`<tr><td colspan="5" class="empty">${logSearch?.value.trim()?'موردی مطابق جستجو پیدا نشد.':'هنوز موردی بررسی نشده است.'}</td></tr>`;
  };
  const detailItem=(title,value,options='')=>`<div class="detail-item${options.includes('full')?' full':''}"><span class="detail-label">${esc(title)}</span><span class="detail-value${options.includes('code')?' code':''}">${esc(value||'—')}</span></div>`;
  const openDetail=(row)=>{if(!row||!dialog||!dialogTitle||!dialogContent)return;dialogTitle.textContent=row.full_name||'جزئیات مهمان';dialogContent.innerHTML=`<section class="detail-section"><h4 class="detail-section-title">مشخصات مهمان</h4><div class="detail-grid">${detailItem('نوع مهمان',row.is_uninvited_guest?'مهمان ناخوانده':'دعوت‌شده')}${detailItem('کد ملی',row.national_id,'code')}${detailItem('کد پرسنلی',row.work_id,'code')}${detailItem('شماره همراه',row.phone_number,'code')}${detailItem('شماره مهمان',row.guest_number,'code')}${detailItem('معاونت',row.deputy)}${detailItem('اداره کل',row.general_department)}${detailItem('اداره',row.department)}${detailItem('جنسیت / سطح پستی',[row.gender,row.postal_level].filter(Boolean).join(' / '))}</div></section><section class="detail-section"><h4 class="detail-section-title">اطلاعات حضور در بازه</h4><div class="detail-grid">${detailItem('بازه',row.period_title)}${detailItem('وضعیت فعلی حضور',attendanceStateLabel(row.attendance_state))}${detailItem('Correct Presence',presenceMark(row.correct_presence))}${detailItem('Fake Presence',presenceMark(row.fake_presence))}${detailItem('وضعیت آخرین بررسی',label(row.status))}${detailItem('زمان ورود',dateTime(row.entered_date,row.entered_time),'code')}${detailItem('زمان خروج',dateTime(row.quit_date,row.quit_time),'code')}${detailItem(`زمان عملیات ${operationLabel(row)}`,dateTime(row.operation_date,row.operation_time,row.attempted_at),'code')}${detailItem('زمان بررسی',row.attempted_at,'code')}</div></section><section class="detail-section"><h4 class="detail-section-title">نتیجه ثبت‌شده</h4><div class="detail-message">${esc(row.message||'—')}</div></section>`;if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','')};
  const walkInControl=(name)=>walkInForm?.elements.namedItem(name)||null;
  const fillWalkInSelect=(name,values,current='')=>{const select=walkInControl(name);if(!(select instanceof HTMLSelectElement))return;select.replaceChildren();const blank=document.createElement('option');blank.value='';blank.textContent='انتخاب کنید';select.append(blank);const unique=new Set(Array.isArray(values)?values.map(value=>String(value||'').trim()).filter(Boolean):[]);if(current)unique.add(String(current));for(const value of unique){const option=document.createElement('option');option.value=value;option.textContent=value;select.append(option)}select.value=String(current||'')};
  let walkInOptionsPromise=null;
  const loadWalkInOptions=()=>{if(walkInOptionsPromise)return walkInOptionsPromise;const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','uninvited_options');walkInOptionsPromise=fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async response=>{const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'دریافت گزینه‌های سازمانی ناموفق بود.');return data.options||{}}).catch(error=>{walkInOptionsPromise=null;throw error});return walkInOptionsPromise};
  const syncOutsideOrganization=()=>{const outside=walkInControl('outside_organization');const checked=outside instanceof HTMLInputElement&&outside.checked;walkInForm?.querySelectorAll('[data-organization-field]').forEach(select=>{select.disabled=checked;select.required=!checked;if(checked)select.value=''});const workId=walkInControl('work_id');if(workId instanceof HTMLInputElement)workId.required=!checked};
  const closeWalkIn=()=>{if(!walkInDialog)return;if(typeof walkInDialog.close==='function'&&walkInDialog.open)walkInDialog.close();else walkInDialog.removeAttribute('open')};
  const openWalkIn=async(row)=>{if(!walkInDialog||!walkInForm||!row)return;walkInForm.reset();if(walkInStatus){walkInStatus.className='walk-in-status';walkInStatus.textContent='در حال دریافت گزینه‌های سازمانی...'}const values={first_name:String(row.first_name||''),last_name:String(row.last_name||''),national_id:String(row.national_id||''),work_id:String(row.work_id||''),phone_number:String(row.phone_number||'')};for(const [name,value] of Object.entries(values)){const control=walkInControl(name);if(control instanceof HTMLInputElement)control.value=value}const outside=walkInControl('outside_organization');if(outside instanceof HTMLInputElement)outside.checked=Boolean(row.outside_organization);syncOutsideOrganization();if(typeof walkInDialog.showModal==='function')walkInDialog.showModal();else walkInDialog.setAttribute('open','');try{const options=await loadWalkInOptions();fillWalkInSelect('deputy',options.deputy,row.deputy||'');fillWalkInSelect('general_department',options.general_department,row.general_department||'');fillWalkInSelect('department',options.department,row.department||'');fillWalkInSelect('gender',options.gender?.length?options.gender:['مرد','زن'],row.gender||'');fillWalkInSelect('postal_level',options.postal_level,row.postal_level||'');syncOutsideOrganization();if(walkInStatus)walkInStatus.textContent='اطلاعات را تکمیل و ثبت کنید.'}catch(error){if(walkInStatus){walkInStatus.className='walk-in-status error';walkInStatus.textContent=error instanceof Error?error.message:'دریافت گزینه‌ها ناموفق بود.'}}};
  let logs=[],lastLogsVersion=String(app.dataset.logsVersion||''); try{logs=JSON.parse(app.dataset.initialLogs||'[]')}catch{} render(logs);
  let searchTimer=0,searchRequest=0,logRefreshInFlight=false;
  const searchLogs=async({silent=false}={})=>{if(logRefreshInFlight&&silent)return;const requestId=++searchRequest;const query=String(logSearch?.value||'').trim();if(!silent&&logSearchMeta)logSearchMeta.textContent='در حال جستجو...';logRefreshInFlight=true;try{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','search_logs');if(query)url.searchParams.set('q',query);else url.searchParams.delete('q');url.searchParams.set('_sync',String(Date.now()));const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'جستجوی گزارش‌ها ناموفق بود.');if(requestId!==searchRequest)return;lastLogsVersion=String(data.logs_version||lastLogsVersion);logs=Array.isArray(data.logs)?data.logs:[];render(logs)}catch(error){if(requestId!==searchRequest||silent)return;if(logSearchMeta)logSearchMeta.textContent=error instanceof Error?error.message:'جستجو ناموفق بود.'}finally{logRefreshInFlight=false}};
  logSearch?.addEventListener('input',()=>{window.clearTimeout(searchTimer);searchTimer=window.setTimeout(()=>void searchLogs(),280)});
   const acceptUpdatedLogs=(items,version='')=>{searchRequest+=1;if(version)lastLogsVersion=String(version);if(logSearch?.value.trim()){void searchLogs();return}logs=Array.isArray(items)?items:[];render(logs)};
  const forceAttendanceFromLog=async(index,button)=>{const row=logs[index];if(!row||!(button instanceof HTMLButtonElement)||isSubmitting||scanProcessing)return;const attendanceAction=String(button.dataset.forceAction||row.force_action||''),guestCode=normalize(row.national_id||row.work_id||'');if(!['entry','quit'].includes(attendanceAction)||guestCode.length<4)return;const title=attendanceAction==='entry'?'Force Enter':'Force Quit';if(!window.confirm(`${title} برای این مهمان ثبت شود؟ این عملیات حضور را Fake Presence علامت می‌زند.`))return;isSubmitting=true;button.disabled=true;input.focus();result.className='result loading';result.textContent='در حال ثبت عملیات اجباری...';try{const response=await fetch(window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'force_attendance',attendance_action:attendanceAction,guest_code:guestCode,csrf:app.dataset.csrf||''})});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'ثبت عملیات اجباری ناموفق بود.');result.className='result success';result.textContent=data.message||'عملیات اجباری ثبت شد.';if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version)}catch(error){result.className='result error';result.textContent=error instanceof Error?error.message:'ثبت عملیات اجباری ناموفق بود.';button.disabled=false}finally{isSubmitting=false;input.focus();void processScanQueue()}};
  tbody.addEventListener('click',event=>{const target=event.target instanceof Element?event.target:null;if(!target)return;const forceTrigger=target.closest('[data-force-log]');if(forceTrigger){const index=Number(forceTrigger.dataset.forceLog);if(Number.isInteger(index)&&logs[index])void forceAttendanceFromLog(index,forceTrigger);return}const walkInTrigger=target.closest('[data-register-uninvited]');if(walkInTrigger){const index=Number(walkInTrigger.dataset.registerUninvited);if(Number.isInteger(index)&&logs[index])void openWalkIn(logs[index]);return}const trigger=target.closest('[data-guest-detail]');if(!trigger)return;const index=Number(trigger.dataset.guestDetail);if(Number.isInteger(index)&&logs[index])openDetail(logs[index])});
  app.querySelector('[data-dialog-close]')?.addEventListener('click',()=>dialog?.close());
  dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
  app.querySelector('[data-walk-in-close]')?.addEventListener('click',closeWalkIn);
  app.querySelector('[data-walk-in-cancel]')?.addEventListener('click',closeWalkIn);
  walkInDialog?.addEventListener('click',event=>{if(event.target===walkInDialog)closeWalkIn()});
  walkInControl('outside_organization')?.addEventListener('change',syncOutsideOrganization);
  walkInControl('national_id')?.addEventListener('input',event=>{event.target.value=normalize(event.target.value)});
  walkInForm?.addEventListener('submit',async event=>{event.preventDefault();const submitButton=app.querySelector('[data-walk-in-submit]');if(submitButton instanceof HTMLButtonElement)submitButton.disabled=true;if(walkInStatus){walkInStatus.className='walk-in-status';walkInStatus.textContent='در حال ثبت مهمان ناخوانده...'}try{const formData=new FormData(walkInForm);const payload=Object.fromEntries(formData.entries());payload.action='register_uninvited';payload.csrf=app.dataset.csrf||'';payload.outside_organization=Boolean(walkInControl('outside_organization')?.checked);const response=await fetch(window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'ثبت مهمان ناخوانده ناموفق بود.');if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version);result.className='result success';result.textContent=data.message||'مهمان ناخوانده ثبت شد.';if(walkInStatus){walkInStatus.className='walk-in-status success';walkInStatus.textContent=data.message||'ثبت شد.'}window.setTimeout(closeWalkIn,500)}catch(error){if(walkInStatus){walkInStatus.className='walk-in-status error';walkInStatus.textContent=error instanceof Error?error.message:'ثبت مهمان ناخوانده ناموفق بود.'}}finally{if(submitButton instanceof HTMLButtonElement)submitButton.disabled=false}});
  let isSubmitting=false,scanProcessing=false,scannerCompletionTimer=0,lastNumericKeyAt=0,consecutiveFastGaps=0,scannerDetected=false;
  const scanQueue=[];
  const resetScannerState=()=>{window.clearTimeout(scannerCompletionTimer);scannerCompletionTimer=0;lastNumericKeyAt=0;consecutiveFastGaps=0;scannerDetected=false};
  const queueStatus=()=>scanQueue.length>0?` (${scanQueue.length.toLocaleString('fa-IR')} اسکن در صف)`:'';
  const processScanQueue=async()=>{if(scanProcessing||isSubmitting||scanQueue.length===0)return;scanProcessing=true;try{while(scanQueue.length>0){const guestCode=scanQueue.shift();result.className='result loading';result.textContent=`در حال بررسی شناسه ${guestCode}${queueStatus()}...`;try{const response=await fetch(window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({guest_code:guestCode,csrf:app.dataset.csrf||''})});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'بررسی مهمان ناموفق بود.');const good=data.result==='success'||data.result==='quit_success'||data.result==='force_entry_success'||data.result==='force_quit_success';const duplicate=data.result==='duplicate'||data.result==='quit_duplicate';result.className=`result ${good?'success':duplicate?'duplicate':'error'}`;result.textContent=`${data.message||'بررسی انجام شد.'}${queueStatus()}`;if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version)}catch(error){result.className='result error';result.textContent=`${error instanceof Error?error.message:'بررسی مهمان ناموفق بود.'}${queueStatus()}`}}}finally{scanProcessing=false;input.focus();if(scanQueue.length>0)void processScanQueue()}};
  const enqueueScan=(rawCode)=>{const guestCode=normalize(rawCode);if(guestCode.length<4||guestCode.length>10)return;scanQueue.push(guestCode);input.value='';resetScannerState();input.focus();if(scanProcessing||isSubmitting){result.className='result loading';result.textContent=`اسکن دریافت شد${queueStatus()}. در صف پردازش است.`}void processScanQueue()};
  const scheduleScannerSubmission=(value)=>{window.clearTimeout(scannerCompletionTimer);scannerCompletionTimer=window.setTimeout(()=>{scannerCompletionTimer=0;const completedCode=normalize(input.value);if(scannerDetected&&completedCode===value&&completedCode.length>=4&&completedCode.length<=9)enqueueScan(completedCode)},SCANNER_COMPLETION_DELAY_MS)};
  input.addEventListener('input',()=>{const value=normalize(input.value);if(input.value!==value)input.value=value;if(value.length===10){enqueueScan(value);return}if(scannerDetected&&value.length>=4&&value.length<=9)scheduleScannerSubmission(value)});
  input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();window.clearTimeout(scannerCompletionTimer);const value=normalize(input.value);if(value.length>=4&&value.length<=10)enqueueScan(value);return}if(event.repeat){resetScannerState();return}if(!/^[0-9]$/.test(event.key)){if(!event.ctrlKey&&!event.metaKey&&!event.altKey)resetScannerState();return}const now=performance.now();const gap=lastNumericKeyAt>0?now-lastNumericKeyAt:Number.POSITIVE_INFINITY;if(gap<=SCANNER_MAX_KEY_GAP_MS)consecutiveFastGaps+=1;else{consecutiveFastGaps=0;scannerDetected=false}lastNumericKeyAt=now;if(consecutiveFastGaps>=SCANNER_MIN_FAST_GAPS)scannerDetected=true});
  const LOG_SYNC_INTERVAL_MS=2500;
  let logVersionCheckInFlight=false;
  const syncVisibleLogs=async()=>{if(document.visibilityState!=='visible'||logRefreshInFlight||logVersionCheckInFlight)return;logVersionCheckInFlight=true;try{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','logs_version');url.searchParams.set('_sync',String(Date.now()));const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')return;const version=String(data.logs_version||'');if(version&&version!==lastLogsVersion)await searchLogs({silent:true})}finally{logVersionCheckInFlight=false}};
  const logSyncTimer=window.setInterval(()=>void syncVisibleLogs(),LOG_SYNC_INTERVAL_MS);
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')void syncVisibleLogs()});
  window.addEventListener('pagehide',()=>window.clearInterval(logSyncTimer),{once:true});
  input.focus();
})();
</script>
</body>
</html>
    <?php
    exit;
}

function handleEgmCheckInPage(string $projectRoot, string $missionDir, array $sessionUser): never
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $payload = null;
    $requestedAction = '';
    if ($method === 'POST') {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) $payload = $_POST;
        $requestedAction = strtolower(trim((string)($payload['action'] ?? 'check_in')));
    }
    $isResetRequest = in_array($requestedAction, ['reset_period_records', 'reset_all_records'], true);
    $canUseGuestControl = userHasPermissionId($sessionUser, 'event-guest-manager:main');
    $canResetAttendance = userHasPermissionId($sessionUser, 'event-guest-manager:manage-tasks');
    if (($isResetRequest && !$canResetAttendance) || (!$isResetRequest && !$canUseGuestControl)) {
        $message = $isResetRequest
            ? 'شما اجازه بازنشانی سوابق حضور را ندارید.'
            : 'شما اجازه دسترسی به پنل کنترل مهمان را ندارید.';
        denyPanelAccess(403, $message, $method === 'POST');
    }
    try {
        $context = egmCheckInContext($projectRoot, $missionDir);
        $context['can_reset'] = $canResetAttendance;
        if ($method === 'GET') {
            $getAction = strtolower(trim((string)($_GET['action'] ?? '')));
            if ($getAction === 'uninvited_options') {
                egmCheckInJson(['status' => 'ok', 'options' => egmCheckInUninvitedOptions($context)]);
            }
            if ($getAction === 'logs_version') {
                egmCheckInJson(['status' => 'ok', 'logs_version' => egmCheckInLogsVersion($context)]);
            }
            if ($getAction === 'search_logs') {
                $logsVersion = egmCheckInLogsVersion($context);
                egmCheckInJson([
                    'status' => 'ok',
                    'logs_version' => $logsVersion,
                    'logs' => egmCheckInRecentLogs($context, 200, (string)($_GET['q'] ?? '')),
                ]);
            }
        }
        if ($method === 'POST') {
            if (!is_array($payload)) $payload = [];
            if (!egmSecurityIsValidCsrfToken(egmSecurityReadCsrfFromRequest($payload))) {
                egmCheckInJson(['status' => 'error', 'message' => 'توکن امنیتی نامعتبر است.'], 403);
            }
            $action = $requestedAction !== '' ? $requestedAction : 'check_in';
            if (in_array($action, ['reset_period_records', 'reset_all_records'], true)) {
                if (empty($context['can_reset'])) {
                    egmCheckInJson(['status' => 'error', 'message' => 'شما اجازه بازنشانی سوابق حضور را ندارید.'], 403);
                }
                $resetAll = $action === 'reset_all_records';
                $expectedConfirmation = $resetAll ? 'RESET_ALL_ATTENDANCE' : 'RESET_PERIOD_ATTENDANCE';
                if (!hash_equals($expectedConfirmation, trim((string)($payload['confirmation'] ?? '')))) {
                    egmCheckInJson(['status' => 'error', 'message' => 'تأیید بازنشانی سوابق معتبر نیست.'], 422);
                }
                $reset = egmCheckInResetAttendanceRecords(
                    $context,
                    $resetAll ? null : (string)($payload['period_code'] ?? '')
                );
                $logsVersion = egmCheckInLogsVersion($context);
                egmCheckInJson(['status' => 'ok'] + $reset + [
                    'logs_version' => $logsVersion,
                    'logs' => egmCheckInRecentLogs($context),
                ]);
            }
            if ($action === 'register_uninvited') {
                $result = egmCheckInRegisterUninvited($context, $payload, $sessionUser);
            } elseif ($action === 'force_attendance') {
                $result = egmCheckInProcess(
                    $context,
                    (string)($payload['guest_code'] ?? ($payload['national_id'] ?? '')),
                    null,
                    (string)($payload['attendance_action'] ?? ''),
                    $sessionUser
                );
            } else {
                $result = egmCheckInProcess(
                    $context,
                    (string)($payload['guest_code'] ?? ($payload['national_id'] ?? ''))
                );
            }
            $logsVersion = egmCheckInLogsVersion($context);
            egmCheckInJson(['status' => 'ok'] + $result + [
                'logs_version' => $logsVersion,
                'logs' => egmCheckInRecentLogs($context),
            ]);
        }
        $nonce = egmSecurityCreateCspNonce();
        egmCheckInRenderPage($context, egmSecurityGetCsrfToken(), $nonce);
    } catch (InvalidArgumentException $error) {
        egmCheckInJson(['status' => 'error', 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('EGM check-in failed: ' . $error->getMessage());
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            egmCheckInJson(['status' => 'error', 'message' => 'بررسی یا ثبت ورود ناموفق بود.'], 500);
        }
        denyPanelAccess(500, 'پنل کنترل مهمان موقتاً در دسترس نیست.', false);
    }
}
