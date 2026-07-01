<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mini apps/Task Club/invitees_csv_safety.php';

if (($argv[1] ?? '') === 'worker') {
  $path = (string)($argv[2] ?? '');
  $workId = (string)($argv[3] ?? '');
  $score = (string)($argv[4] ?? '');
  $delay = max(0, (int)($argv[5] ?? 0));
  $rows = tcInviteesCsvReadRowsForUpdate($path);
  usleep($delay);
  $updated = false;
  for ($index = 1; $index < count($rows); $index += 1) {
    if ((string)($rows[$index][0] ?? '') === $workId) {
      $rows[$index][1] = $score;
      $updated = true;
      break;
    }
  }
  exit($updated && tcInviteesCsvCommitRows($path, $rows) ? 0 : 1);
}

function tcCsvTestAssert(bool $condition, string $message): void
{
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function tcCsvTestRemoveTree(string $path): void
{
  if (!is_dir($path)) {
    return;
  }
  foreach (scandir($path) ?: [] as $name) {
    if ($name === '.' || $name === '..') {
      continue;
    }
    $item = $path . DIRECTORY_SEPARATOR . $name;
    is_dir($item) ? tcCsvTestRemoveTree($item) : @unlink($item);
  }
  @rmdir($path);
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-csv-safety-' . bin2hex(random_bytes(6));
$path = $dir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
tcCsvTestAssert(mkdir($dir, 0700, true), 'temporary directory could not be created');

try {
  $initialRows = [
    ['Work ID', 'score'],
    ['1001', '10'],
    ['1002', '20'],
    ['1003', '30']
  ];
  tcCsvTestAssert(tcInviteesCsvCommitRows($path, $initialRows), 'initial commit failed');

  $rows = tcInviteesCsvReadRowsForUpdate($path);
  tcCsvTestAssert(count($rows) === 4, 'transactional read returned the wrong row count');
  $rows[2][1] = '25';
  tcCsvTestAssert(tcInviteesCsvCommitRows($path, $rows), 'score update failed');
  tcCsvTestAssert(is_file($path . '.bak'), 'last-known-good backup was not created');

  $rows = tcInviteesCsvReadRowsForUpdate($path);
  tcCsvTestAssert(!tcInviteesCsvCommitRows($path, array_slice($rows, 0, 2)), 'row-loss guard accepted a truncated table');
  $afterRejectedCommit = tcInviteesCsvReadRowsSnapshot($path);
  tcCsvTestAssert(count($afterRejectedCommit) === 4, 'rejected commit changed the live CSV');

  tcCsvTestAssert(rename($path, $path . '.rollback'), 'interrupted-replace setup failed');
  $recoveredRows = tcInviteesCsvReadRowsSnapshot($path);
  tcCsvTestAssert(count($recoveredRows) === 4, 'rollback recovery did not restore the CSV');
  tcCsvTestAssert(is_file($path), 'rollback recovery did not restore the live path');

  $workers = [];
  foreach ([['1001', '11', '250000'], ['1003', '33', '0']] as $args) {
    $pipes = [];
    $process = proc_open(
      [PHP_BINARY, __FILE__, 'worker', $path, ...$args],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes
    );
    tcCsvTestAssert(is_resource($process), 'concurrency worker could not be started');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  foreach ($workers as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    tcCsvTestAssert(proc_close($process) === 0, 'concurrency worker failed: ' . trim($stdout . ' ' . $stderr));
  }
  $concurrentRows = tcInviteesCsvReadRowsSnapshot($path);
  tcCsvTestAssert((string)($concurrentRows[1][1] ?? '') === '11', 'first concurrent update was lost');
  tcCsvTestAssert((string)($concurrentRows[3][1] ?? '') === '33', 'second concurrent update was lost');

  fwrite(STDOUT, "Task Club invitees CSV safety test passed.\n");
} finally {
  tcInviteesCsvEndTransaction($path);
  tcCsvTestRemoveTree($dir);
}
