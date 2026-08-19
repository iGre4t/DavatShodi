<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/egm-invite-card-pane.php';
$egmPanelUser = requireTabPermissionFromSession('event-guest-manager', false);
$egmAllowedChildTabs = resolveAllowedPanelChildTabsForUser($egmPanelUser, 'event-guest-manager');
$egmAllowedChildSet = array_fill_keys($egmAllowedChildTabs, true);
$egmCanMainPane = isset($egmAllowedChildSet['event-guest-manager:main']);
$egmCanInviteCardPane = $egmCanMainPane;
$egmCanInviteesPane = isset($egmAllowedChildSet['event-guest-manager:invitees']);
$egmCanManageTasksPane = isset($egmAllowedChildSet['event-guest-manager:manage-tasks']);
$egmCanTaskAccessPane = isset($egmAllowedChildSet['event-guest-manager:task-access']);
$egmCanMonitoringPane = isset($egmAllowedChildSet['event-guest-manager:monitoring']);
$egmCanExportPane = isset($egmAllowedChildSet['event-guest-manager:export']);
$egmCanLinkerPane = isset($egmAllowedChildSet['event-guest-manager:linker']);
$egmCanEventStylePane = isset($egmAllowedChildSet['event-guest-manager:event-style']);
$egmCanLogsPane = isset($egmAllowedChildSet['event-guest-manager:logs']);
$egmCanControlPanel = $egmCanMainPane || $egmCanTaskAccessPane || $egmCanExportPane || $egmCanLinkerPane || $egmCanEventStylePane;
$egmNormalizeBool = static function ($value): bool {
  if (is_bool($value)) {
    return $value;
  }
  if (is_numeric($value)) {
    return ((int)$value) === 1;
  }
  $token = strtolower(trim((string)$value));
  return in_array($token, ['1', 'true', 'on', 'yes'], true);
};
$egmSessionUserCode = strtolower(trim((string)($egmPanelUser['code'] ?? '')));
$egmManageTasksOverride = null;
$egmHasTaskSubtabAccess = false;
if ($egmSessionUserCode !== '') {
  $egmTaskAccessPath = __DIR__ . '/tasks/task-access.json';
  if (egmDbIsFile($egmTaskAccessPath)) {
    $egmTaskAccessRaw = egmDbFileGetContents($egmTaskAccessPath);
    $egmTaskAccessDecoded = is_string($egmTaskAccessRaw) ? json_decode($egmTaskAccessRaw, true) : null;
    $egmTaskAccessUsers = is_array($egmTaskAccessDecoded['users'] ?? null) ? $egmTaskAccessDecoded['users'] : [];
    foreach ($egmTaskAccessUsers as $rawCode => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      if (strtolower(trim((string)$rawCode)) !== $egmSessionUserCode) {
        continue;
      }
      $egmManageTasksOverride = $egmNormalizeBool($entry['allowManageTasksTab'] ?? ($entry['allow_manage_tasks_tab'] ?? false));
      $egmTaskRules = is_array($entry['tasks'] ?? null) ? $entry['tasks'] : [];
      foreach ($egmTaskRules as $egmRule) {
        if (!is_array($egmRule)) {
          continue;
        }
        if (!$egmNormalizeBool($egmRule['enabled'] ?? true)) {
          continue;
        }
        $egmPaneRules = is_array($egmRule['panes'] ?? null) ? $egmRule['panes'] : [];
        if (count($egmPaneRules) === 0) {
          $egmHasTaskSubtabAccess = true;
          break;
        }
        foreach ($egmPaneRules as $egmPaneAllowed) {
          if ($egmNormalizeBool($egmPaneAllowed)) {
            $egmHasTaskSubtabAccess = true;
            break 2;
          }
        }
      }
      break;
    }
  }
}
if ($egmManageTasksOverride !== null) {
  $egmCanManageTasksPane = $egmManageTasksOverride;
}
$egmCanTaskSubtabs = $egmCanManageTasksPane || $egmHasTaskSubtabAccess;
$egmHasAnyPane = $egmCanMainPane
  || $egmCanInviteesPane
  || $egmCanInviteCardPane
  || $egmCanManageTasksPane
  || $egmCanTaskSubtabs
  || $egmCanTaskAccessPane
  || $egmCanMonitoringPane
  || $egmCanExportPane
  || $egmCanLinkerPane
  || $egmCanEventStylePane
  || $egmCanLogsPane;
