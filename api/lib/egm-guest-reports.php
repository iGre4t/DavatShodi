<?php
declare(strict_types=1);
require_once __DIR__ . '/system-telegram.php';

/** Persist independently of bot configuration. One report per original log. */
function egmGuestReportsStore(array $context, int $logId, array $actor = []): ?array
{
    $unfiltered = $context;
    $unfiltered['period_code'] = $unfiltered['logs_period_code'] = '';
    $row = egmCheckInRecentLogs($unfiltered, 1, '', $logId)[0] ?? null;
    if (!$row || empty($row['management_report_eligible']) || (int)($row['user_id'] ?? 0) < 1 || ($row['status'] ?? '') === 'not_found') return null;
    $pdo = $context['pdo'];
    static $ensured = [];
    $schemaKey = spl_object_id($pdo).':'.$context['code'];
    if (empty($ensured[$schemaKey])) {
        systemBotWithProvider('telegram', static fn() => systemTelegramEnsure($pdo));
        $ensured[$schemaKey] = true;
    }
    $row['event_name'] = $context['name'];
    $row['reported_by'] = $actor['username'] ?? $actor['code'] ?? '';
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT IGNORE INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot,delivery_requested,created_at) VALUES(?,?,?,?,?,?,?,0,?)')
        ->execute([$id,$context['code'],$row['period_code'],$logId,$row['user_id'] ?: null,$row['national_id'] ?: $row['work_id'],json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['attempted_at']]);
    $find = $pdo->prepare('SELECT * FROM egm_telegram_reports WHERE egm_code=? AND log_id=?');
    $find->execute([$context['code'],$logId]);
    return $find->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Runs only after attendance commits. Bot failure never reverses entry. */
function egmGuestReportsAfterScan(array $context, int $logId, array $actor = []): void
{
    try {
        $report = egmGuestReportsStore($context,$logId,$actor);
        if (!$report) return;
        $settings = egmInstanceReadData($context['pdo'],$context['code'],'settings',[]);
        if (egmCheckInBool($settings['autoReport'] ?? false)) systemBotsCreateReport($context,$logId,$actor);
    } catch (Throwable $error) {
        error_log('EGM guest report pending: '.$context['code'].'/'.$logId);
    }
}

/** Import older logs incrementally without requiring automatic reporting. */
function egmGuestReportsCollect(array $context): bool
{
    $pdo = $context['pdo'];
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
    $key = 'egm_reports_collect_'.$context['code'];
    $lock->execute([$key]);
    if ((int)$lock->fetchColumn() !== 1) return false;
    try {
        $cursor = (int)egmInstanceReadData($pdo,$context['code'],'guest_reports_cursor',0);
        $logs = $context['logs_pdo'] ?? $pdo;
        $table = $context['tables']['activity_logs'];
        $query = $logs->prepare("SELECT id FROM `{$table}` WHERE action=? AND id>? ORDER BY id LIMIT 200");
        $query->execute([EGM_CHECK_IN_ACTION,$cursor]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            egmGuestReportsStore($context,(int)$id);
            $cursor = (int)$id;
        }
        egmInstanceWriteData($pdo,$context['code'],'guest_reports_cursor',$cursor);
        return count($ids) === 200;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
    }
}

/** Describe the original incident; the manager's saved decision is shown separately. */
function egmGuestReportSummary(array $guest): array
{
    $status = (string)($guest['status'] ?? '');
    if ($status === 'force_quit_success') return ['reason'=>'خروج اجباری', 'effect'=>'عدم دریافت هدیه', 'tone'=>'danger'];
    if (!empty($guest['is_uninvited_guest']) || in_array($status, ['walk_in_registered','not_invited'], true)) {
        return ['reason'=>'ورود بدون دعوت', 'effect'=>'عدم شرکت در بازی و قرعه‌کشی و دریافت هدیه', 'tone'=>'danger'];
    }
    if (!empty($guest['draw_time_excluded'])) return ['reason'=>'ورود خارج از زمان قرعه‌کشی', 'effect'=>'عدم شرکت در قرعه‌کشی', 'tone'=>'warning'];
    $reason = match ($status) {
        'invited_other_period' => 'دعوت در بازهٔ دیگر',
        'attended_previous_period' => 'حضور در بازهٔ قبلی',
        'force_entry_success' => 'ورود اجباری',
        'user_inactive' => 'مهمان غیرفعال',
        default => 'ورود نیازمند بررسی',
    };
    return ['reason'=>$reason, 'effect'=>'نیاز به تصمیم مدیر', 'tone'=>'warning'];
}

