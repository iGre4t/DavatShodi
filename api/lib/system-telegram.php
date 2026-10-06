<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-check-in.php';
require_once __DIR__ . '/egm-registry.php';
require_once __DIR__ . '/system-telegram-exports.php';

// Shared workflow, isolated credentials, identities and delivery receipts.
function systemBotProvider(): string { return ($GLOBALS['systemBotProvider'] ?? 'telegram') === 'bale' ? 'bale' : 'telegram'; }
function systemBotWithProvider(string $provider, callable $action): mixed
{
    if (!in_array($provider, ['telegram', 'bale'], true)) throw new InvalidArgumentException('Invalid bot provider.');
    $previous = systemBotProvider(); $GLOBALS['systemBotProvider'] = $provider;
    try { return $action(); } finally { $GLOBALS['systemBotProvider'] = $previous; }
}
function systemBotSql(string $sql): string
{
    if (systemBotProvider() !== 'bale') return $sql;
    return str_replace(['egm_telegram_grants','egm_telegram_sessions','egm_telegram_deliveries','egm_telegram_export_menus','system_telegram_updates'],
        ['egm_bale_grants','egm_bale_sessions','egm_bale_deliveries','egm_bale_export_menus','system_bale_updates'], $sql);
}
function systemBotPrepare(PDO $pdo, string $sql): PDOStatement|false { return $pdo->prepare(systemBotSql($sql)); }
function systemBotExec(PDO $pdo, string $sql): int|false { return $pdo->exec(systemBotSql($sql)); }
function systemBotQuery(PDO $pdo, string $sql): PDOStatement|false { return $pdo->query(systemBotSql($sql)); }

final class SystemTelegramApiException extends RuntimeException
{
    public function __construct(public int $apiCode, public string $description)
    { parent::__construct('ارتباط با ربات پیام‌رسان ناموفق بود.'); }
}

function systemTelegramConfig(): array
{
    $prefix = systemBotProvider() === 'bale' ? 'DAVATSHODI_BALE_' : 'DAVATSHODI_TELEGRAM_';
    $path = dirname(__DIR__) . '/' . systemBotProvider() . '.config.local.php';
    $config = is_file($path) ? require $path : [];
    return ['bot_token'=>trim((string)(getenv($prefix.'TOKEN') ?: ($config['bot_token'] ?? ''))),
        'webhook_secret'=>trim((string)(getenv($prefix.'SECRET') ?: ($config['webhook_secret'] ?? ''))),
        'proxy_url'=>trim((string)(getenv($prefix.'PROXY') ?: ($config['proxy_url'] ?? ''))),
        'proxy_username'=>(string)(getenv($prefix.'PROXY_USERNAME') ?: ($config['proxy_username'] ?? '')),
        'proxy_password'=>(string)(getenv($prefix.'PROXY_PASSWORD') ?: ($config['proxy_password'] ?? '')),
        'api_base'=>systemBotProvider() === 'bale' ? 'https://tapi.bale.ai' : 'https://api.telegram.org'];
}

function systemTelegramProxyOptions(array $config): array
{
    $proxy=(string)($config['proxy_url']??'');
    if($proxy==='')return [CURLOPT_PROXY=>''];
    $url=parse_url($proxy);
    if(!is_array($url)||!in_array($url['scheme']??'', ['http','https','socks5','socks5h'],true)||empty($url['host'])||empty($url['port'])||$url['port']<1||$url['port']>65535||isset($url['user'])||isset($url['pass'])||!empty($url['query'])||!empty($url['fragment'])||!in_array($url['path']??'', ['', '/'],true))throw new InvalidArgumentException('پروکسی باید به صورت socks5h://HOST:PORT یا http://HOST:PORT باشد؛ نام کاربری و رمز در فیلدهای جدا وارد شوند.');
    $options=[CURLOPT_PROXY=>$proxy,CURLOPT_NOPROXY=>''];
    if(($config['proxy_username']??'')!==''){
        $options[CURLOPT_PROXYUSERNAME]=$config['proxy_username'];$options[CURLOPT_PROXYPASSWORD]=$config['proxy_password']??'';
    }
    return $options;
}

