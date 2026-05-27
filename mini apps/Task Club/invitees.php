<?php
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/invitees_special_access.php';
$tcInviteesSessionUser = requireTabPermissionFromSession('task-club', false);
if (!userHasPermissionId($tcInviteesSessionUser, 'task-club:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Task Club section.', false);
}
$tcInviteesCsrfToken = tcSecurityGetCsrfToken();
$tcInviteesSpecialAccess = tcInviteesSpecialAccessForPanelUser($tcInviteesSessionUser, __DIR__ . '/tasks/task-access.json');
$tcInviteesCanManage = !empty($tcInviteesSpecialAccess['manageInvitees']);
$tcInviteesCanReset = !empty($tcInviteesSpecialAccess['resetInvitee']);
$tcInviteesCanReveal = !empty($tcInviteesSpecialAccess['revealPassword']);
$tcInviteesCanEdit = !empty($tcInviteesSpecialAccess['editInvitee']);
$tcInviteesHasRowAction = $tcInviteesCanEdit || $tcInviteesCanReveal || $tcInviteesCanReset;

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'TC Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapFile = $baseDir . DIRECTORY_SEPARATOR . 'TC Mapped.json';
$anyPasswordSettingsFile = $baseDir . DIRECTORY_SEPARATOR . 'any-password-login.json';
$stats = [
  'total' => 0,
  'columns' => [],
  'conflicts' => [
    'workId' => [],
    'nationalId' => [],
    'phoneNumber' => []
  ]
];
$allInvitees = [];

function readAnyPasswordLoginSettings(string $path): array {
  $defaults = [
    'anyPassword' => false,
    'minLength' => 3
  ];
  if (!is_file($path)) {
    return $defaults;
  }
  $data = json_decode((string)file_get_contents($path), true);
  if (!is_array($data)) {
    return $defaults;
  }
  $minLength = (int)($data['minLength'] ?? ($data['min_length'] ?? $defaults['minLength']));
  return [
    'anyPassword' => !empty($data['anyPassword']) || !empty($data['any_password']),
    'minLength' => max(1, min(128, $minLength))
  ];
}

function readMappedConfig(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : [];
}

function readCsvRows(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  if (!flock($handle, LOCK_SH)) {
    fclose($handle);
    return [];
  }
  while (($data = fgetcsv($handle)) !== false) {
    $rows[] = $data;
  }
  flock($handle, LOCK_UN);
  fclose($handle);
  return $rows;
}

function normalizeInviteHeaderToken(string $value): string {
  $token = trim($value);
  $token = str_replace(['-', '_'], ' ', $token);
  if (function_exists('mb_strtolower')) {
    return mb_strtolower($token, 'UTF-8');
  }
  return strtolower($token);
}

function findInviteHeaderIndex(array $header, array $names): int {
  $targets = [];
  foreach ($names as $name) {
    if (!is_scalar($name)) {
      continue;
    }
    $token = normalizeInviteHeaderToken((string)$name);
    if ($token !== '') {
      $targets[$token] = true;
    }
  }
  if (!$targets) {
    return -1;
  }
  foreach ($header as $index => $value) {
    $token = normalizeInviteHeaderToken((string)$value);
    if ($token !== '' && isset($targets[$token])) {
      return (int)$index;
    }
  }
  return -1;
}

function resolveMappedInviteColumnIndex(array $header, array $mapping, string $key, array $fallbackNames): int {
  $mappedIndex = $mapping[$key] ?? null;
  if (is_numeric($mappedIndex)) {
    $index = (int)$mappedIndex;
    if ($index >= 0 && $index < count($header)) {
      return $index;
    }
  }
  return findInviteHeaderIndex($header, $fallbackNames);
}

$mapping = readMappedConfig($mapFile);
$anyPasswordLoginSettings = readAnyPasswordLoginSettings($anyPasswordSettingsFile);
$rows = readCsvRows($mappedFile);
if ($rows) {
  $header = $rows[0] ?? [];
  $workIdIndex = resolveMappedInviteColumnIndex($header, $mapping, 'workId', ['work id', 'username', 'user name']);
  $firstNameIndex = resolveMappedInviteColumnIndex($header, $mapping, 'firstName', ['first name', 'name']);
  $lastNameIndex = resolveMappedInviteColumnIndex($header, $mapping, 'lastName', ['last name', 'family', 'surname']);
  $nationalIdIndex = resolveMappedInviteColumnIndex($header, $mapping, 'nationalId', ['national id']);
  $phoneNumberIndex = resolveMappedInviteColumnIndex($header, $mapping, 'phoneNumber', ['phone number', 'phone', 'mobile']);
  $passwordIndex = findInviteHeaderIndex($header, ['password']);
  $stats['total'] = max(0, count($rows) - 1);
  $stats['columns'] = [
    'workId' => ($workIdIndex >= 0 && isset($header[$workIdIndex])) ? (string)$header[$workIdIndex] : '',
    'firstName' => ($firstNameIndex >= 0 && isset($header[$firstNameIndex])) ? (string)$header[$firstNameIndex] : '',
    'lastName' => ($lastNameIndex >= 0 && isset($header[$lastNameIndex])) ? (string)$header[$lastNameIndex] : '',
    'nationalId' => ($nationalIdIndex >= 0 && isset($header[$nationalIdIndex])) ? (string)$header[$nationalIdIndex] : '',
    'phoneNumber' => ($phoneNumberIndex >= 0 && isset($header[$phoneNumberIndex])) ? (string)$header[$phoneNumberIndex] : ''
  ];

  $seen = [
    'workId' => [],
    'nationalId' => [],
    'phoneNumber' => []
  ];

  for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    $first = trim((string)($row[$firstNameIndex] ?? ''));
    $last = trim((string)($row[$lastNameIndex] ?? ''));
    $full = trim($first . ' ' . $last);
    $rowNumber = $i + 1;
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    $nationalId = trim((string)($row[$nationalIdIndex] ?? ''));
    $phoneNumber = trim((string)($row[$phoneNumberIndex] ?? ''));
    $password = trim((string)($row[$passwordIndex] ?? ''));

    if ($workId !== '' || $nationalId !== '' || $phoneNumber !== '' || $first !== '' || $last !== '') {
      $allInvitees[] = [
        'row' => $rowNumber,
        'firstName' => $first,
        'lastName' => $last,
        'workId' => $workId,
        'nationalId' => $nationalId,
        'phoneNumber' => $phoneNumber,
        'password' => $password
      ];
    }

    $fields = [
      'workId' => $workId,
      'nationalId' => $nationalId,
      'phoneNumber' => $phoneNumber
    ];

    foreach ($fields as $key => $value) {
      if ($value === '') {
        continue;
      }
      if (isset($seen[$key][$value])) {
        $stats['conflicts'][$key][] = [
          'row' => $rowNumber,
          'name' => $full !== '' ? $full : 'نامشخص',
          'value' => $value
        ];
      } else {
        $seen[$key][$value] = $rowNumber;
      }
    }
  }
}
?>

