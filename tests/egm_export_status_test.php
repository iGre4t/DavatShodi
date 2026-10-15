<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-period-exports.php';
function exportStatusAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$guest=['user_id'=>1,'first_name'=>'آزمایش','entered_date'=>'2026-10-11','entered_time'=>'09:00:00','last_control_condition'=>'duplicate'];
$logs=[];
foreach (['quit_duplicate','force_quit_success','minimum_stay','duplicate','duplicate','success'] as $index=>$condition) {
    $logs[]=$guest+['log_condition'=>$condition,'occurred_at'=>'2026-10-11 10:00:0'.$index,'attendance_action'=>str_contains($condition,'quit')||$condition==='minimum_stay'?'quit':'entry'];
}
$records=egmPeriodExportRecords('all_guests',[$guest],$logs);
exportStatusAssert($records[0]['کد وضعیت']==='وارد شده','Duplicate replaced actual attendance');
exportStatusAssert($records[0]['زمان اسکن‌های تکراری ورود']==="2026-10-11 10:00:04\n2026-10-11 10:00:03",'Duplicate entry timestamps lost');
exportStatusAssert($records[0]['زمان اسکن‌های تکراری خروج']==='2026-10-11 10:00:00','Duplicate exit timestamp lost');
$guest['quit_date']='2026-10-11';$guest['quit_time']='10:00:00';$guest['last_control_condition']='quit_duplicate';
$records=egmPeriodExportRecords('user_conditions',[$guest],$logs);
exportStatusAssert(str_starts_with($records[0]['کد وضعیت'],'خروج اجباری'),'Duplicate concealed forced checkout');
$records=egmPeriodExportRecords('full_log',[$guest],$logs);
exportStatusAssert(count($records)===2,'Duplicate or blocked checkout remained in exported log');
exportStatusAssert($records[0]['نوع عملیات']==='خروج','Operation label is not Persian');
exportStatusAssert(egmPeriodExportConditionLabel('walk_in')==='مهمان ناخوانده','Walk-in label incorrect');
$other=['user_id'=>2,'entered_date'=>'2026-10-11','entered_time'=>'09:00:00'];
exportStatusAssert(egmPeriodExportRecords('all_guests',[$other],$logs)[0]['زمان اسکن‌های تکراری ورود']==='','Duplicate timestamps leaked between guests');
echo "PASS: Persian statuses, actual attendance, forced checkout, duplicate timestamps and guest isolation.\n";
