<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-refmonitor-teams.php';

function egmGameExportRecords(array $game, array $teams, array $profiles, string $periodCode, string $periodTitle): array
{
    $records = [];
    foreach ($teams as $team) {
        $creator = $team['creator'] ?? [];
        $base = ['کد بازه' => $periodCode, 'بازه' => $periodTitle, 'شناسه بازی' => $game['id'], 'بازی' => $game['name'],
            'ساختار بازی' => $game['has_levels'] ? 'چندمرحله‌ای' : 'بدون مرحله', 'مدیریت خودکار اتاق' => !empty($game['auto_room_manager']) ? 'بله' : 'خیر',
            'حداقل اعضا' => (string)($game['min_players'] ?? ''), 'حداکثر اعضا' => (string)($game['max_players'] ?? ''),
            'شناسه تیم' => (string)$team['id'], 'تیم' => $team['name'], 'رنگ پوشش' => $team['cover_color'],
            'تعداد اعضا' => (string)count($team['members']), 'وضعیت تیم' => $team['ended_at'] ? 'پایان‌یافته' : ($team['started_at'] ? 'در جریان' : 'آماده شروع'),
            'امتیاز کل تیم' => !empty($game['no_score_needed']) ? 'بدون امتیاز' : (string)$team['total_score'],
            'زمان ساخت تیم' => $team['created_at'], 'زمان شروع' => (string)$team['started_at'], 'زمان پایان' => (string)$team['ended_at'],
            'شناسه سازنده' => (string)($creator['code'] ?? ''), 'سازنده تیم' => (string)($creator['name'] ?? ''),
            'نام کاربری سازنده' => (string)($creator['username'] ?? ''), 'نوع حساب سازنده' => ($creator['type'] ?? '') === 'facilitator' ? 'تسهیلگر' : 'مدیر',
            'شماره همراه سازنده' => (string)($creator['phone'] ?? ''), 'ایمیل سازنده' => (string)($creator['email'] ?? ''),
            'کد پرسنلی سازنده' => (string)($creator['work_id'] ?? ''), 'کد ملی سازنده' => (string)($creator['id_number'] ?? '')];
        foreach (egmGamesPlayableLevels($game) as $index => $level) {
            $prefix = ($index + 1) . ' · ' . $level['name']; $score = $team['scores'][$level['id']] ?? null;
            $base['نتیجه: ' . $prefix] = !$score ? 'ثبت نشده' : (!empty($score['completion_only']) ? 'پایان‌یافته' : (string)$score['score']);
            $base['اتاق: ' . $prefix] = (string)($score['room_name'] ?? '');
            $base['زمان ثبت: ' . $prefix] = (string)($score['submitted_at'] ?? '');
            $base['ثبت‌کننده: ' . $prefix] = (string)($score['submitted_by'] ?? '');
        }
        foreach ($team['members'] ?: [[]] as $member) {
            $profile = $profiles[(int)($member['id'] ?? 0)] ?? [];
            $flags = egmGamePrizeState($profile, !empty($member['previous_game_participation']));
            $records[] = $base + ['شناسه عضو' => (string)($member['id'] ?? ''), 'نام عضو' => (string)($member['name'] ?? ''),
                'نام' => (string)($profile['first_name'] ?? ''), 'نام خانوادگی' => (string)($profile['last_name'] ?? ''),
                'کد ملی عضو' => (string)($profile['national_id'] ?? $member['national_id'] ?? ''), 'کد پرسنلی عضو' => (string)($profile['work_id'] ?? $member['work_id'] ?? ''),
                'شماره همراه عضو' => (string)($profile['phone_number'] ?? ''), 'شماره مهمان' => (string)($profile['guest_number'] ?? ''),
                'معاونت' => (string)($profile['deputy'] ?? ''), 'اداره کل' => (string)($profile['general_department'] ?? ''), 'اداره' => (string)($profile['department'] ?? ''),
                'جنسیت' => (string)($profile['gender'] ?? ''), 'سطح سازمانی' => (string)($profile['postal_level'] ?? ''),
                'خارج از سازمان' => !empty($profile['outside_organization']) ? 'بله' : 'خیر',
                'نوع ورود' => $flags['walk_in'] ? 'مهمان ناخوانده' : 'دعوت‌شده', 'ورود ثبت شده' => $flags['entry_recorded'] ? 'بله' : 'خیر',
                'تاریخ ورود' => (string)($profile['entered_date'] ?? ''), 'زمان ورود' => (string)($profile['entered_time'] ?? ''),
                'تاریخ خروج' => (string)($profile['quit_date'] ?? ''), 'زمان خروج' => (string)($profile['quit_time'] ?? ''),
                'وضعیت حضور' => egmPeriodExportConditionLabel(egmPeriodExportResolveCondition($profile)),
                'حضور واقعی' => !empty($profile['correct_presence']) ? 'بله' : 'خیر', 'حضور اجباری' => !empty($profile['fake_presence']) ? 'بله' : 'خیر',
                'دریافت هدیه' => $flags['gift_approved'] ? 'بله' : 'خیر',
                'زمان بررسی هدیه و قرعه‌کشی' => (string)($profile['benefits_reviewed_at'] ?? ''), 'بررسی‌کننده هدیه و قرعه‌کشی' => (string)($profile['benefits_reviewed_by'] ?? ''),
                'تأیید قرعه‌کشی' => !isset($profile['draw_eligible']) ? 'بررسی نشده' : ((int)$profile['draw_eligible'] === 1 ? 'بله' : 'خیر'),
                'حضور قبلی در همین بازی' => $flags['previous_game_participation'] ? 'بله' : 'خیر', 'هشدار جایزه' => $flags['prize_warning']];
        }
    }
    return $records;
}

