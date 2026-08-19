<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
$tcPanelUser = requireTabPermissionFromSession('task-club', false);
$tcAllowedChildTabs = resolveAllowedPanelChildTabsForUser($tcPanelUser, 'task-club');
$tcAllowedChildSet = array_fill_keys($tcAllowedChildTabs, true);
$tcCanMainPane = isset($tcAllowedChildSet['task-club:main']);
$tcCanInviteesPane = isset($tcAllowedChildSet['task-club:invitees']);
$tcCanManageTasksPane = isset($tcAllowedChildSet['task-club:manage-tasks']);
$tcCanTaskAccessPane = isset($tcAllowedChildSet['task-club:task-access']);
$tcCanMonitoringPane = isset($tcAllowedChildSet['task-club:monitoring']);
$tcCanExportPane = isset($tcAllowedChildSet['task-club:export']);
$tcCanLinkerPane = isset($tcAllowedChildSet['task-club:linker']);
$tcCanEventStylePane = isset($tcAllowedChildSet['task-club:event-style']);
$tcCanLogsPane = isset($tcAllowedChildSet['task-club:logs']);
$tcCanControlPanel = $tcCanMainPane || $tcCanTaskAccessPane || $tcCanExportPane || $tcCanLinkerPane || $tcCanEventStylePane;
$tcNormalizeBool = static function ($value): bool {
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = strtolower(trim((string)$value));
  return in_array($token, ['1', 'true', 'on', 'yes'], true);
};
$tcSessionUserCode = strtolower(trim((string)($tcPanelUser['code'] ?? '')));
$tcManageTasksOverride = null;
$tcHasTaskSubtabAccess = false;
if ($tcSessionUserCode !== '') {
  $tcTaskAccessPath = __DIR__ . '/tasks/task-access.json';
  if (tcDbIsFile($tcTaskAccessPath)) {
    $tcTaskAccessRaw = tcDbFileGetContents($tcTaskAccessPath);
    $tcTaskAccessDecoded = is_string($tcTaskAccessRaw) ? json_decode($tcTaskAccessRaw, true) : null;
    $tcTaskAccessUsers = is_array($tcTaskAccessDecoded['users'] ?? null) ? $tcTaskAccessDecoded['users'] : [];
    foreach ($tcTaskAccessUsers as $rawCode => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (strtolower(trim((string)$rawCode)) !== $tcSessionUserCode) {
        continue;
      }
      $tcManageTasksOverride = $tcNormalizeBool($entry['allowManageTasksTab'] ?? ($entry['allow_manage_tasks_tab'] ?? false));
      $tcTaskRules = is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [];
      foreach ($tcTaskRules as $tcRule) {
        if (!is_array($tcRule)) {
          continue;
        }
        if (!$tcNormalizeBool($tcRule['enabled'] ?? true)) {
          continue;
        }
        $tcPaneRules = is_array($tcRule['panes'] ?? null) ? $tcRule['panes'] : [];
        if (count($tcPaneRules) === 0) {
          $tcHasTaskSubtabAccess = true;
          break;
        }
        foreach ($tcPaneRules as $tcPaneAllowed) {
          if ($tcNormalizeBool($tcPaneAllowed)) {
            $tcHasTaskSubtabAccess = true;
            break 2;
          }
        }
      }
      break;
    }
  }
}
if ($tcManageTasksOverride !== null) {
  $tcCanManageTasksPane = $tcManageTasksOverride;
}
$tcCanTaskSubtabs = $tcCanManageTasksPane || $tcHasTaskSubtabAccess;
$tcHasAnyPane = $tcCanMainPane
  || $tcCanInviteesPane
  || $tcCanManageTasksPane
  || $tcCanTaskSubtabs
  || $tcCanTaskAccessPane
  || $tcCanMonitoringPane
  || $tcCanExportPane
  || $tcCanLinkerPane
  || $tcCanEventStylePane
  || $tcCanLogsPane;
$tcInitialPane = '';
foreach ([
  'tc-main' => $tcCanControlPanel,
  'tc-rewards-config' => $tcCanMainPane,
  'tc-invitees' => $tcCanInviteesPane,
  'tc-manage-tasks' => $tcCanManageTasksPane,
  'tc-monitoring' => $tcCanMonitoringPane,
  'tc-logs' => $tcCanLogsPane,
] as $paneKey => $allowed) {
  if ($allowed) {
    $tcInitialPane = $paneKey;
    break;
  }
}
require_once __DIR__ . '/tc-security.php';
$tcPanelCsrfToken = tcSecurityGetCsrfToken();

$tcRequestedPane = trim((string)($_GET['tc_pane'] ?? ''));
$tcIsLazyPaneRequest = $tcRequestedPane !== '';
$tcLazyPaneAccess = [
  'tc-main' => $tcCanControlPanel,
  'tc-rewards-config' => $tcCanMainPane,
  'tc-invitees' => $tcCanInviteesPane,
  'tc-manage-tasks' => $tcCanManageTasksPane,
  'tc-monitoring' => $tcCanMonitoringPane,
  'tc-logs' => $tcCanLogsPane,
];
if ($tcIsLazyPaneRequest && !array_key_exists($tcRequestedPane, $tcLazyPaneAccess)) {
  http_response_code(400);
  echo '<div class="card"><p class="muted">Unknown Task Club pane.</p></div>';
  exit;
}
if ($tcIsLazyPaneRequest && empty($tcLazyPaneAccess[$tcRequestedPane])) {
  http_response_code(403);
  echo '<div class="card"><p class="muted">You do not have access to this Task Club pane.</p></div>';
  exit;
}

