<?php
declare(strict_types=1);
require_once __DIR__.'/egm-games.php';
require_once __DIR__.'/egm-check-in.php';

function egmGamePeriodEnd(array $period, DateTimeImmutable $now): array
{
    $end=null;
    $raw=trim((string)($period['endedAt']??$period['ended_at']??''));
    if ($raw!=='') {try {$end=new DateTimeImmutable($raw,$now->getTimezone());} catch(Throwable $error) {}}
    $scheduled=egmCheckInPeriodWindow($period,$now->getTimezone())['end'];
    if ($scheduled && $scheduled<=$now && (!$end || $scheduled<$end)) $end=$scheduled;
    return ['ended'=>egmCheckInBool($period['ended']??false)||$raw!==''||($end && $end<=$now),'at'=>$end];
}
function egmGamesEndedPeriodCodes(array $context): array
{
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));$ended=[];
    foreach(egmInstanceReadPeriods($context['pdo'],(string)$context['code']) as $period) {
        if(is_array($period) && egmGamePeriodEnd($period,$now)['ended'])$ended[egmCheckInPeriodCode($period)]=true;
    }
    return $ended;
}
/** End stale games without deleting teams, members, or scores. Lock matches all game operations. */
function egmGamesCleanupExpiredPeriods(array $context,bool $force=false,?DateTimeImmutable $now=null): array
{
    $pdo=$context['pdo'];if($pdo->inTransaction())return [];
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
    $periods=egmInstanceReadPeriods($pdo,(string)$context['code']);$state=egmGamesState($context);$result=[];
    $observed=(array)egmInstanceReadData($pdo,(string)$context['code'],'games_period_end_observed',[]);$changed=false;
    $done=(array)egmInstanceReadData($pdo,(string)$context['code'],'games_period_cleanup_done',[]);$doneChanged=false;
    foreach($periods as $period) {
        if(!is_array($period))continue;
        $code=egmCheckInPeriodCode($period);if($code==='')continue;
        $end=egmGamePeriodEnd($period,$now);if(!$end['ended'])continue;
        if(!$end['at']) {
            if(!isset($observed[$code])){$observed[$code]=$now->format(DATE_ATOM);$changed=true;}
            $end['at']=new DateTimeImmutable($observed[$code],$now->getTimezone());
        }
        if(!$force && $now<$end['at']->modify('+1 hour'))continue;
        $fingerprint=hash('sha256',json_encode($period,JSON_UNESCAPED_UNICODE));
        if(!$force && ($done[$code]??'')===$fingerprint)continue;
        $ids=array_values(array_unique(array_merge(array_column($state['games'],'id'),$state['enabled'][$code]??[])));
        foreach($ids as $gameId) {
            $table=egmGamesTableName((string)$context['code'],$code,$gameId);
            if(!egmInstanceTableExists($pdo,$table))continue;
            $count=egmGamesWithLock($context,$gameId,static function()use($pdo,$table,$now,$force,$end):int{
                $pdo->beginTransaction();$count=0;
                try {
                    $rows=$pdo->query("SELECT id,payload FROM `$table` WHERE user_id IS NULL FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
                    $save=$pdo->prepare("UPDATE `$table` SET payload=? WHERE id=? AND user_id IS NULL");
                    foreach($rows as $row) {
                        $team=json_decode((string)$row['payload'],true);
                        if(!is_array($team)||($team['type']??'')!=='team')continue;
                        if(!empty($team['ended_at']) && empty($team['room_assignment']) && empty($team['queue_since']))continue;
                        if(empty($team['ended_at'])){$team['ended_at']=$now->format(DATE_ATOM);$team['end_reason']='period_expired';}
                        $team['room_assignment']=null;$team['queue_since']='';unset($team['room_wait_level_id']);
                        $team['period_cleanup']=['at'=>$now->format(DATE_ATOM),'period_ended_at'=>$end['at']->format(DATE_ATOM),'forced'=>$force];
                        $save->execute([json_encode($team,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$row['id']]);$count++;
                    }
                    $pdo->commit();return $count;
                }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            });
            if($count)$result[]=['period_code'=>$code,'game_id'=>$gameId,'teams_ended_or_released'=>$count];
        }
        $done[$code]=$fingerprint;$doneChanged=true;
    }
    if($doneChanged)egmInstanceWriteData($pdo,(string)$context['code'],'games_period_cleanup_done',$done);
    if($changed)egmInstanceWriteData($pdo,(string)$context['code'],'games_period_end_observed',$observed);
    return $result;
}
function egmGamesPeriodRoomStatus(array $context): array
{
    $state=egmGamesState($context);$out=[];$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
    foreach(egmInstanceReadPeriods($context['pdo'],(string)$context['code']) as $period) {
        $code=egmCheckInPeriodCode($period);$rooms=0;$playing=0;
        foreach(array_unique(array_merge(array_column($state['games'],'id'),$state['enabled'][$code]??[])) as $id) {
            $table=egmGamesTableName((string)$context['code'],$code,$id);if(!egmInstanceTableExists($context['pdo'],$table))continue;
            foreach($context['pdo']->query("SELECT payload FROM `$table` WHERE user_id IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $json){
                $team=json_decode((string)$json,true);if(!is_array($team)||($team['type']??'')!=='team')continue;
                if(!empty($team['room_assignment']))$rooms++;
                if(!empty($team['started_at'])&&empty($team['ended_at']))$playing++;
            }
        }
        $out[]=['code'=>$code,'title'=>$period['title']??$code,'ended'=>egmGamePeriodEnd($period,$now)['ended'],'occupied_rooms'=>$rooms,'playing_teams'=>$playing];
    }
    return $out;
}
