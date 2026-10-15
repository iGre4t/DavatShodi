<?php
require_once dirname(__DIR__).'/api/lib/panel-dashboard.php';
$now=new DateTimeImmutable('2026-10-11 12:00:00',new DateTimeZone('Asia/Tehran'));
$base=['id'=>'event-guest-manager-mission-12345','code'=>'12345','name'=>'رویداد نمونه','sidebarVisible'=>true];
$active=['tagCode'=>'01','title'=>'بازه امروز','active'=>true,'duration'=>false];
$event=panelDashboardEvent($base,[$active],$now);
if($event['state']!=='active'||$event['activePeriod']['tagCode']!=='01')throw new RuntimeException('Active period lost');
$ended=$active;$ended['ended']=true;
if(panelDashboardEvent($base,[$ended],$now)['state']!=='no_active_period')throw new RuntimeException('Ended period shown active');
if(panelDashboardEvent($base,[$active,$active+['id'=>2]],$now)['state']!=='multiple_active_periods')throw new RuntimeException('Conflict presented as active');
if(panelDashboardEvents(null,[$base+['unused'=>true],array_replace($base,['sidebarVisible'=>false])])[0]['state']!=='unavailable')throw new RuntimeException('Database failure presented as empty');
if(count(panelDashboardEvents(null,[array_replace($base,['sidebarVisible'=>false])]))!==0)throw new RuntimeException('Hidden event leaked');
echo "PASS: period state, ended period, conflicting periods, unavailable database and hidden events.\n";