$egmInitialPane = '';
foreach ([
  'egm-main' => $egmCanControlPanel,
  'egm-rewards-config' => $egmCanMainPane,
  'egm-invitees' => $egmCanInviteesPane,
  'egm-invite-card' => $egmCanInviteCardPane,
  'egm-manage-tasks' => $egmCanManageTasksPane,
  'egm-monitoring' => $egmCanMonitoringPane,
  'egm-logs' => $egmCanLogsPane,
] as $paneKey => $allowed) {
  if ($allowed) {
    $egmInitialPane = $paneKey;
    break;
  }
}
$egmPanelCsrfToken = egmSecurityGetCsrfToken();
$egmPanelRuntimeContext = egmDatabaseRuntimeContextForPath(__DIR__ . '/Setting.json');
$egmPanelRegistry = is_array($egmPanelRuntimeContext) && ($egmPanelRuntimeContext['pdo'] ?? null) instanceof PDO
  ? egmInstanceRegistryForDirectory($egmPanelRuntimeContext['pdo'], __DIR__)
  : null;
$egmPanelInstanceCode = normalizeEgmInstanceCode(
  $egmPanelRegistry['code'] ?? ($egmPanelRuntimeContext['code'] ?? '')
);
$egmPanelInstanceName = trim((string)($egmPanelRegistry['name'] ?? ''));
if ($egmPanelInstanceName === '') {
  $egmPanelInstanceName = $egmPanelInstanceCode === '00000' ? 'EGM Develop' : 'EGM';
}

