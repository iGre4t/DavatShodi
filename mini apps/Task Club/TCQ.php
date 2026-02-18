<?php
declare(strict_types=1);

$tcqStorePath = __DIR__ . '/TCQ list.json';
$tcqInviteesCsvPath = __DIR__ . '/TC Event/Invitees mapped.csv';
$tcqAnswersCsvPath = __DIR__ . '/TC Event/Answers.csv';
$tcqCodeStatePath = __DIR__ . '/TCQ code state.json';
$tcqSettingsPath = __DIR__ . '/TCQ settings.json';
const TCQ_DEFAULT_SETTINGS = [
  'answerTimeLimit' => true,
  'randomOrder' => true
];

function tcqReadCsv(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $rows = [];
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  while (($row = fgetcsv($handle)) !== false) {
    $rows[] = $row;
  }
  fclose($handle);
  return $rows;
}

function tcqWriteCsv(string $path, array $rows): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $handle = fopen($path, 'c+');
  if ($handle === false) {
    return false;
  }
  if (!flock($handle, LOCK_EX)) {
    fclose($handle);
    return false;
  }
  ftruncate($handle, 0);
  rewind($handle);
  foreach ($rows as $row) {
    fputcsv($handle, $row);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

function tcqNormalizeHeader(string $value): string
{
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value ?? '';
}

function tcqFindHeaderIndex(array $header, string $name): int
{
  $needle = tcqNormalizeHeader($name);
  foreach ($header as $index => $value) {
    if (tcqNormalizeHeader((string)$value) === $needle) {
      return (int)$index;
    }
  }
  return -1;
}

function tcqEnsureInviteesColumns(string $path): void
{
  $rows = tcqReadCsv($path);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return;
  }
  $header = $rows[0];
  $required = ['invitees', 'Answered'];
  $changed = false;
  foreach ($required as $columnName) {
    $idx = tcqFindHeaderIndex($header, $columnName);
    if ($idx >= 0) {
      continue;
    }
    $header[] = $columnName;
    $newIndex = count($header) - 1;
    for ($i = 1; $i < count($rows); $i += 1) {
      if (!is_array($rows[$i])) {
        $rows[$i] = [];
      }
      $rows[$i][$newIndex] = '';
    }
    $changed = true;
  }
  if (!$changed) {
    return;
  }
  $rows[0] = $header;
  tcqWriteCsv($path, $rows);
}

function tcqNormalizeItem(array $item): array
{
  $type = trim(mb_strtolower((string)($item['type'] ?? 'mcq'), 'UTF-8'));
  if ($type !== 'percentage') {
    $type = 'mcq';
  }
  $answers = is_array($item['answers'] ?? null) ? array_values($item['answers']) : [];
  while (count($answers) < 4) {
    $answers[] = '';
  }
  $answers = array_slice($answers, 0, 4);
  if ($type === 'percentage') {
    $answers = ['', '', '', ''];
  }
  return [
    'id' => trim((string)($item['id'] ?? '')) ?: ('q_' . bin2hex(random_bytes(6))),
    'code' => trim((string)($item['code'] ?? '')),
    'type' => $type,
    'question' => trim((string)($item['question'] ?? '')),
    'answers' => array_map(static fn($v) => trim((string)$v), $answers),
    'createdAt' => trim((string)($item['createdAt'] ?? '')) ?: date('Y-m-d H:i:s')
  ];
}

function tcqLoadStore(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return [];
  }
  $items = [];
  foreach ($decoded as $row) {
    if (is_array($row)) {
      $items[] = tcqNormalizeItem($row);
    }
  }
  return $items;
}

function tcqSaveStore(string $path, array $rows): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqLoadSettings(string $path): array
{
  $settings = TCQ_DEFAULT_SETTINGS;
  if (!is_file($path)) {
    return $settings;
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return $settings;
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return $settings;
  }
  $settings['answerTimeLimit'] = (bool)($decoded['answerTimeLimit'] ?? $settings['answerTimeLimit']);
  $settings['randomOrder'] = (bool)($decoded['randomOrder'] ?? $settings['randomOrder']);
  return $settings;
}

