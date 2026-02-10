<?php
declare(strict_types=1);

$wfqStorePath = __DIR__ . '/WFQ list.json';
$wfqInviteesCsvPath = __DIR__ . '/WF Event/Invitees mapped.csv';
$wfqAnswersCsvPath = __DIR__ . '/WF Event/Answers.csv';
$wfqCodeStatePath = __DIR__ . '/WFQ code state.json';

function wfqReadCsv(string $path): array
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

function wfqWriteCsv(string $path, array $rows): bool
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

function wfqNormalizeHeader(string $value): string
{
  $value = trim(mb_strtolower($value, 'UTF-8'));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value ?? '';
}

function wfqFindHeaderIndex(array $header, string $name): int
{
  $needle = wfqNormalizeHeader($name);
  foreach ($header as $index => $value) {
    if (wfqNormalizeHeader((string)$value) === $needle) {
      return (int)$index;
    }
  }
  return -1;
}

function wfqEnsureInviteesColumns(string $path): void
{
  $rows = wfqReadCsv($path);
  if (!$rows || !isset($rows[0]) || !is_array($rows[0])) {
    return;
  }
  $header = $rows[0];
  $required = ['invitees', 'Answered'];
  $changed = false;
  foreach ($required as $columnName) {
    $idx = wfqFindHeaderIndex($header, $columnName);
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
  wfqWriteCsv($path, $rows);
}

function wfqNormalizeItem(array $item): array
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

function wfqLoadStore(string $path): array
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
      $items[] = wfqNormalizeItem($row);
    }
  }
  return $items;
}

function wfqSaveStore(string $path, array $rows): bool
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

function wfqLoadCodeState(string $path): array
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

function wfqSaveCodeState(string $path, array $state): bool
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

function wfqExtractCodeNumber(string $code): int
{
  if (!preg_match('/^Q(\d+)$/', $code, $m)) {
    return -1;
  }
  return (int)$m[1];
}

function wfqFormatCode(int $number): string
{
  return 'Q' . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
}

function wfqReserveOrCreateCode(string $candidateCode, array &$usedCodes, array &$state): string
{
  $candidate = strtoupper(trim($candidateCode));
  if ($candidate !== '' && !isset($usedCodes[$candidate])) {
    $num = wfqExtractCodeNumber($candidate);
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
    $code = wfqFormatCode($next);
    if (!isset($usedCodes[$code])) {
      $usedCodes[$code] = true;
      $state['nextNumber'] = $next + 1;
      return $code;
    }
    $next += 1;
  }
}

function wfqBuildItemByIdMap(array $items): array
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
    $map[$id] = wfqNormalizeItem($item);
  }
  return $map;
}

function wfqBuildAnswersHeader(array $items): array
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

function wfqExtractCodeFromAnswerHeader(string $headerCell): string
{
  if (!preg_match('/^\s*(Q\d+)\b/i', $headerCell, $m)) {
    return '';
  }
  return strtoupper(trim((string)$m[1]));
}

