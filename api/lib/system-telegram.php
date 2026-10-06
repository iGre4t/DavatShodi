<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-check-in.php';
require_once __DIR__ . '/egm-registry.php';
require_once __DIR__ . '/system-telegram-exports.php';

final class SystemTelegramApiException extends RuntimeException
{
    public function __construct(public int $apiCode, public string $description)
    { parent::__construct('ارتباط با ربات تلگرام ناموفق بود.'); }
}

function systemTelegramConfig(): array
{
    $path = dirname(__DIR__) . '/telegram.config.local.php';
    $config = is_file($path) ? require $path : [];
    return ['bot_token'=>trim((string)(getenv('DAVATSHODI_TELEGRAM_TOKEN') ?: ($config['bot_token'] ?? ''))),
        'webhook_secret'=>trim((string)(getenv('DAVATSHODI_TELEGRAM_SECRET') ?: ($config['webhook_secret'] ?? '')))];
}

function systemTelegramCall(string $method, array $payload = []): array
{
    if (PHP_SAPI === 'cli' && is_callable($GLOBALS['systemTelegramTestTransport'] ?? null)) {
        return ($GLOBALS['systemTelegramTestTransport'])($method, $payload);
    }
    $token = systemTelegramConfig()['bot_token'];
    if ($token === '') throw new RuntimeException('توکن ربات تلگرام تنظیم نشده است.');
    $handle = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    $multipart = false;
    foreach ($payload as $value) if ($value instanceof CURLFile) $multipart = true;
    if ($multipart) {
        foreach ($payload as &$value) if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        unset($value);
    }
    curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$multipart ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>$multipart ? [] : ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>($method === 'getUpdates' ? 40 : ($multipart ? 120 : 15))]);
    $raw = curl_exec($handle);
    $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($data) || empty($data['ok'])) throw new SystemTelegramApiException((int)($data['error_code'] ?? $code), (string)($data['description'] ?? ''));
    return is_array($data['result'] ?? null) ? $data['result'] : ['value'=>$data['result'] ?? true];
}