/** Same guest, period and consequence; scan time and wording do not create a new incident. */
function egmGuestReportKey(array $report): string
{
    $guest=is_array($report['guest']??null)?$report['guest']:(json_decode((string)($report['snapshot']??''),true)?:[]);
    return hash('sha256',json_encode([(string)$report['egm_code'],(string)$report['period_code'],(string)($report['user_id']?:$report['guest_code']),
        (string)($guest['status']??''),(string)($guest['attendance_action']??''),
        (string)($guest['force_action']??''),(bool)($guest['draw_time_excluded']??false),(bool)($guest['is_uninvited_guest']??false)
    ],JSON_UNESCAPED_UNICODE));
}
function egmGuestReportGuestKey(array $report): string
{
    return (string)$report['period_code'].':'.(string)($report['user_id']?:$report['guest_code']);
}
function egmGuestReportsGroup(array $reports,array $decisions=[]): array
{
    $groups=[];
    foreach($reports as $report){$groups[egmGuestReportKey($report)][]=$report;}
    $out=[];
    foreach($groups as $key=>$copies){
        usort($copies,static fn($a,$b)=>(int)$b['log_id']<=>(int)$a['log_id']);
        $latest=$copies[0];$current=$decisions[egmGuestReportGuestKey($latest)]??null;$chosen=$latest;
        if($current)foreach($copies as $copy)if($copy['id']===$current['report_id']){$chosen=$copy;break;}
        if(!$chosen['decision'])foreach($copies as $copy)if(!empty($copy['decision'])){$chosen=$copy;break;}
        $methods=[];foreach($copies as $copy){$guest=is_array($copy['guest']??null)?$copy['guest']:(json_decode((string)($copy['snapshot']??''),true)?:[]);$method=(string)($guest['input_method_label']??'نامشخص');$methods[$method]=($methods[$method]??0)+1;}
        $chosen['input_methods']=$methods;
        $chosen['repeat_count']=count($copies);$chosen['last_attempt_at']=$latest['created_at'];
        $chosen['latest_log_id']=(int)$latest['log_id'];$chosen['incident_key']=$key;
        $chosen['actionable']=!empty($chosen['decision']) ? ($current!==null && $current['report_id']===$chosen['id']) : (!$current || (int)$current['log_id']<=(int)$latest['log_id']);
        $chosen['information_label']=$chosen['actionable']?'':'صرفاً اطلاع‌رسانی؛ تصمیم جدیدتری برای این مهمان ثبت شده است.';
        $out[]=$chosen;
    }
    usort($out,static fn($a,$b)=>strcmp($b['last_attempt_at'],$a['last_attempt_at'])?:$b['latest_log_id']<=>$a['latest_log_id']);
    return $out;
}
/** Resolve an old duplicate link to the one current actionable incident. */
function egmGuestReportCanonical(PDO $pdo,array $report): array
{
    $query=$pdo->prepare('SELECT * FROM egm_telegram_reports WHERE egm_code=? AND period_code=? AND user_id=? ORDER BY log_id DESC');
    $query->execute([$report['egm_code'],$report['period_code'],$report['user_id']]);$key=egmGuestReportKey($report);
    $copies=array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC),static fn($copy)=>egmGuestReportKey($copy)===$key));
    $find=$pdo->prepare('SELECT d.report_id,r.log_id FROM egm_telegram_decisions d JOIN egm_telegram_reports r ON r.id=d.report_id WHERE d.egm_code=? AND d.period_code=? AND d.user_id=? ORDER BY r.log_id DESC LIMIT 1');
    $find->execute([$report['egm_code'],$report['period_code'],$report['user_id']]);$current=$find->fetch(PDO::FETCH_ASSOC);
    $groups=egmGuestReportsGroup($copies,$current?[egmGuestReportGuestKey($report)=>$current]:[]);
    $canonical=$groups[0]??$report;
    if(isset($canonical['actionable'])&&!$canonical['actionable'])throw new InvalidArgumentException('این گزارش فقط اطلاع‌رسانی است؛ تصمیم جدیدتر این مهمان را بررسی کنید.');
    foreach(['repeat_count','last_attempt_at','latest_log_id','incident_key','actionable','information_label']as $field)unset($canonical[$field]);
    return $canonical;
}

