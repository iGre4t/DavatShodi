<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/tab-permissions.php';
require_once __DIR__ . '/egm-instance-storage.php';
require_once __DIR__ . '/egm-export-filename.php';
require_once __DIR__ . '/egm-invite-card-store.php';
require_once __DIR__ . '/egm-groups.php';
require_once __DIR__ . '/egm-seat-map.php';

const EGM_CHECK_IN_ACTION = 'egm_period_check_in';

function egmCheckInTicketCardConfigured($card): bool
{
    return is_array($card) && !empty($card['imageData']) && is_array($card['qrRect'] ?? null)
        && is_array($card['textRect'] ?? null) && is_array($card['ticketCountRect'] ?? null);
}

function egmCheckInTicketDefinitions(array $settings): array
{
    $raw = is_array($settings['tickets'] ?? null) ? $settings['tickets'] : [];
    $tickets = [];
    $seen = [];
    foreach (array_slice($raw, 0, 20) as $index => $item) {
        if (!is_array($item)) continue;
        $id = strtolower(trim((string)($item['id'] ?? '')));
        $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?? '';
        $id = trim(substr($id, 0, 24), '-_');
        if ($id === '' || isset($seen[$id])) $id = 'ticket-' . ($index + 1);
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') $title = 'Ticket ' . ($index + 1);
        $tickets[] = ['id' => $id, 'title' => function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100),
            'dependsOn' => trim((string)($item['dependsOn'] ?? ''))];
        $seen[$id] = true;
    }
    if ($tickets === []) return [['id' => 'default', 'title' => 'Custom Number Ticket', 'dependsOn' => '']];
    $previousIds = [];
    foreach ($tickets as &$ticket) {
        if (!in_array($ticket['dependsOn'], $previousIds, true)) $ticket['dependsOn'] = '';
        $previousIds[] = $ticket['id'];
    }
    unset($ticket);
    return $tickets;
}

function egmCheckInPrintProfile(array $context, string $guestCode = ''): array
{
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    $printSettings = is_array($settings['printSettings'] ?? null) ? $settings['printSettings'] : [];
    $card = egmInstanceReadData($context['pdo'], (string)$context['code'], 'print_card', null);
    $card = egmInviteCardHydrateAssets($context['pdo'], (string)$context['code'], $card, 'print-card');
    $ticketSettings = is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : [];
    $ticketCard = egmInstanceReadData($context['pdo'], (string)$context['code'], 'custom_number_ticket', null);
    $ticketCard = egmInviteCardHydrateAssets($context['pdo'], (string)$context['code'], $ticketCard, 'custom-number-ticket');
    $tickets = array_map(static function (array $definition) use ($context, $ticketCard): array {
        $isDefault = $definition['id'] === 'default';
        $card = $isDefault ? $ticketCard : egmInstanceReadData($context['pdo'], (string)$context['code'], 'custom_number_ticket:' . $definition['id'], null);
        $card = $isDefault ? $card : egmInviteCardHydrateAssets($context['pdo'], (string)$context['code'], $card, 'cnt-' . $definition['id']);
        if (!$isDefault && !egmCheckInTicketCardConfigured($card)) $card = $ticketCard;
        return $definition + ['configured' => egmCheckInTicketCardConfigured($card), 'card' => $card];
    }, egmCheckInTicketDefinitions($ticketSettings));
    $profile = [
        'auto_print' => (bool)($printSettings['autoPrint'] ?? false),
        'double_print' => (bool)($printSettings['doublePrint'] ?? false),
        'configured' => is_array($card) && !empty($card['imageData']) && is_array($card['qrRect'] ?? null) && is_array($card['textRect'] ?? null),
        'card' => $card,
        'ticket_active' => (bool)($ticketSettings['active'] ?? false),
        'ticket_only' => (bool)($ticketSettings['ticketOnly'] ?? false),
        'ticket_configured' => egmCheckInTicketCardConfigured($ticketCard),
        'ticket_card' => $ticketCard,
        'tickets' => $tickets,
    ];
    return $guestCode !== '' ? egmGroupsApplyPrintPolicy($context, $profile, $guestCode) : $profile;
}

function egmCheckInAutomaticPrintProfile(array $context, string $guestCode): array
{
    $profile = egmCheckInPrintProfile($context, $guestCode);
    if (empty($profile['group_policy_applied']) && empty($profile['auto_print'])) {
        // For ungrouped guests Automatic Print is the master queue switch for
        // both the main card and all number-ticket outputs.
        $profile['ticket_active'] = false;
    }
    return $profile;
}