function wfqSyncAnswersSheet(string $answersPath, array $oldItems, array $newItems): bool
{
  $rows = wfqReadCsv($answersPath);
  $header = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : ['Work ID'];
  $workIdIndex = wfqFindHeaderIndex($header, 'Work ID');
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
    $code = wfqExtractCodeFromAnswerHeader($cell);
    if ($code !== '' && !array_key_exists($code, $oldHeaderByCode)) {
      $oldHeaderByCode[$code] = (int)$idx;
    }
  }

  $oldById = wfqBuildItemByIdMap($oldItems);
  $newHeader = wfqBuildAnswersHeader($newItems);
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

  return wfqWriteCsv($answersPath, $syncedRows);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['wfq_action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $action = trim((string)($_POST['wfq_action'] ?? ''));

  if ($action === 'list') {
    wfqEnsureInviteesColumns($wfqInviteesCsvPath);
    $items = wfqLoadStore($wfqStorePath);
    $codeState = wfqLoadCodeState($wfqCodeStatePath);
    $usedCodes = [];
    $repaired = [];
    foreach ($items as $item) {
      $item['code'] = wfqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $repaired[] = $item;
    }
    if (count($repaired) === count($items)) {
      $items = $repaired;
      wfqSaveStore($wfqStorePath, $items);
      wfqSaveCodeState($wfqCodeStatePath, $codeState);
    }
    if (!wfqSyncAnswersSheet($wfqAnswersCsvPath, $items, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    echo json_encode(['status' => 'ok', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_all') {
    wfqEnsureInviteesColumns($wfqInviteesCsvPath);
    $raw = (string)($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $oldItems = wfqLoadStore($wfqStorePath);
    $codeState = wfqLoadCodeState($wfqCodeStatePath);
    $usedCodes = [];
    $items = [];
    foreach ($decoded as $index => $row) {
      if (!is_array($row)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid row data.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $item = wfqNormalizeItem($row);
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
      $item['code'] = wfqReserveOrCreateCode((string)($item['code'] ?? ''), $usedCodes, $codeState);
      $items[] = $item;
    }

    if (!wfqSaveStore($wfqStorePath, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!wfqSaveCodeState($wfqCodeStatePath, $codeState)) {
      wfqSaveStore($wfqStorePath, $oldItems);
      echo json_encode(['status' => 'error', 'message' => 'Failed to save question code state.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (!wfqSyncAnswersSheet($wfqAnswersCsvPath, $oldItems, $items)) {
      wfqSaveStore($wfqStorePath, $oldItems);
      echo json_encode(['status' => 'error', 'message' => 'Failed to sync Answers.csv with saved questions.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'All changes saved.', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['status' => 'error', 'message' => 'Unsupported action.'], JSON_UNESCAPED_UNICODE);
  exit;
}
?>

<style>
  .wfq-answer-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    direction: rtl;
  }
  .wfq-list-table td {
    vertical-align: top;
  }
  .wfq-row-grid {
    display: grid;
    gap: 8px;
  }
  .wfq-field {
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 12px;
    font: inherit;
    background: #fff;
  }
  .wfq-list-actions {
    display: flex;
    justify-content: center;
  }
  .wfq-save-wrap {
    margin-top: 12px;
  }
  .wfq-type-group {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    direction: rtl;
  }
  .wfq-type-option {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
  }
  .wfq-type-option input {
    margin: 0;
  }
  .wfq-hidden {
    display: none;
  }
  .wfq-type-hint {
    margin: 0;
    color: var(--muted);
    font-size: 12px;
  }
  @media (max-width: 900px) {
    .wfq-answer-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
</style>

<div class="card">
  <div class="section-header">
    <h3>Question</h3>
  </div>

  <form id="wfq-form" class="form" style="gap:12px;">
    <label class="field standard-width">
      <span>Question Type</span>
      <div class="wfq-type-group">
        <label class="wfq-type-option">
          <input type="radio" name="questionType" value="mcq" checked />
          <span>MCQ (Multi Choice Question)</span>
        </label>
        <label class="wfq-type-option">
          <input type="radio" name="questionType" value="percentage" />
          <span>Percentage Question</span>
        </label>
      </div>
      <p class="wfq-type-hint">Percentage Question is a poll (0 to 100) and does not need multiple answers.</p>
    </label>
    <label class="field standard-width">
      <span>Question</span>
      <input id="wfq-question-input" name="question" type="text" autocomplete="off" required />
    </label>
    <div id="wfq-answer-grid" class="form grid wfq-answer-grid">
      <label class="field standard-width">
        <span>Answer 1 (Correct)</span>
        <input id="wfq-answer-1" name="answer1" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 2</span>
        <input id="wfq-answer-2" name="answer2" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 3</span>
        <input id="wfq-answer-3" name="answer3" type="text" autocomplete="off" required />
      </label>
      <label class="field standard-width">
        <span>Answer 4</span>
        <input id="wfq-answer-4" name="answer4" type="text" autocomplete="off" required />
      </label>
    </div>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
    <p id="wfq-status" class="muted small" aria-live="polite"></p>
  </form>

  <div class="section-header" style="margin-top:12px;">
    <h3>Questions list</h3>
  </div>
  <div class="table-wrapper">
    <table class="wfq-list-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Question & Answers</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="wfq-list-body">
        <tr><td colspan="3" class="muted">Loading questions...</td></tr>
      </tbody>
    </table>
  </div>

  <div class="wfq-save-wrap">
    <button id="wfq-save-all" type="button" class="btn primary standard-primary-button">Save</button>
  </div>
</div>

<script>
(() => {
  const endpoint = 'Wheel%20of%20Fortune/WFQ.php';
  const form = document.getElementById('wfq-form');
  const input = document.getElementById('wfq-question-input');
  const body = document.getElementById('wfq-list-body');
  const statusEl = document.getElementById('wfq-status');
  const saveAllBtn = document.getElementById('wfq-save-all');
  const formAnswerGrid = document.getElementById('wfq-answer-grid');
  if (!form || !input || !body || !statusEl || !saveAllBtn || !formAnswerGrid) return;
  const formTypeInputs = form.querySelectorAll('input[name="questionType"]');
  const formAnswerInputs = form.querySelectorAll('input[name="answer1"], input[name="answer2"], input[name="answer3"], input[name="answer4"]');

  let items = [];

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
    formAnswerGrid.classList.toggle('wfq-hidden', !isMcq);
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
      body.innerHTML = '<tr><td colspan="3" class="muted">No questions added yet.</td></tr>';
      return;
    }

    body.innerHTML = items.map((item, index) => {
      const type = normalizeType(item.type);
      const isMcq = type === 'mcq';
      const answers = normalizeAnswers(item.answers);
      return `<tr data-row-id="${esc(item.id)}">
        <td>${index + 1}</td>
        <td>
          <div class="wfq-row-grid">
            <div class="muted small">Code: ${esc(item.code || 'Auto')}</div>
            <div class="wfq-type-group">
              <label class="wfq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="mcq" ${isMcq ? 'checked' : ''} />
                <span>MCQ</span>
              </label>
              <label class="wfq-type-option">
                <input type="radio" name="row-type-${esc(item.id)}" data-field="type" value="percentage" ${!isMcq ? 'checked' : ''} />
                <span>Percentage Question</span>
              </label>
            </div>
            <input class="wfq-field" type="text" data-field="question" value="${esc(item.question)}" />
            <div class="wfq-answer-grid ${isMcq ? '' : 'wfq-hidden'}">
              <input class="wfq-field" type="text" data-field="answer0" value="${esc(answers[0])}" ${isMcq ? '' : 'disabled'} />
              <input class="wfq-field" type="text" data-field="answer1" value="${esc(answers[1])}" ${isMcq ? '' : 'disabled'} />
              <input class="wfq-field" type="text" data-field="answer2" value="${esc(answers[2])}" ${isMcq ? '' : 'disabled'} />
              <input class="wfq-field" type="text" data-field="answer3" value="${esc(answers[3])}" ${isMcq ? '' : 'disabled'} />
            </div>
          </div>
        </td>
        <td>
          <div class="wfq-list-actions">
            <button type="button" class="btn ghost" data-delete-id="${esc(item.id)}">Delete</button>
          </div>
        </td>
      </tr>`;
    }).join('');
  };

  const postAction = async (action, payload = {}) => {
    const formData = new FormData();
    formData.append('wfq_action', action);
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

  syncFromServer()
    .then(() => setStatus(''))
    .catch((error) => {
      items = [];
      render();
      setStatus(error?.message || 'Failed to load questions.', true);
    });
})();
</script>
