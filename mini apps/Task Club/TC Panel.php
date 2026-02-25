<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
$tcPanelUser = requireTabPermissionFromSession('task-club', false);
$tcAllowedChildTabs = resolveAllowedPanelChildTabsForUser($tcPanelUser, 'task-club');
$tcAllowedChildSet = array_fill_keys($tcAllowedChildTabs, true);
$tcCanMainPane = isset($tcAllowedChildSet['task-club:main']);
$tcCanInviteesPane = isset($tcAllowedChildSet['task-club:invitees']);
$tcCanManageTasksPane = isset($tcAllowedChildSet['task-club:manage-tasks']);
$tcCanTaskAccessPane = isset($tcAllowedChildSet['task-club:task-access']);
$tcCanMonitoringPane = isset($tcAllowedChildSet['task-club:monitoring']);
$tcCanExportPane = isset($tcAllowedChildSet['task-club:export']);
$tcCanEventStylePane = isset($tcAllowedChildSet['task-club:event-style']);
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
  if (is_file($tcTaskAccessPath)) {
    $tcTaskAccessRaw = file_get_contents($tcTaskAccessPath);
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
  || $tcCanEventStylePane;
$tcInitialPane = '';
foreach ([
  'tc-main' => $tcCanMainPane,
  'tc-invitees' => $tcCanInviteesPane,
  'tc-manage-tasks' => $tcCanManageTasksPane,
  'tc-task-access' => $tcCanTaskAccessPane,
  'tc-monitoring' => $tcCanMonitoringPane,
  'tc-export' => $tcCanExportPane,
  'tc-event-style' => $tcCanEventStylePane
] as $paneKey => $allowed) {
  if ($allowed) {
    $tcInitialPane = $paneKey;
    break;
  }
}
require_once __DIR__ . '/tc-security.php';
$tcPanelCsrfToken = tcSecurityGetCsrfToken();

$tcPanelCssVer = (string)(@filemtime(__DIR__ . '/tc-panel.css') ?: time());
$tcPanelLocalJsVer = (string)(@filemtime(__DIR__ . '/tc-panel-local.js') ?: time());
$tcPrizesJsVer = (string)(@filemtime(__DIR__ . '/TC Prizes.js') ?: time());
$tcSettingJsVer = (string)(@filemtime(__DIR__ . '/TCSetting.js') ?: time());
$tcEventStyleJsVer = (string)(@filemtime(__DIR__ . '/TCEventStyle.js') ?: time());
$tcMonitoringJsVer = (string)(@filemtime(__DIR__ . '/TCMonitoring.js') ?: time());
$tcTaskAccessJsVer = (string)(@filemtime(__DIR__ . '/TCTaskAccess.js') ?: time());
?>

<link rel="stylesheet" href="mini%20apps/Task%20Club/tc-panel.css?v=<?= htmlspecialchars($tcPanelCssVer, ENT_QUOTES, 'UTF-8') ?>" />
<div class="tc-shell" data-tc-csrf="<?= htmlspecialchars($tcPanelCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
<div class="sub-layout" data-tc-sub-layout>
  <aside class="sub-sidebar">
    <div class="sub-header">Task Club</div>
    <div class="sub-nav">
      <?php if ($tcCanMainPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-main' ? ' active' : '' ?>" data-pane="tc-main">Main Panel</button>
      <?php endif; ?>
      <?php if ($tcCanInviteesPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-invitees' ? ' active' : '' ?>" data-pane="tc-invitees">Invitees</button>
      <?php endif; ?>
      <?php if ($tcCanManageTasksPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-manage-tasks' ? ' active' : '' ?>" data-pane="tc-manage-tasks">Manage Tasks</button>
      <?php endif; ?>
      <?php if ($tcCanTaskAccessPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-task-access' ? ' active' : '' ?>" data-pane="tc-task-access">دسترسی تسک‌ها</button>
      <?php endif; ?>
      <?php if ($tcCanMonitoringPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-monitoring' ? ' active' : '' ?>" data-pane="tc-monitoring">Monitoring</button>
      <?php endif; ?>
      <?php if ($tcCanExportPane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-export' ? ' active' : '' ?>" data-pane="tc-export">Export</button>
      <?php endif; ?>
      <?php if ($tcCanEventStylePane): ?>
        <button type="button" class="sub-item<?= $tcInitialPane === 'tc-event-style' ? ' active' : '' ?>" data-pane="tc-event-style">Event Style</button>
      <?php endif; ?>
      <?php if ($tcCanTaskSubtabs): ?>
        <div data-tc-task-subtab-nav></div>
      <?php endif; ?>
    </div>
  </aside>
  <div class="sub-content">
    <?php if ($tcCanMainPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-main' ? ' active' : '' ?>" data-pane="tc-main">
      <div class="card">
  <div class="section-header">
    <h3>Status</h3>
  </div>
  <div class="field">
    <a class="btn primary standard-primary-button" href="mini%20apps/Task%20Club/TCM.php" target="_blank" rel="noopener">Open Task Club</a>
  </div>
</div>

<div class="card" id="tc-texts-card">
  <div class="section-header">
    <h3>Control Panel</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div id="tc-status-text" class="tc-status tc-status--inactive">Not Active</div>
    <div class="tc-switch-grid">
      <label class="switch tc-switch">
        <span class="switch-label">Active</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-active-toggle" aria-label="Active" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch tc-switch">
        <span class="switch-label">Duration</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-duration-toggle" aria-label="Duration" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch tc-switch">
        <span class="switch-label">Maintenance Mode</span>
        <span class="switch-toggle">
          <input type="checkbox" id="tc-maintenance-toggle" aria-label="Maintenance Mode" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
    </div>
    <div class="form grid two-column-fields tc-datetime-grid">
      <div class="tc-datetime-title tc-datetime-title--start">Start</div>
      <label class="field standard-width tc-datetime-start">
        <span>Date</span>
        <input
          type="date"
          id="tc-duration-start"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width tc-datetime-start-time">
        <span>Time</span>
        <select id="tc-duration-start-time">
          <option value="">Select time</option>
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
      <div class="tc-datetime-title tc-datetime-title--end">End</div>
      <label class="field standard-width tc-datetime-end">
        <span>Date</span>
        <input
          type="date"
          id="tc-duration-end"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width tc-datetime-end-time">
        <span>Time</span>
        <select id="tc-duration-end-time">
          <option value="">Select time</option>
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
      <button type="button" class="btn primary standard-primary-button" id="tc-settings-save">Save</button>
    </div>
  </div>
</div>

<div class="card" id="tc-assign-admin-card">
  <div class="section-header">
    <h3>Assign Admin</h3>
  </div>
  <div class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Work ID</span>
      <input id="tc-admin-workid" type="text" autocomplete="off" placeholder="Enter Work ID" />
    </label>
    <div class="field full tc-form-action">
      <button type="button" class="btn primary standard-primary-button" id="tc-admin-search-btn">Search User</button>
    </div>
    <div id="tc-admin-search-result" class="tc-admin-search-result hidden" aria-live="polite"></div>
    <p id="tc-admin-status" class="muted small" aria-live="polite"></p>
    <div class="table-wrapper">
      <table class="tc-admin-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Work ID</th>
            <th>Phone Number</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="tc-admin-list">
          <tr>
            <td colspan="4" class="muted">No admins assigned yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Fake Items</h3>
  </div>
  <form id="tc-fake-form" class="form">
    <label class="field standard-width">
      <span>Fake Item Name</span>
      <input id="tc-fake-name" name="fake-name" type="text" autocomplete="off" required />
    </label>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
  </form>
  <div class="table-wrapper tc-fake-table">
    <table class="tc-prize-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="tc-fake-list"></tbody>
    </table>
  </div>
</div>

<div id="tc-prize-section">
  <div class="card">
  <div class="section-header">
    <h3>Add Prize</h3>
  </div>
  <form id="tc-prize-form" class="form">
    <div class="form grid tc-prize-grid">
      <label class="field standard-width">
        <span>Name</span>
        <input id="tc-prize-name" name="name" type="text" autocomplete="off" required />
      </label>
      <label class="field tc-standard-third">
        <span>Quantity</span>
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
        <span>Value</span>
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
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
  </form>
</div>

  <div class="card">
  <div class="section-header">
    <h3>Prizes</h3>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>On Wheel Name</th>
          <th>Status</th>
          <th>Quantity</th>
          <th>Value</th>
          <th>Count Control</th>
          <th>Action Bar</th>
        </tr>
      </thead>
      <tbody id="tc-prize-list"></tbody>
</table>
</div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Prize Levels</h3>
  </div>
  <form id="tc-prize-level-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Name</span>
      <input id="tc-prize-level-name" name="name" type="text" autocomplete="off" required />
    </label>
    <label class="field standard-width">
      <span>Type</span>
      <select id="tc-prize-level-type" name="type" required>
        <option value="value_sum">Value Sum</option>
        <option value="out_of_value">Out of Value</option>
      </select>
    </label>
    <label class="field standard-width">
      <span>Score</span>
      <input id="tc-prize-level-score" name="score" type="number" min="1" step="1" inputmode="numeric" required />
    </label>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add Level</button>
    </div>
    <p id="tc-prize-level-status" class="muted small" aria-live="polite"></p>
  </form>
  <div class="table-wrapper">
    <table class="tc-prize-level-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Type</th>
          <th>Score</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="tc-prize-level-list">
        <tr><td colspan="5" class="muted">No levels added yet.</td></tr>
      </tbody>
    </table>
  </div>
</div>
</div>
    </div>
    <?php endif; ?>
    <?php if ($tcCanInviteesPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-invitees' ? ' active' : '' ?>" data-pane="tc-invitees">
      <?php include __DIR__ . '/invitees.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanManageTasksPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-manage-tasks' ? ' active' : '' ?>" data-pane="tc-manage-tasks">
      <?php include __DIR__ . '/TCT.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanTaskAccessPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-task-access' ? ' active' : '' ?>" data-pane="tc-task-access">
      <div class="card">
        <div class="section-header">
          <h3>دسترسی تسک‌ها</h3>
        </div>
        <div class="form" style="gap:12px;">
          <label class="field standard-width">
            <span>کاربر پنل</span>
            <select id="tc-task-access-user-select"></select>
          </label>
          <label class="tc-task-access-manage-row">
            <input type="checkbox" id="tc-task-access-manage-tasks" />
            <span>دسترسی به تب Manage Tasks</span>
          </label>
          <p class="muted small" id="tc-task-access-status" aria-live="polite"></p>
          <div id="tc-task-access-tree" class="tc-task-access-tree"></div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-task-access-save">ذخیره دسترسی‌ها</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($tcCanMonitoringPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-monitoring' ? ' active' : '' ?>" data-pane="tc-monitoring">
      <?php include __DIR__ . '/TCMonitoring.php'; ?>
    </div>
    <?php endif; ?>
    <?php if ($tcCanExportPane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-export' ? ' active' : '' ?>" data-pane="tc-export">
      <div class="card">
        <div class="section-header">
          <h3>Export</h3>
        </div>
        <div class="field">
          <a
            class="btn primary standard-primary-button"
            href="mini%20apps/Task%20Club/export_login_data.php"
            target="_blank"
            rel="noopener"
          >Export Login Data</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($tcCanEventStylePane): ?>
    <div class="sub-pane<?= $tcInitialPane === 'tc-event-style' ? ' active' : '' ?>" data-pane="tc-event-style">
      <div class="card">
        <div class="section-header">
          <h3>Upload Logo</h3>
        </div>
        <div class="form">
          <div class="photo-uploader tc-event-logo-uploader">
            <div class="photo-preview" aria-live="polite">
              <img id="tc-event-logo-image" class="hidden" alt="Event logo preview" />
              <div id="tc-event-logo-placeholder" class="photo-placeholder">No image selected</div>
            </div>
            <div class="photo-actions">
              <button type="button" class="btn" id="tc-event-logo-pick">Choose photo</button>
              <button type="button" class="btn ghost" id="tc-event-logo-clear" disabled>Clear</button>
            </div>
            <p id="tc-event-style-logo-status" class="hint muted small" aria-live="polite"></p>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="section-header">
          <h3>Colors</h3>
        </div>
        <div class="form" style="gap:12px;">
          <div class="appearance-row">
            <span class="appearance-label">Secondary</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-secondary" class="appearance-hex-field" type="text" maxlength="7" value="#2F8FFF" />
              <button type="button" class="appearance-preview" id="tc-event-preview-secondary" aria-label="Choose secondary color"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-secondary">Choose</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">Highlight</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-highlight" class="appearance-hex-field" type="text" maxlength="7" value="#20C997" />
              <button type="button" class="appearance-preview" id="tc-event-preview-highlight" aria-label="Choose highlight color"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-highlight">Choose</button>
            </div>
          </div>
          <div class="appearance-row">
            <span class="appearance-label">Soft Accent</span>
            <div class="appearance-input-group">
              <input id="tc-event-color-accent-soft" class="appearance-hex-field" type="text" maxlength="7" value="#FFB347" />
              <button type="button" class="appearance-preview" id="tc-event-preview-accent-soft" aria-label="Choose soft accent color"></button>
              <button type="button" class="btn ghost" id="tc-event-picker-accent-soft">Choose</button>
            </div>
          </div>
          <div class="field full">
            <button type="button" class="btn primary standard-primary-button" id="tc-event-style-save">Save</button>
          </div>
          <p id="tc-event-style-save-status" class="hint muted small" aria-live="polite"></p>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($tcCanTaskSubtabs): ?>
    <div data-tc-task-subtab-panes></div>
    <?php endif; ?>
    <?php if (!$tcHasAnyPane): ?>
      <div class="card">
        <div class="section-header">
          <h3>Access Restricted</h3>
        </div>
        <p class="muted">You do not have access to any Task Club subtab.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>

<script src="mini%20apps/Task%20Club/tc-panel-local.js?v=<?= htmlspecialchars($tcPanelLocalJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php if ($tcCanMainPane): ?>
<script src="mini%20apps/Task%20Club/TC%20Prizes.js?v=<?= htmlspecialchars($tcPrizesJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<script src="mini%20apps/Task%20Club/TCSetting.js?v=<?= htmlspecialchars($tcSettingJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcCanEventStylePane): ?>
<script src="mini%20apps/Task%20Club/TCEventStyle.js?v=<?= htmlspecialchars($tcEventStyleJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcCanTaskAccessPane): ?>
<script src="mini%20apps/Task%20Club/TCTaskAccess.js?v=<?= htmlspecialchars($tcTaskAccessJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
<?php if ($tcCanMonitoringPane): ?>
<script src="mini%20apps/Task%20Club/TCMonitoring.js?v=<?= htmlspecialchars($tcMonitoringJsVer, ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
