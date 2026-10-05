<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-period-draws.php';
require_once dirname(__DIR__) . '/api/lib/egm-database-runtime.php';
require_once dirname(__DIR__) . '/api/lib/tab-permissions.php';
function checkDraw(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectsDraw(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException $error) { return; }
    throw new RuntimeException('Expected draw operation to be rejected');
}
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
try {
    checkDraw(egmPeriodDrawCanAccess(
        ['mission_dir' => dirname(__DIR__) . '/tests/nonexistent-egm'],
        ['code' => 'admin', 'permissions' => ['event-guest-manager', 'event-guest-manager:manage-tasks']],
        '001'
    ), 'Manager can access draws without a task-access file');
} finally {
    restore_error_handler();
}
checkDraw($warnings === [], 'Missing task-access file must not emit warnings into JSON');
$rows = [
    ['user_id'=>1, 'work_id'=>'۱۲۳۴۵۶', 'guest_number'=>'0001', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:00', 'user_is_uninvited_guest'=>1],
    ['user_id'=>2, 'guest_number'=>'0098', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:00', 'period_is_uninvited_guest'=>1],
    ['user_id'=>3, 'entered_date'=>'', 'entered_time'=>''],
    ['user_id'=>4, 'guest_number'=>'0004', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:00', 'quit_time'=>'11:00', 'invitation_source'=>'walk_in'],
];
$settings = ['name'=>'Test', 'winnerLimit'=>3, 'prizeName'=>'Gift', 'includeEntered'=>true, 'includeWalkIns'=>false];
$draws=[];
$id=egmPeriodDrawAction($draws,'create',$settings,[],'admin')['draw']['id'];
$state=egmPeriodDrawAction($draws,'state',['id'=>$id],$rows,'admin');
checkDraw(count($state['eligibleParticipants'])===1, 'Only invited entered guests; ignore global walk-in history');
checkDraw($state['eligibleParticipants'][0]['code']==='0001', 'Draw displays the EGM guest number');
$windowRows = [
    ['user_id'=>10, 'guest_number'=>'0010', 'entered_date'=>'2026-09-29', 'entered_time'=>'09:59:59'],
    ['user_id'=>11, 'guest_number'=>'0011', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:00:00'],
    ['user_id'=>12, 'guest_number'=>'0012', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:10:59'],
    ['user_id'=>13, 'guest_number'=>'0013', 'entered_date'=>'2026-09-29', 'entered_time'=>'10:11:00'],
];
$window = ['prizeEntryWindowEnabled'=>true, 'prizeEntryStartDate'=>'2026-09-29', 'prizeEntryStartTime'=>'10:00', 'prizeEntryEndDate'=>'2026-09-29', 'prizeEntryEndTime'=>'10:10'];
checkDraw(count(egmPeriodDrawEligible($windowRows, $draws[$id]))===4, 'Disabled prize window includes all entered guests');
checkDraw(array_column(egmPeriodDrawEligible($windowRows, $draws[$id], $window), 'key')===['11','12'], 'Prize window includes only entries from start through end minute');
rejectsDraw(fn()=>egmPeriodDrawEligible($windowRows, $draws[$id], ['prizeEntryWindowEnabled'=>true]));
$draw=$draws[$id]; $draw['includeEntered']=false; $draw['includeWalkIns']=true;
checkDraw(count(egmPeriodDrawEligible($rows,$draw))===2,'Walk-ins include guests who have subsequently exited');
$draw['includeEntered']=true;
checkDraw(count(egmPeriodDrawEligible(array_merge($rows,[$rows[0]]),$draw))===3,'Both groups deduplicate guest identity');
rejectsDraw(fn()=>egmPeriodDrawSettings(array_replace($settings,['includeEntered'=>false])));
$candidate=egmPeriodDrawAction($draws,'roll',['id'=>$id],$rows,'admin')['participant'];
checkDraw($candidate['key']==='1','Roll chooses only eligible guest');
rejectsDraw(function() use (&$draws,$id,$rows) { egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'2'],$rows,'admin'); });
rejectsDraw(function() use (&$draws,$id,$rows) { egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'1'],$rows,'other'); });
$state=egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'1'],$rows,'admin');
checkDraw(count($state['winners'])===1 && $state['eligibleCount']===0,'Winner saved and excluded');
checkDraw(!isset($state['draw']['pending']) && !isset($state['level']['potSettings']['pending']), 'Pending candidates stay private');
rejectsDraw(function() use (&$draws,$id,$rows) { egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'1'],$rows,'admin'); });
rejectsDraw(function() use (&$draws,$id,$settings) { egmPeriodDrawAction($draws,'save',array_replace($settings,['id'=>$id,'includeWalkIns'=>true]),[],'admin'); });
$other=egmPeriodDrawAction($draws,'create',$settings,[],'admin')['draw']['id'];
checkDraw(egmPeriodDrawAction($draws,'state',['id'=>$other],$rows,'admin')['eligibleCount']===1, 'Draws have independent winners');
egmPeriodDrawAction($draws,'lock',['id'=>$id],[],'admin');
egmPeriodDrawAction($draws,'reset',['id'=>$id],[],'admin');
checkDraw(!$draws[$id]['winners'] && !$draws[$id]['locked'], 'Reset clears winners and reopens a locked draw');
egmPeriodDrawAction($draws,'ensure_pot',['levelId'=>'pot-level','potLevel'=>['name'=>'Pot','title'=>'Pot','prizeName'=>'Gift','description'=>'','winnerLimit'=>2,'includeEntered'=>true,'includeWalkIns'=>true]],[],'admin');
checkDraw(isset($draws['pot-level']), 'Prize-level draw is created in the period');
egmPeriodDrawAction($draws,'ensure_pot',['levelId'=>'pot-level','potLevel'=>['name'=>'Pot','title'=>'Pot','prizeName'=>'Gift','description'=>'','winnerLimit'=>2,'includeEntered'=>true,'includeWalkIns'=>true]],[],'admin');
checkDraw(count($draws) === 3, 'Opening the same prize-level draw does not duplicate it');
egmPeriodDrawAction($draws,'roll',['id'=>$id],$rows,'admin');
rejectsDraw(function() use (&$draws,$id) { egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'1'],[],'admin'); });
$draws[$id]['pending']['admin']['expires']=time()-1;
rejectsDraw(function() use (&$draws,$id,$rows) { egmPeriodDrawAction($draws,'confirm',['id'=>$id,'candidateKey'=>'1'],$rows,'admin'); });
egmPeriodDrawAction($draws,'delete',['id'=>$id],[],'admin');
checkDraw(!isset($draws[$id]) && isset($draws[$other]), 'Deleting a draw preserves other draws');
checkDraw(egmPeriodDrawKey('01')!==egmPeriodDrawKey('02'), 'Periods use separate storage keys');
echo "EGM period draw tests passed.\n";