function systemTelegramTransport(string $method,array $payload=[],?array $config=null,int $diagnosticTimeout=0):array
{
    $config??=systemTelegramConfig();
    if(!extension_loaded('curl'))throw new RuntimeException('افزونه cURL روی سرور فعال نیست.');
    $token=$config['bot_token'];if($token==='')throw new RuntimeException('توکن ربات پیام‌رسان تنظیم نشده است.');
    if(!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$method))throw new InvalidArgumentException('Invalid Telegram method.');
    $options=systemTelegramProxyOptions($config);
    $handle=curl_init(($config['api_base']??'https://api.telegram.org').'/bot'.$token.'/'.$method);
    $multipart=false;foreach($payload as $value)if($value instanceof CURLFile)$multipart=true;
    if($multipart){foreach($payload as &$value)if(is_array($value))$value=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);unset($value);}
    curl_setopt_array($handle,$options+[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$payload:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>$multipart?[]:['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_CONNECTTIMEOUT=>$diagnosticTimeout?4:8,CURLOPT_TIMEOUT=>$diagnosticTimeout?:($method==='getUpdates'?40:($multipart?120:15))
    ]);
    $raw=curl_exec($handle);$errno=curl_errno($handle);$info=curl_getinfo($handle);curl_close($handle);
    return ['errno'=>$errno,'http_status'=>(int)($info['http_code']??0),'elapsed_ms'=>(int)round(($info['total_time']??0)*1000),
        'data'=>is_string($raw)?json_decode($raw,true):null];
}

function systemTelegramCall(string $method, array $payload = []): array
{
    $testTransport=$GLOBALS[systemBotProvider()==='bale'?'systemBaleTestTransport':'systemTelegramTestTransport']??null;
    if (PHP_SAPI === 'cli' && is_callable($testTransport)) {
        return $testTransport($method, $payload);
    }
    $result=systemTelegramTransport($method,$payload);$code=$result['http_status'];$data=$result['data'];
    if($result['errno'])throw new RuntimeException(systemTelegramNetworkMessage($result['errno']));
    if ($code !== 200 || !is_array($data) || empty($data['ok'])) throw new SystemTelegramApiException((int)($data['error_code'] ?? $code), (string)($data['description'] ?? ''));
    return is_array($data['result'] ?? null) ? $data['result'] : ['value'=>$data['result'] ?? true];
}

function systemTelegramNetworkMessage(int $errno):string
{
    return match($errno){5=>'نام میزبان پروکسی پیدا نشد.',6=>'نام api.telegram.org روی سرور پیدا نشد.',7=>'اتصال برقرار نشد؛ محدودیت شبکه یا دسترسی پروکسی را بررسی کنید.',28=>'زمان اتصال تمام شد؛ احتمال محدودیت مسیر شبکه وجود دارد.',35,60=>'اتصال TLS یا اعتبار گواهی ناموفق بود؛ گواهی‌های CA و ساعت سرور را بررسی کنید.',default=>'ارتباط شبکه با پیام‌رسان ناموفق بود. کد cURL: '.$errno};
}

function systemTelegramDiagnoseResult(array $result):array
{
    $base=['curl_code'=>$result['errno'],'http_status'=>$result['http_status'],'elapsed_ms'=>$result['elapsed_ms']];
    if($result['errno'])return $base+['ok'=>false,'kind'=>'network','message'=>systemTelegramNetworkMessage($result['errno'])];
    $data=$result['data'];
    if(is_array($data)&&($data['ok']??false))return $base+['ok'=>true,'kind'=>'ok','message'=>'سرور به پیام‌رسان دسترسی دارد و توکن معتبر است.','bot_username'=>$data['result']['username']??''];
    if(is_array($data)&&in_array((int)($data['error_code']??0),[401,404],true))return $base+['ok'=>false,'kind'=>'token','message'=>'پیام‌رسان در دسترس است، اما توکن نامعتبر یا لغو شده است.'];
    return $base+['ok'=>false,'kind'=>is_array($data)?'api':'unexpected_response','message'=>is_array($data)?'پیام‌رسان درخواست را نپذیرفت.':'پاسخ معتبر پیام‌رسان دریافت نشد؛ محدودیت شبکه یا صفحهٔ خطای هاست را بررسی کنید.'];
}