<div class="tc-task-top-shell" id="tc-invitees-top-shell">
  <div class="tc-task-top-nav" role="tablist" aria-label="Invitees Tabs">
    <button type="button" class="tc-task-top-item active" data-invitees-top-trigger="all-invitees" aria-selected="true">All Invitees</button>
    <?php if ($tcInviteesCanManage): ?>
    <button type="button" class="tc-task-top-item" data-invitees-top-trigger="manage-invitees" aria-selected="false">Manage Invitees</button>
    <?php endif; ?>
  </div>
  <div class="tc-task-top-section active" data-invitees-top-section="all-invitees">
    <div class="card">
      <div class="section-header">
        <h3>All Invitees</h3>
      </div>
      <div class="form" style="gap:12px;">
        <div class="field">
          <span>Total Invitees</span>
          <strong><?= htmlspecialchars((string)count($allInvitees), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <label class="field standard-width">
          <span>Search Invitee</span>
          <input
            id="tc-all-invitees-search"
            type="text"
            placeholder="Search by name, work ID, national ID, or phone number"
            autocomplete="off"
          />
          <small id="tc-all-invitees-search-meta" class="hint"></small>
        </label>
        <div class="table-wrapper tc-info-rate-table-wrap">
          <table class="tct-list-table tc-info-rate-table">
            <thead>
              <tr>
                <th>Row</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Work ID</th>
                <th>National ID</th>
                <th>Phone Number</th>
                <th>Password</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody data-invitees-all-table-body>
              <?php if (!$allInvitees): ?>
                <tr>
                  <td colspan="8" class="muted">No invitees found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($allInvitees as $invitee): ?>
                  <?php
                    $inviteeSearchText = trim(implode(' ', [
                      (string)($invitee['row'] ?? ''),
                      (string)($invitee['firstName'] ?? ''),
                      (string)($invitee['lastName'] ?? ''),
                      (string)($invitee['workId'] ?? ''),
                      (string)($invitee['nationalId'] ?? ''),
                      (string)($invitee['phoneNumber'] ?? '')
                    ]));
                  ?>
                  <tr data-invitee-row="1" data-search="<?= htmlspecialchars($inviteeSearchText, ENT_QUOTES, 'UTF-8') ?>">
                    <td><?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['firstName'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['lastName'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['nationalId'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['phoneNumber'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['password'] ?? '') !== '' ? '*****' : '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <div class="tc-info-rate-row-actions">
                        <?php if ($tcInviteesCanEdit): ?>
                        <button
                          type="button"
                          class="btn ghost"
                          data-action="edit-invitee"
                          data-row="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-first-name="<?= htmlspecialchars((string)($invitee['firstName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-last-name="<?= htmlspecialchars((string)($invitee['lastName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-work-id="<?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-national-id="<?= htmlspecialchars((string)($invitee['nationalId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-phone-number="<?= htmlspecialchars((string)($invitee['phoneNumber'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >Edit</button>
                        <?php endif; ?>
                        <?php if ($tcInviteesCanReveal): ?>
                        <button
                          type="button"
                          class="btn ghost"
                          data-action="reveal-invitee-password"
                          data-row="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-display-name="<?= htmlspecialchars(trim(((string)($invitee['firstName'] ?? '')) . ' ' . ((string)($invitee['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                          data-work-id="<?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >Reveal Password</button>
                        <?php endif; ?>
                        <?php if ($tcInviteesCanReset): ?>
                        <button
                          type="button"
                          class="btn ghost"
                          data-action="reset-invitee-progress"
                          data-row="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-display-name="<?= htmlspecialchars(trim(((string)($invitee['firstName'] ?? '')) . ' ' . ((string)($invitee['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                          data-work-id="<?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >Rest</button>
                        <?php endif; ?>
                        <button
                          type="button"
                          class="btn ghost"
                          data-action="view-invitee-participation"
                          data-row="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-display-name="<?= htmlspecialchars(trim(((string)($invitee['firstName'] ?? '')) . ' ' . ((string)($invitee['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                          data-work-id="<?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >Participate</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr data-invitees-no-results hidden>
                  <td colspan="8" class="muted">No matching invitee found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php if ($tcInviteesCanManage): ?>
  <div class="tc-task-top-section" data-invitees-top-section="manage-invitees" hidden>
<div class="card">
  <div class="section-header">
    <h3>Insert Invite List</h3>
  </div>
  <div class="form">
    <div class="field standard-width">
      <span>Insert Excel File</span>
      <div class="field-block" style="padding:10px;">
        <input id="tc-invite-file" type="file" accept=".csv,.xls,.xlsx" hidden />
        <div class="field-controls" style="gap:8px;">
          <button type="button" class="btn" id="tc-invite-pick">Insert Excel File</button>
          <div id="tc-invite-file-name" class="muted">No file selected.</div>
        </div>
      </div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="tc-invite-map">Map and Upload</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Any Password Login</h3>
  </div>
  <div class="form grid two-columns">
    <label class="field">
      <span>Min Length</span>
      <input id="tc-any-password-min-length" type="number" min="1" max="128" step="1" value="<?= htmlspecialchars((string)$anyPasswordLoginSettings['minLength'], ENT_QUOTES, 'UTF-8') ?>" />
    </label>
    <label class="field checkbox-field">
      <span>Any Password</span>
      <input id="tc-any-password-enabled" type="checkbox" <?= !empty($anyPasswordLoginSettings['anyPassword']) ? 'checked' : '' ?> />
    </label>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="tc-any-password-save">Save</button>
      <p id="tc-any-password-msg" class="hint" aria-live="polite"></p>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Add Invitee</h3>
  </div>
  <div class="form grid two-columns">
    <label class="field">
      <span>Work ID</span>
      <input id="tc-add-work-id" type="text" placeholder="Work ID" />
    </label>
    <label class="field">
      <span>First Name</span>
      <input id="tc-add-first-name" type="text" placeholder="First Name" />
    </label>
    <label class="field">
      <span>Last Name</span>
      <input id="tc-add-last-name" type="text" placeholder="Last Name" />
    </label>
    <label class="field">
      <span>National ID</span>
      <input id="tc-add-national-id" type="text" placeholder="National ID" />
    </label>
    <label class="field">
      <span>Phone Number</span>
      <input id="tc-add-phone-number" type="text" placeholder="Phone Number" />
    </label>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="tc-add-invitee-btn">Add Invitee</button>
      <p id="tc-add-invitee-msg" class="hint" aria-live="polite"></p>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Stats</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div class="field">
      <span>Total Invitees</span>
      <strong><?= htmlspecialchars((string)$stats['total'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div class="field">
      <span>Mapped Columns</span>
      <div class="field-block">
        <div class="muted">Work ID: <?= htmlspecialchars($stats['columns']['workId'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">First Name: <?= htmlspecialchars($stats['columns']['firstName'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">Last Name: <?= htmlspecialchars($stats['columns']['lastName'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">National ID: <?= htmlspecialchars($stats['columns']['nationalId'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
        <div class="muted">Phone Number: <?= htmlspecialchars($stats['columns']['phoneNumber'] ?: '—', ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <div class="field">
      <span>Conflicts</span>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Row</th>
              <th>Full Name</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $conflictRows = [];
            foreach (['workId' => 'Work ID', 'nationalId' => 'National ID', 'phoneNumber' => 'Phone Number'] as $key => $label) {
              foreach ($stats['conflicts'][$key] as $item) {
                $conflictRows[] = [
                  'type' => $label,
                  'row' => $item['row'],
                  'name' => $item['name'],
                  'value' => $item['value']
                ];
              }
            }
            if (!$conflictRows): ?>
              <tr>
                <td colspan="4" class="muted">No conflicts found.</td>
              </tr>
            <?php else:
              foreach ($conflictRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)$row['row'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
  </div>
  <?php endif; ?>
</div>

<div id="tc-invite-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Map Columns</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-modal aria-label="Close">×</button>
    </div>
    <div id="tc-invite-progress" class="modal-progress hidden" role="status" aria-live="polite">
      <div class="loader-ring" aria-hidden="true">
        <span></span>
        <span></span>
      </div>
      <p class="modal-progress__message" data-invite-progress-message>در حال آماده‌سازی...</p>
    </div>
    <div class="modal-card-body">
      <div class="form grid one-column">
        <label class="field">
          <span>Work ID</span>
          <select id="tc-map-work"></select>
        </label>
        <label class="field">
          <span>First Name</span>
          <select id="tc-map-first"></select>
        </label>
        <label class="field">
          <span>Last Name</span>
          <select id="tc-map-last"></select>
        </label>
        <label class="field">
          <span>National ID</span>
          <select id="tc-map-national"></select>
        </label>
        <label class="field">
          <span>Phone Number</span>
          <select id="tc-map-phone"></select>
        </label>
      </div>
      <p id="tc-invite-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-modal>Cancel</button>
      <button type="button" class="btn primary" id="tc-invite-upload">Upload</button>
    </div>
</div>
</div>

<div id="tc-invite-edit-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Edit Invitee</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-edit-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <div class="form grid two-columns">
        <label class="field">
          <span>Work ID</span>
          <input id="tc-edit-work-id" type="text" />
        </label>
        <label class="field">
          <span>First Name</span>
          <input id="tc-edit-first-name" type="text" />
        </label>
        <label class="field">
          <span>Last Name</span>
          <input id="tc-edit-last-name" type="text" />
        </label>
        <label class="field">
          <span>National ID</span>
          <input id="tc-edit-national-id" type="text" />
        </label>
        <label class="field">
          <span>Phone Number</span>
          <input id="tc-edit-phone-number" type="text" />
        </label>
      </div>
      <p id="tc-edit-invitee-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-edit-modal>Cancel</button>
      <button type="button" class="btn primary" id="tc-edit-invitee-save">Save</button>
    </div>
  </div>
</div>

<div id="tc-invite-auth-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Password Confirmation</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-auth-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <div class="form">
        <label class="field">
          <span>Enter your panel account password</span>
          <input id="tc-invite-auth-password" type="password" autocomplete="current-password" />
        </label>
      </div>
      <p id="tc-invite-auth-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-auth-modal>Cancel</button>
      <button type="button" class="btn primary" id="tc-invite-auth-submit">Verify</button>
    </div>
  </div>
</div>

<div id="tc-invite-password-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3 id="tc-invite-password-title">Invitee Password</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-password-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <div class="form">
        <label class="field">
          <span>Password</span>
          <input id="tc-invite-password-value" type="text" autocomplete="off" />
        </label>
      </div>
      <p id="tc-invite-password-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-password-modal>Close</button>
      <button type="button" class="btn primary" id="tc-invite-password-save">Save New Password</button>
    </div>
  </div>
</div>

<div id="tc-invite-reset-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Reset Invitee Progress</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-reset-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <p id="tc-invite-reset-text" class="muted">Are you sure you want to reset this invitee progress?</p>
      <p id="tc-invite-reset-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-reset-modal>Cancel</button>
      <button type="button" class="btn primary" id="tc-invite-reset-confirm">Confirm</button>
    </div>
  </div>
</div>

<div id="tc-invite-participation-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card tc-participation-modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3 id="tc-invite-participation-title">Invitee Participation</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-participation-modal aria-label="Close">&times;</button>
    </div>
    <div class="modal-card-body">
      <div id="tc-invite-participation-summary" class="tc-participation-summary"></div>
      <p id="tc-invite-participation-msg" class="hint" aria-live="polite"></p>
      <div id="tc-invite-participation-tasks" class="tc-participation-tasks"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-participation-modal>Close</button>
    </div>
  </div>
</div>

<script src="mini%20apps/Task%20Club/vendor/xlsx/xlsx.full.min.js" defer></script>
<script>
(() => {
  const csrfToken = <?= json_encode($tcInviteesCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const canResetInviteeTasks = <?= $tcInviteesCanReset ? 'true' : 'false' ?>;
  const pickBtn = document.getElementById('tc-invite-pick');
  const fileInput = document.getElementById('tc-invite-file');
  const fileNameEl = document.getElementById('tc-invite-file-name');
  const mapBtn = document.getElementById('tc-invite-map');
  const modal = document.getElementById('tc-invite-modal');
  const closeBtns = modal ? modal.querySelectorAll('[data-close-invite-modal]') : [];
  const mapWork = document.getElementById('tc-map-work');
  const mapFirst = document.getElementById('tc-map-first');
  const mapLast = document.getElementById('tc-map-last');
  const mapNational = document.getElementById('tc-map-national');
  const mapPhone = document.getElementById('tc-map-phone');
  const uploadBtn = document.getElementById('tc-invite-upload');
  const msgEl = document.getElementById('tc-invite-msg');
  const progressEl = document.getElementById('tc-invite-progress');
  const progressMsg = progressEl?.querySelector('[data-invite-progress-message]');
  const addWorkIdEl = document.getElementById('tc-add-work-id');
  const addFirstNameEl = document.getElementById('tc-add-first-name');
  const addLastNameEl = document.getElementById('tc-add-last-name');
  const addNationalIdEl = document.getElementById('tc-add-national-id');
  const addPhoneNumberEl = document.getElementById('tc-add-phone-number');
  const addInviteeBtn = document.getElementById('tc-add-invitee-btn');
  const addInviteeMsgEl = document.getElementById('tc-add-invitee-msg');
  const anyPasswordEnabledEl = document.getElementById('tc-any-password-enabled');
  const anyPasswordMinLengthEl = document.getElementById('tc-any-password-min-length');
  const anyPasswordSaveBtn = document.getElementById('tc-any-password-save');
  const anyPasswordMsgEl = document.getElementById('tc-any-password-msg');
  const editModal = document.getElementById('tc-invite-edit-modal');
  const editCloseBtns = editModal ? editModal.querySelectorAll('[data-close-invite-edit-modal]') : [];
  const editWorkIdEl = document.getElementById('tc-edit-work-id');
  const editFirstNameEl = document.getElementById('tc-edit-first-name');
  const editLastNameEl = document.getElementById('tc-edit-last-name');
  const editNationalIdEl = document.getElementById('tc-edit-national-id');
  const editPhoneNumberEl = document.getElementById('tc-edit-phone-number');
  const editSaveBtn = document.getElementById('tc-edit-invitee-save');
  const editMsgEl = document.getElementById('tc-edit-invitee-msg');
  const authModal = document.getElementById('tc-invite-auth-modal');
  const authCloseBtns = authModal ? authModal.querySelectorAll('[data-close-invite-auth-modal]') : [];
  const authPasswordEl = document.getElementById('tc-invite-auth-password');
  const authSubmitBtn = document.getElementById('tc-invite-auth-submit');
  const authMsgEl = document.getElementById('tc-invite-auth-msg');
  const passwordModal = document.getElementById('tc-invite-password-modal');
  const passwordCloseBtns = passwordModal ? passwordModal.querySelectorAll('[data-close-invite-password-modal]') : [];
  const passwordTitleEl = document.getElementById('tc-invite-password-title');
  const passwordValueEl = document.getElementById('tc-invite-password-value');
  const passwordSaveBtn = document.getElementById('tc-invite-password-save');
  const passwordMsgEl = document.getElementById('tc-invite-password-msg');
  const resetModal = document.getElementById('tc-invite-reset-modal');
  const resetCloseBtns = resetModal ? resetModal.querySelectorAll('[data-close-invite-reset-modal]') : [];
  const resetTextEl = document.getElementById('tc-invite-reset-text');
  const resetMsgEl = document.getElementById('tc-invite-reset-msg');
  const resetConfirmBtn = document.getElementById('tc-invite-reset-confirm');
  const participationModal = document.getElementById('tc-invite-participation-modal');
  const participationCloseBtns = participationModal ? participationModal.querySelectorAll('[data-close-invite-participation-modal]') : [];
  const participationTitleEl = document.getElementById('tc-invite-participation-title');
  const participationSummaryEl = document.getElementById('tc-invite-participation-summary');
  const participationMsgEl = document.getElementById('tc-invite-participation-msg');
  const participationTasksEl = document.getElementById('tc-invite-participation-tasks');
  const inviteesTopShell = document.getElementById('tc-invitees-top-shell');
  const allInviteesSearchInput = document.getElementById('tc-all-invitees-search');
  const allInviteesSearchMetaEl = document.getElementById('tc-all-invitees-search-meta');
  const allInviteesTableBody = document.querySelector('[data-invitees-all-table-body]');

  let parsedRows = [];
  let headerRow = [];
  let editingInviteeRow = 0;
  let revealPasswordContext = null;
  let resetProgressContext = null;
  let scoreEditContext = null;
  let participationContext = null;
  let pendingSensitiveAction = '';

  const normalizeSearchValue = (value) => String(value || '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim();

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const formatStatValue = (value) => {
    const text = String(value ?? '').trim();
    return text !== '' ? text : '-';
  };

  const applyAllInviteesSearch = () => {
    if (!(allInviteesTableBody instanceof HTMLElement)) return;
    const inviteeRows = Array.from(allInviteesTableBody.querySelectorAll('tr[data-invitee-row="1"]'));
    if (!inviteeRows.length) return;
    const noResultsRow = allInviteesTableBody.querySelector('tr[data-invitees-no-results]');
    const query = normalizeSearchValue(allInviteesSearchInput?.value || '');

    let visibleCount = 0;
    inviteeRows.forEach((row) => {
      const haystack = normalizeSearchValue(row.getAttribute('data-search') || row.textContent || '');
      const isVisible = query === '' || haystack.includes(query);
      row.hidden = !isVisible;
      if (isVisible) {
        visibleCount += 1;
      }
    });

    if (noResultsRow instanceof HTMLElement) {
      noResultsRow.hidden = visibleCount > 0 || query === '';
    }
    if (allInviteesSearchMetaEl instanceof HTMLElement) {
      if (query === '') {
        allInviteesSearchMetaEl.textContent = `Showing ${visibleCount} invitees`;
      } else if (visibleCount === 0) {
        allInviteesSearchMetaEl.textContent = 'No match found';
      } else {
        allInviteesSearchMetaEl.textContent = `${visibleCount} result${visibleCount === 1 ? '' : 's'} found`;
      }
    }
  };

  const setMsg = (text, isError = false) => {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setAddMsg = (text, isError = false) => {
    if (!addInviteeMsgEl) return;
    addInviteeMsgEl.textContent = text;
    addInviteeMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setAnyPasswordMsg = (text, isError = false) => {
    if (!anyPasswordMsgEl) return;
    anyPasswordMsgEl.textContent = text;
    anyPasswordMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setEditMsg = (text, isError = false) => {
    if (!editMsgEl) return;
    editMsgEl.textContent = text;
    editMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setAuthMsg = (text, isError = false) => {
    if (!authMsgEl) return;
    authMsgEl.textContent = text;
    authMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setPasswordMsg = (text, isError = false) => {
    if (!passwordMsgEl) return;
    passwordMsgEl.textContent = text;
    passwordMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setResetMsg = (text, isError = false) => {
    if (!resetMsgEl) return;
    resetMsgEl.textContent = text;
    resetMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const setParticipationMsg = (text, isError = false) => {
    if (!participationMsgEl) return;
    participationMsgEl.textContent = text;
    participationMsgEl.style.color = isError ? '#e11d2e' : '';
  };

  const openModal = () => {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
  };

  const showProgress = (message) => {
    if (!progressEl) return;
    if (progressMsg) progressMsg.textContent = message || 'در حال آماده‌سازی...';
    progressEl.classList.remove('hidden');
  };

  const hideProgress = () => {
    if (!progressEl) return;
    progressEl.classList.add('hidden');
  };

  const closeModal = () => {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    setMsg('');
    hideProgress();
  };

  const openEditModal = () => {
    if (!editModal) return;
    editModal.classList.remove('hidden');
    editModal.setAttribute('aria-hidden', 'false');
  };

  const closeEditModal = () => {
    if (!editModal) return;
    editModal.classList.add('hidden');
    editModal.setAttribute('aria-hidden', 'true');
    setEditMsg('');
    editingInviteeRow = 0;
  };

  const openAuthModal = () => {
    if (!authModal) return;
    authModal.classList.remove('hidden');
    authModal.setAttribute('aria-hidden', 'false');
    setAuthMsg('');
    if (authPasswordEl) {
      authPasswordEl.value = '';
      authPasswordEl.focus();
    }
  };

  const closeAuthModal = () => {
    if (!authModal) return;
    authModal.classList.add('hidden');
    authModal.setAttribute('aria-hidden', 'true');
    setAuthMsg('');
    if (authPasswordEl) {
      authPasswordEl.value = '';
    }
  };

  const openPasswordModal = () => {
    if (!passwordModal) return;
    passwordModal.classList.remove('hidden');
    passwordModal.setAttribute('aria-hidden', 'false');
    setPasswordMsg('');
  };

  const closePasswordModal = () => {
    if (!passwordModal) return;
    passwordModal.classList.add('hidden');
    passwordModal.setAttribute('aria-hidden', 'true');
    setPasswordMsg('');
    if (passwordValueEl) {
      passwordValueEl.value = '';
    }
    revealPasswordContext = null;
  };

  const openResetModal = () => {
    if (!resetModal) return;
    resetModal.classList.remove('hidden');
    resetModal.setAttribute('aria-hidden', 'false');
    setResetMsg('');
  };

  const closeResetModal = () => {
    if (!resetModal) return;
    resetModal.classList.add('hidden');
    resetModal.setAttribute('aria-hidden', 'true');
    setResetMsg('');
    resetProgressContext = null;
  };

  const openParticipationModal = () => {
    if (!participationModal) return;
    participationModal.classList.remove('hidden');
    participationModal.setAttribute('aria-hidden', 'false');
    setParticipationMsg('');
  };

  const closeParticipationModal = () => {
    if (!participationModal) return;
    participationModal.classList.add('hidden');
    participationModal.setAttribute('aria-hidden', 'true');
    setParticipationMsg('');
    if (participationSummaryEl) participationSummaryEl.innerHTML = '';
    if (participationTasksEl) participationTasksEl.innerHTML = '';
    scoreEditContext = null;
    participationContext = null;
  };

  const postRevealAction = async (action, payload = {}) => {
    const response = await fetch('mini%20apps/Task%20Club/invitees_password_guard.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf: csrfToken,
        action,
        ...payload
      })
    });
    const result = await response.json().catch(() => ({}));
    return {
      ok: response.ok && result?.status === 'ok',
      status: String(result?.status || ''),
      message: String(result?.message || ''),
      data: result
    };
  };

  const renderParticipationLoading = () => {
    if (participationSummaryEl) {
      participationSummaryEl.innerHTML = '<div class="muted">Loading participation...</div>';
    }
    if (participationTasksEl) {
      participationTasksEl.innerHTML = '';
    }
    setParticipationMsg('');
  };

  const renderParticipationStats = (participation) => {
    const data = participation && typeof participation === 'object' ? participation : {};
    const invitee = data.invitee && typeof data.invitee === 'object' ? data.invitee : {};
    const summary = data.summary && typeof data.summary === 'object' ? data.summary : {};
    const tasks = Array.isArray(data.tasks) ? data.tasks : [];
    const displayName = String(invitee.displayName || invitee.workId || '').trim();

    if (participationTitleEl) {
      participationTitleEl.textContent = displayName !== '' ? `Invitee Participation - ${displayName}` : 'Invitee Participation';
    }

    if (participationSummaryEl) {
      const summaryCards = [
        ['Total Score', summary.totalScore ?? 0],
        ['Completed Tasks', `${summary.completedTasks ?? 0} / ${summary.taskCount ?? tasks.length}`],
        ['Started Tasks', summary.startedTasks ?? 0],
        ['Login Count', summary.loginCount ?? 0],
        ['Roll Count', summary.rollCount ?? 0],
        ['Card Flips', summary.cardFlipsCount ?? 0],
        ['Prize Won', summary.prizeWon || '-'],
        ['Total Prize Won', summary.totalPrizeWon || '-']
      ];
      const extraRows = [
        ['Work ID', invitee.workId || '-'],
        ['Phone Number', invitee.phoneNumber || '-'],
        ['National ID', invitee.nationalId || '-'],
        ['Logins', summary.logins || '-'],
        ['Prize Won At', summary.prizeWonAt || '-'],
        ['Out of Value Rewards', summary.outOfValueRewards || '-'],
        ['Level Prizes', summary.eachLevelWonPrize || '-'],
        ['Legacy Answered', summary.legacyAnswered || '-'],
        ['Legacy Answers', summary.legacyAnswers || '-']
      ];
      participationSummaryEl.innerHTML = `
        <div class="tc-participation-stat-grid">
          ${summaryCards.map(([label, value]) => `
            <div class="tc-participation-stat">
              <span>${escapeHtml(label)}</span>
              <strong>${escapeHtml(formatStatValue(value))}</strong>
            </div>
          `).join('')}
        </div>
        <div class="tc-participation-meta-grid">
          ${extraRows.map(([label, value]) => `
            <div class="tc-participation-meta-row">
              <span>${escapeHtml(label)}</span>
              <strong>${escapeHtml(formatStatValue(value))}</strong>
            </div>
          `).join('')}
        </div>
      `;
    }

    if (!participationTasksEl) return;
    if (!tasks.length) {
      participationTasksEl.innerHTML = '<div class="muted">No task activity is available.</div>';
      return;
    }

    const renderAnswers = (task) => {
      const answers = Array.isArray(task.answers) ? task.answers : [];
      if (!answers.length) return '';
      return `
        <details class="tc-participation-answer-details">
          <summary>${escapeHtml(String(answers.length))} saved answer${answers.length === 1 ? '' : 's'}</summary>
          <div class="tc-participation-answer-list">
            ${answers.map((answer) => {
              const correct = answer.correct === true ? 'Correct' : (answer.correct === false ? 'Incorrect' : '');
              return `
                <div class="tc-participation-answer-row">
                  <span>${escapeHtml(answer.question || answer.code || 'Question')}</span>
                  <strong>${escapeHtml(answer.answer || '-')}</strong>
                  ${correct ? `<em>${escapeHtml(correct)}</em>` : ''}
                </div>
              `;
            }).join('')}
          </div>
        </details>
      `;
    };

    const renderDescribeStats = (task) => {
      const stats = task.describePhotoStats && typeof task.describePhotoStats === 'object'
        ? task.describePhotoStats
        : null;
      if (!stats || !Array.isArray(stats.photos) || !stats.photos.length) return '';
      return `
        <details class="tc-participation-answer-details">
          <summary>${escapeHtml(String(stats.photos.length))} photo item${stats.photos.length === 1 ? '' : 's'}</summary>
          <div class="tc-participation-answer-list">
            ${stats.photos.map((photo) => `
              <div class="tc-participation-answer-row">
                <span>${escapeHtml(photo.photoName || photo.photoId || 'Photo')}</span>
                <strong>${escapeHtml(`${photo.wordCount ?? 0} words`)}</strong>
              </div>
            `).join('')}
          </div>
        </details>
      `;
    };

    participationTasksEl.innerHTML = `
      <div class="table-wrapper tc-participation-table-wrap">
        <table class="tct-list-table tc-participation-task-table">
          <thead>
            <tr>
              <th>Task</th>
              <th>Type</th>
              <th>Status</th>
              <th>Score</th>
              <th>Activity</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            ${tasks.map((task) => {
              const taskId = String(task.id || '').trim();
              const details = Array.isArray(task.details) ? task.details : [];
              const possibleScore = task.possibleScore ?? task.configuredScore ?? 0;
              const score = task.scoreLabel || `${task.score ?? 0} / ${possibleScore}`;
              const canManageTask = canResetInviteeTasks && taskId !== '';
              const taskTitle = task.title || taskId || 'Untitled Task';
              const currentScore = Math.max(0, Number.parseInt(String(task.score ?? 0), 10) || 0);
              return `
                <tr>
                  <td>
                    <div class="tc-participation-task-title">${escapeHtml(taskTitle)}</div>
                    <div class="muted small">${escapeHtml(task.tagCode || taskId || '')}</div>
                  </td>
                  <td>${escapeHtml(task.typeLabel || task.taskType || '-')}</td>
                  <td><span class="tc-participation-status tc-participation-status--${escapeHtml(task.status || 'not_started')}">${escapeHtml(task.statusLabel || '-')}</span></td>
                  <td>${escapeHtml(score)}</td>
                  <td>
                    ${details.length ? `<div class="tc-participation-detail-list">${details.map((detail) => `<span>${escapeHtml(detail)}</span>`).join('')}</div>` : '<span class="muted">No saved activity.</span>'}
                    ${renderAnswers(task)}
                    ${renderDescribeStats(task)}
                  </td>
                  <td>
                    ${canManageTask ? `
                      <div class="tc-participation-action-stack">
                        <div class="tc-participation-score-edit">
                          <input type="number" min="0" step="1" inputmode="numeric" value="${escapeHtml(String(currentScore))}" data-task-score-input data-task-id="${escapeHtml(taskId)}" aria-label="Task score" />
                          <button type="button" class="btn ghost" data-action="save-invitee-task-score" data-task-id="${escapeHtml(taskId)}" data-task-title="${escapeHtml(taskTitle)}">Save</button>
                        </div>
                        <button type="button" class="btn ghost tc-btn-danger" data-action="reset-invitee-task-progress" data-task-id="${escapeHtml(taskId)}" data-task-title="${escapeHtml(taskTitle)}">Reset</button>
                      </div>
                    ` : '<span class="muted">-</span>'}
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  };

  const loadParticipationStats = async () => {
    if (!participationContext || !Number.isFinite(participationContext.row) || participationContext.row <= 1) {
      setParticipationMsg('Invalid invitee row.', true);
      return;
    }
    renderParticipationLoading();
    try {
      const response = await postRevealAction('get_participation', { row: participationContext.row });
      if (response.ok) {
        renderParticipationStats(response.data?.participation || {});
      } else {
        setParticipationMsg(response.message || 'Failed to load participation.', true);
        if (participationSummaryEl) participationSummaryEl.innerHTML = '';
        if (participationTasksEl) participationTasksEl.innerHTML = '';
      }
    } catch {
      setParticipationMsg('Failed to load participation.', true);
    }
  };

  const saveTaskScore = async () => {
    if (!scoreEditContext || !Number.isFinite(scoreEditContext.row) || scoreEditContext.row <= 1) {
      setParticipationMsg('Invalid invitee row.', true);
      return;
    }
    const taskId = String(scoreEditContext.taskId || '').trim();
    const score = Math.max(0, Number.parseInt(String(scoreEditContext.score ?? 0), 10) || 0);
    if (!taskId) {
      setParticipationMsg('Invalid task id.', true);
      return;
    }
    const button = scoreEditContext.button;
    if (button instanceof HTMLButtonElement) {
      button.disabled = true;
    }
    setParticipationMsg('Saving task score...');
    try {
      const response = await postRevealAction('save_task_score', {
        row: scoreEditContext.row,
        task_id: taskId,
        score
      });
      if (response.ok) {
        pendingSensitiveAction = '';
        if (response.data?.participation) {
          renderParticipationStats(response.data.participation);
        } else {
          await loadParticipationStats();
        }
        setParticipationMsg(response.message || 'Task score updated.');
      } else if (response.status === 'auth_required') {
        setParticipationMsg('Authorization required. Please verify your panel password.', true);
        pendingSensitiveAction = 'save_task_score';
        openAuthModal();
      } else {
        setParticipationMsg(response.message || 'Failed to save task score.', true);
      }
    } catch {
      setParticipationMsg('Failed to save task score.', true);
    } finally {
      if (button instanceof HTMLButtonElement && button.isConnected) {
        button.disabled = false;
      }
    }
  };

  const requestRevealPassword = async () => {
    if (!revealPasswordContext || !Number.isFinite(revealPasswordContext.row) || revealPasswordContext.row <= 1) {
      setPasswordMsg('Invalid invitee row.', true);
      return;
    }
    const response = await postRevealAction('get_password', { row: revealPasswordContext.row });
    if (response.ok) {
      const currentPassword = String(response.data?.password ?? '');
      if (passwordValueEl) {
        passwordValueEl.value = currentPassword;
      }
      const label = String(revealPasswordContext.displayName || revealPasswordContext.workId || '').trim();
      if (passwordTitleEl) {
        passwordTitleEl.textContent = label !== '' ? `Invitee Password - ${label}` : 'Invitee Password';
      }
      setPasswordMsg('');
      openPasswordModal();
      return;
    }
    if (response.status === 'auth_required') {
      pendingSensitiveAction = 'reveal';
      openAuthModal();
      return;
    }
    setPasswordMsg(response.message || 'Failed to reveal password.', true);
  };

  const requestResetAccess = async () => {
    if (!resetProgressContext || !Number.isFinite(resetProgressContext.row) || resetProgressContext.row <= 1) {
      setResetMsg('Invalid invitee row.', true);
      return;
    }
    const check = await postRevealAction('check_unlock');
    if (check.ok) {
      const label = String(resetProgressContext.displayName || resetProgressContext.workId || '').trim();
      if (resetTextEl) {
        const suffix = label !== '' ? ` (${label})` : '';
        const taskTitle = String(resetProgressContext.taskTitle || '').trim();
        resetTextEl.textContent = taskTitle !== ''
          ? `This action will reset the task "${taskTitle}" for this invitee${suffix}.`
          : `This action will reset logins, score, mission progress, and rewards for this invitee${suffix}.`;
      }
      setResetMsg('');
      openResetModal();
      return;
    }
    if (check.status === 'auth_required') {
      pendingSensitiveAction = resetProgressContext.taskId ? 'reset_task' : 'reset';
      openAuthModal();
      return;
    }
    setResetMsg(check.message || 'Failed to authorize reset action.', true);
  };

  const activateInviteesPane = (paneKey) => {
    if (!(inviteesTopShell instanceof HTMLElement)) return;
    const targetPane = String(paneKey || '').trim();
    if (!targetPane) return;
    inviteesTopShell.querySelectorAll('[data-invitees-top-trigger]').forEach((button) => {
      if (!(button instanceof HTMLElement)) return;
      const isActive = button.getAttribute('data-invitees-top-trigger') === targetPane;
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    inviteesTopShell.querySelectorAll('[data-invitees-top-section]').forEach((section) => {
      if (!(section instanceof HTMLElement)) return;
      const isActive = section.getAttribute('data-invitees-top-section') === targetPane;
      section.classList.toggle('active', isActive);
      section.hidden = !isActive;
    });
  };

  if (inviteesTopShell instanceof HTMLElement) {
    inviteesTopShell.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const trigger = target.closest('[data-invitees-top-trigger]');
      if (!(trigger instanceof HTMLElement)) return;
      const paneKey = String(trigger.getAttribute('data-invitees-top-trigger') || '').trim();
      if (!paneKey) return;
      event.preventDefault();
      activateInviteesPane(paneKey);
    });
    activateInviteesPane('all-invitees');
  }

  allInviteesSearchInput?.addEventListener('input', () => {
    applyAllInviteesSearch();
  });
  applyAllInviteesSearch();

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const participationTrigger = target.closest('[data-action="view-invitee-participation"]');
    if (participationTrigger instanceof HTMLElement) {
      const rowValue = Number(participationTrigger.getAttribute('data-row') || '0');
      if (!Number.isFinite(rowValue) || rowValue <= 1) {
        return;
      }
      participationContext = {
        row: Math.trunc(rowValue),
        displayName: String(participationTrigger.getAttribute('data-display-name') || '').trim(),
        workId: String(participationTrigger.getAttribute('data-work-id') || '').trim()
      };
      openParticipationModal();
      void loadParticipationStats();
      return;
    }

    const saveScoreTrigger = target.closest('[data-action="save-invitee-task-score"]');
    if (saveScoreTrigger instanceof HTMLButtonElement) {
      if (!participationContext || !Number.isFinite(participationContext.row) || participationContext.row <= 1) {
        setParticipationMsg('Invalid invitee row.', true);
        return;
      }
      const taskId = String(saveScoreTrigger.getAttribute('data-task-id') || '').trim();
      if (!taskId) {
        setParticipationMsg('Invalid task id.', true);
        return;
      }
      const row = saveScoreTrigger.closest('tr');
      const input = row instanceof HTMLElement
        ? Array.from(row.querySelectorAll('input[data-task-score-input]'))
          .find((candidate) => candidate instanceof HTMLInputElement && String(candidate.getAttribute('data-task-id') || '').trim() === taskId)
        : null;
      const rawScore = input instanceof HTMLInputElement ? String(input.value || '').trim() : '';
      if (rawScore === '' || !/^\d+$/.test(rawScore)) {
        setParticipationMsg('Score must be a non-negative whole number.', true);
        return;
      }
      scoreEditContext = {
        row: participationContext.row,
        displayName: participationContext.displayName,
        workId: participationContext.workId,
        taskId,
        taskTitle: String(saveScoreTrigger.getAttribute('data-task-title') || '').trim(),
        score: Math.max(0, Number.parseInt(rawScore, 10) || 0),
        button: saveScoreTrigger
      };
      pendingSensitiveAction = 'save_task_score';
      void saveTaskScore();
      return;
    }

    const resetTaskTrigger = target.closest('[data-action="reset-invitee-task-progress"]');
    if (resetTaskTrigger instanceof HTMLElement) {
      if (!participationContext || !Number.isFinite(participationContext.row) || participationContext.row <= 1) {
        setParticipationMsg('Invalid invitee row.', true);
        return;
      }
      const taskId = String(resetTaskTrigger.getAttribute('data-task-id') || '').trim();
      if (!taskId) {
        setParticipationMsg('Invalid task id.', true);
        return;
      }
      pendingSensitiveAction = 'reset_task';
      resetProgressContext = {
        row: participationContext.row,
        displayName: participationContext.displayName,
        workId: participationContext.workId,
        taskId,
        taskTitle: String(resetTaskTrigger.getAttribute('data-task-title') || '').trim()
      };
      void requestResetAccess();
      return;
    }

    const revealTrigger = target.closest('[data-action="reveal-invitee-password"]');
    if (revealTrigger instanceof HTMLElement) {
      const rowValue = Number(revealTrigger.getAttribute('data-row') || '0');
      if (!Number.isFinite(rowValue) || rowValue <= 1) {
        return;
      }
      pendingSensitiveAction = 'reveal';
      revealPasswordContext = {
        row: Math.trunc(rowValue),
        displayName: String(revealTrigger.getAttribute('data-display-name') || '').trim(),
        workId: String(revealTrigger.getAttribute('data-work-id') || '').trim()
      };
      void requestRevealPassword();
      return;
    }

    const resetTrigger = target.closest('[data-action="reset-invitee-progress"]');
    if (resetTrigger instanceof HTMLElement) {
      const rowValue = Number(resetTrigger.getAttribute('data-row') || '0');
      if (!Number.isFinite(rowValue) || rowValue <= 1) {
        return;
      }
      pendingSensitiveAction = 'reset';
      resetProgressContext = {
        row: Math.trunc(rowValue),
        displayName: String(resetTrigger.getAttribute('data-display-name') || '').trim(),
        workId: String(resetTrigger.getAttribute('data-work-id') || '').trim()
      };
      void requestResetAccess();
      return;
    }

    const editTrigger = target.closest('[data-action="edit-invitee"]');
    if (!(editTrigger instanceof HTMLElement)) return;
    const rowValue = Number(editTrigger.getAttribute('data-row') || '0');
    if (!Number.isFinite(rowValue) || rowValue <= 1) {
      return;
    }
    editingInviteeRow = Math.trunc(rowValue);
    if (editWorkIdEl) editWorkIdEl.value = String(editTrigger.getAttribute('data-work-id') || '').trim();
    if (editFirstNameEl) editFirstNameEl.value = String(editTrigger.getAttribute('data-first-name') || '').trim();
    if (editLastNameEl) editLastNameEl.value = String(editTrigger.getAttribute('data-last-name') || '').trim();
    if (editNationalIdEl) editNationalIdEl.value = String(editTrigger.getAttribute('data-national-id') || '').trim();
    if (editPhoneNumberEl) editPhoneNumberEl.value = String(editTrigger.getAttribute('data-phone-number') || '').trim();
    setEditMsg('');
    openEditModal();
  });

  editCloseBtns.forEach((btn) => btn.addEventListener('click', closeEditModal));

  editSaveBtn?.addEventListener('click', async () => {
    if (!editingInviteeRow) {
      setEditMsg('Invalid invitee row.', true);
      return;
    }
    const workId = String(editWorkIdEl?.value || '').trim();
    const firstName = String(editFirstNameEl?.value || '').trim();
    const lastName = String(editLastNameEl?.value || '').trim();
    const nationalId = String(editNationalIdEl?.value || '').trim();
    const phoneNumber = String(editPhoneNumberEl?.value || '').trim();

    if (!workId || !firstName || !lastName || !nationalId || !phoneNumber) {
      setEditMsg('Please fill all fields.', true);
      return;
    }

    editSaveBtn.disabled = true;
    setEditMsg('Saving invitee...');
    try {
      const response = await fetch('mini%20apps/Task%20Club/invitees_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: csrfToken,
          row: editingInviteeRow,
          invitee: {
            workId,
            firstName,
            lastName,
            nationalId,
            phoneNumber
          }
        })
      });
      const result = await response.json();
      if (response.ok && result?.status === 'ok') {
        setEditMsg('Invitee updated successfully.');
        setTimeout(() => window.location.reload(), 350);
      } else {
        setEditMsg(result?.message || 'Failed to update invitee.', true);
      }
    } catch {
      setEditMsg('Failed to update invitee.', true);
    } finally {
      editSaveBtn.disabled = false;
    }
  });

  authCloseBtns.forEach((btn) => btn.addEventListener('click', closeAuthModal));
  passwordCloseBtns.forEach((btn) => btn.addEventListener('click', closePasswordModal));
  resetCloseBtns.forEach((btn) => btn.addEventListener('click', closeResetModal));
  participationCloseBtns.forEach((btn) => btn.addEventListener('click', closeParticipationModal));

  authSubmitBtn?.addEventListener('click', async () => {
    const password = String(authPasswordEl?.value || '');
    if (password.trim() === '') {
      setAuthMsg('Enter your panel password.', true);
      return;
    }
    authSubmitBtn.disabled = true;
    setAuthMsg('Verifying password...');
    try {
      const response = await postRevealAction('verify_unlock', { password });
      if (response.ok) {
        setAuthMsg('Verified. Sensitive actions are unlocked for 5 minutes.');
        const nextAction = pendingSensitiveAction;
        pendingSensitiveAction = '';
        closeAuthModal();
        if (nextAction === 'reveal' && revealPasswordContext) {
          await requestRevealPassword();
        } else if (nextAction === 'reset' && resetProgressContext) {
          await requestResetAccess();
        } else if (nextAction === 'reset_task' && resetProgressContext) {
          await requestResetAccess();
        } else if (nextAction === 'save_task_score' && scoreEditContext) {
          await saveTaskScore();
        }
      } else {
        setAuthMsg(response.message || 'Password verification failed.', true);
      }
    } catch {
      setAuthMsg('Password verification failed.', true);
    } finally {
      authSubmitBtn.disabled = false;
    }
  });

  authPasswordEl?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    authSubmitBtn?.click();
  });

  passwordSaveBtn?.addEventListener('click', async () => {
    if (!revealPasswordContext || !Number.isFinite(revealPasswordContext.row) || revealPasswordContext.row <= 1) {
      setPasswordMsg('Invalid invitee row.', true);
      return;
    }
    const newPassword = String(passwordValueEl?.value || '').trim();
    if (newPassword === '') {
      setPasswordMsg('Password cannot be empty.', true);
      return;
    }
    passwordSaveBtn.disabled = true;
    setPasswordMsg('Saving password...');
    try {
      const response = await postRevealAction('save_password', {
        row: revealPasswordContext.row,
        new_password: newPassword
      });
      if (response.ok) {
        setPasswordMsg(response.message || 'Password updated.');
      } else if (response.status === 'auth_required') {
        setPasswordMsg('Authorization expired. Please verify again.', true);
        pendingSensitiveAction = 'reveal';
        openAuthModal();
      } else {
        setPasswordMsg(response.message || 'Failed to save password.', true);
      }
    } catch {
      setPasswordMsg('Failed to save password.', true);
    } finally {
      passwordSaveBtn.disabled = false;
    }
  });

  resetConfirmBtn?.addEventListener('click', async () => {
    if (!resetProgressContext || !Number.isFinite(resetProgressContext.row) || resetProgressContext.row <= 1) {
      setResetMsg('Invalid invitee row.', true);
      return;
    }
    resetConfirmBtn.disabled = true;
    const isTaskReset = Boolean(resetProgressContext.taskId);
    setResetMsg(isTaskReset ? 'Resetting task progress...' : 'Resetting invitee progress...');
    try {
      const response = await postRevealAction(isTaskReset ? 'reset_task_progress' : 'reset_progress', {
        row: resetProgressContext.row,
        ...(isTaskReset ? { task_id: resetProgressContext.taskId } : {})
      });
      if (response.ok) {
        setResetMsg(response.message || (isTaskReset ? 'Task progress reset.' : 'Invitee progress reset.'));
        if (isTaskReset) {
          if (response.data?.participation) {
            renderParticipationStats(response.data.participation);
          } else {
            await loadParticipationStats();
          }
          setTimeout(closeResetModal, 450);
        } else {
          setTimeout(() => window.location.reload(), 500);
        }
      } else if (response.status === 'auth_required') {
        setResetMsg('Authorization expired. Please verify again.', true);
        pendingSensitiveAction = isTaskReset ? 'reset_task' : 'reset';
        closeResetModal();
        openAuthModal();
      } else {
        setResetMsg(response.message || (isTaskReset ? 'Failed to reset task progress.' : 'Failed to reset invitee progress.'), true);
      }
    } catch {
      setResetMsg(isTaskReset ? 'Failed to reset task progress.' : 'Failed to reset invitee progress.', true);
    } finally {
      resetConfirmBtn.disabled = false;
    }
  });

  const csvEscape = (value) => {
    const text = String(value ?? '');
    if (/[",\n]/.test(text)) {
      return `"${text.replace(/"/g, '""')}"`;
    }
    return text;
  };

  const generatePassword = () => {
    const chars = '0123456789';
    let out = '';
    for (let i = 0; i < 5; i += 1) {
      out += chars[Math.floor(Math.random() * chars.length)];
    }
    return out;
  };

  const buildOptions = (selectEl) => {
    if (!selectEl) return;
    selectEl.innerHTML = '';
    headerRow.forEach((label, index) => {
      const opt = document.createElement('option');
      opt.value = String(index);
      opt.textContent = label || `Column ${index + 1}`;
      selectEl.appendChild(opt);
    });
  };

  const parseFile = async (file) => {
    const arrayBuffer = await file.arrayBuffer();
    const workbook = window.XLSX.read(arrayBuffer, { type: 'array' });
    const sheetName = workbook.SheetNames[0];
    const worksheet = workbook.Sheets[sheetName];
    const rows = window.XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });
    return rows;
  };

  pickBtn?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    fileNameEl.textContent = file.name;
    showProgress('در حال خواندن فایل...');
    try {
      parsedRows = await parseFile(file);
      headerRow = parsedRows[0] || [];
    } catch {
      setMsg('خواندن فایل با خطا مواجه شد.', true);
    }
    hideProgress();
  });

  mapBtn?.addEventListener('click', async () => {
    if (!parsedRows.length) {
      setMsg('ابتدا فایل را انتخاب کنید.', true);
      return;
    }
    if (!headerRow.length) {
      headerRow = parsedRows[0] || [];
    }
    if (!headerRow.length) {
      headerRow = new Array(parsedRows[0]?.length || 0).fill('').map((_, i) => `Column ${i + 1}`);
    }
    buildOptions(mapWork);
    buildOptions(mapFirst);
    buildOptions(mapLast);
    buildOptions(mapNational);
    buildOptions(mapPhone);
    openModal();
  });

  closeBtns.forEach(btn => btn.addEventListener('click', closeModal));

  uploadBtn?.addEventListener('click', async () => {
    if (!parsedRows.length) {
      setMsg('فایلی برای آپلود وجود ندارد.', true);
      return;
    }
    const workIdx = Number(mapWork?.value ?? -1);
    const firstIdx = Number(mapFirst?.value ?? -1);
    const lastIdx = Number(mapLast?.value ?? -1);
    const nationalIdx = Number(mapNational?.value ?? -1);
    const phoneIdx = Number(mapPhone?.value ?? -1);
    if (workIdx < 0 || firstIdx < 0 || lastIdx < 0 || nationalIdx < 0 || phoneIdx < 0) {
      setMsg('ستون‌ها را انتخاب کنید.', true);
      return;
    }

    const rows = parsedRows.map((row) => Array.from(row));
    if (!rows.length) {
      setMsg('فایل خالی است.', true);
      return;
    }
    rows[0] = [
      ...rows[0],
      'password',
      'logins counts',
      'logins',
      'count of rolls',
      'prize won'
    ];
    for (let i = 1; i < rows.length; i += 1) {
      rows[i] = [
        ...rows[i],
        generatePassword(),
        '',
        '',
        '',
        ''
      ];
    }

    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');

    const payload = {
      csrf: csrfToken,
      csv,
      mapping: {
        workId: workIdx,
        firstName: firstIdx,
        lastName: lastIdx,
        nationalId: nationalIdx,
        phoneNumber: phoneIdx
      }
    };

    try {
      showProgress('در حال آپلود و ساخت فایل...');
      const response = await fetch('mini%20apps/Task%20Club/invitees_upload.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const result = await response.json();
      if (response.ok && result?.status === 'ok') {
        setMsg('آپلود انجام شد.');
        closeModal();
      } else {
        setMsg(result?.message || 'خطا در آپلود.', true);
      }
    } catch {
      setMsg('خطا در آپلود.', true);
    } finally {
      hideProgress();
    }
  });

  anyPasswordSaveBtn?.addEventListener('click', async () => {
    const minLength = Number.parseInt(String(anyPasswordMinLengthEl?.value || ''), 10);
    if (!Number.isFinite(minLength) || minLength < 1 || minLength > 128) {
      setAnyPasswordMsg('Min Length must be between 1 and 128.', true);
      return;
    }

    anyPasswordSaveBtn.disabled = true;
    setAnyPasswordMsg('Saving settings...');
    try {
      const response = await fetch('mini%20apps/Task%20Club/invitees_login_settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: csrfToken,
          anyPassword: Boolean(anyPasswordEnabledEl?.checked),
          minLength
        })
      });
      const result = await response.json();
      if (response.ok && result?.status === 'ok') {
        setAnyPasswordMsg(result?.message || 'Settings saved.');
        if (anyPasswordMinLengthEl) {
          anyPasswordMinLengthEl.value = String(result?.settings?.minLength ?? minLength);
        }
        if (anyPasswordEnabledEl) {
          anyPasswordEnabledEl.checked = Boolean(result?.settings?.anyPassword);
        }
      } else {
        setAnyPasswordMsg(result?.message || 'Failed to save settings.', true);
      }
    } catch {
      setAnyPasswordMsg('Failed to save settings.', true);
    } finally {
      anyPasswordSaveBtn.disabled = false;
    }
  });

  addInviteeBtn?.addEventListener('click', async () => {
    const workId = String(addWorkIdEl?.value || '').trim();
    const firstName = String(addFirstNameEl?.value || '').trim();
    const lastName = String(addLastNameEl?.value || '').trim();
    const nationalId = String(addNationalIdEl?.value || '').trim();
    const phoneNumber = String(addPhoneNumberEl?.value || '').trim();

    if (!workId || !firstName || !lastName || !nationalId || !phoneNumber) {
      setAddMsg('Please fill all fields.', true);
      return;
    }

    addInviteeBtn.disabled = true;
    setAddMsg('Saving invitee...');
    try {
      const response = await fetch('mini%20apps/Task%20Club/invitees_add.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: csrfToken,
          invitee: {
            workId,
            firstName,
            lastName,
            nationalId,
            phoneNumber
          }
        })
      });
      const result = await response.json();
      if (response.ok && result?.status === 'ok') {
        setAddMsg('Invitee added successfully.');
        if (addWorkIdEl) addWorkIdEl.value = '';
        if (addFirstNameEl) addFirstNameEl.value = '';
        if (addLastNameEl) addLastNameEl.value = '';
        if (addNationalIdEl) addNationalIdEl.value = '';
        if (addPhoneNumberEl) addPhoneNumberEl.value = '';
        setTimeout(() => window.location.reload(), 350);
      } else {
        setAddMsg(result?.message || 'Failed to add invitee.', true);
      }
    } catch {
      setAddMsg('Failed to add invitee.', true);
    } finally {
      addInviteeBtn.disabled = false;
    }
  });
})();
</script>