function tcqSaveSettings(string $path, array $settings): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $payload = [
    'answerTimeLimit' => (bool)($settings['answerTimeLimit'] ?? true),
    'randomOrder' => (bool)($settings['randomOrder'] ?? true)
  ];
  $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqLoadCodeState(string $path): array
{
  if (!is_file($path)) {
    return ['nextNumber' => 1];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return ['nextNumber' => 1];
  }
  $decoded = json_decode($content, true);
  $next = is_array($decoded) ? (int)($decoded['nextNumber'] ?? 1) : 1;
  if ($next < 1) {
    $next = 1;
  }
  return ['nextNumber' => $next];
}

function tcqSaveCodeState(string $path, array $state): bool
{
  $dir = dirname($path);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }
  $next = (int)($state['nextNumber'] ?? 1);
  if ($next < 1) {
    $next = 1;
  }
  $json = json_encode(['nextNumber' => $next], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function tcqExtractCodeNumber(string $code): int
{
  if (!preg_match('/^Q(\d+)$/', $code, $m)) {
    return -1;
  }
  return (int)$m[1];
}

function tcqFormatCode(int $number): string
{
  return 'Q' . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
}

function tcqReserveOrCreateCode(string $candidateCode, array &$usedCodes, array &$state): string
{
  $candidate = strtoupper(trim($candidateCode));
  if ($candidate !== '' && !isset($usedCodes[$candidate])) {
    $num = tcqExtractCodeNumber($candidate);
    if ($num > 0) {
      $usedCodes[$candidate] = true;
      $next = (int)($state['nextNumber'] ?? 1);
      if ($num >= $next) {
        $state['nextNumber'] = $num + 1;
      }
      return $candidate;
    }
  }

  $next = max(1, (int)($state['nextNumber'] ?? 1));
  while (true) {
    $code = tcqFormatCode($next);
    if (!isset($usedCodes[$code])) {
      $usedCodes[$code] = true;
      $state['nextNumber'] = $next + 1;
      return $code;
    }
    $next += 1;
  }
}

function tcqBuildItemByIdMap(array $items): array
{
  $map = [];
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = trim((string)($item['id'] ?? ''));
    if ($id === '') {
      continue;
    }
    $map[$id] = tcqNormalizeItem($item);
  }
  return $map;
}

function tcqBuildAnswersHeader(array $items): array
{
  $header = ['Work ID'];
  foreach ($items as $item) {
    if (!is_array($item)) {
      continue;
    }
    $code = strtoupper(trim((string)($item['code'] ?? '')));
    $question = trim((string)($item['question'] ?? ''));
    if ($code === '' || $question === '') {
      continue;
    }
    $header[] = "{$code} | {$question}";
  }
  return $header;
}

function tcqExtractCodeFromAnswerHeader(string $headerCell): string
{
  if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*(?:\||$)/', $headerCell, $m)) {
    return '';
  }
  return strtoupper(trim((string)$m[1]));
}