function systemTelegramDiagnose():array
{
    $config=systemTelegramConfig();$results=[];
    foreach(($config['proxy_url']!==''?['direct','proxy']:['direct'])as $route){
        try{$routeConfig=$config;if($route==='direct')$routeConfig['proxy_url']='';
            $result=systemTelegramDiagnoseResult(systemTelegramTransport('getMe',[],$routeConfig,6));
            if($result['ok']){
                $hook=systemTelegramTransport('getWebhookInfo',[],$routeConfig,6);
                if(is_array($hook['data'])&&!empty($hook['data']['ok'])){
                    $info=$hook['data']['result'];$url=parse_url($info['url']??'');
                    $result['webhook']=['configured'=>!empty($info['url']),'host'=>$url['host']??'','pending_updates'=>(int)($info['pending_update_count']??0),
                        'last_error_date'=>$info['last_error_date']??null,'last_error_message'=>systemTelegramRedact((string)($info['last_error_message']??''),$config)];
                }else $result['webhook_check']=systemTelegramDiagnoseResult($hook);
            }
        }catch(Throwable $error){$result=['ok'=>false,'kind'=>'configuration','message'=>systemTelegramRedact($error->getMessage(),$config)];}
        $results[$route]=$result;
    }
    return ['active_route'=>$config['proxy_url']!==''?'proxy':'direct','checks'=>$results];
}

function systemTelegramRedact(string $text,array $config):string
{
    foreach(['bot_token','webhook_secret','proxy_username','proxy_password','proxy_url']as $field){$value=(string)($config[$field]??'');if($value!=='')$text=str_replace($value,'[hidden]',$text);}
    return preg_replace('~bot\d+:[A-Za-z0-9_-]+~','bot[hidden]',$text)??'';
}

function systemBaleSetWebhook(string $url): void
{
    systemBotWithProvider('bale', static function() use ($url): void {
        $secret = systemTelegramConfig()['webhook_secret'];
        $parts = parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['port']??443,[443,88],true) || !str_ends_with($parts['path']??'', '/api/bale-webhook.php') || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$secret)) {
            throw new InvalidArgumentException('نشانی HTTPS وب‌هوک بله و رمز تصادفی معتبر لازم است. پورت باید ۴۴۳ یا ۸۸ باشد.');
        }
        systemTelegramCall('setWebhook',['url'=>$url.'?key='.rawurlencode($secret)]);
    });
}

