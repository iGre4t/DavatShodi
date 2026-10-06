<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-period-invites.php';
require_once __DIR__ . '/xlsx-export.php';
require_once __DIR__ . '/egm-export-filename.php';
require_once __DIR__ . '/egm-benefits.php';

const EGM_PERIOD_EXPORT_LOG_ACTION = 'egm_period_check_in';

/** @return array<string,string> */
function egmPeriodExportTypes(): array
{
    return [
        'all_guests' => 'همه مهمانان',
        'entered_no_quit' => 'ورود ثبت‌شده بدون خروج',
        'uninvited_guests' => 'مهمانان ناخوانده',
        'full_log' => 'گزارش کامل',
        'user_conditions' => 'وضعیت همه کاربران',
        'correct_presence' => 'حضور واقعی',
        'fake_presence' => 'حضوری نامعقول',
        'ticket_numbers' => 'گزارش تعداد بلیت‌ها',
    ];
}

/** @return array<string,string> */
function egmPeriodExportConditionLabels(): array
{
    return [
        'success' => 'ورود موفق',
        'quit_success' => 'خروج موفق',
        'force_entry_success' => 'ورود اجباری؛ حضور نامعقول',
        'force_quit_success' => 'خروج اجباری؛ حضور نامعقول',
        'walk_in_registered' => 'ثبت مهمان ناخوانده',
        'duplicate' => 'ورود تکراری؛ قبلاً وارد شده',
        'quit_duplicate' => 'خروج تکراری؛ قبلاً خارج شده',
        'quit_without_entry' => 'درخواست خروج بدون ورود ثبت‌شده',
        'invited_other_period' => 'دعوت‌شده در بازه دیگر',
        'attended_previous_period' => 'حضور در بازه قبلی',
        'minimum_stay' => 'حداقل زمان حضور کامل نشده',
        'entry_closed_quit_wave' => 'ورود در زمان خروج بسته است',
        'invalid_attendance_record' => 'سابقه حضور ناسازگار',
        'user_inactive' => 'حساب مهمان غیرفعال',
        'no_active_period' => 'بدون بازه فعال',
        'multiple_active_periods' => 'هم‌پوشانی چند بازه فعال',
        'not_found' => 'کاربر پیدا نشد',
        'not_invited' => 'به این بازه دعوت نشده',
        'upcoming' => 'در انتظار شروع بازه',
        'immune_time' => 'بررسی در زمان ایمن',
        'ended' => 'بازه پایان یافته',
        'inactive' => 'بازه غیرفعال',
        'invalid_schedule' => 'زمان‌بندی نامعتبر',
        'quit_completed' => 'ورود و خروج ثبت شده',
        'entered' => 'ورود ثبت شده؛ خروج ثبت نشده',
        'invalid_quit_without_entry' => 'وضعیت ناسازگار؛ خروج بدون ورود',
        'not_entered' => 'ورود ثبت نشده',
        'not_started' => 'هنوز بررسی نشده',
    ];
}

function egmPeriodExportConditionLabel(string $condition): string
{
    $condition = trim($condition);
    if ($condition === '') return 'هنوز بررسی نشده';
    return egmPeriodExportConditionLabels()[$condition] ?? $condition;
}

function egmPeriodExportResolveCondition(array $row): string
{
    $condition = trim((string)($row['last_control_condition'] ?? ''));
    if ($condition !== '') return $condition;
    $attendance = trim((string)($row['attendance_state'] ?? ''));
    if ($attendance !== '') return $attendance;
    $status = trim((string)($row['period_status'] ?? ($row['status'] ?? '')));
    return $status !== '' ? $status : 'not_started';
}

