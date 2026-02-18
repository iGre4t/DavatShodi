<?php
session_start();

require_once __DIR__ . '/api/lib/common.php';
require_once __DIR__ . '/api/lib/users.php';
require_once __DIR__ . '/api/lib/tab-permissions.php';

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
if ($userPdo) {
  ensureUsersExtendedColumns($userPdo);
}
$userCode = normalizeUserValue($sessionUser['code'] ?? '');
$dbUser = ($userPdo && $userCode !== '') ? loadUserByCode($userPdo, $userCode) : null;
$currentUser = array_merge($sessionUser, is_array($dbUser) ? $dbUser : []);
unset($currentUser['password_hash']);
$currentUserPermissions = normalizeTabPermissions($currentUser['permissions'] ?? null, true);
$currentUser['permissions'] = $currentUserPermissions;
$allowedTabs = resolveAllowedPanelTabsForUser($currentUser);
$initialTab = '';
if (in_array('home', $allowedTabs, true)) {
  $initialTab = 'home';
} else {
  $preferredTabs = array_values(array_filter($allowedTabs, static function ($tabId): bool {
    return $tabId !== 'wheel-of-fortune';
  }));
  $initialTab = $preferredTabs[0] ?? ($allowedTabs[0] ?? '');
}
if ($initialTab === '') {
  http_response_code(403);
  echo 'No tab permissions assigned to this account.';
  exit;
}
$_SESSION['user'] = array_merge($sessionUser, $currentUser, [
  'permissions' => $currentUserPermissions,
  'display_name' => normalizeUserValue($currentUser['fullname'] ?? '') ?: (normalizeUserValue($currentUser['username'] ?? '') ?: 'Admin')
]);
$tabCatalog = getPanelTabOptionsForFrontend();
$permissionTree = getPanelPermissionTreeForFrontend();
$childPermissionMap = getPanelChildTabIdsByParent();
$sidebarName = normalizeUserValue($currentUser['fullname'] ?? '');
if ($sidebarName === '') {
  $sidebarName = normalizeUserValue($currentUser['username'] ?? '') ?: 'Admin';
}
$topbarName = normalizeUserValue($currentUser['fullname'] ?? '');
if ($topbarName === '') {
  $topbarName = normalizeUserValue($currentUser['username'] ?? '');
}
$topbarUserName = $topbarName !== '' ? $topbarName : 'Admin';
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
    <div id="app-loader" class="global-lazy-loader-overlay" role="status" aria-live="polite" aria-label="در حال بارگذاری پنل...">
      <div class="global-lazy-loader-card">
        <div class="global-lazy-loader-icon-wrap global-lazy-loader-icon-wrap--default" aria-hidden="true">
            <svg class="global-lazy-loader-icon-svg" viewBox="0 0 1173 773" aria-hidden="true" focusable="false">
              <path class="global-lazy-loader-icon-fill" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z"></path>
              <path class="global-lazy-loader-icon-path" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z"></path>
            </svg>
          </div>
        <p class="global-lazy-loader-text">در حال بارگذاری...</p>
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
          <?php if (in_array('home', $allowedTabs, true)): ?>
            <!-- Home expands the KPI overview; app.js also controls the headline text for this tab. -->
            <button class="nav-item<?= $initialTab === 'home' ? ' active' : '' ?>" data-tab="home"<?= $initialTab === 'home' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-home-4-line" aria-hidden="true"></span>
              <span>خانه</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('users', $allowedTabs, true)): ?>
            <!-- Users tab is driven by app.js: it fetches the user list, wires up add/edit/delete modals, and calls api/data.php with add_user/update_user/delete_user actions. -->
            <button class="nav-item<?= $initialTab === 'users' ? ' active' : '' ?>" data-tab="users"<?= $initialTab === 'users' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-user-3-line" aria-hidden="true"></span>
              <span>کاربران</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('settings', $allowedTabs, true)): ?>
            <!-- Account tab contains the static forms that post to update_user_* actions. -->
            <button class="nav-item<?= $initialTab === 'settings' ? ' active' : '' ?>" data-tab="settings"<?= $initialTab === 'settings' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-user-settings-line" aria-hidden="true"></span>
              <span>تنظیمات حساب</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('features', $allowedTabs, true) || in_array('wheel-of-fortune', $allowedTabs, true) || in_array('task-club', $allowedTabs, true) || in_array('asset-manager', $allowedTabs, true) || in_array('devsettings', $allowedTabs, true)): ?>
            <div class="nav-separator" aria-hidden="true"></div>
          <?php endif; ?>
          <?php if (in_array('features', $allowedTabs, true)): ?>
            <!-- Features tab placeholder has no content yet but reserves a nav entry. -->
            <button class="nav-item<?= $initialTab === 'features' ? ' active' : '' ?>" data-tab="features"<?= $initialTab === 'features' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-apps-line" aria-hidden="true"></span>
              <span>المان‌ها</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('wheel-of-fortune', $allowedTabs, true)): ?>
            <button class="nav-item<?= $initialTab === 'wheel-of-fortune' ? ' active' : '' ?>" data-tab="wheel-of-fortune"<?= $initialTab === 'wheel-of-fortune' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-gamepad-line" aria-hidden="true"></span>
              <span>گردونه شانس</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('task-club', $allowedTabs, true)): ?>
            <button class="nav-item<?= $initialTab === 'task-club' ? ' active' : '' ?>" data-tab="task-club"<?= $initialTab === 'task-club' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-group-line" aria-hidden="true"></span>
              <span>باشگاه تعاملی</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('asset-manager', $allowedTabs, true)): ?>
            <button class="nav-item<?= $initialTab === 'asset-manager' ? ' active' : '' ?>" data-tab="asset-manager"<?= $initialTab === 'asset-manager' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-archive-drawer-line" aria-hidden="true"></span>
              <span>مدیریت اموال</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('devsettings', $allowedTabs, true)): ?>
            <!-- Developer settings tab exposes appearance controls and general settings via dev-settings.php. -->
            <button class="nav-item<?= $initialTab === 'devsettings' ? ' active' : '' ?>" data-tab="devsettings"<?= $initialTab === 'devsettings' ? ' aria-current="page"' : '' ?>>
              <span class="nav-icon ri ri-terminal-box-line" aria-hidden="true"></span>
              <span>تنظیمات توسعه‌دهنده</span>
            </button>
          <?php endif; ?>
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
          <div class="spacer"></div>
          <div id="live-clock" class="clock" aria-live="polite"></div>
        </header>

        <?php if (in_array('home', $allowedTabs, true)): ?>
        <!-- Home tab shows quick KPI cards and recent asset system logs populated by app.js. -->
        <section id="tab-home" class="tab<?= $initialTab === 'home' ? ' active' : '' ?>">
          <div class="cards">
            <div class="card kpi">
              <div class="kpi-label">مجموع کاربران</div>
              <div class="kpi-value" id="kpi-users">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">تعداد اموال</div>
              <div class="kpi-value" id="kpi-photos">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">گزارشات 24 ساعت اخیر</div>
              <div class="kpi-value" id="kpi-db-status">0</div>
            </div>
          </div>

          <div class="card home-log-card">
            <div class="section-header">
              <h3>گزارشات سیستم اموال</h3>
            </div>
            <p id="home-asset-log-status" class="hint home-log-status" aria-live="polite"></p>
            <div id="home-asset-log-days" class="home-log-days"></div>
            <div class="section-footer home-log-footer">
              <button type="button" id="home-asset-log-more" class="btn ghost hidden">نمایش بیشتر</button>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <?php if (in_array('users', $allowedTabs, true)): ?>
          <section
            id="tab-users"
            class="tab<?= $initialTab === 'users' ? ' active' : '' ?>"
            data-tab-source="users.php"
          ></section>
        <?php endif; ?>

        <?php if (in_array('settings', $allowedTabs, true)): ?>
        <!-- Account Settings tab is intentionally stable; the three forms below hook into API actions (update_user_personal, update_user_account, update_user_password) handled in api/data.php. -->
        <section id="tab-settings" class="tab<?= $initialTab === 'settings' ? ' active' : '' ?>">
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
        <?php endif; ?>

        <?php if (in_array('features', $allowedTabs, true)): ?>
          <section
            id="tab-features"
            class="tab<?= $initialTab === 'features' ? ' active' : '' ?>"
            data-tab-source="guide/components.php"
          ></section>
        <?php endif; ?>
        <?php if (in_array('wheel-of-fortune', $allowedTabs, true)): ?>
          <section
            id="tab-wheel-of-fortune"
            class="tab<?= $initialTab === 'wheel-of-fortune' ? ' active' : '' ?>"
            data-tab-source="mini%20apps/Wheel%20of%20Fortune/WF%20Panel.php"
          ></section>
        <?php endif; ?>
        <?php if (in_array('task-club', $allowedTabs, true)): ?>
          <section
            id="tab-task-club"
            class="tab<?= $initialTab === 'task-club' ? ' active' : '' ?>"
            data-tab-source="mini%20apps/Task%20Club/TC%20Panel.php"
          ></section>
        <?php endif; ?>
        <?php if (in_array('devsettings', $allowedTabs, true)): ?>
          <section
            id="tab-devsettings"
            class="tab<?= $initialTab === 'devsettings' ? ' active' : '' ?>"
            data-tab-source="dev-settings.php"
          ></section>
        <?php endif; ?>
        <?php if (in_array('asset-manager', $allowedTabs, true)): ?>
          <section
            id="tab-asset-manager"
            class="tab<?= $initialTab === 'asset-manager' ? ' active' : '' ?>"
            data-tab-source="mini%20apps/Asset%20Manager/panel-tab.php"
          ></section>
        <?php endif; ?>

        <div
          id="pm-ancestor-labels-modal"
          class="modal hidden"
          role="dialog"
          aria-modal="true"
          aria-labelledby="pm-ancestor-labels-title"
        >
          <div class="modal-card" style="max-width: 560px;">
            <div class="modal-card-header">
              <h3 id="pm-ancestor-labels-title">برچسب‌های مال مرسوم</h3>
              <button
                type="button"
                class="icon-btn"
                data-pm-ancestor-modal-close
                aria-label="بستن"
              >
                ×
              </button>
            </div>
            <div class="form">
              <div class="field">
                <span>زنجیره برچسب</span>
                <div id="pm-ancestor-modal-label-chain" class="grid one-column"></div>
              </div>
            </div>
            <p id="pm-ancestor-modal-status" class="hint pm-status" aria-live="polite"></p>
            <div class="modal-actions">
              <button type="button" class="btn" data-pm-ancestor-modal-close>انصراف</button>
              <button type="button" class="btn primary" id="pm-ancestor-modal-save">ذخیره</button>
            </div>
          </div>
        </div>

        <div
          id="pm-asset-labels-modal"
          class="modal hidden"
          role="dialog"
          aria-modal="true"
          aria-labelledby="pm-asset-labels-title"
        >
          <div class="modal-card" style="max-width: 560px;">
            <div class="modal-card-header">
              <h3 id="pm-asset-labels-title">برچسب‌های مال</h3>
              <button
                type="button"
                class="icon-btn"
                data-pm-asset-modal-close
                aria-label="بستن"
              >
                ×
              </button>
            </div>
            <div class="form">
              <div id="pm-asset-modal-label-fields" class="grid one-column"></div>
            </div>
            <p id="pm-asset-modal-status" class="hint pm-status" aria-live="polite"></p>
            <div class="modal-actions">
              <button type="button" class="btn" data-pm-asset-modal-close>انصراف</button>
              <button type="button" class="btn primary" id="pm-asset-modal-save">ذخیره</button>
            </div>
          </div>
        </div>

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

    <!-- Permissions modal controls which panel tabs each user can access. -->
    <div id="permissions-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="permissions-modal-title">
      <div class="modal-card default-modal-card">
        <div class="modal-card-header">
          <h3 id="permissions-modal-title">دسترسی ها</h3>
          <button type="button" class="icon-btn" data-close-permissions aria-label="بستن دسترسی ها">×</button>
        </div>
        <p class="hint">دسترسی هر کاربر فقط به تب‌های انتخاب‌شده محدود می‌شود.</p>
        <div id="permissions-checkboxes" class="permissions-checkboxes pm-permission-grid">
          <?php foreach ($permissionTree as $group): ?>
            <?php $parentId = (string)($group['id'] ?? ''); ?>
            <?php $parentLabel = (string)($group['label'] ?? $parentId); ?>
            <?php $children = is_array($group['children'] ?? null) ? $group['children'] : []; ?>
            <div class="pm-permission-group">
              <label class="pm-permission-item">
                <input
                  type="checkbox"
                  data-permissions-role="parent"
                  data-permissions-tab="<?= htmlspecialchars($parentId, ENT_QUOTES, 'UTF-8') ?>"
                />
                <span><?= htmlspecialchars($parentLabel, ENT_QUOTES, 'UTF-8') ?></span>
              </label>
              <?php if (!empty($children)): ?>
                <div class="pm-permission-children">
                  <?php foreach ($children as $child): ?>
                    <?php $childId = (string)($child['id'] ?? ''); ?>
                    <?php $childLabel = (string)($child['label'] ?? $childId); ?>
                    <label class="pm-permission-item pm-permission-child">
                      <input
                        type="checkbox"
                        data-permissions-role="child"
                        data-permissions-parent="<?= htmlspecialchars($parentId, ENT_QUOTES, 'UTF-8') ?>"
                        data-permissions-tab="<?= htmlspecialchars($childId, ENT_QUOTES, 'UTF-8') ?>"
                      />
                      <span><?= htmlspecialchars($childLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p id="permissions-modal-status" class="hint" aria-live="polite"></p>
        <div class="modal-actions">
          <button type="button" class="btn" data-close-permissions>بستن</button>
          <button type="button" class="btn primary" id="permissions-save">ذخیره دسترسی ها</button>
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
          <label class="field">
            <span>شناسه تلگرام</span>
            <input
              id="user-telegram-id"
              type="text"
              inputmode="numeric"
              pattern="^\d*$"
              placeholder="Only numerals"
              oninput="this.value = this.value.replace(/\D/g, '')"
            />
          </label>
          <label class="field">
            <span>پین‌کد کاربر (4 رقم)</span>
            <input
              id="user-pin-code"
              type="text"
              inputmode="numeric"
              pattern="^\d{4}$"
              maxlength="4"
              placeholder="1234"
              oninput="this.value = this.value.replace(/\D/g, '')"
            />
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

    <div id="user-password-reset-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="user-password-reset-title">
      <div class="modal-card">
        <h3 id="user-password-reset-title">تغییر گذرواژه کاربر</h3>
        <form id="user-password-reset-form" class="form">
          <label class="field">
            <span>گذرواژه جدید (8 رقم عددی)</span>
            <input
              id="user-password-reset-input"
              type="password"
              inputmode="numeric"
              pattern="^\d{8}$"
              maxlength="8"
              autocomplete="new-password"
              placeholder="12345678"
              oninput="this.value = this.value.replace(/\D/g, '')"
              required
            />
          </label>
          <div class="modal-actions">
            <button type="button" class="btn" id="user-password-reset-cancel">انصراف</button>
            <button type="submit" class="btn primary">ذخیره</button>
          </div>
        </form>
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
      window.__CURRENT_USER_CODE = <?= json_encode($userCode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
      window.PANEL_TAB_CATALOG = <?= json_encode($tabCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
      window.PANEL_CHILD_TAB_MAP = <?= json_encode($childPermissionMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
      window.PANEL_ALLOWED_TABS = <?= json_encode(array_values($allowedTabs), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
      window.PANEL_INITIAL_TAB = <?= json_encode($initialTab, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="mini%20apps/Asset%20Manager/assets-manager.js"></script>
    <script src="app.js?v=<?= (int)@filemtime(__DIR__ . '/app.js') ?>"></script>
  </body>
</html>
