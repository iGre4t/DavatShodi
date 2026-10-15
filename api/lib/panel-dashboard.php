<?php
/** Dashboard reads existing period records without migrations or guest data. */
require_once __DIR__.'/egm-check-in.php';

function panelDashboardEvent(array $event, array $periods, DateTimeImmutable $now): array
{
    $state=egmCheckInResolveEgmPeriodState($periods,$now);
    $event['periods']=array_values(array_filter($periods,'is_array'));
    $event['activePeriod']=$state['period'];
    $event['nextPeriod']=$state['next'];
    $event['state']=$state['result'];
    return $event;
}
function panelDashboardEvents(?PDO $pdo,array $events): array
{
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
    $result=[];
    foreach ($events as $event) {
        if (!($event['sidebarVisible']??true)) continue;
        try {
            if (!$pdo) throw new RuntimeException('No database');
            $table=egmInstanceTableNames($event['code'])['data'];
            $query=$pdo->query("SELECT periods FROM `$table` WHERE data_key='periods' LIMIT 1");
            $periods=json_decode((string)$query->fetchColumn(),true);
            $result[]=panelDashboardEvent($event,is_array($periods)?$periods:[],$now);
        } catch (Throwable $error) {
            error_log('Dashboard period read failed: '.$error->getMessage());
            $event=panelDashboardEvent($event,[],$now);$event['state']='unavailable';$result[]=$event;
        }
    }
    usort($result,static function($a,$b){
        $priority=['active'=>0,'multiple_active_periods'=>1,'no_active_period'=>2,'unavailable'=>3];
        return ($priority[$a['state']]<=>$priority[$b['state']]) ?: strcmp($a['name'],$b['name']);
    });
    return $result;
}