$egmPanelCssVer = (string)(@egmDbFilemtime(__DIR__ . '/egm-panel.css') ?: time());
$egmPanelLocalJsVer = (string)(@egmDbFilemtime(__DIR__ . '/egm-panel-local.js') ?: time());
$egmPrizesJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGM Prizes.js') ?: time());
$egmSettingJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMSetting.js') ?: time());
$egmEventStyleJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMEventStyle.js') ?: time());
$egmMonitoringJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMMonitoring.js') ?: time());
$egmTaskAccessJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMTaskAccess.js') ?: time());
$egmInviteCardCssVer = (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-invite-card.css') ?: time());
$egmInviteCardJsVer = (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-invite-card.js') ?: time());
?>

<link rel="stylesheet" href="mini%20apps/Event%20Guest%20Manager/egm-panel.css?v=<?= htmlspecialchars($egmPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<?php if ($egmCanInviteCardPane || $egmCanManageTasksPane): ?>
<link rel="stylesheet" href="assets/egm-invite-card.css?v=<?= htmlspecialchars($egmInviteCardCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<?php endif; ?>
<div class="egm-shell" data-egm-csrf="<?= htmlspecialchars($egmPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
<div class="sub-layout" data-egm-sub-layout>
  <aside class="sub-sidebar">
    <div class="sub-header"><?= htmlspecialchars($egmPanelInstanceName, ENT_QUOTES, 'UTF-8') ?> <span class="muted" dir="ltr">(<?= htmlspecialchars($egmPanelInstanceCode, ENT_QUOTES, 'UTF-8') ?>)</span></div>
    <div class="sub-nav">
      <?php if ($egmCanControlPanel): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-main' ? ' active' : '' ?>" data-pane="egm-main">کنترل پنل</button>
      <?php endif; ?>
      <?php if ($egmCanMainPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-rewards-config' ? ' active' : '' ?>" data-pane="egm-rewards-config">جوایز</button>
      <?php endif; ?>
      <?php if ($egmCanInviteesPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-invitees' ? ' active' : '' ?>" data-pane="egm-invitees">دعوت‌شدگان</button>
      <?php endif; ?>
      <?php if ($egmCanInviteCardPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-invite-card' ? ' active' : '' ?>" data-pane="egm-invite-card">Invite Card</button>
      <?php endif; ?>
      <?php if ($egmCanManageTasksPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-manage-tasks' ? ' active' : '' ?>" data-pane="egm-manage-tasks">بازه‌ها</button>
      <?php endif; ?>
      <?php if ($egmCanMonitoringPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-monitoring' ? ' active' : '' ?>" data-pane="egm-monitoring">مانیتورینگ</button>
      <?php endif; ?>
      <?php if ($egmCanLogsPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-logs' ? ' active' : '' ?>" data-pane="egm-logs">Logs</button>
      <?php endif; ?>
      <?php if ($egmCanTaskSubtabs): ?>
        <div data-egm-task-subtab-nav></div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="sub-content">
    <?php if ($egmCanControlPanel): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-main' ? ' active' : '' ?>" data-pane="egm-main">
      <?php $egmControlInitialSection = $egmCanMainPane ? 'general' : ($egmCanTaskAccessPane ? 'admin-access' : ($egmCanExportPane ? 'export' : ($egmCanLinkerPane ? 'linker' : 'event-style'))); ?>
      <div class="egm-task-top-nav" role="tablist" aria-label="تب‌های کنترل پنل">
        <?php if ($egmCanMainPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'general' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'general' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="general">عمومی</button>
          <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-control-panel-trigger="landing">Landing</button>
        <?php endif; ?>
        <?php if ($egmCanMainPane || $egmCanTaskAccessPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'admin-access' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'admin-access' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="admin-access">دسترسی ادمین</button>
        <?php endif; ?>
        <?php if ($egmCanExportPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'export' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'export' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="export">خروجی</button>
        <?php endif; ?>
        <?php if ($egmCanLinkerPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'linker' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'linker' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="linker">Linker</button>
        <?php endif; ?>
        <?php if ($egmCanEventStylePane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'event-style' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'event-style' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="event-style">استایل رویداد</button>
        <?php endif; ?>
      </div>

      <?php if ($egmCanMainPane): ?>
      <section data-egm-control-panel-section="general"<?= $egmControlInitialSection === 'general' ? '' : ' hidden' ?>>
      <div class="card">
  <div class="section-header">
    <h3>وضعیت</h3>
  </div>
  <div class="field" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
    <a class="btn primary standard-primary-button" href="mini%20apps/Event%20Guest%20Manager/EGMM.php" target="_blank" rel="noopener">باز کردن صفحه رویداد</a>
  </div>
</div>

<div class="card" id="egm-texts-card">
  <div class="section-header">
    <h3>کنترل پنل</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div id="egm-status-text" class="egm-status egm-status--inactive">غیرفعال</div>
    <div class="egm-switch-grid">
      <label class="switch egm-switch">
        <span class="switch-label">فعال</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-active-toggle" aria-label="فعال" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">زمان‌بندی</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-duration-toggle" aria-label="زمان‌بندی" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">حالت تعمیرات</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-maintenance-toggle" aria-label="حالت تعمیرات" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">قفل ماموریت‌ها و کارت‌ها</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-event-access-lock-toggle" aria-label="قفل ماموریت‌ها و کارت‌ها" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
    </div>
    <div class="form grid two-column-fields egm-datetime-grid">
      <div class="egm-datetime-title egm-datetime-title--start">شروع</div>
      <label class="field standard-width egm-datetime-start">
        <span>تاریخ</span>
        <input
          type="date"
          id="egm-duration-start"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width egm-datetime-start-time">
        <span>ساعت</span>
        <select id="egm-duration-start-time">
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
      <div class="egm-datetime-title egm-datetime-title--end">پایان</div>
      <label class="field standard-width egm-datetime-end">
        <span>تاریخ</span>
        <input
          type="date"
          id="egm-duration-end"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width egm-datetime-end-time">
        <span>ساعت</span>
        <select id="egm-duration-end-time">
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
      <div class="egm-datetime-empty" aria-hidden="true"></div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="egm-settings-save">ذخیره</button>
    </div>
  </div>
</div>

      </section>
      <?php endif; ?>

      <?php if ($egmCanMainPane): ?>
      <section data-egm-control-panel-section="landing" hidden>
      <div class="card egm-landing-editor-card">
        <div class="section-header">
          <h3>Landing</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>Title</span>
            <input type="text" id="egm-landing-title" autocomplete="off" />
          </label>
          <label class="field full">
            <span>Subtitle</span>
            <textarea id="egm-landing-subtitle" rows="3"></textarea>
          </label>
          <div class="egm-landing-sections-head">
            <div>
              <strong>Text sections</strong>
              <p class="muted small">Use the editor buttons or Ctrl+B, Ctrl+L, and Ctrl+Alt+1 inside a section text area.</p>
            </div>
            <button type="button" class="btn ghost" id="egm-landing-add-section">Add section</button>
          </div>
          <div id="egm-landing-sections" class="egm-landing-sections"></div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="egm-landing-save">Save landing</button>
          </div>
          <p class="muted small" id="egm-landing-status" aria-live="polite"></p>
        </div>
      </div>
      </section>
      <?php endif; ?>

      <?php if ($egmCanMainPane || $egmCanTaskAccessPane): ?>
      <section data-egm-control-panel-section="admin-access"<?= $egmControlInitialSection === 'admin-access' ? '' : ' hidden' ?>>
      <?php if ($egmCanMainPane): ?>
<div class="card" id="egm-assign-admin-card">
  <div class="section-header">
    <h3>تعیین ادمین</h3>
  </div>
  <div class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>شناسه کاری</span>
      <input id="egm-admin-workid" type="text" autocomplete="off" placeholder="شناسه کاری را وارد کنید" />
    </label>
    <div class="field full egm-form-action">
      <button type="button" class="btn primary standard-primary-button" id="egm-admin-search-btn">جستجوی کاربر</button>
    </div>
    <div id="egm-admin-search-result" class="egm-admin-search-result hidden" aria-live="polite"></div>
    <p id="egm-admin-status" class="muted small" aria-live="polite"></p>
    <div class="table-wrapper">
      <table class="egm-admin-table">
        <thead>
          <tr>
            <th>نام</th>
            <th>شناسه کاری</th>
            <th>شماره تلفن</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody id="egm-admin-list">
          <tr>
            <td colspan="4" class="muted">هنوز ادمینی تعیین نشده است.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
      <?php endif; ?>

      <?php if ($egmCanTaskAccessPane): ?>
      <div class="card">
        <div class="section-header">
          <h3>دسترسی بازه‌ها</h3>
        </div>
        <div class="form" style="gap:12px;">
          <p class="muted small">فهرست کاربران دارای دسترسی «باشگاه تعاملی». برای هر کاربر می‌توانید «دسترسی بازه‌ها» و «دسترسی‌های خاص» را تنظیم کنید.</p>
          <div class="table-wrapper">
            <table class="tct-list-table egm-task-access-users-table">
              <thead>
                <tr>
                  <th>ردیف</th>
                  <th>نام کاربر</th>
                  <th>شناسه</th>
                  <th>نام کاربری</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody id="egm-task-access-users-body">
                <tr>
                  <td colspan="5" class="muted">در حال بارگذاری...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div id="egm-task-access-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="egm-task-access-modal-title">
        <div class="modal-card default-modal-card">
          <div class="modal-card-header">
            <h3 id="egm-task-access-modal-title">دسترسی‌ها</h3>
            <button type="button" class="icon-btn" data-egm-task-access-close aria-label="بستن">×</button>
          </div>
          <p class="hint">دسترسی هر کاربر به تب‌ها و زیربخش‌های هر بازه از این بخش مدیریت می‌شود.</p>
          <label class="egm-task-access-manage-row">
            <input type="checkbox" id="egm-task-access-manage-tasks" />
            <span>دسترسی به تب بازه‌ها</span>
          </label>
          <p class="muted small" id="egm-task-access-status" aria-live="polite"></p>
          <div id="egm-task-access-tree" class="egm-task-access-tree"></div>
          <div class="modal-actions">
            <button type="button" class="btn" data-egm-task-access-close>بستن</button>
            <button type="button" class="btn primary" id="egm-task-access-save">ذخیره دسترسی‌ها</button>
          </div>
        </div>
      </div>

      <div id="egm-task-special-access-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="egm-task-special-access-modal-title">
        <div class="modal-card default-modal-card">
          <div class="modal-card-header">
            <h3 id="egm-task-special-access-modal-title">دسترسی‌های خاص</h3>
            <button type="button" class="icon-btn" data-egm-task-special-close aria-label="بستن">×</button>
          </div>
          <p class="hint">این دسترسی‌ها مربوط به تب «دعوت‌شدگان» هستند.</p>
          <div class="egm-task-special-access-grid">
            <label class="egm-task-access-manage-row">
              <input type="checkbox" id="egm-special-invitees-manage" />
              <span>مدیریت دعوت‌شدگان</span>
            </label>
            <label class="egm-task-access-manage-row">
              <input type="checkbox" id="egm-special-invitees-reset" />
              <span>بازنشانی دعوت‌شده</span>
            </label>
            <label class="egm-task-access-manage-row">
              <input type="checkbox" id="egm-special-invitees-reveal" />
              <span>نمایش رمز عبور</span>
            </label>
            <label class="egm-task-access-manage-row">
              <input type="checkbox" id="egm-special-invitees-edit" />
              <span>ویرایش دعوت‌شده</span>
            </label>
          </div>
          <p class="muted small" id="egm-task-special-access-status" aria-live="polite"></p>
          <div class="modal-actions">
            <button type="button" class="btn" data-egm-task-special-close>بستن</button>
            <button type="button" class="btn primary" id="egm-task-special-access-save">ذخیره دسترسی‌های خاص</button>
          </div>
        </div>
      </div>
      <?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if ($egmCanExportPane): ?>
      <section data-egm-control-panel-section="export"<?= $egmControlInitialSection === 'export' ? '' : ' hidden' ?>>
      <div class="card">
        <div class="section-header">
          <h3>خروجی گرفتن</h3>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/Event%20Guest%20Manager/export_login_data.php"
            target="_blank"
            rel="noopener"
          >خروجی اطلاعات ورود</a>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/Event%20Guest%20Manager/export_participants.php"
            target="_blank"
            rel="noopener"
          >خروجی شرکت‌کنندگان</a>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/Event%20Guest%20Manager/valuable_prizes_export.php"
            target="_blank"
            rel="noopener"
          >خروجی برندگان جوایز ارزشمند</a>
        </div>
        <?php
          $egmPrizeLevelsPath = __DIR__ . '/EGM Prize Levels.json';
          $egmPrizeLevels = [];
          if (egmDbIsFile($egmPrizeLevelsPath)) {
            $egmPrizeLevelsDecoded = json_decode((string)egmDbFileGetContents($egmPrizeLevelsPath), true);
            if (is_array($egmPrizeLevelsDecoded)) $egmPrizeLevels = $egmPrizeLevelsDecoded;
          }
          foreach ($egmPrizeLevels as $egmPrizeLevel):
            if (!is_array($egmPrizeLevel) || strtolower(trim((string)($egmPrizeLevel['type'] ?? ''))) !== 'pot') continue;
            $egmPotLevelId = trim((string)($egmPrizeLevel['id'] ?? ''));
            if ($egmPotLevelId === '') continue;
            $egmPotLevelName = trim((string)($egmPrizeLevel['name'] ?? 'Pot'));
            $egmPotExportBase = 'mini%20apps/Event%20Guest%20Manager/pot_export.php?level_id=' . rawurlencode($egmPotLevelId);
        ?>
        <div class="field">
          <span><?= htmlspecialchars($egmPotLevelName, ENT_QUOTES, 'UTF-8') ?></span>
          <div class="egm-action-bar">
            <a class="btn primary standard-primary-button" href="<?= htmlspecialchars($egmPotExportBase . '&type=winners', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">خروجی برندگان</a>
            <a class="btn ghost" href="<?= htmlspecialchars($egmPotExportBase . '&type=reached_non_winners', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">خروجی رسیده‌ها بدون برد</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      </section>
      <?php endif; ?>

      <?php if ($egmCanLinkerPane): ?>
      <section data-egm-control-panel-section="linker"<?= $egmControlInitialSection === 'linker' ? '' : ' hidden' ?>>
      <div class="card" id="egm-campaign-linker-card">
        <div class="section-header">
          <h3>Linker</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>Campaign path</span>
            <div class="egm-linker-input-row">
              <span class="egm-linker-prefix">/campaigns/</span>
              <input id="egm-linker-path" type="text" maxlength="180" autocomplete="off" dir="ltr" placeholder="dastavard" />
            </div>
          </label>
          <p id="egm-linker-preview" class="hint muted small" aria-live="polite"></p>
          <div class="egm-action-bar">
            <button type="button" class="btn ghost" id="egm-linker-check">Check availability</button>
            <button type="button" class="btn primary standard-primary-button" id="egm-linker-create" disabled>Create redirect</button>
          </div>
          <p id="egm-linker-status" class="hint muted small" aria-live="polite"></p>
          <div class="table-wrapper">
            <table class="tct-list-table egm-linker-table">
              <thead>
                <tr>
                  <th>Campaign URL</th>
                  <th>Type</th>
                  <th>Target</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="egm-linker-current-body">
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

      <?php if ($egmCanEventStylePane): ?>
      <section data-egm-control-panel-section="event-style"<?= $egmControlInitialSection === 'event-style' ? '' : ' hidden' ?>>
      <div class="card">
        <div class="section-header">
          <h3>نام رویداد</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>نام رویداد</span>
            <input id="egm-event-name" type="text" maxlength="120" autocomplete="off" />
          </label>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="egm-event-name-save">ذخیره</button>
          </div>
          <p id="egm-event-name-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      <div class="card" id="egm-mission-link-card">
        <div class="section-header">
          <h3>لینک باشگاه</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>کد لینک</span>
            <input id="egm-mission-link-code" type="text" maxlength="80" autocomplete="off" dir="ltr" placeholder="club-code" />
          </label>
          <p id="egm-mission-link-preview" class="hint muted small" aria-live="polite"></p>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="egm-mission-link-save">ذخیره لینک</button>
          </div>
          <p id="egm-mission-link-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      <div class="card">
        <div class="section-header">
          <h3>لوگوی رویداد</h3>
        </div>
        <div class="form">
          <div class="photo-uploader egm-event-logo-uploader">
            <div class="photo-preview" aria-live="polite">
              <img id="egm-event-logo-image" class="hidden" alt="پیش‌نمایش لوگوی رویداد" />
              <div id="egm-event-logo-placeholder" class="photo-placeholder">تصویری انتخاب نشده است</div>
            </div>
            <div class="photo-actions">
              <button type="button" class="btn" id="egm-event-logo-pick">انتخاب تصویر</button>
              <button type="button" class="btn ghost" id="egm-event-logo-clear" disabled>حذف تصویر</button>
            </div>
            <p id="egm-event-style-logo-status" class="hint muted small" aria-live="polite"></p>
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
              <input id="egm-event-color-secondary" class="appearance-hex-field" type="text" maxlength="7" value="#2F8FFF" />
              <button type="button" class="appearance-preview" id="egm-event-preview-secondary" aria-label="انتخاب رنگ دوم"></button>
              <button type="button" class="btn ghost" id="egm-event-picker-secondary">انتخاب</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">رنگ برجسته</span>
            <div class="appearance-input-group">
              <input id="egm-event-color-highlight" class="appearance-hex-field" type="text" maxlength="7" value="#20C997" />
              <button type="button" class="appearance-preview" id="egm-event-preview-highlight" aria-label="انتخاب رنگ برجسته"></button>
              <button type="button" class="btn ghost" id="egm-event-picker-highlight">انتخاب</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">رنگ تاکیدی ملایم</span>
            <div class="appearance-input-group">
              <input id="egm-event-color-accent-soft" class="appearance-hex-field" type="text" maxlength="7" value="#FFB347" />
              <button type="button" class="appearance-preview" id="egm-event-preview-accent-soft" aria-label="انتخاب رنگ تاکیدی ملایم"></button>
              <button type="button" class="btn ghost" id="egm-event-picker-accent-soft">انتخاب</button>
            </div>
          </div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="egm-event-style-save">ذخیره</button>
          </div>
          <p id="egm-event-style-save-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
      </section>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($egmCanMainPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-rewards-config' ? ' active' : '' ?>" data-pane="egm-rewards-config">
      <div class="egm-task-top-nav" role="tablist" aria-label="تب‌های جوایز">
        <button type="button" class="egm-task-top-item active" aria-selected="true" data-egm-reward-config-trigger="guide">راهنمای دریافت جایزه</button>
        <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-reward-config-trigger="advanced">Advanced Prize Setting</button>
        <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-reward-config-trigger="levels">سطح بندی جوایز</button>
        <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-reward-config-trigger="storage">انبار جوایز</button>
      </div>

      <section data-egm-reward-config-section="guide">
        <div class="card">
          <div class="section-header"><h3>کارت راهنما</h3></div>
          <div class="form" style="gap:12px;">
            <label class="field standard-width">
              <span>عنوان</span>
              <input type="text" id="egm-reward-guide-title" value="راهنمای دریافت جایزه" />
            </label>
            <label class="field full">
              <span>متن راهنما</span>
              <textarea id="egm-reward-guide-text" rows="8"></textarea>
            </label>
            <div class="field full">
              <button type="button" class="btn primary standard-primary-button" id="egm-reward-guide-save">ذخیره</button>
            </div>
            <p class="muted small" id="egm-reward-guide-status" aria-live="polite"></p>
          </div>
        </div>
      </section>

      <section data-egm-reward-config-section="advanced" hidden>
        <div class="card">
          <div class="section-header">
            <h3>Advanced Prize Setting</h3>
          </div>
          <div class="form" style="gap:12px;">
            <div class="egm-switch-grid egm-reward-advanced-switch-grid">
              <label class="switch egm-switch">
                <span class="switch-label">Non Value Prize Describe</span>
                <span class="switch-toggle">
                  <input id="egm-reward-non-value-describe-toggle" type="checkbox" />
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </span>
              </label>
              <label class="switch egm-switch">
                <span class="switch-label">Show Prize</span>
                <span class="switch-toggle">
                  <input id="egm-reward-show-prize-toggle" type="checkbox" checked />
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </span>
              </label>
            </div>
            <label class="field full">
              <span>Text shown when prize value is hidden</span>
              <input id="egm-reward-hidden-prize-text" type="text" autocomplete="off" />
            </label>
            <div class="field full">
              <button type="button" class="btn primary standard-primary-button" id="egm-reward-advanced-save">Save</button>
            </div>
            <p class="muted small" id="egm-reward-advanced-status" aria-live="polite"></p>
          </div>
        </div>
      </section>

      <section data-egm-reward-config-section="levels" hidden>
        <div class="card">
          <div class="section-header">
            <h3>سطح بندی جوایز</h3>
          </div>
          <form id="egm-prize-level-form" class="form" style="gap:12px;">
            <label class="field standard-width">
              <span>نام سطح</span>
              <input id="egm-prize-level-name" name="name" type="text" autocomplete="off" required />
            </label>
            <label class="field standard-width">
              <span>نوع محاسبه</span>
              <select id="egm-prize-level-type" name="type" required>
                <option value="value_sum">مجموع ارزش جوایز</option>
                <option value="out_of_value">خارج از ارزش جایزه</option>
                <option value="pot">Pot</option>
              </select>
            </label>
            <label class="field standard-width">
              <span>امتیاز</span>
              <input id="egm-prize-level-score" name="score" type="number" min="1" step="1" inputmode="numeric" required />
            </label>
            <div class="field full">
              <button type="submit" class="btn primary standard-primary-button">افزودن سطح</button>
            </div>
            <p id="egm-prize-level-status" class="muted small" aria-live="polite"></p>
          </form>
          <div class="table-wrapper">
            <table class="egm-prize-level-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>نام سطح</th>
                  <th>نوع محاسبه</th>
                  <th>امتیاز</th>
                  <th>عملیات</th>
                </tr>
              </thead>
              <tbody id="egm-prize-level-list">
                <tr><td colspan="5" class="muted">هنوز سطحی اضافه نشده است.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section data-egm-reward-config-section="storage" hidden>
        <div id="egm-prize-section">
          <div class="card">
            <div class="section-header">
              <h3>افزودن جایزه</h3>
            </div>
            <form id="egm-prize-form" class="form">
              <div class="form grid egm-prize-grid">
                <label class="field standard-width">
                  <span>نام جایزه</span>
                  <input id="egm-prize-name" name="name" type="text" autocomplete="off" required />
                </label>
                <label class="field egm-standard-third">
                  <span>تعداد</span>
                  <input
                    id="egm-prize-quantity"
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
                    id="egm-prize-value"
                    name="value"
                    type="text"
                    inputmode="decimal"
                    placeholder="0"
                    autocomplete="off"
                    required
                  />
                </label>
              </div>
              <div class="field full egm-form-action">
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
                <tbody id="egm-prize-list"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="section-header">
              <h3>آیتم‌های نمایشی</h3>
            </div>
            <form id="egm-fake-form" class="form">
              <label class="field standard-width">
                <span>نام آیتم نمایشی</span>
                <input id="egm-fake-name" name="fake-name" type="text" autocomplete="off" required />
              </label>
              <div class="field full">
                <button type="submit" class="btn primary standard-primary-button">افزودن</button>
              </div>
            </form>
            <div class="table-wrapper egm-fake-table">
              <table class="egm-prize-table">
                <thead>
                  <tr>
                    <th>نام آیتم</th>
                    <th>عملیات</th>
                  </tr>
                </thead>
                <tbody id="egm-fake-list"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>
    <?php endif; ?>
    <?php if ($egmCanInviteesPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-invitees' ? ' active' : '' ?>" data-pane="egm-invitees">
      <?php include __DIR__ . '/invitees.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($egmCanInviteCardPane): ?>
      <?php renderEgmInviteCardPane('mini%20apps/Event%20Guest%20Manager/invite_card_store.php', $egmInitialPane === 'egm-invite-card'); ?>
    <?php endif; ?>
    <?php if ($egmCanManageTasksPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-manage-tasks' ? ' active' : '' ?>" data-pane="egm-manage-tasks">
      <?php include __DIR__ . '/EGMT.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($egmCanMonitoringPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-monitoring' ? ' active' : '' ?>" data-pane="egm-monitoring">
      <?php include __DIR__ . '/EGMMonitoring.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($egmCanLogsPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-logs' ? ' active' : '' ?>" data-pane="egm-logs" data-egm-logs-pane="1">
      <div class="card egm-logs-card">
        <div class="section-header">
          <h3>Logs</h3>
        </div>
        <div class="egm-logs-toolbar">
          <label class="field egm-logs-search-field">
            <span>Search username</span>
            <input id="egm-logs-search" type="search" placeholder="Type username, user id, action..." autocomplete="off" />
          </label>
          <label class="field egm-logs-day-field">
            <span>Day</span>
            <select id="egm-logs-day"></select>
          </label>
          <button type="button" class="btn ghost" id="egm-logs-refresh">Refresh</button>
        </div>
        <p class="muted egm-logs-status" id="egm-logs-status" aria-live="polite">Open this tab to load logs.</p>
        <div class="table-wrapper egm-logs-table-wrap">
          <table class="egm-logs-table">
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
            <tbody id="egm-logs-body">
              <tr><td colspan="8" class="muted">Logs are loaded separately when this tab opens.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($egmCanTaskSubtabs): ?>
    <div data-egm-task-subtab-panes></div>
    <?php endif; ?>
    <?php if (!$egmHasAnyPane): ?>
      <div class="card">
        <div class="section-header">
          <h3>Access Restricted</h3>
        </div>
        <p class="muted">You do not have access to any Event Guest Manager subtab.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<?php if ($egmCanInviteesPane || $egmCanManageTasksPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/vendor/xlsx/xlsx.full.min.js" defer></script>
<?php endif; ?>
<script src="mini%20apps/Event%20Guest%20Manager/egm-panel-local.js?v=<?= htmlspecialchars($egmPanelLocalJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if ($egmCanInviteCardPane): ?>
<script src="assets/egm-invite-card.js?v=<?= htmlspecialchars($egmInviteCardJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanMainPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGM%20Prizes.js?v=<?= htmlspecialchars($egmPrizesJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanControlPanel): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMSetting.js?v=<?= htmlspecialchars($egmSettingJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanEventStylePane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMEventStyle.js?v=<?= htmlspecialchars($egmEventStyleJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanTaskAccessPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMTaskAccess.js?v=<?= htmlspecialchars($egmTaskAccessJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanMonitoringPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMMonitoring.js?v=<?= htmlspecialchars($egmMonitoringJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
