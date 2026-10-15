<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/lib/egm-guest-reports.php';
function groupCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$base=['egm_code'=>'0006','period_code'=>'01','user_id'=>10,'guest_code'=>'1234567890','decision'=>null];$reports=[];
for($i=1;$i<=5;$i++)$reports[]=$base+['id'=>str_pad((string)$i,32,'0',STR_PAD_LEFT),'log_id'=>$i,'created_at'=>'2026-10-12 08:0'.$i.':00','snapshot'=>json_encode(['status'=>'not_invited','message'=>'Attempt '.$i,'attendance_action'=>'entry'])];
$groups=egmGuestReportsGroup($reports);groupCheck(count($groups)===1&&$groups[0]['repeat_count']===5&&$groups[0]['log_id']===5,'Repeated incident not grouped');
$other=$base+['id'=>str_repeat('a',32),'log_id'=>6,'created_at'=>'2026-10-12 08:06:00','snapshot'=>json_encode(['status'=>'force_quit_success','attendance_action'=>'quit'])];
$reports[]=$other;groupCheck(count(egmGuestReportsGroup($reports))===2,'Different incident merged');
$other['decision']='deny';$reports[5]=$other;
$groups=egmGuestReportsGroup($reports,['01:10'=>['report_id'=>$other['id'],'log_id'=>6]]);
groupCheck($groups[0]['actionable']&&!$groups[1]['actionable'],'Superseded actions still exposed');
$another=$reports[0];$another['period_code']='02';$reports[]=$another;
groupCheck(count(egmGuestReportsGroup($reports))===3,'Periods merged');
groupCheck(!egmCheckInCanReportToManagement('duplicate',true,'entry',true),'Repeated check-in still produces report');
echo "PASS: five identical attempts grouped, different actions and periods separate, superseded reports informational, duplicate scans excluded.\n";