/** @return array<int,array<string,mixed>> */
function egmPeriodExportGuestRows(array $context, string $periodCode): array
{
    if ((string)($context['code'] ?? '') === '' || !is_array($context['tables'] ?? null)) {
        throw new RuntimeException('خروجی این بازه فقط برای رویداد ثبت‌شده در پایگاه داده در دسترس است.');
    }
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $statement = $context['pdo']->prepare(<<<SQL
SELECT
  u.`id` AS `user_id`, u.`first_name`, u.`last_name`, u.`national_id`, u.`work_id`, u.`phone_number`,
  u.`guest_number`, u.`deputy`, u.`general_department`, u.`department`, u.`gender`, u.`postal_level`,
  u.`is_uninvited_guest` AS `user_is_uninvited_guest`, u.`outside_organization`,
  p.`status` AS `period_status`, p.`invitation_source`, p.`invited_at`, p.`entered_date`, p.`entered_time`,
  p.`quit_date`, p.`quit_time`, p.`correct_presence`, p.`fake_presence`, p.`attendance_state`, p.`last_control_condition`, p.`last_control_action`,
  p.`last_control_message`, p.`last_control_at`, p.`is_uninvited_guest` AS `period_is_uninvited_guest`,
  p.`uninvited_registered_at`, p.`uninvited_registered_by`, p.`number_of_ticket`,
  p.`ticket_numbers_json`, p.`ticket_number_recorded_at`, p.`should_get_gift`, p.`draw_eligible`
FROM `{$periodsTable}` p
JOIN `{$usersTable}` u ON u.`id` = p.`user_id`
WHERE p.`period_code` = :period_code
ORDER BY u.`guest_number`, u.`last_name`, u.`first_name`, u.`id`
SQL);
    $statement->execute([':period_code' => $periodCode]);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<int,array<string,mixed>> */
function egmPeriodExportLogRows(array $context, string $periodCode): array
{
    if ((string)($context['code'] ?? '') === '' || !is_array($context['tables'] ?? null)) {
        throw new RuntimeException('خروجی این بازه فقط برای رویداد ثبت‌شده در پایگاه داده در دسترس است.');
    }
    $usersTable = (string)$context['tables']['users'];
    $periodsTable = (string)$context['tables']['user_periods'];
    $logsTable = (string)$context['tables']['activity_logs'];
    $logsPdo = $context['logs_pdo'] ?? $context['pdo'];
    if (!$logsPdo instanceof PDO) return [];
    $statement = $logsPdo->prepare(<<<SQL
SELECT
  l.`id` AS `log_id`, l.`user_id`, l.`status` AS `log_condition`, l.`message`, l.`level`, l.`occurred_at`,
  l.`ip_address`, l.`user_agent`, l.`work_id` AS `log_work_id`, l.`metadata_json`
FROM `{$logsTable}` l
WHERE l.`action` = :log_action AND l.`entity_id` = :period_code
ORDER BY l.`occurred_at` DESC, l.`id` DESC
SQL);
    $statement->execute([
        ':log_action' => EGM_PERIOD_EXPORT_LOG_ACTION,
        ':period_code' => $periodCode,
    ]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $userIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['user_id'] ?? 0), $rows))));
    $users = [];
    $periods = [];
    if ($userIds) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userStatement = $context['pdo']->prepare("SELECT * FROM `{$usersTable}` WHERE `id` IN ({$placeholders})");
        $userStatement->execute($userIds);
        foreach ($userStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $user) $users[(int)$user['id']] = $user;
        $periodStatement = $context['pdo']->prepare("SELECT * FROM `{$periodsTable}` WHERE `user_id` IN ({$placeholders}) AND `period_code` = ?");
        $periodStatement->execute([...$userIds, $periodCode]);
        foreach ($periodStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $period) $periods[(int)$period['user_id']] = $period;
    }
    foreach ($rows as &$row) {
        $userId = (int)($row['user_id'] ?? 0);
        $user = $users[$userId] ?? [];
        $period = $periods[$userId] ?? [];
        $row += $user + [
            'user_is_uninvited_guest' => $user['is_uninvited_guest'] ?? 0,
            'period_is_uninvited_guest' => $period['is_uninvited_guest'] ?? 0,
            'entered_date' => $period['entered_date'] ?? '', 'entered_time' => $period['entered_time'] ?? '',
            'quit_date' => $period['quit_date'] ?? '', 'quit_time' => $period['quit_time'] ?? '',
            'attendance_state' => $period['attendance_state'] ?? 'not_entered',
            'should_get_gift' => $period['should_get_gift'] ?? 0,
            'correct_presence' => $period['correct_presence'] ?? 0,
            'fake_presence' => $period['fake_presence'] ?? 0,
        ];
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        if (trim((string)($row['national_id'] ?? '')) === '') {
            $row['national_id'] = trim((string)($metadata['national_id'] ?? ''));
        }
        if (trim((string)($row['work_id'] ?? '')) === '') {
            $row['work_id'] = trim((string)($row['log_work_id'] ?? ''));
        }
        $row['attendance_action'] = trim((string)($metadata['attendance_action'] ?? 'check'));
    }
    unset($row);
    return $rows;
}

