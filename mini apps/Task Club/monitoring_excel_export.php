<?php
declare(strict_types=1);

function tcMonitoringExportXmlEscape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function tcMonitoringExportGregorianToJalali(int $gy, int $gm, int $gd): array
{
  $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
  $gy2 = $gm > 2 ? $gy + 1 : $gy;
  $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
    + intdiv($gy2 + 399, 400) + $gd + $gdm[max(0, min(11, $gm - 1))];
  $jy = -1595 + (33 * intdiv($days, 12053));
  $days %= 12053;
  $jy += 4 * intdiv($days, 1461);
  $days %= 1461;
  if ($days > 365) {
    $jy += intdiv($days - 1, 365);
    $days = ($days - 1) % 365;
  }
  if ($days < 186) {
    $jm = 1 + intdiv($days, 31);
    $jd = 1 + ($days % 31);
  } else {
    $jm = 7 + intdiv($days - 186, 30);
    $jd = 1 + (($days - 186) % 30);
  }
  return [$jy, $jm, $jd];
}

function tcMonitoringExportShamsiDate(?DateTimeInterface $date, bool $withTime = false): string
{
  if (!$date) {
    return 'نامشخص';
  }
  [$jy, $jm, $jd] = tcMonitoringExportGregorianToJalali(
    (int)$date->format('Y'),
    (int)$date->format('n'),
    (int)$date->format('j')
  );
  $months = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  $result = $jd . ' ' . ($months[$jm] ?? '') . ' ' . $jy;
  return $withTime ? $result . '، ساعت ' . $date->format('H:i') : $result;
}

function tcMonitoringExportParseTaskDate(array $task, string $dateKey, string $timeKey, string $defaultTime): ?DateTimeImmutable
{
  $date = trim((string)($task[$dateKey] ?? ''));
  if ($date === '') {
    return null;
  }
  $time = trim((string)($task[$timeKey] ?? ''));
  if ($time === '') {
    $time = $defaultTime;
  }
  try {
    return new DateTimeImmutable($date . ' ' . $time . ':00', new DateTimeZone('Asia/Tehran'));
  } catch (Throwable $err) {
    return null;
  }
}

function tcMonitoringExportEventWindow(array $tasks): array
{
  $starts = [];
  $ends = [];
  foreach ($tasks as $task) {
    if (!is_array($task)) {
      continue;
    }
    $start = tcMonitoringExportParseTaskDate($task, 'startDate', 'startTime', '00:00');
    $end = tcMonitoringExportParseTaskDate($task, 'endDate', 'endTime', '23:59');
    if ($start) {
      $starts[] = $start;
    }
    if ($end) {
      $ends[] = $end;
    } elseif ($start) {
      $ends[] = $start;
    }
  }
  usort($starts, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
  usort($ends, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
  $start = $starts[0] ?? null;
  $end = $ends ? $ends[count($ends) - 1] : null;
  $duration = 'نامشخص';
  if ($start && $end && $end >= $start) {
    $minutes = max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60));
    if ($minutes >= 1440) {
      $days = intdiv($minutes, 1440);
      $hours = intdiv($minutes % 1440, 60);
      $duration = $days . ' روز' . ($hours > 0 ? ' و ' . $hours . ' ساعت' : '');
    } else {
      $hours = intdiv($minutes, 60);
      $remainingMinutes = $minutes % 60;
      $duration = $hours . ' ساعت' . ($remainingMinutes > 0 ? ' و ' . $remainingMinutes . ' دقیقه' : '');
    }
  }
  return ['start' => $start, 'end' => $end, 'duration' => $duration];
}

function tcMonitoringExportEventTitle(string $baseDir): string
{
  $settings = tcMonitoringReadJson($baseDir . DIRECTORY_SEPARATOR . 'Setting.json', []);
  $title = is_array($settings) ? trim((string)($settings['eventName'] ?? '')) : '';
  return $title !== '' ? $title : 'باشگاه ماموریت';
}

