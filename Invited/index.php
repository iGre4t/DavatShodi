<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/common.php';
require_once dirname(__DIR__) . '/api/lib/egm-invite-card-routes.php';
require_once dirname(__DIR__) . '/api/lib/egm-database-runtime.php';

function invitedNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>کارت دعوت پیدا نشد</title><style>html,body{height:100%;margin:0}body{display:grid;place-items:center;'
        . 'font-family:Tahoma,Arial,sans-serif;background:#111827;color:#fff;text-align:center;padding:24px;box-sizing:border-box}</style>'
        . '<p>این کارت دعوت پیدا نشد یا هنوز آماده نشده است.</p>';
    exit;
}

try {
    $inviteCode = trim((string)($_GET['code'] ?? ''));
    if (preg_match('/^[A-Za-z0-9]{8,191}$/D', $inviteCode) !== 1) {
        invitedNotFound();
    }
    $projectRoot = dirname(__DIR__);
    $pdo = connectDatabase(loadConfig($projectRoot . '/api/config.php'));
    if (!$pdo instanceof PDO) {
        invitedNotFound();
    }
    $route = egmInviteCardFindRoute($pdo, $inviteCode);
    if (!is_array($route) || (string)($route['status'] ?? '') !== 'generated') {
        invitedNotFound();
    }
    $egmCode = normalizeEgmInstanceCode($route['egm_code'] ?? '');
    $periodCode = trim((string)($route['period_code'] ?? ''));
    $registry = $egmCode !== '' ? findEgmRegistryByCode($pdo, $egmCode) : null;
    if (!is_array($registry) || $periodCode === '') {
        invitedNotFound();
    }
    $tables = egmInstanceTableNames($egmCode);
    $periodsTable = (string)$tables['user_periods'];
    if (!egmInstanceTableExists($pdo, $periodsTable)) {
        invitedNotFound();
    }
    $statement = $pdo->prepare(
        "SELECT `invite_card_file` FROM `{$periodsTable}` WHERE `user_id` = :user_id "
        . "AND `period_code` = :period_code AND `invite_card_code` = :invite_code "
        . "AND `invite_card_generated_at` IS NOT NULL LIMIT 1"
    );
    $statement->execute([
        ':user_id' => (int)($route['user_id'] ?? 0),
        ':period_code' => $periodCode,
        ':invite_code' => $inviteCode,
    ]);
    $storedPath = trim(str_replace('\\', '/', (string)$statement->fetchColumn()), '/');
    $registryDirectory = trim(str_replace('\\', '/', (string)$registry['directory']), '/');
    if ($storedPath === '' || $registryDirectory === '') {
        invitedNotFound();
    }
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/Invited/index.php'));
    $projectBase = rtrim(dirname(dirname($scriptName)), '/');

    $databaseAsset = egmInviteCardDatabaseAssetRelative(
        $storedPath,
        $registryDirectory,
        $inviteCode
    );
    if ($databaseAsset !== '') {
        // One-time repair for cards created by the short-lived DB-blob
        // implementation: restore the JPG to disk, update the stored paths,
        // then remove the blob. The public invite code never changes.
        $expectedPath = $registryDirectory . '/' . $databaseAsset;
        $destination = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $expectedPath);
        $destinationDirectory = dirname($destination);
        if (!is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0775, true)
            && !is_dir($destinationDirectory)) {
            invitedNotFound();
        }
        if (!is_file($destination)) {
            $asset = egmDatabaseRuntimeRead($destination);
            $content = is_array($asset) ? ($asset['content'] ?? null) : null;
            $imageInfo = is_string($content) && $content !== '' ? @getimagesizefromstring($content) : false;
            if (!is_string($content) || !is_array($imageInfo)
                || strtolower((string)($imageInfo['mime'] ?? '')) !== 'image/jpeg') {
                invitedNotFound();
            }
            $staging = $destination . '.restore-' . bin2hex(random_bytes(6));
            $written = file_put_contents($staging, $content, LOCK_EX);
            $published = $written === strlen($content) && @rename($staging, $destination);
            if (!$published && !is_file($destination)) {
                @unlink($staging);
                invitedNotFound();
            }
            if (!$published) @unlink($staging);
            @chmod($destination, 0644);
        }
        if (!is_file($destination) || (int)filesize($destination) < 1) {
            invitedNotFound();
        }
        $updatePath = $pdo->prepare(
            "UPDATE `{$periodsTable}` SET `invite_card_file` = :file WHERE `user_id` = :user_id "
            . "AND `period_code` = :period_code AND `invite_card_code` = :invite_code"
        );
        $updatePath->execute([
            ':file' => $expectedPath,
            ':user_id' => (int)($route['user_id'] ?? 0),
            ':period_code' => $periodCode,
            ':invite_code' => $inviteCode,
        ]);
        egmInviteCardUpsertRoute(
            $pdo,
            $inviteCode,
            $egmCode,
            $periodCode,
            (int)($route['user_id'] ?? 0),
            $expectedPath,
            'generated'
        );
        try {
            egmDatabaseRuntimeDelete($destination);
        } catch (Throwable $cleanupError) {
            error_log('Invite card DB cleanup failed: ' . $cleanupError->getMessage());
        }
        $storedPath = $expectedPath;
    }

    // Preserve compatibility with pre-database cards that still exist as
    // physical files in an instance's InviteCards directory.
    $expectedPath = $registryDirectory . '/InviteCards/' . $inviteCode . '.jpg';
    if (!hash_equals($expectedPath, $storedPath)) {
        invitedNotFound();
    }
    $absolute = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
    $expectedDirectory = realpath($projectRoot . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $registryDirectory)
        . DIRECTORY_SEPARATOR . 'InviteCards');
    if (!is_string($absolute) || !is_string($expectedDirectory) || !is_file($absolute)
        || !str_starts_with(strtolower($absolute), strtolower($expectedDirectory . DIRECTORY_SEPARATOR))) {
        invitedNotFound();
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $storedPath)));
    $assetVersion = max(1, (int)filemtime($absolute));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Location: ' . ($projectBase === '' ? '' : $projectBase) . '/' . $encodedPath . '?v=' . $assetVersion, true, 302);
    exit;
} catch (Throwable $error) {
    error_log('Invite card route failed: ' . $error->getMessage());
    invitedNotFound();
}