function tcqSyncAnswersSheet(string $answersPath, array $oldItems, array $newItems): bool
{
  $rows = tcqReadCsv($answersPath);
  $header = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : ['Work ID'];
  $workIdIndex = tcqFindHeaderIndex($header, 'Work ID');
  if ($workIdIndex < 0) {
    $header = array_merge(['Work ID'], array_values($header));
    $workIdIndex = 0;
  }

  $oldHeaderLookup = [];
  $oldHeaderByCode = [];
  foreach ($header as $idx => $name) {
    $cell = trim((string)$name);
    $key = $cell;
    if ($key !== '' && !array_key_exists($key, $oldHeaderLookup)) {
      $oldHeaderLookup[$key] = (int)$idx;
    }
    $code = tcqExtractCodeFromAnswerHeader($cell);
    if ($code !== '' && !array_key_exists($code, $oldHeaderByCode)) {
      $oldHeaderByCode[$code] = (int)$idx;
    }
  }

  $oldById = tcqBuildItemByIdMap($oldItems);
  $newHeader = tcqBuildAnswersHeader($newItems);
  $columnSources = [];
  for ($i = 1; $i < count($newHeader); $i += 1) {
    $newHeaderCell = $newHeader[$i];
    $sourceIndex = -1;
    $newItem = $newItems[$i - 1] ?? null;
    if (is_array($newItem)) {
      $id = trim((string)($newItem['id'] ?? ''));
      $newCode = strtoupper(trim((string)($newItem['code'] ?? '')));
      if ($newCode !== '' && array_key_exists($newCode, $oldHeaderByCode)) {
        $sourceIndex = (int)$oldHeaderByCode[$newCode];
      }
      if ($id !== '' && isset($oldById[$id])) {
        $oldCode = strtoupper(trim((string)($oldById[$id]['code'] ?? '')));
        if ($sourceIndex < 0 && $oldCode !== '' && array_key_exists($oldCode, $oldHeaderByCode)) {
          $sourceIndex = (int)$oldHeaderByCode[$oldCode];
        }
        $oldHeaderCell = '';
        if ($oldCode !== '') {
          $oldQuestion = trim((string)($oldById[$id]['question'] ?? ''));
          if ($oldQuestion !== '') {
            $oldHeaderCell = "{$oldCode} | {$oldQuestion}";
          }
        }
        if ($sourceIndex < 0 && $oldHeaderCell !== '' && array_key_exists($oldHeaderCell, $oldHeaderLookup)) {
          $sourceIndex = (int)$oldHeaderLookup[$oldHeaderCell];
        }
        $oldQuestionOnly = trim((string)($oldById[$id]['question'] ?? ''));
        if ($sourceIndex < 0 && $oldQuestionOnly !== '' && array_key_exists($oldQuestionOnly, $oldHeaderLookup)) {
          $sourceIndex = (int)$oldHeaderLookup[$oldQuestionOnly];
        }
      }
    }
    if ($sourceIndex < 0 && array_key_exists($newHeaderCell, $oldHeaderLookup)) {
      $sourceIndex = (int)$oldHeaderLookup[$newHeaderCell];
    }
    $columnSources[] = $sourceIndex;
  }

  $syncedRows = [$newHeader];
  for ($i = 1; $i < count($rows); $i += 1) {
    $row = is_array($rows[$i]) ? $rows[$i] : [];
    $workId = trim((string)($row[$workIdIndex] ?? ''));
    if ($workId === '') {
      continue;
    }
    $nextRow = [$workId];
    foreach ($columnSources as $sourceIndex) {
      $nextRow[] = $sourceIndex >= 0 ? (string)($row[$sourceIndex] ?? '') : '';
    }
    $syncedRows[] = $nextRow;
  }

  return tcqWriteCsv($answersPath, $syncedRows);
}

if (!defined('TCQ_INCLUDE_ONLY')) {
  define('TCQ_INCLUDE_ONLY', false);
}