function tcMonitoringExportCell($value, string $style = ''): string
{
  $styleAttribute = $style !== '' ? ' ss:StyleID="' . tcMonitoringExportXmlEscape($style) . '"' : '';
  if (is_int($value) || is_float($value)) {
    $number = is_float($value) ? rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') : (string)$value;
    return '<Cell' . $styleAttribute . '><Data ss:Type="Number">' . $number . '</Data></Cell>';
  }
  return '<Cell' . $styleAttribute . '><Data ss:Type="String">'
    . tcMonitoringExportXmlEscape((string)$value)
    . '</Data></Cell>';
}

function tcMonitoringExportWorksheet(string $name, array $headers, array $rows, array $widths = []): string
{
  // SpreadsheetML keeps column A on the left even when the worksheet content
  // is right-to-left. Reverse every sheet so its first (title/key) field is
  // displayed on the right and its final value/result field is on the left.
  $headers = array_reverse($headers);
  $widths = array_reverse($widths);
  $rows = array_map(
    static fn($row): array => array_reverse((array)$row),
    $rows
  );

  $xml = '<Worksheet ss:Name="' . tcMonitoringExportXmlEscape($name) . '"><Table>';
  foreach ($widths as $width) {
    $xml .= '<Column ss:AutoFitWidth="0" ss:Width="' . max(45, (int)$width) . '"/>';
  }
  if ($headers) {
    $xml .= '<Row ss:Height="28">';
    foreach ($headers as $header) {
      $xml .= tcMonitoringExportCell((string)$header, 'sHeader');
    }
    $xml .= '</Row>';
  }
  foreach ($rows as $row) {
    $xml .= '<Row>';
    foreach ((array)$row as $cell) {
      if (is_array($cell) && array_key_exists('value', $cell)) {
        $xml .= tcMonitoringExportCell($cell['value'], (string)($cell['style'] ?? ''));
      } else {
        $xml .= tcMonitoringExportCell($cell);
      }
    }
    $xml .= '</Row>';
  }
  $xml .= '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">'
    . '<DisplayRightToLeft/><DoNotDisplayGridlines/><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane>'
    . '<PageSetup><Layout x:Orientation="Landscape"/></PageSetup>'
    . '</WorksheetOptions></Worksheet>';
  return $xml;
}

function tcMonitoringExportPercent(float $percent): array
{
  return ['value' => max(0.0, $percent) / 100, 'style' => 'sPercent'];
}

function tcMonitoringExportLevelType(string $type): string
{
  $normalized = strtolower(trim($type));
  if ($normalized === 'out_of_value') {
    return 'خارج از ارزش';
  }
  if ($normalized === 'pot') {
    return 'قرعه‌کشی';
  }
  return 'مجموع ارزش';
}

