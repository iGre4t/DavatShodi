<?php
declare(strict_types=1);

function egmOeuImportFields(): array
{
    return ['first_name','last_name','national_id','work_id','phone_number','deputy','general_department','department','gender','postal_level'];
}

/** Preview only: no writes to OEU or EGM, including already invited guests. */
function egmOeuImportPreview(array $context, string $period, array $inputs): array
{
    if (count($inputs)>1000) throw new InvalidArgumentException('هر بخش فایل باید حداکثر ۱۰۰۰ ردیف داشته باشد.');
    $pdo=$context['pdo']; $fields=egmOeuImportFields(); $columns='id,'.implode(',',array_map(static fn($field)=>'`'.$field.'`',$fields));
    $query=$pdo->prepare('SELECT '.$columns.' FROM `organizational_event_users` WHERE national_id=:national OR work_id=:work');
    $updates=[]; $issues=[];
    foreach ($inputs as $i=>$input) {
        if (!is_array($input)) continue;
        $row=egmPeriodInvitesNormalizeExcelRow($input,$i+2);
        $national=egmPeriodInvitesValidNationalId($row['national_id']??''); $work=egmPeriodInvitesWorkId($row['work_id']??'');
        if ($national==='' && $work==='') continue;
        // Empty identifiers must not match the empty identifiers of unrelated users.
        $query->execute(['national'=>$national!==''?$national:'__no_national__','work'=>$work!==''?$work:'__no_work__']);
        $matches=$query->fetchAll(PDO::FETCH_ASSOC);
        if (!$matches) continue;
        if (count($matches)!==1) { $issues[]=['excel_id'=>$row['excel_id'],'message'=>'کد ملی یا کد پرسنلی این ردیف به چند کاربر متفاوت تعلق دارد.']; continue; }
        $existing=$matches[0]; $changes=egmPeriodInvitesExcelProfileChanges($existing,$input);
        // An empty identity cell is not a request to erase an existing identity.
        foreach (['national_id','work_id'] as $identity) if (isset($changes[$identity]) && $changes[$identity]==='') unset($changes[$identity]);
        if (!$changes) continue;
        $id=(int)$existing['id'];
        if (isset($updates[$id]) && $updates[$id]['changes']!==$changes) {
            $updates[$id]['conflict']=true;
            $issues[]=['excel_id'=>$updates[$id]['excel_id'],'message'=>'برای یک کاربر، اطلاعات متفاوتی در چند ردیف فایل آمده است.'];
            $issues[]=['excel_id'=>$row['excel_id'],'message'=>'برای یک کاربر، اطلاعات متفاوتی در چند ردیف فایل آمده است.']; continue;
        }
        if (!isset($updates[$id])) $updates[$id]=['id'=>$id,'name'=>trim($existing['first_name'].' '.$existing['last_name']),'work_id'=>$existing['work_id'],'excel_id'=>$row['excel_id'],'before'=>array_intersect_key($existing,array_flip($fields)),'changes'=>$changes];
    }
    $updates=array_values(array_filter($updates,static fn($update)=>empty($update['conflict'])));
    $token=bin2hex(random_bytes(20));
    $key=(string)$context['code'];
    $_SESSION['egm_oeu_import_reviews']??=[];
    foreach ($_SESSION['egm_oeu_import_reviews'] as $old=>$review) if (($review['expires']??0)<time()) unset($_SESSION['egm_oeu_import_reviews'][$old]);
    if (count($_SESSION['egm_oeu_import_reviews'])>100) throw new InvalidArgumentException('بررسی‌های قبلی را ببندید و دوباره فایل را بارگذاری کنید.');
    if ($updates) $_SESSION['egm_oeu_import_reviews'][$token]=['event'=>$key,'period'=>$period,'expires'=>time()+1800,'updates'=>$updates];
    return ['token'=>$updates?$token:'','updates'=>$updates,'issues'=>$issues];
}

/** Apply only server-staged changes explicitly selected in the review dialog. */
function egmOeuImportApply(array $context, string $period, string $token, array $ids, string $actor): int
{
    $review=$_SESSION['egm_oeu_import_reviews'][$token]??null;
    if (!is_array($review) || $review['expires']<time() || $review['event']!==(string)$context['code'] || $review['period']!==$period) throw new InvalidArgumentException('بررسی فایل منقضی شده است. دوباره تطبیق دهید.');
    $wanted=array_fill_keys(array_map('intval',$ids),true); $updates=[];
    foreach ($review['updates'] as $update) if (isset($wanted[$update['id']])) { $updates[]=$update; unset($wanted[$update['id']]); }
    if ($wanted) throw new InvalidArgumentException('کاربر انتخاب‌شده در بررسی فایل نیست.');
    if (!$updates) return 0;
    $pdo=$context['pdo']; $pdo->beginTransaction();
    try {
        $read=$pdo->prepare('SELECT * FROM `organizational_event_users` WHERE id=? FOR UPDATE');
        foreach ($updates as $update) {
            $read->execute([$update['id']]); $current=$read->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new InvalidArgumentException('کاربر دیگر در OEU وجود ندارد. دوباره تطبیق دهید.');
            foreach ($update['before'] as $field=>$before) if ((string)($current[$field]??'')!==(string)$before) throw new InvalidArgumentException('اطلاعات '.$update['name'].' پس از بررسی تغییر کرده است. دوباره تطبیق دهید.');
            foreach (['national_id','work_id'] as $field) if (isset($update['changes'][$field])) {
                $unique=$pdo->prepare('SELECT id FROM `organizational_event_users` WHERE `'.$field.'`=? AND id<>? LIMIT 1 FOR UPDATE');
                $unique->execute([$update['changes'][$field],$update['id']]);
                if ($unique->fetchColumn()) throw new InvalidArgumentException('کد ملی یا پرسنلی پیشنهادی متعلق به کاربر دیگری است.');
            }
            $sets=[]; $values=[];
            foreach ($update['changes'] as $field=>$value) { if (!in_array($field,egmOeuImportFields(),true)) throw new InvalidArgumentException('فیلد نامعتبر است.'); $sets[]='`'.$field.'`=?'; $values[]=$value; }
            $values[]=$update['id'];
            $pdo->prepare('UPDATE `organizational_event_users` SET '.implode(',',$sets).' WHERE id=?')->execute($values);
        }
        $pdo->commit(); unset($_SESSION['egm_oeu_import_reviews'][$token]);
        try {
            if (!empty($context['logs_pdo']) && !empty($context['tables']['activity_logs'])) {
                $table=$context['tables']['activity_logs'];
                $context['logs_pdo']->prepare("INSERT INTO `{$table}` (source_key,action,entity_type,entity_id,level,status,message,metadata_json,occurred_at) VALUES(?,'egm.oeu_import_update','period',?,'info','success',?,?,NOW())")->execute([hash('sha256',$token),$period,'به‌روزرسانی تأییدشده OEU از اکسل بازه',json_encode(['actor'=>$actor,'updates'=>$updates],JSON_UNESCAPED_UNICODE)]);
            }
        } catch (Throwable $error) { error_log('EGM OEU import audit log failed: '.$error->getMessage()); }
        return count($updates);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