/** @return array<string,string> */
function egmPeriodExportMainRecord(array $row, ?string $condition = null): array
{
    $condition ??= egmPeriodExportResolveCondition($row);
    return [
        'نام و نام خانوادگی' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        'کد ملی' => trim((string)($row['national_id'] ?? '')),
        'کد پرسنلی' => trim((string)($row['work_id'] ?? '')),
        'شماره همراه' => trim((string)($row['phone_number'] ?? '')),
        'دریافت هدیه' => (int)($row['should_get_gift'] ?? 0) === 1 ? 'بله' : 'خیر',
        'وضعیت دقیق' => egmPeriodExportConditionLabel($condition),
        'تاریخ ورود' => trim((string)($row['entered_date'] ?? '')),
        'زمان ورود' => trim((string)($row['entered_time'] ?? '')),
        'تاریخ خروج' => trim((string)($row['quit_date'] ?? '')),
        'زمان خروج' => trim((string)($row['quit_time'] ?? '')),
        'Correct Presence' => (int)($row['correct_presence'] ?? 0) === 1 ? 'بله' : 'خیر',
        'Fake Presence' => (int)($row['fake_presence'] ?? 0) === 1 ? 'بله' : 'خیر',
    ];
}

/** @return array<int,array{id:string,title:string}> */
function egmPeriodExportTicketDefinitions(array $context, array $guestRows): array
{
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    $ticketSettings = is_array($settings['customNumberTicketSettings'] ?? null)
        ? $settings['customNumberTicketSettings']
        : [];
    $rawDefinitions = is_array($ticketSettings['tickets'] ?? null) ? $ticketSettings['tickets'] : [];
    $definitions = [];
    $seen = [];
    foreach (array_slice($rawDefinitions, 0, 20) as $index => $definition) {
        if (!is_array($definition)) continue;
        $id = strtolower(trim((string)($definition['id'] ?? '')));
        $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?? '';
        if ($id === '' || isset($seen[$id])) continue;
        $title = trim((string)($definition['title'] ?? '')) ?: ('Ticket ' . ($index + 1));
        $definitions[] = ['id' => $id, 'title' => $title];
        $seen[$id] = true;
    }
    foreach ($guestRows as $row) {
        $stored = json_decode((string)($row['ticket_numbers_json'] ?? ''), true);
        if (!is_array($stored)) continue;
        foreach (array_keys($stored) as $rawId) {
            $id = strtolower(trim((string)$rawId));
            $id = preg_replace('/[^a-z0-9_-]+/', '-', $id) ?? '';
            if ($id === '' || isset($seen[$id])) continue;
            $definitions[] = ['id' => $id, 'title' => $id];
            $seen[$id] = true;
        }
    }
    if ($definitions === []) $definitions[] = ['id' => 'default', 'title' => 'Custom Number Ticket'];
    return $definitions;
}

/** @return array<string,int> */
function egmPeriodExportTicketValues(array $row): array
{
    $stored = json_decode((string)($row['ticket_numbers_json'] ?? ''), true);
    if (!is_array($stored)) $stored = [];
    if (!array_key_exists('default', $stored) && trim((string)($row['number_of_ticket'] ?? '')) !== '') {
        $stored['default'] = $row['number_of_ticket'];
    }
    $values = [];
    foreach ($stored as $id => $value) {
        $digits = trim((string)$value);
        if (preg_match('/^[0-9]+$/D', $digits) !== 1) continue;
        $values[(string)$id] = strlen(ltrim($digits, '0')) > 18 ? PHP_INT_MAX : (int)$digits;
    }
    return $values;
}

/** @param array<int,array{id:string,title:string}> $ticketDefinitions
 *  @return array<int,array<string,string>>
 */
