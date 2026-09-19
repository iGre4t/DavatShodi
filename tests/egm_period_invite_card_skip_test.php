<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-period-invite-cards.php';
function skipAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = connectDatabase(loadConfig(dirname(__DIR__) . '/api/config.php'));
skipAssert($pdo instanceof PDO, 'Database unavailable');
do { $code = '96' . random_int(100000, 999999); $tables = egmInstanceTableNames($code); }
while (egmInstanceTableExists($pdo, $tables['data']));
$context = ['pdo'=>$pdo, 'code'=>$code, 'tables'=>$tables];
try {
    ensureEgmInstanceTables($pdo, $code);
    egmInstanceWriteData($pdo, $code, 'invite_card', [
        'imageData'=>'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        'qrRect'=>['x'=>10,'y'=>10,'width'=>20,'height'=>20], 'textRect'=>['x'=>10,'y'=>40,'width'=>80,'height'=>20]
    ]);
    $insert = $pdo->prepare("INSERT INTO `{$tables['users']}` (work_id,first_name,national_id,source_row) VALUES (:work,:name,:national,1)");
    $ids = [];
    foreach ([['8888','Valid','1234567890'],['invalid-test-' . $code,'Invalid',null]] as [$work,$name,$national]) {
        $insert->execute([':work'=>$work,':name'=>$name,':national'=>$national]);
        $ids[] = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO `{$tables['user_periods']}` (user_id,period_code) VALUES (" . end($ids) . ",'001')");
    }
    $report = egmPeriodInviteCardsPrepare($context, '001', false);
    skipAssert(($report['validation_error'] ?? false) && count($report['problems']) === 1, 'Missing validation report');
    skipAssert($report['problems'][0]['name'] === 'Invalid', 'Wrong problematic guest');
    $prepared = egmPeriodInviteCardsPrepare($context, '001', false, true);
    skipAssert($prepared['skipped'] === 1 && $prepared['pending'] === 1, 'Skip counts incorrect');
    $batch = egmPeriodInviteCardsNextBatch($context, '001', 10);
    skipAssert(count($batch['rows']) === 1 && (int)$batch['rows'][0]['id'] === $ids[0], 'Invalid guest included in batch');
    skipAssert((int)$pdo->query("SELECT COUNT(*) FROM `{$tables['user_periods']}`")->fetchColumn() === 2, 'Skipping deleted invitations');
    $pdo->exec("UPDATE `{$tables['users']}` SET work_id='7777' WHERE id=" . $ids[1]);
    $repaired = egmPeriodInviteCardsPrepare($context, '001', false);
    skipAssert($repaired['skipped'] === 0 && $repaired['pending'] === 2, 'Corrected guest remained skipped');
    $pdo->exec("UPDATE `{$tables['user_periods']}` SET invite_card_generated_at=NOW(), invite_card_file='test.jpg'");
    skipAssert(egmPeriodInviteCardsNextBatch($context, '001', 10)['rows'] === [], 'Completed rows returned again');
    $pdo->exec("UPDATE `{$tables['users']}` SET work_id='9195145245' WHERE id=" . $ids[1]);
    $preview = egmPeriodPersonnelCopy($context, '001', false, '', 'test-admin');
    skipAssert(count($preview['eligible']) === 1 && count($preview['skipped']) === 1, 'Transfer did not protect existing national IDs');
    skipAssert($pdo->query("SELECT national_id FROM `{$tables['users']}` WHERE id=" . $ids[1])->fetchColumn() === null, 'Preview mutated guest');
    $copied = egmPeriodPersonnelCopy($context, '001', true, $preview['token'], 'test-admin');
    skipAssert($copied['copied'] === 1, 'Confirmed transfer failed');
    $user = $pdo->query("SELECT national_id,work_id FROM `{$tables['users']}` WHERE id=" . $ids[1])->fetch(PDO::FETCH_ASSOC);
    skipAssert($user['national_id'] === '9195145245' && $user['work_id'] === '9195145245', 'Personnel code was lost or not copied');
    skipAssert($pdo->query("SELECT national_id FROM `{$tables['users']}` WHERE id=" . $ids[0])->fetchColumn() === '1234567890', 'Existing national ID overwritten');
    $conflicts = egmPeriodPersonnelCopyPlan([
        ['id'=>1,'national_id'=>null,'work_id'=>'9195145245'],
        ['id'=>2,'national_id'=>'9195145245','work_id'=>'1234'],
        ['id'=>3,'national_id'=>null,'work_id'=>'9120078237'],
        ['id'=>4,'national_id'=>null,'work_id'=>'9120078237']
    ], [1,3,4]);
    skipAssert($conflicts['eligible'] === [] && count($conflicts['skipped']) === 3, 'Identity collisions accepted');
    echo "EGM period card validation/skip/resume test passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (egmInstanceTableExists($pdo, EGM_INVITE_CARD_ROUTES_TABLE)) {
        $delete = $pdo->prepare('DELETE FROM `' . EGM_INVITE_CARD_ROUTES_TABLE . '` WHERE egm_code=:code');
        $delete->execute([':code'=>$code]);
    }
    dropEgmInstanceTables($pdo, $code);
    unset($_SESSION['egm_card_skips'][$code]);
}
