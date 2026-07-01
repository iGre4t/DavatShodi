<?php
declare(strict_types=1);

/**
 * Transactional storage for every "Invitees mapped.csv" file.
 *
 * Writers hold a sidecar lock from the initial read until commit. Commits are
 * prepared completely in the same directory and then replace the live file,
 * so readers see either the old complete file or the new complete file.
 */

function tcInviteesCsvIsManagedPath(string $path): bool
{
  return strcasecmp(basename($path), 'Invitees mapped.csv') === 0;
}

function tcInviteesCsvTransactionKey(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function &tcInviteesCsvTransactions(): array
{
  if (!isset($GLOBALS['tc_invitees_csv_transactions']) || !is_array($GLOBALS['tc_invitees_csv_transactions'])) {
    $GLOBALS['tc_invitees_csv_transactions'] = [];
  }
  return $GLOBALS['tc_invitees_csv_transactions'];
}

function tcInviteesCsvRecoverInterruptedReplace(string $path): void
{
  $rollbackPath = $path . '.rollback';
  if (!is_file($path) && is_file($rollbackPath)) {
    @rename($rollbackPath, $path);
    return;
  }
  if (is_file($path) && is_file($rollbackPath)) {
    @unlink($rollbackPath);
  }
}

function tcInviteesCsvBeginTransaction(string $path): bool
{
  if (!tcInviteesCsvIsManagedPath($path)) {
    return false;
  }
  $transactions =& tcInviteesCsvTransactions();
  $key = tcInviteesCsvTransactionKey($path);
  if (isset($transactions[$key]) && is_resource($transactions[$key]['handle'] ?? null)) {
    return true;
  }
  $dir = dirname($path);
  if (!is_dir($dir) && !(mkdir($dir, 0777, true) || is_dir($dir))) {
    return false;
  }
  $handle = fopen($path . '.lock', 'c+b');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  tcInviteesCsvRecoverInterruptedReplace($path);
  $transactions[$key] = [
    'handle' => $handle,
    'path' => $path,
    'baselineRowCount' => null
  ];
  return true;
}

function tcInviteesCsvEndTransaction(string $path): void
{
  $transactions =& tcInviteesCsvTransactions();
  $key = tcInviteesCsvTransactionKey($path);
  $handle = $transactions[$key]['handle'] ?? null;
  if (is_resource($handle)) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
  unset($transactions[$key]);
}

function tcInviteesCsvReadHandleRows($handle): array
{
  $rows = [];
  while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  return $rows;
}

function tcInviteesCsvReadRowsForUpdate(string $path): array
{
  if (!tcInviteesCsvBeginTransaction($path) || !is_file($path)) {
    tcInviteesCsvEndTransaction($path);
    return [];
  }
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    tcInviteesCsvEndTransaction($path);
    return [];
  }
  $rows = tcInviteesCsvReadHandleRows($handle);
  fclose($handle);
  $transactions =& tcInviteesCsvTransactions();
  $key = tcInviteesCsvTransactionKey($path);
  if (isset($transactions[$key])) {
    $transactions[$key]['baselineRowCount'] = count($rows);
  }
  return $rows;
}

function tcInviteesCsvReadRowsSnapshot(string $path): array
{
  if (!tcInviteesCsvBeginTransaction($path) || !is_file($path)) {
    tcInviteesCsvEndTransaction($path);
    return [];
  }
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    tcInviteesCsvEndTransaction($path);
    return [];
  }
  $rows = tcInviteesCsvReadHandleRows($handle);
  fclose($handle);
  tcInviteesCsvEndTransaction($path);
  return $rows;
}

function tcInviteesCsvWriteTempRows(string $tempPath, array $rows): bool
{
  $handle = fopen($tempPath, 'wb');
  if ($handle === false) {
    return false;
  }
  $ok = true;
  foreach ($rows as $row) {
    if (fputcsv($handle, is_array($row) ? $row : [], ',', '"', '\\') === false) {
      $ok = false;
      break;
    }
  }
  if ($ok) {
    $ok = fflush($handle);
    if ($ok && function_exists('fsync')) {
      $ok = fsync($handle);
    }
  }
  fclose($handle);
  return $ok;
}

function tcInviteesCsvWriteTempText(string $tempPath, string $content): bool
{
  $handle = fopen($tempPath, 'wb');
  if ($handle === false) {
    return false;
  }
  $offset = 0;
  $length = strlen($content);
  $ok = true;
  while ($offset < $length) {
    $written = fwrite($handle, substr($content, $offset));
    if (!is_int($written) || $written <= 0) {
      $ok = false;
      break;
    }
    $offset += $written;
  }
  if ($ok) {
    $ok = fflush($handle);
    if ($ok && function_exists('fsync')) {
      $ok = fsync($handle);
    }
  }
  fclose($handle);
  return $ok;
}