function egmPeriodExportTicketRecords(array $guestRows, array $ticketDefinitions): array
{
    $records = [];
    $totals = array_fill_keys(array_column($ticketDefinitions, 'id'), 0);
    $grandTotal = 0;
    foreach ($guestRows as $row) {
        $hasEntry = trim((string)($row['entered_date'] ?? '')) !== ''
            && trim((string)($row['entered_time'] ?? '')) !== '';
        if (!$hasEntry) continue;
        $values = egmPeriodExportTicketValues($row);
        $record = [
            'نام و نام خانوادگی' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
            'کد ملی' => trim((string)($row['national_id'] ?? '')),
            'کد پرسنلی' => trim((string)($row['work_id'] ?? '')),
            'شماره مهمان' => trim((string)($row['guest_number'] ?? '')),
            'دریافت هدیه' => (int)($row['should_get_gift'] ?? 0) === 1 ? 'بله' : 'خیر',
        ];
        $userTotal = 0;
        foreach ($ticketDefinitions as $definition) {
            $value = max(0, (int)($values[$definition['id']] ?? 0));
            $record['تعداد: ' . $definition['title']] = (string)$value;
            $totals[$definition['id']] = min(PHP_INT_MAX, $totals[$definition['id']] + $value);
            $userTotal = min(PHP_INT_MAX, $userTotal + $value);
        }
        $record['جمع بلیت‌های کاربر'] = (string)$userTotal;
        $grandTotal = min(PHP_INT_MAX, $grandTotal + $userTotal);
        $records[] = $record;
    }
    $totalRecord = [
        'نام و نام خانوادگی' => 'جمع کل',
        'کد ملی' => '',
        'کد پرسنلی' => '',
        'شماره مهمان' => '',
    ];
    foreach ($ticketDefinitions as $definition) {
        $totalRecord['تعداد: ' . $definition['title']] = (string)($totals[$definition['id']] ?? 0);
    }
    $totalRecord['جمع بلیت‌های کاربر'] = (string)$grandTotal;
    $records[] = $totalRecord;
    return $records;
}

/** @return array<int,array<string,string>> */
function egmPeriodExportRecords(string $type, array $guestRows, array $logRows = [], array $ticketDefinitions = []): array
{
    if ($type === 'ticket_numbers') {
        return egmPeriodExportTicketRecords($guestRows, $ticketDefinitions);
    }
    if ($type === 'full_log') {
        return array_map(static function (array $row): array {
            $condition = trim((string)($row['log_condition'] ?? '')) ?: 'not_started';
            return egmPeriodExportMainRecord($row, $condition) + [
                'کد وضعیت' => $condition,
                'زمان عملیات' => trim((string)($row['occurred_at'] ?? '')),
                'نوع عملیات' => trim((string)($row['attendance_action'] ?? 'check')),
                'پیام ثبت‌شده' => trim((string)($row['message'] ?? '')),
                'شماره مهمان' => trim((string)($row['guest_number'] ?? '')),
                'معاونت' => trim((string)($row['deputy'] ?? '')),
                'اداره کل' => trim((string)($row['general_department'] ?? '')),
                'اداره' => trim((string)($row['department'] ?? '')),
                'نشانی شبکه' => trim((string)($row['ip_address'] ?? '')),
            ];
        }, $logRows);
    }

    if ($type === 'entered_no_quit') {
        $guestRows = array_values(array_filter($guestRows, static function (array $row): bool {
            $hasEntry = trim((string)($row['entered_date'] ?? '')) !== ''
                && trim((string)($row['entered_time'] ?? '')) !== '';
            $hasQuit = trim((string)($row['quit_date'] ?? '')) !== ''
                && trim((string)($row['quit_time'] ?? '')) !== '';
            return $hasEntry && !$hasQuit;
        }));
    } elseif ($type === 'uninvited_guests') {
        $guestRows = array_values(array_filter($guestRows, static fn(array $row): bool =>
            (int)($row['period_is_uninvited_guest'] ?? 0) === 1 || (int)($row['user_is_uninvited_guest'] ?? 0) === 1
        ));
    } elseif ($type === 'correct_presence') {
        $guestRows = array_values(array_filter(
            $guestRows,
            static fn(array $row): bool => (int)($row['correct_presence'] ?? 0) === 1
                && (int)($row['fake_presence'] ?? 0) !== 1
        ));
    } elseif ($type === 'fake_presence') {
        $guestRows = array_values(array_filter(
            $guestRows,
            static fn(array $row): bool => (int)($row['fake_presence'] ?? 0) === 1
        ));
    }

    return array_map(static function (array $row) use ($type): array {
        // This is an operational "currently inside" list, not a final
        // Correct/Fake Presence classification.
        $condition = $type === 'entered_no_quit' ? 'entered' : egmPeriodExportResolveCondition($row);
        $record = egmPeriodExportMainRecord($row, $condition) + [
            'کد وضعیت' => $condition,
            'شماره مهمان' => trim((string)($row['guest_number'] ?? '')),
            'نوع دعوت' => trim((string)($row['invitation_source'] ?? '')),
            'معاونت' => trim((string)($row['deputy'] ?? '')),
            'اداره کل' => trim((string)($row['general_department'] ?? '')),
            'اداره' => trim((string)($row['department'] ?? '')),
            'آخرین زمان بررسی' => trim((string)($row['last_control_at'] ?? '')),
            'پیام آخرین بررسی' => trim((string)($row['last_control_message'] ?? '')),
        ];
        if ($type === 'uninvited_guests') {
            $record += [
                'خارج از سازمان' => (int)($row['outside_organization'] ?? 0) === 1 ? 'بله' : 'خیر',
                'زمان ثبت مهمان ناخوانده' => trim((string)($row['uninvited_registered_at'] ?? '')),
                'ثبت‌کننده مهمان ناخوانده' => trim((string)($row['uninvited_registered_by'] ?? '')),
            ];
        }
        return $record;
    }, $guestRows);
}