function egmGameExportXml(array $sheets): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="sHeader"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#0076AD" ss:Pattern="Solid"/></Style></Styles>';
    foreach ($sheets as $index => $sheet) {
        $name = appXlsxSheetName(($index + 1) . ' - ' . $sheet['name'], $index + 1);
        $headers = $sheet['records'] ? array_keys($sheet['records'][0]) : ['بازی', 'تیم', 'سازنده تیم', 'نام کاربری سازنده', 'امتیاز کل تیم', 'نام عضو', 'نوع ورود', 'ورود ثبت شده', 'هشدار جایزه'];
        $xml .= '<Worksheet ss:Name="' . appXlsxXml($name) . '"><Table><Row ss:StyleID="sHeader">';
        foreach ($headers as $header) $xml .= egmPeriodExportXmlCell($header, 'sHeader');
        $xml .= '</Row>';
        foreach ($sheet['records'] as $record) {
            $xml .= '<Row>'; foreach ($headers as $header) $xml .= egmPeriodExportXmlCell((string)($record[$header] ?? '')); $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet>';
    }
    return $xml . '</Workbook>';
}

function egmGameExportBuild(array $context, string $periodCode, string $periodTitle, string $filename): array
{
    $profiles = [];
    foreach (egmPeriodExportGuestRows($context, $periodCode) as $row) $profiles[(int)$row['user_id']] = $row;
    $state = egmGamesState($context); $enabled = $state['enabled'][$periodCode] ?? []; $sheets = []; $count = 0;
    foreach ($state['games'] as $game) {
        if (!in_array($game['id'], $enabled, true)) continue;
        $teams = egmRefMonitorListTeams($context['pdo'], (string)$context['code'], $periodCode, $game['id']);
        $records = egmGameExportRecords($game, $teams, $profiles, $periodCode, $periodTitle);
        $sheets[] = ['name' => $game['name'], 'records' => $records]; $count += count($records);
    }
    if (!$sheets) $sheets[] = ['name' => 'بازی‌ها', 'records' => []];
    return ['content' => appXlsxFromSpreadsheetXml(egmGameExportXml($sheets)), 'filename' => $filename, 'row_count' => $count, 'sheets' => $sheets];
}