if (!TCQ_INCLUDE_ONLY && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['tcq_action']))) {
  header('Content-Type: application/json; charset=utf-8');
  $action = trim((string)($_POST['tcq_action'] ?? ''));

  if ($action === 'list') {
    tcqEnsureInviteesColumns($tcqInviteesCsvPath);
    $items = tcqLoadStore($tcqStorePath);
    $codeState = tcqLoadCodeState($tcqCodeStatePath);
    $usedCodes = [];
    $repaired = [];
    foreach ($items as $item) {
      $item['code'] = tcqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $repaired[] = $item;
    }
    if (count($repaired) === count($items)) {
      $items = $repaired;
      tcqSaveStore($tcqStorePath, $items);
      tcqSaveCodeState($tcqCodeStatePath, $codeState);
    }
    if (!tcqSyncAnswersSheet($tcqAnswersCsvPath, $items, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    $settings = tcqLoadSettings($tcqSettingsPath);
    echo json_encode(['status' => 'ok', 'items' => $items, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_settings') {
    $answerTimeLimitRaw = trim((string)($_POST['answer_time_limit'] ?? '1'));
    $randomOrderRaw = trim((string)($_POST['random_order'] ?? '1'));
    $settings = [
      'answerTimeLimit' => in_array($answerTimeLimitRaw, ['1', 'true', 'on'], true),
      'randomOrder' => in_array($randomOrderRaw, ['1', 'true', 'on'], true)
    ];
    if (!tcqSaveSettings($tcqSettingsPath, $settings)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save general settings.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'General settings saved.', 'settings' => tcqLoadSettings($tcqSettingsPath)], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_all') {
    tcqEnsureInviteesColumns($tcqInviteesCsvPath);
    $raw = (string)($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $oldItems = tcqLoadStore($tcqStorePath);
    $codeState = tcqLoadCodeState($tcqCodeStatePath);
    $usedCodes = [];
    $items = [];
    foreach ($decoded as $index => $row) {
      if (!is_array($row)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid row data.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $item = tcqNormalizeItem($row);
      if ($item['question'] === '') {
        $num = $index + 1;
        echo json_encode(['status' => 'error', 'message' => "Question in row {$num} is required."], JSON_UNESCAPED_UNICODE);
        exit;
      }
      if (($item['type'] ?? 'mcq') === 'mcq') {
        foreach ($item['answers'] as $ans) {
          if ($ans === '') {
            $num = $index + 1;
            echo json_encode(['status' => 'error', 'message' => "All 4 answers in row {$num} are required for MCQ."], JSON_UNESCAPED_UNICODE);
            exit;
          }
        }
      }
      $item['code'] = tcqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $items[] = $item;
    }

    if (!tcqSaveStore($tcqStorePath, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tcqSaveCodeState($tcqCodeStatePath, $codeState)) {
      tcqSaveStore($tcqStorePath, $oldItems);
      echo json_encode(['status' => 'error', 'message' => 'Failed to save question code state.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!tcqSyncAnswersSheet($tcqAnswersCsvPath, $oldItems, $items)) {
      tcqSaveStore($tcqStorePath, $oldItems);
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with saved questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'All changes saved.', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (TCQ_INCLUDE_ONLY) {
  return;
}
?>

<div class="card">
  <div class="section-header">
    <h3>General Setting</h3>
  </div>
  <div class="tcq-settings-grid">
    <label class="tcq-settings-row">
      <span>Answer Time Limit</span>
      <input id="tcq-setting-answer-time-limit" type="checkbox" checked />
    </label>
    <label class="tcq-settings-row">
      <span>Random Order</span>
      <input id="tcq-setting-random-order" type="checkbox" checked />
    </label>
  </div>
  <div class="tcq-settings-actions">
    <button id="tcq-save-settings" type="button" class="btn primary">Save General Settings</button>
  </div>
  <p id="tcq-settings-status" class="muted small tcq-settings-status" aria-live="polite"></p>
</div>

<div class="card">
  <div class="section-header">
    <h3>Question</h3>
  </div>

  <form id="tcq-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Question Type</span>
      <div class="tcq-type-group">
        <label class="tcq-type-option">
          <input type="radio" name="questionType" value="mcq" checked />
          <span>MCQ (Multi Choice Question)</span>
        </label>
        <label class="tcq-type-option">
          <input type="radio" name="questionType" value="percentage" />
          <span>Percentage Question</span>
        </label>
      </div>
      <p class="tcq-type-hint">Percentage Question is a poll (0 to 100) and does not need multiple answers.</p>
    </label>
    <label class="field standard-width">
      <span>Question</span>
      <input id="tcq-question-input" name="question" type="text" autocomplete="off" required />
    </label>
    <div id="tcq-answer-grid" class="form grid tcq-answer-grid">
      <label class="field standard-width">
        <span>Answer 1 (Correct)</span>
        <input id="tcq-answer-1" name="answer1" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 2</span>
        <input id="tcq-answer-2" name="answer2" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 3</span>
        <input id="tcq-answer-3" name="answer3" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 4</span>
        <input id="tcq-answer-4" name="answer4" type="text" autocomplete="off" required />
      </label>
    </div>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
    <p id="tcq-status" class="muted small" aria-live="polite"></p>
  </form>

  <div class="section-header" style="margin-top:12px;">
    <h3>Questions list</h3>
  </div>
  <div class="table-wrapper">
    <table class="tcq-list-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Question & Answers</th>
          <th>Actions</th>
          <th class="tcq-drag-cell">Sort</th>
        </tr>
      </thead>
      <tbody id="tcq-list-body">
        <tr><td colspan="4" class="muted">Loading questions...</td></tr>
      </tbody>
    </table>
  </div>

  <div class="tcq-save-wrap">
    <button id="tcq-save-all" type="button" class="btn primary standard-primary-button">Save</button>
  </div>
</div>

<script>
(() => {
  const endpoint = 'mini%20apps/Task%20Club/TCQ.php';
  const form = document.getElementById('tcq-form');
  const input = document.getElementById('tcq-question-input');
  const body = document.getElementById('tcq-list-body');
  const statusEl = document.getElementById('tcq-status');
  const saveAllBtn = document.getElementById('tcq-save-all');
  const answerTimeLimitToggle = document.getElementById('tcq-setting-answer-time-limit');
  const randomOrderToggle = document.getElementById('tcq-setting-random-order');
  const saveSettingsBtn = document.getElementById('tcq-save-settings');
  const settingsStatusEl = document.getElementById('tcq-settings-status');
  const formAnswerGrid = document.getElementById('tcq-answer-grid');
  if (!form || !input || !body || !statusEl || !saveAllBtn || !formAnswerGrid) return;
  const formTypeInputs = form.querySelectorAll('input[name="questionType"]');
  const formAnswerInputs = form.querySelectorAll('input[name="answer1"], input[name="answer2"], input[name="answer3"], input[name="answer4"]');

  let items = [];
  let draggedRowId = '';

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const makeId = () => `q_${Math.random().toString(36).slice(2, 10)}`;
  const normalizeType = (value) => String(value ?? '').toLowerCase() === 'percentage' ? 'percentage' : 'mcq';

  const normalizeAnswers = (list) => {
    const answers = Array.isArray(list) ? list.slice(0, 4) : [];
    while (answers.length < 4) answers.push('');
    return answers.map((v) => String(v ?? ''));
  };

  const getSelectedType = () => {
    const selected = form.querySelector('input[name="questionType"]:checked');
    return normalizeType(selected ? selected.value : 'mcq');
  };

  const syncAddFormTypeState = () => {
    const type = getSelectedType();
    const isMcq = type === 'mcq';
    formAnswerGrid.classList.toggle('tcq-hidden', !isMcq);
    formAnswerInputs.forEach((field) => {
      field.required = isMcq;
      if (!isMcq) {
        field.value = '';
      }
    });
  };

  const setStatus = (message, isError = false) => {
    statusEl.textContent = message || '';
    statusEl.style.color = isError ? '#d1434a' : '';
  };

  const setSettingsStatus = (message, isError = false) => {
    if (!settingsStatusEl) return;
    settingsStatusEl.textContent = message || '';
    settingsStatusEl.style.color = isError ? '#d1434a' : '';
  };

  const applySettingsToForm = (settings) => {
    if (!(answerTimeLimitToggle instanceof HTMLInputElement) || !(randomOrderToggle instanceof HTMLInputElement)) return;
    answerTimeLimitToggle.checked = Boolean(settings?.answerTimeLimit ?? true);
    randomOrderToggle.checked = Boolean(settings?.randomOrder ?? true);
  };

  const validateAll = () => {
    for (let i = 0; i < items.length; i += 1) {
      const row = items[i];
      if (!String(row.question || '').trim()) {
        return `Question in row ${i + 1} is required.`;
      }
      const type = normalizeType(row.type);
      if (type === 'mcq') {
        const answers = normalizeAnswers(row.answers);
        if (answers.some((ans) => !String(ans).trim())) {
          return `All 4 answers in row ${i + 1} are required for MCQ.`;
        }
      }
    }
    return '';
  };

  const render = () => {
    if (!items.length) {
      body.innerHTML = '<tr><td colspan="4" class="muted">No questions added yet.</td></tr>';
      return;
    }

    body.innerHTML = items.map((item, index) => {
      const type = normalizeType(item.type);
      const isMcq = type === 'mcq';
      const answers = normalizeAnswers(item.answers);
      return `<tr data-row-id="${esc(item.id)}" draggable="true">
        <td>${index + 1}</td>
        <td>
          <div class="tcq-row-grid">
            <div class="muted small">Code: ${esc(item.code || 'Auto')}</div>
            <div class="tcq-type-group">
              <label class="tcq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="mcq" ${isMcq ? 'checked' : ''} />
                <span>MCQ</span>
              </label>
              <label class="tcq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="percentage" ${!isMcq ? 'checked' : ''} />
                <span>Percentage Question</span>
              </label>
            </div>
            <input class="tcq-field" type="text" data-field="question" value="${esc(item.question)}" />
            <div class="tcq-answer-grid ${isMcq ? '' : 'tcq-hidden'}">
              <input class="tcq-field" type="text" data-field="answer0" value="${esc(answers[0])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer1" value="${esc(answers[1])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer2" value="${esc(answers[2])}" ${isMcq ? '' : 'disabled'} />
              <input class="tcq-field" type="text" data-field="answer3" value="${esc(answers[3])}" ${isMcq ? '' : 'disabled'} />
            </div>
          </div>
        </td>
        <td>
          <div class="tcq-list-actions">
            <button type="button" class="btn ghost" data-delete-id="${esc(item.id)}">Delete</button>
          </div>
        </td>
        <td class="tcq-drag-cell">
          <button type="button" class="tcq-drag-handle" data-drag-handle="1" title="Drag to reorder" aria-label="Drag to reorder">&#9776;</button>
        </td>
      </tr>`;
    }).join('');
  };

  const reorderById = (dragId, targetId, placeAfter) => {
    const fromIndex = items.findIndex((item) => item.id === dragId);
    const toIndex = items.findIndex((item) => item.id === targetId);
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return false;
    const [moved] = items.splice(fromIndex, 1);
    let insertIndex = toIndex;
    if (fromIndex < toIndex) {
      insertIndex = placeAfter ? toIndex : toIndex - 1;
    } else {
      insertIndex = placeAfter ? toIndex + 1 : toIndex;
    }
    insertIndex = Math.max(0, Math.min(items.length, insertIndex));
    items.splice(insertIndex, 0, moved);
    return true;
  };

  const clearDragVisuals = () => {
    Array.from(body.querySelectorAll('.tcq-row-drop-target')).forEach((node) => {
      node.classList.remove('tcq-row-drop-target');
    });
    Array.from(body.querySelectorAll('.tcq-row-dragging')).forEach((node) => {
      node.classList.remove('tcq-row-dragging');
    });
  };

  const postAction = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('tcq_action', action);
    Object.entries(payload).forEach(([key, value]) => {
      formData.append(key, String(value ?? ''));
    });
    const response = await fetch(endpoint, { method: 'POST', body: formData });
    const data = await response.json();
    if (!response.ok || data?.status !== 'ok') {
      throw new Error(data?.message || 'Request failed.');
    }
    return data;
  };

  const syncFromServer = async () => {
    const data = await postAction('list');
    items = Array.isArray(data.items) ? data.items.map((item) => ({
      id: String(item.id || makeId()),
      code: String(item.code || ''),
      type: normalizeType(item.type),
      question: String(item.question || ''),
      answers: normalizeAnswers(item.answers),
      createdAt: String(item.createdAt || '')
    })) : [];
    applySettingsToForm(data.settings || {});
    render();
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const fd = new FormData(form);
    const type = normalizeType(fd.get('questionType'));
    const next = {
      id: makeId(),
      code: '',
      type,
      question: String(fd.get('question') ?? '').trim(),
      answers: type === 'mcq' ? [
        String(fd.get('answer1') ?? '').trim(),
        String(fd.get('answer2') ?? '').trim(),
        String(fd.get('answer3') ?? '').trim(),
        String(fd.get('answer4') ?? '').trim()
      ] : ['', '', '', ''],
      createdAt: new Date().toISOString().slice(0, 19).replace('T', ' ')
    };
    if (!next.question) {
      setStatus('Question is required.', true);
      return;
    }
    if (next.type === 'mcq' && next.answers.some((ans) => !ans)) {
      setStatus('All 4 answers are required for MCQ.', true);
      return;
    }
    items.push(next);
    render();
    setStatus('Question added to list. Click Save to persist changes.');
    form.reset();
    const defaultType = form.querySelector('input[name="questionType"][value="mcq"]');
    if (defaultType instanceof HTMLInputElement) {
      defaultType.checked = true;
    }
    syncAddFormTypeState();
    input.focus();
  });

  body.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!row) return;
    const id = row.getAttribute('data-row-id') || '';
    const idx = items.findIndex((item) => item.id === id);
    if (idx < 0) return;
    const field = target.dataset.field || '';
    if (field === 'question') {
      items[idx].question = target.value;
      return;
    }
    if (field.startsWith('answer')) {
      const pos = Number.parseInt(field.replace('answer', ''), 10);
      if (Number.isInteger(pos) && pos >= 0 && pos < 4) {
        const answers = normalizeAnswers(items[idx].answers);
        answers[pos] = target.value;
        items[idx].answers = answers;
      }
    }
  });

  body.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.dataset.field !== 'type') return;
    const row = target.closest('tr[data-row-id]');
    if (!row) return;
    const id = row.getAttribute('data-row-id') || '';
    const idx = items.findIndex((item) => item.id === id);
    if (idx < 0) return;
    items[idx].type = normalizeType(target.value);
    if (items[idx].type === 'percentage') {
      items[idx].answers = ['', '', '', ''];
    } else {
      items[idx].answers = normalizeAnswers(items[idx].answers);
    }
    render();
  });

  body.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const deleteBtn = target.closest('[data-delete-id]');
    if (!deleteBtn) return;
    const id = deleteBtn.getAttribute('data-delete-id') || '';
    items = items.filter((item) => item.id !== id);
    render();
    setStatus('Row removed from list. Click Save to persist changes.');
  });

  body.addEventListener('dragstart', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const handle = target.closest('[data-drag-handle]');
    if (!handle) {
      event.preventDefault();
      return;
    }
    const row = handle.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) {
      event.preventDefault();
      return;
    }
    draggedRowId = row.getAttribute('data-row-id') || '';
    if (!draggedRowId) {
      event.preventDefault();
      return;
    }
    row.classList.add('tcq-row-dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', draggedRowId);
    }
  });

  body.addEventListener('dragover', (event) => {
    if (!draggedRowId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-row-id') || '';
    if (!targetId || targetId === draggedRowId) return;
    event.preventDefault();
    clearDragVisuals();
    row.classList.add('tcq-row-drop-target');
  });

  body.addEventListener('drop', (event) => {
    if (!draggedRowId) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const row = target.closest('tr[data-row-id]');
    if (!(row instanceof HTMLTableRowElement)) return;
    const targetId = row.getAttribute('data-row-id') || '';
    if (!targetId || targetId === draggedRowId) return;
    event.preventDefault();
    const rect = row.getBoundingClientRect();
    const placeAfter = event.clientY > (rect.top + rect.height / 2);
    if (reorderById(draggedRowId, targetId, placeAfter)) {
      render();
      setStatus('Order changed. Click Save to persist changes.');
    }
    draggedRowId = '';
    clearDragVisuals();
  });

  body.addEventListener('dragend', () => {
    draggedRowId = '';
    clearDragVisuals();
  });

  saveAllBtn.addEventListener('click', async () => {
    const validationError = validateAll();
    if (validationError) {
      setStatus(validationError, true);
      return;
    }
    saveAllBtn.disabled = true;
    try {
      const data = await postAction('save_all', { items: JSON.stringify(items) });
      items = Array.isArray(data.items) ? data.items : items;
      render();
      setStatus(data.message || 'All changes saved.');
    } catch (error) {
      setStatus(error?.message || 'Failed to save changes.', true);
    } finally {
      saveAllBtn.disabled = false;
    }
  });

  formTypeInputs.forEach((radio) => {
    radio.addEventListener('change', syncAddFormTypeState);
  });
  syncAddFormTypeState();

  if (saveSettingsBtn instanceof HTMLButtonElement) {
    saveSettingsBtn.addEventListener('click', async () => {
      if (!(answerTimeLimitToggle instanceof HTMLInputElement) || !(randomOrderToggle instanceof HTMLInputElement)) return;
      saveSettingsBtn.disabled = true;
      try {
        const data = await postAction('save_settings', {
          answer_time_limit: answerTimeLimitToggle.checked ? '1' : '0',
          random_order: randomOrderToggle.checked ? '1' : '0'
        });
        applySettingsToForm(data.settings || {});
        setSettingsStatus(data.message || 'General settings saved.');
      } catch (error) {
        setSettingsStatus(error?.message || 'Failed to save general settings.', true);
      } finally {
        saveSettingsBtn.disabled = false;
      }
    });
  }

  syncFromServer()
    .then(() => setStatus(''))
    .catch((error) => {
      items = [];
      render();
      setStatus(error?.message || 'Failed to load questions.', true);
    });
})();
</script>