function egmPeriodExportXmlCell(string $value, string $style = ''): string
{
    return '<Cell' . ($style !== '' ? ' ss:StyleID="' . appXlsxXml($style) . '"' : '')
        . '><Data ss:Type="String">' . appXlsxXml($value) . '</Data></Cell>';
}

/** @param array<int,array<string,string>> $records */
function egmPeriodExportSpreadsheetXml(string $sheetName, array $records): string
{
    $headers = $records ? array_keys($records[0]) : [
        'نام و نام خانوادگی', 'کد ملی', 'کد پرسنلی', 'شماره همراه', 'وضعیت دقیق',
        'تاریخ ورود', 'زمان ورود', 'تاریخ خروج', 'زمان خروج',
    ];
    $rowsXml = '<Row ss:StyleID="sHeader">';
    foreach ($headers as $header) $rowsXml .= egmPeriodExportXmlCell((string)$header, 'sHeader');
    $rowsXml .= '</Row>';
    foreach ($records as $record) {
        $rowsXml .= '<Row>';
        foreach ($headers as $header) $rowsXml .= egmPeriodExportXmlCell((string)($record[$header] ?? ''));
        $rowsXml .= '</Row>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Styles><Style ss:ID="sHeader"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>'
        . '<Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#E11D2E" ss:Pattern="Solid"/></Style></Styles>'
        . '<Worksheet ss:Name="' . appXlsxXml($sheetName) . '"><Table>' . $rowsXml . '</Table></Worksheet></Workbook>';
}

function egmPeriodExportSafeFilenamePart(string $value, string $fallback): string
{
    $value = trim(preg_replace('~[\\\\/:*?"<>|]+~u', '-', $value) ?? '');
    return $value !== '' ? $value : $fallback;
}

function egmPeriodExportSessionCanAccess(array $context, array $sessionUser, string $periodCode): bool
{
    if (function_exists('userHasPermissionId') && (
        userHasPermissionId($sessionUser, 'event-guest-manager:export')
        || userHasPermissionId($sessionUser, 'event-guest-manager:manage-tasks')
    )) {
        return true;
    }
    $userCode = strtolower(trim((string)($sessionUser['code'] ?? '')));
    if ($userCode === '') return false;
    $accessPath = (string)$context['mission_dir'] . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'task-access.json';
    $raw = is_file($accessPath) ? file_get_contents($accessPath) : false;
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];
    $userEntry = null;
    foreach ($users as $rawCode => $entry) {
        if (strtolower(trim((string)$rawCode)) === $userCode && is_array($entry)) {
            $userEntry = $entry;
            break;
        }
    }
    if (!is_array($userEntry)) return false;
    if (egmInstanceBoolValue($userEntry['allowManageTasksTab'] ?? ($userEntry['allow_manage_tasks_tab'] ?? false))) {
        return true;
    }
    $periodId = '';
    foreach (egmPeriodInvitesPeriods($context) as $period) {
        $candidateCode = trim((string)($period['tagCode'] ?? ($period['code'] ?? '')));
        if ($candidateCode === $periodCode) {
            $periodId = trim((string)($period['id'] ?? ''));
            break;
        }
    }
    if ($periodId === '') return false;
    $taskRules = is_array($userEntry['tasks'] ?? null) ? $userEntry['tasks'] : [];
    $rule = null;
    foreach ($taskRules as $rawTaskId => $entry) {
        if (strtolower(trim((string)$rawTaskId)) === strtolower($periodId) && is_array($entry)) {
            $rule = $entry;
            break;
        }
    }
    if (!is_array($rule) || !egmInstanceBoolValue($rule['enabled'] ?? true)) return false;
    $panes = is_array($rule['panes'] ?? null) ? $rule['panes'] : [];
    return egmInstanceBoolValue($panes['export'] ?? true);
}

