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
    <link rel="icon" id="site-icon-link" href="<?= htmlspecialchars($panelSiteIconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <link rel="preload" href="style/fonts/remixicon.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="stylesheet" href="style/styles.css" />
    <link rel="stylesheet" href="style/remixicon.css" />
  </head>
  <body>
    <!-- Loader remains until app.js finishes initializing the view and hides this element. -->
    <div id="app-loader" role="status" aria-live="polite" aria-label="Loading panel...">
          <div class="loader-card">
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="loader-title">Loading...</p>
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
            <span>Home</span>
          </button>
          <!-- Users tab is driven by app.js: it fetches the user list, wires up add/edit/delete modals, and calls api/data.php with add_user/update_user/delete_user actions. -->
          <button class="nav-item" data-tab="users">
            <span class="nav-icon ri ri-user-3-line" aria-hidden="true"></span>
            <span>Users</span>
          </button>
          <!-- Account tab contains the static forms that post to update_user_* actions. -->
          <button class="nav-item" data-tab="settings">
            <span class="nav-icon ri ri-user-settings-line" aria-hidden="true"></span>
            <span>Account Settings</span>
          </button>
          <div class="nav-separator" aria-hidden="true"></div>
          <!-- Gallery tab is populated by gallery-tab.php; app.js toggles it on demand. -->
          <button class="nav-item" data-tab="gallery">
            <span class="nav-icon ri ri-gallery-line" aria-hidden="true"></span>
            <span>Photo Gallery</span>
          </button>
          <!-- Developer settings tab exposes appearance controls and general settings via dev-settings.php. -->
          <button class="nav-item" data-tab="devsettings">
            <span class="nav-icon ri ri-terminal-box-line" aria-hidden="true"></span>
            <span>Developer Settings</span>
          </button>
        </nav>

        <!-- Logout link hits logout.php directly to end the session without JavaScript. -->
        <div class="sidebar-footer">
          <a
            class="nav-item logout-nav"
            href="logout.php"
            aria-label="Log out of the system"
            title="Log out of the system"
          >
            <span class="nav-icon ri ri-logout-box-line" aria-hidden="true"></span>
            <span>Logout from system</span>
          </a>
        </div>
      </aside>

      <main class="content">
        <!-- Top bar displays the current tab title and hooks into sidebar toggle + live clock logic defined in app.js. -->
        <header class="topbar">
          <button id="sidebarToggle" class="icon-btn" title="Toggle sidebar" aria-label="Toggle sidebar">≡</button>
          <h2 id="page-title">Home</h2>
          <div class="spacer"></div>
          <div id="live-clock" class="clock" aria-live="polite"></div>
        </header>

        <!-- Home tab shows quick KPI cards populated by the front-end fetch loop in app.js; the release info block is static text only. -->
        <section id="tab-home" class="tab active">
          <div class="cards">
            <div class="card kpi">
              <div class="kpi-label">Total users</div>
              <div class="kpi-value" id="kpi-users">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">Gallery photos</div>
              <div class="kpi-value" id="kpi-photos">0</div>
            </div>
            <div class="card kpi">
              <div class="kpi-label">Database status</div>
              <div class="kpi-value db-status" id="kpi-db-status">Checking...</div>
            </div>
          </div>
          <div class="card">
            <h3>Front-end release</h3>
            <p class="muted">This front-end release runs entirely in the browser and does not rely on a backend or logins.</p>
          </div>
        </section>

        <!-- User Settings tab renders the grid/table managed by app.js; user-related modals post to api/data.php so the backend can enforce phone/email uniqueness and persist to both JSON store and the optional DB. -->
        <section id="tab-users" class="tab">
          <div class="card">
            <div class="table-header">
              <h3>Users</h3>
              <!-- JS binds #add-user to open the management modal in add mode. -->
              <button class="btn primary" id="add-user">Add user</button>
            </div>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>User unique code</th>
                    <th>Full name</th>
                    <th>Phone number</th>
                    <th>Work ID</th>
                    <th>National ID</th>
                    <th>Email</th>
                    <th>Action</th>
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
                <h3>Personal information</h3>
              </div>
              <!-- Updates the logged-in user's fullname through the update_user_personal API action. -->
              <form id="personal-info-form" class="form">
                <label class="field standard-width">
                  <span>Full name</span>
                  <input id="personal-fullname" name="fullname" type="text" value="<?= htmlspecialchars($personalFullname, ENT_QUOTES, 'UTF-8') ?>" required />
                </label>
                <label class="field standard-width">
                  <span>ID number</span>
                  <input type="text" value="<?= htmlspecialchars($personalIdNumber, ENT_QUOTES, 'UTF-8') ?>" readonly />
                </label>
                <label class="field standard-width">
                  <span>Work ID</span>
                  <input type="text" value="<?= htmlspecialchars($personalWorkId, ENT_QUOTES, 'UTF-8') ?>" readonly />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">Save</button>
                </div>
              </form>
            </div>

            <div class="card settings-section">
              <div class="section-header">
                <h3>Account information</h3>
              </div>
              <!-- Sends username/phone/email edits to update_user_account so the backend can validate and refresh the session. -->
              <form id="account-info-form" class="form">
                <label class="field standard-width">
                  <span>Username</span>
                  <input id="account-username" name="username" type="text" value="<?= htmlspecialchars($accountUsername, ENT_QUOTES, 'UTF-8') ?>" required />
                </label>
                <label class="field standard-width">
                  <span>Phone number</span>
                  <input id="account-phone" name="phone" type="text" value="<?= htmlspecialchars($accountPhone, ENT_QUOTES, 'UTF-8') ?>" />
                </label>
                <label class="field standard-width">
                  <span>Email</span>
                  <input id="account-email" name="email" type="email" value="<?= htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') ?>" />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">Save</button>
                </div>
              </form>
            </div>

            <div class="card settings-section">
              <div class="section-header">
                <h3>Privacy</h3>
              </div>
              <!-- Privacy form posts current+new password to update_user_password for validation before persisting. -->
              <form id="privacy-form" class="form">
                <label class="field standard-width">
                  <span>Current password</span>
                  <input id="current-password" name="current_password" type="password" autocomplete="current-password" />
                </label>
                <label class="field standard-width">
                  <span>New password</span>
                  <input id="new-password" name="new_password" type="password" autocomplete="new-password" />
                </label>
                <label class="field standard-width">
                  <span>Confirm new password</span>
                  <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" />
                </label>
                <div class="section-footer">
                  <button type="submit" class="btn primary">Save</button>
                </div>
              </form>
            </div>
          </div>
        </section>

        <?php include __DIR__ . '/gallery-tab.php'; ?>

        <!-- Developer settings tab contains the general and appearance panes controlled by the sub-nav buttons. -->
        <section id="tab-devsettings" class="tab">
            <div class="sub-layout" data-sub-layout>
              <aside class="sub-sidebar">
                <div class="sub-header">Developer settings</div>
                <div class="sub-nav">
                  <button type="button" class="sub-item active" data-pane="panel-settings">
                    General
                  </button>
                  <button type="button" class="sub-item" data-pane="appearance">
                    Appearance
                  </button>
                  <button type="button" class="sub-item" data-pane="beta-test">
                    Beta Test
                  </button>
                  <button type="button" class="sub-item" data-pane="database">
                    Database
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
                      <h3>General appearance setting</h3>
                    </div>
                    <div class="form grid one-column">
                      <label class="field">
                        <span>Panel name</span>
                        <input id="dev-panel-name" type="text" value="Panel name" />
                      </label>
                      <label class="field icon-field">
                        <span>Site icon</span>
                        <div class="photo-uploader" data-site-icon-uploader>
                          <div class="photo-preview" data-site-icon-preview aria-live="polite">
                            <img data-site-icon-image class="hidden" alt="Selected site icon preview" />
                            <div class="photo-placeholder" data-site-icon-placeholder>No image</div>
                          </div>
                          <div class="photo-actions">
                            <button
                              type="button"
                              class="btn ghost small"
                              data-open-photo-chooser
                              aria-label="Add site icon from photo library"
                            >
                              Add photo
                            </button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-clear-site-icon
                              aria-label="Clear selected site icon"
                            >
                              Clear
                            </button>
                          </div>
                        </div>
                      </label>
                    </div>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Colors setting</h3>
                    </div>
                    <div class="appearance-grid">
                      <?php foreach ([
                        "primary" => "Primary color",
                        "background" => "Background color",
                        "text" => "Text color",
                        "toggle" => "Toggle button color"
                      ] as $key => $label): ?>
                        <div class="appearance-row">
                          <span class="appearance-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                          <div class="appearance-input-group">
                            <input
                              type="text"
                              class="appearance-hex-field"
                              data-appearance-hex="<?= $key ?>"
                              aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> hex value"
                              maxlength="7"
                              placeholder="#000000"
                            />
                            <button
                              type="button"
                              class="appearance-preview"
                              data-appearance-preview="<?= $key ?>"
                              data-show-appearance-picker="<?= $key ?>"
                              aria-label="Open color picker for <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            ></button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-show-appearance-picker="<?= $key ?>"
                            >
                              Pick color
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-appearance-settings">Apply</button>
                      <button type="button" class="btn ghost" id="reset-appearance-settings">Reset</button>
                    </div>
                    <p class="hint" id="appearance-hint">Fine-tune the UI colors directly from the developer lab.</p>
                  </div>
                </div>
                <div class="sub-pane beta-test-pane" data-pane="beta-test" dir="rtl">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Notifications</h3>
                    </div>
                    <div class="section-footer beta-test-footer">
                      <button type="button" class="btn secondary" data-test-toast aria-label="Test Toast">
                        Test Toast
                      </button>
                      <button
                        type="button"
                        class="btn secondary"
                        data-test-snackbar
                        aria-label="Test Snackbar"
                      >
                        Snackbar Test
                      </button>
                      <button
                        type="button"
                        class="btn secondary"
                        data-test-error-snackbar
                        aria-label="Test Error Snackbar"
                      >
                        Error Snackbar
                      </button>
                    </div>
                    <p class="hint">Trigger a toast-style notification for testing.</p>
                  </div>
                </div>
                <div class="sub-pane" data-pane="database">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Database Import / export</h3>
                    </div>
                    <p class="hint">
                      Export the current database snapshot, import a previously downloaded file, and configure automated backups
                      without leaving the panel.
                    </p>
                    <div class="form single-column">
                      <label class="field standard-width">
                        <span>Instant backup</span>
                        <button type="button" class="btn primary full-width" id="instant-backup-btn">
                          Download backup
                        </button>
                      </label>
                      <label class="field standard-width">
                        <span>Import backup</span>
                        <div class="backup-import-control">
                          <button type="button" class="btn ghost small" id="backup-import-trigger">
                            Select file
                          </button>
                          <span id="backup-file-chosen" class="backup-file-chosen">No file selected.</span>
                          <input id="dev-db-backup-file" type="file" accept=".json" class="backup-file-input" hidden />
                        </div>
                      </label>
                    </div>
                    <form id="backup-settings-form" class="form single-column">
                      <label class="field standard-width">
                        <span>Auto-backup interval (minutes)</span>
                        <input
                          id="auto-backup-interval"
                          type="number"
                          min="0"
                          placeholder="0 = disabled"
                          class="numeric-field"
                        />
                      </label>
                      <label class="field standard-width">
                        <span>Automatic backup storage limit</span>
                        <input
                          id="auto-backup-limit"
                          type="number"
                          min="0"
                          placeholder="0 = unlimited"
                          class="numeric-field"
                        />
                      </label>
                      <div class="section-footer auto-backup-actions">
                        <button type="submit" class="btn primary" id="save-backup-settings">
                          Save auto-backup settings
                        </button>
                      </div>
                    </form>
                    <p class="hint backup-history-hint">
                      Backups below include instant snapshots and scheduled copies. Automatic versions respect the storage limit you
                      configure.
                    </p>
                    <div class="backup-history" id="backup-history"></div>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Database SQL console</h3>
                    </div>
                    <p class="hint sql-console-hint">
                      Run SQL directly on the connected database without opening phpMyAdmin.
                    </p>
                    <p class="hint sql-console-status muted" id="dev-sql-status">
                      Checking database connection...
                    </p>
                    <form id="developer-sql-form" class="form">
                      <label class="field full">
                        <span>SQL query</span>
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
                            Run SQL
                          </button>
                          <button type="button" class="btn ghost" id="clear-sql-query">
                            Clear
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
              <h3 id="appearance-picker-title">Choose color</h3>
              <button type="button" class="icon-btn" data-close-appearance-picker aria-label="Close color picker">×</button>
            </div>
            <p class="hint" id="appearance-picker-hint">Slide or pick a swatch to fine-tune the selected color.</p>
            <div class="default-color-picker" data-appearance-modal-picker>
              <div class="default-color-picker__grid">
                <span class="default-color-picker__handle"></span>
              </div>
              <div class="default-color-picker__slider">
                <input type="range" min="0" max="360" aria-label="Picker hue slider" />
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- User modal is populated via app.js when adding or editing a user and submits to add/update actions. -->
    <!-- Modal shared between adding/editing users; it posts to api/data.php and mirrors payload expectations. -->
    <div id="user-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
      <div class="modal-card">
        <h3 id="user-modal-title">Add user</h3>
        <form id="user-form" class="form grid two-column-fields" data-mode="add">
          <label class="field">
            <span>User ID</span>
            <input id="user-code" type="text" readonly />
          </label>
          <!-- Username is sourced from the backend username column so it is distinct from the unique code. -->
          <label class="field">
            <span>Username</span>
            <input id="user-name" type="text" required />
          </label>
          <label class="field">
            <span>Full name</span>
            <input id="user-fullname" type="text" required />
          </label>
          <label class="field">
            <span>Phone number (11 digits)</span>
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
            <span>Email</span>
            <input
              id="user-email"
              type="email"
              placeholder="user@example.com"
              required
            />
          </label>
          <label class="field">
            <span>Work ID</span>
            <input id="user-work-id" type="text" />
          </label>
          <!-- National ID spans the full grid because it pairs with additional validation hints in the JS handler. -->
          <label class="field full">
            <span>National ID</span>
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
            <button type="button" class="btn" id="user-cancel">Cancel</button>
            <button type="submit" class="btn primary">Add</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete confirmation modal is shown by app.js whenever a user row triggers removal. -->
    <!-- Delete confirmation modal for enforcing safe removals driven by confirmUserDeletion(). -->
    <div id="user-delete-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="user-delete-modal-title">
      <div class="modal-card">
        <h3 id="user-delete-modal-title">Delete user</h3>
        <p id="user-delete-modal-msg">Are you sure you want to delete <strong id="user-delete-name">this user</strong>?</p>
        <div class="modal-actions">
          <button type="button" class="btn" id="user-delete-cancel">Cancel</button>
          <button type="button" class="btn primary" id="user-delete-confirm">Delete</button>
        </div>
      </div>
    </div>

    <!-- System modal surfaces settings controlled by app.js for updating rates via the dev area. -->
    <div id="system-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="system-modal-title">
      <div class="modal-card">
        <h3 id="system-modal-title">System settings</h3>
        <form id="system-form" class="form">
          <div class="grid full">
            <label class="field">
              <span>System name</span>
              <input id="system-name" type="text" required />
            </label>
          </div>
          <div class="grid full">
            <label class="field">
              <span>Single tier (IRR/hour)</span>
              <input id="price-1p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>Double tier (IRR/hour)</span>
              <input id="price-2p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>Triple tier (IRR/hour)</span>
              <input id="price-3p" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>Quad tier (IRR/hour)</span>
              <input id="price-4p" class="price-input" type="text" inputmode="numeric" required />
            </label>
          </div>
          <div class="grid full">
            <label class="field">
              <span>Birthday (IRR)</span>
              <input id="price-birthday" class="price-input" type="text" inputmode="numeric" required />
            </label>
            <label class="field">
              <span>Film (IRR)</span>
              <input id="price-film" class="price-input" type="text" inputmode="numeric" required />
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn" id="system-cancel">Cancel</button>
            <button type="submit" class="btn primary">Save</button>
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
          <h3 id="gallery-upload-modal-title">Upload a photo</h3>
          <button
            type="button"
            class="icon-btn"
            data-gallery-upload-modal-close
            aria-label="Close upload form"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <form data-gallery-photo-form class="form" enctype="multipart/form-data">
          <div class="photo-uploader" data-photo-uploader="gallery">
            <div class="photo-preview">
              <img data-photo-image class="hidden" alt="" />
              <div data-photo-placeholder class="photo-placeholder">
                Drag &amp; drop a photo or use the button below
              </div>
              <button
                type="button"
                class="photo-preview-clear hidden"
                data-photo-clear
                aria-label="Remove photo"
              >
                Clear
              </button>
            </div>
            <div class="photo-actions">
              <input type="file" name="photo" data-photo-input accept="image/*" hidden />
              <button type="button" class="btn" data-photo-upload>Select photo</button>
            </div>
          </div>
          <div class="grid">
            <label class="field">
              <span>Photo title</span>
              <input name="title" type="text" required />
            </label>
            <label class="field">
              <span>Alternate text (alt)</span>
              <input name="alt_text" type="text" />
            </label>
            <label class="field">
              <span>Category</span>
              <select data-gallery-photo-category name="category_id">
                <option value="">Select a category</option>
              </select>
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn" data-gallery-upload-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Upload photo</button>
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
          <h3 id="gallery-photo-modal-title">Photo details</h3>
          <button
            type="button"
            class="icon-btn"
            data-gallery-photo-close
            aria-label="Close photo details"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <div class="gallery-photo-modal-body">
          <div class="gallery-photo-modal-preview-wrapper">
            <div class="gallery-photo-modal-preview">
              <a data-gallery-photo-link target="_blank" rel="noopener">
                <img data-gallery-photo-preview alt="Gallery photo preview" />
              </a>
            </div>
            <p class="gallery-photo-modal-preview-meta" data-gallery-photo-created></p>
          </div>
          <form class="form gallery-photo-modal-form" data-gallery-photo-modal-form>
            <label class="field">
              <span>Photo title</span>
              <input type="text" data-gallery-photo-modal-title name="title" required />
            </label>
            <label class="field">
              <span>Alternate text (alt)</span>
              <input type="text" data-gallery-photo-modal-alt name="alt_text" />
            </label>
            <label class="field">
              <span>Category</span>
              <select data-gallery-photo-category name="category_id">
                <option value="">Select a category</option>
              </select>
            </label>
            <div class="modal-actions gallery-photo-modal-actions">
              <button type="button" class="btn" data-gallery-photo-replace>Replace photo</button>
              <button type="button" class="btn ghost" data-gallery-photo-delete>Delete</button>
              <button type="submit" class="btn primary" data-gallery-photo-save>Save</button>
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
              Upload photo
            </button>
          </div>
          <h3 id="photo-chooser-title">Photo chooser</h3>
          <button
            type="button"
            class="icon-btn"
            data-photo-chooser-close
            aria-label="Close photo chooser"
          >
            <span class="ri ri-close-line" aria-hidden="true"></span>
          </button>
        </div>
        <div class="gallery-thumb-grid-wrapper">
          <div class="gallery-search-row photo-chooser-search-row">
            <label class="gallery-search-field">
              <span class="gallery-search-label">Search photo chooser</span>
              <input
                type="search"
                class="gallery-search-input"
                data-photo-chooser-search
                placeholder="Search by photo title or category"
                autocomplete="off"
                aria-label="Search the photo chooser by title or category"
              />
            </label>
            <span class="gallery-search-count" data-photo-chooser-search-count>
              0 photos
            </span>
          </div>
          <div class="photo-chooser-scroll">
            <div id="photo-chooser-thumb-grid" class="gallery-thumb-grid"></div>
            <p class="muted gallery-thumb-loading hidden" data-gallery-loading>Loading photos…</p>
            <p id="photo-chooser-thumb-empty" class="muted gallery-thumb-empty hidden">No photos uploaded yet.</p>
          </div>
          <div class="gallery-thumb-actions">
            <button type="button" id="photo-chooser-load-more" class="btn ghost hidden">Load More</button>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn ghost" data-photo-chooser-cancel>Cancel</button>
          <button type="button" class="btn primary" id="photo-chooser-choose" disabled>Choose</button>
        </div>
      </div>
    </div>

    <!-- Period configuration modal allows app.js to define time slices used in price calculations. -->
    <div id="periods-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="periods-modal-title">
      <div class="modal-card" style="max-width:640px;">
        <h3 id="periods-modal-title">Configure time periods (24 hours)</h3>
        <div class="form">
          <div class="hint">Define between 1 and 5 periods and cover the entire 24 hours without overlap or gaps.</div>
          <div id="periods-list" class="periods-list"></div>
          <div style="display:flex; gap:8px;">
            <button id="add-period" type="button" class="btn">+ Add period</button>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="periods-cancel">Cancel</button>
          <button type="button" class="btn primary" id="periods-save">Save</button>
        </div>
        <p id="periods-msg" class="hint"></p>
      </div>
    </div>

    <!-- Generic dialog modal is reused for messages initiated by app.js. -->
    <div id="dialog-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
      <div class="modal-card" style="max-width:420px;">
        <h3 id="dialog-title">Message</h3>
        <div class="form">
          <div id="dialog-text" class="hint" style="white-space: pre-wrap;"></div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="dialog-cancel">Cancel</button>
          <button type="button" class="btn primary" id="dialog-ok">OK</button>
        </div>
      </div>
    </div>

    <script>
      window.__CURRENT_USER_NAME = <?= json_encode($topbarUserName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="app.js"></script>
  </body>
</html>