function egmGuestReportsList(array $context, array $input): array
{
    $pdo = $context['pdo'];
    systemBotWithProvider('telegram', static fn() => systemTelegramEnsure($pdo));
    $moreLogs = egmGuestReportsCollect($context);
    $where = ['egm_code=?', 'user_id IS NOT NULL', 'user_id>0']; $args = [$context['code']];
    // Keep original scan logs, but exclude obsolete scan-only/blocked-checkout reports.
    $statusSql = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.status')),'')";
    $where[] = "{$statusSql} NOT IN ('not_found','duplicate','entry_duplicate','entered_duplicate')";
    $where[] = "({$statusSql}='force_quit_success' OR ({$statusSql} NOT IN ('minimum_stay','quit_success','quit_duplicate','quit_without_entry') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.attendance_action')),'')<>'quit' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot,'$.force_action')),'')<>'quit'))";
    $period = trim((string)($input['period'] ?? 'active'));
    if ($period === 'active') {
        $period = (string)($context['period_code'] ?? '');
        if ($period === '') $where[] = '1=0';
    }
    if ($period !== '') { $where[]='period_code=?'; $args[]=$period; }
    $state = (string)($input['state'] ?? 'pending');
    if(!in_array($state,['pending','resolved','all','information'],true))throw new InvalidArgumentException('وضعیت گزارش معتبر نیست.');
    $q=trim((string)($input['q']??''));
    $query=$pdo->prepare('SELECT * FROM egm_telegram_reports WHERE '.implode(' AND ',$where).' ORDER BY log_id DESC');$query->execute($args);
    $find=$pdo->prepare('SELECT d.period_code,d.user_id,d.guest_code,d.report_id,r.log_id FROM egm_telegram_decisions d JOIN egm_telegram_reports r ON r.id=d.report_id WHERE d.egm_code=?');$find->execute([$context['code']]);$decisions=[];
    foreach($find->fetchAll(PDO::FETCH_ASSOC)as $decision)$decisions[egmGuestReportGuestKey($decision)]=$decision;
    $grouped=egmGuestReportsGroup($query->fetchAll(PDO::FETCH_ASSOC),$decisions);
    $grouped=array_values(array_filter($grouped,static function($report)use($state,$q):bool{
        if($state==='pending' && (!empty($report['decision'])||!$report['actionable']))return false;
        if($state==='resolved' && empty($report['decision']))return false;
        if($state==='information' && $report['actionable'])return false;
        return $q===''||mb_stripos((string)$report['snapshot'].' '.$report['guest_code'],$q)!==false;
    }));
    $total=count($grouped);$pages=max(1,(int)ceil($total/30));$page=max(1,min($pages,(int)($input['page']??1)));
    $grouped=array_slice($grouped,($page-1)*30,30);
    $rows=[];
    foreach ($grouped as $report) {
        $snapshot=json_decode($report['snapshot'],true)?:[];
        unset($report['snapshot']);
        $report['guest']=$snapshot;
        $report['summary']=egmGuestReportSummary($snapshot);
        $report['decision_label']=$report['decision'] ? egmBenefitsDecision($report['decision'])['label'] : '';
        $rows[]=$report;
    }
    return ['reports'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'more_logs'=>$moreLogs,'active_period_code'=>(string)($context['period_code'] ?? ''),'periods'=>array_map(static fn(array $period):array=>['code'=>egmCheckInPeriodCode($period),'title'=>(string)($period['title'] ?? '')],$context['periods'] ?? [])];
}

function egmGuestReportsRefreshBots(PDO $pdo, array $report): void
{
    foreach (['telegram','bale'] as $provider) systemBotWithProvider($provider, static function() use ($pdo,$report): void {
        if (systemTelegramConfig()['bot_token'] === '' || !egmInstanceTableExists($pdo,systemBotSql('egm_telegram_deliveries'))) return;
        systemTelegramRefreshMessages($pdo,$report);
    });
}
