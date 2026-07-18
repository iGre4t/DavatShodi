<?php
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once __DIR__ . '/invitees_special_access.php';
require_once __DIR__ . '/invitees_csv_safety.php';
$egmInviteesSessionUser = requireTabPermissionFromSession('event-guest-manager', false);
if (!userHasPermissionId($egmInviteesSessionUser, 'event-guest-manager:invitees')) {
  denyPanelAccess(403, 'You do not have permission to access this Event Guest Manager section.', false);
}
$egmInviteesCsrfToken = egmSecurityGetCsrfToken();
$egmInviteesSpecialAccess = egmInviteesSpecialAccessForPanelUser($egmInviteesSessionUser, __DIR__ . '/tasks/task-access.json');
$egmInviteesCanManage = !empty($egmInviteesSpecialAccess['manageInvitees']);
$egmInviteesCanReset = !empty($egmInviteesSpecialAccess['resetInvitee']);
$egmInviteesCanReveal = !empty($egmInviteesSpecialAccess['revealPassword']);
$egmInviteesCanEdit = !empty($egmInviteesSpecialAccess['editInvitee']);
$egmInviteesHasRowAction = $egmInviteesCanEdit || $egmInviteesCanReveal || $egmInviteesCanReset;

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'EGM Event';
$mappedFile = $baseDir . DIRECTORY_SEPARATOR . 'Invitees mapped.csv';
$mapFile = $baseDir . DIRECTORY_SEPARATOR . 'EGM Mapped.json';
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
  if (egmInviteesCsvIsManagedPath($path)) {
    return egmInviteesCsvReadRowsSnapshot($path);
  }
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