function tcMonitoringBuildExcelXml(array $data, string $baseDir): array
{
  $tasks = tcMonitoringReadTasks(
    $baseDir . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js',
    $baseDir . DIRECTORY_SEPARATOR . 'tasks'
  );
  $window = tcMonitoringExportEventWindow($tasks);
  $eventTitle = tcMonitoringExportEventTitle($baseDir);
  $timezone = new DateTimeZone('Asia/Tehran');
  $exportedAt = new DateTimeImmutable('now', $timezone);
  $selectedGroups = array_values(array_filter(array_map('strval', (array)($data['selectedWorkIdGroups'] ?? []))));
  $groupLabel = $selectedGroups ? implode('، ', $selectedGroups) : 'هیچ گروهی انتخاب نشده است';
  $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
  $participation = is_array($data['participationStats'] ?? null) ? $data['participationStats'] : [];
  $totalUsers = max(0, (int)($summary['totalUsers'] ?? 0));
  $loggedInUsers = max(0, (int)($participation['loggedInUsers'] ?? 0));

  $sheets = [];
  $sheets[] = tcMonitoringExportWorksheet('اطلاعات رویداد', ['عنوان', 'مقدار'], [
    ['نام رویداد', $eventTitle],
    ['شروع رویداد', tcMonitoringExportShamsiDate($window['start'] ?? null, true)],
    ['پایان رویداد', tcMonitoringExportShamsiDate($window['end'] ?? null, true)],
    ['مدت رویداد', (string)($window['duration'] ?? 'نامشخص')],
    ['تاریخ تهیه گزارش', tcMonitoringExportShamsiDate($exportedAt, true)],
    ['گروه‌های شماره پرسنلی', $groupLabel],
    ['توضیح بازه', 'شروع بر اساس اولین زمان شروع ماموریت و پایان بر اساس آخرین زمان پایان ماموریت محاسبه شده است.']
  ], [150, 430]);

  $summaryLabels = [
    'totalUsers' => 'تعداد دعوت‌شدگان',
    'loggedInUsers' => 'حداقل یک‌بار ورود',
    'usersWithCompletion' => 'کاربران با ماموریت تکمیل‌شده',
    'usersEligibleAnyLevel' => 'واجد حداقل یک سطح جایزه',
    'maximumPrizeCardsOpenable' => 'حداکثر کارت جایزه قابل باز شدن',
    'avgScore' => 'میانگین امتیاز',
    'topScore' => 'بیشترین امتیاز',
    'maxPossibleScore' => 'حداکثر امتیاز قابل دریافت',
    'usersWithMaxPossibleScore' => 'کاربران با حداکثر امتیاز ممکن',
    'startedTaskCount' => 'ماموریت‌های شروع‌شده',
    'usersCompletedAllStarted' => 'تکمیل‌کنندگان همه ماموریت‌های شروع‌شده',
    'prizeRemaining' => 'جوایز باقی‌مانده',
    'prizeCapacity' => 'ظرفیت کل جوایز',
    'prizeGiven' => 'جوایز داده‌شده',
    'prizeValueAssignedToInvitees' => 'مجموع ارزش جوایز داده‌شده (تومان)',
    'prizeValueRemaining' => 'مجموع ارزش جوایز داده‌نشده (تومان)',
    'prizeValueBudget' => 'بودجه کل جوایز (تومان)',
    'prizeValueReconciliationDifference' => 'مغایرت بودجه جوایز (تومان)'
  ];
  $summaryRows = [];
  foreach ($summaryLabels as $key => $label) {
    $summaryRows[] = [$label, is_numeric($summary[$key] ?? null) ? (float)$summary[$key] : 0];
  }
  $sheets[] = tcMonitoringExportWorksheet('خلاصه مانیتورینگ', ['شاخص', 'مقدار'], $summaryRows, [280, 120]);

  $participationRows = [
    ['کل دعوت‌شدگان', $totalUsers, tcMonitoringExportPercent($totalUsers > 0 ? 100 : 0), 'همه کاربران گروه‌های انتخاب‌شده'],
    ['حداقل یک‌بار ورود', $loggedInUsers, tcMonitoringExportPercent($totalUsers > 0 ? ($loggedInUsers * 100 / $totalUsers) : 0), 'نرخ ورود از کل دعوت‌شدگان'],
    ['مشارکت در حداقل یک ماموریت', (int)($participation['usersWithTaskParticipation'] ?? 0), tcMonitoringExportPercent($totalUsers > 0 ? ((int)($participation['usersWithTaskParticipation'] ?? 0) * 100 / $totalUsers) : 0), 'تعامل یا تکمیل حداقل یک ماموریت'],
    ['تکمیل همه ماموریت‌ها', (int)($participation['usersCompletedAllStarted'] ?? 0), tcMonitoringExportPercent($totalUsers > 0 ? ((int)($participation['usersCompletedAllStarted'] ?? 0) * 100 / $totalUsers) : 0), 'تکمیل همه ماموریت‌های شروع‌شده'],
    ['بالاترین امتیاز ممکن', (int)($participation['usersWithMaxPossibleScore'] ?? 0), tcMonitoringExportPercent($totalUsers > 0 ? ((int)($participation['usersWithMaxPossibleScore'] ?? 0) * 100 / $totalUsers) : 0), 'رسیدن به حداکثر امتیاز قابل دریافت'],
    ['واجد سطح جایزه', (int)($participation['usersEligibleAnyLevel'] ?? 0), tcMonitoringExportPercent($totalUsers > 0 ? ((int)($participation['usersEligibleAnyLevel'] ?? 0) * 100 / $totalUsers) : 0), 'رسیدن به حداقل یک سطح جایزه'],
    ['نرخ ترک زودهنگام', (int)($participation['loggedInWithoutCompletion'] ?? 0), tcMonitoringExportPercent($loggedInUsers > 0 ? ((int)($participation['loggedInWithoutCompletion'] ?? 0) * 100 / $loggedInUsers) : 0), 'وارد شده‌اند اما هیچ ماموریتی را تکمیل نکرده‌اند'],
    ['بدون ورود', (int)($participation['neverLoggedInUsers'] ?? 0), tcMonitoringExportPercent($totalUsers > 0 ? ((int)($participation['neverLoggedInUsers'] ?? 0) * 100 / $totalUsers) : 0), 'دعوت‌شدگانی که وارد نشده‌اند']
  ];
  $sheets[] = tcMonitoringExportWorksheet('شاخص‌های مشارکت', ['شاخص', 'تعداد', 'نرخ', 'تعریف'], $participationRows, [230, 90, 90, 360]);

  $taskRows = [];
  foreach ((array)($data['taskStats'] ?? []) as $task) {
    $sourceTask = null;
    foreach ($tasks as $candidate) {
      if ((string)($candidate['id'] ?? '') === (string)($task['id'] ?? '')) {
        $sourceTask = $candidate;
        break;
      }
    }
    $start = is_array($sourceTask) ? tcMonitoringExportParseTaskDate($sourceTask, 'startDate', 'startTime', '00:00') : null;
    $end = is_array($sourceTask) ? tcMonitoringExportParseTaskDate($sourceTask, 'endDate', 'endTime', '23:59') : null;
    $taskRows[] = [
      (string)($task['title'] ?? ''),
      (string)($task['tagCode'] ?? ''),
      tcMonitoringExportShamsiDate($start, true),
      tcMonitoringExportShamsiDate($end, true),
      (int)($task['completedUsers'] ?? 0),
      tcMonitoringExportPercent((float)($task['completionRate'] ?? 0)),
      (float)($task['awardedScoreAvg'] ?? 0),
      (float)($task['awardedScoreTotal'] ?? 0)
    ];
  }
  $sheets[] = tcMonitoringExportWorksheet('ماموریت‌ها', ['ماموریت', 'کد', 'شروع', 'پایان', 'تکمیل‌شده', 'نرخ تکمیل', 'میانگین امتیاز', 'مجموع امتیاز'], $taskRows, [260, 100, 170, 170, 95, 95, 110, 110]);

  $levelRows = [];
  foreach ((array)($data['levelStats'] ?? []) as $level) {
    $levelRows[] = [(string)($level['name'] ?? ''), tcMonitoringExportLevelType((string)($level['type'] ?? '')), (int)($level['score'] ?? 0), (int)($level['eligibleUsers'] ?? 0), tcMonitoringExportPercent((float)($level['eligibleRate'] ?? 0))];
  }
  $sheets[] = tcMonitoringExportWorksheet('سطوح جایزه', ['نام سطح', 'نوع', 'امتیاز لازم', 'کاربران واجد', 'نرخ واجد بودن'], $levelRows, [220, 120, 110, 110, 110]);

  $scoreRows = [];
  foreach ((array)($data['scoreStages'] ?? []) as $stage) {
    $label = ((int)($stage['minScore'] ?? 0) === 0 && (int)($stage['maxScore'] ?? 0) === 0)
      ? 'مشارکت بدون امتیاز'
      : 'امتیاز ' . (int)($stage['minScore'] ?? 0) . ' تا ' . (int)($stage['maxScore'] ?? 0);
    $scoreRows[] = [$label, (int)($stage['users'] ?? 0), tcMonitoringExportPercent((float)($stage['percentage'] ?? 0)), (float)($stage['avgScore'] ?? 0)];
  }
  $sheets[] = tcMonitoringExportWorksheet('پراکندگی امتیاز', ['بازه', 'کاربران', 'درصد', 'میانگین امتیاز'], $scoreRows, [240, 100, 90, 120]);

  $groupRows = [];
  foreach ((array)($data['workIdGroupStats'] ?? []) as $group) {
    $groupRows[] = [(string)($group['group'] ?? ''), (int)($group['users'] ?? 0), (int)($group['participants'] ?? 0), tcMonitoringExportPercent((float)($group['participantRate'] ?? 0)), (float)($group['avgCompletedStartedMissions'] ?? 0), (float)($group['avgScore'] ?? 0)];
  }
  $sheets[] = tcMonitoringExportWorksheet('گروه‌های پرسنلی', ['گروه', 'کاربران', 'مشارکت‌کننده', 'نرخ مشارکت', 'میانگین ماموریت', 'میانگین امتیاز'], $groupRows, [180, 90, 110, 100, 120, 110]);

  $activeRows = [];
  foreach ((array)($data['mostActiveUsers'] ?? []) as $user) {
    $activeRows[] = [(int)($user['rank'] ?? 0), (string)($user['name'] ?? ''), (string)($user['workId'] ?? ''), (int)($user['score'] ?? 0), (int)($user['loginCount'] ?? 0), (int)($user['completedTaskCount'] ?? 0), (int)($user['activityScore'] ?? 0)];
  }
  $sheets[] = tcMonitoringExportWorksheet('فعال‌ترین کاربران', ['رتبه', 'نام', 'شماره پرسنلی', 'امتیاز', 'تعداد ورود', 'ماموریت تکمیل‌شده', 'شاخص فعالیت'], $activeRows, [70, 220, 120, 90, 90, 130, 100]);

  $prizeRows = [];
  foreach ((array)(($data['prizeStats']['rows'] ?? [])) as $prize) {
    $quantity = max(0, (int)($prize['quantity'] ?? 0));
    $remaining = max(0, min($quantity, (int)($prize['last'] ?? 0)));
    $prizeRows[] = [(string)($prize['name'] ?? ($prize['title'] ?? '')), $quantity, $remaining, max(0, $quantity - $remaining), (float)($prize['value'] ?? 0)];
  }
  $sheets[] = tcMonitoringExportWorksheet('موجودی جوایز', ['جایزه', 'ظرفیت', 'باقی‌مانده', 'داده‌شده', 'ارزش هر مورد'], $prizeRows, [260, 90, 100, 100, 120]);

  $xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<?mso-application progid="Excel.Sheet"?>'
    . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
    . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
    . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
    . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
    . '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>'
    . tcMonitoringExportXmlEscape('گزارش مانیتورینگ ' . $eventTitle)
    . '</Title><Created>' . $exportedAt->format('c') . '</Created></DocumentProperties>'
    . '<Styles>'
    . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:ReadingOrder="RightToLeft" ss:WrapText="1"/><Font ss:FontName="PeydaFaNum" ss:Size="11" ss:Color="#1F2937"/></Style>'
    . '<Style ss:ID="sHeader"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:ReadingOrder="RightToLeft" ss:WrapText="1"/><Font ss:FontName="PeydaFaNum" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1689E6" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#0F6FBF"/></Borders></Style>'
    . '<Style ss:ID="sPercent"><Alignment ss:Horizontal="Center" ss:ReadingOrder="RightToLeft"/><Font ss:FontName="PeydaFaNum" ss:Size="11"/><NumberFormat ss:Format="0.0%"/></Style>'
    . '</Styles>'
    . implode('', $sheets)
    . '</Workbook>';

  $stem = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]+/u', '-', trim($eventTitle));
  $stem = is_string($stem) && $stem !== '' ? $stem : 'task-club';
  return ['content' => $xml, 'filename' => $stem . '-گزارش-مانیتورینگ.xlsx'];
}

function tcMonitoringSendExcelExport(array $data, string $baseDir): void
{
  require_once dirname(__DIR__, 2) . '/api/lib/xlsx-export.php';
  $export = tcMonitoringBuildExcelXml($data, $baseDir);
  $content = appXlsxFromSpreadsheetXml((string)$export['content']);
  $filename = (string)$export['filename'];
  appXlsxSend($content, $filename);
}
