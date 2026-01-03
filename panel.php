<?php
session_start();

require_once __DIR__ . '/api/lib/common.php';
require_once __DIR__ . '/api/lib/users.php';

const DEFAULT_PANEL_SETTINGS = [
  'title' => 'Great Panel',
  'timezone' => 'Asia/Tehran',
  'panelName' => 'Panel in progress',
  'siteIcon' => '',
  'backupSettings' => [
    'autoIntervalMinutes' => 0,
    'autoLimit' => 0,
    'lastAutoBackupAt' => null
  ]
];

function formatSiteIconUrlForHtml($value = '') {
  $trimmed = trim((string)$value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\\/\\/|\\/\\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "./{$trimmed}";
}

function loadJsonPayload(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function loadPanelSettings(): array {
  $data = loadJsonPayload(__DIR__ . '/data/store.json');
  $config = loadConfig(__DIR__ . '/api/config.php');
  $pdo = connectDatabase($config);
  if ($pdo) {
    $dbData = loadDataFromDb($pdo, $config);
    if (is_array($dbData)) {
      $data = $dbData;
    }
  }
  $settings = [];
  if (isset($data['settings']) && is_array($data['settings'])) {
    $settings = $data['settings'];
  }
  persistGeneralSettingsScript($settings);
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
}

function normalizeUserValue($value): string {
  $trimmed = trim((string)$value);
  return ($trimmed === '' || $trimmed === '0') ? '' : $trimmed;
}

$panelSettings = loadPanelSettings();
$panelTitle = $panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName'];
if (!is_string($panelTitle) || $panelTitle === '') {
  $panelTitle = DEFAULT_PANEL_SETTINGS['panelName'];
}
$panelSiteIconUrl = formatSiteIconUrlForHtml($panelSettings['siteIcon'] ?? '');

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}
$sessionUser = $_SESSION['user'] ?? [];
$userConfig = loadConfig(__DIR__ . '/api/config.php');
$userPdo = connectDatabase($userConfig);
$userCode = normalizeUserValue($sessionUser['code'] ?? '');
$dbUser = ($userPdo && $userCode !== '') ? loadUserByCode($userPdo, $userCode) : null;
$currentUser = array_merge($sessionUser, is_array($dbUser) ? $dbUser : []);
$sidebarName = normalizeUserValue($currentUser['fullname'] ?? '');
if ($sidebarName === '') {
  $sidebarName = normalizeUserValue($currentUser['username'] ?? '') ?: 'Admin';
}
$topbarName = normalizeUserValue($currentUser['username'] ?? '');
$topbarUserName = $topbarName !== '' ? $topbarName : ($sidebarName ?: 'Admin');
$personalFullname = $currentUser['fullname'] ?? '';
$personalIdNumber = $currentUser['id_number'] ?? $currentUser['id'] ?? '';
$personalWorkId = $currentUser['work_id'] ?? '';
$accountUsername = $currentUser['username'] ?? '';
$accountPhone = $currentUser['phone'] ?? '';
$accountEmail = $currentUser['email'] ?? '';
?>

<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="color-scheme" content="light" />
    <script src="General%20Setting/general-settings.js"></script>
    <script src="style/appearance.js"></script>
    <link rel="icon" id="site-icon-link" href="<?= htmlspecialchars($panelSiteIconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="preload" href="style/fonts/remixicon.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="stylesheet" href="style/styles.css" />
    <link rel="stylesheet" href="style/remixicon.css" />
  </head>
  <body>
    <!-- Loader remains until app.js finishes initializing the view and hides this element. -->
    <div id="app-loader" role="status" aria-live="polite" aria-label="در حال بارگذاری پنل...">
          <div class="loader-card">
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="loader-title">در حال بارگذاری...</p>
      </div>
    </div>
    <!-- The main application shell; app.js toggles tabs within this container. -->
    <div id="app-view" class="view">
      <!-- Sidebar navigation is static and toggled via buttons with data-tab attributes that app.js listens to. -->
      <aside class="sidebar">
        <div class="sidebar-header">
          <div class="logo small" data-sidebar-logo>
            <img
              data-sidebar-site-icon
              class="logo-icon<?= $panelSiteIconUrl ? '' : ' hidden' ?>"
              <?= $panelSiteIconUrl ? 'src="' . htmlspecialchars($panelSiteIconUrl, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
              alt="Site icon"
              aria-hidden="true"
            />
            <span
              data-sidebar-logo-text
              class="logo-text<?= $panelSiteIconUrl ? ' hidden' : '' ?>"
            >
              GN
            </span>
          </div>
          <div class="title"><?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <nav class="nav">
          <!-- Home expands the KPI overview; app.js also controls the headline text for this tab. -->
          <button class="nav-item active" data-tab="home" aria-current="page">
            <span class="nav-icon ri ri-home-4-line" aria-hidden="true"></span>
            <span>خانه</span>
          </button>
          <!-- Users tab is driven by app.js: it fetches the user list, wires up add/edit/delete modals, and calls api/data.php with add_user/update_user/delete_user actions. -->
          <button class="nav-item" data-tab="users">
            <span class="nav-icon ri ri-user-3-line" aria-hidden="true"></span>
            <span>کاربران</span>
          </button>
          <!-- Account tab contains the static forms that post to update_user_* actions. -->
          <button class="nav-item" data-tab="settings">
            <span class="nav-icon ri ri-user-settings-line" aria-hidden="true"></span>
            <span>تنظیمات حساب</span>
          </button>
          <div class="nav-separator" aria-hidden="true"></div>
          <!-- Guests tab is routed to guests.php; content will be added later. -->
          <button class="nav-item" data-tab="guests">
            <span class="nav-icon ri ri-team-line" aria-hidden="true"></span>
            <span>مهمانان و رویدادها</span>
          </button>
          <button
            type="button"
            class="nav-item"
            data-external-target="invite.php"
            title="Jump to invite page"
          >
            <span class="nav-icon ri ri-mail-add-line" aria-hidden="true"></span>
            <span>دعوت</span>
          </button>
          <!-- Gallery tab is populated by gallery-tab.php; app.js toggles it on demand. -->
          <button class="nav-item" data-tab="gallery">
            <span class="nav-icon ri ri-gallery-line" aria-hidden="true"></span>
            <span>Ú¯Ø§Ù„Ø±ÛŒ Ø¹Ú©Ø³</span>
          </button>
          <!-- Features tab placeholder has no content yet but reserves a nav entry. -->
          <button class="nav-item" data-tab="features">
            <span class="nav-icon ri ri-list-check" aria-hidden="true"></span>
            <span>Features</span>
          </button>
          <!-- Typography tab provides font uploads and previews. -->
          <button class="nav-item" data-tab="typography">
            <span class="nav-icon ri ri-font-color" aria-hidden="true"></span>
            <span>Typography</span>
          </button>
          <!-- Developer settings tab exposes appearance controls and general settings via dev-settings.php. -->
          <button class="nav-item" data-tab="devsettings">
            <span class="nav-icon ri ri-terminal-box-line" aria-hidden="true"></span>
            <span>تنظیمات توسعه‌دهنده</span>
          </button>
        </nav>

        <!-- Logout link hits logout.php directly to end the session without JavaScript. -->
        <div class="sidebar-footer">
          <a
            class="nav-item logout-nav"
            href="logout.php"
            aria-label="خروج از سیستم"
            title="خروج از سیستم"
          >
            <span class="nav-icon ri ri-logout-box-line" aria-hidden="true"></span>
            <span>خروج از سیستم</span>
          </a>
        </div>
      </aside>

      <main class="content">
        <!-- Top bar displays the current tab title and hooks into sidebar toggle + live clock logic defined in app.js. -->
        <header class="topbar">
          <button id="sidebarToggle" class="icon-btn" title="نمایش/پنهان کردن نوار کناری" aria-label="نمایش/پنهان کردن نوار کناری">≡</button>
          <h2 id="page-title">خانه</h2>
          <div class="spacer"></div>
          <div id="live-clock" class="clock" aria-live="polite"></div>
        </header>

        <!-- Home tab shows quick KPI cards populated by the front-end fetch loop in app.js; the release info block is static text only. -->
        <section id="tab-home" class="tab active">
          <div class="cards">
            <div class="card kpi">
              <div class="kpi-label">مجموع کاربران</div>
              <div class="kpi-value" id="kpi-users">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">عکس‌های گالری</div>
              <div class="kpi-value" id="kpi-photos">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">وضعیت پایگاه داده</div>
              <div class="kpi-value db-status" id="kpi-db-status">در حال بررسی...</div>
            </div>
          </div>
          <div class="card">
            <h3>نسخه رابط کاربری</h3>
            <p class="muted">این نسخه از رابط کاربری کاملاً در مرورگر اجرا می‌شود و به بک‌اند یا ورود کاربر وابسته نیست.</p>
          </div>
        </section>

        <!-- User Settings tab renders the grid/table managed by app.js; user-related modals post to api/data.php so the backend can enforce phone/email uniqueness and persist to both JSON store and the optional DB. -->
        <section id="tab-users" class="tab">
          <div class="card">
            <div class="table-header">
              <h3>کاربران</h3>
              <!-- JS binds #add-user to open the management modal in add mode. -->
              <button class="btn primary" id="add-user">افزودن کاربر</button>
            </div>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>کد یکتای کاربر</th>
                    <th>نام کامل</th>
                    <th>شماره تلفن</th>
                    <th>کد پرسنلی</th>
                    <th>کد ملی</th>
                    <th>ایمیل</th>
                    <th>عملیات</th>
                  </tr>
                </thead>
                <!-- Rows are injected by app.js -> renderUsers(), keeping USER_DB as the source of truth. -->
                <tbody id="users-body"></tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- Account Settings tab is intentionally stable; the three forms below hook into API actions (update_user_personal, update_user_account, update_user_password) handled in api/data.php. -->
        <section id="tab-settings" class="tab">
          <div class="settings-grid">
            <div class="card settings-section">
              <div class="section-header">
                <h3>اطلاعات شخصی</h3>
              </div>
              <!-- Updates the logged-in user's fullname through the update_user_personal API action. -->
              <form id="personal-info-form" class="form">
                <label class="field standard-width">
                  <span>نام کامل</span>
                  <input id="personal-fullname" name="fullname" type="text" value="<?= htmlspecialchars($personalFullname, ENT_QUOTES, 'UTF-8') ?>" required />
                </label>
                <label class="field standard-width">
                  <span>شماره ملی</span>
                  <input type="text" value="<?= htmlspecialchars($personalIdNumber, ENT_QUOTES, 'UTF-8') ?>" readonly />
                </label>
                <label class="field standard-width">
                  <span>کد پرسنلی</span>
                  <input type="text" value="<?= htmlspecialchars($personalWorkId, ENT_QUOTES, 'UTF-8') ?>" readonly />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">ذخیره</button>
                </div>
              </form>
            </div>

            <div class="card settings-section">
              <div class="section-header">
                <h3>اطلاعات حساب</h3>
              </div>
              <!-- Sends username/phone/email edits to update_user_account so the backend can validate and refresh the session. -->
              <form id="account-info-form" class="form">
                <label class="field standard-width">
                  <span>نام کاربری</span>
                  <input id="account-username" name="username" type="text" value="<?= htmlspecialchars($accountUsername, ENT_QUOTES, 'UTF-8') ?>" required />
                </label>
                <label class="field standard-width">
                  <span>شماره تلفن</span>
                  <input id="account-phone" name="phone" type="text" value="<?= htmlspecialchars($accountPhone, ENT_QUOTES, 'UTF-8') ?>" />
                </label>
                <label class="field standard-width">
                  <span>ایمیل</span>
                  <input id="account-email" name="email" type="email" value="<?= htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') ?>" />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">ذخیره</button>
                </div>
              </form>
            </div>

            <div class="card settings-section">
              <div class="section-header">
                <h3>حریم خصوصی</h3>
              </div>
              <!-- Privacy form posts current+new password to update_user_password for validation before persisting. -->
              <form id="privacy-form" class="form">
                <label class="field standard-width">
                  <span>رمز عبور فعلی</span>
                  <input id="current-password" name="current_password" type="password" autocomplete="current-password" />
                </label>
                <label class="field standard-width">
                  <span>رمز عبور جدید</span>
                  <input id="new-password" name="new_password" type="password" autocomplete="new-password" />
                </label>
                <label class="field standard-width">
                  <span>تأیید رمز عبور جدید</span>
                  <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">ذخیره</button>
                </div>
              </form>
            </div>
          </div>
        </section>

        <?php include __DIR__ . '/guests.php'; ?>
        <?php include __DIR__ . '/winnerstab.php'; ?>
        <?php include __DIR__ . '/gallery-tab.php'; ?>
        <?php include __DIR__ . '/invitepanel.php'; ?>
        <?php include __DIR__ . '/typography.php'; ?>
        <?php include __DIR__ . '/features.php'; ?>
        <!-- Developer settings tab contains the general and appearance panes controlled by the sub-nav buttons. -->
        <section id="tab-devsettings" class="tab">
            <div class="sub-layout" data-sub-layout>
              <aside class="sub-sidebar">
            <div class="sub-header">تنظیمات توسعه‌دهنده</div>
            <div class="sub-nav">
              <button type="button" class="sub-item active" data-pane="panel-settings">
                عمومی
              </button>
              <button type="button" class="sub-item" data-pane="appearance">
                ظاهر
              </button>
              <button type="button" class="sub-item" data-pane="database">
                پایگاه داده
              </button>
              <button type="button" class="sub-item" data-pane="printer-settings">
                Printer Setting
              </button>
                </div>
              </aside>
              <div class="sub-content">
                <!-- General pane includes the contents of dev-settings.php for backend configuration. -->
                <div class="sub-pane active" data-pane="panel-settings">
                  <?php include __DIR__ . '/dev-settings.php'; ?>
                </div>
                <!-- Appearance pane exposes color pickers and panel metadata managed via app.js state helpers. -->
                <div class="sub-pane" data-pane="appearance">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>تنظیمات ظاهری عمومی</h3>
                    </div>
                    <div class="form grid one-column">
                      <label class="field">
                        <span>عنوان پنل</span>
                        <input id="dev-panel-name" type="text" value="<?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>" />
                      </label>
                      <label class="field icon-field">
                        <span>آیکون سایت</span>
                        <div class="photo-uploader" data-site-icon-uploader>
                          <div class="photo-preview" data-site-icon-preview aria-live="polite">
                            <img data-site-icon-image class="hidden" alt="پیش‌نمایش آیکون انتخاب‌شده" />
                            <div class="photo-placeholder" data-site-icon-placeholder>تصویری نیست</div>
                          </div>
                          <div class="photo-actions">
                            <button
                              type="button"
                              class="btn ghost small"
                              data-open-photo-chooser
                              aria-label="افزودن آیکون سایت از کتابخانه عکس"
                            >
                              افزودن عکس
                            </button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-clear-site-icon
                              aria-label="پاک کردن آیکون سایت انتخاب‌شده"
                            >
                              پاک کردن
                            </button>
                          </div>
                        </div>
                      </label>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-panel-settings">ذخیره عنوان پنل</button>
                    </div>
                    <p class="hint">متن نمایش داده‌شده در نوار کناری و تب مرورگر را برای همه کاربران به‌روز کنید.</p>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>تنظیم رنگ‌ها</h3>
                    </div>
                    <div class="appearance-grid">
                      <?php foreach ([
                        "primary" => "رنگ اصلی",
                        "background" => "رنگ پس‌زمینه",
                        "text" => "رنگ متن",
                        "toggle" => "رنگ دکمه تغییر وضعیت"
                      ] as $key => $label): ?>
                        <div class="appearance-row">
                          <span class="appearance-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                          <div class="appearance-input-group">
                            <input
                              type="text"
                              class="appearance-hex-field"
                              data-appearance-hex="<?= $key ?>"
                              aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> مقدار هگز"
                              maxlength="7"
                              placeholder="#000000"
                            />
                            <button
                              type="button"
                              class="appearance-preview"
                              data-appearance-preview="<?= $key ?>"
                              data-show-appearance-picker="<?= $key ?>"
                              aria-label="باز کردن انتخاب رنگ برای <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            ></button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-show-appearance-picker="<?= $key ?>"
                            >
                              انتخاب رنگ
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-appearance-settings">اعمال</button>
                      <button type="button" class="btn ghost" id="reset-appearance-settings">بازنشانی</button>
                    </div>
                    <p class="hint" id="appearance-hint">رنگ‌های رابط کاربری را مستقیماً از آزمایشگاه توسعه تنظیم کنید.</p>
                  </div>
                </div>
                <div class="sub-pane" data-pane="database">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>واردات / صادرات پایگاه داده</h3>
                    </div>
                    <p class="hint">
                      تصویر فعلی پایگاه داده را صادر کنید، فایل قبلی را وارد کنید و پشتیبان‌گیری خودکار را بدون ترک پنل پیکربندی کنید.
                    </p>
                    <div class="form single-column">
                      <label class="field standard-width">
                        <span>پشتیبان‌گیری فوری</span>
                        <button type="button" class="btn primary full-width" id="instant-backup-btn">
                          دانلود پشتیبان
                        </button>
                      </label>
                      <label class="field standard-width">
                        <span>درون‌ریزی پشتیبان</span>
                        <div class="backup-import-control">
                          <button type="button" class="btn ghost small" id="backup-import-trigger">
                            انتخاب فایل
                          </button>
                          <span id="backup-file-chosen" class="backup-file-chosen">فایلی انتخاب نشده است.</span>
                          <input id="dev-db-backup-file" type="file" accept=".json" class="backup-file-input" hidden />
                        </div>
                      </label>
                    </div>
                    <form id="backup-settings-form" class="form single-column">
                      <label class="field standard-width">
                        <span>فاصله پشتیبان‌گیری خودکار (دقیقه)</span>
                        <input
                          id="auto-backup-interval"
                          type="number"
                          min="0"
                          placeholder="۰ = غیرفعال"
                          class="numeric-field"
                        />
                      </label>
                      <label class="field standard-width">
                        <span>حداکثر فضای ذخیره‌سازی پشتیبان خودکار</span>
                        <input
                          id="auto-backup-limit"
                          type="number"
                          min="0"
                          placeholder="۰ = نامحدود"
                          class="numeric-field"
                        />
                      </label>
                      <div class="section-footer auto-backup-actions">
                        <button type="submit" class="btn primary" id="save-backup-settings">
                          ذخیره تنظیمات پشتیبان خودکار
                        </button>
                      </div>
                    </form>
                    <p class="hint backup-history-hint">
                      پشتیبان‌های زیر شامل تصویر فوری و نسخه‌های زمان‌بندی‌شده هستند. نسخه‌های خودکار مطابق با محدودیت ذخیره‌سازی تنظیم‌شده عمل می‌کنند.
                    </p>
                    <div class="backup-history" id="backup-history"></div>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>کنسول SQL پایگاه داده</h3>
                    </div>
                    <p class="hint sql-console-hint">
                      اجرای مستقیم SQL روی پایگاه داده متصل بدون باز کردن phpMyAdmin.
                    </p>
                    <p class="hint sql-console-status muted" id="dev-sql-status">
                      در حال بررسی اتصال پایگاه داده...
                    </p>
                    <form id="developer-sql-form" class="form">
                      <label class="field full">
                        <span>پرس‌وجوی SQL</span>
                        <textarea
                          id="dev-db-sql"
                          class="sql-editor"
                          dir="ltr"
                          spellcheck="false"
                          rows="10"
                          placeholder="SELECT * FROM gallery ORDER BY uploaded_at DESC LIMIT 10"
                        ></textarea>
                      </label>
                      <div class="section-footer sql-console-footer">
                        <div class="sql-console-actions">
                          <button type="submit" class="btn primary" id="run-sql-query">
                            اجرای SQL
                          </button>
                          <button type="button" class="btn ghost" id="clear-sql-query">
                            پاک کردن
                          </button>
                        </div>
                      </div>
                    </form>
                    <div id="dev-sql-result" class="sql-result hidden" aria-live="polite">
                      <p class="muted" data-sql-result-message></p>
                      <div data-sql-result-body></div>
    </div>
  </div>
</div>

                <div class="sub-pane" data-pane="printer-settings">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Printer Setting</h3>
                    </div>
                    <form id="printer-settings-form" class="form grid one-column">
                      <label class="field">
                        <span>Printer device</span>
                        <select id="printer-device" name="printer-device">
                          <option value="">Loading printers…</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Layout</span>
                        <select id="printer-layout" name="printer-layout">
                          <option value="">Select layout</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Paper size</span>
                        <select id="printer-paper-size" name="printer-paper-size">
                          <option value="">Select paper size</option>
                        </select>
                      </label>
                      <p class="hint">Any custom size registered in the system will be used by default.</p>
                      <label class="field">
                        <span>Pages per paper</span>
                        <select id="printer-pages-per-paper" name="printer-pages-per-paper">
                          <option value="">Select pages per sheet</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Margin</span>
                        <select id="printer-margin" name="printer-margin">
                          <option value="">Select margin</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Scale</span>
                        <select id="printer-scale" name="printer-scale">
                          <option value="">Select scale</option>
                        </select>
                      </label>
                    </form>
                    <div class="section-footer">
                      <button type="button" id="printer-settings-save" class="btn primary">Save</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

        <!-- Color picker modal is toggled by app.js whenever a hex field requests a swatch. -->
        <div
          id="appearance-picker-modal"
          class="modal color-modal hidden"
          role="dialog"
          aria-modal="true"
          aria-labelledby="appearance-picker-title"
        >
          <div class="modal-card">
            <div class="modal-card-header">
              <h3 id="appearance-picker-title">انتخاب رنگ</h3>
              <button type="button" class="icon-btn" data-close-appearance-picker aria-label="بستن انتخاب رنگ">×</button>
            </div>
            <p class="hint" id="appearance-picker-hint">کشیدن یا انتخاب نمونه‌ای رنگ برای تنظیم دقیق رنگ انتخاب‌شده.</p>
            <div class="default-color-picker" data-appearance-modal-picker>
              <div class="default-color-picker__grid">
                <span class="default-color-picker__handle"></span>
              </div>
              <div class="default-color-picker__slider">
                <input type="range" min="0" max="360" aria-label="اسلایدر طیف رنگ" />
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- Permissions modal lets you preview which sidebar tabs could be granted; it remains client-side only. -->
    <div id="permissions-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="permissions-modal-title">
      <div class="modal-card default-modal-card">
        <div class="modal-card-header">
          <h3 id="permissions-modal-title">دسترسی ها</h3>
          <button type="button" class="icon-btn" data-close-permissions aria-label="بستن دسترسی ها">×</button>
        </div>
        <p class="hint">علامت‌زدن هر گزینه صرفاً پیش‌نمایش است و تغییری در داده‌های واقعی ایجاد نمی‌کند.</p>
        <div class="form grid one-column permissions-checkboxes">
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="home" checked />
            <span>خانه</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="users" checked />
            <span>کاربران</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="settings" checked />
            <span>تنظیمات حساب</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="guests" />
            <span>لیست مهمانان</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="gallery" />
            <span>گالری</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="typography" />
            <span>تایپوگرافی</span>
          </label>
          <label class="field checkbox">
            <input type="checkbox" data-permissions-tab="devsettings" />
            <span>تنظیمات توسعه‌دهنده</span>
          </label>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" data-close-permissions>بستن</button>
        </div>
      </div>
    </div>
    <!-- User modal is populated via app.js when adding or editing a user and submits to add/update actions. -->
    <!-- Modal shared between adding/editing users; it posts to api/data.php and mirrors payload expectations. -->
    <div id="user-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
      <div class="modal-card">
        <h3 id="user-modal-title">افزودن کاربر</h3>
        <form id="user-form" class="form grid two-column-fields" data-mode="add">
          <label class="field">
            <span>شناسه کاربر</span>
            <input id="user-code" type="text" readonly />
          </label>
          <!-- Username is sourced from the backend username column so it is distinct from the unique code. -->
          <label class="field">
            <span>نام کاربری</span>
            <input id="user-name" type="text" required />
          </label>
          <label class="field">
            <span>نام کامل</span>
            <input id="user-fullname" type="text" required />
          </label>
          <label class="field">
            <span>شماره تلفن (۱۱ رقم)</span>
            <input
              id="user-phone"
              type="text"
              inputmode="numeric"
              pattern="^\d{11}$"
              maxlength="11"
              placeholder="09xxxxxxxxx"
              required
              oninput="this.value = this.value.replace(/\D/g, '')"
            />
          </label>
          <label class="field">
            <span>ایمیل</span>
            <input
              id="user-email"
              type="email"
              placeholder="user@example.com"
              required
            />
          </label>
          <label class="field">
            <span>کد پرسنلی</span>
            <input id="user-work-id" type="text" />
          </label>
          <!-- National ID spans the full grid because it pairs with additional validation hints in the JS handler. -->
          <label class="field full">
            <span>کد ملی</span>
            <input
              id="user-id-number"
              type="text"
              inputmode="numeric"
              pattern="^\d{0,10}$"
              maxlength="10"
              placeholder="1234567890"
              oninput="this.value = this.value.replace(/\D/g, '')"
            />
          </label>
          <div class="modal-actions">
            <button type="button" class="btn" id="user-cancel">انصراف</button>
            <button type="submit" class="btn primary">افزودن</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete confirmation modal is shown by app.js whenever a user row triggers removal. -->
    <!-- Delete confirmation modal for enforcing safe removals driven by confirmUserDeletion(). -->
    <div id="user-delete-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="user-delete-modal-title">
      <div class="modal-card">
        <h3 id="user-delete-modal-title">حذف کاربر</h3>
        <p id="user-delete-modal-msg">آیا مطمئن هستید که می‌خواهید <strong id="user-delete-name">این کاربر</strong> را حذف کنید؟</p>
        <div class="modal-actions">
          <button type="button" class="btn" id="user-delete-cancel">انصراف</button>
          <button type="button" class="btn primary" id="user-delete-confirm">حذف</button>
        </div>
      </div>
    </div>

    <!-- System modal surfaces settings controlled by app.js for updating rates via the dev area. -->
    <div id="system-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="system-modal-title">
      <div class="modal-card">
        <h3 id="system-modal-title">تنظیمات سیستم</h3>
        <form id="system-form" class="form">
          <div class="grid full">
            <label class="field">
              <span>نام سیستم</span>
              <input id="system-name" type="text" required />
            </label>
          </div>
          <div class="grid full">
            <label class="field">
              <span>پلن تک‌نفره (ریال/ساعت)</span>
              <input id="price-1p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>پلن دو نفره (ریال/ساعت)</span>
              <input id="price-2p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>پلن سه نفره (ریال/ساعت)</span>
              <input id="price-3p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>پلن چهار نفره (ریال/ساعت)</span>
              <input id="price-4p" class="price-input" type="text" inputmode="numeric" required />
            </label>
          </div>
          <div class="grid full">
            <label class="field">
              <span>تولد (ریال)</span>
              <input id="price-birthday" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>فیلم (ریال)</span>
              <input id="price-film" class="price-input" type="text" inputmode="numeric" required />
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn" id="system-cancel">انصراف</button>
            <button type="submit" class="btn primary">ذخیره</button>
          </div>
          <p id="system-form-msg" class="hint"></p>
        </form>
      </div>
    </div>

    <div
      id="gallery-upload-modal"
      class="modal hidden"
      role="dialog"
      aria-modal="true"
      aria-labelledby="gallery-upload-modal-title"
    >
      <div class="modal-card large">
        <div class="modal-card-header">
          <h3 id="gallery-upload-modal-title">بارگذاری عکس</h3>
          <button
            type="button"
            class="icon-btn"
            data-gallery-upload-modal-close
            aria-label="بستن فرم بارگذاری"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <form data-gallery-photo-form class="form" enctype="multipart/form-data">
          <div class="photo-uploader" data-photo-uploader="gallery">
            <div class="photo-preview">
              <img data-photo-image class="hidden" alt="" />
              <div data-photo-placeholder class="photo-placeholder">
                کشیدن و رها کردن یک عکس یا استفاده از دکمه زیر
              </div>
              <button
                type="button"
                class="photo-preview-clear hidden"
                data-photo-clear
                aria-label="حذف عکس"
              >
                پاک کردن
              </button>
            </div>
            <div class="photo-actions">
              <input type="file" name="photo" data-photo-input accept="image/*" hidden />
              <button type="button" class="btn" data-photo-upload>انتخاب عکس</button>
            </div>
          </div>
          <div class="grid">
            <label class="field">
              <span>عنوان عکس</span>
              <input name="title" type="text" required />
            </label>
            <label class="field">
              <span>متن جایگزین (alt)</span>
              <input name="alt_text" type="text" />
            </label>
            <label class="field">
              <span>دسته</span>
              <select data-gallery-photo-category name="category_id">
                <option value="">انتخاب دسته</option>
              </select>
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn" data-gallery-upload-modal-close>انصراف</button>
            <button type="submit" class="btn primary">بارگذاری عکس</button>
          </div>
          </form>
        </div>
      </div>

    <!-- Gallery photo modal shows metadata and preview for each photo. -->
    <div
      id="gallery-photo-modal"
      class="modal hidden gallery-photo-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="gallery-photo-modal-title"
    >
      <div class="modal-card large">
        <div class="modal-card-header">
          <h3 id="gallery-photo-modal-title">جزئیات عکس</h3>
          <button
            type="button"
            class="icon-btn"
            data-gallery-photo-close
            aria-label="بستن جزئیات عکس"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <div class="gallery-photo-modal-body">
          <div class="gallery-photo-modal-preview-wrapper">
            <div class="gallery-photo-modal-preview">
              <a data-gallery-photo-link target="_blank" rel="noopener">
                <img data-gallery-photo-preview alt="پیش‌نمایش عکس گالری" />
              </a>
            </div>
            <p class="gallery-photo-modal-preview-meta" data-gallery-photo-created></p>
          </div>
          <form class="form gallery-photo-modal-form" data-gallery-photo-modal-form>
            <label class="field">
              <span>عنوان عکس</span>
              <input type="text" data-gallery-photo-modal-title name="title" required />
            </label>
            <label class="field">
              <span>متن جایگزین (alt)</span>
              <input type="text" data-gallery-photo-modal-alt name="alt_text" />
            </label>
            <label class="field">
              <span>دسته</span>
              <select data-gallery-photo-category name="category_id">
                <option value="">انتخاب دسته</option>
              </select>
            </label>
            <div class="modal-actions gallery-photo-modal-actions">
              <button type="button" class="btn" data-gallery-photo-replace>جایگزینی عکس</button>
              <button type="button" class="btn ghost" data-gallery-photo-delete>حذف</button>
              <button type="submit" class="btn primary" data-gallery-photo-save>ذخیره</button>
            </div>
            <input type="file" name="photo" accept="image/*" data-gallery-photo-replace-input hidden />
          </form>
        </div>
      </div>
    </div>

    <div
      id="photo-chooser-modal"
      class="modal hidden"
      role="dialog"
      aria-modal="true"
      aria-labelledby="photo-chooser-title"
    >
      <div class="modal-card large">
        <div class="modal-card-header">
          <div class="modal-card-header-start">
            <button
              type="button"
              class="btn ghost small"
              data-photo-chooser-upload
            >
              بارگذاری عکس
            </button>
          </div>
          <h3 id="photo-chooser-title">انتخابگر عکس</h3>
          <button
            type="button"
            class="icon-btn"
            data-photo-chooser-close
            aria-label="بستن انتخابگر عکس"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <div class="gallery-thumb-grid-wrapper">
          <div class="gallery-search-row photo-chooser-search-row">
            <label class="gallery-search-field">
              <span class="gallery-search-label">جستجوی انتخابگر عکس</span>
              <input
                type="search"
                class="gallery-search-input"
                data-photo-chooser-search
                placeholder="جستجو بر اساس عنوان یا دسته عکس"
                autocomplete="off"
                aria-label="جستجو در انتخابگر عکس بر اساس عنوان یا دسته"
              />
            </label>
            <span class="gallery-search-count" data-photo-chooser-search-count>
              ۰ عکس
            </span>
          </div>
          <div class="photo-chooser-scroll">
            <div id="photo-chooser-thumb-grid" class="gallery-thumb-grid"></div>
            <p class="muted gallery-thumb-loading hidden" data-gallery-loading>در حال بارگذاری عکس‌ها...</p>
            <p id="photo-chooser-thumb-empty" class="muted gallery-thumb-empty hidden">هنوز عکسی بارگذاری نشده است.</p>
          </div>
          <div class="gallery-thumb-actions">
            <button type="button" id="photo-chooser-load-more" class="btn ghost hidden">بارگذاری بیشتر</button>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn ghost" data-photo-chooser-cancel>انصراف</button>
          <button type="button" class="btn primary" id="photo-chooser-choose" disabled>انتخاب</button>
        </div>
      </div>
    </div>

    <!-- Period configuration modal allows app.js to define time slices used in price calculations. -->
    <div id="periods-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="periods-modal-title">
      <div class="modal-card" style="max-width:640px;">
        <h3 id="periods-modal-title">تنظیم بازه‌های زمانی (۲۴ ساعته)</h3>
        <div class="form">
          <div class="hint">بین ۱ تا ۵ بازه تعریف کنید و کل ۲۴ ساعت را بدون هم‌پوشانی یا شکاف پوشش دهید.</div>
          <div id="periods-list" class="periods-list"></div>
          <div style="display:flex; gap:8px;">
            <button id="add-period" type="button" class="btn">+ افزودن بازه</button>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="periods-cancel">انصراف</button>
          <button type="button" class="btn primary" id="periods-save">ذخیره</button>
        </div>
        <p id="periods-msg" class="hint"></p>
      </div>
    </div>

    <!-- Generic dialog modal is reused for messages initiated by app.js. -->
    <div id="dialog-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
      <div class="modal-card" style="max-width:420px;">
        <h3 id="dialog-title">پیام</h3>
        <div class="form">
          <div id="dialog-text" class="hint" style="white-space: pre-wrap;"></div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="dialog-cancel">انصراف</button>
          <button type="button" class="btn primary" id="dialog-ok">تأیید</button>
        </div>
      </div>
    </div>

    <script>
      window.__CURRENT_USER_NAME = <?= json_encode($topbarUserName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="app.js"></script>
  </body>
</html>
