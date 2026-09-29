<?php
declare(strict_types=1);
require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/invitees_special_access.php';
header('Content-Type: application/json; charset=utf-8');
$user = requireTabPermissionFromSession('task-club', true);
$access = tcInviteesSpecialAccessForPanelUser($user, __DIR__ . '/tasks/task-access.json');
if (!userHasPermissionId($user, 'task-club:invitees') || empty($access['manageInvitees']) || empty($access['revealPassword'])) {
    denyPanelAccess(403, 'You do not have permission to regenerate passwords.', true);
}
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); exit; }
$input = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : [];
if ($method === 'POST' && (!is_array($input) || !tcSecurityIsValidCsrfToken(tcSecurityReadCsrfFromRequest($input, 'csrf')))) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
try {
    $context = tcDatabaseRuntimeContextForPath(__DIR__ . '/TC Event/Invitees mapped.csv');
    if ($context === null) throw new RuntimeException('Password regeneration requires database storage.');
    require_once dirname(__DIR__, 2) . '/api/lib/tc-password-generation.php';
    $pdo = $context['pdo'];
    $table = $context['tables']['users'];
    $lock = 'tc_regen_' . substr(hash('sha256', $context['code']), 0, 40);
    $query = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
    $query->execute([':name' => $lock]);
    if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Another regeneration request is running.');
    $job = tcInstanceReadData($pdo, $context['code'], 'password_regeneration_job', []);
    $job = is_array($job) ? $job : [];
    $action = $input['action'] ?? 'status';
    if ($method === 'POST' && $action === 'start' && ($job['status'] ?? '') !== 'preparing') {
        $length = (int)($input['length'] ?? 8);
        $type = (string)($input['type'] ?? 'numeric');
        tcGenerateInviteePassword($length, $type);
        tcPasswordVaultKey();
        $users = $pdo->query("SELECT id,password_hash FROM `{$table}` WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $job = ['status' => 'preparing', 'length' => $length, 'type' => $type, 'users' => $users, 'prepared' => [], 'total' => count($users)];
        tcInstanceWriteData($pdo, $context['code'], 'password_regeneration_job', $job);
    } elseif ($method === 'POST' && $action === 'process' && ($job['status'] ?? '') === 'preparing') {
        foreach (array_slice($job['users'], count($job['prepared']), 25) as $row) {
            $password = tcGenerateInviteePassword($job['length'], $job['type']);
            $job['prepared'][] = ['id' => $row['id'], 'old_hash' => $row['password_hash'], 'hash' => password_hash($password, PASSWORD_DEFAULT), 'encrypted' => tcPasswordVaultEncrypt($password)];
        }
        if (count($job['prepared']) === $job['total']) {
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE `{$table}` SET password_hash=:hash,state_json=JSON_SET(COALESCE(state_json,'{}'),'$.password_encrypted',:encrypted) WHERE id=:id AND password_hash <=> :old_hash");
            foreach ($job['prepared'] as $row) {
                $update->execute([':hash' => $row['hash'], ':encrypted' => $row['encrypted'], ':id' => $row['id'], ':old_hash' => $row['old_hash']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('An invitee password changed during regeneration. No new passwords were applied.');
            }
            $job = ['status' => 'complete', 'total' => $job['total'], 'completed_at' => time()];
            tcInstanceWriteData($pdo, $context['code'], 'password_regeneration_job', $job);
            $pdo->commit();
        } else tcInstanceWriteData($pdo, $context['code'], 'password_regeneration_job', $job);
    }
    echo json_encode(['status' => 'ok', 'prepared' => ($job['status'] ?? '') === 'complete' ? ($job['total'] ?? 0) : count($job['prepared'] ?? []), 'total' => $job['total'] ?? 0, 'done' => ($job['status'] ?? '') === 'complete']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('TaskClub password regeneration: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Regeneration paused. Retry to resume; existing passwords are unchanged.']);
} finally {
    if (isset($pdo, $lock)) {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lock]);
    }
}