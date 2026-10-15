<?php
declare(strict_types=1);

function egmScanInputNormalize($input): array
{
    $input = is_array($input) ? $input : [];
    $method = is_string($input['method'] ?? null) ? $input['method'] : 'unknown';
    if (!in_array($method, ['typed','scanner','paste','selection','unknown'], true)) $method = 'unknown';
    $timing = [];
    foreach (['key_count','duration_ms','max_gap_ms','avg_gap_ms'] as $field) {
        $value=$input[$field]??0;
        $timing[$field]=is_numeric($value)&&is_finite((float)$value) ? min(600000,max(0,round((float)$value,2))) : 0;
    }
    $labels=['typed'=>'تایپ دستی','scanner'=>'اسکن سریع (تشخیص بر اساس سرعت)','paste'=>'چسباندن کد','selection'=>'انتخاب از فهرست یا پیشنهاد','unknown'=>'نامشخص'];
    $result=['input_method'=>$method,'input_method_label'=>$labels[$method],'input_timing'=>$timing];
    $original=preg_replace('/[^0-9]/','',(string)($input['corrected_from']??''));
    if ($original!==''&&strlen($original)<=11) $result['corrected_from']=$original;
    return $result;
}

function egmScanNearCandidates(string $submitted, array $rows): array
{
    if (!preg_match('/^[0-9]{8,11}$/D',$submitted)) return [];
    $query=strlen($submitted)<10?str_pad($submitted,10,'0',STR_PAD_LEFT):$submitted;
    $result=[];
    foreach ($rows as $row) {
        $id=trim((string)($row['national_id']??''));
        if (preg_match('/^[0-9]{8,10}$/D',$id)!==1) continue;
        $id=str_pad($id,10,'0',STR_PAD_LEFT);
        $distance=levenshtein($query,$id);
        if ($distance>2) continue;
        $result[]=['user_id'=>(int)($row['id']??0),'national_id'=>$id,'name'=>trim((string)($row['first_name']??'').' '.(string)($row['last_name']??'')),
            'difference'=>$distance,'already_entered'=>!empty($row['entered_date'])&&empty($row['quit_date'])];
    }
    usort($result,static fn($a,$b)=>($a['difference']<=>$b['difference'])?:($a['user_id']<=>$b['user_id']));
    return array_slice($result,0,5);
}

function egmScanSuggestions(array $context,string $submitted): array
{
    if (($context['scan_input']['input_method']??'unknown')!=='typed' || !preg_match('/^[0-9]{8,11}$/D',$submitted)) return [];
    $period=$context['period']??null;
    if (!is_array($period)) return [];
    $users=(string)$context['tables']['users'];$periods=(string)$context['tables']['user_periods'];
    $stmt=$context['pdo']->prepare("SELECT u.`id`,u.`first_name`,u.`last_name`,u.`national_id`,p.`entered_date`,p.`quit_date` FROM `{$periods}` p JOIN `{$users}` u ON u.`id`=p.`user_id` WHERE p.`period_code`=:period AND COALESCE(u.`is_active`,1)=1 AND COALESCE(p.`is_uninvited_guest`,0)=0 AND LOWER(TRIM(COALESCE(p.`invitation_source`,'')))<>'walk_in'");
    $stmt->execute(['period'=>egmCheckInPeriodCode($period)]);
    return egmScanNearCandidates($submitted,$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

/** Resolve first; this route never checks in, checks out, logs or prints a guest. */
function egmScanLookupCandidates(array $context, string $typed, string $scanned): array
{
    $period=$context['period']??null;
    if (!is_array($period)) throw new InvalidArgumentException('بازهٔ فعالی برای بررسی مهمان وجود ندارد.');
    $periodCode=egmCheckInPeriodCode($period); $people=[];
    foreach (['typed'=>$typed,'scanner'=>$scanned] as $method=>$raw) {
        $code=egmCheckInNormalizeDigits($raw);
        if (strlen($code)<2 || strlen($code)>11) continue;
        $user=strlen($code)<=10?egmCheckInFindUser($context['pdo'],(string)$context['tables']['users'],$code):null;
        $matches=[];
        if (is_array($user) && (int)($user['is_active']??1)!==0) {
            $table=(string)$context['tables']['user_periods'];
            $find=$context['pdo']->prepare("SELECT entered_date,quit_date FROM `{$table}` WHERE user_id=:id AND period_code=:period LIMIT 1");
            $find->execute(['id'=>(int)$user['id'],'period'=>$periodCode]);$invitation=$find->fetch(PDO::FETCH_ASSOC);
            if (is_array($invitation)) $matches=[['user_id'=>(int)$user['id'],'national_id'=>(string)($user['national_id']??''),'name'=>trim((string)($user['first_name']??'').' '.(string)($user['last_name']??'')),'difference'=>0,'already_entered'=>!empty($invitation['entered_date'])&&empty($invitation['quit_date']),'guest_code'=>$code]];
        }
        if (!$matches && $method==='typed') $matches=egmScanSuggestions(array_replace($context,['scan_input'=>['input_method'=>'typed']]),$code);
        foreach($matches as $match) $people[]=$match+['guest_code'=>$match['national_id'],'input_method'=>$method];
    }
    return ['period_code'=>$periodCode,'candidates'=>$people];
}