$tcPanelCssVer = (string)(@tcDbFilemtime(__DIR__ . '/tc-panel.css') ?: time());
$tcPanelLocalJsVer = (string)(@tcDbFilemtime(__DIR__ . '/tc-panel-local.js') ?: time());
$tcPrizesJsVer = (string)(@tcDbFilemtime(__DIR__ . '/TC Prizes.js') ?: time());
$tcSettingJsVer = (string)(@tcDbFilemtime(__DIR__ . '/TCSetting.js') ?: time());
$tcEventStyleJsVer = (string)(@tcDbFilemtime(__DIR__ . '/TCEventStyle.js') ?: time());
$tcMonitoringJsVer = (string)(@tcDbFilemtime(__DIR__ . '/TCMonitoring.js') ?: time());
$tcTaskAccessJsVer = (string)(@tcDbFilemtime(__DIR__ . '/TCTaskAccess.js') ?: time());
?>

<?php if (!$tcIsLazyPaneRequest): ?>
<link rel="stylesheet" href="mini%20apps/missions/LahzehEyd/tc-panel.css?v=<?= htmlspecialchars($tcPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<div class="tc-shell" data-tc-csrf="<?= htmlspecialchars($tcPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
<div class="sub-layout" data-tc-sub-layout>
  <aside class="sub-sidebar">
    <div class="sub-header">Task Club</div>
    <div class="sub-nav">
      <?php if ($tcCanControlPanel): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-main' ? ' active' : '' ?>" data-pane="tc-main">کنترل پنل</button>
      <?php endif; ?>
      <?php if ($tcCanMainPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-rewards-config' ? ' active' : '' ?>" data-pane="tc-rewards-config">جوایز</button>
      <?php endif; ?>
      <?php if ($tcCanInviteesPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-invitees' ? ' active' : '' ?>" data-pane="tc-invitees">دعوت‌شدگان</button>
      <?php endif; ?>
      <?php if ($tcCanManageTasksPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-manage-tasks' ? ' active' : '' ?>" data-pane="tc-manage-tasks">مدیریت تسک‌ها</button>
      <?php endif; ?>
      <?php if ($tcCanMonitoringPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-monitoring' ? ' active' : '' ?>" data-pane="tc-monitoring">مانیتورینگ</button>
      <?php endif; ?>
      <?php if ($tcCanLogsPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-logs' ? ' active' : '' ?>" data-pane="tc-logs">Logs</button>
      <?php endif; ?>
      <?php if ($tcCanTaskSubtabs): ?>
        <div data-tc-task-subtab-nav></div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="sub-content">
<?php endif; ?>
    <?php if ($tcCanControlPanel && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-main')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-main' ? ' active' : '' ?>" data-pane="tc-main" data-tc-lazy-pane="tc-main">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load its controls.</p></div>
      <?php else: ?>
      <?php $tcControlInitialSection = $tcCanMainPane ? 'general' : ($tcCanTaskAccessPane ? 'admin-access' : ($tcCanExportPane ? 'export' : ($tcCanLinkerPane ? 'linker' : 'event-style'))); ?>
      <div class="tc-task-top-nav" role="tablist" aria-label="تب‌های کنترل پنل">
        <?php if ($tcCanMainPane): ?>
          <button type="button" class="tc-task-top-item<?= $tcControlInitialSection === 'general' ? ' active' : '' ?>" aria-selected="<?= $tcControlInitialSection === 'general' ? 'true' : 'false' ?>" data-tc-control-panel-trigger="general">عمومی</button>
          <button type="button" class="tc-task-top-item" aria-selected="false" data-tc-control-panel-trigger="landing">Landing</button>
        <?php endif; ?>
        <?php if ($tcCanMainPane || $tcCanTaskAccessPane): ?>
          <button type="button" class="tc-task-top-item<?= $tcControlInitialSection === 'admin-access' ? ' active' : '' ?>" aria-selected="<?= $tcControlInitialSection === 'admin-access' ? 'true' : 'false' ?>" data-tc-control-panel-trigger="admin-access">دسترسی ادمین</button>
        <?php endif; ?>
        <?php if ($tcCanExportPane): ?>
          <button type="button" class="tc-task-top-item<?= $tcControlInitialSection === 'export' ? ' active' : '' ?>" aria-selected="<?= $tcControlInitialSection === 'export' ? 'true' : 'false' ?>" data-tc-control-panel-trigger="export">خروجی</button>
        <?php endif; ?>
        <?php if ($tcCanLinkerPane): ?>
          <button type="button" class="tc-task-top-item<?= $tcControlInitialSection === 'linker' ? ' active' : '' ?>" aria-selected="<?= $tcControlInitialSection === 'linker' ? 'true' : 'false' ?>" data-tc-control-panel-trigger="linker">Linker</button>
        <?php endif; ?>
        <?php if ($tcCanEventStylePane): ?>
          <button type="button" class="tc-task-top-item<?= $tcControlInitialSection === 'event-style' ? ' active' : '' ?>" aria-selected="<?= $tcControlInitialSection === 'event-style' ? 'true' : 'false' ?>" data-tc-control-panel-trigger="event-style">استایل رویداد</button>
        <?php endif; ?>
      </div>

      <?php if ($tcCanMainPane): ?>
      <section data-tc-control-panel-section="general"<?= $tcControlInitialSection === 'general' ? '' : ' hidden' ?>>
      <div class="card">
  <div class="section-header">
    <h3>وضعیت</h3>
  </div>
  <div class="field">
    <a class="btn primary standard-primary-button" href="mini%20apps/missions/LahzehEyd/TCM.php" target="_blank" rel="noopener">باز کردن تسک کلاب</a>
  </div>
</div>

<div class="card" id="tc-texts-card">
  <div class="section-header">
    <h3>کنترل پنل</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div id="tc-status-text" class="tc-status tc-status--inactive">غیرفعال</div>
    <div class="tc-switch-grid">
      <label class="switch tc-switch">
        <span class="switch-label">فعال</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-active-toggle" aria-label="فعال" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch tc-switch">
        <span class="switch-label">زمان‌بندی</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-duration-toggle" aria-label="زمان‌بندی" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch tc-switch">
        <span class="switch-label">حالت تعمیرات</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-maintenance-toggle" aria-label="حالت تعمیرات" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch tc-switch">
        <span class="switch-label">قفل ماموریت‌ها و کارت‌ها</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-event-access-lock-toggle" aria-label="قفل ماموریت‌ها و کارت‌ها" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
    </div>
    <div class="form grid two-column-fields tc-datetime-grid">
      <div class="tc-datetime-title tc-datetime-title--start">شروع</div>
      <label class="field standard-width tc-datetime-start">
        <span>تاریخ</span>
        <input
          type="date"
          id="tc-duration-start"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width tc-datetime-start-time">
        <span>ساعت</span>
        <select id="tc-duration-start-time">
          <option value="">انتخاب ساعت</option>
          <option value="00:00">00:00</option>
          <option value="01:00">01:00</option>
          <option value="02:00">02:00</option>
          <option value="03:00">03:00</option>
          <option value="04:00">04:00</option>
          <option value="05:00">05:00</option>
          <option value="06:00">06:00</option>
          <option value="07:00">07:00</option>
          <option value="08:00">08:00</option>
          <option value="09:00">09:00</option>
          <option value="10:00">10:00</option>
          <option value="11:00">11:00</option>
          <option value="12:00">12:00</option>
          <option value="13:00">13:00</option>
          <option value="14:00">14:00</option>
          <option value="15:00">15:00</option>
          <option value="16:00">16:00</option>
          <option value="17:00">17:00</option>
          <option value="18:00">18:00</option>
          <option value="19:00">19:00</option>
          <option value="20:00">20:00</option>
          <option value="21:00">21:00</option>
          <option value="22:00">22:00</option>
          <option value="23:00">23:00</option>
        </select>
      </label>
      <div class="tc-datetime-title tc-datetime-title--end">پایان</div>
      <label class="field standard-width tc-datetime-end">
        <span>تاریخ</span>
        <input
          type="date"
          id="tc-duration-end"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width tc-datetime-end-time">
        <span>ساعت</span>
        <select id="tc-duration-end-time">
          <option value="">انتخاب ساعت</option>
          <option value="00:00">00:00</option>
          <option value="01:00">01:00</option>
          <option value="02:00">02:00</option>
          <option value="03:00">03:00</option>
          <option value="04:00">04:00</option>
          <option value="05:00">05:00</option>
          <option value="06:00">06:00</option>
          <option value="07:00">07:00</option>
          <option value="08:00">08:00</option>
          <option value="09:00">09:00</option>
          <option value="10:00">10:00</option>
          <option value="11:00">11:00</option>
          <option value="12:00">12:00</option>
          <option value="13:00">13:00</option>
          <option value="14:00">14:00</option>
          <option value="15:00">15:00</option>
          <option value="16:00">16:00</option>
          <option value="17:00">17:00</option>
          <option value="18:00">18:00</option>
          <option value="19:00">19:00</option>
          <option value="20:00">20:00</option>
          <option value="21:00">21:00</option>
          <option value="22:00">22:00</option>
          <option value="23:00">23:00</option>
        </select>
      </label>
      <div class="tc-datetime-empty" aria-hidden="true"></div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="tc-settings-save">ذخیره</button>
    </div>
  </div>
</div>

      </section>
      <?php endif; ?>

      <?php if ($tcCanMainPane): ?>
      <section data-tc-control-panel-section="landing" hidden>
      <div class="card tc-landing-editor-card">
        <div class="section-header">
          <h3>Landing</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>Title</span>
            <input type="text" id="tc-landing-title" autocomplete="off" />
          </label>
          <label class="field full">
            <span>Subtitle</span>
            <textarea id="tc-landing-subtitle" rows="3"></textarea>
          </label>
          <div class="tc-landing-sections-head">
            <div>
              <strong>Text sections</strong>
              <p class="muted small">Use the editor buttons or Ctrl+B, Ctrl+L, and Ctrl+Alt+1 inside a section text area.</p>
            </div>
            <button type="button" class="btn ghost" id="tc-landing-add-section">Add section</button>
          </div>
          <div id="tc-landing-sections" class="tc-landing-sections"></div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-landing-save">Save landing</button>
          </div>
          <p class="muted small" id="tc-landing-status" aria-live="polite"></p>
        </div>
      </div>
      </section>
      <?php endif; ?>

      <?php if ($tcCanMainPane || $tcCanTaskAccessPane): ?>
      <section data-tc-control-panel-section="admin-access"<?= $tcControlInitialSection === 'admin-access' ? '' : ' hidden' ?>>
      <?php if ($tcCanMainPane): ?>
<div class="card" id="tc-assign-admin-card">
  <div class="section-header">
    <h3>تعیین ادمین</h3>
  </div>
  <div class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>شناسه کاری</span>
      <input id="tc-admin-workid" type="text" autocomplete="off" placeholder="شناسه کاری را وارد کنید" />
    </label>
    <div class="field full tc-form-action">
      <button type="button" class="btn primary standard-primary-button" id="tc-admin-search-btn">جستجوی کاربر</button>
    </div>
    <div id="tc-admin-search-result" class="tc-admin-search-result hidden" aria-live="polite"></div>
    <p id="tc-admin-status" class="muted small" aria-live="polite"></p>
    <div class="table-wrapper">
      <table class="tc-admin-table">
        <thead>
          <tr>
            <th>نام</th>
            <th>شناسه کاری</th>
            <th>شماره تلفن</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody id="tc-admin-list">
          <tr>
            <td colspan="4" class="muted">هنوز ادمینی تعیین نشده است.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
      <?php endif; ?>

      <?php if ($tcCanTaskAccessPane): ?>
      <div class="card">
        <div class="section-header">
          <h3>دسترسی تسک‌ها</h3>
        </div>
        <div class="form" style="gap:12px;">
          <p class="muted small">فهرست کاربران دارای دسترسی «باشگاه تعاملی». برای هر کاربر می‌توانید «دسترسی تسک‌ها» و «دسترسی‌های خاص» را تنظیم کنید.</p>
          <div class="table-wrapper">
            <table class="tct-list-table tc-task-access-users-table">
              <thead>
                <tr>
                  <th>ردیف</th>
                  <th>نام کاربر</th>
                  <th>شناسه</th>
                  <th>نام کاربری</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody id="tc-task-access-users-body">
                <tr>
                  <td colspan="5" class="muted">در حال بارگذاری...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div id="tc-task-access-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="tc-task-access-modal-title">
        <div class="modal-card default-modal-card">
          <div class="modal-card-header">
            <h3 id="tc-task-access-modal-title">دسترسی‌ها</h3>
            <button type="button" class="icon-btn" data-tc-task-access-close aria-label="بستن">×</button>
          </div>
          <p class="hint">دسترسی هر کاربر به تب‌ها و زیربخش‌های هر تسک از این بخش مدیریت می‌شود.</p>
          <label class="tc-task-access-manage-row">
            <input type="checkbox" id="tc-task-access-manage-tasks" />
            <span>دسترسی به تب مدیریت تسک‌ها</span>
          </label>
          <p class="muted small" id="tc-task-access-status" aria-live="polite"></p>
          <div id="tc-task-access-tree" class="tc-task-access-tree"></div>
          <div class="modal-actions">
            <button type="button" class="btn" data-tc-task-access-close>بستن</button>
            <button type="button" class="btn primary" id="tc-task-access-save">ذخیره دسترسی‌ها</button>
          </div>
        </div>
      </div>

      <div id="tc-task-special-access-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="tc-task-special-access-modal-title">
        <div class="modal-card default-modal-card">
          <div class="modal-card-header">
            <h3 id="tc-task-special-access-modal-title">دسترسی‌های خاص</h3>
            <button type="button" class="icon-btn" data-tc-task-special-close aria-label="بستن">×</button>
          </div>
          <p class="hint">این دسترسی‌ها مربوط به تب «دعوت‌شدگان» هستند.</p>
          <div class="tc-task-special-access-grid">
            <label class="tc-task-access-manage-row">
              <input type="checkbox" id="tc-special-invitees-manage" />
              <span>مدیریت دعوت‌شدگان</span>
            </label>
            <label class="tc-task-access-manage-row">
              <input type="checkbox" id="tc-special-invitees-reset" />
              <span>بازنشانی دعوت‌شده</span>
            </label>
            <label class="tc-task-access-manage-row">
              <input type="checkbox" id="tc-special-invitees-reveal" />
              <span>نمایش رمز عبور</span>
            </label>
            <label class="tc-task-access-manage-row">
              <input type="checkbox" id="tc-special-invitees-edit" />
              <span>ویرایش دعوت‌شده</span>
            </label>
          </div>
          <p class="muted small" id="tc-task-special-access-status" aria-live="polite"></p>
          <div class="modal-actions">
            <button type="button" class="btn" data-tc-task-special-close>بستن</button>
            <button type="button" class="btn primary" id="tc-task-special-access-save">ذخیره دسترسی‌های خاص</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($tcCanExportPane): ?>
      <section data-tc-control-panel-section="export"<?= $tcControlInitialSection === 'export' ? '' : ' hidden' ?>>
      <div class="card">
        <div class="section-header">
          <h3>خروجی گرفتن</h3>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/missions/LahzehEyd/export_login_data.php"
            target="_blank"
            rel="noopener"
          >خروجی اطلاعات ورود</a>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/missions/LahzehEyd/export_participants.php"
            target="_blank"
            rel="noopener"
          >خروجی شرکت‌کنندگان</a>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/missions/LahzehEyd/valuable_prizes_export.php"
            target="_blank"
            rel="noopener"
          >خروجی برندگان جوایز ارزشمند</a>
        </div>
        <div class="field">
          <button
            type="button"
            class="btn primary standard-primary-button"
            id="tc-invitee-prize-totals-open"
          >خروجی مجموع جوایز دعوت‌شدگان</button>
        </div>
        <div id="tc-invitee-prize-totals-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="tc-invitee-prize-totals-title">
          <div class="modal-card default-modal-card">
            <div class="modal-card-header">
              <h3 id="tc-invitee-prize-totals-title">خروجی مجموع جوایز دعوت‌شدگان</h3>
              <button type="button" class="btn ghost" data-tc-prize-totals-close>بستن</button>
            </div>
            <p class="muted" id="tc-invitee-prize-totals-status" aria-live="polite"></p>
            <div class="modal-actions">
              <form method="post" action="mini%20apps/missions/LahzehEyd/invitees_prize_totals_export.php" target="_blank" data-tc-prize-totals-form>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($tcPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>" />
                <input type="hidden" name="action" value="remaining" />
                <button type="submit" class="btn primary standard-primary-button">خروجی باقی مانده ها و اضافه کردن به لیست دریافت کرده</button>
              </form>
              <form method="post" action="mini%20apps/missions/LahzehEyd/invitees_prize_totals_export.php" target="_blank" data-tc-prize-totals-form>
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($tcPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>" />
                <input type="hidden" name="action" value="all" />
                <button type="submit" class="btn ghost">خروجی همه برندگان</button>
              </form>
            </div>
          </div>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/missions/LahzehEyd/prize_winners_log.php"
            target="_blank"
            rel="noopener"
          >Prize card winners log</a>
        </div>
        <?php
          $tcPrizeLevelsPath = __DIR__ . '/TC Prize Levels.json';
          $tcPrizeLevels = [];
          if (tcDbIsFile($tcPrizeLevelsPath)) {
            $tcPrizeLevelsDecoded = json_decode((string)tcDbFileGetContents($tcPrizeLevelsPath), true);
            if (is_array($tcPrizeLevelsDecoded)) $tcPrizeLevels = $tcPrizeLevelsDecoded;
          }
          foreach ($tcPrizeLevels as $tcPrizeLevel):
            if (!is_array($tcPrizeLevel) || strtolower(trim((string)($tcPrizeLevel['type'] ?? ''))) !== 'pot') continue;
            $tcPotLevelId = trim((string)($tcPrizeLevel['id'] ?? ''));
            if ($tcPotLevelId === '') continue;
            $tcPotLevelName = trim((string)($tcPrizeLevel['name'] ?? 'Pot'));
            $tcPotExportBase = 'mini%20apps/missions/LahzehEyd/pot_export.php?level_id=' . rawurlencode($tcPotLevelId);
        ?>
        <div class="field">
          <span><?= htmlspecialchars($tcPotLevelName, ENT_QUOTES, 'UTF-8') ?></span>
          <div class="tc-action-bar">
            <a class="btn primary standard-primary-button" href="<?= htmlspecialchars($tcPotExportBase . '&type=winners', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">خروجی برندگان</a>
            <a class="btn ghost" href="<?= htmlspecialchars($tcPotExportBase . '&type=reached_non_winners', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">خروجی رسیده‌ها بدون برد</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      </section>
      <?php endif; ?>

      <?php if ($tcCanLinkerPane): ?>
      <section data-tc-control-panel-section="linker"<?= $tcControlInitialSection === 'linker' ? '' : ' hidden' ?>>
      <div class="card" id="tc-campaign-linker-card">
        <div class="section-header">
          <h3>Linker</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>Campaign path</span>
            <div class="tc-linker-input-row">
              <span class="tc-linker-prefix">/campaigns/</span>
              <input id="tc-linker-path" type="text" maxlength="180" autocomplete="off" dir="ltr" placeholder="dastavard" />
            </div>
          </label>
          <p id="tc-linker-preview" class="hint muted small" aria-live="polite"></p>
          <div class="tc-action-bar">
            <button type="button" class="btn ghost" id="tc-linker-check">Check availability</button>
            <button type="button" class="btn primary standard-primary-button" id="tc-linker-create" disabled>Create redirect</button>
          </div>
          <p id="tc-linker-status" class="hint muted small" aria-live="polite"></p>
          <div class="table-wrapper">
            <table class="tct-list-table tc-linker-table">
              <thead>
                <tr>
                  <th>Campaign URL</th>
                  <th>Type</th>
                  <th>Target</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="tc-linker-current-body">
                <tr>
                  <td colspan="4" class="muted">Check a campaign path to see whether it is available.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      </section>
      <?php endif; ?>

      <?php if ($tcCanEventStylePane): ?>
      <section data-tc-control-panel-section="event-style"<?= $tcControlInitialSection === 'event-style' ? '' : ' hidden' ?>>
      <div class="card">
        <div class="section-header">
          <h3>نام رویداد</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>نام رویداد</span>
            <input id="tc-event-name" type="text" maxlength="120" autocomplete="off" />
          </label>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-event-name-save">ذخیره</button>
          </div>
          <p id="tc-event-name-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      <div class="card" id="tc-mission-link-card">
        <div class="section-header">
          <h3>لینک باشگاه</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>کد لینک</span>
            <input id="tc-mission-link-code" type="text" maxlength="80" autocomplete="off" dir="ltr" placeholder="club-code" />
          </label>
          <p id="tc-mission-link-preview" class="hint muted small" aria-live="polite"></p>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-mission-link-save">ذخیره لینک</button>
          </div>
          <p id="tc-mission-link-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      <div class="card">
        <div class="section-header">
          <h3>لوگوی رویداد</h3>
        </div>
        <div class="form">
          <div class="photo-uploader tc-event-logo-uploader">
            <div class="photo-preview" aria-live="polite">
              <img id="tc-event-logo-image" class="hidden" alt="پیش‌نمایش لوگوی رویداد" />
              <div id="tc-event-logo-placeholder" class="photo-placeholder">تصویری انتخاب نشده است</div>
            </div>
            <div class="photo-actions">
              <button type="button" class="btn" id="tc-event-logo-pick">انتخاب تصویر</button>
              <button type="button" class="btn ghost" id="tc-event-logo-clear" disabled>حذف تصویر</button>
            </div>
            <p id="tc-event-style-logo-status" class="hint muted small" aria-live="polite"></p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="section-header">
          <h3>رنگ‌ها</h3>
        </div>
        <div class="form" style="gap:12px;">
          <div class="appearance-row">
            <span class="appearance-label">رنگ دوم</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-secondary" class="appearance-hex-field" type="text" maxlength="7" value="#2F8FFF" />
              <button type="button" class="appearance-preview" id="tc-event-preview-secondary" aria-label="انتخاب رنگ دوم"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-secondary">انتخاب</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">رنگ برجسته</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-highlight" class="appearance-hex-field" type="text" maxlength="7" value="#20C997" />
              <button type="button" class="appearance-preview" id="tc-event-preview-highlight" aria-label="انتخاب رنگ برجسته"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-highlight">انتخاب</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">رنگ تاکیدی ملایم</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-accent-soft" class="appearance-hex-field" type="text" maxlength="7" value="#FFB347" />
              <button type="button" class="appearance-preview" id="tc-event-preview-accent-soft" aria-label="انتخاب رنگ تاکیدی ملایم"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-accent-soft">انتخاب</button>
            </div>
          </div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-event-style-save">ذخیره</button>
          </div>
          <p id="tc-event-style-save-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      </section>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanMainPane && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-rewards-config')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-rewards-config' ? ' active' : '' ?>" data-pane="tc-rewards-config" data-tc-lazy-pane="tc-rewards-config">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load prize settings.</p></div>
      <?php else: ?>
      <div class="tc-task-top-nav" role="tablist" aria-label="تب‌های جوایز">
        <button type="button" class="tc-task-top-item active" aria-selected="true" data-tc-reward-config-trigger="guide">راهنمای دریافت جایزه</button>
        <button type="button" class="tc-task-top-item" aria-selected="false" data-tc-reward-config-trigger="advanced">Advanced Prize Setting</button>
        <button type="button" class="tc-task-top-item" aria-selected="false" data-tc-reward-config-trigger="levels">سطح بندی جوایز</button>
        <button type="button" class="tc-task-top-item" aria-selected="false" data-tc-reward-config-trigger="storage">انبار جوایز</button>
      </div>

      <section data-tc-reward-config-section="guide">
        <div class="card">
          <div class="section-header"><h3>کارت راهنما</h3></div>
          <div class="form" style="gap:12px;">
            <label class="field standard-width">
              <span>عنوان</span>
              <input type="text" id="tc-reward-guide-title" value="راهنمای دریافت جایزه" />
            </label>
            <label class="field full">
              <span>متن راهنما</span>
              <textarea id="tc-reward-guide-text" rows="8"></textarea>
            </label>
            <div class="field full">
              <button type="button" class="btn primary standard-primary-button" id="tc-reward-guide-save">ذخیره</button>
            </div>
            <p class="muted small" id="tc-reward-guide-status" aria-live="polite"></p>
          </div>
        </div>
      </section>

      <section data-tc-reward-config-section="advanced" hidden>
        <div class="card">
          <div class="section-header">
            <h3>Advanced Prize Setting</h3>
          </div>
          <div class="form" style="gap:12px;">
            <div class="tc-switch-grid tc-reward-advanced-switch-grid">
              <label class="switch tc-switch">
                <span class="switch-label">Non Value Prize Describe</span>
                <span class="switch-toggle">
                  <input id="tc-reward-non-value-describe-toggle" type="checkbox" />
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </span>
              </label>
              <label class="switch tc-switch">
                <span class="switch-label">Show Prize</span>
                <span class="switch-toggle">
                  <input id="tc-reward-show-prize-toggle" type="checkbox" checked />
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </span>
              </label>
            </div>
            <label class="field full">
              <span>Text shown when prize value is hidden</span>
              <input id="tc-reward-hidden-prize-text" type="text" autocomplete="off" />
            </label>
            <div class="field full">
              <button type="button" class="btn primary standard-primary-button" id="tc-reward-advanced-save">Save</button>
            </div>
            <p class="muted small" id="tc-reward-advanced-status" aria-live="polite"></p>
          </div>
        </div>
      </section>

      <section data-tc-reward-config-section="levels" hidden>
        <div class="card">
          <div class="section-header">
            <h3>سطح بندی جوایز</h3>
          </div>
          <form id="tc-prize-level-form" class="form" style="gap:12px;">
            <label class="field standard-width">
              <span>نام سطح</span>
              <input id="tc-prize-level-name" name="name" type="text" autocomplete="off" required />
            </label>
            <label class="field standard-width">
              <span>نوع محاسبه</span>
              <select id="tc-prize-level-type" name="type" required>
                <option value="value_sum">مجموع ارزش جوایز</option>
                <option value="out_of_value">خارج از ارزش جایزه</option>
                <option value="pot">Pot</option>
              </select>
            </label>
            <label class="field standard-width">
              <span>امتیاز</span>
              <input id="tc-prize-level-score" name="score" type="number" min="1" step="1" inputmode="numeric" required />
            </label>
            <div class="field full">
              <button type="submit" class="btn primary standard-primary-button">افزودن سطح</button>
            </div>
            <p id="tc-prize-level-status" class="muted small" aria-live="polite"></p>
          </form>
          <div class="table-wrapper">
            <table class="tc-prize-level-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>نام سطح</th>
                  <th>نوع محاسبه</th>
                  <th>امتیاز</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody id="tc-prize-level-list">
                <tr><td colspan="5" class="muted">هنوز سطحی اضافه نشده است.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section data-tc-reward-config-section="storage" hidden>
        <div id="tc-prize-section">
          <div class="card">
            <div class="section-header">
              <h3>افزودن جایزه</h3>
            </div>
            <form id="tc-prize-form" class="form">
              <div class="form grid tc-prize-grid">
                <label class="field standard-width">
                  <span>نام جایزه</span>
                  <input id="tc-prize-name" name="name" type="text" autocomplete="off" required />
                </label>
                <label class="field tc-standard-third">
                  <span>تعداد</span>
                  <input
                    id="tc-prize-quantity"
                    name="quantity"
                    type="number"
                    min="1"
                    step="1"
                    value="1"
                    required
                  />
                </label>
                <label class="field standard-width">
                  <span>ارزش</span>
                  <input
                    id="tc-prize-value"
                    name="value"
                    type="text"
                    inputmode="decimal"
                    placeholder="0"
                    autocomplete="off"
                    required
                  />
                </label>
              </div>
              <div class="field full tc-form-action">
                <button type="submit" class="btn primary standard-primary-button">افزودن</button>
              </div>
            </form>
          </div>

          <div class="card">
            <div class="section-header">
              <h3>جوایز</h3>
            </div>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>نام جایزه</th>
                    <th>نام نمایشی</th>
                    <th>وضعیت موجودی</th>
                    <th>تعداد کل</th>
                    <th>ارزش</th>
                    <th>کنترل تعداد</th>
                    <th>عملیات</th>
                  </tr>
                </thead>
                <tbody id="tc-prize-list"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="section-header">
              <h3>Won prize records</h3>
              <div class="tc-action-bar">
                <button type="button" class="btn ghost" id="tc-prize-awards-refresh">Refresh</button>
                <button type="button" class="btn tc-btn-danger" id="tc-prize-awards-reset-all">Reset all prizes</button>
              </div>
            </div>
            <p class="muted small" id="tc-prize-awards-status" aria-live="polite"></p>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr><th>User</th><th>Prize value</th><th>Card</th><th>Level</th><th>Prize</th><th>Won at</th><th>Action</th></tr>
                </thead>
                <tbody id="tc-prize-awards-list"><tr><td colspan="7" class="muted">Loading prize records...</td></tr></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="section-header">
              <h3>آیتم‌های نمایشی</h3>
            </div>
            <form id="tc-fake-form" class="form">
              <label class="field standard-width">
                <span>نام آیتم نمایشی</span>
                <input id="tc-fake-name" name="fake-name" type="text" autocomplete="off" required />
              </label>
              <div class="field full">
                <button type="submit" class="btn primary standard-primary-button">افزودن</button>
              </div>
            </form>
            <div class="table-wrapper tc-fake-table">
              <table class="tc-prize-table">
                <thead>
                  <tr>
                    <th>نام آیتم</th>
                    <th>عملیات</th>
                  </tr>
                </thead>
                <tbody id="tc-fake-list"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanInviteesPane && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-invitees')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-invitees' ? ' active' : '' ?>" data-pane="tc-invitees" data-tc-lazy-pane="tc-invitees" data-tc-lazy-dispose="1">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load invitees.</p></div>
      <?php else: ?>
        <?php include __DIR__ . '/invitees.php'; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanManageTasksPane && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-manage-tasks')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-manage-tasks' ? ' active' : '' ?>" data-pane="tc-manage-tasks" data-tc-lazy-pane="tc-manage-tasks">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load task management.</p></div>
      <?php else: ?>
        <?php include __DIR__ . '/TCT.php'; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanMonitoringPane && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-monitoring')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-monitoring' ? ' active' : '' ?>" data-pane="tc-monitoring" data-tc-lazy-pane="tc-monitoring">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load monitoring.</p></div>
      <?php else: ?>
        <?php include __DIR__ . '/TCMonitoring.php'; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanLogsPane && (!$tcIsLazyPaneRequest || $tcRequestedPane === 'tc-logs')): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-logs' ? ' active' : '' ?>" data-pane="tc-logs" data-tc-logs-pane="1" data-tc-lazy-pane="tc-logs">
      <?php if (!$tcIsLazyPaneRequest): ?>
        <div class="card"><p class="muted" data-tc-lazy-status>Open this tab to load logs.</p></div>
      <?php else: ?>
      <div class="card tc-logs-card">
        <div class="section-header">
          <h3>Logs</h3>
        </div>
        <div class="tc-logs-toolbar">
          <label class="field tc-logs-search-field">
            <span>Search username</span>
            <input id="tc-logs-search" type="search" placeholder="Type username, user id, action..." autocomplete="off" />
          </label>
          <label class="field tc-logs-day-field">
            <span>Day</span>
            <select id="tc-logs-day"></select>
          </label>
          <button type="button" class="btn ghost" id="tc-logs-refresh">Refresh</button>
        </div>
        <p class="muted tc-logs-status" id="tc-logs-status" aria-live="polite">Open this tab to load logs.</p>
        <div class="table-wrapper tc-logs-table-wrap">
          <table class="tc-logs-table">
            <thead>
              <tr>
                <th>Time</th>
                <th>Level</th>
                <th>User</th>
                <th>Action</th>
                <th>Status</th>
                <th>Message</th>
                <th>Details</th>
                <th>IP</th>
              </tr>
            </thead>
            <tbody id="tc-logs-body">
              <tr><td colspan="8" class="muted">Logs are loaded separately when this tab opens.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!$tcIsLazyPaneRequest && $tcCanTaskSubtabs): ?>
    <div data-tc-task-subtab-panes></div>
    <?php endif; ?>
    <?php if (!$tcIsLazyPaneRequest && !$tcHasAnyPane): ?>
      <div class="card">
        <div class="section-header">
          <h3>Access Restricted</h3>
        </div>
        <p class="muted">You do not have access to any Task Club subtab.</p>
      </div>
    <?php endif; ?>
<?php if (!$tcIsLazyPaneRequest): ?>
  </div>
</div>
</div>

<script src="mini%20apps/missions/LahzehEyd/tc-panel-local.js?v=<?= htmlspecialchars($tcPanelLocalJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php else: ?>
<?php if ($tcRequestedPane === 'tc-rewards-config'): ?>
<script src="mini%20apps/missions/LahzehEyd/TC%20Prizes.js?v=<?= htmlspecialchars($tcPrizesJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcRequestedPane === 'tc-main' || $tcRequestedPane === 'tc-rewards-config'): ?>
<script src="mini%20apps/missions/LahzehEyd/TCSetting.js?v=<?= htmlspecialchars($tcSettingJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcRequestedPane === 'tc-main' && $tcCanEventStylePane): ?>
<script src="mini%20apps/missions/LahzehEyd/TCEventStyle.js?v=<?= htmlspecialchars($tcEventStyleJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcRequestedPane === 'tc-main' && $tcCanTaskAccessPane): ?>
<script src="mini%20apps/missions/LahzehEyd/TCTaskAccess.js?v=<?= htmlspecialchars($tcTaskAccessJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcRequestedPane === 'tc-monitoring'): ?>
<script src="mini%20apps/missions/LahzehEyd/TCMonitoring.js?v=<?= htmlspecialchars($tcMonitoringJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php endif; ?>