function inviteeListDecodeProgressValue(string $raw) {
  $value = trim($raw);
  if ($value === '') {
    return null;
  }
  $decoded = json_decode($value, true);
  return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function inviteeListProgressEntries(string $raw): array {
  $decoded = inviteeListDecodeProgressValue($raw);
  if (is_array($decoded)) {
    return $decoded;
  }
  $entries = preg_split('/\s*(?:,|;)\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
  return is_array($entries) ? $entries : [];
}

function inviteeListCompletedMissionIds(array $row, array $header): array {
  $completed = [];
  $completedIndex = findInviteHeaderIndex($header, ['task completed ids', 'task completed id', 'task completed']);
  $rawCompleted = $completedIndex >= 0 ? trim((string)($row[$completedIndex] ?? '')) : '';
  $completedValues = inviteeListProgressEntries(str_replace('|', ',', $rawCompleted));
  foreach ($completedValues ?: [] as $key => $value) {
    $taskId = is_string($key) && !is_int($key) && is_bool($value) ? $key : (string)$value;
    $taskId = trim($taskId);
    if ($taskId !== '') {
      $completed[$taskId] = true;
    }
  }

  foreach (['task score map', 'info tasks', 'describe photo task'] as $columnName) {
    $index = findInviteHeaderIndex($header, [$columnName]);
    $entries = $index >= 0 ? inviteeListProgressEntries((string)($row[$index] ?? '')) : [];
    foreach ($entries as $taskId => $value) {
      if (is_int($taskId)) {
        $taskId = trim(explode(':', (string)$value, 2)[0] ?? '');
      }
      $taskId = trim((string)$taskId);
      if ($taskId !== '') {
        $completed[$taskId] = true;
      }
    }
  }

  $teamIndex = findInviteHeaderIndex($header, ['team task']);
  $teamEntries = $teamIndex >= 0 ? inviteeListProgressEntries((string)($row[$teamIndex] ?? '')) : [];
  foreach ($teamEntries as $taskId => $entry) {
    if (is_int($taskId)) {
      $parts = explode('::', (string)$entry);
      $taskId = trim((string)($parts[0] ?? ''));
      $score = (int)($parts[3] ?? 0);
    } else {
      $score = is_array($entry) ? (int)($entry['score'] ?? 0) : 0;
    }
    if ($score > 0) {
      $taskId = trim((string)$taskId);
      if ($taskId !== '') {
        $completed[$taskId] = true;
      }
    }
  }

  return array_keys($completed);
}

function inviteeListTaskOrderMap(string $tasksPath): array {
  if (!is_file($tasksPath)) {
    return [];
  }
  $content = (string)file_get_contents($tasksPath);
  if (!preg_match('/window\.EGM_TASKS\s*=\s*(\[[\s\S]*\])\s*;?\s*$/', $content, $matches)) {
    return [];
  }
  $tasks = json_decode((string)($matches[1] ?? ''), true);
  if (!is_array($tasks)) {
    return [];
  }
  $orders = [];
  foreach ($tasks as $index => $task) {
    if (!is_array($task)) {
      continue;
    }
    $taskId = trim((string)($task['id'] ?? ''));
    if ($taskId !== '') {
      $orders[$taskId] = max(1, (int)($task['order'] ?? ($index + 1)));
    }
  }
  return $orders;
}

function inviteeListReachedMissionOrder(array $completedMissionIds, array $taskOrderMap): int {
  $reached = 0;
  foreach ($completedMissionIds as $taskId) {
    $id = trim((string)$taskId);
    if (isset($taskOrderMap[$id])) {
      $reached = max($reached, (int)$taskOrderMap[$id]);
      continue;
    }
    if (preg_match('/(\d+)(?!.*\d)/', $id, $matches)) {
      $reached = max($reached, (int)($matches[1] ?? 0));
    }
  }
  return $reached;
}

$mapping = readMappedConfig($mapFile);
$taskOrderMap = inviteeListTaskOrderMap(__DIR__ . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'tasks.js');
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
  $totalScoreIndex = findInviteHeaderIndex($header, ['score', 'total score']);
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
    $totalScore = max(0, (int)($row[$totalScoreIndex] ?? 0));
    $completedMissionIds = inviteeListCompletedMissionIds($row, $header);
    $completedMissions = count($completedMissionIds);
    $reachedMissionOrder = inviteeListReachedMissionOrder($completedMissionIds, $taskOrderMap);

    if ($workId !== '' || $nationalId !== '' || $phoneNumber !== '' || $first !== '' || $last !== '') {
      $allInvitees[] = [
        'row' => $rowNumber,
        'firstName' => $first,
        'lastName' => $last,
        'workId' => $workId,
        'nationalId' => $nationalId,
        'phoneNumber' => $phoneNumber,
        'password' => $password,
        'completedMissions' => $completedMissions,
        'reachedMissionOrder' => $reachedMissionOrder,
        'totalScore' => $totalScore
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

<div class="egm-task-top-shell" id="egm-invitees-top-shell">
  <div class="egm-task-top-nav" role="tablist" aria-label="Invitees Tabs">
    <button type="button" class="egm-task-top-item active" data-invitees-top-trigger="all-invitees" aria-selected="true">All Invitees</button>
    <?php if ($egmInviteesCanManage): ?>
    <button type="button" class="egm-task-top-item" data-invitees-top-trigger="manage-invitees" aria-selected="false">Manage Invitees</button>
    <?php endif; ?>
  </div>
  <div class="egm-task-top-section active" data-invitees-top-section="all-invitees">
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
            id="egm-all-invitees-search"
            type="text"
            placeholder="Search by name, work ID, national ID, or phone number"
            autocomplete="off"
          />
          <small id="egm-all-invitees-search-meta" class="hint"></small>
        </label>
        <div class="table-wrapper egm-info-rate-table-wrap">
          <table class="tct-list-table egm-info-rate-table">
            <thead>
              <tr>
                <?php if ($egmInviteesCanReset): ?>
                <th>
                  <input type="checkbox" id="egm-invitees-select-all" aria-label="Select all visible invitees" />
                </th>
                <?php endif; ?>
                <th>Row</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Work ID</th>
                <th>National ID</th>
                <th>Phone Number</th>
                <th>Password</th>
                <th>Missions Done</th>
                <th>Total Score</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody data-invitees-all-table-body>
              <?php if (!$allInvitees): ?>
                <tr>
                  <td colspan="<?= $egmInviteesCanReset ? '11' : '10' ?>" class="muted">No invitees found.</td>
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
                    <?php if ($egmInviteesCanReset): ?>
                    <td>
                      <input
                        type="checkbox"
                        data-invitee-select
                        data-reached-mission-order="<?= htmlspecialchars((string)($invitee['reachedMissionOrder'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Select <?= htmlspecialchars(trim(((string)($invitee['firstName'] ?? '')) . ' ' . ((string)($invitee['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                      />
                    </td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['firstName'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['lastName'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['nationalId'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['phoneNumber'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['password'] ?? '') !== '' ? '*****' : '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['completedMissions'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($invitee['totalScore'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                      <div class="egm-info-rate-row-actions">
                        <?php if ($egmInviteesCanEdit): ?>
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
                        <?php if ($egmInviteesCanReveal): ?>
                        <button
                          type="button"
                          class="btn ghost"
                          data-action="reveal-invitee-password"
                          data-row="<?= htmlspecialchars((string)($invitee['row'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                          data-display-name="<?= htmlspecialchars(trim(((string)($invitee['firstName'] ?? '')) . ' ' . ((string)($invitee['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                          data-work-id="<?= htmlspecialchars((string)($invitee['workId'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        >Reveal Password</button>
                        <?php endif; ?>
                        <?php if ($egmInviteesCanReset): ?>
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
                  <td colspan="<?= $egmInviteesCanReset ? '11' : '10' ?>" class="muted">No matching invitee found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($egmInviteesCanReset && $allInvitees): ?>
        <div class="card egm-invitees-bulk-card" id="egm-invitees-bulk-card">
          <div class="card-header">
            <div>
              <h4>Selected Invitees Action</h4>
              <p class="muted"><span id="egm-invitees-selected-count">0</span> invitees selected</p>
            </div>
          </div>
          <div class="egm-invitees-active-selector">
            <div class="egm-invitees-active-fields">
              <label class="field">
                <span>Active users — level reached</span>
                <select id="egm-invitees-active-minimum">
                  <option value="">Select a level / mission</option>
                  <option value="0">Non mission done</option>
                </select>
              </label>
              <label class="field">
                <span>But (optional)</span>
                <select id="egm-invitees-bulk-condition">
                  <option value="none">None — ignore score condition</option>
                  <option value="not_already_scored">But not already scored</option>
                </select>
              </label>
              <label class="field">
                <span>In level / mission</span>
                <select id="egm-invitees-condition-task" disabled>
                  <option value="">Select a level / mission</option>
                </select>
              </label>
              <label class="field">
                <span>At least logged in X times — optional</span>
                <input id="egm-invitees-minimum-logins" type="number" min="0" step="1" inputmode="numeric" placeholder="Example: 3" />
              </label>
              <label class="field">
                <span>Top by login count (%) — optional</span>
                <input id="egm-invitees-login-percentage" type="number" min="1" max="100" step="1" inputmode="numeric" placeholder="Example: 80" />
              </label>
            </div>
            <div class="egm-invitees-filter-counts" aria-live="polite">
              <div>
                <span>Reached selected level</span>
                <strong id="egm-invitees-reached-count">—</strong>
              </div>
              <div>
                <span>Reached level and not scored in selected mission</span>
                <strong id="egm-invitees-not-scored-count">—</strong>
              </div>
              <div>
                <span>After minimum-login filter</span>
                <strong id="egm-invitees-minimum-logins-count">—</strong>
              </div>
              <div>
                <span>After top-login percentage filter</span>
                <strong id="egm-invitees-login-filtered-count">—</strong>
              </div>
            </div>
            <div class="egm-invitees-active-actions">
              <button type="button" class="btn ghost" id="egm-invitees-select-active">Select Active Users</button>
              <button type="button" class="btn ghost" id="egm-invitees-clear-selection">Clear Selection</button>
            </div>
          </div>
          <p class="muted small">Minimum level selects users by the furthest mission reached. The optional score condition narrows that group, then the login percentage keeps the users with the highest login counts.</p>
          <div class="form grid egm-invitees-bulk-form">
            <label class="field">
              <span>Mission</span>
              <select id="egm-invitees-bulk-task">
                <option value="">Select a mission</option>
              </select>
            </label>
            <label class="field">
              <span>Score</span>
              <input id="egm-invitees-bulk-score" type="number" min="0" step="1" inputmode="numeric" />
            </label>
            <button type="button" class="btn primary" id="egm-invitees-bulk-apply" disabled>Apply to Selected</button>
          </div>
          <p id="egm-invitees-bulk-msg" class="hint" aria-live="polite"></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($egmInviteesCanManage): ?>
  <div class="egm-task-top-section" data-invitees-top-section="manage-invitees" hidden>
<div class="card">
  <div class="section-header">
    <h3>Insert Invite List</h3>
  </div>
  <div class="form">
    <div class="field standard-width">
      <span>Insert Excel File</span>
      <div class="field-block" style="padding:10px;">
        <input id="egm-invite-file" type="file" accept=".csv,.xls,.xlsx" hidden />
        <div class="field-controls" style="gap:8px;">
          <button type="button" class="btn" id="egm-invite-pick">Insert Excel File</button>
          <div id="egm-invite-file-name" class="muted">No file selected.</div>
        </div>
      </div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="egm-invite-map">Map and Upload</button>
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
      <input id="egm-any-password-min-length" type="number" min="1" max="128" step="1" value="<?= htmlspecialchars((string)$anyPasswordLoginSettings['minLength'], ENT_QUOTES, 'UTF-8') ?>" />
    </label>
    <label class="field checkbox-field">
      <span>Any Password</span>
      <input id="egm-any-password-enabled" type="checkbox" <?= !empty($anyPasswordLoginSettings['anyPassword']) ? 'checked' : '' ?> />
    </label>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="egm-any-password-save">Save</button>
      <p id="egm-any-password-msg" class="hint" aria-live="polite"></p>
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
      <input id="egm-add-work-id" type="text" placeholder="Work ID" />
    </label>
    <label class="field">
      <span>First Name</span>
      <input id="egm-add-first-name" type="text" placeholder="First Name" />
    </label>
    <label class="field">
      <span>Last Name</span>
      <input id="egm-add-last-name" type="text" placeholder="Last Name" />
    </label>
    <label class="field">
      <span>National ID</span>
      <input id="egm-add-national-id" type="text" placeholder="National ID" />
    </label>
    <label class="field">
      <span>Phone Number</span>
      <input id="egm-add-phone-number" type="text" placeholder="Phone Number" />
    </label>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="egm-add-invitee-btn">Add Invitee</button>
      <p id="egm-add-invitee-msg" class="hint" aria-live="polite"></p>
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

<div id="egm-invite-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Map Columns</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-modal aria-label="Close">×</button>
    </div>
    <div id="egm-invite-progress" class="modal-progress hidden" role="status" aria-live="polite">
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
          <select id="egm-map-work"></select>
        </label>
        <label class="field">
          <span>First Name</span>
          <select id="egm-map-first"></select>
        </label>
        <label class="field">
          <span>Last Name</span>
          <select id="egm-map-last"></select>
        </label>
        <label class="field">
          <span>National ID</span>
          <select id="egm-map-national"></select>
        </label>
        <label class="field">
          <span>Phone Number</span>
          <select id="egm-map-phone"></select>
        </label>
      </div>
      <p id="egm-invite-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-modal>Cancel</button>
      <button type="button" class="btn primary" id="egm-invite-upload">Upload</button>
    </div>
</div>
</div>

<div id="egm-invite-edit-modal" class="modal hidden" aria-hidden="true">
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
          <input id="egm-edit-work-id" type="text" />
        </label>
        <label class="field">
          <span>First Name</span>
          <input id="egm-edit-first-name" type="text" />
        </label>
        <label class="field">
          <span>Last Name</span>
          <input id="egm-edit-last-name" type="text" />
        </label>
        <label class="field">
          <span>National ID</span>
          <input id="egm-edit-national-id" type="text" />
        </label>
        <label class="field">
          <span>Phone Number</span>
          <input id="egm-edit-phone-number" type="text" />
        </label>
      </div>
      <p id="egm-edit-invitee-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-edit-modal>Cancel</button>
      <button type="button" class="btn primary" id="egm-edit-invitee-save">Save</button>
    </div>
  </div>
</div>

<div id="egm-invite-auth-modal" class="modal hidden" aria-hidden="true">
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
          <input id="egm-invite-auth-password" type="password" autocomplete="current-password" />
        </label>
      </div>
      <p id="egm-invite-auth-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-auth-modal>Cancel</button>
      <button type="button" class="btn primary" id="egm-invite-auth-submit">Verify</button>
    </div>
  </div>
</div>

<div id="egm-invite-password-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3 id="egm-invite-password-title">Invitee Password</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-password-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <div class="form">
        <label class="field">
          <span>Password</span>
          <input id="egm-invite-password-value" type="text" autocomplete="off" />
        </label>
      </div>
      <p id="egm-invite-password-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-password-modal>Close</button>
      <button type="button" class="btn primary" id="egm-invite-password-save">Save New Password</button>
    </div>
  </div>
</div>

<div id="egm-invite-reset-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3>Reset Invitee Progress</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-reset-modal aria-label="Close">×</button>
    </div>
    <div class="modal-card-body">
      <p id="egm-invite-reset-text" class="muted">Are you sure you want to reset this invitee progress?</p>
      <p id="egm-invite-reset-msg" class="hint" aria-live="polite"></p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-reset-modal>Cancel</button>
      <button type="button" class="btn primary" id="egm-invite-reset-confirm">Confirm</button>
    </div>
  </div>
</div>

<div id="egm-invite-participation-modal" class="modal hidden" aria-hidden="true">
  <div class="modal-card egm-participation-modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3 id="egm-invite-participation-title">Invitee Participation</h3>
      </div>
      <button type="button" class="icon-btn" data-close-invite-participation-modal aria-label="Close">&times;</button>
    </div>
    <div class="modal-card-body">
      <div id="egm-invite-participation-summary" class="egm-participation-summary"></div>
      <p id="egm-invite-participation-msg" class="hint" aria-live="polite"></p>
      <div id="egm-invite-participation-tasks" class="egm-participation-tasks"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-invite-participation-modal>Close</button>
    </div>
  </div>
</div>

<script src="mini%20apps/Event%20Guest%20Manager/vendor/xlsx/xlsx.full.min.js" defer></script>
<script>
(() => {
  const csrfToken = <?= json_encode($egmInviteesCsrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const canResetInviteeTasks = <?= $egmInviteesCanReset ? 'true' : 'false' ?>;
  const pickBtn = document.getElementById('egm-invite-pick');
  const fileInput = document.getElementById('egm-invite-file');
  const fileNameEl = document.getElementById('egm-invite-file-name');
  const mapBtn = document.getElementById('egm-invite-map');
  const modal = document.getElementById('egm-invite-modal');
  const closeBtns = modal ? modal.querySelectorAll('[data-close-invite-modal]') : [];
  const mapWork = document.getElementById('egm-map-work');
  const mapFirst = document.getElementById('egm-map-first');
  const mapLast = document.getElementById('egm-map-last');
  const mapNational = document.getElementById('egm-map-national');
  const mapPhone = document.getElementById('egm-map-phone');
  const uploadBtn = document.getElementById('egm-invite-upload');
  const msgEl = document.getElementById('egm-invite-msg');
  const progressEl = document.getElementById('egm-invite-progress');
  const progressMsg = progressEl?.querySelector('[data-invite-progress-message]');
  const addWorkIdEl = document.getElementById('egm-add-work-id');
  const addFirstNameEl = document.getElementById('egm-add-first-name');
  const addLastNameEl = document.getElementById('egm-add-last-name');
  const addNationalIdEl = document.getElementById('egm-add-national-id');
  const addPhoneNumberEl = document.getElementById('egm-add-phone-number');
  const addInviteeBtn = document.getElementById('egm-add-invitee-btn');
  const addInviteeMsgEl = document.getElementById('egm-add-invitee-msg');
  const anyPasswordEnabledEl = document.getElementById('egm-any-password-enabled');
  const anyPasswordMinLengthEl = document.getElementById('egm-any-password-min-length');
  const anyPasswordSaveBtn = document.getElementById('egm-any-password-save');
  const anyPasswordMsgEl = document.getElementById('egm-any-password-msg');
  const editModal = document.getElementById('egm-invite-edit-modal');
  const editCloseBtns = editModal ? editModal.querySelectorAll('[data-close-invite-edit-modal]') : [];
  const editWorkIdEl = document.getElementById('egm-edit-work-id');
  const editFirstNameEl = document.getElementById('egm-edit-first-name');
  const editLastNameEl = document.getElementById('egm-edit-last-name');
  const editNationalIdEl = document.getElementById('egm-edit-national-id');
  const editPhoneNumberEl = document.getElementById('egm-edit-phone-number');
  const editSaveBtn = document.getElementById('egm-edit-invitee-save');
  const editMsgEl = document.getElementById('egm-edit-invitee-msg');
  const authModal = document.getElementById('egm-invite-auth-modal');
  const authCloseBtns = authModal ? authModal.querySelectorAll('[data-close-invite-auth-modal]') : [];
  const authPasswordEl = document.getElementById('egm-invite-auth-password');
  const authSubmitBtn = document.getElementById('egm-invite-auth-submit');
  const authMsgEl = document.getElementById('egm-invite-auth-msg');
  const passwordModal = document.getElementById('egm-invite-password-modal');
  const passwordCloseBtns = passwordModal ? passwordModal.querySelectorAll('[data-close-invite-password-modal]') : [];
  const passwordTitleEl = document.getElementById('egm-invite-password-title');
  const passwordValueEl = document.getElementById('egm-invite-password-value');
  const passwordSaveBtn = document.getElementById('egm-invite-password-save');
  const passwordMsgEl = document.getElementById('egm-invite-password-msg');
  const resetModal = document.getElementById('egm-invite-reset-modal');
  const resetCloseBtns = resetModal ? resetModal.querySelectorAll('[data-close-invite-reset-modal]') : [];
  const resetTextEl = document.getElementById('egm-invite-reset-text');
  const resetMsgEl = document.getElementById('egm-invite-reset-msg');
  const resetConfirmBtn = document.getElementById('egm-invite-reset-confirm');
  const participationModal = document.getElementById('egm-invite-participation-modal');
  const participationCloseBtns = participationModal ? participationModal.querySelectorAll('[data-close-invite-participation-modal]') : [];
  const participationTitleEl = document.getElementById('egm-invite-participation-title');
  const participationSummaryEl = document.getElementById('egm-invite-participation-summary');
  const participationMsgEl = document.getElementById('egm-invite-participation-msg');
  const participationTasksEl = document.getElementById('egm-invite-participation-tasks');
  const inviteesTopShell = document.getElementById('egm-invitees-top-shell');
  const allInviteesSearchInput = document.getElementById('egm-all-invitees-search');
  const allInviteesSearchMetaEl = document.getElementById('egm-all-invitees-search-meta');
  const allInviteesTableBody = document.querySelector('[data-invitees-all-table-body]');
  const inviteesSelectAllEl = document.getElementById('egm-invitees-select-all');
  const bulkTaskEl = document.getElementById('egm-invitees-bulk-task');
  const bulkScoreEl = document.getElementById('egm-invitees-bulk-score');
  const bulkConditionEl = document.getElementById('egm-invitees-bulk-condition');
  const conditionTaskEl = document.getElementById('egm-invitees-condition-task');
  const bulkApplyBtn = document.getElementById('egm-invitees-bulk-apply');
  const bulkSelectedCountEl = document.getElementById('egm-invitees-selected-count');
  const bulkMsgEl = document.getElementById('egm-invitees-bulk-msg');
  const activeMinimumEl = document.getElementById('egm-invitees-active-minimum');
  const selectActiveBtn = document.getElementById('egm-invitees-select-active');
  const clearSelectionBtn = document.getElementById('egm-invitees-clear-selection');
  const reachedCountEl = document.getElementById('egm-invitees-reached-count');
  const notScoredCountEl = document.getElementById('egm-invitees-not-scored-count');
  const minimumLoginsEl = document.getElementById('egm-invitees-minimum-logins');
  const minimumLoginsCountEl = document.getElementById('egm-invitees-minimum-logins-count');
  const loginPercentageEl = document.getElementById('egm-invitees-login-percentage');
  const loginFilteredCountEl = document.getElementById('egm-invitees-login-filtered-count');
  if (selectActiveBtn instanceof HTMLButtonElement) {
    selectActiveBtn.disabled = false;
    selectActiveBtn.removeAttribute('aria-disabled');
  }

  let parsedRows = [];
  let headerRow = [];
  let editingInviteeRow = 0;
  let revealPasswordContext = null;
  let resetProgressContext = null;
  let scoreEditContext = null;
  let participationContext = null;
  let pendingSensitiveAction = '';
  let bulkActionContext = null;
  let activeFilterPreview = null;

  const normalizeSearchDigits = (value) => String(value || '').replace(/[\u06F0-\u06F9\u0660-\u0669]/g, (digit) =>
    String(digit.charCodeAt(0) & 0xF)
  );

  const normalizeSearchValue = (value) => normalizeSearchDigits(value)
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

  const selectedInviteeRows = () => Array.from(document.querySelectorAll('[data-invitee-select]:checked'))
    .map((checkbox) => Number(checkbox.value))
    .filter((row) => Number.isFinite(row) && row > 1)
    .map((row) => Math.trunc(row));

  const setBulkMsg = (message = '', isError = false) => {
    if (!bulkMsgEl) return;
    bulkMsgEl.textContent = message;
    bulkMsgEl.classList.toggle('error', isError);
  };

  const updateBulkSelection = () => {
    const all = Array.from(document.querySelectorAll('[data-invitee-select]'));
    const selected = all.filter((checkbox) => checkbox.checked);
    if (bulkSelectedCountEl) bulkSelectedCountEl.textContent = String(selected.length);
    if (bulkApplyBtn) bulkApplyBtn.disabled = selected.length === 0;
    if (inviteesSelectAllEl) {
      const visible = all.filter((checkbox) => !checkbox.closest('tr')?.hidden);
      const visibleSelected = visible.filter((checkbox) => checkbox.checked);
      inviteesSelectAllEl.checked = visible.length > 0 && visibleSelected.length === visible.length;
      inviteesSelectAllEl.indeterminate = visibleSelected.length > 0 && visibleSelected.length < visible.length;
    }
  };

  const refreshActiveFilterPreview = async () => {
    const minimumRaw = String(activeMinimumEl?.value || '').trim();
    const conditionTaskId = String(conditionTaskEl?.value || '').trim();
    const useNotScoredFilter = bulkConditionEl?.value === 'not_already_scored';
    const minimumLoginsRaw = String(minimumLoginsEl?.value || '').trim();
    const minimumLogins = minimumLoginsRaw === '' ? 0 : Number(minimumLoginsRaw);
    const loginPercentageRaw = String(loginPercentageEl?.value || '').trim();
    const loginPercentage = loginPercentageRaw === '' ? 0 : Number(loginPercentageRaw);
    if (!/^\d+$/.test(minimumRaw)) {
      activeFilterPreview = null;
      if (reachedCountEl) reachedCountEl.textContent = '—';
      if (notScoredCountEl) notScoredCountEl.textContent = '—';
      if (minimumLoginsCountEl) minimumLoginsCountEl.textContent = '—';
      if (loginFilteredCountEl) loginFilteredCountEl.textContent = '—';
      return null;
    }
    if (minimumLoginsRaw !== '' && (!Number.isInteger(minimumLogins) || minimumLogins < 0)) {
      activeFilterPreview = null;
      if (minimumLoginsCountEl) minimumLoginsCountEl.textContent = '!';
      return null;
    }
    if (loginPercentageRaw !== '' && (!Number.isInteger(loginPercentage) || loginPercentage < 1 || loginPercentage > 100)) {
      activeFilterPreview = null;
      if (loginFilteredCountEl) loginFilteredCountEl.textContent = '!';
      return null;
    }
    if (reachedCountEl) reachedCountEl.textContent = '…';
    if (notScoredCountEl) notScoredCountEl.textContent = conditionTaskId ? '…' : '—';
    if (minimumLoginsCountEl) minimumLoginsCountEl.textContent = minimumLoginsRaw !== '' ? '…' : '—';
    if (loginFilteredCountEl) loginFilteredCountEl.textContent = loginPercentage > 0 ? '…' : '—';
    try {
      const response = await postRevealAction('get_active_filter_preview', {
        minimum_order: Number(minimumRaw),
        condition_task_id: conditionTaskId,
        use_not_scored: useNotScoredFilter,
        minimum_logins: minimumLogins,
        login_percentage: loginPercentage
      });
      if (!response.ok) throw new Error(response.message || 'Failed to load filter counts.');
      activeFilterPreview = {
        reachedRows: Array.isArray(response.data?.reachedRows) ? response.data.reachedRows.map(Number) : [],
        notScoredRows: Array.isArray(response.data?.notScoredRows) ? response.data.notScoredRows.map(Number) : [],
        filteredRows: Array.isArray(response.data?.filteredRows) ? response.data.filteredRows.map(Number) : []
      };
      if (reachedCountEl) reachedCountEl.textContent = String(response.data?.reachedCount ?? activeFilterPreview.reachedRows.length);
      if (notScoredCountEl) {
        notScoredCountEl.textContent = conditionTaskId
          ? String(response.data?.notScoredCount ?? activeFilterPreview.notScoredRows.length)
          : '—';
      }
      if (minimumLoginsCountEl) {
        minimumLoginsCountEl.textContent = minimumLoginsRaw !== ''
          ? String(response.data?.minimumLoginsCount ?? activeFilterPreview.filteredRows.length)
          : '—';
      }
      if (loginFilteredCountEl) {
        loginFilteredCountEl.textContent = loginPercentage > 0
          ? String(response.data?.filteredCount ?? activeFilterPreview.filteredRows.length)
          : '—';
      }
      return activeFilterPreview;
    } catch {
      activeFilterPreview = null;
      if (reachedCountEl) reachedCountEl.textContent = '!';
      if (notScoredCountEl) notScoredCountEl.textContent = conditionTaskId ? '!' : '—';
      if (minimumLoginsCountEl) minimumLoginsCountEl.textContent = minimumLoginsRaw !== '' ? '!' : '—';
      if (loginFilteredCountEl) loginFilteredCountEl.textContent = loginPercentage > 0 ? '!' : '—';
      return null;
    }
  };

  const loadBulkTaskOptions = async () => {
    if (!(bulkTaskEl instanceof HTMLSelectElement)) return;
    try {
      const response = await postRevealAction('get_task_options');
      if (!response.ok) return;
      const tasks = Array.isArray(response.data?.tasks) ? response.data.tasks : [];
      tasks.forEach((task) => {
        const id = String(task.id || '').trim();
        if (!id) return;
        const order = Math.max(1, Number.parseInt(String(task.order || ''), 10) || 1);
        const option = document.createElement('option');
        option.value = id;
        const title = String(task.title || id).trim();
        const tagCode = String(task.tagCode || '').trim();
        option.textContent = tagCode ? `${title} (${tagCode})` : title;
        bulkTaskEl.appendChild(option);
        if (conditionTaskEl instanceof HTMLSelectElement) {
          conditionTaskEl.appendChild(option.cloneNode(true));
        }
        if (activeMinimumEl instanceof HTMLSelectElement) {
          const levelOption = document.createElement('option');
          levelOption.value = String(order);
          levelOption.textContent = `Level ${order} — ${option.textContent}`;
          activeMinimumEl.appendChild(levelOption);
        }
      });
      await refreshActiveFilterPreview();
    } catch {
      setBulkMsg('Failed to load missions.', true);
    }
  };

  const applyBulkMissionScore = async () => {
    const rows = bulkActionContext?.rows || selectedInviteeRows();
    const taskId = String(bulkActionContext?.taskId || bulkTaskEl?.value || '').trim();
    const scoreRaw = String(bulkActionContext?.score ?? bulkScoreEl?.value ?? '').trim();
    const condition = String(bulkActionContext?.condition || bulkConditionEl?.value || 'none').trim();
    const conditionTaskId = String(bulkActionContext?.conditionTaskId || conditionTaskEl?.value || '').trim();
    if (!rows.length) {
      setBulkMsg('Select at least one invitee.', true);
      return;
    }
    if (!taskId) {
      setBulkMsg('Select a mission.', true);
      return;
    }
    if (!/^\d+$/.test(scoreRaw)) {
      setBulkMsg('Score must be a non-negative whole number.', true);
      return;
    }
    if (condition === 'not_already_scored' && !conditionTaskId) {
      setBulkMsg('Select the level or mission used by the “not already scored” condition.', true);
      return;
    }
    bulkActionContext = { rows, taskId, score: Number(scoreRaw), condition, conditionTaskId };
    if (bulkApplyBtn) bulkApplyBtn.disabled = true;
    setBulkMsg(`Updating ${rows.length} invitees...`);
    try {
      const response = await postRevealAction('bulk_save_task_score', {
        rows,
        task_id: taskId,
        score: Number(scoreRaw),
        condition,
        condition_task_id: conditionTaskId
      });
      if (response.ok) {
        setBulkMsg(response.message || 'Mission scores updated.');
        bulkActionContext = null;
        activeFilterPreview = null;
        await refreshActiveFilterPreview();
      } else if (response.status === 'auth_required') {
        pendingSensitiveAction = 'bulk_save_task_score';
        setBulkMsg('Authorization required. Please verify your panel password.', true);
        openAuthModal();
      } else {
        setBulkMsg(response.message || 'Failed to update mission scores.', true);
        bulkActionContext = null;
      }
    } catch {
      setBulkMsg('Failed to update mission scores.', true);
      bulkActionContext = null;
    } finally {
      if (bulkApplyBtn) bulkApplyBtn.disabled = selectedInviteeRows().length === 0;
    }
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
    updateBulkSelection();
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
    const response = await fetch('mini%20apps/Event%20Guest%20Manager/invitees_password_guard.php', {
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
        <div class="egm-participation-stat-grid">
          ${summaryCards.map(([label, value]) => `
            <div class="egm-participation-stat">
              <span>${escapeHtml(label)}</span>
              <strong>${escapeHtml(formatStatValue(value))}</strong>
            </div>
          `).join('')}
        </div>
        <div class="egm-participation-meta-grid">
          ${extraRows.map(([label, value]) => `
            <div class="egm-participation-meta-row">
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
        <details class="egm-participation-answer-details">
          <summary>${escapeHtml(String(answers.length))} saved answer${answers.length === 1 ? '' : 's'}</summary>
          <div class="egm-participation-answer-list">
            ${answers.map((answer) => {
              const correct = answer.correct === true ? 'Correct' : (answer.correct === false ? 'Incorrect' : '');
              return `
                <div class="egm-participation-answer-row">
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
        <details class="egm-participation-answer-details">
          <summary>${escapeHtml(String(stats.photos.length))} photo item${stats.photos.length === 1 ? '' : 's'}</summary>
          <div class="egm-participation-answer-list">
            ${stats.photos.map((photo) => `
              <div class="egm-participation-answer-row">
                <span>${escapeHtml(photo.photoName || photo.photoId || 'Photo')}</span>
                <strong>${escapeHtml(`${photo.wordCount ?? 0} words`)}</strong>
              </div>
            `).join('')}
          </div>
        </details>
      `;
    };

    participationTasksEl.innerHTML = `
      <div class="table-wrapper egm-participation-table-wrap">
        <table class="tct-list-table egm-participation-task-table">
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
                    <div class="egm-participation-task-title">${escapeHtml(taskTitle)}</div>
                    <div class="muted small">${escapeHtml(task.tagCode || taskId || '')}</div>
                  </td>
                  <td>${escapeHtml(task.typeLabel || task.taskType || '-')}</td>
                  <td><span class="egm-participation-status egm-participation-status--${escapeHtml(task.status || 'not_started')}">${escapeHtml(task.statusLabel || '-')}</span></td>
                  <td>${escapeHtml(score)}</td>
                  <td>
                    ${details.length ? `<div class="egm-participation-detail-list">${details.map((detail) => `<span>${escapeHtml(detail)}</span>`).join('')}</div>` : '<span class="muted">No saved activity.</span>'}
                    ${renderAnswers(task)}
                    ${renderDescribeStats(task)}
                  </td>
                  <td>
                    ${canManageTask ? `
                      <div class="egm-participation-action-stack">
                        <div class="egm-participation-score-edit">
                          <input type="number" min="0" step="1" inputmode="numeric" value="${escapeHtml(String(currentScore))}" data-task-score-input data-task-id="${escapeHtml(taskId)}" aria-label="Task score" />
                          <button type="button" class="btn ghost" data-action="save-invitee-task-score" data-task-id="${escapeHtml(taskId)}" data-task-title="${escapeHtml(taskTitle)}">Save</button>
                        </div>
                        <button type="button" class="btn ghost egm-btn-danger" data-action="reset-invitee-task-progress" data-task-id="${escapeHtml(taskId)}" data-task-title="${escapeHtml(taskTitle)}">Reset</button>
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
      const response = await fetch('mini%20apps/Event%20Guest%20Manager/invitees_update.php', {
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
        } else if (nextAction === 'bulk_save_task_score' && bulkActionContext) {
          await applyBulkMissionScore();
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
      const response = await fetch('mini%20apps/Event%20Guest%20Manager/invitees_upload.php', {
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
      const response = await fetch('mini%20apps/Event%20Guest%20Manager/invitees_login_settings.php', {
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
      const response = await fetch('mini%20apps/Event%20Guest%20Manager/invitees_add.php', {
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

  inviteesSelectAllEl?.addEventListener('change', () => {
    const checked = Boolean(inviteesSelectAllEl.checked);
    document.querySelectorAll('[data-invitee-select]').forEach((checkbox) => {
      if (!checkbox.closest('tr')?.hidden) {
        checkbox.checked = checked;
      }
    });
    updateBulkSelection();
  });

  selectActiveBtn?.addEventListener('click', async () => {
    const minimumRaw = String(activeMinimumEl?.value || '').trim();
    if (!/^\d+$/.test(minimumRaw)) {
      setBulkMsg('Select the minimum level reached.', true);
      return;
    }
    const minimum = Number(minimumRaw);
    const useNotScoredFilter = bulkConditionEl?.value === 'not_already_scored';
    if (useNotScoredFilter && !conditionTaskEl?.value) {
      setBulkMsg('Select the level or mission for the “not already scored” filter.', true);
      return;
    }
    const minimumLoginsRaw = String(minimumLoginsEl?.value || '').trim();
    if (minimumLoginsRaw !== '') {
      const minimumLogins = Number(minimumLoginsRaw);
      if (!Number.isInteger(minimumLogins) || minimumLogins < 0) {
        setBulkMsg('Minimum login count must be a non-negative whole number.', true);
        return;
      }
    }
    const loginPercentageRaw = String(loginPercentageEl?.value || '').trim();
    if (loginPercentageRaw !== '') {
      const loginPercentage = Number(loginPercentageRaw);
      if (!Number.isInteger(loginPercentage) || loginPercentage < 1 || loginPercentage > 100) {
        setBulkMsg('Top login percentage must be a whole number from 1 to 100.', true);
        return;
      }
    }
    const preview = await refreshActiveFilterPreview();
    if (!preview) {
      setBulkMsg('Failed to evaluate active users.', true);
      return;
    }
    const matchingRows = new Set(preview.filteredRows);
    document.querySelectorAll('[data-invitee-select]').forEach((checkbox) => {
      checkbox.checked = matchingRows.has(Number(checkbox.value));
    });
    const selectedCount = selectedInviteeRows().length;
    bulkActionContext = null;
    const levelLabel = minimum === 0 ? 'with no mission done' : `who reached level ${minimum} or later`;
    const suffix = useNotScoredFilter ? ' and are not already scored in the selected mission' : '';
    const minimumLoginsSuffix = minimumLoginsRaw !== '' ? `, with at least ${minimumLoginsRaw} logins` : '';
    const loginSuffix = loginPercentageRaw !== '' ? `, limited to the top ${loginPercentageRaw}% by login count` : '';
    setBulkMsg(`${selectedCount} user${selectedCount === 1 ? '' : 's'} ${levelLabel}${suffix}${minimumLoginsSuffix}${loginSuffix} selected.`);
    updateBulkSelection();
  });

  bulkConditionEl?.addEventListener('change', () => {
    const enabled = bulkConditionEl.value === 'not_already_scored';
    if (conditionTaskEl) {
      conditionTaskEl.disabled = !enabled;
      if (enabled && !conditionTaskEl.value && bulkTaskEl?.value) {
        conditionTaskEl.value = bulkTaskEl.value;
      } else if (!enabled) {
        conditionTaskEl.value = '';
      }
    }
    if (selectActiveBtn instanceof HTMLButtonElement) {
      selectActiveBtn.disabled = false;
      selectActiveBtn.removeAttribute('aria-disabled');
    }
    bulkActionContext = null;
    void refreshActiveFilterPreview();
  });

  activeMinimumEl?.addEventListener('change', () => {
    activeFilterPreview = null;
    void refreshActiveFilterPreview();
  });

  conditionTaskEl?.addEventListener('change', () => {
    activeFilterPreview = null;
    bulkActionContext = null;
    void refreshActiveFilterPreview();
  });

  minimumLoginsEl?.addEventListener('change', () => {
    activeFilterPreview = null;
    bulkActionContext = null;
    void refreshActiveFilterPreview();
  });

  loginPercentageEl?.addEventListener('change', () => {
    activeFilterPreview = null;
    bulkActionContext = null;
    void refreshActiveFilterPreview();
  });

  clearSelectionBtn?.addEventListener('click', () => {
    document.querySelectorAll('[data-invitee-select]').forEach((checkbox) => {
      checkbox.checked = false;
    });
    bulkActionContext = null;
    setBulkMsg('');
    updateBulkSelection();
  });

  allInviteesTableBody?.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.matches('[data-invitee-select]')) {
      updateBulkSelection();
    }
  });

  bulkApplyBtn?.addEventListener('click', () => {
    bulkActionContext = null;
    void applyBulkMissionScore();
  });

  updateBulkSelection();
  void loadBulkTaskOptions();
})();
</script>

