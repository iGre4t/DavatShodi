<?php
declare(strict_types=1);

/** @return array{year:int,month:int,day:int} */
function egmExportGregorianToJalali(int $year, int $month, int $day): array
{
    $gregorianMonthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $adjustedYear = $month > 2 ? $year + 1 : $year;
    $days = 355666 + (365 * $year)
        + intdiv($adjustedYear + 3, 4)
        - intdiv($adjustedYear + 99, 100)
        + intdiv($adjustedYear + 399, 400)
        + $day
        + $gregorianMonthDays[$month - 1];
    $jalaliYear = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jalaliYear += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jalaliYear += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jalaliMonth = 1 + intdiv($days, 31);
        $jalaliDay = 1 + ($days % 31);
    } else {
        $jalaliMonth = 7 + intdiv($days - 186, 30);
        $jalaliDay = 1 + (($days - 186) % 30);
    }
    return ['year' => $jalaliYear, 'month' => $jalaliMonth, 'day' => $jalaliDay];
}

function egmExportShamsiDayMonth(?string $gregorianDate = null): string
{
    $timezone = new DateTimeZone('Asia/Tehran');
    $date = null;
    $candidate = trim((string)$gregorianDate);
    if ($candidate !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($candidate, 0, 10), $timezone);
    }
    if (!$date instanceof DateTimeImmutable) $date = new DateTimeImmutable('now', $timezone);
    $jalali = egmExportGregorianToJalali(
        (int)$date->format('Y'),
        (int)$date->format('n'),
        (int)$date->format('j')
    );
    $months = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $jalali['day'] . ' ' . ($months[$jalali['month']] ?? '') . 'ماه';
}

function egmExportSafeFilenamePart(string $value, string $fallback = 'export'): string
{
    $value = trim(preg_replace('~[\\/:*?"<>|]+~u', '-', $value) ?? '');
    return $value !== '' ? $value : $fallback;
}

function egmExportDatedFilename(string $label, ?string $gregorianDate = null, string $extension = 'xlsx'): string
{
    $extension = strtolower(trim($extension));
    if (!preg_match('/^[a-z0-9]{1,8}$/D', $extension)) $extension = 'xlsx';
    return egmExportSafeFilenamePart($label) . ' ' . egmExportShamsiDayMonth($gregorianDate) . '.' . $extension;
}
