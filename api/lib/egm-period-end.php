<?php
declare(strict_types=1);

/**
 * Normalize the administrator's decision for guests who entered a period but
 * did not record a quit before the period was manually ended.
 */
function egmPeriodEndNormalizeResolution(string $value): string
{
    $normalized = strtolower(trim($value));
    if (!in_array($normalized, ['correct_presence', 'fake_presence'], true)) {
        throw new InvalidArgumentException('نحوه تعیین وضعیت مهمانان بدون خروج معتبر نیست.');
    }
    return $normalized;
}

/**
 * Classify only unresolved guests who have a real entry timestamp and no quit
 * timestamp. Existing Correct/Fake Presence decisions are deliberately kept.
 * No invitation, identity, entry time, or quit time is changed.
 *
 * @return array{pending:int,classified:int,resolution:string}
 */
function egmPeriodEndClassifyOpenAttendance(
    PDO $pdo,
    string $userPeriodsTable,
    string $periodCode,
    string $resolution
): array {
    $periodCode = trim($periodCode);
    if ($periodCode === '') {
        throw new InvalidArgumentException('کد بازه نامعتبر است.');
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $userPeriodsTable) !== 1) {
        throw new InvalidArgumentException('جدول سوابق حضور نامعتبر است.');
    }
    $resolution = egmPeriodEndNormalizeResolution($resolution);

    $where = "`period_code` = :period_code "
        . "AND NULLIF(TRIM(COALESCE(`entered_date`, '')), '') IS NOT NULL "
        . "AND NULLIF(TRIM(COALESCE(`entered_time`, '')), '') IS NOT NULL "
        . "AND NULLIF(TRIM(COALESCE(`quit_date`, '')), '') IS NULL "
        . "AND NULLIF(TRIM(COALESCE(`quit_time`, '')), '') IS NULL "
        . "AND COALESCE(`correct_presence`, 0) = 0 "
        . "AND COALESCE(`fake_presence`, 0) = 0";

    $countStatement = $pdo->prepare("SELECT COUNT(*) FROM `{$userPeriodsTable}` WHERE {$where}");
    $countStatement->execute([':period_code' => $periodCode]);
    $pending = max(0, (int)$countStatement->fetchColumn());

    if ($pending === 0) {
        return ['pending' => 0, 'classified' => 0, 'resolution' => $resolution];
    }

    $correctPresence = $resolution === 'correct_presence' ? 1 : 0;
    $fakePresence = $resolution === 'fake_presence' ? 1 : 0;
    $updateStatement = $pdo->prepare(
        "UPDATE `{$userPeriodsTable}` SET `correct_presence` = :correct_presence, `fake_presence` = :fake_presence "
        . "WHERE {$where}"
    );
    $updateStatement->execute([
        ':correct_presence' => $correctPresence,
        ':fake_presence' => $fakePresence,
        ':period_code' => $periodCode,
    ]);

    return [
        'pending' => $pending,
        'classified' => max(0, $updateStatement->rowCount()),
        'resolution' => $resolution,
    ];
}