function systemTelegramEnsure(PDO $pdo): void
{
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_grants (egm_code VARCHAR(64) NOT NULL,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,pin_fingerprint CHAR(64) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(egm_code,admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_sessions (admin_id BIGINT PRIMARY KEY,awaiting_pin TINYINT NOT NULL DEFAULT 0,expires_at DATETIME NULL,failures INT NOT NULL DEFAULT 0,blocked_until DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_reports (id CHAR(32) PRIMARY KEY,egm_code VARCHAR(64) NOT NULL,period_code VARCHAR(64) NOT NULL,log_id BIGINT NOT NULL,user_id BIGINT NULL,guest_code VARCHAR(32) NOT NULL,snapshot LONGTEXT NOT NULL,decision VARCHAR(16) NULL,decided_by BIGINT NULL,decided_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_egm_report(egm_code,log_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_deliveries (report_id CHAR(32) NOT NULL,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,message_id BIGINT NOT NULL,PRIMARY KEY(report_id,admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_decisions (id BIGINT AUTO_INCREMENT PRIMARY KEY,egm_code VARCHAR(64) NOT NULL,period_code VARCHAR(64) NOT NULL,guest_code VARCHAR(32) NOT NULL,user_id BIGINT NULL,gift TINYINT NOT NULL,draw TINYINT NOT NULL,admin_id BIGINT NOT NULL,report_id CHAR(32) NOT NULL,decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_egm_guest_decision(egm_code,period_code,guest_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS egm_telegram_export_menus (id CHAR(32) PRIMARY KEY,admin_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,payload LONGTEXT NOT NULL,expires_at DATETIME NOT NULL,KEY ix_expiry(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    systemBotExec($pdo, "CREATE TABLE IF NOT EXISTS system_telegram_updates (update_id BIGINT PRIMARY KEY,completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
    $key=systemBotProvider().'_report_'.$report['id'];
    $lock=systemBotPrepare($pdo, 'SELECT GET_LOCK(?,0)');$lock->execute([$key]);
    if ((int)$lock->fetchColumn() !== 1) return ['sent'=>0,'failed'=>0];
    try { return systemTelegramDeliverUnlocked($pdo,$report); }
    finally { systemBotPrepare($pdo, 'SELECT RELEASE_LOCK(?)')->execute([$key]); }
}

function systemTelegramDeliverUnlocked(PDO $pdo, array $report): array
{
    $query = systemBotPrepare($pdo, 'SELECT g.* FROM egm_telegram_grants g LEFT JOIN egm_telegram_deliveries d ON d.report_id=? AND d.admin_id=g.admin_id WHERE g.egm_code=? AND d.report_id IS NULL');
    $query->execute([$report['id'], $report['egm_code']]);
    $hash = systemTelegramPinHash($pdo, $report['egm_code']);
    $sent = 0; $failed = 0;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $grant) {
        if ($hash === '' || !hash_equals(hash('sha256',$hash), $grant['pin_fingerprint'])) continue;
        try {
            $payload = ['chat_id'=>$grant['chat_id'], 'text'=>systemTelegramReportText($report)];
            if (empty($report['decision'])) $payload['reply_markup'] = systemTelegramKeyboard($report['id']);
            $message = systemTelegramCall('sendMessage', $payload);
            systemBotPrepare($pdo, 'INSERT IGNORE INTO egm_telegram_deliveries(report_id,admin_id,chat_id,message_id) VALUES(?,?,?,?)')->execute([$report['id'],$grant['admin_id'],$grant['chat_id'],$message['message_id']]);
            $sent++;
        } catch (Throwable $error) { $failed++; }
    }
    return ['sent'=>$sent,'failed'=>$failed];
}

function systemTelegramCreateReport(array $context, int $logId, array $actor): array
{
    if (systemTelegramConfig()['bot_token'] === '') throw new InvalidArgumentException('ربات پیام‌رسان هنوز تنظیم نشده است.');
    $rows = egmCheckInRecentLogs($context, 1, '', $logId);
    $row = $rows[0] ?? null;
    if (!$row || empty($row['management_report_eligible'])) throw new InvalidArgumentException('این گزارش قابل ارسال به مدیریت نیست.');
    if ($row['period_code'] === '') throw new InvalidArgumentException('بازه این گزارش مشخص نیست.');
    $pdo = $context['pdo'];systemTelegramEnsure($pdo);
    $id = bin2hex(random_bytes(16));
    $row['event_name'] = $context['name'];$row['reported_by'] = $actor['username'] ?? $actor['code'] ?? '';
    systemBotPrepare($pdo, 'INSERT IGNORE INTO egm_telegram_reports(id,egm_code,period_code,log_id,user_id,guest_code,snapshot) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$context['code'],$row['period_code'],$logId,$row['user_id'] ?: null,$row['national_id'] ?: $row['work_id'],json_encode($row,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $find = systemBotPrepare($pdo, 'SELECT * FROM egm_telegram_reports WHERE egm_code=? AND log_id=?');$find->execute([$context['code'],$logId]);$report=$find->fetch(PDO::FETCH_ASSOC);
    $result = systemTelegramDeliver($pdo,$report);
    return ['report_id'=>$report['id'],'message'=>$result['sent'] ? 'گزارش برای مدیران پیام‌رسان ارسال شد.' : 'گزارش ذخیره شد؛ پس از اتصال مدیر یا برقراری ارتباط ارسال می‌شود.'] + $result;
}

/** Save once and deliver independently to both configured management bots. */
function systemBotsCreateReport(array $context, int $logId, array $actor): array
{
    $providers = array_values(array_filter(['telegram', 'bale'], static fn(string $provider): bool => systemBotWithProvider($provider, static fn(): bool => systemTelegramConfig()['bot_token'] !== '')));
    if (!$providers) throw new InvalidArgumentException('ربات مدیریت هنوز تنظیم نشده است.');
    $result = systemBotWithProvider($providers[0], static fn(): array => systemTelegramCreateReport($context, $logId, $actor));
    $delivery = [$providers[0] => ['sent'=>$result['sent'], 'failed'=>$result['failed']]];
    foreach (array_slice($providers, 1) as $provider) {
        try {
            $delivery[$provider] = systemBotWithProvider($provider, static function() use ($context, $result): array {
                systemTelegramEnsure($context['pdo']);
                $query = $context['pdo']->prepare('SELECT * FROM egm_telegram_reports WHERE id=?');
                $query->execute([$result['report_id']]);
                return systemTelegramDeliver($context['pdo'], $query->fetch(PDO::FETCH_ASSOC));
            });
        } catch (Throwable $error) { $delivery[$provider] = ['sent'=>0, 'failed'=>1]; }
    }
    $result['sent'] = array_sum(array_column($delivery, 'sent'));
    $result['failed'] = array_sum(array_column($delivery, 'failed'));
    $result['delivery'] = $delivery;
    $result['message'] = $result['sent'] ? 'گزارش برای مدیران ارسال شد.' : 'گزارش ذخیره شد؛ پس از اتصال مدیر یا برقراری ارتباط ارسال می‌شود.';
    if ($result['sent'] && $result['failed']) $result['message'] .= ' ارسال در بعضی مسیرها ناموفق بود و دوباره تلاش می‌شود.';
    return $result;
}

function systemTelegramDecide(PDO $pdo, string $id, string $choice, string $adminId, ?array $context = null): array
{
    $decision = egmBenefitsDecision($choice);
    $find = systemBotPrepare($pdo, 'SELECT * FROM egm_telegram_reports WHERE id=?');$find->execute([$id]);$report=$find->fetch(PDO::FETCH_ASSOC);
    if (!$report) throw new InvalidArgumentException('گزارش پیدا نشد.');
    $context ??= systemTelegramContext($pdo,$report['egm_code']);
    if ($context['code'] !== $report['egm_code']) throw new InvalidArgumentException('رویداد گزارش تطابق ندارد.');
    $grant = systemBotPrepare($pdo, 'SELECT pin_fingerprint FROM egm_telegram_grants WHERE egm_code=? AND admin_id=?');$grant->execute([$report['egm_code'],$adminId]);
    $fingerprint = $grant->fetchColumn();$hash = systemTelegramPinHash($pdo,$report['egm_code']);
    if (!$fingerprint || $hash === '' || !hash_equals(hash('sha256',$hash), (string)$fingerprint)) throw new InvalidArgumentException('برای این رویداد دسترسی ندارید؛ دوباره pin بفرستید.');
    // Use the context connection for the guest update and decision in one transaction.
    $db=$context['pdo'];$db->beginTransaction();
    try {
        $lock=systemBotPrepare($db, 'SELECT * FROM egm_telegram_reports WHERE id=? FOR UPDATE');$lock->execute([$id]);$report=$lock->fetch(PDO::FETCH_ASSOC);
        if ($report['decision'] !== null) throw new InvalidArgumentException('این گزارش قبلاً بررسی شده است.');
        $user=egmCheckInFindUser($db,$context['tables']['users'],$report['guest_code']);
        $prior=systemBotPrepare($db, 'SELECT r.created_at FROM egm_telegram_decisions d JOIN egm_telegram_reports r ON r.id=d.report_id WHERE d.egm_code=? AND d.period_code=? AND (d.guest_code=? OR (? IS NOT NULL AND d.user_id=?)) ORDER BY r.created_at DESC LIMIT 1 FOR UPDATE');
        $prior->execute([$report['egm_code'],$report['period_code'],$report['guest_code'],$user['id'] ?? null,$user['id'] ?? null]);$newer=$prior->fetchColumn();
        if ($newer && $newer > $report['created_at']) throw new InvalidArgumentException('گزارش جدیدتری برای این مهمان بررسی شده است.');
        systemBotPrepare($db, 'INSERT INTO egm_telegram_decisions(egm_code,period_code,guest_code,user_id,gift,draw,admin_id,report_id) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),gift=VALUES(gift),draw=VALUES(draw),admin_id=VALUES(admin_id),report_id=VALUES(report_id),decided_at=NOW()')
            ->execute([$report['egm_code'],$report['period_code'],$report['guest_code'],$user['id'] ?? null,$decision['gift'],$decision['draw'],$adminId,$id]);
        if ($user) systemBotPrepare($db, "UPDATE `{$context['tables']['user_periods']}` SET should_get_gift=?,draw_eligible=?,benefits_reviewed_at=NOW(),benefits_reviewed_by=? WHERE user_id=? AND period_code=?")
            ->execute([$decision['gift'],$decision['draw'],systemBotProvider().':'.$adminId,$user['id'],$report['period_code']]);
        $snapshot=json_decode($report['snapshot'],true)?:[];$snapshot['decision_provider']=systemBotProvider();
        systemBotPrepare($db, 'UPDATE egm_telegram_reports SET decision=?,decided_by=?,decided_at=NOW(),snapshot=? WHERE id=?')->execute([$choice,$adminId,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
        $db->commit();
    } catch (Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
    $report['decision']=$choice;
    return $report;
}

function systemTelegramRefreshMessages(PDO $pdo, array $report): void
{
    $query=systemBotPrepare($pdo, 'SELECT * FROM egm_telegram_deliveries WHERE report_id=?');$query->execute([$report['id']]);
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
            $answer='تصمیم ثبت شد: '.egmBenefitsDecision($parts[2])['label'];
            foreach(['telegram','bale'] as $provider)systemBotWithProvider($provider,static function()use($pdo,$report):void{
                if(systemTelegramConfig()['bot_token']!==''&&egmInstanceTableExists($pdo,systemBotSql('egm_telegram_deliveries')))systemTelegramRefreshMessages($pdo,$report);
            });
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
        systemBotPrepare($pdo, 'INSERT INTO egm_telegram_sessions(admin_id,awaiting_pin,expires_at) VALUES(?,1,DATE_ADD(NOW(),INTERVAL 5 MINUTE)) ON DUPLICATE KEY UPDATE awaiting_pin=1,expires_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)')->execute([$admin]);
        $send('پین مدیریت رویداد را بفرستید.');return;
    }
    if ($text === '/logout') {
        systemBotPrepare($pdo, 'DELETE FROM egm_telegram_grants WHERE admin_id=?')->execute([$admin]);
        systemBotPrepare($pdo, 'DELETE FROM egm_telegram_sessions WHERE admin_id=?')->execute([$admin]);systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'دسترسی پیام‌رسان شما حذف شد.','reply_markup'=>['remove_keyboard'=>true]]);return;
    }
    $query=systemBotPrepare($pdo, 'SELECT *,expires_at>NOW() AS valid_session,blocked_until>NOW() AS blocked FROM egm_telegram_sessions WHERE admin_id=?');$query->execute([$admin]);$session=$query->fetch(PDO::FETCH_ASSOC);
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
        systemBotPrepare($pdo, 'UPDATE egm_telegram_sessions SET failures=failures+1,blocked_until=IF(failures>=5,DATE_ADD(NOW(),INTERVAL 10 MINUTE),NULL) WHERE admin_id=?')->execute([$admin]);
        $send('پین صحیح نیست. دوباره تلاش کنید.');return;
    }
    foreach ($matches as $record) systemBotPrepare($pdo, 'INSERT INTO egm_telegram_grants(egm_code,admin_id,chat_id,pin_fingerprint) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id),pin_fingerprint=VALUES(pin_fingerprint)')
        ->execute([$record['code'],$admin,$chat,hash('sha256',$record['pin_hash'])]);
    systemBotPrepare($pdo, 'UPDATE egm_telegram_sessions SET awaiting_pin=0,failures=0,blocked_until=NULL WHERE admin_id=?')->execute([$admin]);
    systemTelegramCall('sendMessage',['chat_id'=>$chat,'text'=>'دسترسی شما فعال شد: '.implode('، ',array_column($matches,'name')),'reply_markup'=>systemTelegramManagerKeyboard()]);
}

function systemTelegramProcess(PDO $pdo, array $update): void
{
    $id=(int)($update['update_id'] ?? 0);if($id<1)return;
    $lock=systemBotProvider().'_update_'.$id;
    $query=systemBotPrepare($pdo, 'SELECT GET_LOCK(?,5)');$query->execute([$lock]);if((int)$query->fetchColumn()!==1)throw new RuntimeException('Update busy');
    try {
        $seen=systemBotPrepare($pdo, 'SELECT update_id FROM system_telegram_updates WHERE update_id=?');$seen->execute([$id]);if($seen->fetchColumn())return;
        systemTelegramUpdate($pdo,$update);
        systemBotPrepare($pdo, 'INSERT IGNORE INTO system_telegram_updates(update_id) VALUES(?)')->execute([$id]);
    } finally {systemBotPrepare($pdo, 'SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}

function systemTelegramRetry(PDO $pdo): void
{
    foreach (systemBotQuery($pdo, 'SELECT r.* FROM egm_telegram_reports r WHERE r.decision IS NULL AND EXISTS(SELECT 1 FROM egm_telegram_grants g WHERE g.egm_code=r.egm_code AND NOT EXISTS(SELECT 1 FROM egm_telegram_deliveries d WHERE d.report_id=r.id AND d.admin_id=g.admin_id)) ORDER BY r.created_at LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) as $report) systemTelegramDeliver($pdo,$report);
}