function systemTelegramEnsure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_grants (egm_code VARCHAR(64) NOT NULL,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,pin_fingerprint CHAR(64) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(egm_code,admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_sessions (admin_id BIGINT PRIMARY KEY,awaiting_pin TINYINT NOT NULL DEFAULT 0,expires_at DATETIME NULL,failures INT NOT NULL DEFAULT 0,blocked_until DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_reports (id CHAR(32) PRIMARY KEY,egm_code VARCHAR(64) NOT NULL,period_code VARCHAR(64) NOT NULL,log_id BIGINT NOT NULL,user_id BIGINT NULL,guest_code VARCHAR(32) NOT NULL,snapshot LONGTEXT NOT NULL,decision VARCHAR(16) NULL,decided_by BIGINT NULL,decided_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_egm_report(egm_code,log_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_deliveries (report_id CHAR(32) NOT NULL,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,message_id BIGINT NOT NULL,PRIMARY KEY(report_id,admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_decisions (id BIGINT AUTO_INCREMENT PRIMARY KEY,egm_code VARCHAR(64) NOT NULL,period_code VARCHAR(64) NOT NULL,guest_code VARCHAR(32) NOT NULL,user_id BIGINT NULL,gift TINYINT NOT NULL,draw TINYINT NOT NULL,admin_id BIGINT NOT NULL,report_id CHAR(32) NOT NULL,decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_egm_guest_decision(egm_code,period_code,guest_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS egm_telegram_export_menus (id CHAR(32) PRIMARY KEY,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,payload LONGTEXT NOT NULL,expires_at DATETIME NOT NULL,KEY ix_expiry(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_telegram_updates (update_id BIGINT PRIMARY KEY,completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function systemTelegramPinHash(PDO $pdo, string $code): string
{
    $settings = egmInstanceReadData($pdo, $code, 'settings', []);
    return trim((string)($settings['adminPasscode']['hash'] ?? ''));
}

function systemTelegramContext(PDO $pdo, string $code): array
{
    $record = findEgmRegistryByCode($pdo, $code);
    if (!$record) throw new InvalidArgumentException('رویداد پیدا نشد.');
    $root = dirname(__DIR__, 2);
    $directory = normalizeEgmRegistryDirectory($record['directory']);
    $real = realpath($root . '/' . $directory);
    if (!$real || !str_starts_with(strtolower(str_replace('\\','/',$real)), strtolower(str_replace('\\','/',$root)) . '/')) throw new RuntimeException('مسیر رویداد معتبر نیست.');
    return egmCheckInContext($root, $real);
}

function systemTelegramKeyboard(string $reportId): array
{
    $rows = [];
    foreach (['both','deny','gift','draw'] as $choice) {
        $decision = egmBenefitsDecision($choice);
        $rows[] = ['text'=>$decision['label'], 'callback_data'=>'egm:' . $reportId . ':' . $choice];
    }
    return ['inline_keyboard'=>[array_slice($rows,0,2),array_slice($rows,2,2)]];
}

function systemTelegramReportText(array $report): string
{
    $row = json_decode($report['snapshot'], true) ?: [];
    require_once __DIR__ . '/egm-period-exports.php';
    $statusLabel=egmPeriodExportConditionLabels()[$row['status'] ?? ''] ?? 'بررسی ناموفق';
    $parts = ['گزارش به مدیریت', 'رویداد: ' . ($row['event_name'] ?? $report['egm_code']), 'بازه: ' . (($row['period_title'] ?? '') ?: $report['period_code']),
        'مهمان: ' . (($row['full_name'] ?? '') ?: 'نامشخص'), 'وضعیت: ' . $statusLabel, 'شرح: ' . ($row['message'] ?? '')];
    foreach (['national_id'=>'کد ملی','work_id'=>'کد پرسنلی','guest_number'=>'شماره مهمان','phone_number'=>'همراه','department'=>'اداره','gender'=>'جنسیت','attempted_at'=>'زمان بررسی','reported_by'=>'اپراتور'] as $field=>$label) {
        if (trim((string)($row[$field] ?? '')) !== '') $parts[] = $label . ': ' . $row[$field];
    }
    if (!empty($row['entered_date']) && !empty($row['entered_time'])) $parts[] = 'زمان ورود: ' . $row['entered_date'] . ' ' . $row['entered_time'];
    if (!empty($report['decision'])) $parts[] = 'تصمیم مدیر: ' . egmBenefitsDecision($report['decision'])['label'];
    $text = implode("\n", $parts);
    return function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 3900);
}

function systemTelegramDeliver(PDO $pdo, array $report): array
{
    $key='telegram_report_'.$report['id'];
    $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$key]);
    if ((int)$lock->fetchColumn() !== 1) return ['sent'=>0,'failed'=>0];
    try { return systemTelegramDeliverUnlocked($pdo,$report); }
    finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
}

function systemTelegramDeliverUnlocked(PDO $pdo, array $report): array
{
    $query = $pdo->prepare('SELECT g.* FROM egm_telegram_grants g LEFT JOIN egm_telegram_deliveries d ON d.report_id=? AND d.admin_id=g.admin_id WHERE g.egm_code=? AND d.report_id IS NULL');
    $query->execute([$report['id'], $report['egm_code']]);
    $hash = systemTelegramPinHash($pdo, $report['egm_code']);
    $sent = 0; $failed = 0;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $grant) {
        if ($hash === '' || !hash_equals(hash('sha256',$hash), $grant['pin_fingerprint'])) continue;
        try {
            $payload = ['chat_id'=>$grant['chat_id'], 'text'=>systemTelegramReportText($report)];
            if (empty($report['decision'])) $payload['reply_markup'] = systemTelegramKeyboard($report['id']);
            $message = systemTelegramCall('sendMessage', $payload);
            $pdo->prepare('INSERT IGNORE INTO egm_telegram_deliveries(report_id,admin_id,chat_id,message_id) VALUES(?,?,?,?)')->execute([$report['id'],$grant['admin_id'],$grant['chat_id'],$message['message_id']]);
            $sent++;
        } catch (Throwable $error) { $failed++; }
    }
    return ['sent'=>$sent,'failed'=>$failed];
}

function systemTelegramCreateReport(array $context, int $logId, array $actor): array
{
    if (systemTelegramConfig()['bot_token'] === '') throw new InvalidArgumentException('ربات تلگرام هنوز تنظیم نشده است.');
    $rows = egmCheckInRecentLogs($context, 1, '', $logId);
    $row = $rows[0] ?? null;
    if (!$row || empty($row['management_report_eligible'])) throw new InvalidArgumentException('این گزارش قابل ارسال به مدیریت نیست.');
    if ($row['period_code'] === '') throw new InvalidArgumentException('بازه این گزارش مشخص نیست.');
    $pdo = $context['pdo'];systemTelegramEnsure($pdo);
    $id = bin2hex(random_bytes(16));
    $row['event_name'] = $context['name'];$row['reported_by'] = $actor['username'] ?? $actor['code'] ?? '';
    $pdo->prepare('INSERT IGNORE INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$context['code'],$row['period_code'],$logId,$row['user_id'] ?: null,$row['national_id'] ?: $row['work_id'],json_encode($row,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $find = $pdo->prepare('SELECT * FROM egm_telegram_reports WHERE egm_code=? AND log_id=?');$find->execute([$context['code'],$logId]);$report=$find->fetch(PDO::FETCH_ASSOC);
    $result = systemTelegramDeliver($pdo,$report);
    return ['report_id'=>$report['id'],'message'=>$result['sent'] ? 'گزارش برای مدیران تلگرام ارسال شد.' : 'گزارش ذخیره شد؛ پس از اتصال مدیر یا برقراری ارتباط ارسال می‌شود.'] + $result;
}

function systemTelegramDecide(PDO $pdo, string $id, string $choice, string $adminId, ?array $context = null): array
{
    $decision = egmBenefitsDecision($choice);
    $find = $pdo->prepare('SELECT * FROM egm_telegram_reports WHERE id=?');$find->execute([$id]);$report=$find->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new InvalidArgumentException('گزارش پیدا نشد.');
    $context ??= systemTelegramContext($pdo,$report['egm_code']);
    if ($context['code'] !== $report['egm_code']) throw new InvalidArgumentException('رویداد گزارش تطابق ندارد.');
    $grant = $pdo->prepare('SELECT pin_fingerprint FROM egm_telegram_grants WHERE egm_code=? AND admin_id=?');$grant->execute([$report['egm_code'],$adminId]);
    $fingerprint = $grant->fetchColumn();$hash = systemTelegramPinHash($pdo,$report['egm_code']);
    if (!$fingerprint || $hash === '' || !hash_equals(hash('sha256',$hash), (string)$fingerprint)) throw new InvalidArgumentException('برای این رویداد دسترسی ندارید؛ دوباره pin بفرستید.');
    // Use the context connection for the guest update and decision in one transaction.
    $db=$context['pdo'];$db->beginTransaction();
    try {
        $lock=$db->prepare('SELECT * FROM egm_telegram_reports WHERE id=? FOR UPDATE');$lock->execute([$id]);$report=$lock->fetch(PDO::FETCH_ASSOC);
        if ($report['decision'] !== null) throw new InvalidArgumentException('این گزارش قبلاً بررسی شده است.');
        $user=egmCheckInFindUser($db,$context['tables']['users'],$report['guest_code']);
        $prior=$db->prepare('SELECT r.created_at FROM egm_telegram_decisions d JOIN egm_telegram_reports r ON r.id=d.report_id WHERE d.egm_code=? AND d.period_code=? AND (d.guest_code=? OR (? IS NOT NULL AND d.user_id=?)) ORDER BY r.created_at DESC LIMIT 1 FOR UPDATE');
        $prior->execute([$report['egm_code'],$report['period_code'],$report['guest_code'],$user['id'] ?? null,$user['id'] ?? null]);$newer=$prior->fetchColumn();
        if ($newer && $newer > $report['created_at']) throw new InvalidArgumentException('گزارش جدیدتری برای این مهمان بررسی شده است.');
        $db->prepare('INSERT INTO egm_telegram_decisions(egm_code,period_code,guest_code,user_id,gift,draw,admin_id,report_id) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),gift=VALUES(gift),draw=VALUES(draw),admin_id=VALUES(admin_id),report_id=VALUES(report_id),decided_at=NOW()')
            ->execute([$report['egm_code'],$report['period_code'],$report['guest_code'],$user['id'] ?? null,$decision['gift'],$decision['draw'],$adminId,$id]);
        if ($user) $db->prepare("UPDATE `{$context['tables']['user_periods']}` SET should_get_gift=?,draw_eligible=?,benefits_reviewed_at=NOW(),benefits_reviewed_by=? WHERE user_id=? AND period_code=?")
            ->execute([$decision['gift'],$decision['draw'],'telegram:'.$adminId,$user['id'],$report['period_code']]);
        $db->prepare('UPDATE egm_telegram_reports SET decision=?,decided_by=?,decided_at=NOW() WHERE id=?')->execute([$choice,$adminId,$id]);
        $db->commit();
    } catch (Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
    $report['decision']=$choice;
    return $report;
}

function systemTelegramRefreshMessages(PDO $pdo, array $report): void
{
    $query=$pdo->prepare('SELECT * FROM egm_telegram_deliveries WHERE report_id=?');$query->execute([$report['id']]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
        try { systemTelegramCall('editMessageText',['chat_id'=>$delivery['chat_id'],'message_id'=>$delivery['message_id'],'text'=>systemTelegramReportText($report),'reply_markup'=>['inline_keyboard'=>[]]]); }
        catch (Throwable $error) { /* The stored decision remains authoritative. */ }
    }
}

function systemTelegramUpdate(PDO $pdo, array $update): void
{
    $callback=$update['callback_query'] ?? null;
    if (is_array($callback)) {
        if (str_starts_with((string)($callback['data'] ?? ''), 'egmx:')) {
            systemTelegramExportCallback($pdo, $callback);
            return;
        }
        $answer='';
        try {
            if (!preg_match('/^egm:([a-f0-9]{32}):(both|gift|draw|deny)$/D',(string)($callback['data'] ?? ''),$parts)) throw new InvalidArgumentException('درخواست معتبر نیست.');
            $report=systemTelegramDecide($pdo,$parts[1],$parts[2],(string)$callback['from']['id']);
            $answer='تصمیم ثبت شد: '.egmBenefitsDecision($parts[2])['label'];systemTelegramRefreshMessages($pdo,$report);
        } catch (InvalidArgumentException $error) {$answer=$error->getMessage();}
        systemTelegramCall('answerCallbackQuery',['callback_query_id'=>$callback['id'],'text'=>$answer,'show_alert'=>true]);
        return;
    }
    $message=$update['message'] ?? null;
    if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private' || !empty($message['from']['is_bot'])) return;
    $admin=(string)$message['from']['id'];$chat=(string)$message['chat']['id'];$text=trim((string)($message['text'] ?? ''));
    $send=static fn(string $text)=>systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>$text]);
    if (in_array($text, ['خروجی', '/exports'], true)) {
        systemTelegramExportHome($pdo, $admin, $chat);
        return;
    }
    if (strtolower($text) === '/start' && systemTelegramExportGrants($pdo, $admin, $chat)) {
        systemTelegramCall('sendMessage', ['chat_id'=>$chat,'text'=>'گزینه موردنظر را انتخاب کنید.','reply_markup'=>systemTelegramManagerKeyboard()]);
        return;
    }
    if (in_array(strtolower($text),['/start','/pin','pin'],true)) {
        $pdo->prepare('INSERT INTO egm_telegram_sessions(admin_id,awaiting_pin,expires_at) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 5 MINUTE)) ON DUPLICATE KEY UPDATE awaiting_pin=1,expires_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)')->execute([$admin]);
        $send('پین مدیریت رویداد را بفرستید.');return;
    }
    if ($text === '/logout') {
        $pdo->prepare('DELETE FROM egm_telegram_grants WHERE admin_id=?')->execute([$admin]);
        $pdo->prepare('DELETE FROM egm_telegram_sessions WHERE admin_id=?')->execute([$admin]);systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'دسترسی تلگرام شما حذف شد.','reply_markup'=>['remove_keyboard'=>true]]);return;
    }
    $query=$pdo->prepare('SELECT *,expires_at>NOW() AS valid_session,blocked_until>NOW() AS blocked FROM egm_telegram_sessions WHERE admin_id=?');$query->execute([$admin]);$session=$query->fetch(PDO::FETCH_ASSOC);
    if (!$session || empty($session['awaiting_pin']) || empty($session['valid_session'])) {$send('برای دسترسی به رویداد، pin بفرستید.');return;}
    // PINs are never stored; remove the submitted private message before granting access.
    try {systemTelegramCall('deleteMessage',['chat_id'=>$chat,'message_id'=>$message['message_id']]);}
    catch (SystemTelegramApiException $error) {
        // A webhook retry can refer to the PIN message already removed by the first attempt.
        if ($error->apiCode !== 400 || !str_contains(strtolower($error->description),'message to delete not found')) throw $error;
    }
    if (!empty($session['blocked'])) {$send('تلاش‌های ناموفق زیاد بوده است؛ ده دقیقه دیگر تلاش کنید.');return;}
    $pin=egmCheckInNormalizeDigits($text);$matches=[];
    if (preg_match('/^[0-9]{4,6}$/D',$pin)) foreach (listEgmRegistry($pdo) as $record) {
        $hash=systemTelegramPinHash($pdo,$record['code']);
        if ($hash !== '' && password_verify($pin,$hash)) $matches[]=$record+['pin_hash'=>$hash];
    }
    if (!$matches) {
        $pdo->prepare('UPDATE egm_telegram_sessions SET failures=failures+1,blocked_until=IF(failures>=5,DATE_ADD(NOW(),INTERVAL 10 MINUTE),NULL) WHERE admin_id=?')->execute([$admin]);
        $send('پین صحیح نیست. دوباره تلاش کنید.');return;
    }
    foreach ($matches as $record) $pdo->prepare('INSERT INTO egm_telegram_grants(egm_code,admin_id,chat_id,pin_fingerprint) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),pin_fingerprint=VALUES(pin_fingerprint)')
        ->execute([$record['code'],$admin,$chat,hash('sha256',$record['pin_hash'])]);
    $pdo->prepare('UPDATE egm_telegram_sessions SET awaiting_pin=0,failures=0,blocked_until=NULL WHERE admin_id=?')->execute([$admin]);
    systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'دسترسی شما فعال شد: '.implode('، ',array_column($matches,'name')),'reply_markup'=>systemTelegramManagerKeyboard()]);
}

function systemTelegramProcess(PDO $pdo, array $update): void
{
    $id=(int)($update['update_id'] ?? 0);if($id<1)return;
    $lock='telegram_update_'.$id;
    $query=$pdo->prepare('SELECT GET_LOCK(?,5)');$query->execute([$lock]);if((int)$query->fetchColumn()!==1)throw new RuntimeException('Update busy');
    try {
        $seen=$pdo->prepare('SELECT update_id FROM system_telegram_updates WHERE update_id=?');$seen->execute([$id]);if($seen->fetchColumn())return;
        systemTelegramUpdate($pdo,$update);
        $pdo->prepare('INSERT IGNORE INTO system_telegram_updates(update_id) VALUES(?)')->execute([$id]);
    } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}

function systemTelegramRetry(PDO $pdo): void
{
    foreach ($pdo->query('SELECT r.* FROM egm_telegram_reports r WHERE r.decision IS NULL AND EXISTS(SELECT 1 FROM egm_telegram_grants g WHERE g.egm_code=r.egm_code AND NOT EXISTS(SELECT 1 FROM egm_telegram_deliveries d WHERE d.report_id=r.id AND d.admin_id=g.admin_id)) ORDER BY r.created_at LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $report) systemTelegramDeliver($pdo,$report);
}