function tcInviteesCsvPreparedFileIsComplete(string $tempPath): bool
{
  $handle = fopen($tempPath, 'rb');
  if ($handle === false) {
    return false;
  }
  $rows = tcInviteesCsvReadHandleRows($handle);
  fclose($handle);
  if (!$rows || !is_array($rows[0] ?? null) || count($rows[0]) === 0) {
    return false;
  }
  foreach ($rows as $row) {
    if (!is_array($row)) {
      return false;
    }
  }
  return true;
}

function tcInviteesCsvRefreshBackup(string $path): bool
{
  if (!is_file($path)) {
    return true;
  }
  $backupPath = $path . '.bak';
  $backupTempPath = $backupPath . '.tmp';
  @unlink($backupTempPath);
  if (!@copy($path, $backupTempPath)) {
    return false;
  }
  $handle = fopen($backupTempPath, 'r+b');
  if ($handle === false) {
    @unlink($backupTempPath);
    return false;
  }
  $ok = true;
  if (function_exists('fsync')) {
    $ok = fsync($handle);
  }
  fclose($handle);
  if (!$ok || !tcInviteesCsvPreparedFileIsComplete($backupTempPath)) {
    @unlink($backupTempPath);
    return false;
  }
  @unlink($backupPath);
  if (!@rename($backupTempPath, $backupPath)) {
    @unlink($backupTempPath);
    return false;
  }
  return true;
}

function tcInviteesCsvReplacePreparedFile(string $path, string $tempPath): bool
{
  if (!tcInviteesCsvPreparedFileIsComplete($tempPath) || !tcInviteesCsvRefreshBackup($path)) {
    return false;
  }
  if (PHP_OS_FAMILY !== 'Windows') {
    return @rename($tempPath, $path);
  }
  $rollbackPath = $path . '.rollback';
  @unlink($rollbackPath);
  $hadOriginal = is_file($path);
  if ($hadOriginal && !@rename($path, $rollbackPath)) {
    return false;
  }
  if (@rename($tempPath, $path)) {
    @unlink($rollbackPath);
    return true;
  }
  if ($hadOriginal && is_file($rollbackPath)) {
    @rename($rollbackPath, $path);
  }
  return false;
}

function tcInviteesCsvCommitPrepared(string $path, callable $writer, bool $endTransaction = true): bool
{
  if (!tcInviteesCsvBeginTransaction($path)) {
    return false;
  }
  $dir = dirname($path);
  $tempPath = tempnam($dir, '.invitees-');
  if (!is_string($tempPath) || $tempPath === '') {
    if ($endTransaction) tcInviteesCsvEndTransaction($path);
    return false;
  }
  $ok = false;
  try {
    $ok = $writer($tempPath) === true && tcInviteesCsvReplacePreparedFile($path, $tempPath);
  } finally {
    if (is_file($tempPath)) {
      @unlink($tempPath);
    }
    if ($endTransaction) {
      tcInviteesCsvEndTransaction($path);
    }
  }
  return $ok;
}

function tcInviteesCsvCommitRows(string $path, array $rows, bool $endTransaction = true): bool
{
  if (!$rows || !is_array($rows[0] ?? null) || count($rows[0]) === 0) {
    if ($endTransaction) tcInviteesCsvEndTransaction($path);
    return false;
  }
  $transactions =& tcInviteesCsvTransactions();
  $key = tcInviteesCsvTransactionKey($path);
  $baselineRowCount = $transactions[$key]['baselineRowCount'] ?? null;
  if (is_int($baselineRowCount) && $baselineRowCount > 1 && count($rows) < $baselineRowCount) {
    if ($endTransaction) tcInviteesCsvEndTransaction($path);
    return false;
  }
  return tcInviteesCsvCommitPrepared(
    $path,
    static fn(string $tempPath): bool => tcInviteesCsvWriteTempRows($tempPath, $rows),
    $endTransaction
  );
}

function tcInviteesCsvReplaceText(string $path, string $content, bool $endTransaction = true): bool
{
  return tcInviteesCsvCommitPrepared(
    $path,
    static fn(string $tempPath): bool => tcInviteesCsvWriteTempText($tempPath, $content),
    $endTransaction
  );
}

register_shutdown_function(static function (): void {
  $transactions = array_values(tcInviteesCsvTransactions());
  foreach ($transactions as $transaction) {
    $path = (string)($transaction['path'] ?? '');
    if ($path !== '') {
      tcInviteesCsvEndTransaction($path);
    }
  }
});
