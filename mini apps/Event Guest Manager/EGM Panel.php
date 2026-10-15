<?php
declare(strict_types=1);


require_once __DIR__ . '/egm-database-runtime.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../api/lib/egm-invite-card-pane.php';
require_once __DIR__ . '/../../api/lib/egm-groups-pane.php';
$egmPanelUser = requireTabPermissionFromSession('event-guest-manager', false);
$egmAllowedChildTabs = resolveAllowedPanelChildTabsForUser($egmPanelUser, 'event-guest-manager');
$egmAllowedChildSet = array_fill_keys($egmAllowedChildTabs, true);
$egmCanScanPane = userHasPermissionId($egmPanelUser, 'event-guest-manager:scan');
$egmCanExportPane = userHasPermissionId($egmPanelUser, 'event-guest-manager:export');
$egmCanMainPane = isset($egmAllowedChildSet['event-guest-manager:main']);
$egmCanInviteCardPane = isset($egmAllowedChildSet['event-guest-manager:invite-card']);
$egmCanPrintCardPane = isset($egmAllowedChildSet['event-guest-manager:print-card']);
$egmCanTicketPane = isset($egmAllowedChildSet['event-guest-manager:custom-number-ticket']);
$egmCanGamesPane = isset($egmAllowedChildSet['event-guest-manager:games']);
$egmCanGroupsPane = isset($egmAllowedChildSet['event-guest-manager:groups']);
$egmCanPrizesPane = isset($egmAllowedChildSet['event-guest-manager:prizes']);
$egmCanGuestReportsPane = isset($egmAllowedChildSet['event-guest-manager:guest-reports']);
$egmCanFacilitatorsPane = isset($egmAllowedChildSet['event-guest-manager:facilitators']);
$egmCanInviteesPane = isset($egmAllowedChildSet['event-guest-manager:invitees']);
$egmCanManageTasksPane = isset($egmAllowedChildSet['event-guest-manager:manage-tasks']);
$egmCanTaskAccessPane = isset($egmAllowedChildSet['event-guest-manager:task-access']);
$egmCanMonitoringPane = isset($egmAllowedChildSet['event-guest-manager:monitoring']);
$egmCanLinkerPane = isset($egmAllowedChildSet['event-guest-manager:linker']);
$egmCanLogsPane = isset($egmAllowedChildSet['event-guest-manager:logs']);
$egmCanControlPanel = $egmCanMainPane || $egmCanTaskAccessPane || $egmCanLinkerPane || $egmCanFacilitatorsPane;
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
$egmCanTaskSubtabs = $egmCanManageTasksPane || $egmHasTaskSubtabAccess || $egmCanExportPane;
$egmHasAnyPane = $egmCanScanPane || $egmCanExportPane || $egmCanGamesPane || $egmCanGroupsPane || $egmCanPrizesPane || $egmCanGuestReportsPane || $egmCanFacilitatorsPane || $egmCanTicketPane || $egmCanMainPane
  || $egmCanInviteesPane
  || $egmCanInviteCardPane
  || $egmCanPrintCardPane
  || $egmCanManageTasksPane
  || $egmCanTaskSubtabs
  || $egmCanTaskAccessPane
  || $egmCanMonitoringPane
  || $egmCanLinkerPane
  || $egmCanLogsPane;
$egmInitialPane = '';
foreach ([
  'egm-main' => $egmCanControlPanel,
  'egm-rewards-config' => $egmCanPrizesPane,
  'egm-groups' => $egmCanGroupsPane,
  'egm-games' => $egmCanGamesPane,
  'egm-invitees' => $egmCanInviteesPane,
  'egm-invite-card' => $egmCanInviteCardPane,
  'egm-print-card' => $egmCanPrintCardPane,
  'egm-custom-number-ticket' => $egmCanTicketPane,
  'egm-manage-tasks' => $egmCanManageTasksPane,
  'egm-monitoring' => $egmCanMonitoringPane,
  'egm-logs' => $egmCanLogsPane,
  'egm-guest-reports' => $egmCanGuestReportsPane,
  'egm-scan' => $egmCanScanPane,
  'egm-export' => $egmCanExportPane,
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
  $egmPanelInstanceName = $egmPanelInstanceCode === '00000' ? 'رویداد آزمایشی' : 'مدیریت مهمانان';
}
$egmPanelScriptRoot = realpath(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) ?: dirname(__DIR__, 2);
$egmPanelRelativeDirectory = str_replace('\\', '/', substr(__DIR__, strlen($egmPanelScriptRoot) + 1));
if (!str_starts_with($egmPanelRelativeDirectory, 'mini apps/')) $egmPanelRelativeDirectory = 'mini apps/Event Guest Manager';
$egmRefMonitorHref = implode('/', array_map('rawurlencode', explode('/', $egmPanelRelativeDirectory))) . '/RefMonitor.php';
$egmScanHref = implode('/', array_map('rawurlencode', explode('/', $egmPanelRelativeDirectory))) . '/check-in.php';
$egmPanelStoredSettings = is_array($egmPanelRuntimeContext) && ($egmPanelRuntimeContext['pdo'] ?? null) instanceof PDO
  ? egmInstanceReadData($egmPanelRuntimeContext['pdo'], $egmPanelInstanceCode, 'settings', [])
  : [];
$egmPanelTicketSettings = is_array($egmPanelStoredSettings['customNumberTicketSettings'] ?? null) ? $egmPanelStoredSettings['customNumberTicketSettings'] : [];
$egmPanelTicketDefinitions = is_array($egmPanelTicketSettings['tickets'] ?? null) ? $egmPanelTicketSettings['tickets'] : [];
if ($egmPanelTicketDefinitions === []) $egmPanelTicketDefinitions = [['id' => 'default', 'title' => 'بلیت شماره‌دار']];
if ($egmInitialPane === 'egm-custom-number-ticket') {
  $egmInitialPane .= '-' . (preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)($egmPanelTicketDefinitions[0]['id'] ?? 'default'))) ?: 'default');
}