function egmCheckInRecordTicketNumber(array $context, string $guestCode, string $ticketNumber, string $ticketId = 'default'): array
{
    $guestCode = egmCheckInNormalizeGuestCode($guestCode);
    $ticketNumber = egmCheckInNormalizeDigits($ticketNumber);
    if ($guestCode === '') throw new InvalidArgumentException('شناسه مهمان معتبر نیست.');
    if ($ticketNumber === '' || strlen($ticketNumber) > 32) throw new InvalidArgumentException('Number of Ticket باید یک عدد معتبر باشد.');
    $ticketId = strtolower(trim($ticketId));
    $ticketId = preg_replace('/[^a-z0-9_-]+/', '-', $ticketId) ?? 'default';
    if ($ticketId === '') $ticketId = 'default';
    $printProfile = egmCheckInPrintProfile($context, $guestCode);
    $allowedTicketIds = array_map(static fn($ticket): string => is_array($ticket) ? (string)($ticket['id'] ?? '') : '', (array)($printProfile['tickets'] ?? []));
    if (empty($printProfile['ticket_active']) || !in_array($ticketId, $allowedTicketIds, true)) {
        throw new InvalidArgumentException('این Custom Number Ticket برای مهمان انتخاب‌شده فعال نیست.');
    }
    $period = is_array($context['period'] ?? null) ? $context['period'] : null;
    if (!is_array($period)) throw new InvalidArgumentException('بازه فعال برای ثبت Number of Ticket پیدا نشد.');
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $user = egmCheckInFindUser($pdo, $usersTable, $guestCode);
    if (!is_array($user)) throw new InvalidArgumentException('مهمان برای ثبت Number of Ticket پیدا نشد.');
    $pdo->beginTransaction();
    try {
    egmSeatMapLock($context);
    $current = $pdo->prepare("SELECT `ticket_numbers_json` FROM `{$periodsTable}` WHERE `user_id`=:user_id AND `period_code`=:period_code LIMIT 1 FOR UPDATE");
    $current->execute([':user_id'=>(int)$user['id'], ':period_code'=>egmCheckInPeriodCode($period)]);
    $numbers = json_decode((string)($current->fetchColumn() ?: ''), true);
    if (!is_array($numbers)) $numbers = [];
    $numbers[$ticketId] = $ticketNumber;
    $numbersJson = json_encode($numbers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $statement = $pdo->prepare(
        "UPDATE `{$periodsTable}` SET `number_of_ticket`=:ticket, `ticket_numbers_json`=:numbers, `ticket_number_recorded_at`=:recorded_at "
        . "WHERE `user_id`=:user_id AND `period_code`=:period_code "
        . "AND `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL"
    );
    $statement->execute([
        ':ticket' => $ticketNumber,
        ':numbers' => is_string($numbersJson) ? $numbersJson : '{}',
        ':recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s'),
        ':user_id' => (int)$user['id'],
        ':period_code' => egmCheckInPeriodCode($period),
    ]);
    if ($statement->rowCount() < 1) {
        $check = $pdo->prepare("SELECT `id` FROM `{$periodsTable}` WHERE `user_id`=:user_id AND `period_code`=:period_code AND `number_of_ticket`=:ticket LIMIT 1");
        $check->execute([':user_id'=>(int)$user['id'], ':period_code'=>egmCheckInPeriodCode($period), ':ticket'=>$ticketNumber]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('ورود موفق مهمان برای ثبت Number of Ticket پیدا نشد.');
    }
    $seatAssignment = egmSeatMapAssign($context, egmCheckInPeriodCode($period), (int)$user['id'], $numbers);
    $pdo->commit();
    return ['number_of_ticket' => $ticketNumber, 'ticket_id' => $ticketId, 'ticket_numbers' => $numbers, 'seat_assignment' => $seatAssignment];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function egmCheckInSeatRequirement(array $context, string $guestCode): array
{
    $period = is_array($context['period'] ?? null) ? $context['period'] : null;
    if (!is_array($period)) return ['required' => false, 'enabled' => false];
    $periodCode = egmCheckInPeriodCode($period);
    $map = egmSeatMapEffective($context, $periodCode);
    if (!$map['enabled']) return ['required' => false, 'enabled' => false];
    $code = egmCheckInNormalizeGuestCode($guestCode);
    $user = egmCheckInFindUser($context['pdo'], (string)$context['tables']['users'], $code);
    if (!is_array($user)) return ['required' => false, 'enabled' => false];
    $table = (string)$context['tables']['user_periods'];
    $find = $context['pdo']->prepare("SELECT `entered_date`,`ticket_numbers_json`,`seat_assignment_json`,`seat_mode` FROM `{$table}` WHERE `user_id`=:user_id AND `period_code`=:period_code LIMIT 1");
    $find->execute([':user_id' => (int)$user['id'], ':period_code' => $periodCode]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || trim((string)($row['entered_date'] ?? '')) !== '' || (string)($row['seat_mode'] ?? '') === 'free') return ['required' => false, 'enabled' => false];
    $numbers = json_decode((string)($row['ticket_numbers_json'] ?? ''), true);
    $hasQuantity = is_array($numbers) && trim((string)($numbers[$map['ticketId']] ?? '')) !== '';
    $assigned = json_decode((string)($row['seat_assignment_json'] ?? ''), true);
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    $definitions = egmCheckInTicketDefinitions((array)($settings['customNumberTicketSettings'] ?? []));
    $title = $map['ticketId'];
    foreach ($definitions as $ticket) if ($ticket['id'] === $map['ticketId']) $title = $ticket['title'];
    return [
        'enabled' => true,
        'required' => !$hasQuantity && empty($assigned['seats']),
        'ticket_id' => $map['ticketId'], 'ticket_title' => $title,
        'ticket_number' => $hasQuantity ? (string)$numbers[$map['ticketId']] : '',
        'guest_name' => trim((string)$user['first_name'] . ' ' . (string)$user['last_name']),
    ];
}

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
    if (egmCheckInBool($period['activationPending'] ?? false)) {
        return [
            'eligible' => false, 'scheduled' => $scheduled, 'reason' => 'inactive',
            'action' => null, 'quit_required' => $quitRequired,
            'quit_timeline_required' => $quitTimelineRequired,
        ];
    }
    $endedAt = trim((string)($period['endedAt'] ?? ($period['ended_at'] ?? '')));
    if ($endedAt !== '' || egmCheckInBool($period['ended'] ?? false)) {
        return [
            'eligible' => false,
            'scheduled' => $scheduled,
            'reason' => 'ended',
            'action' => null,
            'quit_required' => $quitRequired,
            'quit_timeline_required' => $quitTimelineRequired,
        ];
    }
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

/** @return array<string,mixed>|null */
function egmCheckInPreviousAttendance(
    array $context,
    int $userId,
    string $currentPeriodCode,
    DateTimeImmutable $now,
    array $user = []
): ?array {
    if ($userId < 1 || $currentPeriodCode === '') return null;
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    $statement = $context['pdo']->prepare(
        "SELECT `period_code`,`entered_date`,`entered_time`,`quit_date`,`quit_time`,`correct_presence`,`fake_presence`,`is_uninvited_guest` "
        . "FROM `{$userPeriodsTable}` WHERE `user_id`=:user_id AND `period_code`<>:period_code "
        . "AND `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL "
        . "AND TIMESTAMP(`entered_date`,`entered_time`)<=:now ORDER BY `entered_date` DESC,`entered_time` DESC,`id` DESC LIMIT 1 FOR UPDATE"
    );
    $statement->execute([
        ':user_id' => $userId,
        ':period_code' => $currentPeriodCode,
        ':now' => $now->format('Y-m-d H:i:s'),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;

    $periodCode = trim((string)($row['period_code'] ?? ''));
    $periodTitle = $periodCode;
    foreach ((array)($context['periods'] ?? []) as $period) {
        if (!is_array($period) || egmCheckInPeriodCode($period) !== $periodCode) continue;
        $periodTitle = trim((string)($period['title'] ?? '')) ?: $periodCode;
        break;
    }
    $enteredDate = trim((string)($row['entered_date'] ?? ''));
    $enteredTime = trim((string)($row['entered_time'] ?? ''));
    $wasWalkIn = (int)($row['is_uninvited_guest'] ?? 0) === 1
        || (int)($user['is_uninvited_guest'] ?? 0) === 1;
    $guestType = $wasWalkIn ? 'walk_in' : 'invited';
    $guestTypeLabel = $wasWalkIn ? 'مهمان ناخوانده' : 'مهمان دعوت‌شده';
    $dateLabel = $enteredDate !== '' ? egmExportShamsiDayMonth($enteredDate) : '';
    $message = 'این مهمان قبلاً در بازه «' . $periodTitle . '»'
        . ($dateLabel !== '' ? ' در ' . $dateLabel : '')
        . ($enteredTime !== '' ? ' ساعت ' . $enteredTime : '')
        . ' وارد شده است (' . $guestTypeLabel . ').';
    return [
        'period_code' => $periodCode,
        'period_title' => $periodTitle,
        'entered_date' => $enteredDate,
        'entered_date_label' => $dateLabel,
        'entered_time' => $enteredTime,
        'quit_date' => trim((string)($row['quit_date'] ?? '')),
        'quit_time' => trim((string)($row['quit_time'] ?? '')),
        'guest_type' => $guestType,
        'guest_type_label' => $guestTypeLabel,
        'correct_presence' => (int)($row['correct_presence'] ?? 0) === 1,
        'fake_presence' => (int)($row['fake_presence'] ?? 0) === 1,
        'message' => $message,
    ];
}

/** @return array<string,mixed> */
function egmCheckInAttachPreviousAttendance(array $response, ?array $previousAttendance): array
{
    if (!is_array($previousAttendance)) return $response;
    $response['previous_attendance'] = $previousAttendance;
    return $response;
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
        . '`last_control_message`=NULL, `last_control_at`=NULL, `number_of_ticket`=NULL, `ticket_number_recorded_at`=NULL, `seat_assignment_json`=NULL'
        . $where
    );
    $pdo->beginTransaction();
    try {
        egmSeatMapLock($context);
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

/** Release one guest's numbered seats and restore their invitation to the pending state. */
function egmCheckInResetGuestEntry(array $context, string $guestCode, string $periodCode, array $actor): array
{
    $guestCode = egmCheckInNormalizeGuestCode($guestCode);
    $periodCode = trim($periodCode);
    if ($guestCode === '' || $periodCode === '') throw new InvalidArgumentException('مهمان یا بازه معتبر نیست.');
    $knownPeriod = null;
    foreach ((array)($context['periods'] ?? []) as $period) {
        if (is_array($period) && egmCheckInPeriodCode($period) === $periodCode) { $knownPeriod = $period; break; }
    }
    if ($knownPeriod === null) throw new InvalidArgumentException('بازه انتخاب‌شده در این EGM وجود ندارد.');
    $pdo = $context['pdo'];
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $pdo->beginTransaction();
    try {
        egmSeatMapLock($context);
        $user = egmCheckInFindUser($pdo, $usersTable, $guestCode);
        if (!is_array($user)) throw new InvalidArgumentException('مهمان پیدا نشد.');
        $find = $pdo->prepare("SELECT `id`,`entered_date`,`entered_time`,`seat_assignment_json` FROM `{$periodsTable}` WHERE `user_id`=:user_id AND `period_code`=:period_code LIMIT 1 FOR UPDATE");
        $find->execute([':user_id' => (int)$user['id'], ':period_code' => $periodCode]);
        $current = $find->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current) || empty($current['entered_date']) || empty($current['entered_time'])) {
            throw new InvalidArgumentException('برای این مهمان ورود ثبت‌شده‌ای در بازه انتخاب‌شده وجود ندارد.');
        }
        $released = count((array)((json_decode((string)($current['seat_assignment_json'] ?? ''), true))['seats'] ?? []));
        $update = $pdo->prepare(
            "UPDATE `{$periodsTable}` SET `entered_date`=NULL,`entered_time`=NULL,`quit_date`=NULL,`quit_time`=NULL,"
            . "`correct_presence`=0,`fake_presence`=0,`attendance_state`='not_entered',"
            . "`last_control_condition`='entry_reset',`last_control_action`='manual',"
            . "`last_control_message`='ورود مهمان برای ثبت مجدد بازنشانی شد.',`last_control_at`=NOW(),"
            . "`number_of_ticket`=NULL,`ticket_numbers_json`=NULL,`ticket_number_recorded_at`=NULL,`seat_assignment_json`=NULL "
            . "WHERE `id`=:id AND `period_code`=:period_code AND `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL"
        );
        $update->execute([':id' => (int)$current['id'], ':period_code' => $periodCode]);
        if ($update->rowCount() !== 1) throw new RuntimeException('وضعیت ورود هم‌زمان تغییر کرده است؛ دوباره تلاش کنید.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    try {
        $operator = egmCheckInCleanText($actor['username'] ?? ($actor['code'] ?? ''), 191);
        egmCheckInWriteLog($context, $user, $guestCode, 'entry_reset',
            'ورود مهمان بازنشانی شد و صندلی‌های قبلی آزاد شدند.', $knownPeriod,
            new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')),
            ['attendance_action' => 'reset', 'released_seats' => $released, 'operator' => $operator]);
    } catch (Throwable $ignored) {
        // The attendance reset has committed; a separate log failure must not invite a second reset.
    }
    return ['message' => 'ورود مهمان بازنشانی شد و ' . $released . ' صندلی آزاد شد.',
        'released_seats' => $released, 'period_code' => $periodCode];
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

/** @return array{result:string,message:string,user_id:int,guest_number:string,entry_recorded:bool,attendance_state:string} */
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
    $seatMap = egmSeatMapEffective($context, $periodCode);
    $seatMode = (string)($input['seat_mode'] ?? 'assigned');
    if (!in_array($seatMode, ['assigned', 'free'], true)) throw new InvalidArgumentException('روش نشستن معتبر نیست.');
    $seatQuantity = egmCheckInNormalizeDigits($input['seat_ticket_count'] ?? '');
    if ($seatMap['enabled'] && $seatQuantity !== '' && ((int)$seatQuantity < 1 || (int)$seatQuantity > 500)) {
        throw new InvalidArgumentException('تعداد بلیت برای صندلی باید بین ۱ تا ۵۰۰ باشد.');
    }
    if ($seatMap['enabled'] && $seatMode === 'assigned' && ($seatQuantity === '' || (int)$seatQuantity < 1 || (int)$seatQuantity > 500)) {
        throw new InvalidArgumentException('تعداد بلیت برای صندلی باید بین ۱ تا ۵۰۰ باشد.');
    }
    if (!$seatMap['enabled']) $seatMode = 'assigned';
    $pdo->beginTransaction();
    try {
        egmSeatMapLock($context);
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
        $priorStatement = $pdo->prepare(
            "SELECT `entered_date`,`seat_mode`,`ticket_numbers_json` FROM `{$periodsTable}` "
            . "WHERE `user_id`=:user_id AND `period_code`=:period_code LIMIT 1 FOR UPDATE"
        );
        $priorStatement->execute([':user_id' => $userId, ':period_code' => $periodCode]);
        $priorInvitation = $priorStatement->fetch(PDO::FETCH_ASSOC);
        if ($seatMap['enabled'] && is_array($priorInvitation) && trim((string)($priorInvitation['entered_date'] ?? '')) !== '') {
            $priorNumbers = json_decode((string)($priorInvitation['ticket_numbers_json'] ?? ''), true);
            if (!is_array($priorNumbers)) $priorNumbers = [];
            if ((string)($priorInvitation['seat_mode'] ?? 'assigned') !== $seatMode
                || ($seatMode === 'assigned' && (string)($priorNumbers[$seatMap['ticketId']] ?? '') !== $seatQuantity)) {
                throw new InvalidArgumentException('روش نشستن یا تعداد بلیت مهمانی که قبلاً وارد شده قابل تغییر نیست.');
            }
        }
        $invitation = $pdo->prepare(
            "INSERT INTO `{$periodsTable}` (`user_id`, `period_code`, `status`, `invitation_source`, `invited_by`, `invited_at`, "
            . "`is_uninvited_guest`, `uninvited_registered_at`, `uninvited_registered_by`, `seat_mode`) "
            . "VALUES (:user_id, :period_code, 'invited', 'walk_in', :actor, :registered_at, 1, :registered_at_value, :actor_value, :seat_mode) "
            . "ON DUPLICATE KEY UPDATE `invitation_source` = 'walk_in', `invited_by` = VALUES(`invited_by`), "
            . "`invited_at` = COALESCE(`invited_at`, VALUES(`invited_at`)), `is_uninvited_guest` = 1, "
            . "`uninvited_registered_at` = VALUES(`uninvited_registered_at`), `uninvited_registered_by` = VALUES(`uninvited_registered_by`), `seat_mode`=VALUES(`seat_mode`)"
        );
        $registeredAt = $now->format('Y-m-d H:i:s');
        $invitation->execute([
            ':user_id' => $userId, ':period_code' => $periodCode, ':actor' => $actor,
            ':registered_at' => $registeredAt, ':registered_at_value' => $registeredAt, ':actor_value' => $actor,
            ':seat_mode' => $seatMode,
        ]);
        $invitationIdStatement = $pdo->prepare(
            "SELECT `id`, `entered_date`, `entered_time`, `quit_date`, `quit_time`, `seat_assignment_json`, `seat_mode`, `ticket_numbers_json` FROM `{$periodsTable}` "
            . "WHERE `user_id` = :user_id AND `period_code` = :period_code LIMIT 1 FOR UPDATE"
        );
        $invitationIdStatement->execute([':user_id' => $userId, ':period_code' => $periodCode]);
        $storedInvitation = $invitationIdStatement->fetch(PDO::FETCH_ASSOC);
        $invitationId = (int)($storedInvitation['id'] ?? 0);
        if (!is_array($storedInvitation) || $invitationId < 1) {
            throw new RuntimeException('The current-period guest row could not be loaded after registration.');
        }
        if ($seatMode === 'free' && trim((string)($storedInvitation['seat_assignment_json'] ?? '')) !== '') {
            throw new InvalidArgumentException('این مهمان قبلاً صندلی گرفته است؛ تغییر به نشستن آزاد مجاز نیست.');
        }
        $storedNumbers = json_decode((string)($storedInvitation['ticket_numbers_json'] ?? ''), true);
        if (!is_array($storedNumbers)) $storedNumbers = [];
        if ($seatMap['enabled'] && $seatQuantity !== '') {
            $numbers = $storedNumbers;
            $numbers[$seatMap['ticketId']] = $seatQuantity;
            $saveTicket = $pdo->prepare("UPDATE `{$periodsTable}` SET `ticket_numbers_json`=:numbers,`number_of_ticket`=:quantity WHERE `id`=:id");
            $saveTicket->execute([':numbers' => json_encode($numbers, JSON_THROW_ON_ERROR), ':quantity' => $seatQuantity, ':id' => $invitationId]);
        }
        $hasEntryDate = trim((string)($storedInvitation['entered_date'] ?? '')) !== '';
        $hasEntryTime = trim((string)($storedInvitation['entered_time'] ?? '')) !== '';
        $hasQuitDate = trim((string)($storedInvitation['quit_date'] ?? '')) !== '';
        $hasQuitTime = trim((string)($storedInvitation['quit_time'] ?? '')) !== '';
        if ($hasEntryDate !== $hasEntryTime || $hasQuitDate !== $hasQuitTime || (!$hasEntryDate && $hasQuitDate)) {
            throw new RuntimeException('The current-period attendance row is inconsistent and was not overwritten.');
        }

        // Confirming the walk-in dialog is the operator's admission decision.
        // Record entry in this transaction so dashboard totals update at once.
        // The conditional write preserves an entry created concurrently.
        $entryRecorded = false;
        if (!$hasEntryDate && !$hasQuitDate) {
            $entryStatement = $pdo->prepare(
                "UPDATE `{$periodsTable}` SET `entered_date` = :entered_date, `entered_time` = :entered_time, "
                . "`attendance_state` = 'entered' WHERE `id` = :id "
                . "AND (`entered_date` IS NULL OR TRIM(`entered_date`) = '') "
                . "AND (`entered_time` IS NULL OR TRIM(`entered_time`) = '') "
                . "AND (`quit_date` IS NULL OR TRIM(`quit_date`) = '') "
                . "AND (`quit_time` IS NULL OR TRIM(`quit_time`) = '')"
            );
            $entryStatement->execute([
                ':entered_date' => $now->format('Y-m-d'),
                ':entered_time' => $now->format('H:i:s'),
                ':id' => $invitationId,
            ]);
            $entryRecorded = $entryStatement->rowCount() === 1;
            if ($entryRecorded) {
                $hasEntryDate = true;
                $hasEntryTime = true;
                if ($seatMap['enabled'] && $seatMode === 'assigned') egmSeatMapAssign($context, $periodCode, $userId, $numbers, egmCheckInBool($input['allow_split_seats'] ?? false));
                if ($seatMap['enabled'] && $seatMode === 'free') egmSeatMapEnsureFreeCapacity($context, $periodCode, egmCheckInBool($input['allow_free_seat'] ?? false));
            }
        }
        $attendanceState = $hasQuitDate && $hasQuitTime
            ? 'quit_completed'
            : ($hasEntryDate && $hasEntryTime ? 'entered' : 'not_entered');
        $message = $entryRecorded
            ? "مهمان ناخوانده {$firstName} {$lastName} به بازه فعال اضافه شد و ورود او ثبت شد."
            : "مهمان ناخوانده {$firstName} {$lastName} در بازه فعال ثبت شده بود و سابقه حضور او تغییر نکرد.";
        $attendanceAction = $entryRecorded ? 'entry' : 'register';
        egmCheckInSavePeriodCondition(
            $context,
            $invitationId,
            'walk_in_registered',
            $attendanceAction,
            $message,
            $now,
            $attendanceState
        );
        $loggedUser = ['id' => $userId, 'work_id' => $workId];
        egmCheckInWriteLog($context, $loggedUser, $nationalId, 'walk_in_registered', $message, $period, $now, [
            'attendance_action' => $attendanceAction,
            'entry_recorded' => $entryRecorded,
            'outside_organization' => $outsideOrganization,
            'registered_by' => $actor,
        ]);
        $pdo->commit();
        return [
            'result' => 'walk_in_registered',
            'message' => $message,
            'user_id' => $userId,
            'guest_number' => (string)($guestNumbers[$userId] ?? ''),
            'entry_recorded' => $entryRecorded,
            'attendance_state' => $attendanceState,
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
        'attended_previous_period' => ['حضور در بازه قبلی', 'قبلاً در بازه', 'روز قبل'],
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
            'number_of_ticket' => $period['number_of_ticket'] ?? '',
            'ticket_numbers_json' => $period['ticket_numbers_json'] ?? '',
            'seat_assignment_json' => $period['seat_assignment_json'] ?? '',
            'seat_mode' => $period['seat_mode'] ?? 'assigned',
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
        $ticketNumbers = json_decode((string)($row['ticket_numbers_json'] ?? ''), true);
        if (!is_array($ticketNumbers)) $ticketNumbers = [];
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
            'number_of_ticket' => (string)($row['number_of_ticket'] ?? ''),
            'ticket_numbers' => (object)$ticketNumbers,
            'seat_assignment' => is_array(json_decode((string)($row['seat_assignment_json'] ?? ''), true)) ? json_decode((string)$row['seat_assignment_json'], true) : null,
            'seat_mode' => (string)($row['seat_mode'] ?? 'assigned'),
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

/**
 * A lightweight version for the core period roster. Unlike activity-log
 * versioning, this changes when Excel invitations are added/removed even when
 * no scan log is written.
 */
function egmCheckInStatsVersion(array $context): string
{
    $pdo = $context['pdo'] ?? null;
    $periodCode = trim((string)($context['period_code'] ?? ''));
    if (!$pdo instanceof PDO || $periodCode === '') return 'inactive';
    $usersTable = (string)$context['tables']['users'];
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    try {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS `row_count`, COALESCE(MAX(p.`id`),0) AS `max_id`, "
            . "COALESCE(SUM(p.`user_id`),0) AS `user_sum`, COALESCE(MAX(p.`updated_at`),'') AS `period_updated`, "
            . "COALESCE(MAX(u.`egm_updated_at`),'') AS `user_updated` "
            . "FROM `{$userPeriodsTable}` p JOIN `{$usersTable}` u ON u.`id`=p.`user_id` "
            . "WHERE p.`period_code`=:period_code"
        );
        $statement->execute([':period_code' => $periodCode]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return hash('sha256', implode('|', [
            $periodCode,
            (string)($row['row_count'] ?? '0'),
            (string)($row['max_id'] ?? '0'),
            (string)($row['user_sum'] ?? '0'),
            (string)($row['period_updated'] ?? ''),
            (string)($row['user_updated'] ?? ''),
        ]));
    } catch (Throwable $error) {
        error_log('EGM Guest Control statistics version unavailable: ' . $error->getMessage());
        return 'unavailable';
    }
}

function egmCheckInGenderGroup(string $gender): string
{
    $normalized = trim(strtr($gender, ['ي' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه']));
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
    if ($normalized === '') return 'unspecified';
    if (in_array($normalized, ['زن', 'خانم', 'مونث', 'مؤنث', 'female', 'woman', 'f'], true)
        || str_contains($normalized, 'خانم') || str_contains($normalized, 'زن')) {
        return 'female';
    }
    if (in_array($normalized, ['مرد', 'آقا', 'مذکر', 'male', 'man', 'm'], true)
        || str_contains($normalized, 'آقا') || str_contains($normalized, 'مرد')) {
        return 'male';
    }
    return 'unspecified';
}

/**
 * Older Guest Control registrations required a second scan before entry was
 * stored. Registration confirmation is now the admission decision, so repair
 * those existing rows from their recorded registration timestamp. This is
 * idempotent and never overwrites an entry, a quit, or a normal invitation.
 */
function egmCheckInRepairRegisteredWalkInEntries(array $context, string $periodCode): int
{
    $pdo = $context['pdo'] ?? null;
    $userPeriodsTable = trim((string)($context['tables']['user_periods'] ?? ''));
    $periodCode = trim($periodCode);
    if (!$pdo instanceof PDO || $userPeriodsTable === '' || $periodCode === '') return 0;

    $registeredAt = 'COALESCE(`uninvited_registered_at`, `last_control_at`, `invited_at`, `created_at`, NOW())';
    $statement = $pdo->prepare(
        "UPDATE `{$userPeriodsTable}` SET "
        . "`entered_date` = DATE({$registeredAt}), `entered_time` = TIME({$registeredAt}), "
        . "`attendance_state` = 'entered', `last_control_action` = 'entry' "
        . "WHERE `period_code` = :period_code "
        . "AND (COALESCE(`is_uninvited_guest`,0)=1 "
        . "OR LOWER(TRIM(COALESCE(`invitation_source`,'')))='walk_in') "
        . "AND (`uninvited_registered_at` IS NOT NULL "
        . "OR LOWER(TRIM(COALESCE(`last_control_condition`,'')))='walk_in_registered') "
        . "AND (`entered_date` IS NULL OR TRIM(`entered_date`)='') "
        . "AND (`entered_time` IS NULL OR TRIM(`entered_time`)='') "
        . "AND (`quit_date` IS NULL OR TRIM(`quit_date`)='') "
        . "AND (`quit_time` IS NULL OR TRIM(`quit_time`)='')"
    );
    $statement->execute([':period_code' => $periodCode]);
    return max(0, $statement->rowCount());
}

/**
 * Compact attendance totals for the active period. The roster denominator and
 * waiting count describe invited guests only, while entered/inside/quit include
 * everyone physically processed. The entry breakdown separates guests invited
 * to this period, guests invited only to another period, and guests with no
 * period invitation at all. "Entered" includes guests who later quit; "inside"
 * includes only entered guests without a quit record.
 *
 * @return array{active:bool,period_code:string,total:int,invited_total:int,invited_entered:int,other_period_total:int,other_period_entered:int,other_period_inside:int,other_period_quit:int,walk_in_total:int,walk_in_entered:int,walk_in_inside:int,walk_in_quit:int,overall_total:int,overall_entered:int,overall_inside:int,overall_quit:int,entered:int,waiting:int,inside:int,quit:int,entry_percent:float,gender:array<string,array{total:int,entered:int,waiting:int,inside:int,quit:int}>}
 */
function egmCheckInAddTicketQuantity(string $left, string $right): string
{
    $result = ''; $carry = 0; $i = strlen($left)-1; $j = strlen($right)-1;
    while ($i >= 0 || $j >= 0 || $carry > 0) {
        $sum = ($i >= 0 ? (int)$left[$i--] : 0) + ($j >= 0 ? (int)$right[$j--] : 0) + $carry;
        $result = (string)($sum % 10) . $result; $carry = intdiv($sum, 10);
    }
    return ltrim($result, '0') ?: '0';
}

/** @return list<array{id:string,title:string,sum:string}> */
function egmCheckInManualTicketTotalsFromRows(array $rows, array $tickets): array
{
    $titles = [];
    foreach ($tickets as $ticket) $titles[(string)$ticket['id']] = (string)$ticket['title'];
    $totals = [];
    foreach ($rows as $row) {
        $id = strtolower(trim((string)($row['ticket_id'] ?? '')));
        $digits = egmCheckInNormalizeDigits($row['quantity'] ?? '');
        if ($id === '' || $digits === '') continue;
        $title = $titles[$id] ?? trim((string)($row['ticket_title'] ?? ''));
        if ($title === '') $title = $id;
        if (!isset($totals[$id])) $totals[$id] = ['id'=>$id, 'title'=>$title, 'sum'=>'0'];
        $totals[$id]['sum'] = egmCheckInAddTicketQuantity($totals[$id]['sum'], $digits);
    }
    return array_values($totals);
}

function egmCheckInManualTicketTotals(array $context, string $periodCode): array
{
    if ($periodCode === '') return [];
    $table = (string)($context['tables']['manual_ticket_prints'] ?? '');
    if ($table === '') return [];
    $statement = $context['pdo']->prepare(
        "SELECT ticket_id,ticket_title,quantity FROM `{$table}` WHERE period_code=:period ORDER BY id"
    );
    $statement->execute([':period'=>$periodCode]);
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    $definitions = egmCheckInTicketDefinitions(is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : []);
    return egmCheckInManualTicketTotalsFromRows($statement->fetchAll(PDO::FETCH_ASSOC) ?: [], $definitions);
}

function egmCheckInRecordManualTicket(array $context, string $ticketId, string $quantity, string $clientToken, array $actor, string $seatMode = 'none', bool $allowSplit = false): array
{
    $period = is_array($context['period'] ?? null) ? $context['period'] : null;
    if (!is_array($period)) throw new InvalidArgumentException('بازه فعال برای ثبت بلیت دستی پیدا نشد.');
    $periodCode = egmCheckInPeriodCode($period);
    $quantity = egmCheckInNormalizeDigits($quantity);
    if ($quantity === '' || strlen($quantity) > 32) throw new InvalidArgumentException('عدد بلیت دستی معتبر نیست.');
    $ticketId = strtolower(trim($ticketId));
    $ticketId = preg_replace('/[^a-z0-9_-]+/', '-', $ticketId) ?? '';
    $clientToken = strtolower(trim($clientToken));
    if ($ticketId === '' || preg_match('/^[a-f0-9]{32}$/D', $clientToken) !== 1) {
        throw new InvalidArgumentException('درخواست چاپ دستی معتبر نیست.');
    }
    if (!in_array($seatMode, ['none', 'assigned'], true)) throw new InvalidArgumentException('روش صندلی بلیت دستی معتبر نیست.');
    $profile = egmCheckInPrintProfile($context);
    $ticket = null;
    foreach ((array)($profile['tickets'] ?? []) as $candidate) {
        if (is_array($candidate) && (string)($candidate['id'] ?? '') === $ticketId && !empty($candidate['configured'])) {
            $ticket = $candidate; break;
        }
    }
    if (!is_array($ticket)) throw new InvalidArgumentException('طرح بلیت شماره‌دار انتخاب‌شده آماده نیست.');
    $pdo = $context['pdo'];
    $table = (string)$context['tables']['manual_ticket_prints'];
    $pdo->beginTransaction();
    try {
        egmSeatMapLock($context);
        $verify = $pdo->prepare("SELECT period_code,ticket_id,quantity,seat_assignment_json FROM `{$table}` WHERE client_token=:token LIMIT 1 FOR UPDATE");
        $verify->execute([':token' => $clientToken]);
        $saved = $verify->fetch(PDO::FETCH_ASSOC);
        if (is_array($saved)) {
            $previousAssignment = json_decode((string)($saved['seat_assignment_json'] ?? ''), true);
            if ((string)$saved['period_code'] !== $periodCode || (string)$saved['ticket_id'] !== $ticketId
                || (string)$saved['quantity'] !== $quantity || ($seatMode === 'assigned') !== is_array($previousAssignment)) {
                throw new InvalidArgumentException('شناسه این چاپ قبلاً برای بلیت دیگری استفاده شده است.');
            }
            $assignment = is_array($previousAssignment) ? $previousAssignment : null;
        } else {
            $assignment = $seatMode === 'assigned'
                ? egmSeatMapReserveManual($context, $periodCode, $ticketId, (int)$quantity, $allowSplit) : null;
            $operator = trim((string)($actor['code'] ?? ($actor['username'] ?? '')));
            $statement = $pdo->prepare(
                "INSERT INTO `{$table}` (client_token,period_code,ticket_id,ticket_title,quantity,guest_name,qr_value,operator_code,seat_assignment_json) "
                . "VALUES (:token,:period,:ticket_id,:title,:quantity,'مهمان','000000000',:operator,:assignment)"
            );
            $statement->execute([
                ':token' => $clientToken, ':period' => $periodCode, ':ticket_id' => $ticketId,
                ':title' => (string)$ticket['title'], ':quantity' => $quantity,
                ':operator' => $operator !== '' ? $operator : null,
                ':assignment' => $assignment === null ? null : json_encode($assignment, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['ticket_id' => $ticketId, 'ticket_title' => (string)$ticket['title'], 'quantity' => $quantity,
        'seat_assignment' => $assignment, 'manual_ticket_totals' => egmCheckInManualTicketTotals($context, $periodCode)];
}

function egmCheckInTicketAndGroupStats(array $rows, array $tickets, array $groups): array
{
    $totals = []; $progress = [];
    foreach ($tickets as $ticket) $totals[(string)$ticket['id']] = ['id'=>(string)$ticket['id'], 'title'=>$ticket['title'], 'sum'=>'0'];
    foreach ($groups as $group) $progress[(string)$group['id']] = ['id'=>(string)$group['id'], 'title'=>$group['title'], 'total'=>0, 'entered'=>0];
    foreach ($rows as $row) {
        $entered = trim((string)($row['entered_date'] ?? '')) !== '' && trim((string)($row['entered_time'] ?? '')) !== '';
        $groupId = trim((string)($row['group_id'] ?? ''));
        if (isset($progress[$groupId])) { $progress[$groupId]['total']++; if ($entered) $progress[$groupId]['entered']++; }
        if (!$entered) continue;
        $numbers = json_decode((string)($row['ticket_numbers_json'] ?? ''), true);
        if (!is_array($numbers)) $numbers = [];
        if (!array_key_exists('default', $numbers) && trim((string)($row['number_of_ticket'] ?? '')) !== '') $numbers['default'] = $row['number_of_ticket'];
        foreach ($numbers as $id=>$number) {
            if (!isset($totals[$id]) || !is_scalar($number)) continue;
            $digits = egmCheckInNormalizeDigits($number);
            if ($digits !== '') $totals[$id]['sum'] = egmCheckInAddTicketQuantity($totals[$id]['sum'], $digits);
        }
    }
    return ['ticket_totals'=>array_values($totals), 'groups'=>array_values($progress)];
}

function egmCheckInDashboardStats(array $context): array
{
    $emptyGroup = static fn(): array => ['total' => 0, 'entered' => 0, 'waiting' => 0, 'inside' => 0, 'quit' => 0];
    $stats = [
        'active' => false,
        'ticket_totals' => [],
        'manual_ticket_totals' => [],
        'groups' => [],
        'period_code' => '',
        'total' => 0,
        'invited_total' => 0,
        'invited_entered' => 0,
        'other_period_total' => 0,
        'other_period_entered' => 0,
        'other_period_inside' => 0,
        'other_period_quit' => 0,
        'walk_in_total' => 0,
        'walk_in_entered' => 0,
        'walk_in_inside' => 0,
        'walk_in_quit' => 0,
        'overall_total' => 0,
        'overall_entered' => 0,
        'overall_inside' => 0,
        'overall_quit' => 0,
        'entered' => 0,
        'waiting' => 0,
        'inside' => 0,
        'quit' => 0,
        'entry_percent' => 0.0,
        'gender' => ['male' => $emptyGroup(), 'female' => $emptyGroup(), 'unspecified' => $emptyGroup()],
    ];
    $pdo = $context['pdo'] ?? null;
    $periodCode = trim((string)($context['period_code'] ?? ''));
    if (!$pdo instanceof PDO || $periodCode === '') return $stats;

    $usersTable = (string)$context['tables']['users'];
    $userPeriodsTable = (string)$context['tables']['user_periods'];
    try {
        egmCheckInRepairRegisteredWalkInEntries($context, $periodCode);
    } catch (Throwable $error) {
        // Keep statistics available even when a malformed legacy row cannot
        // be repaired. New registrations already store entry transactionally.
        error_log('EGM legacy walk-in entry repair unavailable: ' . $error->getMessage());
    }
    $hasEntry = "p.`entered_date` IS NOT NULL AND TRIM(p.`entered_date`) <> '' "
        . "AND p.`entered_time` IS NOT NULL AND TRIM(p.`entered_time`) <> ''";
    $hasQuit = "p.`quit_date` IS NOT NULL AND TRIM(p.`quit_date`) <> '' "
        . "AND p.`quit_time` IS NOT NULL AND TRIM(p.`quit_time`) <> ''";
    // The period row is authoritative. A user may have been a walk-in on one
    // day and a normal invitee on another, so the user-level historical flag
    // must not change the classification of every period they attend.
    $isCurrentWalkIn = "(COALESCE(p.`is_uninvited_guest`,0)=1 "
        . "OR LOWER(TRIM(COALESCE(p.`invitation_source`,'')))='walk_in')";
    $hasOtherPeriodInvitation = "EXISTS (SELECT 1 FROM `{$userPeriodsTable}` op "
        . "WHERE op.`user_id`=p.`user_id` AND op.`period_code`<>p.`period_code` "
        // The explicit source is authoritative. Some migrated databases copied
        // the historical user walk-in flag onto genuine period invitations.
        . "AND LOWER(TRIM(COALESCE(op.`invitation_source`,'')))<>'walk_in')";
    $isOtherPeriodGuest = "({$isCurrentWalkIn} AND {$hasOtherPeriodInvitation})";
    $isPureWalkIn = "({$isCurrentWalkIn} AND NOT ({$hasOtherPeriodInvitation}))";
    try {
        $statement = $pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(u.`gender`), ''), '') AS `gender`, COUNT(*) AS `total`, "
            . "SUM(CASE WHEN {$hasEntry} THEN 1 ELSE 0 END) AS `entered`, "
            . "SUM(CASE WHEN {$hasQuit} THEN 1 ELSE 0 END) AS `quit`, "
            . "SUM(CASE WHEN {$hasEntry} AND NOT ({$hasQuit}) THEN 1 ELSE 0 END) AS `inside`, "
            . "SUM(CASE WHEN {$isCurrentWalkIn} THEN 1 ELSE 0 END) AS `non_invited_current_total`, "
            . "SUM(CASE WHEN {$isCurrentWalkIn} AND {$hasEntry} THEN 1 ELSE 0 END) AS `non_invited_current_entered`, "
            . "SUM(CASE WHEN {$isOtherPeriodGuest} THEN 1 ELSE 0 END) AS `other_period_total`, "
            . "SUM(CASE WHEN {$isOtherPeriodGuest} AND {$hasEntry} THEN 1 ELSE 0 END) AS `other_period_entered`, "
            . "SUM(CASE WHEN {$isOtherPeriodGuest} AND {$hasEntry} AND NOT ({$hasQuit}) THEN 1 ELSE 0 END) AS `other_period_inside`, "
            . "SUM(CASE WHEN {$isOtherPeriodGuest} AND {$hasQuit} THEN 1 ELSE 0 END) AS `other_period_quit`, "
            . "SUM(CASE WHEN {$isPureWalkIn} THEN 1 ELSE 0 END) AS `walk_in_total`, "
            . "SUM(CASE WHEN {$isPureWalkIn} AND {$hasEntry} THEN 1 ELSE 0 END) AS `walk_in_entered`, "
            . "SUM(CASE WHEN {$isPureWalkIn} AND {$hasEntry} AND NOT ({$hasQuit}) THEN 1 ELSE 0 END) AS `walk_in_inside`, "
            . "SUM(CASE WHEN {$isPureWalkIn} AND {$hasQuit} THEN 1 ELSE 0 END) AS `walk_in_quit` "
            . "FROM `{$userPeriodsTable}` p JOIN `{$usersTable}` u ON u.`id` = p.`user_id` "
            . "WHERE p.`period_code` = :period_code GROUP BY COALESCE(NULLIF(TRIM(u.`gender`), ''), '')"
        );
        $statement->execute([':period_code' => $periodCode]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $group = egmCheckInGenderGroup((string)($row['gender'] ?? ''));
            $total = max(0, (int)($row['total'] ?? 0));
            $entered = min($total, max(0, (int)($row['entered'] ?? 0)));
            $quit = min($total, max(0, (int)($row['quit'] ?? 0)));
            $inside = min($entered, max(0, (int)($row['inside'] ?? 0)));
            $nonInvitedCurrentTotal = min($total, max(0, (int)($row['non_invited_current_total'] ?? 0)));
            $nonInvitedCurrentEntered = min($nonInvitedCurrentTotal, max(0, (int)($row['non_invited_current_entered'] ?? 0)));
            $otherPeriodTotal = min($nonInvitedCurrentTotal, max(0, (int)($row['other_period_total'] ?? 0)));
            $otherPeriodEntered = min($otherPeriodTotal, max(0, (int)($row['other_period_entered'] ?? 0)));
            $otherPeriodInside = min($otherPeriodEntered, max(0, (int)($row['other_period_inside'] ?? 0)));
            $otherPeriodQuit = min($otherPeriodTotal, max(0, (int)($row['other_period_quit'] ?? 0)));
            $walkInTotal = min($nonInvitedCurrentTotal, max(0, (int)($row['walk_in_total'] ?? 0)));
            $walkInEntered = min($walkInTotal, max(0, (int)($row['walk_in_entered'] ?? 0)));
            $walkInInside = min($walkInEntered, max(0, (int)($row['walk_in_inside'] ?? 0)));
            $walkInQuit = min($walkInTotal, max(0, (int)($row['walk_in_quit'] ?? 0)));
            $invitedTotal = max(0, $total - $nonInvitedCurrentTotal);
            $invitedEntered = min($invitedTotal, max(0, $entered - $nonInvitedCurrentEntered));
            $stats['gender'][$group]['total'] += $invitedTotal;
            $stats['gender'][$group]['entered'] += $entered;
            $stats['gender'][$group]['waiting'] += max(0, $invitedTotal - $invitedEntered);
            $stats['gender'][$group]['inside'] += $inside;
            $stats['gender'][$group]['quit'] += $quit;
            $stats['total'] += $invitedTotal;
            $stats['invited_entered'] += $invitedEntered;
            $stats['entered'] += $entered;
            $stats['inside'] += $inside;
            $stats['quit'] += $quit;
            $stats['walk_in_total'] += $walkInTotal;
            $stats['walk_in_entered'] += $walkInEntered;
            $stats['walk_in_inside'] += $walkInInside;
            $stats['walk_in_quit'] += $walkInQuit;
            $stats['other_period_total'] += $otherPeriodTotal;
            $stats['other_period_entered'] += $otherPeriodEntered;
            $stats['other_period_inside'] += $otherPeriodInside;
            $stats['other_period_quit'] += $otherPeriodQuit;
            $stats['overall_total'] += $total;
            $stats['overall_entered'] += $entered;
            $stats['overall_inside'] += $inside;
            $stats['overall_quit'] += $quit;
        }
    } catch (Throwable $error) {
        error_log('EGM Guest Control statistics unavailable: ' . $error->getMessage());
        return $stats;
    }
    $stats['active'] = true;
    $stats['period_code'] = $periodCode;
    $stats['waiting'] = max(0, $stats['total'] - $stats['invited_entered']);
    $stats['invited_total'] = $stats['total'];
    $stats['entry_percent'] = $stats['total'] > 0
        ? round(($stats['entered'] / $stats['total']) * 100, 1)
        : 0.0;
    $details = $pdo->prepare("SELECT group_id,entered_date,entered_time,number_of_ticket,ticket_numbers_json FROM `{$userPeriodsTable}` WHERE period_code=:period");
    $details->execute([':period'=>$periodCode]);
    $settings = egmInstanceReadData($pdo, (string)$context['code'], 'settings', []);
    $definitions = egmCheckInTicketDefinitions(is_array($settings['customNumberTicketSettings'] ?? null) ? $settings['customNumberTicketSettings'] : []);
    $stats = array_merge($stats, egmCheckInTicketAndGroupStats($details->fetchAll(PDO::FETCH_ASSOC), $definitions, egmGroupsRead($context)));
    $stats['manual_ticket_totals'] = egmCheckInManualTicketTotals($context, $periodCode);
    return $stats;
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
    array $sessionUser = [],
    ?string $seatTicketCount = null,
    bool $allowSplitSeats = false,
    bool $allowFreeSeat = false
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
        egmSeatMapLock($context);
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
        $columns = '`id`, `user_id`, `period_code`, `entered_date`, `entered_time`, `quit_date`, `quit_time`, `correct_presence`, `fake_presence`, `ticket_numbers_json`, `seat_assignment_json`, `seat_mode`';
        $selectedCode = egmCheckInPeriodCode($selectedPeriod);
        $previousAttendance = egmCheckInPreviousAttendance(
            $context,
            (int)$user['id'],
            $selectedCode,
            $now,
            $user
        );
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
            $result = is_array($previousAttendance)
                ? 'attended_previous_period'
                : ($otherCodes ? 'invited_other_period' : 'not_invited');
            $message = is_array($previousAttendance)
                ? (string)$previousAttendance['message'] . ' این مهمان به بازه فعال فعلی دعوت نشده و ورود جدیدی ثبت نشد.'
                : ($otherCodes
                    ? 'این مهمان به بازه فعال دعوت نشده و دعوت او مربوط به بازه دیگری است'
                        . ($periodTitles ? ': ' . implode('، ', $periodTitles) : '.')
                    : 'این مهمان به هیچ بازه‌ای در این رویداد دعوت نشده است.');
            egmCheckInWriteLog($context, $user, $submittedCode, $result, $message, $selectedPeriod, $now, [
                'invited_period_codes' => $otherCodes,
                'previous_attendance' => $previousAttendance,
            ]);
            $pdo->commit();
            return egmCheckInAttachPreviousAttendance(['result' => $result, 'message' => $message], $previousAttendance);
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
            return egmCheckInAttachPreviousAttendance(['result' => $result, 'message' => $message] + $forceOption, $previousAttendance);
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
                return egmCheckInAttachPreviousAttendance(['result' => $result, 'message' => $message] + $forceOption, $previousAttendance);
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
                return egmCheckInAttachPreviousAttendance(
                    ['result' => 'quit_without_entry', 'message' => $message]
                        + ['force_action' => 'entry', 'force_label' => 'Force Enter'],
                    $previousAttendance
                );
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
            return egmCheckInAttachPreviousAttendance(['result' => $duplicateResult, 'message' => $message] + $forceOption, $previousAttendance);
        }
        $entryTicketNumbers = json_decode((string)($invitation['ticket_numbers_json'] ?? ''), true);
        if (!is_array($entryTicketNumbers)) $entryTicketNumbers = [];
        if (!$isQuit) {
            $seatMap = egmSeatMapEffective($context, $selectedCode);
            $assigned = json_decode((string)($invitation['seat_assignment_json'] ?? ''), true);
            if ($seatMap['enabled'] && (string)($invitation['seat_mode'] ?? '') !== 'free' && empty($assigned['seats'])) {
                $suppliedQuantity = egmCheckInNormalizeDigits($seatTicketCount ?? '');
                $quantity = $suppliedQuantity !== '' ? $suppliedQuantity : (string)($entryTicketNumbers[$seatMap['ticketId']] ?? '');
                if ($quantity === '' || (int)$quantity < 1 || (int)$quantity > 500) {
                    throw new InvalidArgumentException('برای ورود، تعداد بلیت مبنای صندلی (۱ تا ۵۰۰) را وارد کنید.');
                }
                if ($suppliedQuantity !== '' || trim((string)($entryTicketNumbers[$seatMap['ticketId']] ?? '')) === '') {
                    $entryTicketNumbers[$seatMap['ticketId']] = $quantity;
                    $saveQuantity = $pdo->prepare("UPDATE `{$userPeriodsTable}` SET `ticket_numbers_json`=:numbers,`number_of_ticket`=:quantity WHERE `id`=:id");
                    $saveQuantity->execute([':numbers' => json_encode($entryTicketNumbers, JSON_THROW_ON_ERROR), ':quantity' => $quantity, ':id' => (int)$invitation['id']]);
                }
            }
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
        if (!$isQuit) {
            egmSeatMapAssign($context, $selectedCode, (int)$user['id'], $entryTicketNumbers, $allowSplitSeats, $allowFreeSeat);
            if ((string)($invitation['seat_mode'] ?? '') === 'free') egmSeatMapEnsureFreeCapacity($context, $selectedCode, $allowFreeSeat);
        }
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
        return egmCheckInAttachPreviousAttendance([
            'result' => $successResult,
            'message' => $message,
            'correct_presence' => !$isForced && $isQuit && (int)($invitation['fake_presence'] ?? 0) !== 1,
            'fake_presence' => $isForced || (int)($invitation['fake_presence'] ?? 0) === 1,
        ], $previousAttendance);
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
    $printCardRendererUrl = htmlspecialchars($projectWebBase . '/assets/egm-invite-card.js', ENT_QUOTES, 'UTF-8');
    $qrGeneratorUrl = htmlspecialchars($projectWebBase . '/modules/minor/QR%20Code%20Generator/generate.php', ENT_QUOTES, 'UTF-8');
    $logsVersion = htmlspecialchars(egmCheckInLogsVersion($context), ENT_QUOTES, 'UTF-8');
    $statsVersion = htmlspecialchars(egmCheckInStatsVersion($context), ENT_QUOTES, 'UTF-8');
    $logs = htmlspecialchars((string)json_encode(egmCheckInRecentLogs($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $dashboardStats = htmlspecialchars((string)json_encode(egmCheckInDashboardStats($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $walkInSeatMap = $hasActivePeriod ? egmSeatMapEffective($context, egmCheckInPeriodCode($period)) : ['enabled' => false];
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
    .guest-control-page .attendance-overview{margin-top:16px;padding:12px;border:1px solid #e1e6ed;border-radius:12px;background:linear-gradient(135deg,#fbfcfe 0%,#f7f9fc 100%)}
    .guest-control-page .attendance-stats-content{display:grid;grid-template-columns:minmax(230px,1.5fr) repeat(3,minmax(78px,.55fr)) minmax(235px,1.25fr);gap:8px;align-items:stretch}
    .guest-control-page .attendance-overview.is-inactive .attendance-stats-content{display:none}.guest-control-page .attendance-stats-empty{display:none;min-height:54px;align-items:center;justify-content:center;color:var(--muted,#6b7280);font-size:12px}.guest-control-page .attendance-overview.is-inactive .attendance-stats-empty{display:flex}
    .guest-control-page .entry-summary,.guest-control-page .stat-tile,.guest-control-page .gender-summary{min-width:0;border:1px solid #e5e9ef;border-radius:9px;background:rgba(255,255,255,.88)}
    .guest-control-page .entry-summary{padding:10px 12px}.guest-control-page .entry-summary-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px}.guest-control-page .entry-summary-label{color:#667085;font-size:11px;font-weight:700}.guest-control-page .entry-summary-value{display:flex;align-items:baseline;gap:5px;white-space:nowrap}.guest-control-page .entry-summary-value strong{color:#15243a;font-size:22px;line-height:1}.guest-control-page .entry-summary-value span{color:#667085;font-size:11px}.guest-control-page .entry-progress{height:5px;margin-top:10px;overflow:hidden;border-radius:999px;background:#e8edf3}.guest-control-page .entry-progress span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--primary,#e11d2e),#f06a76);transition:width .28s ease}.guest-control-page .entry-summary-foot{display:flex;justify-content:space-between;gap:8px;margin-top:6px;color:#667085;font-size:10px}.guest-control-page .entry-percent{text-align:left}.guest-control-page .entry-composition{display:flex;align-items:center;gap:3px 7px;flex-wrap:wrap}.guest-control-page .entry-composition-item{display:inline-flex;align-items:center;gap:3px;white-space:nowrap}.guest-control-page .entry-composition-item::before{width:5px;height:5px;border-radius:50%;background:#4f8edc;content:""}.guest-control-page .entry-composition-item.other-period::before{background:#7c3aed}.guest-control-page .entry-composition-item.walk-in::before{background:#d97706}.guest-control-page .entry-composition-item b{color:#344054;font-size:10px}
    .guest-control-page .stat-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:9px 7px;text-align:center}.guest-control-page .stat-tile span{color:#667085;font-size:10px;font-weight:700}.guest-control-page .stat-tile strong{margin-top:3px;color:#243246;font-size:18px;line-height:1.2}.guest-control-page .stat-tile small{margin-top:2px;color:#667085;font-size:9px}.guest-control-page .stat-tile.inside strong{color:#087443}.guest-control-page .stat-tile.quit strong{color:#5b21b6}.guest-control-page .stat-tile.waiting strong{color:#8a5a00}
    .guest-control-page .gender-summary{display:grid;align-content:center;gap:6px;padding:9px 11px}.guest-control-page .gender-title{color:#667085;font-size:10px;font-weight:700}.guest-control-page .gender-row{display:flex;align-items:center;gap:8px;font-size:11px}.guest-control-page .gender-row-label{flex:0 0 42px;color:#344054;font-weight:700}.guest-control-page .gender-row-track{flex:1 1 auto;height:4px;overflow:hidden;border-radius:999px;background:#e8edf3}.guest-control-page .gender-row-track span{display:block;width:0;height:100%;border-radius:inherit;background:#4f8edc;transition:width .28s ease}.guest-control-page .gender-row.female .gender-row-track span{background:#bf6ab1}.guest-control-page .gender-row.unspecified .gender-row-track span{background:#98a2b3}.guest-control-page .gender-row-value{flex:0 0 auto;color:#667085;font-size:10px;direction:rtl;white-space:nowrap}.guest-control-page .gender-row[hidden]{display:none}
    .guest-control-page .period-navigation{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:16px}
    .guest-control-page .period-neighbor{min-width:0;padding:11px 13px;border:1px solid var(--border,#e5e7eb);border-radius:10px;background:#fafbfc}.guest-control-page .period-neighbor-label{display:block;margin-bottom:3px;color:var(--muted,#6b7280);font-size:11px}.guest-control-page .period-neighbor strong{display:block;font-size:13px;overflow-wrap:anywhere}.guest-control-page .period-neighbor small{display:block;margin-top:3px;color:var(--muted,#6b7280);font-size:11px;direction:ltr;text-align:right;unicode-bidi:isolate}
    .guest-control-page .scan-grid{display:grid;grid-template-columns:minmax(280px,410px) minmax(300px,1fr);gap:24px;align-items:start;margin-top:20px;padding-top:18px;border-top:1px solid var(--border,#e5e7eb)}
    .guest-control-page .scanner,.guest-control-page .result-area{min-width:0}
    .guest-control-page .scanner-label,.guest-control-page .result-label{display:block;margin:0 0 7px;color:var(--text,#111);font-size:13px;font-weight:700}
    .guest-control-page .scanner input{display:block;width:100%;height:58px;padding:9px 14px;border:2px solid #cbd5e1;border-radius:10px;background:#fcfdff;color:#111827;text-align:center;direction:ltr;font-family:"OCR A Std","OCR A Extended","OCR-B","Lucida Console",Consolas,monospace;font-size:27px;font-weight:700;line-height:1;letter-spacing:.18em;outline:none;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
    .guest-control-page .scanner input:hover{border-color:#cbd0d8}
    .guest-control-page .scanner input:focus{border-color:var(--primary,#e11d2e);box-shadow:0 0 0 3px var(--primary-focus,rgba(225,29,46,.12))}
    .guest-control-page .scanner input.scanner-captured{animation:egm-scanner-captured .48s ease}
    @keyframes egm-scanner-captured{0%{background:#fff7ed;box-shadow:0 0 0 3px rgba(234,88,12,.22)}100%{background:#fcfdff;box-shadow:0 0 0 3px var(--primary-focus,rgba(225,29,46,.12))}}
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
    .guest-control-page .print-card-action{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:32px;padding:5px 10px;border:1px solid var(--primary,#1d96e1);border-radius:8px;background:var(--primary,#1d96e1);color:#fff;font:inherit;font-size:11px;font-weight:800;white-space:nowrap;cursor:pointer}.guest-control-page .print-card-action:hover{filter:brightness(.92)}.guest-control-page .print-card-action:disabled{opacity:.55;cursor:wait}
    .guest-control-page .ticket-number-dialog{border:0;border-radius:18px;padding:0;box-shadow:0 24px 70px rgba(16,24,40,.28);width:min(440px,calc(100vw - 32px));direction:rtl}.guest-control-page .ticket-number-dialog::backdrop{background:rgba(15,23,42,.55)}.guest-control-page .ticket-number-form{padding:24px;display:grid;gap:16px}.guest-control-page .ticket-number-form h3{margin:0;font-size:20px}.guest-control-page .ticket-number-form p{margin:0;color:#667085}.guest-control-page .ticket-number-form input{height:52px;border:2px solid var(--primary,#1d96e1);border-radius:10px;padding:0 14px;font:inherit;font-size:22px;text-align:center;direction:ltr}.guest-control-page .ticket-number-actions{display:flex;gap:10px}.guest-control-page .ticket-number-actions button{flex:1;min-height:42px;border-radius:9px;font:inherit;font-weight:800;cursor:pointer}.guest-control-page .ticket-number-submit{border:0;background:var(--primary,#1d96e1);color:#fff}.guest-control-page .ticket-number-cancel{border:1px solid #d0d5dd;background:#fff;color:#344054}
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
    .guest-control-page .scan-toast-stack{position:fixed;z-index:10020;top:18px;left:18px;display:grid;gap:9px;width:min(340px,calc(100vw - 24px));pointer-events:none;direction:rtl}
    .guest-control-page .scan-toast{position:relative;overflow:hidden;padding:12px 14px 13px;border:1px solid #fecdd3;border-inline-start:5px solid #df4052;border-radius:11px;background:#fff7f8;color:#881c2a;box-shadow:0 12px 32px rgba(16,24,40,.18);animation:egm-toast-enter .22s ease-out both}
    .guest-control-page .scan-toast.success{border-color:#b7ead0;border-inline-start-color:#18a566;background:#f1fbf6;color:#07673d}.guest-control-page .scan-toast.duplicate{border-color:#f6dda2;border-inline-start-color:#d59a12;background:#fffaf0;color:#765500}
    .guest-control-page .scan-toast.leaving{animation:egm-toast-leave .22s ease-in both}.guest-control-page .scan-toast-title{display:block;margin-bottom:3px;font-size:14px;font-weight:900}.guest-control-page .scan-toast-guest{display:block;color:#344054;font-size:12px;font-weight:800}.guest-control-page .scan-toast-message{display:-webkit-box;margin-top:4px;overflow:hidden;color:#667085;font-size:11px;line-height:1.65;-webkit-box-orient:vertical;-webkit-line-clamp:2}.guest-control-page .scan-toast-progress{position:absolute;inset-inline-start:0;bottom:0;width:100%;height:3px;background:currentColor;opacity:.35;transform-origin:left;animation:egm-toast-progress 4s linear forwards}
    @keyframes egm-toast-enter{from{opacity:0;transform:translateX(-18px) scale(.98)}to{opacity:1;transform:none}}@keyframes egm-toast-leave{to{opacity:0;transform:translateX(-15px) scale(.98)}}@keyframes egm-toast-progress{to{transform:scaleX(0)}}
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
    @media(max-width:900px){.guest-control-page .attendance-stats-content{grid-template-columns:minmax(220px,1.4fr) repeat(3,minmax(68px,.55fr))}.guest-control-page .gender-summary{grid-column:1/-1;grid-template-columns:auto repeat(3,minmax(0,1fr));align-items:center}.guest-control-page .gender-row{min-width:0}}
    @media(max-width:760px){.guest-control-page .guest-shell{width:min(100% - 20px,1180px);padding:14px 0 28px}.guest-control-page .guest-card{padding:16px 8px}.guest-control-page .hero-head{display:block}.guest-control-page .phase{margin-top:12px}.guest-control-page .attendance-overview{padding:8px}.guest-control-page .attendance-stats-content{grid-template-columns:repeat(2,minmax(0,1fr))}.guest-control-page .entry-summary{grid-column:1/-1}.guest-control-page .gender-summary{grid-column:1/-1;grid-template-columns:1fr;gap:7px}.guest-control-page .period-navigation{grid-template-columns:1fr}.guest-control-page .scan-grid{grid-template-columns:1fr;gap:16px}.guest-control-page .scanner input{height:54px;font-size:24px}.guest-control-page .card-head{align-items:flex-start}.guest-control-page .card-head .muted{display:none}.guest-control-page .log-toolbar{grid-template-columns:1fr}.guest-control-page .log-search-meta{padding:0;text-align:right}.guest-control-page th,.guest-control-page td{padding-inline:5px;font-size:10px}.guest-control-page th{font-size:9px}.guest-control-page .log-card-cell{padding:10px 5px}.guest-control-page .log-detail-line{align-items:flex-start;gap:4px 10px;flex-wrap:wrap}.guest-control-page .log-main-message{flex-basis:100%}.guest-control-page .detail-grid,.guest-control-page .walk-in-grid{grid-template-columns:1fr}.guest-control-page .detail-item.full,.guest-control-page .walk-in-field.full{grid-column:auto}}
  </style>
</head>
<body class="guest-control-page">
<main class="guest-shell" data-check-in-app data-csrf="<?= $csrfValue ?>" data-qr-endpoint="<?= $qrGeneratorUrl ?>" data-can-register-uninvited="<?= $canScan ? '1' : '0' ?>" data-logs-version="<?= $logsVersion ?>" data-stats-version="<?= $statsVersion ?>" data-initial-logs="<?= $logs ?>" data-initial-stats="<?= $dashboardStats ?>">
  <section class="hero guest-card">
    <div class="hero-head">
      <div>
        <p class="eyebrow">پنل کنترل مهمان</p>
        <h1><?= $name ?></h1>
        <?php if ($hasActivePeriod): ?><div class="event-meta"><span>بازه رویداد فعال: <b><?= $periodTitle ?></b></span></div><?php endif; ?>
      </div>
      <div class="phase phase--<?= htmlspecialchars($phaseClass, ENT_QUOTES, 'UTF-8') ?>">وضعیت فعلی: <?= $phaseLabel ?></div>
    </div>
    <div class="attendance-overview" data-attendance-stats aria-live="polite">
      <div class="attendance-stats-empty">آمار با فعال شدن بازه رویداد نمایش داده می‌شود.</div>
      <div class="attendance-stats-content">
        <div class="entry-summary">
          <div class="entry-summary-head"><span class="entry-summary-label">ورود مهمانان</span><span class="entry-summary-value"><strong data-stat="entered">۰</strong><span>از <b data-stat="total">۰</b> نفر دعوت‌شده</span></span></div>
          <div class="entry-progress" role="progressbar" aria-label="درصد ورود مهمانان نسبت به فهرست دعوت‌شدگان" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-stat-progress></span></div>
          <div class="entry-summary-foot"><span class="entry-composition" aria-label="تفکیک مهمانان واردشده"><span class="entry-composition-item" title="دعوت‌شده در همین بازه"><b data-stat="invited_entered">۰</b> دعوت همین بازه</span><span class="entry-composition-item other-period" title="دعوت‌شده در بازه‌ای دیگر و واردشده به این بازه"><b data-stat="other_period_entered">۰</b> دعوت بازه دیگر</span><span class="entry-composition-item walk-in" title="بدون دعوت در هیچ‌یک از بازه‌ها"><b data-stat="walk_in_entered">۰</b> بدون دعوت</span></span><span class="entry-percent" data-stat="entry_percent">۰٪ وارد شده‌اند</span></div>
        </div>
        <div class="stat-tile waiting"><span>مانده تا ورود</span><strong data-stat="waiting">۰</strong></div>
        <div class="stat-tile inside"><span>اکنون داخل</span><strong data-stat="inside">۰</strong></div>
        <div class="stat-tile quit"><span>خروج ثبت‌شده</span><strong data-stat="quit">۰</strong></div>
        <div class="gender-summary">
          <span class="gender-title">تفکیک ورود بر اساس جنسیت</span>
          <div class="gender-row male" data-gender="male"><span class="gender-row-label">مرد</span><span class="gender-row-track"><span></span></span><span class="gender-row-value">۰ از ۰</span></div>
          <div class="gender-row female" data-gender="female"><span class="gender-row-label">زن</span><span class="gender-row-track"><span></span></span><span class="gender-row-value">۰ از ۰</span></div>
          <div class="gender-row unspecified" data-gender="unspecified" hidden><span class="gender-row-label">نامشخص</span><span class="gender-row-track"><span></span></span><span class="gender-row-value">۰ از ۰</span></div>
        </div>
      </div>
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
          <?php if ($walkInSeatMap['enabled']): ?>
          <label class="walk-in-field"><span>روش نشستن</span><select name="seat_mode"><option value="assigned">تخصیص صندلی</option><option value="free">در صورت خالی بودن صندلی</option></select></label>
          <label class="walk-in-field"><span>تعداد بلیت مبنای صندلی *</span><input name="seat_ticket_count" type="number" min="1" max="500" value="1" required /></label>
          <?php endif; ?>
        </div>
        <p class="walk-in-status" data-walk-in-status aria-live="polite"></p>
        <div class="dialog-actions"><button type="button" class="dialog-action" data-walk-in-cancel>انصراف</button><button type="submit" class="dialog-action primary" data-walk-in-submit>ثبت مهمان ناخوانده</button></div>
      </form>
    </div>
  </dialog>
  <div class="scan-toast-stack" data-scan-toasts aria-live="polite" aria-atomic="false"></div>
</main>
<script nonce="<?= $nonce ?>" src="<?= $printCardRendererUrl ?>"></script>
<script nonce="<?= $nonce ?>">
(() => {
  const app=document.querySelector('[data-check-in-app]'); if(!app)return;
   const input=app.querySelector('[data-national-id]'),result=app.querySelector('[data-result]'),tbody=app.querySelector('[data-logs]'),logSearch=app.querySelector('[data-log-search]'),logSearchMeta=app.querySelector('[data-log-search-meta]'),statsRoot=app.querySelector('[data-attendance-stats]'),dialog=app.querySelector('[data-guest-dialog]'),dialogTitle=app.querySelector('[data-dialog-title]'),dialogContent=app.querySelector('[data-dialog-content]'),walkInDialog=app.querySelector('[data-walk-in-dialog]'),walkInForm=app.querySelector('[data-walk-in-form]'),walkInStatus=app.querySelector('[data-walk-in-status]'),toastStack=app.querySelector('[data-scan-toasts]');
  const canRegisterUninvited=app.dataset.canRegisterUninvited==='1';
  let printProfilePromise=null;
  const loadPrintProfile=(guestCode='')=>{if(printProfilePromise)return printProfilePromise;const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','print_profile');if(guestCode)url.searchParams.set('guest_code',guestCode);url.searchParams.set('_print_sync',String(Date.now()));printProfilePromise=fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}).then(async response=>{const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'دریافت تنظیمات چاپ ناموفق بود.');return data.print_profile||{}}).finally(()=>{printProfilePromise=null});return printProfilePromise};
  const seatText=(row)=>{if(row?.seat_mode==='free')return 'در صورت خالی بودن صندلی';const seats=Array.isArray(row?.seat_assignment?.seats)?row.seat_assignment.seats:[];if(!seats.length)return '';const groups=new Map();for(const seat of seats){const key=Number(seat.row);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(Number(seat.chair))}return Array.from(groups,([number,chairs])=>`ردیف ${number.toLocaleString('fa-IR')} صندلی ${chairs.map(chair=>chair.toLocaleString('fa-IR')).join('، ')}`).join('\n')};
  const printGuest=(row)=>({firstName:row.first_name,lastName:row.last_name,nationalId:row.national_id,workId:row.work_id,guestNumber:row.guest_number,phoneNumber:row.phone_number,deputy:row.deputy,generalDepartment:row.general_department,department:row.department,gender:row.gender,postalLevel:row.postal_level,score:row.total_score||'0',ticketCount:row.number_of_ticket||'',ticketTitle:row.ticket_title||'',seat:seatText(row),periodTitle:row.period_title||''});
  const printCardForRow=async(row,profile,copies=1)=>{if(!profile?.configured||!profile.card)throw new Error('طرح Print Card کامل نشده است. ابتدا آن را در تب Print Card ذخیره کنید.');if(!window.EGMInviteCardRenderer)throw new Error('ماژول ساخت کارت چاپی در مرورگر بارگذاری نشده است. صفحه را با Ctrl+F5 تازه‌سازی کنید.');const guest=printGuest(row);const output=await window.EGMInviteCardRenderer.render(profile.card,guest,guest.nationalId||guest.workId,{qrEndpoint:app.dataset.qrEndpoint||''});const imageUrl=URL.createObjectURL(output.blob),frame=document.createElement('iframe');frame.title='چاپ کارت مهمان';frame.style.cssText='position:fixed;left:-10000px;top:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';const pageCount=Math.max(1,Number(copies)||1),pages=Array.from({length:pageCount},()=>`<img src="${imageUrl}" alt="" />`).join('');await new Promise((resolve,reject)=>{const cleanup=()=>window.setTimeout(()=>{URL.revokeObjectURL(imageUrl);frame.remove()},10000);frame.addEventListener('load',()=>window.setTimeout(()=>{try{const printWindow=frame.contentWindow;if(!printWindow)throw new Error('پنجره چاپ در دسترس نیست.');printWindow.focus();printWindow.print();cleanup();resolve()}catch(error){cleanup();reject(error)}},180),{once:true});frame.srcdoc=`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:${output.height>output.width?"75mm "+Math.ceil(75*output.height/output.width)+"mm":"auto"};margin:0}html,body{margin:0;padding:0}img{display:block;width:100vw;height:auto;object-fit:contain;break-after:page;page-break-after:always}img:last-child{break-after:auto;page-break-after:auto}</style></head><body>${pages}</body></html>`;document.body.append(frame)});};
  const requestTicketNumber=(row,title)=>new Promise(resolve=>{const dialog=document.createElement('dialog');dialog.className='ticket-number-dialog';dialog.innerHTML=`<form class="ticket-number-form"><h3>${esc(title||'Custom Number Ticket')}</h3><p>شماره «${esc(title||'بلیت')}» برای ${esc(row.full_name||'مهمان')} را وارد کنید.</p><input name="ticket" inputmode="numeric" pattern="[0-9۰-۹٠-٩]+" maxlength="32" required autocomplete="off" aria-label="Number of Ticket - ${esc(title||'Ticket')}" /><div class="ticket-number-actions"><button type="submit" class="ticket-number-submit">ثبت و چاپ</button><button type="button" class="ticket-number-cancel">انصراف</button></div></form>`;document.body.append(dialog);const finish=value=>{if(dialog.open)dialog.close();dialog.remove();resolve(value)};dialog.querySelector('.ticket-number-cancel')?.addEventListener('click',()=>finish(null));dialog.addEventListener('cancel',event=>{event.preventDefault();finish(null)},{once:true});dialog.querySelector('form')?.addEventListener('submit',event=>{event.preventDefault();const field=dialog.querySelector('input');const value=normalizeTicket(field?.value||'');if(!value){field?.focus();return}finish(value)});dialog.showModal();window.setTimeout(()=>dialog.querySelector('input')?.focus(),30)});
  const recordTicketNumber=async(row,ticket,ticketNumber)=>{const response=await fetch(window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'record_ticket_number',guest_code:row.national_id||row.work_id,ticket_id:ticket.id,number_of_ticket:ticketNumber,csrf:app.dataset.csrf||''})});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'ثبت Number of Ticket ناموفق بود.');row.number_of_ticket=String(data.number_of_ticket||ticketNumber);row.ticket_title=String(ticket.title||'');if(data.seat_assignment)row.seat_assignment=data.seat_assignment;return row};
  const seatCountForEntry=async(guestCode)=>{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','seat_requirement');url.searchParams.set('guest_code',guestCode);const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'بررسی صندلی ناموفق بود.');if(!data.required)return '';const count=await requestTicketNumber({full_name:data.guest_name||'مهمان'},data.ticket_title||'بلیت مبنای صندلی');if(count!==null&&(!Number.isSafeInteger(Number(count))||Number(count)<1||Number(count)>500))throw new Error('تعداد بلیت مبنای صندلی باید بین ۱ تا ۵۰۰ باشد.');return count};
  const postWithSeatApproval=async(payload)=>{
    for(let attempt=0;attempt<3;attempt++){
      const response=await fetch(window.location.href,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
      const data=await response.json().catch(()=>({}));
      if(data.code==='seat_split_confirmation_required'&&!payload.allow_split_seats){
        const groups=new Map();
        for(const seat of Array.isArray(data.suggested_seats)?data.suggested_seats:[]){const row=Number(seat.row);if(!groups.has(row))groups.set(row,[]);groups.get(row).push(Number(seat.chair))}
        const details=Array.from(groups,([row,chairs])=>`ردیف ${row}: ${chairs.join('، ')}`).join('\n');
        if(!window.confirm(`${data.message||'صندلی‌های نزدیک اما جدا پیشنهاد شده‌اند.'}\n${details}\nآیا این صندلی‌ها را تأیید می‌کنید؟`))throw new Error('ورود بدون تخصیص صندلی لغو شد.');
        payload={...payload,allow_split_seats:true};
        continue;
      }
      if(data.code==='seat_capacity_insufficient'&&!payload.allow_free_seat){
        if(!window.confirm(`${data.message||'ظرفیت سالن کافی نیست.'}\nبرای این مهمان صندلی رزرو نمی‌شود. بلیت «در صورت خالی بودن صندلی» صادر و ورود ثبت شود؟`))throw new Error('ورود به‌دلیل کمبود ظرفیت لغو شد.');
        payload={...payload,allow_free_seat:true};
        if(payload.action==='register_uninvited')payload.seat_mode='free';
        continue;
      }
      if(!response.ok||data.status!=='ok')throw new Error(data.message||'ثبت ورود ناموفق بود.');
      return data;
    }
    throw new Error('تخصیص صندلی پس از تأیید ناموفق بود.');
  };
  const autoPrintEntry=async(data,guestCode)=>{if(!['success','force_entry_success','walk_in_registered'].includes(String(data?.result||''))||(data?.result==='walk_in_registered'&&!data?.entry_recorded))return;try{const profile=await loadPrintProfile();const rows=Array.isArray(data.logs)?data.logs:[];const row=rows.find(item=>['success','force_entry_success','walk_in_registered'].includes(String(item.status||''))&&(normalize(item.national_id)===guestCode||normalize(item.work_id)===guestCode))||rows.find(item=>['success','force_entry_success','walk_in_registered'].includes(String(item.status||'')));if(!row)throw new Error('اطلاعات مهمان واردشده برای چاپ پیدا نشد.');if(profile.ticket_active){if(!profile.ticket_only&&(!profile.configured||!profile.card))throw new Error('طرح Print Card کامل نشده است.');const tickets=Array.isArray(profile.tickets)&&profile.tickets.length?profile.tickets:[{id:'default',title:'Custom Number Ticket',configured:profile.ticket_configured,card:profile.ticket_card}];if(tickets.some(ticket=>!ticket.configured||!ticket.card))throw new Error('طرح Custom Number Ticket کامل نشده یا متغیر [ticketcount] در آن قرار نگرفته است.');if(!profile.ticket_only)await printCardForRow(row,profile,profile.double_print?2:1);for(const ticket of tickets){const savedNumbers=row.ticket_numbers&&typeof row.ticket_numbers==='object'?row.ticket_numbers:{};const ticketNumber=String(savedNumbers[ticket.id]||'')||await requestTicketNumber(row,ticket.title);if(ticketNumber===null){result.className='result duplicate';result.textContent=`ورود ثبت شد؛ چاپ ${ticket.title||'بلیت'} لغو شد.`;return}await recordTicketNumber(row,ticket,ticketNumber);await printCardForRow(row,{configured:ticket.configured,card:ticket.card},1)}result.className='result success';result.textContent=`${tickets.length.toLocaleString('fa-IR')} بلیت شماره‌دار برای چاپ ارسال شد.`;return}if(!profile.auto_print)return;await printCardForRow(row,profile,profile.double_print?2:1);result.className='result success';result.textContent=profile.double_print?'ورود ثبت شد و دو نسخه برای چاپ ارسال شد.':'ورود ثبت شد و پنجره چاپ باز شد.'}catch(error){console.error('Receipt print failed:',error);result.className='result error';result.textContent=`ورود ثبت شد، اما فرآیند رسید کامل نشد: ${error instanceof Error?error.message:'خطای ناشناخته چاپ'}`}};
  const SCANNER_MAX_KEY_GAP_MS=50,SCANNER_MIN_FAST_GAPS=3,SCANNER_COMPLETION_DELAY_MS=90;
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const normalize=(v)=>String(v??'').replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-0x06f0)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-0x0660)).replace(/\D/g,'').slice(0,10);
  const normalizeTicket=(v)=>String(v??'').replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-0x06f0)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-0x0660)).replace(/\D/g,'').slice(0,32);
  const keyDigit=(key)=>/^[0-9۰-۹٠-٩]$/.test(String(key??''))?normalize(key):'';
  const statCount=(value)=>Math.max(0,Number.parseInt(String(value??0),10)||0);
  const faCount=(value)=>statCount(value).toLocaleString('fa-IR');
  const renderStats=(value)=>{if(!statsRoot)return;const stats=value&&typeof value==='object'?value:{};const active=stats.active===true;statsRoot.classList.toggle('is-inactive',!active);if(!active)return;for(const key of ['total','invited_total','invited_entered','other_period_entered','walk_in_entered','entered','waiting','inside','quit']){statsRoot.querySelectorAll(`[data-stat="${key}"]`).forEach(target=>{target.textContent=faCount(stats[key])})}const percent=Math.max(0,Math.min(100,Number(stats.entry_percent)||0)),progress=statsRoot.querySelector('[data-stat-progress]'),progressBox=progress?.parentElement,percentLabel=statsRoot.querySelector('[data-stat="entry_percent"]');if(progress)progress.style.width=`${percent}%`;if(progressBox)progressBox.setAttribute('aria-valuenow',String(percent));if(percentLabel)percentLabel.textContent=`${percent.toLocaleString('fa-IR',{maximumFractionDigits:1})}٪ وارد شده‌اند`;for(const group of ['male','female','unspecified']){const row=statsRoot.querySelector(`[data-gender="${group}"]`),groupStats=stats.gender?.[group]||{},total=statCount(groupStats.total),entered=statCount(groupStats.entered),fill=row?.querySelector('.gender-row-track span'),labelValue=row?.querySelector('.gender-row-value');if(!row)continue;row.hidden=group==='unspecified'&&total===0&&entered===0;if(fill)fill.style.width=`${total>0?Math.min(100,(entered/total)*100):0}%`;if(labelValue)labelValue.textContent=`${faCount(entered)} از ${faCount(total)}`}};
  const label=(s)=>s==='success'?'ورود موفق':s==='quit_success'?'خروج موفق':s==='force_entry_success'?'ورود اجباری':s==='force_quit_success'?'خروج اجباری':s==='walk_in_registered'?'مهمان ناخوانده ثبت شد':s==='duplicate'?'قبلاً وارد شده':s==='quit_duplicate'?'قبلاً خارج شده':s==='attended_previous_period'?'حضور در بازه قبلی':s==='quit_without_entry'?'ورود ثبت نشده':s==='minimum_stay'?'حداقل مدت حضور کامل نشده':s==='entry_closed_quit_wave'?'ورود به‌دلیل موج خروج بسته است':s==='invalid_attendance_record'?'سابقه حضور ناسازگار':s==='invited_other_period'?'دعوت در بازه دیگر':s==='user_inactive'?'مهمان غیرفعال':s==='no_active_period'?'بدون بازه فعال':s==='multiple_active_periods'?'هم‌پوشانی بازه‌ها':s==='not_found'?'یافت نشد':s==='not_invited'?'دعوت نشده':s==='upcoming'?'در انتظار شروع':s==='immune_time'?'زمان ایمن':s==='ended'?'پایان‌یافته':s==='inactive'?'غیرفعال':s==='invalid_schedule'?'زمان‌بندی نامعتبر':'ناموفق';
  const cls=(s)=>(s==='success'||s==='quit_success'||s==='force_entry_success'||s==='force_quit_success'||s==='walk_in_registered')?'success':(s==='duplicate'||s==='quit_duplicate'||s==='attended_previous_period')?'duplicate':'error';
  const SCAN_TOAST_DURATION_MS=4000;
  let scanAudioContext=null;
  const unlockScanAudio=()=>{const AudioContextClass=window.AudioContext||window.webkitAudioContext;if(!AudioContextClass)return null;try{if(!scanAudioContext)scanAudioContext=new AudioContextClass();if(scanAudioContext.state==='suspended')void scanAudioContext.resume().catch(()=>{});return scanAudioContext}catch{return null}};
  const playScanSound=(successful)=>{const context=unlockScanAudio();if(!context)return;const play=()=>{const start=context.currentTime+.025;const notes=successful?[{frequency:620,offset:0,duration:.11},{frequency:880,offset:.115,duration:.14}]:[{frequency:270,offset:0,duration:.14},{frequency:175,offset:.13,duration:.2}];notes.forEach(note=>{const oscillator=context.createOscillator(),gain=context.createGain(),noteStart=start+note.offset;oscillator.type=successful?'sine':'triangle';oscillator.frequency.setValueAtTime(note.frequency,noteStart);gain.gain.setValueAtTime(.0001,noteStart);gain.gain.exponentialRampToValueAtTime(successful ? .045 : .035,noteStart+.018);gain.gain.exponentialRampToValueAtTime(.0001,noteStart+note.duration);oscillator.connect(gain);gain.connect(context.destination);oscillator.start(noteStart);oscillator.stop(noteStart+note.duration+.025)})};if(context.state==='running')play();else void context.resume().then(play).catch(()=>{})};
  const findScanLog=(items,guestCode,status)=>{const rows=Array.isArray(items)?items:[];return rows.find(row=>String(row.status||'')===status&&(normalize(row.national_id||'')===guestCode||normalize(row.work_id||'')===guestCode))||rows.find(row=>String(row.status||'')===status)||null};
  const showScanFeedback=(data,guestCode,forcedTone='')=>{if(!toastStack)return;const status=String(data?.result||'request_error'),tone=forcedTone||cls(status),row=findScanLog(data?.logs,guestCode,status),guest=String(row?.full_name||'').trim()||('شناسه '+guestCode),message=String(data?.message||'نتیجه اسکن دریافت شد.').trim();const toast=document.createElement('section'),title=document.createElement('strong'),guestLine=document.createElement('span'),messageLine=document.createElement('span'),progress=document.createElement('span');toast.className='scan-toast '+tone;toast.setAttribute('role',tone==='success'?'status':'alert');title.className='scan-toast-title';title.textContent=label(status);guestLine.className='scan-toast-guest';guestLine.textContent=guest;messageLine.className='scan-toast-message';messageLine.textContent=message;progress.className='scan-toast-progress';toast.append(title,guestLine,messageLine,progress);toastStack.prepend(toast);while(toastStack.children.length>4)toastStack.lastElementChild?.remove();window.setTimeout(()=>toast.classList.add('leaving'),SCAN_TOAST_DURATION_MS-220);window.setTimeout(()=>toast.remove(),SCAN_TOAST_DURATION_MS);playScanSound(tone==='success')};
  const showPreviousAttendanceAlert=(previous)=>{if(!toastStack||!previous||typeof previous!=='object')return;const toast=document.createElement('section'),title=document.createElement('strong'),periodLine=document.createElement('span'),messageLine=document.createElement('span'),progress=document.createElement('span');toast.className='scan-toast duplicate previous-attendance-alert';toast.setAttribute('role','alert');title.className='scan-toast-title';title.textContent='هشدار حضور در بازه قبلی';periodLine.className='scan-toast-guest';periodLine.textContent=`${String(previous.period_title||previous.period_code||'بازه قبلی')} — ${String(previous.guest_type_label||'مهمان')}`;messageLine.className='scan-toast-message';messageLine.textContent=String(previous.message||'برای این مهمان سابقه ورود در بازه قبلی وجود دارد.');progress.className='scan-toast-progress';toast.append(title,periodLine,messageLine,progress);toastStack.prepend(toast);while(toastStack.children.length>4)toastStack.lastElementChild?.remove();window.setTimeout(()=>toast.classList.add('leaving'),SCAN_TOAST_DURATION_MS+1780);window.setTimeout(()=>toast.remove(),SCAN_TOAST_DURATION_MS+2000)};
  const operationLabel=(row)=>row.attendance_action==='quit'?'خروج':row.attendance_action==='entry'?'ورود':row.attendance_action==='register'?'ثبت مهمان':'بررسی';
  const attendanceStateLabel=(state)=>state==='quit_completed'?'خروج ثبت شده':state==='entered'?'وارد شده':state==='invalid_quit_without_entry'?'خروج ناسازگار بدون ورود':'هنوز وارد نشده';
  const dateTime=(date,time,fallback='')=>[date,time].filter(Boolean).join(' ')||fallback||'—';
  const walkInStatuses=new Set(['not_found','not_invited','invited_other_period','attended_previous_period']);
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
      if(['success','force_entry_success'].includes(String(row.status||'')))actions.push(`<button type="button" class="print-card-action" data-print-log="${index}" aria-label="چاپ مجدد کارت مهمان">چاپ مجدد</button>`);
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
  const openDetail=(row)=>{if(!row||!dialog||!dialogTitle||!dialogContent)return;dialogTitle.textContent=row.full_name||'جزئیات مهمان';dialogContent.innerHTML=`<section class="detail-section"><h4 class="detail-section-title">مشخصات مهمان</h4><div class="detail-grid">${detailItem('نوع مهمان',row.is_uninvited_guest?'مهمان ناخوانده':'دعوت‌شده')}${detailItem('کد ملی',row.national_id,'code')}${detailItem('کد پرسنلی',row.work_id,'code')}${detailItem('شماره همراه',row.phone_number,'code')}${detailItem('شماره مهمان',row.guest_number,'code')}${detailItem('معاونت',row.deputy)}${detailItem('اداره کل',row.general_department)}${detailItem('اداره',row.department)}${detailItem('جنسیت / سطح پستی',[row.gender,row.postal_level].filter(Boolean).join(' / '))}</div></section><section class="detail-section"><h4 class="detail-section-title">اطلاعات حضور در بازه</h4><div class="detail-grid">${detailItem('بازه',row.period_title)}${detailItem('Number of Ticket',row.number_of_ticket,'code')}${detailItem('صندلی',seatText(row))}${detailItem('وضعیت فعلی حضور',attendanceStateLabel(row.attendance_state))}${detailItem('Correct Presence',presenceMark(row.correct_presence))}${detailItem('Fake Presence',presenceMark(row.fake_presence))}${detailItem('وضعیت آخرین بررسی',label(row.status))}${detailItem('زمان ورود',dateTime(row.entered_date,row.entered_time),'code')}${detailItem('زمان خروج',dateTime(row.quit_date,row.quit_time),'code')}${detailItem(`زمان عملیات ${operationLabel(row)}`,dateTime(row.operation_date,row.operation_time,row.attempted_at),'code')}${detailItem('زمان بررسی',row.attempted_at,'code')}</div></section><section class="detail-section"><h4 class="detail-section-title">نتیجه ثبت‌شده</h4><div class="detail-message">${esc(row.message||'—')}</div></section>`;if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','')};
  const walkInControl=(name)=>walkInForm?.elements.namedItem(name)||null;
  const fillWalkInSelect=(name,values,current='')=>{const select=walkInControl(name);if(!(select instanceof HTMLSelectElement))return;select.replaceChildren();const blank=document.createElement('option');blank.value='';blank.textContent='انتخاب کنید';select.append(blank);const unique=new Set(Array.isArray(values)?values.map(value=>String(value||'').trim()).filter(Boolean):[]);if(current)unique.add(String(current));for(const value of unique){const option=document.createElement('option');option.value=value;option.textContent=value;select.append(option)}select.value=String(current||'')};
  let walkInOptionsPromise=null;
  const loadWalkInOptions=()=>{if(walkInOptionsPromise)return walkInOptionsPromise;const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','uninvited_options');walkInOptionsPromise=fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async response=>{const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'دریافت گزینه‌های سازمانی ناموفق بود.');return data.options||{}}).catch(error=>{walkInOptionsPromise=null;throw error});return walkInOptionsPromise};
  const syncOutsideOrganization=()=>{const outside=walkInControl('outside_organization');const checked=outside instanceof HTMLInputElement&&outside.checked;walkInForm?.querySelectorAll('[data-organization-field]').forEach(select=>{select.disabled=checked;select.required=!checked;if(checked)select.value=''});const workId=walkInControl('work_id');if(workId instanceof HTMLInputElement)workId.required=!checked};
  const syncWalkInSeatMode=()=>{const mode=walkInControl('seat_mode'),count=walkInControl('seat_ticket_count');if(!(mode instanceof HTMLSelectElement)||!(count instanceof HTMLInputElement))return;const assigned=mode.value==='assigned';count.disabled=!assigned;count.required=assigned;count.closest('label')?.toggleAttribute('hidden',!assigned)};
  const closeWalkIn=()=>{if(!walkInDialog)return;if(typeof walkInDialog.close==='function'&&walkInDialog.open)walkInDialog.close();else walkInDialog.removeAttribute('open')};
  const openWalkIn=async(row)=>{if(!walkInDialog||!walkInForm||!row)return;walkInForm.reset();syncWalkInSeatMode();if(walkInStatus){walkInStatus.className='walk-in-status';walkInStatus.textContent='در حال دریافت گزینه‌های سازمانی...'}const values={first_name:String(row.first_name||''),last_name:String(row.last_name||''),national_id:String(row.national_id||''),work_id:String(row.work_id||''),phone_number:String(row.phone_number||'')};for(const [name,value] of Object.entries(values)){const control=walkInControl(name);if(control instanceof HTMLInputElement)control.value=value}const outside=walkInControl('outside_organization');if(outside instanceof HTMLInputElement)outside.checked=Boolean(row.outside_organization);syncOutsideOrganization();if(typeof walkInDialog.showModal==='function')walkInDialog.showModal();else walkInDialog.setAttribute('open','');try{const options=await loadWalkInOptions();fillWalkInSelect('deputy',options.deputy,row.deputy||'');fillWalkInSelect('general_department',options.general_department,row.general_department||'');fillWalkInSelect('department',options.department,row.department||'');fillWalkInSelect('gender',options.gender?.length?options.gender:['مرد','زن'],row.gender||'');fillWalkInSelect('postal_level',options.postal_level,row.postal_level||'');syncOutsideOrganization();if(walkInStatus)walkInStatus.textContent='اطلاعات را تکمیل و ثبت کنید.'}catch(error){if(walkInStatus){walkInStatus.className='walk-in-status error';walkInStatus.textContent=error instanceof Error?error.message:'دریافت گزینه‌ها ناموفق بود.'}}};
  let logs=[],initialStats={},lastLogsVersion=String(app.dataset.logsVersion||''),lastStatsVersion=String(app.dataset.statsVersion||''); try{logs=JSON.parse(app.dataset.initialLogs||'[]')}catch{} try{initialStats=JSON.parse(app.dataset.initialStats||'{}')}catch{} render(logs);renderStats(initialStats);
  let searchTimer=0,searchRequest=0,logRefreshInFlight=false;
  const searchLogs=async({silent=false}={})=>{if(logRefreshInFlight&&silent)return;const requestId=++searchRequest;const query=String(logSearch?.value||'').trim();if(!silent&&logSearchMeta)logSearchMeta.textContent='در حال جستجو...';logRefreshInFlight=true;try{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','search_logs');if(query)url.searchParams.set('q',query);else url.searchParams.delete('q');if(silent)url.searchParams.set('include_stats','1');else url.searchParams.delete('include_stats');url.searchParams.set('_sync',String(Date.now()));const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')throw new Error(data.message||'جستجوی گزارش‌ها ناموفق بود.');if(requestId!==searchRequest)return;lastLogsVersion=String(data.logs_version||lastLogsVersion);logs=Array.isArray(data.logs)?data.logs:[];render(logs);if(data.stats){lastStatsVersion=String(data.stats_version||lastStatsVersion);renderStats(data.stats)}}catch(error){if(requestId!==searchRequest||silent)return;if(logSearchMeta)logSearchMeta.textContent=error instanceof Error?error.message:'جستجو ناموفق بود.'}finally{logRefreshInFlight=false}};
  logSearch?.addEventListener('input',()=>{window.clearTimeout(searchTimer);searchTimer=window.setTimeout(()=>void searchLogs(),280)});
  const acceptUpdatedLogs=(items,version='',stats=null,statsVersion='')=>{searchRequest+=1;if(version)lastLogsVersion=String(version);if(stats){if(statsVersion)lastStatsVersion=String(statsVersion);renderStats(stats)}if(logSearch?.value.trim()){void searchLogs();return}logs=Array.isArray(items)?items:[];render(logs)};
  const printFromLog=async(index,button)=>{const row=logs[index];if(!row||!(button instanceof HTMLButtonElement))return;const originalLabel=button.textContent;button.disabled=true;button.textContent='در حال آماده‌سازی...';result.className='result loading';result.textContent='در حال ساخت کارت مهمان برای چاپ...';try{const profile=await loadPrintProfile();await printCardForRow(row,profile,1);result.className='result success';result.textContent='پنجره چاپ مجدد باز شد.'}catch(error){result.className='result error';result.textContent=error instanceof Error?error.message:'چاپ مجدد کارت ناموفق بود.'}finally{button.disabled=false;button.textContent=originalLabel;input.focus()}};
  const forceAttendanceFromLog=async(index,button)=>{const row=logs[index];if(!row||!(button instanceof HTMLButtonElement)||isSubmitting||scanProcessing)return;const attendanceAction=String(button.dataset.forceAction||row.force_action||''),guestCode=normalize(row.national_id||row.work_id||'');if(!['entry','quit'].includes(attendanceAction)||guestCode.length<4)return;const title=attendanceAction==='entry'?'Force Enter':'Force Quit';if(!window.confirm(`${title} برای این مهمان ثبت شود؟ این عملیات حضور را Fake Presence علامت می‌زند.`))return;isSubmitting=true;button.disabled=true;input.focus();result.className='result loading';result.textContent='در حال ثبت عملیات اجباری...';try{const seatCount=attendanceAction==='entry'?await seatCountForEntry(guestCode):'';if(seatCount===null)throw new Error('ورود اجباری لغو شد؛ شماره صندلی اختصاص نیافت.');const data=await postWithSeatApproval({action:'force_attendance',attendance_action:attendanceAction,guest_code:guestCode,seat_ticket_count:seatCount,csrf:app.dataset.csrf||''});result.className='result success';result.textContent=data.message||'عملیات اجباری ثبت شد.';if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version,data.stats,data.stats_version);if(data.result==='force_entry_success')await autoPrintEntry(data,guestCode)}catch(error){result.className='result error';result.textContent=error instanceof Error?error.message:'ثبت عملیات اجباری ناموفق بود.';button.disabled=false}finally{isSubmitting=false;input.focus();void processScanQueue()}};
  tbody.addEventListener('click',event=>{const target=event.target instanceof Element?event.target:null;if(!target)return;const printTrigger=target.closest('[data-print-log]');if(printTrigger){const index=Number(printTrigger.dataset.printLog);if(Number.isInteger(index)&&logs[index])void printFromLog(index,printTrigger);return}const forceTrigger=target.closest('[data-force-log]');if(forceTrigger){const index=Number(forceTrigger.dataset.forceLog);if(Number.isInteger(index)&&logs[index])void forceAttendanceFromLog(index,forceTrigger);return}const walkInTrigger=target.closest('[data-register-uninvited]');if(walkInTrigger){const index=Number(walkInTrigger.dataset.registerUninvited);if(Number.isInteger(index)&&logs[index])void openWalkIn(logs[index]);return}const trigger=target.closest('[data-guest-detail]');if(!trigger)return;const index=Number(trigger.dataset.guestDetail);if(Number.isInteger(index)&&logs[index])openDetail(logs[index])});
  app.querySelector('[data-dialog-close]')?.addEventListener('click',()=>dialog?.close());
  dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close()});
  app.querySelector('[data-walk-in-close]')?.addEventListener('click',closeWalkIn);
  app.querySelector('[data-walk-in-cancel]')?.addEventListener('click',closeWalkIn);
  walkInDialog?.addEventListener('click',event=>{if(event.target===walkInDialog)closeWalkIn()});
  walkInControl('outside_organization')?.addEventListener('change',syncOutsideOrganization);
  walkInControl('seat_mode')?.addEventListener('change',syncWalkInSeatMode);
  walkInControl('national_id')?.addEventListener('input',event=>{event.target.value=normalize(event.target.value)});
  walkInForm?.addEventListener('submit',async event=>{event.preventDefault();const submitButton=app.querySelector('[data-walk-in-submit]');if(submitButton instanceof HTMLButtonElement)submitButton.disabled=true;if(walkInStatus){walkInStatus.className='walk-in-status';walkInStatus.textContent='در حال ثبت مهمان ناخوانده...'}try{const formData=new FormData(walkInForm);const payload=Object.fromEntries(formData.entries());payload.action='register_uninvited';payload.csrf=app.dataset.csrf||'';payload.outside_organization=Boolean(walkInControl('outside_organization')?.checked);const data=await postWithSeatApproval(payload);if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version,data.stats,data.stats_version);result.className='result success';result.textContent=data.message||'مهمان ناخوانده ثبت شد.';if(walkInStatus){walkInStatus.className='walk-in-status success';walkInStatus.textContent=data.message||'ثبت شد.'}closeWalkIn();await autoPrintEntry(data,normalize(payload.national_id))}catch(error){if(walkInStatus){walkInStatus.className='walk-in-status error';walkInStatus.textContent=error instanceof Error?error.message:'ثبت مهمان ناخوانده ناموفق بود.'}}finally{if(submitButton instanceof HTMLButtonElement)submitButton.disabled=false}});
  let isSubmitting=false,scanProcessing=false,scannerCompletionTimer=0,lastNumericKeyAt=0,consecutiveFastGaps=0,scannerDetected=false;
  const scanQueue=[];
  const resetScannerState=()=>{window.clearTimeout(scannerCompletionTimer);scannerCompletionTimer=0;lastNumericKeyAt=0;consecutiveFastGaps=0;scannerDetected=false};
  const queueStatus=()=>scanQueue.length>0?` (${scanQueue.length.toLocaleString('fa-IR')} اسکن در صف)`:'';
  const processScanQueue=async()=>{if(scanProcessing||isSubmitting||scanQueue.length===0)return;scanProcessing=true;try{while(scanQueue.length>0){const guestCode=scanQueue.shift();result.className='result loading';result.textContent=`در حال بررسی شناسه ${guestCode}${queueStatus()}...`;try{const seatCount=await seatCountForEntry(guestCode);if(seatCount===null){result.className='result duplicate';result.textContent='ورود لغو شد؛ شماره صندلی اختصاص نیافت.';continue}const data=await postWithSeatApproval({guest_code:guestCode,seat_ticket_count:seatCount,csrf:app.dataset.csrf||''});const good=data.result==='success'||data.result==='quit_success'||data.result==='force_entry_success'||data.result==='force_quit_success';const duplicate=data.result==='duplicate'||data.result==='quit_duplicate'||data.result==='attended_previous_period';result.className=`result ${good?'success':duplicate?'duplicate':'error'}`;result.textContent=`${data.message||'بررسی انجام شد.'}${queueStatus()}`;if(Array.isArray(data.logs))acceptUpdatedLogs(data.logs,data.logs_version,data.stats,data.stats_version);showScanFeedback(data,guestCode);await autoPrintEntry(data,guestCode);if(data.result!=='attended_previous_period')showPreviousAttendanceAlert(data.previous_attendance)}catch(error){const failureMessage=error instanceof Error?error.message:'بررسی مهمان ناموفق بود.';result.className='result error';result.textContent=`${failureMessage}${queueStatus()}`;showScanFeedback({result:'request_error',message:failureMessage},guestCode,'error')}}}finally{scanProcessing=false;input.focus();if(scanQueue.length>0)void processScanQueue()}};
  const enqueueScan=(rawCode)=>{const guestCode=normalize(rawCode);if(guestCode.length<4||guestCode.length>10)return;unlockScanAudio();scanQueue.push(guestCode);input.value='';resetScannerState();input.focus();if(scanProcessing||isSubmitting){result.className='result loading';result.textContent=`اسکن دریافت شد${queueStatus()}. در صف پردازش است.`}void processScanQueue()};
  const scheduleScannerSubmission=(value)=>{window.clearTimeout(scannerCompletionTimer);scannerCompletionTimer=window.setTimeout(()=>{scannerCompletionTimer=0;const completedCode=normalize(input.value);if(scannerDetected&&completedCode===value&&completedCode.length>=4&&completedCode.length<=9)enqueueScan(completedCode)},SCANNER_COMPLETION_DELAY_MS)};
  input.addEventListener('input',()=>{const value=normalize(input.value);if(input.value!==value)input.value=value;if(value.length===10){enqueueScan(value);return}if(scannerDetected&&value.length>=4&&value.length<=9)scheduleScannerSubmission(value)});
  input.addEventListener('keydown',event=>{unlockScanAudio();if(event.key==='Enter'){event.preventDefault();window.clearTimeout(scannerCompletionTimer);const value=normalize(input.value);if(value.length>=4&&value.length<=10)enqueueScan(value);return}if(event.repeat){resetScannerState();return}if(keyDigit(event.key)===''){if(!event.ctrlKey&&!event.metaKey&&!event.altKey)resetScannerState();return}const now=performance.now();const gap=lastNumericKeyAt>0?now-lastNumericKeyAt:Number.POSITIVE_INFINITY;if(gap<=SCANNER_MAX_KEY_GAP_MS)consecutiveFastGaps+=1;else{consecutiveFastGaps=0;scannerDetected=false}lastNumericKeyAt=now;if(consecutiveFastGaps>=SCANNER_MIN_FAST_GAPS)scannerDetected=true});
  let pageScannerBuffer='',pageScannerLastKeyAt=0,pageScannerFastGaps=0,pageScannerResetTimer=0,pageScannerSourceSnapshot=null;
  const captureScannerSource=(element)=>{if(element instanceof HTMLInputElement||element instanceof HTMLTextAreaElement)return{kind:'input',element,value:element.value,start:element.selectionStart,end:element.selectionEnd};if(element instanceof HTMLSelectElement)return{kind:'select',element,selectedIndex:element.selectedIndex};if(element instanceof HTMLElement&&element.isContentEditable)return{kind:'editable',element,html:element.innerHTML};return null};
  const restoreScannerSource=()=>{const snapshot=pageScannerSourceSnapshot;if(!snapshot?.element?.isConnected)return;if(snapshot.kind==='input'){snapshot.element.value=snapshot.value;try{snapshot.element.setSelectionRange(snapshot.start,snapshot.end)}catch{}}else if(snapshot.kind==='select')snapshot.element.selectedIndex=snapshot.selectedIndex;else if(snapshot.kind==='editable')snapshot.element.innerHTML=snapshot.html};
  const resetPageScannerCandidate=()=>{window.clearTimeout(pageScannerResetTimer);pageScannerResetTimer=0;pageScannerBuffer='';pageScannerLastKeyAt=0;pageScannerFastGaps=0;pageScannerSourceSnapshot=null};
  const armPageScannerReset=()=>{window.clearTimeout(pageScannerResetTimer);pageScannerResetTimer=window.setTimeout(resetPageScannerCandidate,Math.max(150,SCANNER_COMPLETION_DELAY_MS+60))};
  const showCapturedFocus=()=>{input.classList.remove('scanner-captured');void input.offsetWidth;input.classList.add('scanner-captured');window.setTimeout(()=>input.classList.remove('scanner-captured'),520)};
  document.addEventListener('keydown',event=>{if(event.target===input||input.disabled||event.defaultPrevented||event.repeat||event.isComposing||event.ctrlKey||event.metaKey||event.altKey||dialog?.open||walkInDialog?.open)return;const digit=keyDigit(event.key);if(digit===''){if(event.key!=='Enter')resetPageScannerCandidate();return}unlockScanAudio();const now=performance.now(),gap=pageScannerLastKeyAt>0?now-pageScannerLastKeyAt:Number.POSITIVE_INFINITY;if(gap>SCANNER_MAX_KEY_GAP_MS){resetPageScannerCandidate();pageScannerBuffer=digit;pageScannerSourceSnapshot=captureScannerSource(event.target);pageScannerFastGaps=0}else{pageScannerBuffer=(pageScannerBuffer+digit).slice(0,10);pageScannerFastGaps+=1}pageScannerLastKeyAt=now;armPageScannerReset();if(pageScannerFastGaps<SCANNER_MIN_FAST_GAPS)return;event.preventDefault();event.stopPropagation();const capturedCode=pageScannerBuffer;restoreScannerSource();resetPageScannerCandidate();input.focus();input.value=capturedCode;showCapturedFocus();scannerDetected=true;lastNumericKeyAt=now;consecutiveFastGaps=SCANNER_MIN_FAST_GAPS;if(capturedCode.length===10)enqueueScan(capturedCode);else scheduleScannerSubmission(capturedCode)},true);
  const LOG_SYNC_INTERVAL_MS=2500;
  let logVersionCheckInFlight=false;
  const syncStats=async()=>{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','stats');url.searchParams.set('_sync',String(Date.now()));const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')return;lastStatsVersion=String(data.stats_version||lastStatsVersion);renderStats(data.stats)};
  const syncVisibleLogs=async()=>{if(document.visibilityState!=='visible'||logRefreshInFlight||logVersionCheckInFlight)return;logVersionCheckInFlight=true;try{const url=new URL(window.location.href);url.searchParams.delete('period');url.searchParams.set('action','logs_version');url.searchParams.set('_sync',String(Date.now()));const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.status!=='ok')return;const version=String(data.logs_version||''),statsVersion=String(data.stats_version||'');if(version&&version!==lastLogsVersion){await searchLogs({silent:true});return}if(statsVersion&&statsVersion!==lastStatsVersion)await syncStats()}finally{logVersionCheckInFlight=false}};
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
            if ($getAction === 'print_profile') {
                $profileGuestCode = (string)($_GET['guest_code'] ?? ($_SESSION['egm_last_print_guest_code'] ?? ''));
                egmCheckInJson(['status' => 'ok', 'print_profile' => egmCheckInAutomaticPrintProfile($context, $profileGuestCode)]);
            }
            if ($getAction === 'seat_requirement') {
                egmCheckInJson(['status' => 'ok'] + egmCheckInSeatRequirement($context, (string)($_GET['guest_code'] ?? '')));
            }
            if ($getAction === 'logs_version') {
                egmCheckInJson([
                    'status' => 'ok',
                    'logs_version' => egmCheckInLogsVersion($context),
                    'stats_version' => egmCheckInStatsVersion($context),
                ]);
            }
            if ($getAction === 'stats') {
                egmCheckInJson([
                    'status' => 'ok',
                    'stats_version' => egmCheckInStatsVersion($context),
                    'stats' => egmCheckInDashboardStats($context),
                ]);
            }
            if ($getAction === 'search_logs') {
                $logsVersion = egmCheckInLogsVersion($context);
                egmCheckInJson([
                    'status' => 'ok',
                    'logs_version' => $logsVersion,
                    'logs' => egmCheckInRecentLogs($context, 200, (string)($_GET['q'] ?? '')),
                    'stats_version' => egmCheckInStatsVersion($context),
                    'stats' => egmCheckInBool($_GET['include_stats'] ?? false)
                        ? egmCheckInDashboardStats($context)
                        : null,
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
                    'stats_version' => egmCheckInStatsVersion($context),
                    'stats' => egmCheckInDashboardStats($context),
                ]);
            }
            if ($action === 'record_ticket_number') {
                $result = egmCheckInRecordTicketNumber(
                    $context,
                    (string)($payload['guest_code'] ?? ''),
                    (string)($payload['number_of_ticket'] ?? ''),
                    (string)($payload['ticket_id'] ?? 'default')
                );
            } elseif ($action === 'register_uninvited') {
                $result = egmCheckInRegisterUninvited($context, $payload, $sessionUser);
            } elseif ($action === 'force_attendance') {
                $result = egmCheckInProcess(
                    $context,
                    (string)($payload['guest_code'] ?? ($payload['national_id'] ?? '')),
                    null,
                    (string)($payload['attendance_action'] ?? ''),
                    $sessionUser,
                    isset($payload['seat_ticket_count']) ? (string)$payload['seat_ticket_count'] : null,
                    egmCheckInBool($payload['allow_split_seats'] ?? false),
                    egmCheckInBool($payload['allow_free_seat'] ?? false)
                );
            } else {
                $result = egmCheckInProcess(
                    $context,
                    (string)($payload['guest_code'] ?? ($payload['national_id'] ?? '')),
                    null,
                    null,
                    [],
                    isset($payload['seat_ticket_count']) ? (string)$payload['seat_ticket_count'] : null,
                    egmCheckInBool($payload['allow_split_seats'] ?? false),
                    egmCheckInBool($payload['allow_free_seat'] ?? false)
                );
            }
            if (in_array((string)($result['result'] ?? ''), ['success', 'force_entry_success'], true)) {
                $_SESSION['egm_last_print_guest_code'] = egmCheckInNormalizeGuestCode((string)($payload['guest_code'] ?? ($payload['national_id'] ?? '')));
            }
            $logsVersion = egmCheckInLogsVersion($context);
            egmCheckInJson(['status' => 'ok'] + $result + [
                'logs_version' => $logsVersion,
                'logs' => egmCheckInRecentLogs($context),
                'stats_version' => egmCheckInStatsVersion($context),
                'stats' => egmCheckInDashboardStats($context),
            ]);
        }
        $nonce = egmSecurityCreateCspNonce();
        egmCheckInRenderPage($context, egmSecurityGetCsrfToken(), $nonce);
    } catch (EgmSeatSplitRequiredException $error) {
        egmCheckInJson(['status' => 'error', 'code' => 'seat_split_confirmation_required', 'message' => $error->getMessage(), 'suggested_seats' => $error->suggestedSeats], 409);
    } catch (EgmSeatCapacityException $error) {
        egmCheckInJson(['status' => 'error', 'code' => 'seat_capacity_insufficient', 'message' => $error->getMessage()], 409);
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