/** @return array{content:string,filename:string,row_count:int,records:array<int,array<string,string>>} */
function egmPeriodExportBuild(array $context, string $periodCode, string $type): array
{
    $types = egmPeriodExportTypes();
    if (!isset($types[$type])) throw new InvalidArgumentException('نوع خروجی اکسل معتبر نیست.');
    $periodCode = egmPeriodInvitesValidatePeriod($context, $periodCode);
    $periodTitle = $periodCode;
    $periodDate = null;
    foreach (egmPeriodInvitesPeriods($context) as $period) {
        $candidateCode = trim((string)($period['tagCode'] ?? ($period['code'] ?? '')));
        if ($candidateCode === $periodCode) {
            $periodTitle = trim((string)($period['title'] ?? '')) ?: $periodCode;
            $periodDate = egmExportPeriodDate($period);
            break;
        }
    }
    $guestRows = egmPeriodExportGuestRows($context, $periodCode);
    $logRows = $type === 'full_log' ? egmPeriodExportLogRows($context, $periodCode) : [];
    $ticketDefinitions = $type === 'ticket_numbers' ? egmPeriodExportTicketDefinitions($context, $guestRows) : [];
    $records = egmPeriodExportRecords($type, $guestRows, $logRows, $ticketDefinitions);
    $sheetName = $types[$type];
    $xml = egmPeriodExportSpreadsheetXml($sheetName, $records);
    $filename = egmExportPeriodDatedFilename($sheetName, $periodDate);
    return [
        'content' => appXlsxFromSpreadsheetXml($xml),
        'filename' => $filename,
        'row_count' => count($records),
        'records' => $records,
    ];
}

function handleEgmPeriodExportRequest(string $missionDir, array $sessionUser): never
{
    try {
        $context = egmPeriodInvitesContext($missionDir);
        $periodCode = egmPeriodInvitesValidatePeriod($context, trim((string)($_GET['period_code'] ?? '')));
        if (!egmPeriodExportSessionCanAccess($context, $sessionUser, $periodCode)) {
            denyPanelAccess(403, 'شما اجازه دریافت خروجی این بازه را ندارید.', false);
        }
        $export = egmPeriodExportBuild(
            $context,
            $periodCode,
            strtolower(trim((string)($_GET['type'] ?? 'all_guests')))
        );
        appXlsxSend($export['content'], $export['filename']);
        exit;
    } catch (InvalidArgumentException $error) {
        denyPanelAccess(422, $error->getMessage(), false);
    } catch (Throwable $error) {
        error_log('EGM period export failed: ' . $error->getMessage());
        denyPanelAccess(500, 'ساخت خروجی اکسل بازه ناموفق بود.', false);
    }
}