$egmPanelCssVer = (string)(@egmDbFilemtime(__DIR__ . '/egm-panel.css') ?: time());
$egmPanelLocalJsVer = (string)(@egmDbFilemtime(__DIR__ . '/egm-panel-local.js') ?: time());
$egmPrizesJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGM Prizes.js') ?: time());
$egmSettingJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMSetting.js') ?: time());
$egmMonitoringJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMMonitoring.js') ?: time());
$egmTaskAccessJsVer = (string)(@egmDbFilemtime(__DIR__ . '/EGMTaskAccess.js') ?: time());
$egmInviteCardCssVer = (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-invite-card.css') ?: time());
$egmInviteCardJsHash = @hash_file('sha256', __DIR__ . '/../../assets/egm-invite-card.js');
$egmInviteCardJsVer = is_string($egmInviteCardJsHash) && $egmInviteCardJsHash !== ''
  ? substr($egmInviteCardJsHash, 0, 16)
  : (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-invite-card.js') ?: time());
$egmGroupsCssVer = (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-groups.css') ?: time());
$egmGroupsJsVer = (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-groups.js') ?: time());
$egmGamesJsHash = @hash_file('sha256', __DIR__ . '/../../assets/egm-games.js');
$egmGamesJsVer = is_string($egmGamesJsHash) && $egmGamesJsHash !== ''
  ? substr($egmGamesJsHash, 0, 16)
  : (string)(@egmDbFilemtime(__DIR__ . '/../../assets/egm-games.js') ?: time());
?>

<link rel="stylesheet" href="mini%20apps/Event%20Guest%20Manager/egm-panel.css?v=<?= htmlspecialchars($egmPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<?php if ($egmCanGroupsPane): ?><link rel="stylesheet" href="assets/egm-groups.css?v=<?= htmlspecialchars($egmGroupsCssVer, ENT_QUOTES, 'UTF-8') ?>" /><?php endif; ?>
<?php if ($egmCanInviteCardPane || $egmCanPrintCardPane || $egmCanTicketPane || $egmCanManageTasksPane): ?>
<link rel="stylesheet" href="assets/egm-invite-card.css?v=<?= htmlspecialchars($egmInviteCardCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<?php endif; ?>
<div class="egm-shell" data-egm-games-endpoint="mini%20apps/Event%20Guest%20Manager/games.php" data-egm-csrf="<?= htmlspecialchars($egmPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>" data-egm-code="<?= htmlspecialchars($egmPanelInstanceCode, ENT_QUOTES, 'UTF-8') ?>" data-egm-can-manage-games="<?= ($egmCanManageTasksPane || $egmCanGamesPane) ? '1' : '0' ?>" data-egm-can-end-games="<?= $egmCanGamesPane ? '1' : '0' ?>">
<div class="sub-layout" data-egm-sub-layout>
  <aside class="sub-sidebar">
    <div class="sub-header"><?= htmlspecialchars($egmPanelInstanceName, ENT_QUOTES, 'UTF-8') ?> <span class="muted" dir="ltr">(<?= htmlspecialchars($egmPanelInstanceCode, ENT_QUOTES, 'UTF-8') ?>)</span></div>
    <div class="sub-nav">
      <?php if ($egmCanScanPane || $egmCanControlPanel || $egmCanPrizesPane || $egmCanGroupsPane || $egmCanGamesPane || $egmCanInviteesPane): ?>
      <div class="egm-nav-group" role="group" aria-label="مدیریت رویداد">
        <div class="egm-nav-divider">مدیریت رویداد</div>
      <?php if ($egmCanScanPane): ?><button type="button" class="sub-item" data-pane="egm-scan">اسکن و کنترل مهمانان</button><?php endif; ?>
      <?php if ($egmCanControlPanel): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-main' ? ' active' : '' ?>" data-pane="egm-main">کنترل پنل</button>
      <?php endif; ?>
      <?php if ($egmCanPrizesPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-rewards-config' ? ' active' : '' ?>" data-pane="egm-rewards-config">جوایز و قرعه‌کشی</button>
      <?php endif; ?>
      <?php if ($egmCanGroupsPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-groups' ? ' active' : '' ?>" data-pane="egm-groups">گروه‌ها</button>
      <?php endif; ?>
      <?php if ($egmCanGamesPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-games' ? ' active' : '' ?>" data-pane="egm-games">بازی‌ها</button>
      <?php endif; ?>
      <?php if ($egmCanInviteesPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-invitees' ? ' active' : '' ?>" data-pane="egm-invitees">دعوت‌شدگان</button>
      <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($egmCanInviteCardPane || $egmCanPrintCardPane || $egmCanTicketPane): ?>
      <div class="egm-nav-group" role="group" aria-label="کارت و چاپ">
        <div class="egm-nav-divider">کارت و چاپ</div>
      <?php if ($egmCanInviteCardPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-invite-card' ? ' active' : '' ?>" data-pane="egm-invite-card">کارت دعوت</button>
      <?php endif; ?>
      <?php if ($egmCanPrintCardPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-print-card' ? ' active' : '' ?>" data-pane="egm-print-card">کارت چاپی</button>
      <?php endif; ?>
      <?php if ($egmCanTicketPane): ?>
        <?php foreach ($egmPanelTicketDefinitions as $egmTicketIndex => $egmTicket): $egmTicketId = preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)($egmTicket['id'] ?? 'default'))) ?: 'default'; $egmTicketPane = 'egm-custom-number-ticket-' . $egmTicketId; ?>
          <button type="button" class="sub-item<?= $egmInitialPane === $egmTicketPane ? ' active' : '' ?>" data-pane="<?= htmlspecialchars($egmTicketPane, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)(($egmTicket['title'] ?? '') === 'Custom Number Ticket' ? 'بلیت شماره‌دار' : ($egmTicket['title'] ?? ('بلیت ' . ($egmTicketIndex + 1)))), ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($egmCanManageTasksPane || $egmCanTaskSubtabs): ?>
      <div class="egm-nav-group" role="group" aria-label="بازه‌ها">
        <div class="egm-nav-divider">بازه‌ها</div>
      <?php if ($egmCanManageTasksPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-manage-tasks' ? ' active' : '' ?>" data-pane="egm-manage-tasks">بازه‌ها</button>
      <?php endif; ?>
      <?php if ($egmCanTaskSubtabs): ?>
        <div data-egm-task-subtab-nav></div>
      <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($egmCanGuestReportsPane || $egmCanMonitoringPane || $egmCanLogsPane): ?>
      <div class="egm-nav-group" role="group" aria-label="گزارش‌ها">
        <div class="egm-nav-divider">گزارش‌ها</div>
        <?php if ($egmCanGuestReportsPane): ?><button type="button" class="sub-item" data-pane="egm-guest-reports">گزارش‌های مهمانان</button><?php endif; ?>
      <?php if ($egmCanMonitoringPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-monitoring' ? ' active' : '' ?>" data-pane="egm-monitoring">گزارش رویداد</button>
      <?php endif; ?>
      <?php if ($egmCanLogsPane): ?>
        <button type="button" class="sub-item<?= $egmInitialPane === 'egm-logs' ? ' active' : '' ?>" data-pane="egm-logs">گزارش‌ها</button>
      <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="sub-content">
    <?php if ($egmCanScanPane): ?><div class="sub-pane<?= $egmInitialPane === 'egm-scan' ? ' active' : '' ?>" data-pane="egm-scan"><div class="card"><h3>اسکن و کنترل مهمانان</h3><a class="btn primary" href="<?= htmlspecialchars($egmScanHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">باز کردن صفحه اسکن</a></div></div><?php endif; ?>
    <?php if ($egmCanExportPane): ?><div class="sub-pane<?= $egmInitialPane === 'egm-export' ? ' active' : '' ?>" data-pane="egm-export"><div class="card"><h3>خروجی بازه‌ها</h3><p>بازه را از فهرست انتخاب کنید.</p></div></div><?php endif; ?>
    <?php if ($egmCanGuestReportsPane): ?>
    <div class="sub-pane" data-pane="egm-guest-reports" data-guest-reports data-endpoint="mini%20apps/Event%20Guest%20Manager/guest_reports.php">
      <div class="card"><div class="section-header"><h3>گزارش‌های مهمانان</h3><button type="button" class="btn ghost" data-report-refresh>تازه‌سازی</button></div>
      <div class="form"><label class="field"><span>بازه</span><select data-report-period><option value="">همهٔ بازه‌ها</option></select></label><label class="field"><span>وضعیت</span><select data-report-state><option value="pending">نیاز به بررسی</option><option value="resolved">حل‌شده</option><option value="all">همه</option></select></label><label class="field"><span>جست‌وجو</span><input type="search" data-report-search placeholder="نام یا شناسهٔ مهمان"></label></div>
      <p data-report-status role="status"></p><div data-report-list></div><div class="egm-period-actions"><button type="button" class="btn ghost" data-report-prev>قبلی</button><span data-report-page></span><button type="button" class="btn ghost" data-report-next>بعدی</button></div></div>
    </div>
    <?php endif; ?>
    <?php if ($egmCanControlPanel): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-main' ? ' active' : '' ?>" data-pane="egm-main">
      <?php $egmControlInitialSection = $egmCanMainPane ? 'general' : ($egmCanTaskAccessPane ? 'admin-access' : ($egmCanFacilitatorsPane ? 'facilitators' : 'linker')); ?>
      <div class="egm-task-top-nav egm-control-nav" role="tablist" aria-label="تب‌های کنترل پنل">
        <?php if ($egmCanMainPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'general' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'general' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="general">عمومی</button>
          <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-control-panel-trigger="seating">نقشه سالن</button>
          <button type="button" class="egm-task-top-item" aria-selected="false" data-egm-control-panel-trigger="printing">چاپ و بلیت</button>
        <?php endif; ?>
        <?php if ($egmCanMainPane || $egmCanTaskAccessPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'admin-access' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'admin-access' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="admin-access">دسترسی ادمین</button>
        <?php endif; ?>
        <?php if ($egmCanFacilitatorsPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'facilitators' ? ' active' : '' ?>" data-egm-control-panel-trigger="facilitators">تسهیلگرها</button>
        <?php endif; ?>
        <?php if ($egmCanLinkerPane): ?>
          <button type="button" class="egm-task-top-item<?= $egmControlInitialSection === 'linker' ? ' active' : '' ?>" aria-selected="<?= $egmControlInitialSection === 'linker' ? 'true' : 'false' ?>" data-egm-control-panel-trigger="linker">لینک کوتاه</button>
        <?php endif; ?>
      </div>

      <?php if ($egmCanFacilitatorsPane): ?>
      <section data-egm-control-panel-section="facilitators"<?= $egmControlInitialSection === 'facilitators' ? '' : ' hidden' ?>>
        <div class="card" id="egm-facilitators" data-endpoint="mini%20apps/Event%20Guest%20Manager/facilitators.php">
          <div class="section-header"><h3>تسهیلگرها</h3><div class="egm-action-bar"><button type="button" class="btn primary" data-fac-add>افزودن تسهیلگر</button><button type="button" class="btn ghost" data-fac-upload>ورود از اکسل</button></div></div>
          <p class="hint">حساب‌های ورود به پنل داور این رویداد</p>
          <input type="file" data-fac-file accept=".xlsx,.xls,.csv" hidden>
          <p class="hint" data-fac-status role="status" aria-live="polite"></p>
          <div class="table-wrapper"><table class="tct-list-table egm-fac-table"><thead><tr><th>نام</th><th>نام کاربری</th><th>دسترسی برنامه ویندوز</th><th>نقش رویداد</th><th>عملیات</th></tr></thead><tbody data-fac-list><tr><td colspan="5" class="muted">در حال دریافت تسهیلگرها…</td></tr></tbody></table></div>
          <h4>کاربران پنل با دسترسی به این رویداد</h4>
          <div class="table-wrapper"><table class="tct-list-table egm-fac-table"><thead><tr><th>نام</th><th>نام کاربری</th><th>نقش رویداد</th><th>عملیات</th></tr></thead><tbody data-fac-panel-list></tbody></table></div>
          <dialog class="egm-fac-dialog" data-fac-panel-dialog aria-labelledby="egm-fac-panel-title">
            <form data-fac-panel-form class="egm-fac-form">
              <div class="section-header"><h3 id="egm-fac-panel-title">نقش کاربر در رویداد</h3><button type="button" class="icon-btn" data-fac-close aria-label="بستن">×</button></div>
              <p data-fac-panel-name></p>
              <label class="field"><span>نقش رویداد</span><select name="event_role"><option value="referee">داور</option><option value="head_referee">سرداور</option><option value="system_manager">مدیر سیستم</option></select></label>
              <p class="hint" data-fac-panel-status role="alert"></p>
              <div class="modal-actions"><button type="button" class="btn ghost" data-fac-close>انصراف</button><button type="submit" class="btn primary">ذخیره نقش</button></div>
            </form>
          </dialog>
          <dialog class="egm-fac-dialog" data-fac-editor aria-labelledby="egm-fac-editor-title">
            <form data-fac-form class="egm-fac-form">
              <div class="section-header"><h3 id="egm-fac-editor-title">افزودن تسهیلگر</h3><button type="button" class="icon-btn" data-fac-close aria-label="بستن">×</button></div>
              <label class="field"><span>نام</span><input name="name" type="text" maxlength="100" required autocomplete="off"></label>
              <label class="field"><span>نام کاربری</span><input name="username" type="text" maxlength="80" required autocomplete="off" dir="auto"></label>
              <label class="field"><span>رمز عبور</span><span class="egm-fac-password-field"><input name="password" type="password" autocomplete="new-password"><button type="button" class="btn ghost" data-fac-toggle>نمایش</button></span><small class="muted" data-fac-password-hint></small></label>
              <label class="field"><span>دسترسی برنامه ویندوز</span><select name="win_app_access_level"><option value="scan_only">فقط اسکن</option><option value="scan_and_event">اسکن و اطلاعات عمومی رویداد</option><option value="scan_and_actions">اسکن و عملیات حضور، بدون جزئیات مهمان</option><option value="scan_and_details">اسکن، اطلاعات مهمانان و عملیات حضور</option><option value="full_access">دسترسی کامل، چاپ و تنظیمات</option></select></label>
              <label class="field"><span>نقش رویداد</span><select name="event_role"><option value="referee">داور</option><option value="head_referee">سرداور</option><option value="system_manager">مدیر سیستم</option></select></label>
              <p class="hint" data-fac-editor-status role="alert"></p>
              <div class="modal-actions"><button type="button" class="btn ghost" data-fac-close>انصراف</button><button class="btn primary" type="submit">ذخیره</button></div>
            </form>
          </dialog>
          <dialog class="egm-fac-dialog" data-fac-password-dialog aria-labelledby="egm-fac-password-title">
            <form data-fac-password-form class="egm-fac-form">
              <div class="section-header"><h3 id="egm-fac-password-title">رمز عبور تسهیلگر</h3><button type="button" class="icon-btn" data-fac-close aria-label="بستن">×</button></div>
              <p data-fac-password-name></p>
              <label class="field"><span>رمز عبور</span><span class="egm-fac-password-field"><input name="password" type="text" autocomplete="new-password" required><button type="button" class="btn ghost" data-fac-toggle>پنهان</button></span></label>
              <p class="hint" data-fac-password-status role="alert"></p>
              <div class="modal-actions"><button type="button" class="btn ghost" data-fac-close>بستن</button><button class="btn primary" type="submit">ذخیره رمز عبور</button></div>
            </form>
          </dialog>
          <dialog class="egm-fac-dialog" data-fac-delete-dialog aria-labelledby="egm-fac-delete-title">
            <form data-fac-delete-form class="egm-fac-form">
              <h3 id="egm-fac-delete-title">حذف تسهیلگر</h3><p data-fac-delete-name></p><p class="hint">دسترسی این حساب به پنل داور قطع می‌شود. تیم‌ها و نتایج ثبت‌شده باقی می‌مانند.</p>
              <p class="hint" data-fac-delete-status role="alert"></p>
              <div class="modal-actions"><button type="button" class="btn ghost" data-fac-close>انصراف</button><button class="btn danger" type="submit">حذف تسهیلگر</button></div>
            </form>
          </dialog>
          <dialog class="egm-fac-dialog egm-fac-import-dialog" data-fac-import-dialog aria-labelledby="egm-fac-import-title">
            <form data-fac-import-form class="egm-fac-form">
              <div class="section-header"><h3 id="egm-fac-import-title">ورود تسهیلگرها از اکسل</h3><button type="button" class="icon-btn" data-fac-close aria-label="بستن">×</button></div>
              <p class="hint">ردیف اول عنوان ستون‌هاست. ستون‌های نام، نام کاربری و رمز عبور را انتخاب کنید.</p>
              <label class="field"><span>برگه</span><select data-fac-sheet></select></label>
              <div class="egm-fac-mapping"><label class="field"><span>نام</span><select data-fac-column="name" required></select></label><label class="field"><span>نام کاربری</span><select data-fac-column="username" required></select></label><label class="field"><span>رمز عبور</span><select data-fac-column="password" required></select></label></div>
              <label class="egm-fac-update"><input type="checkbox" data-fac-update><span>به‌روزرسانی نام‌های کاربری موجود</span></label>
              <p class="hint" data-fac-import-count></p>
              <div class="table-wrapper"><table class="tct-list-table"><thead><tr><th>نام</th><th>نام کاربری</th><th>رمز عبور</th></tr></thead><tbody data-fac-preview></tbody></table></div>
              <p class="hint" data-fac-import-status role="alert"></p>
              <div class="modal-actions"><button type="button" class="btn ghost" data-fac-close>انصراف</button><button class="btn primary" type="submit" data-fac-import-save disabled>ورود تسهیلگرها</button></div>
            </form>
          </dialog>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($egmCanMainPane): ?>
      <section data-egm-control-panel-section="general"<?= $egmControlInitialSection === 'general' ? '' : ' hidden' ?>>
<div class="card" data-seat-map-editor>
  <div class="section-header"><h3>نقشه پیش‌فرض سالن</h3></div>
  <label class="field"><span><input type="checkbox" data-seat-enabled /> فعال‌سازی شماره صندلی</span></label>
  <label class="field"><span>بلیت شماره‌دار مبنا</span><select data-seat-ticket></select></label>
  <label class="field full"><span>بخش‌های صندلی هر ردیف، یک ردیف در هر خط</span><textarea data-seat-rows rows="6" placeholder="14 | 14&#10;14 | 14&#10;6 | 15 | 6"></textarea></label>
  <p class="muted" data-seat-summary></p>
  <div class="muted" data-seat-preview></div>
  <button type="button" class="btn primary" data-seat-save>ذخیره نقشه سالن</button>
  <p class="hint" data-seat-status aria-live="polite"></p>
</div>

<div class="card" id="egm-texts-card">
  <div class="section-header">
    <h3>تنظیمات رویداد</h3>
  </div>
  <div class="egm-refmonitor-link" data-egm-scan-link>
    <label class="field"><span>لینک اسکن و کنترل مهمان</span><input type="text" readonly dir="ltr" data-egm-scan-url aria-label="لینک اسکن و کنترل مهمان"></label>
    <a class="btn ghost" href="<?= htmlspecialchars($egmScanHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" data-egm-scan-open>باز کردن</a>
    <button type="button" class="btn ghost" data-egm-scan-copy>کپی لینک</button>
    <?php if ($egmCanLinkerPane): ?><button type="button" class="btn ghost" data-egm-scan-short>ساخت لینک کوتاه</button><?php endif; ?>
    <span class="muted small" data-egm-scan-link-status role="status" aria-live="polite"></span>
  </div>
  <div class="form" style="gap:12px;">
    <div id="egm-status-text" class="egm-status egm-status--inactive">غیرفعال</div>
    <div class="egm-switch-grid">
      <label class="switch egm-switch"><span class="switch-label">گزارش خودکار</span><span class="switch-toggle"><input type="checkbox" id="egm-auto-report-toggle" aria-label="گزارش خودکار"><span class="switch-track"><span class="switch-thumb"></span></span></span></label>
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
        <span class="switch-label">توقف دسترسی رویداد</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-event-access-lock-toggle" aria-label="توقف دسترسی رویداد" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">چاپ خودکار</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-auto-print-toggle" aria-label="چاپ خودکار همه خروجی‌های فعال" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">چاپ کارت دعوت در دو نسخه</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-double-print-toggle" aria-label="چاپ دو نسخه از کارت دعوت" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">چاپ بلیت شماره‌دار</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-custom-number-ticket-toggle" aria-label="فعال‌سازی بلیت شماره‌دار" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch egm-switch">
        <span class="switch-label">چاپ کارت دعوت</span>
        <span class="switch-toggle">
          <input type="checkbox" id="egm-print-card-toggle" aria-label="فعال‌سازی کارت دعوت" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
    </div>
    <div class="card" style="margin-top:12px">
      <div class="section-header"><h3>بلیت‌های شماره‌دار</h3><div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn ghost" id="egm-add-ticket-type">افزودن بلیت</button><button type="button" class="btn primary standard-primary-button" id="egm-save-ticket-types">ذخیره چاپ و بلیت</button></div></div>
      <div id="egm-ticket-types" class="form" style="gap:10px"></div>
      <p class="muted small">پس از ذخیره، تب طراحی هر بلیت تازه‌سازی می‌شود.</p>
      <p class="muted small" id="egm-ticket-types-status" role="status" aria-live="polite"></p>
    </div>
    <div class="card" style="margin-top:12px">
      <div class="section-header"><h3>کد مدیریت</h3><span class="muted small" id="egm-admin-passcode-state">تنظیم نشده</span></div>
      <div class="form grid two-column-fields" style="gap:10px">
        <label class="field standard-width">
          <span>کد مدیریت اپلیکیشن ویندوز</span>
          <input type="password" id="egm-admin-passcode" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" placeholder="۴ تا ۶ رقم" />
        </label>
        <label class="field standard-width">
          <span>تکرار کد</span>
          <input type="password" id="egm-admin-passcode-confirm" inputmode="numeric" pattern="[0-9]{4,6}" minlength="4" maxlength="6" autocomplete="new-password" placeholder="تکرار کد" />
        </label>
      </div>
      <div style="display:flex;align-items:center;gap:12px;margin-top:12px">
        <button type="button" class="btn" id="egm-admin-passcode-save">ذخیره کد</button>
        <span class="small" id="egm-admin-passcode-status" role="status" aria-live="polite"></span>
      </div>
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
      <section data-egm-control-panel-section="seating" hidden><div data-egm-seating-content></div></section>
      <section data-egm-control-panel-section="printing" hidden>
        <div class="card egm-control-settings-card"><div class="section-header"><h3>چاپ و بلیت</h3></div>
          <div class="egm-switch-grid" data-egm-print-switches></div>
        </div>
        <div data-egm-ticket-settings-content></div>
        <div class="egm-control-save-row" data-egm-print-save></div>
      </section>
      <?php endif; ?>



      <?php if ($egmCanMainPane || $egmCanTaskAccessPane): ?>
      <section data-egm-control-panel-section="admin-access"<?= $egmControlInitialSection === 'admin-access' ? '' : ' hidden' ?>>
      <?php if ($egmCanMainPane): ?><div data-egm-admin-passcode-content></div><?php endif; ?>
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
          <label class="egm-task-access-manage-row">
            <input type="checkbox" id="egm-task-access-manage-tasks" />
            <span>دسترسی به تب بازه‌ها</span>
          </label>
          <label class="field full">
            <span>سطح دسترسی اپلیکیشن ویندوز</span>
            <select id="egm-winapp-access-level">
              <option value="full_access">دسترسی کامل به اسکن، اطلاعات و تنظیمات</option>
              <option value="scan_and_actions">اسکن و عملیات حضور، بدون جزئیات مهمان</option><option value="scan_and_details">اسکن و تمام اطلاعات کاربران و رویداد، بدون تنظیمات</option>
              <option value="scan_and_event">فقط اسکن و اطلاعات عمومی رویداد</option>
              <option value="scan_only">فقط اسکن</option>
            </select>
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

      <?php if ($egmCanLinkerPane): ?>
      <section data-egm-control-panel-section="linker"<?= $egmControlInitialSection === 'linker' ? '' : ' hidden' ?>>
      <div class="card" id="egm-campaign-linker-card">
        <div class="section-header">
          <h3>لینک کوتاه</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width"><span>مقصد</span><select id="egm-linker-destination"><option value="refmonitor">پنل داور</option><option value="scan">اسکن و کنترل مهمان</option></select></label>
          <label class="field standard-width">
            <span>مسیر کمپین</span>
            <div class="egm-linker-input-row">
              <span class="egm-linker-prefix">/campaigns/</span>
              <input id="egm-linker-path" type="text" maxlength="180" autocomplete="off" dir="ltr" placeholder="dastavard" />
            </div>
          </label>
          <p id="egm-linker-preview" class="hint muted small" aria-live="polite"></p>
          <div class="egm-action-bar">
            <button type="button" class="btn ghost" id="egm-linker-check">بررسی دسترسی</button>
            <button type="button" class="btn primary standard-primary-button" id="egm-linker-create" disabled>ساخت لینک کوتاه</button>
          </div>
          <p id="egm-linker-status" class="hint muted small" aria-live="polite"></p>
          <div class="table-wrapper">
            <table class="tct-list-table egm-linker-table">
              <thead>
                <tr>
                  <th>نشانی کمپین</th>
                  <th>نوع</th>
                  <th>مقصد</th>
                  <th>وضعیت</th>
                </tr>
              </thead>
              <tbody id="egm-linker-current-body">
                <tr>
                  <td colspan="4" class="muted">در حال دریافت لینک‌های ذخیره‌شده…</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      </section>
      <?php endif; ?>


    </div>
    <?php endif; ?>
    <?php if ($egmCanPrizesPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-rewards-config' ? ' active' : '' ?>" data-pane="egm-rewards-config">
      <nav class="egm-period-actions" aria-label="جوایز و قرعه‌کشی"><button type="button" class="btn primary" data-prize-pane="inventory">انبار جوایز</button><button type="button" class="btn ghost" data-prize-pane="draws">قرعه‌کشی‌های دائمی</button></nav>
      <section data-prize-content="inventory">
      <h2 class="egm-inventory-title">انبار جوایز</h2>
        <div id="egm-prize-section">
          <p id="egm-prize-status" class="egm-prize-status" role="status" aria-live="polite" hidden></p>
          <div class="card">
            <div class="section-header">
              <h3>افزودن جایزه</h3>
            </div>
            <form id="egm-prize-form" class="form">
              <div class="form grid egm-prize-grid">
                <label class="field standard-width">
                  <span>نام جایزه</span>
                  <input id="egm-prize-name" name="name" type="text" autocomplete="off" aria-describedby="egm-prize-status" placeholder="نام جایزه" required />
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
                <label class="field standard-width"><span>رتبه</span><select id="egm-prize-rank" name="rank"><option value="0">بدون رتبه</option><option value="1">اول</option><option value="2">دوم</option><option value="3">سوم</option><option value="4">چهارم</option></select></label>
                <label class="field standard-width"><span>شانس انتخاب</span><select id="egm-prize-chance"><option value="normal">عادی</option><option value="more">شانس بیشتر</option><option value="priority">اولویت ۶۰٪</option></select></label>
              </div>
              <div class="field full egm-form-action">
                <button type="submit" class="btn primary standard-primary-button">ثبت جایزه</button>
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
                    <th>وضعیت موجودی</th>
                    <th>تعداد کل</th>
                    <th>ارزش</th>
                    <th>رتبه</th>
                    <th>شانس انتخاب</th>
                    <th>کنترل تعداد</th>
                    <th>عملیات</th>
                  </tr>
                </thead>
                <tbody id="egm-prize-list"></tbody>
              </table>
            </div>
          </div>

          <div class="card" id="egm-competition-queue-card">
            <div class="section-header"><h3>صف جوایز رقابت‌ها</h3><span class="muted" id="egm-competition-queue-count">۰ جایزه</span></div>
            <label class="switch egm-switch"><span class="switch-label">انتخاب طبق صف</span><span class="switch-toggle"><input type="checkbox" role="switch" id="egm-competition-queue-enabled" checked><span class="switch-track"><span class="switch-thumb"></span></span></span></label>
            <div class="form egm-competition-queue-form">
              <div id="egm-competition-queue-list" class="egm-competition-queue-list"></div>
              <div class="egm-action-bar"><label class="field"><span>رتبه بعدی</span><select id="egm-competition-queue-rank"><option value="1">اول</option><option value="2">دوم</option><option value="3">سوم</option><option value="4">چهارم</option></select></label><button type="button" class="btn ghost" id="egm-competition-queue-add">افزودن به صف</button><button type="button" class="btn primary" id="egm-competition-queue-save">ذخیره صف</button></div>
              <p class="hint" id="egm-competition-queue-status" aria-live="polite"></p>
            </div>
          </div>

        </div>
      </section>
      <section data-prize-content="draws" data-general-draw-manager data-endpoint="mini%20apps/Event%20Guest%20Manager/general_draws.php" hidden>
        <div class="card"><div class="section-header"><h3>قرعه‌کشی‌های دائمی</h3><button type="button" class="btn primary" data-general-new>قرعه‌کشی جدید</button></div><p role="status" data-general-status></p><div data-general-list></div></div>
        <form class="card form" data-general-form hidden><input name="id" type="hidden">
          <div class="form grid"><label class="field"><span>نام قرعه‌کشی</span><input name="name" maxlength="160" required></label>
          <label class="field"><span>عنوان صفحه</span><input name="title" maxlength="160"></label>
          <label class="field"><span>نام جایزه</span><input name="prizeName" maxlength="160"></label>
          <label class="field"><span>تعداد برندگان هر بازه</span><input name="winnerLimit" type="number" min="1" max="1000" value="1" required></label>
          <label class="field full"><span>توضیحات</span><textarea name="description" maxlength="4000"></textarea></label></div>
          <label class="switch egm-switch"><span class="switch-label">نمایش و قفل کارت پس از تأیید جایزه</span><span class="switch-toggle"><input type="checkbox" name="keepConfirmedCards" checked><span class="switch-track"><span class="switch-thumb"></span></span></span></label>
          <p class="muted">خاموش: کارت پنهان می‌شود و شمارهٔ آن دوباره قابل انتخاب است.</p>
          <label class="switch egm-switch"><span class="switch-label">جلوگیری از تکرار نام جایزه</span><span class="switch-toggle"><input type="checkbox" name="preventRepeatedPrizeNames" checked><span class="switch-track"><span class="switch-thumb"></span></span></span></label>
          <p class="muted">خاموش: جوایز با کد متفاوت و نام یکسان قابل انتخاب‌اند.</p>
          <label class="switch egm-switch"><span class="switch-label">جلوگیری از تکرار همان جایزه در بازه</span><span class="switch-toggle"><input type="checkbox" name="preventRepeatedPrizes" checked><span class="switch-track"><span class="switch-thumb"></span></span></span></label>
          <p class="muted">خاموش: همان کد جایزه با موجودی باقی‌مانده دوباره قابل انتخاب است.</p>
          <div class="egm-period-actions"><button type="submit" class="btn primary">ذخیره قرعه‌کشی</button><button type="button" class="btn ghost" data-general-cancel>انصراف</button></div>
        </form>
      </section>
    </div>
    <?php endif; ?>
    <?php if ($egmCanInviteesPane): ?>
    <div class="sub-pane<?= $egmInitialPane === 'egm-invitees' ? ' active' : '' ?>" data-pane="egm-invitees">
      <?php include __DIR__ . '/invitees.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($egmCanGroupsPane): ?>
      <?php renderEgmGroupsPane('mini%20apps/Event%20Guest%20Manager/groups.php', $egmInitialPane === 'egm-groups'); ?>
    <?php endif; ?>
    <?php if ($egmCanGamesPane): ?>
      <div class="sub-pane<?= $egmInitialPane === 'egm-games' ? ' active' : '' ?>" data-pane="egm-games" data-egm-games-catalog>
        <div class="card egm-games-catalog"><div class="section-header"><h3>بازی‌ها</h3><a href="mini%20apps/Event%20Guest%20Manager/telegram-diagnostics.php" target="_blank" rel="noopener">بررسی اتصال ربات تلگرام</a><a href="mini%20apps/Event%20Guest%20Manager/telegram-diagnostics.php?platform=bale" target="_blank" rel="noopener">راه‌اندازی و بررسی ربات بله</a></div>
          <div class="egm-refmonitor-link"><label class="field"><span>لینک پنل داور</span><input type="text" readonly dir="ltr" data-refmonitor-url aria-label="لینک پنل داور"></label><a class="btn ghost" href="<?= htmlspecialchars($egmRefMonitorHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" data-refmonitor-open>باز کردن</a><button type="button" class="btn ghost" data-refmonitor-copy>کپی لینک</button></div>
          <?php if ($egmCanLinkerPane): ?><p class="hint">برای ساخت لینک کوتاه، در کنترل پنل ← لینک کوتاه، مقصد «پنل داور» را انتخاب کنید.</p><?php endif; ?>
          <form data-games-create class="form egm-games-create"><label class="field"><span>نام بازی جدید</span><input type="text" name="name" maxlength="100" required placeholder="نام بازی"></label><button class="btn primary" type="submit">افزودن بازی</button></form>
          <div data-games-list></div><p class="hint" data-games-status aria-live="polite"></p>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($egmCanInviteCardPane): ?>
      <?php renderEgmInviteCardPane('mini%20apps/Event%20Guest%20Manager/invite_card_store.php', $egmInitialPane === 'egm-invite-card'); ?>
    <?php endif; ?>
    <?php if ($egmCanPrintCardPane): ?>
      <?php renderEgmInviteCardPane('mini%20apps/Event%20Guest%20Manager/print_card_store.php', $egmInitialPane === 'egm-print-card', ['paneKey' => 'egm-print-card', 'kind' => 'print', 'eventName' => $egmPanelInstanceName]); ?>
    <?php endif; ?>
    <?php if ($egmCanTicketPane): ?>
      <?php foreach ($egmPanelTicketDefinitions as $egmTicketIndex => $egmTicket):
        $egmTicketId = preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)($egmTicket['id'] ?? 'default'))) ?: 'default';
        $egmTicketTitle = (string)($egmTicket['title'] ?? ('Ticket ' . ($egmTicketIndex + 1)));
        renderEgmInviteCardPane('mini%20apps/Event%20Guest%20Manager/custom_number_ticket_store.php?ticket_id=' . rawurlencode($egmTicketId), $egmInitialPane === 'egm-custom-number-ticket-' . $egmTicketId, ['paneKey' => 'egm-custom-number-ticket-' . $egmTicketId, 'kind' => 'ticket', 'title' => $egmTicketTitle]);
      endforeach; ?>
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
          <h3>گزارش‌ها</h3>
        </div>
        <div class="egm-logs-toolbar">
          <label class="field egm-logs-search-field">
            <span>جستجوی نام کاربری</span>
            <input id="egm-logs-search" type="search" placeholder="نام کاربری، شناسه یا عملیات..." autocomplete="off" />
          </label>
          <label class="field egm-logs-day-field">
            <span>روز</span>
            <select id="egm-logs-day"></select>
          </label>
          <button type="button" class="btn ghost" id="egm-logs-refresh">تازه‌سازی</button>
        </div>
        <p class="muted egm-logs-status" id="egm-logs-status" aria-live="polite">گزارش‌ها در حال بارگذاری هستند.</p>
        <div class="table-wrapper egm-logs-table-wrap">
          <table class="egm-logs-table">
            <thead>
              <tr>
                <th>زمان</th>
                <th>سطح</th>
                <th>کاربر</th>
                <th>عملیات</th>
                <th>وضعیت</th>
                <th>پیام</th>
                <th>جزئیات</th>
                <th>نشانی اینترنتی</th>
              </tr>
            </thead>
            <tbody id="egm-logs-body">
              <tr><td colspan="8" class="muted">گزارش‌ها هنگام باز شدن این بخش بارگذاری می‌شوند.</td></tr>
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
          <h3>دسترسی محدود</h3>
        </div>
        <p class="muted">به هیچ‌یک از بخش‌های مدیریت مهمانان دسترسی ندارید.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<?php if ($egmCanInviteesPane || $egmCanManageTasksPane || $egmCanMainPane || $egmCanTaskAccessPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/vendor/xlsx/xlsx.full.min.js" defer></script>
<?php endif; ?>
<script src="mini%20apps/Event%20Guest%20Manager/egm-panel-local.js?v=<?= htmlspecialchars($egmPanelLocalJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if ($egmCanFacilitatorsPane): ?><script src="assets/egm-facilitators.js?v=<?= (int)(@filemtime(__DIR__ . '/../../assets/egm-facilitators.js') ?: time()) ?>" defer></script><?php endif; ?>
<?php if ($egmCanGamesPane || $egmCanTaskSubtabs): ?><script src="assets/egm-games.js?v=<?= htmlspecialchars($egmGamesJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script><?php endif; ?>
<?php if ($egmCanGroupsPane): ?><script src="assets/egm-groups.js?v=<?= htmlspecialchars($egmGroupsJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script><?php endif; ?>
<?php if ($egmCanInviteCardPane || $egmCanPrintCardPane || $egmCanTicketPane): ?>
<script src="assets/egm-invite-card.js?v=<?= htmlspecialchars($egmInviteCardJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanPrizesPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGM%20Prizes.js?v=<?= htmlspecialchars($egmPrizesJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanControlPanel): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMSetting.js?v=<?= htmlspecialchars($egmSettingJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanTaskAccessPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMTaskAccess.js?v=<?= htmlspecialchars($egmTaskAccessJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($egmCanMonitoringPane): ?>
<script src="mini%20apps/Event%20Guest%20Manager/EGMMonitoring.js?v=<?= htmlspecialchars($egmMonitoringJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<script src="mini%20apps/Event%20Guest%20Manager/egm-admin-ux.js?v=<?= (int)(@filemtime(__DIR__ . '/egm-admin-ux.js') ?: time()) ?>" defer></script>

<script src="assets/egm-general-draws.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/egm-general-draws.js') ?>" defer></script>
<script src="assets/egm-game-ranking-export.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/egm-game-ranking-export.js') ?>" defer></script>
<script src="assets/egm-oeu-import-review.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/egm-oeu-import-review.js') ?>" defer></script>

<script src="assets/egm-guest-reports.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/egm-guest-reports.js') ?>" defer></script>
