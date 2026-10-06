<?php
declare(strict_types=1);
$projectRoot = __DIR__; $projectRelative = '';
while (!is_file($projectRoot . '/api/config.php')) {
    $parent = dirname($projectRoot);
    if ($parent === $projectRoot) { http_response_code(503); exit('مسیر سامانه پیدا نشد.'); }
    $projectRoot = $parent; $projectRelative .= '../';
}
require_once __DIR__ . '/egm-security.php';
require_once $projectRoot . '/api/lib/tab-permissions.php';
require_once $projectRoot . '/api/lib/system-telegram.php';
$isBale = ($_GET['platform'] ?? '') === 'bale';
$GLOBALS['systemBotProvider'] = $isBale ? 'bale' : 'telegram';
$botLabel = $isBale ? 'بله' : 'تلگرام';
$user = requireTabPermissionFromSession('event-guest-manager', false);
if (!userHasPermissionId($user, 'event-guest-manager:main')) {
    http_response_code(403); exit('دسترسی بررسی ربات را ندارید.');
}
$nonce = egmSecurityCreateCspNonce();
egmSecuritySendPageHeaders($nonce);
$csrf = egmSecurityGetCsrfToken();
$report = null; $error = ''; $success = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!egmSecurityIsValidCsrfToken((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403); $error = 'فرم منقضی شده است. صفحه را تازه‌سازی کنید.';
    } elseif (time() - (int)($_SESSION['telegram_diagnostic_at'] ?? 0) < 30) {
        http_response_code(429); $error = 'برای بررسی دوباره، ۳۰ ثانیه صبر کنید.';
    } else {
        $_SESSION['telegram_diagnostic_at'] = time();
        session_write_close();
        if ($isBale && ($_POST['action'] ?? '') === 'set-webhook') {
            try {
                if (empty($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS']) === 'off') throw new RuntimeException('برای فعال‌سازی دریافت پیام، همین صفحه را با HTTPS باز کنید.');
                $base = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'], substr_count($projectRelative, '../') + 1)), '/');
                systemBaleSetWebhook('https://' . ($_SERVER['HTTP_HOST'] ?? '') . $base . '/api/bale-webhook.php');
                $success = 'دریافت پیام‌های بله فعال شد. در گفت‌وگوی خصوصی بازو، pin بفرستید و سپس پین مدیریت رویداد را وارد کنید.';
            } catch (Throwable $setupError) { $error = systemTelegramRedact($setupError->getMessage(), systemTelegramConfig()); }
        } else { $report = systemTelegramDiagnose(); }
    }
}
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$proxyConfigured = systemTelegramConfig()['proxy_url'] !== '';
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بررسی اتصال ربات <?= $escape($botLabel) ?></title>
<style nonce="<?= $escape($nonce) ?>">
@font-face{font-family:Vazirmatn;src:url('<?= $escape($projectRelative) ?>style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');font-display:swap}
*{box-sizing:border-box}body{margin:0;background:#f7f9fc;color:#172b42;font-family:Vazirmatn,Tahoma,sans-serif;line-height:1.9}main{max-width:760px;margin:40px auto;padding:20px}h1{font-size:24px;margin:0}h2{font-size:18px;margin:0 0 8px}p{margin:8px 0;color:#4a5d72}.card{background:white;border:1px solid #dde5ef;border-radius:18px;padding:22px;margin:18px 0;box-shadow:0 8px 28px #152f5210}.state{border-right:4px solid #2f8fff}.failed{border-right-color:#bf7149}button{background:#247ddd;color:white;border:0;border-radius:12px;padding:12px 24px;font:inherit;cursor:pointer}button:disabled{opacity:.6;cursor:wait}a{color:#246dbb}dl{display:grid;grid-template-columns:auto 1fr;gap:5px 18px;margin-bottom:0}dd{margin:0;overflow-wrap:anywhere}small{color:#66768a}code{direction:ltr;unicode-bidi:embed} .error{color:#a34932}
</style></head><body><main><a href="<?= $escape($projectRelative) ?>panel.php">بازگشت به مدیریت رویداد</a><section class="card"><h1>بررسی اتصال ربات <?= $escape($botLabel) ?></h1><p>این تست از همین سرور اجرا می‌شود و اتصال، اعتبار توکن و وضعیت دریافت پیام‌ها را بررسی می‌کند.</p><p>مسیر فعال: <strong><?= $proxyConfigured ? 'پروکسی' : 'مستقیم' ?></strong></p><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button type="submit">بررسی اتصال</button></form><small>ممکن است بررسی تا ۲۴ ثانیه طول بکشد. پیامی به کاربران ارسال نمی‌شود.</small><?php if ($error): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?></section>
<?php foreach (($report['checks'] ?? []) as $route => $check): ?>
<section class="card state <?= empty($check['ok']) ? 'failed' : '' ?>" role="status"><h2><?= $route === 'proxy' ? 'اتصال با پروکسی' : 'اتصال مستقیم' ?><?= ($report['active_route'] ?? '') === $route ? ' · مسیر فعال ربات' : '' ?></h2><p><?= $escape($check['message']) ?></p><dl>
<?php if (isset($check['http_status'])): ?><dt>پاسخ HTTP</dt><dd><?= (int)$check['http_status'] ?></dd><dt>زمان پاسخ</dt><dd><?= (int)$check['elapsed_ms'] ?> میلی‌ثانیه</dd><?php endif; ?>
<?php if (!empty($check['curl_code'])): ?><dt>کد خطای شبکه</dt><dd><?= (int)$check['curl_code'] ?></dd><?php endif; ?>
<?php if (!empty($check['bot_username'])): ?><dt>ربات</dt><dd dir="ltr">@<?= $escape($check['bot_username']) ?></dd><?php endif; ?>
<?php if (isset($check['webhook'])): $hook = $check['webhook']; ?>
<dt>وب‌هوک</dt><dd><?= $hook['configured'] ? $escape($hook['host']) : 'ثبت نشده؛ برای دریافت پیام‌ها وب‌هوک یا polling لازم است.' ?></dd><dt>پیام‌های در انتظار</dt><dd><?= (int)$hook['pending_updates'] ?></dd>
<?php if ($hook['last_error_message']): ?><dt>آخرین خطای دریافت</dt><dd><?= $escape($hook['last_error_message']) ?><?php if ($hook['last_error_date']): ?><br><small dir="ltr"><?= $escape(gmdate('Y-m-d H:i:s', (int)$hook['last_error_date'])) ?> UTC</small><?php endif; ?></dd><?php else: ?><dt>خطای ثبت‌شده دریافت</dt><dd>ندارد</dd><?php endif; ?>
<?php endif; ?></dl><?php if (isset($check['webhook_check'])): ?><p class="error">بررسی وب‌هوک: <?= $escape($check['webhook_check']['message']) ?></p><?php endif; ?></section>
<?php endforeach; ?>
<?php if ($isBale): ?><section class="card"><h2>دریافت پیام‌های بله</h2><p>پس از بررسی اتصال، دریافت پیام را روی همین هاست فعال کنید. این دکمه نشانی وب‌هوک فعلی بازو را با نشانی همین نصب جایگزین می‌کند.</p><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-webhook"><button type="submit">فعال‌سازی دریافت پیام</button></form><?php if ($success): ?><p role="status"><?= $escape($success) ?></p><?php endif; ?></section><?php endif; ?><section class="card"><h2>پروکسی اختیاری</h2><p>برای فعال‌سازی، تنظیمات <code>proxy_url</code>، <code>proxy_username</code> و <code>proxy_password</code> را در فایل <code>api/<?= $isBale ? 'bale' : 'telegram' ?>.config.local.php</code> وارد کنید. HTTP، HTTPS و SOCKS5 پشتیبانی می‌شوند. برای عبور DNS از پروکسی از <code>socks5h://HOST:PORT</code> استفاده کنید.</p><p>پروکسی MTProto تلگرام برای این اتصال قابل استفاده نیست. پروکسی فقط درخواست‌های خروجی ربات را تغییر می‌دهد؛ تلگرام همچنان باید بتواند به وب‌هوک HTTPS شما وصل شود.</p><small>تست موفق اتصال، تحویل پیام به وب‌هوک را تضمین نمی‌کند. آخرین خطا ممکن است مربوط به گذشته باشد.</small></section></main>
<script nonce="<?= $escape($nonce) ?>">document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',function(){const b=this.querySelector('button');b.disabled=true;b.textContent='در حال انجام…';}));</script></body></html>
