<?php
declare(strict_types=1);
require_once __DIR__.'/egm-refmonitor-teams.php';
require_once __DIR__.'/egm-staff-roles.php';

function egmRefManagerAssert(array $actor, array $input = []): void
{
    $role=egmStaffRole($actor['event_role'] ?? null);
    if ($role==='system_manager') return;
    if ($role==='head_referee') {
        $method=(string)($input['method']??'');
        if (in_array($method,['games','teams','team'],true)) return;
        if ($method==='room' && ($input['enabled']??null)===false) return;
    }
    throw new InvalidArgumentException('دسترسی این عملیات برای نقش شما مجاز نیست.');
}

function egmRefManagerOwners(PDO $pdo, string $code): array
{
    $owners=[];
    foreach (egmFacilitatorsPublic(egmFacilitatorsRead($pdo,$code)) as $row) $owners[]=['code'=>'facilitator:'.$row['id'],'name'=>$row['name'],'username'=>$row['username']];
    foreach (egmStaffPanelUsers($pdo,$code) as $row) $owners[]=['code'=>$row['code'],'name'=>$row['name'],'username'=>$row['username']];
    return $owners;
}

function egmRefManagerRevision(array $payload): string
{
    return hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function egmRefManagerTeam(array $context, string $period, string $game, int $id): array
{
    $table=egmRefMonitorGameTable($context['pdo'],$context['code'],$period,$game);
    $team=egmRefMonitorReadTeam($context['pdo'],$table,$id);
    $view=egmGameEnrichTeam($context['pdo'],$context['code'],$period,$game,egmRefMonitorTeamView($team['row'],$team['payload']));
    $view['revision']=egmRefManagerRevision($team['payload']);
    return $view;
}

/** Event users from any period, and OEU users who may not yet be invited. */
function egmRefManagerSearch(array $context,string $query): array
{
    $query=trim(strtr($query,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    if (mb_strlen($query)<2) return [];
    $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
    $result=[];
    foreach (['e'=>$context['tables']['users'],'o'=>'organizational_event_users'] as $source=>$table) {
        if ($source==='o' && !egmInstanceTableExists($context['pdo'],$table)) continue;
        $read=$context['pdo']->prepare("SELECT id,first_name,last_name,national_id,work_id,gender FROM `{$table}` WHERE CONCAT(first_name,' ',last_name) LIKE ? ESCAPE '!' OR national_id LIKE ? ESCAPE '!' OR work_id LIKE ? ESCAPE '!' ORDER BY last_name,first_name,id LIMIT 30");
        $read->execute([$like,$like,$like]);
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $person=egmRefMonitorPerson($row);
            $person['key']=$source.':'.$row['id'];$person['source']=$source==='e'?'مهمان رویداد':'کاربر سازمان';
            $result[]=$person;
        }
    }
    return $result;
}

/** Import without falsifying scan attendance or granting gift/draw eligibility. Caller owns transaction. */
function egmRefManagerResolvePeople(array $context,string $period,array $keys,string $actor): array
{
    if (!$keys || count($keys)>EGM_GAME_TEAM_MAX || count(array_unique($keys))!==count($keys)) throw new InvalidArgumentException('فهرست اعضا معتبر نیست؛ بین ۱ تا ۱۰۰ عضو انتخاب کنید.');
    $pdo=$context['pdo'];$users=$context['tables']['users'];$periods=$context['tables']['user_periods'];$people=[];
    foreach ($keys as $key) {
        if (!is_string($key)||!preg_match('/^([eo]):([1-9][0-9]*)$/D',$key,$match)) throw new InvalidArgumentException('عضو نامعتبر است.');
        $table=$match[1]==='e'?$users:'organizational_event_users';
        $read=$pdo->prepare("SELECT * FROM `{$table}` WHERE id=? FOR UPDATE");$read->execute([(int)$match[2]]);$row=$read->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('عضو انتخاب‌شده پیدا نشد.');
        if ($match[1]==='o') {
            $find=$pdo->prepare("SELECT * FROM `{$users}` WHERE (source_type='oeu' AND source_user_id=?) OR (national_id<>'' AND national_id=?) OR (work_id<>'' AND work_id=?) ORDER BY id LIMIT 2 FOR UPDATE");
            $find->execute([$row['id'],$row['national_id'] ?? '',$row['work_id'] ?? '']);$existing=$find->fetchAll(PDO::FETCH_ASSOC);
            if (count($existing)>1) throw new InvalidArgumentException('شناسه‌های این کاربر با چند مهمان رویداد مطابقت دارند؛ ابتدا اطلاعات را اصلاح کنید.');
            if ($existing) {
                $found=$existing[0];
                if (!empty($found['national_id'])&&!empty($row['national_id'])&&$found['national_id']!==$row['national_id']) throw new InvalidArgumentException('کد ملی کاربر سازمان با مهمان رویداد مغایرت دارد.');
                $row=$found;
            } else {
                $fields=['work_id','first_name','last_name','national_id','phone_number','deputy','general_department','department','gender','postal_level','source_row'];
                $values=array_map(static fn($field)=>$field==='source_row'?(int)($row[$field]??0):($field==='national_id'?($row[$field]?:null):($row[$field]??'')),$fields);
                $pdo->prepare("INSERT INTO `{$users}` (`".implode('`,`',$fields)."`,source_type,source_user_id,is_active) VALUES (".implode(',',array_fill(0,count($fields),'?')).",'oeu',?,1)")->execute([...$values,$row['id']]);
                $row['id']=(int)$pdo->lastInsertId();
                egmInstanceAssignGuestNumbers($pdo,$context['code'],[$row['id']]);
            }
        }
        $person=egmRefMonitorPerson($row);
        if (isset($people[$person['id']])) throw new InvalidArgumentException('یک نفر از دو منبع انتخاب شده است.');
        $pdo->prepare("INSERT INTO `{$periods}` (user_id,period_code,status,invitation_source,invited_by,invited_at) VALUES (?,?,'invited','manager',?,NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)")->execute([$person['id'],$period,$actor]);
        $people[$person['id']]=$person;
    }
    return array_values($people);
}

function egmRefManagerSaveSettings(array $context,string $gameId,array $input): void
{
    egmGamesWithLock($context,$gameId,static function() use($context,$gameId,$input): void {
        $state=egmGamesState($context);$game=null;
        foreach ($state['games'] as $candidate) if ($candidate['id']===$gameId) $game=$candidate;
        if (!$game) throw new InvalidArgumentException('بازی پیدا نشد.');
        if (($input['revision']??'')!==egmRefManagerRevision($game)) throw new InvalidArgumentException('تنظیمات تغییر کرده‌اند؛ دوباره بازی را باز کنید.');
        $name=egmRefMonitorName((string)($input['name']??''));
        foreach (['has_levels','auto_room_manager','no_score_needed','require_cover_color'] as $field) if (!is_bool($input[$field]??null)) throw new InvalidArgumentException('تنظیم بازی نامعتبر است.');
        $pdo=$context['pdo'];$pdo->beginTransaction();
        try {
            // Reuse the panel's validation and room/score migration rules.
            if (!$input['auto_room_manager']) egmGamesSaveAutoMode($context,$gameId,false);
            egmGamesSaveMode($context,$gameId,$input['has_levels']);
            egmGamesSaveGenderMode($context,$gameId,(string)($input['gender_mode']??''));
            egmGamesSaveLimits($context,$gameId,$input['min_players']??null,$input['max_players']??null,$input['score_count']??null);
            egmGamesSaveNoScoreMode($context,$gameId,$input['no_score_needed']);
            egmGamesSaveCoverColorRequirement($context,$gameId,$input['require_cover_color']);
            egmGamesSaveAutoMode($context,$gameId,$input['auto_room_manager']);
            $state=egmGamesState($context);
            foreach ($state['games'] as &$item) if ($item['id']===$gameId) $item['name']=$name;
            unset($item);egmGamesWrite($context,$state);$pdo->commit();
        } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    });
}

/** Atomic manager edits, guarded against overwriting another user's changes. */
function egmRefManagerChangeTeam(array $context,string $period,string $gameId,int $id,array $input,string $actor): array
{
    $pdo=$context['pdo'];$table=egmRefMonitorGameTable($pdo,$context['code'],$period,$gameId);
    return egmRefMonitorWithLock($pdo,$context['code'],$gameId,static function() use($context,$pdo,$table,$period,$gameId,$id,$input,$actor): array {
        $team=egmRefMonitorReadTeam($pdo,$table,$id);$payload=$team['payload'];$game=egmRefMonitorGameConfig($pdo,$context['code'],$gameId);
        if (($input['revision']??'')!==egmRefManagerRevision($payload)) throw new InvalidArgumentException('تیم تغییر کرده است؛ جزئیات را تازه‌سازی کنید.');
        $method=(string)($input['method']??'');$before=$payload;
        $owners=$method==='details'?egmRefManagerOwners($pdo,$context['code']):[];
        $pdo->beginTransaction();
        try {
            if ($method==='details') {
                $payload['name']=egmRefMonitorName((string)($input['name']??''));
                $owner=(string)($input['owner']??'');$found=false;
                foreach ($owners as $candidate) if ($candidate['code']===$owner) {$found=true;$payload['created_by']=$owner;$payload['creator']=$candidate;}
                if (!$found) throw new InvalidArgumentException('داور انتخاب‌شده به این رویداد دسترسی ندارد.');
                $payload['cover_color']=egmRefMonitorCoverColor((string)($input['cover_color']??''),$game['require_cover_color'],$payload['cover_color']??'');
            } elseif ($method==='members') {
                $members=egmRefManagerResolvePeople($context,$period,(array)($input['members']??[]),$actor);
                egmRefMonitorAssertGenderMode($members,$game['gender_mode']);
                egmRefMonitorAssertMembersAvailable($pdo,$table,$members,$id);
                $payload['members']=$members;
                $assignment=$payload['room_assignment']??null;
                if ($assignment && !egmRefMonitorRoomEligible(egmRefMonitorTeamGender($members),egmRefManagerRoomGender($game,$assignment['room_id']),$game['gender_mode'])) {
                    $payload['room_wait_level_id']=$assignment['level_id'];unset($payload['room_assignment']);$payload['queue_since']=egmRefMonitorQueueTime();
                }
            } elseif ($method==='disband') {
                if (($input['confirmed']??false)!==true) throw new InvalidArgumentException('انحلال تیم نیاز به تأیید دارد.');
                $payload['type']='disbanded_team';$payload['disbanded_at']=date('c');$payload['disbanded_by']=$actor;
                unset($payload['room_assignment'],$payload['queue_since'],$payload['room_wait_level_id']);
            } elseif ($method==='start' || $method==='reopen') {
                if ($method==='start' && !empty($payload['started_at'])) throw new InvalidArgumentException('تیم قبلاً شروع کرده است.');
                if ($method==='reopen' && empty($payload['ended_at'])) throw new InvalidArgumentException('تیم هنوز پایان نیافته است.');
                if ($method==='reopen' && !array_diff(array_column(egmGamesPlayableLevels($game),'id'),array_keys($payload['scores']??[]))) throw new InvalidArgumentException('همهٔ مراحل تکمیل شده‌اند؛ امتیازها از بخش امتیازها قابل ویرایش هستند.');
                $count=count($payload['members']);
                if ($count<$game['min_players']||$count>$game['max_players']) throw new InvalidArgumentException('تعداد اعضای تیم با محدودیت بازی سازگار نیست.');
                egmRefMonitorAssertGenderMode($payload['members'],$game['gender_mode']);
                if (empty($payload['started_at'])) {$payload['started_at']=date('c');$payload['started_by']=$actor;}
                $payload['score_count']??=$game['score_count'];
                unset($payload['ended_at'],$payload['ended_by'],$payload['end_reason']);
                $payload['queue_since']=egmRefMonitorQueueTime();
            } elseif ($method==='end') {
                $payload['ended_at']=date('c');$payload['ended_by']=$actor;$payload['end_reason']='manager';
                unset($payload['room_assignment'],$payload['queue_since'],$payload['room_wait_level_id']);
            } elseif ($method==='score') {
                if (empty($payload['started_at'])) throw new InvalidArgumentException('ابتدا تیم را شروع کنید.');
                $levelId=(string)($input['level_id']??'');$levels=egmGamesPlayableLevels($game);$names=array_column($levels,'name','id');
                if (!isset($names[$levelId])) throw new InvalidArgumentException('مرحله پیدا نشد.');
                $index=$input['index']??null;$required=$game['no_score_needed']?1:($payload['score_count']??$game['score_count']);
                $complete=$payload['scores'][$levelId]??null;
                $attempts=$payload['score_attempts'][$levelId]??($complete['attempts']??($complete?[$complete]:[]));
                if (!is_int($index)||$index<0||$index>=max($required,count($attempts))) throw new InvalidArgumentException('نوبت امتیاز نامعتبر است.');
                if ($index>count($attempts)) throw new InvalidArgumentException('ابتدا نوبت قبلی این مرحله را ثبت کنید.');
                $expected=$input['expected_score']??null;$previous=$attempts[$index]['score']??null;
                if ($expected!==$previous && !($expected!==null&&$previous!==null&&(float)$expected===(float)$previous)) throw new InvalidArgumentException('امتیاز تغییر کرده است؛ تیم را دوباره باز کنید.');
                $score=(string)($input['score']??'');
                if (!$game['no_score_needed']&&!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D',$score)) throw new InvalidArgumentException('امتیاز باید عددی نامنفی با حداکثر دو رقم اعشار باشد.');
                $attempt=$attempts[$index]??['submitted_at'=>date('c'),'submitted_by'=>$actor,'level_name'=>$names[$levelId]];
                $attempt['score']=$game['no_score_needed']?null:(float)$score;$attempt['completion_only']=$game['no_score_needed'];
                $attempts[$index]=$attempt;$payload['score_attempts'][$levelId]=$attempts;
                if (count($attempts)>=$required || $complete!==null) {
                    $payload['scores'][$levelId]=$attempt+['attempts'=>$attempts];
                    $payload['scores'][$levelId]['score']=$game['no_score_needed']?null:round(array_sum(array_column($attempts,'score')),2);
                    $payload['scores'][$levelId]['attempts']=$attempts;
                    if (($payload['room_assignment']['level_id']??'')===$levelId) {unset($payload['room_assignment'],$payload['room_wait_level_id']);$payload['queue_since']=egmRefMonitorQueueTime();}
                    if (!array_diff(array_keys($names),array_keys($payload['scores']))) {
                        if (empty($payload['ended_at'])) {$payload['ended_at']=date('c');$payload['ended_by']=$actor;$payload['end_reason']='completed';}
                        unset($payload['room_assignment'],$payload['queue_since'],$payload['room_wait_level_id']);
                    }
                }
            } else throw new InvalidArgumentException('عملیات مدیریت تیم معتبر نیست.');
            $payload['manager_changes'][]=['method'=>$method,'by'=>$actor,'at'=>date('c'),'before'=>$before,'after'=>array_diff_key($payload,['manager_changes'=>true])];
            // Store a compact audit: previous payload already contains prior audit entries.
            $last=count($payload['manager_changes'])-1;unset($payload['manager_changes'][$last]['before']['manager_changes']);
            egmRefMonitorWriteTeam($pdo,$table,$id,$payload);
            egmRefMonitorDispatchRooms($pdo,$context['code'],$table,$game);$pdo->commit();
        } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
        return $method==='disband'?['disbanded'=>true]:egmRefManagerTeam($context,$period,$gameId,$id);
    });
}

function egmRefManagerRoomGender(array $game,string $roomId): string
{
    foreach (egmGamesPlayableLevels($game) as $level) foreach ($level['rooms'] as $room) if ($room['id']===$roomId) return $room['gender'];
    return '';
}

function egmRefManagerRequest(PDO $pdo,string $code,array $actor,array $input): array
{
    egmRefManagerAssert($actor,$input);
    $context=['pdo'=>$pdo,'code'=>$code,'tables'=>ensureEgmInstanceTables($pdo,$code)];
    $method=(string)($input['method']??'');$gameId=(string)($input['game_id']??'');$state=egmGamesState($context);
    if ($method==='games') {
        try {$period=egmRefMonitorActivePeriod($pdo,$code);$active=$period['code'];} catch(InvalidArgumentException $error) {$period=null;$active='';}
        return ['games'=>array_map(static fn($game)=>$game+['revision'=>egmRefManagerRevision($game)],$state['games']),'enabled'=>$state['enabled'][$active]??[],'period'=>$period];
    }
    $game=null;foreach ($state['games'] as $candidate) if ($candidate['id']===$gameId) $game=$candidate;
    if (!$game) throw new InvalidArgumentException('بازی پیدا نشد.');
    if ($method==='settings') egmRefManagerSaveSettings($context,$gameId,$input);
    elseif ($method==='room') {
        if (!is_bool($input['enabled']??null)||!is_bool($input['confirmed']??false)) throw new InvalidArgumentException('وضعیت اتاق نامعتبر است.');
        return egmGamesSetRoomEnabled($context,$gameId,(string)($input['level_id']??''),(string)($input['room_id']??''),$input['enabled'],$input['confirmed']??false,(string)$actor['code']);
    }
    elseif ($method==='save_level') egmGamesSaveLevel($context,$gameId,(string)($input['level_id']??''),(string)($input['name']??''));
    elseif ($method==='delete_level') egmGamesDeleteLevel($context,$gameId,(string)($input['level_id']??''));
    else {
        $period=egmRefMonitorActivePeriod($pdo,$code)['code'];
        if (($input['period_code']??'')!==$period) throw new InvalidArgumentException('بازهٔ فعال تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
        if ($method==='teams') return ['teams'=>egmRefMonitorListTeams($pdo,$code,$period,$gameId),'owners'=>($actor['event_role']??'')==='system_manager'?egmRefManagerOwners($pdo,$code):[]];
        if ($method==='search') return ['people'=>egmRefManagerSearch($context,(string)($input['query']??''))];
        $id=(int)($input['team_id']??0);
        if ($method==='team') return ['team'=>egmRefManagerTeam($context,$period,$gameId,$id),'owners'=>($actor['event_role']??'')==='system_manager'?egmRefManagerOwners($pdo,$code):[]];
        return ['team'=>egmRefManagerChangeTeam($context,$period,$gameId,$id,$input,(string)$actor['code'])];
    }
    return ['games'=>array_map(static fn($item)=>$item+['revision'=>egmRefManagerRevision($item)],egmGamesState($context)['games'])];
}
