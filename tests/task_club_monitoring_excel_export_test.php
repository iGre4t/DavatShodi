<?php
declare(strict_types=1);

function tcMonitoringReadJson(string $path, $fallback)
{
  return str_ends_with($path, 'Setting.json') ? ['eventName' => 'رویداد آزمایشی'] : $fallback;
}

function tcMonitoringReadTasks(string $storePath, string $tasksDir): array
{
  return [
    [
      'id' => 'task-1',
      'title' => 'ماموریت اول',
      'tagCode' => 'T1',
      'startDate' => '2026-07-14',
      'startTime' => '10:00',
      'endDate' => '2026-07-15',
      'endTime' => '12:00'
    ]
  ];
}

require_once dirname(__DIR__) . '/mini apps/Task Club/monitoring_excel_export.php';

$data = [
  'selectedWorkIdGroups' => ['100'],
  'summary' => [
    'totalUsers' => 10,
    'loggedInUsers' => 8,
    'usersWithCompletion' => 6,
    'maximumPrizeCardsOpenable' => 12
  ],
  'participationStats' => [
    'loggedInUsers' => 8,
    'usersWithTaskParticipation' => 7,
    'usersWithCompletion' => 6,
    'usersCompletedAllStarted' => 3,
    'usersWithMaxPossibleScore' => 2,
    'usersEligibleAnyLevel' => 4,
    'loggedInWithoutCompletion' => 2,
    'neverLoggedInUsers' => 2
  ],
  'taskStats' => [[
    'id' => 'task-1',
    'title' => 'ماموریت اول',
    'tagCode' => 'T1',
    'completedUsers' => 6,
    'completionRate' => 60,
    'awardedScoreAvg' => 25,
    'awardedScoreTotal' => 150
  ]],
  'levelStats' => [[
    'name' => 'سطح اول',
    'type' => 'value_sum',
    'score' => 20,
    'eligibleUsers' => 4,
    'eligibleRate' => 40
  ]],
  'scoreStages' => [],
  'workIdGroupStats' => [],
  'mostActiveUsers' => [],
  'prizeStats' => ['rows' => []]
];

$export = tcMonitoringBuildExcelXml($data, dirname(__DIR__) . '/mini apps/Task Club');
$xml = (string)($export['content'] ?? '');

$dom = new DOMDocument();
if (!$dom->loadXML($xml)) {
  throw new RuntimeException('Monitoring Excel export is not valid XML.');
}

$requiredFragments = [
  'PeydaFaNum',
  'DisplayRightToLeft',
  'اطلاعات رویداد',
  'شاخص‌های مشارکت',
  '23 تیر 1405',
  'نرخ ترک زودهنگام',
  'گروه‌های پرسنلی',
  'موجودی جوایز'
];
foreach ($requiredFragments as $fragment) {
  if (!str_contains($xml, $fragment)) {
    throw new RuntimeException('Missing expected export fragment: ' . $fragment);
  }
}

if (!str_ends_with((string)($export['filename'] ?? ''), '.xlsx')) {
  throw new RuntimeException('Monitoring export filename must use the .xlsx extension.');
}

fwrite(STDOUT, "Task Club monitoring Excel export test passed.\n");
