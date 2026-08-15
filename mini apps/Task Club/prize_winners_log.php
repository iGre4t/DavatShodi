<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/prize_award_log.php';

$user = requireTabPermissionFromSession('task-club', false);
if (!userHasPermissionId($user, 'task-club:export')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', false);
}

$ledgerPath = __DIR__ . '/TC Event/' . TC_PRIZE_AWARD_LOG_FILENAME;

/** @return list<array{won_at:string,user_name:string,work_id:string,card_number:int,level_name:string,prize_name:string}> */
function tcPrizeWinnersRows(array $entries): array
{
  $rows = [];
  foreach ($entries as $entry) {
    if (!is_array($entry) || (string)($entry['status'] ?? '') !== 'awarded') {
      continue;
    }
    $prize = is_array($entry['prize'] ?? null) ? $entry['prize'] : [];
    $level = is_array($entry['level'] ?? null) ? $entry['level'] : [];
    $rows[] = [
      'won_at' => trim((string)($entry['awardedAt'] ?? $entry['selectedAt'] ?? '')),
      'user_name' => trim((string)($entry['userName'] ?? '')),
      'work_id' => trim((string)($entry['workId'] ?? '')),
      'card_number' => max(0, (int)($entry['cardIndex'] ?? -1)) + 1,
      'level_name' => trim((string)($level['name'] ?? '')),
      'prize_name' => trim((string)($prize['name'] ?? ''))
    ];
  }
  usort($rows, static fn(array $left, array $right): int => strcmp($right['won_at'], $left['won_at']));
  return $rows;
}

function tcPrizeWinnersCsvCell(string $value): string
{
  return preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
}

function tcPrizeWinnersHtml(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$rows = tcPrizeWinnersRows(tcPrizeAwardLogRead($ledgerPath));

if (strtolower(trim((string)($_GET['format'] ?? ''))) === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="task-club-prize-winners.csv"');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  $output = fopen('php://output', 'wb');
  if ($output === false) {
    http_response_code(500);
    exit;
  }
  fwrite($output, "\xEF\xBB\xBF");
  fputcsv($output, ['Won At', 'User Name', 'Work ID', 'Card Number', 'Prize Level', 'Prize Won'], ',', '"', '\\');
  foreach ($rows as $row) {
    fputcsv($output, [
      tcPrizeWinnersCsvCell($row['won_at']),
      tcPrizeWinnersCsvCell($row['user_name']),
      tcPrizeWinnersCsvCell($row['work_id']),
      (string)$row['card_number'],
      tcPrizeWinnersCsvCell($row['level_name']),
      tcPrizeWinnersCsvCell($row['prize_name'])
    ], ',', '"', '\\');
  }
  fclose($output);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Task Club Prize Winners</title>
  <style>
    body { margin: 0; padding: 24px; color: #172033; background: #f4f6fa; font: 14px/1.5 system-ui, sans-serif; }
    main { max-width: 1200px; margin: auto; }
    header { display: flex; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    h1 { margin: 0; font-size: 24px; }
    a { padding: 10px 14px; color: white; background: #3457d5; border-radius: 8px; text-decoration: none; }
    .table-wrap { overflow: auto; background: white; border: 1px solid #dfe4ee; border-radius: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 11px 13px; border-bottom: 1px solid #e8ebf1; text-align: left; white-space: nowrap; }
    th { background: #f8f9fc; }
    tbody tr:last-child td { border-bottom: 0; }
    .empty { padding: 32px; color: #687086; text-align: center; }
  </style>
</head>
<body>
<main>
  <header>
    <div><h1>Task Club Prize Winners</h1><div><?= count($rows) ?> confirmed win<?= count($rows) === 1 ? '' : 's' ?></div></div>
    <a href="?format=csv">Download CSV</a>
  </header>
  <div class="table-wrap">
    <?php if (!$rows): ?>
      <div class="empty">No confirmed prize wins have been recorded yet.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Won at</th><th>User</th><th>Work ID</th><th>Card</th><th>Prize level</th><th>Prize won</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr><td><?= tcPrizeWinnersHtml($row['won_at']) ?></td><td><?= tcPrizeWinnersHtml($row['user_name']) ?></td><td><?= tcPrizeWinnersHtml($row['work_id']) ?></td><td><?= $row['card_number'] ?></td><td><?= tcPrizeWinnersHtml($row['level_name']) ?></td><td><?= tcPrizeWinnersHtml($row['prize_name']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
