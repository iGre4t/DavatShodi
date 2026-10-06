<?php
declare(strict_types=1);

function egmBenefitsDecision(string $choice): array
{
    return match ($choice) {
        'both' => ['gift'=>1, 'draw'=>1, 'label'=>'هدیه و قرعه‌کشی'],
        'gift' => ['gift'=>1, 'draw'=>0, 'label'=>'فقط هدیه'],
        'draw' => ['gift'=>0, 'draw'=>1, 'label'=>'فقط قرعه‌کشی'],
        'deny' => ['gift'=>0, 'draw'=>0, 'label'=>'بدون هدیه و قرعه‌کشی'],
        default => throw new InvalidArgumentException('انتخاب مدیر معتبر نیست.'),
    };
}

/** Apply a pending manager decision when a previously unknown guest is registered. */
function egmBenefitsApplyEntry(array $context, int $userId, string $periodCode): void
{
    $pdo = $context['pdo'];
    $table = $context['tables']['user_periods'];
    $pdo->prepare("UPDATE `{$table}` SET should_get_gift=IF(is_uninvited_guest=0 AND LOWER(COALESCE(invitation_source,''))<>'walk_in',1,0) WHERE user_id=? AND period_code=? AND benefits_reviewed_at IS NULL")
        ->execute([$userId, $periodCode]);
    if (!egmInstanceTableExists($pdo, 'egm_telegram_decisions')) return;
    $users = $context['tables']['users'];
    $find = $pdo->prepare("SELECT d.*,r.snapshot AS report_snapshot FROM egm_telegram_decisions d JOIN egm_telegram_reports r ON r.id=d.report_id JOIN `{$users}` u ON u.id=? WHERE d.egm_code=? AND d.period_code=? AND (d.user_id=u.id OR BINARY d.guest_code=BINARY u.national_id OR BINARY d.guest_code=BINARY u.work_id) ORDER BY d.decided_at DESC,d.id DESC LIMIT 1");
    $find->execute([$userId, $context['code'], $periodCode]);
    $decision = $find->fetch(PDO::FETCH_ASSOC);
    if (!$decision) return;
    $snapshot = json_decode((string)($decision['report_snapshot'] ?? ''), true) ?: [];
    $provider = ($snapshot['decision_provider'] ?? '') === 'bale' ? 'bale' : 'telegram';
    $pdo->prepare("UPDATE `{$table}` SET should_get_gift=?,draw_eligible=?,benefits_reviewed_at=?,benefits_reviewed_by=? WHERE user_id=? AND period_code=?")
        ->execute([$decision['gift'], $decision['draw'], $decision['decided_at'], $provider . ':' . $decision['admin_id'], $userId, $periodCode]);
}

function egmBenefitsDrawAllowed(array $row): bool
{
    if (array_key_exists('draw_eligible', $row) && $row['draw_eligible'] !== null) return (int)$row['draw_eligible'] === 1;
    return empty($row['period_is_uninvited_guest']) && strtolower(trim((string)($row['invitation_source'] ?? ''))) !== 'walk_in';
}

function egmBenefitsDrawEntryWindow(array $period): ?array
{
    if (!egmCheckInBool($period['prizeEntryWindowEnabled'] ?? ($period['prize_entry_window_enabled'] ?? false))) return null;
    $values = [
        (string)($period['prizeEntryStartDate'] ?? ($period['prize_entry_start_date'] ?? '')),
        (string)($period['prizeEntryStartTime'] ?? ($period['prize_entry_start_time'] ?? '')),
        (string)($period['prizeEntryEndDate'] ?? ($period['prize_entry_end_date'] ?? '')),
        (string)($period['prizeEntryEndTime'] ?? ($period['prize_entry_end_time'] ?? '')),
    ];
    if (in_array('', array_map('trim', $values), true)) throw new InvalidArgumentException('زمان واجدان شرایط قرعه‌کشی این بازه کامل نیست.');
    $timezone = new DateTimeZone('Asia/Tehran');
    $start = egmCheckInDateTime($values[0], $values[1], false, $timezone);
    $end = egmCheckInDateTime($values[2], $values[3], true, $timezone);
    if (!$start || !$end || $end <= $start) throw new InvalidArgumentException('زمان واجدان شرایط قرعه‌کشی این بازه معتبر نیست.');
    return [$start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')];
}

/** Explicit manager decisions override automatic walk-in and entry-time rules. */
function egmBenefitsDrawState(array $row, array $period): array
{
    $window = egmBenefitsDrawEntryWindow($period);
    $hasEntry = trim((string)($row['entered_date'] ?? '')) !== '' && trim((string)($row['entered_time'] ?? '')) !== '';
    $minute = trim((string)($row['entered_date'] ?? '')) . ' ' . substr(trim((string)($row['entered_time'] ?? '')), 0, 5);
    $outside = $hasEntry && $window !== null && ($minute < $window[0] || $minute > $window[1]);
    $decision = $row['draw_eligible'] ?? null;
    $reason = '';
    if ($hasEntry) {
        if ($decision !== null) {
            $reason = (int)$decision === 1 ? 'شرکت در قرعه‌کشی با تأیید مدیر.' : 'این مهمان با تصمیم مدیر در قرعه‌کشی شرکت نمی‌کند.';
        } elseif ($outside) {
            $reason = 'این مهمان خارج از زمان مجاز قرعه‌کشی وارد شده و در قرعه‌کشی شرکت نمی‌کند.';
        } elseif (!egmBenefitsDrawAllowed($row)) {
            $reason = 'شرکت این مهمان ناخوانده در قرعه‌کشی نیاز به تأیید مدیر دارد.';
        }
    }
    return [
        'allowed' => $hasEntry && egmBenefitsDrawAllowed($row) && ($decision !== null || !$outside),
        'time_excluded' => $outside && $decision === null,
        'reason' => $reason,
    ];
}

/** Read the saved decision after registration, including decisions made before entry. */
function egmBenefitsEntryDrawState(array $context, int $userId, array $period): array
{
    $table = $context['tables']['user_periods'];
    $query = $context['pdo']->prepare("SELECT *,is_uninvited_guest AS period_is_uninvited_guest FROM `{$table}` WHERE user_id=? AND period_code=? LIMIT 1");
    $query->execute([$userId, egmCheckInPeriodCode($period)]);
    return egmBenefitsDrawState($query->fetch(PDO::FETCH_ASSOC) ?: [], $period);
}
