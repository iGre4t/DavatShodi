<?php
declare(strict_types=1);

function systemTelegramManagerKeyboard(): array
{
    return ['keyboard'=>[[['text'=>'خروجی']]],'resize_keyboard'=>true,'is_persistent'=>true];
}

/** Revalidate the PIN fingerprint for every menu and download. */
function systemTelegramExportGrants(PDO $pdo, string $admin, string $chat): array
{
    $query = $pdo->prepare('SELECT g.*,e.name FROM egm_telegram_grants g JOIN egm e ON e.code=g.egm_code WHERE g.admin_id=? AND g.chat_id=? ORDER BY e.name');
    $query->execute([$admin,$chat]);
    return array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC), static function(array $grant) use ($pdo): bool {
        $hash = systemTelegramPinHash($pdo,$grant['egm_code']);
        return $hash !== '' && hash_equals(hash('sha256',$hash),$grant['pin_fingerprint']);
    }));
}

function systemTelegramExportButton(PDO $pdo, string $admin, string $chat, string $label, array $payload): array
{
    $id = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO egm_telegram_export_menus(id,admin_id,chat_id,payload,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')
        ->execute([$id,$admin,$chat,json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    return ['text'=>$label,'callback_data'=>'egmx:'.$id];
}

function systemTelegramExportMenu(string $chat, string $text, array $buttons): void
{
    $payload=['chat_id'=>$chat,'text'=>$text];
    if ($buttons) $payload['reply_markup']=['inline_keyboard'=>$buttons];
    systemTelegramCall('sendMessage',$payload);
}

function systemTelegramExportHome(PDO $pdo, string $admin, string $chat, int $offset=0): void
{
    $grants = systemTelegramExportGrants($pdo,$admin,$chat);
    if (!$grants) {
        systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'ابتدا با ارسال pin به رویداد متصل شوید.','reply_markup'=>['remove_keyboard'=>true]]);
        return;
    }
    $pdo->exec('DELETE FROM egm_telegram_export_menus WHERE expires_at<NOW()');
    if (count($grants) === 1) {
        systemTelegramExportChoose($pdo,$admin,$chat,['kind'=>'periods','code'=>$grants[0]['egm_code']]);
        return;
    }
    $buttons=[];
    foreach (array_slice($grants,$offset,20) as $grant) $buttons[] = [systemTelegramExportButton($pdo,$admin,$chat,$grant['name'],['kind'=>'periods','code'=>$grant['egm_code']])];
    if ($offset>0) $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,'قبلی',['kind'=>'home','offset'=>max(0,$offset-20)])];
    if (count($grants)>$offset+20) $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,'بعدی',['kind'=>'home','offset'=>$offset+20])];
    systemTelegramExportMenu($chat,'رویداد را انتخاب کنید.',$buttons);
}

