<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-period-exports.php';
require_once dirname(__DIR__) . '/api/lib/egm-game-exports.php';

function giftCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$entry = ['should_get_gift'=>1, 'entered_date'=>'2026-10-10', 'entered_time'=>'08:30:00',
    'quit_date'=>null, 'quit_time'=>null, 'correct_presence'=>0, 'fake_presence'=>0];
$checkout = array_replace($entry, ['quit_date'=>'2026-10-10', 'quit_time'=>'12:00:00', 'correct_presence'=>1]);
$cases = [
    'check-in only'=>[$entry, false],
    'successful checkout'=>[$checkout, true],
    'denied gift after checkout'=>[array_replace($checkout, ['should_get_gift'=>0]), false],
    'manager-approved walk-in before checkout'=>[array_replace($entry, ['invitation_source'=>'walk_in']), false],
    'manager-approved walk-in after checkout'=>[array_replace($checkout, ['invitation_source'=>'walk_in']), true],
    'forced checkout'=>[array_replace($checkout, ['fake_presence'=>1, 'correct_presence'=>0]), false],
    'incomplete checkout'=>[array_replace($checkout, ['quit_time'=>'']), false],
    'checkout without entry'=>[array_replace($checkout, ['entered_date'=>null]), false],
    'other period with no attendance'=>[['should_get_gift'=>1], false],
    'draw denied independently'=>[array_replace($checkout, ['draw_eligible'=>0]), true],
    'attendance reset'=>[array_replace($entry, ['entered_date'=>null, 'entered_time'=>null, 'should_get_gift'=>0]), false],
];
$game = ['id'=>'g1', 'name'=>'آزمایش', 'has_levels'=>false, 'rooms'=>[]];
$team = egmRefMonitorTeamView(['id'=>1,'created_at'=>'2026-10-10'], ['name'=>'تیم', 'members'=>[['id'=>1,'name'=>'مهمان']]]);
foreach ($cases as $label => [$row, $eligible]) {
    giftCheck(egmBenefitsGiftEligible($row) === $eligible, $label . ': incorrect final eligibility');
    $value = $eligible ? 'بله' : 'خیر';
    giftCheck(egmPeriodExportMainRecord($row)['دریافت هدیه'] === $value, $label . ': guest export mismatch');
    if (!empty($row['entered_date']) && !empty($row['entered_time'])) {
        giftCheck(egmPeriodExportTicketRecords([$row], [])[0]['دریافت هدیه'] === $value, $label . ': ticket export mismatch');
    }
    giftCheck(egmGameExportRecords($game, [$team], [1=>$row], '01', 'بازه')[0]['دریافت هدیه'] === $value, $label . ': game export mismatch');
}
echo "PASS: gift approval requires real checkout; guest, ticket and game exports agree across approval, rejection, walk-in, forced, reset and period scenarios.\n";
