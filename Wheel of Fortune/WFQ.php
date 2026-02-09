<?php
declare(strict_types=1);

$wfqStorePath = __DIR__ . '/WFQ list.json';

function wfqNormalizeItem(array $item): array
{
  $answers = is_array($item['answers'] ?? null) ? array_values($item['answers']) : [];
  while (count($answers) < 4) {
    $answers[] = '';
  }
  $answers = array_slice($answers, 0, 4);
  return [
    'id' => trim((string)($item['id'] ?? '')) ?: ('q_' . bin2hex(random_bytes(6))),
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['wfq_action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $action = trim((string)($_POST['wfq_action'] ?? ''));

  if ($action === 'list') {
    $items = wfqLoadStore($wfqStorePath);
    echo json_encode(['status' => 'ok', 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($action === 'save_all') {
    $raw = (string)($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      echo json_encode(['status' => 'error', 'message' => 'Invalid payload.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

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
      foreach ($item['answers'] as $ans) {
        if ($ans === '') {
          $num = $index + 1;
          echo json_encode(['status' => 'error', 'message' => "All 4 answers in row {$num} are required."], JSON_UNESCAPED_UNICODE);
          exit;
        }
      }
      $items[] = $item;
    }

    if (!wfqSaveStore($wfqStorePath, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save questions.'], JSON_UNESCAPED_UNICODE);
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
      <span>Question</span>
      <input id="wfq-question-input" name="question" type="text" autocomplete="off" required />
    </label>
    <div class="form grid wfq-answer-grid">
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
  if (!form || !input || !body || !statusEl || !saveAllBtn) return;

  let items = [];

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const makeId = () => `q_${Math.random().toString(36).slice(2, 10)}`;

  const normalizeAnswers = (list) => {
    const answers = Array.isArray(list) ? list.slice(0, 4) : [];
    while (answers.length < 4) answers.push('');
    return answers.map((v) => String(v ?? ''));
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
      const answers = normalizeAnswers(row.answers);
      if (answers.some((ans) => !String(ans).trim())) {
        return `All 4 answers in row ${i + 1} are required.`;
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
      const answers = normalizeAnswers(item.answers);
      return `<tr data-row-id="${esc(item.id)}">
        <td>${index + 1}</td>
        <td>
          <div class="wfq-row-grid">
            <input class="wfq-field" type="text" data-field="question" value="${esc(item.question)}" />
            <div class="wfq-answer-grid">
              <input class="wfq-field" type="text" data-field="answer0" value="${esc(answers[0])}" />
              <input class="wfq-field" type="text" data-field="answer1" value="${esc(answers[1])}" />
              <input class="wfq-field" type="text" data-field="answer2" value="${esc(answers[2])}" />
              <input class="wfq-field" type="text" data-field="answer3" value="${esc(answers[3])}" />
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
      question: String(item.question || ''),
      answers: normalizeAnswers(item.answers),
      createdAt: String(item.createdAt || '')
    })) : [];
    render();
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const fd = new FormData(form);
    const next = {
      id: makeId(),
      question: String(fd.get('question') ?? '').trim(),
      answers: [
        String(fd.get('answer1') ?? '').trim(),
        String(fd.get('answer2') ?? '').trim(),
        String(fd.get('answer3') ?? '').trim(),
        String(fd.get('answer4') ?? '').trim()
      ],
      createdAt: new Date().toISOString().slice(0, 19).replace('T', ' ')
    };
    if (!next.question) {
      setStatus('Question is required.', true);
      return;
    }
    if (next.answers.some((ans) => !ans)) {
      setStatus('All 4 answers are required.', true);
      return;
    }
    items.push(next);
    render();
    setStatus('Question added to list. Click Save to persist changes.');
    form.reset();
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

  syncFromServer()
    .then(() => setStatus(''))
    .catch((error) => {
      items = [];
      render();
      setStatus(error?.message || 'Failed to load questions.', true);
    });
})();
</script>
