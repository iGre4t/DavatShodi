<?php
declare(strict_types=1);

/**
 * Transactional storage for every "Invitees mapped.csv" file.
 *
 * Writers hold a sidecar lock from the initial read until commit. Commits are
 * prepared completely in the same directory and then replace the live file,
 * so readers see either the old complete file or the new complete file.
 */

function egmInviteesCsvIsManagedPath(string $path): bool
{
  return strcasecmp(basename($path), 'Invitees mapped.csv') === 0;
}

function egmInviteesCsvTransactionKey(string $path): string
{
  $normalized = str_replace('\\', '/', $path);
  return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function &egmInviteesCsvTransactions(): array
{
  if (!isset($GLOBALS['egm_invitees_csv_transactions']) || !is_array($GLOBALS['egm_invitees_csv_transactions'])) {
    $GLOBALS['egm_invitees_csv_transactions'] = [];
  }
  return $GLOBALS['egm_invitees_csv_transactions'];
}

function egmInviteesCsvRecoverInterruptedReplace(string $path): void
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

function egmInviteesCsvBeginTransaction(string $path): bool
{
  if (!egmInviteesCsvIsManagedPath($path)) {
    return false;
  }
  $transactions =& egmInviteesCsvTransactions();
  $key = egmInviteesCsvTransactionKey($path);
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
  egmInviteesCsvRecoverInterruptedReplace($path);
  $transactions[$key] = [
    'handle' => $handle,
    'path' => $path,
    'baselineRowCount' => null
  ];
  return true;
}

function egmInviteesCsvEndTransaction(string $path): void
{
  $transactions =& egmInviteesCsvTransactions();
  $key = egmInviteesCsvTransactionKey($path);
  $handle = $transactions[$key]['handle'] ?? null;
  if (is_resource($handle)) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
  unset($transactions[$key]);
}

function egmInviteesCsvReadHandleRows($handle): array
{
  $rows = [];
  while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
    $rows[] = is_array($row) ? $row : [];
  }
  return $rows;
}

function egmInviteesCsvReadRowsForUpdate(string $path): array
{
  if (!egmInviteesCsvBeginTransaction($path) || !is_file($path)) {
    egmInviteesCsvEndTransaction($path);
    return [];
  }
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    egmInviteesCsvEndTransaction($path);
    return [];
  }
  $rows = egmInviteesCsvReadHandleRows($handle);
  fclose($handle);
  $transactions =& egmInviteesCsvTransactions();
  $key = egmInviteesCsvTransactionKey($path);
  if (isset($transactions[$key])) {
    $transactions[$key]['baselineRowCount'] = count($rows);
  }
  return $rows;
}

function egmInviteesCsvReadRowsSnapshot(string $path): array
{
  if (!egmInviteesCsvBeginTransaction($path) || !is_file($path)) {
    egmInviteesCsvEndTransaction($path);
    return [];
  }
  $handle = fopen($path, 'rb');
  if ($handle === false) {
    egmInviteesCsvEndTransaction($path);
    return [];
  }
  $rows = egmInviteesCsvReadHandleRows($handle);
  fclose($handle);
  egmInviteesCsvEndTransaction($path);
  return $rows;
}

function egmInviteesCsvWriteTempRows(string $tempPath, array $rows): bool
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

function egmInviteesCsvWriteTempText(string $tempPath, string $content): bool
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

function egmInviteesCsvPreparedFileIsComplete(string $tempPath): bool
{
  $handle = fopen($tempPath, 'rb');
  if ($handle === false) {
    return false;
  }
  $rows = egmInviteesCsvReadHandleRows($handle);
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

function egmInviteesCsvRefreshBackup(string $path): bool
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
  if (!$ok || !egmInviteesCsvPreparedFileIsComplete($backupTempPath)) {
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

function egmInviteesCsvReplacePreparedFile(string $path, string $tempPath): bool
{
  if (!egmInviteesCsvPreparedFileIsComplete($tempPath) || !egmInviteesCsvRefreshBackup($path)) {
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

function egmInviteesCsvCommitPrepared(string $path, callable $writer, bool $endTransaction = true): bool
{
  if (!egmInviteesCsvBeginTransaction($path)) {
    return false;
  }
  $dir = dirname($path);
  $tempPath = tempnam($dir, '.invitees-');
  if (!is_string($tempPath) || $tempPath === '') {
    if ($endTransaction) egmInviteesCsvEndTransaction($path);
    return false;
  }
  $ok = false;
  try {
    $ok = $writer($tempPath) === true && egmInviteesCsvReplacePreparedFile($path, $tempPath);
  } finally {
    if (is_file($tempPath)) {
      @unlink($tempPath);
    }
    if ($endTransaction) {
      egmInviteesCsvEndTransaction($path);
    }
  }
  return $ok;
}

function egmInviteesCsvCommitRows(string $path, array $rows, bool $endTransaction = true): bool
{
  if (!$rows || !is_array($rows[0] ?? null) || count($rows[0]) === 0) {
    if ($endTransaction) egmInviteesCsvEndTransaction($path);
    return false;
  }
  $transactions =& egmInviteesCsvTransactions();
  $key = egmInviteesCsvTransactionKey($path);
  $baselineRowCount = $transactions[$key]['baselineRowCount'] ?? null;
  if (is_int($baselineRowCount) && $baselineRowCount > 1 && count($rows) < $baselineRowCount) {
    if ($endTransaction) egmInviteesCsvEndTransaction($path);
    return false;
  }
  return egmInviteesCsvCommitPrepared(
    $path,
    static fn(string $tempPath): bool => egmInviteesCsvWriteTempRows($tempPath, $rows),
    $endTransaction
  );
}

function egmInviteesCsvReplaceText(string $path, string $content, bool $endTransaction = true): bool
{
  return egmInviteesCsvCommitPrepared(
    $path,
    static fn(string $tempPath): bool => egmInviteesCsvWriteTempText($tempPath, $content),
    $endTransaction
  );
}

register_shutdown_function(static function (): void {
  $transactions = array_values(egmInviteesCsvTransactions());
  foreach ($transactions as $transaction) {
    $path = (string)($transaction['path'] ?? '');
    if ($path !== '') {
      egmInviteesCsvEndTransaction($path);
    }
  }
});