/** The optional context is used by isolated database tests. */
function systemTelegramExportChoose(PDO $pdo, string $admin, string $chat, array $selection, ?array $context=null): void
{
    require_once __DIR__.'/egm-period-exports.php';
    if (($selection['kind'] ?? '') === 'home') {
        systemTelegramExportHome($pdo,$admin,$chat,max(0,(int)($selection['offset'] ?? 0)));
        return;
    }
    $code = (string)($selection['code'] ?? '');
    $authorized = array_filter(systemTelegramExportGrants($pdo,$admin,$chat),static fn(array $grant):bool=>$grant['egm_code']===$code);
    if (!$authorized) throw new InvalidArgumentException('دسترسی شما به این رویداد فعال نیست؛ دوباره pin بفرستید.');
    $context ??= systemTelegramContext($pdo,$code);
    if ($context['code'] !== $code) throw new InvalidArgumentException('رویداد معتبر نیست.');
    $name = (string)($context['name'] ?? $code);
    $kind = (string)($selection['kind'] ?? '');
    if ($kind === 'periods') {
        $periods = array_values(egmPeriodInvitesPeriods($context));
        $offset = max(0,(int)($selection['offset'] ?? 0));
        $buttons=[];
        foreach (array_slice($periods,$offset,20) as $period) {
            $periodCode = egmCheckInPeriodCode($period);
            if ($periodCode === '') continue;
            $title = trim((string)($period['title'] ?? '')) ?: $periodCode;
            if ($periodCode === (string)($context['period_code'] ?? '')) $title .= ' · فعال';
            $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,$title,['kind'=>'types','code'=>$code,'period'=>$periodCode])];
        }
        if ($offset>0) $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,'قبلی',['kind'=>'periods','code'=>$code,'offset'=>max(0,$offset-20)])];
        if (count($periods)>$offset+20) $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,'بعدی',['kind'=>'periods','code'=>$code,'offset'=>$offset+20])];
        if (count(systemTelegramExportGrants($pdo,$admin,$chat))>1) $buttons[]=[systemTelegramExportButton($pdo,$admin,$chat,'بازگشت به رویدادها',['kind'=>'home'])];
        systemTelegramExportMenu($chat,$buttons ? $name."\nبازه را انتخاب کنید." : $name."\nهنوز بازه‌ای ساخته نشده است.",$buttons);
        return;
    }
    $periodCode = egmPeriodInvitesValidatePeriod($context,(string)($selection['period'] ?? ''));
    $title = $periodCode;
    foreach (egmPeriodInvitesPeriods($context) as $period) if (egmCheckInPeriodCode($period)===$periodCode) $title = trim((string)($period['title'] ?? '')) ?: $periodCode;
    if ($kind === 'types') {
        $buttons=[];
        foreach (egmPeriodExportTypes() as $type=>$label) $buttons[]=systemTelegramExportButton($pdo,$admin,$chat,$label,['kind'=>'file','code'=>$code,'period'=>$periodCode,'type'=>$type]);
        $rows=array_chunk($buttons,2);
        $rows[]=[systemTelegramExportButton($pdo,$admin,$chat,'بازگشت به بازه‌ها',['kind'=>'periods','code'=>$code])];
        systemTelegramExportMenu($chat,$name.' — '.$title."\nخروجی اکسل را انتخاب کنید.",$rows);
        return;
    }
    if ($kind !== 'file') throw new InvalidArgumentException('گزینه معتبر نیست.');
    $export = egmPeriodExportBuild($context,$periodCode,(string)($selection['type'] ?? ''));
    systemTelegramSendExcel($chat,$export,$name.' — '.$title."\n".egmPeriodExportTypes()[$selection['type']]);
}

function systemTelegramSendExcel(string $chat, array $export, string $caption): void
{
    if (strlen($export['content'])>50*1024*1024) throw new InvalidArgumentException('حجم این خروجی زیاد است؛ آن را از پنل دریافت کنید.');
    $path = tempnam(sys_get_temp_dir(),'egm_excel_');
    if ($path === false) throw new RuntimeException('ساخت فایل اکسل انجام نشد.');
    try {
        if (file_put_contents($path,$export['content']) !== strlen($export['content'])) throw new RuntimeException('ذخیره فایل اکسل انجام نشد.');
        systemTelegramCall('sendDocument',['chat_id'=>$chat,'document'=>new CURLFile($path,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$export['filename']),'caption'=>$caption]);
    } finally { if (is_file($path)) unlink($path); }
}

function systemTelegramExportCallback(PDO $pdo, array $callback, ?array $context=null): void
{
    $admin=(string)($callback['from']['id'] ?? '');
    $message=$callback['message'] ?? [];
    $chat=(string)($message['chat']['id'] ?? '');
    try {
        if (($message['chat']['type'] ?? '') !== 'private' || !preg_match('/^egmx:([a-f0-9]{32})$/D',(string)($callback['data'] ?? ''),$match)) throw new InvalidArgumentException('درخواست معتبر نیست.');
        $query=$pdo->prepare('SELECT payload FROM egm_telegram_export_menus WHERE id=? AND admin_id=? AND chat_id=? AND expires_at>NOW()');
        $query->execute([$match[1],$admin,$chat]);
        $raw=$query->fetchColumn();
        if (!$raw) throw new InvalidArgumentException('این گزینه منقضی شده است؛ دوباره «خروجی» را بزنید.');
        $selection=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!systemTelegramExportGrants($pdo,$admin,$chat)) throw new InvalidArgumentException('دسترسی شما فعال نیست؛ دوباره pin بفرستید.');
    } catch (InvalidArgumentException $error) {
        systemTelegramCall('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>$error->getMessage(),'show_alert'=>true]);
        return;
    }
    // Acknowledge before building/uploading the workbook so Telegram's spinner stops promptly.
    systemTelegramCall('answerCallbackQuery',['callback_query_id'=>$callback['id']]);
    try { systemTelegramExportChoose($pdo,$admin,$chat,$selection,$context); }
    catch (InvalidArgumentException $error) { systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>$error->getMessage()]); }
    catch (Throwable $error) {
        error_log('Telegram Excel export failed: '.get_class($error));
        systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'ارسال خروجی انجام نشد؛ دوباره تلاش کنید.']);
    }
}
