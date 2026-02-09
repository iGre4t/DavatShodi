<?php
declare(strict_types=1);

$wfqStorePath = __DIR__ . '/WFQ list.json';

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
  return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
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

  if ($action === 'add') {
    $question = trim((string)($_POST['question'] ?? ''));
    $answers = [
      trim((string)($_POST['answer1'] ?? '')),
      trim((string)($_POST['answer2'] ?? '')),
      trim((string)($_POST['answer3'] ?? '')),
      trim((string)($_POST['answer4'] ?? ''))
    ];

    if ($question === '') {
      echo json_encode(['status' => 'error', 'message' => 'Question is required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (count(array_filter($answers, static fn($v) => $v !== '')) < 4) {
      echo json_encode(['status' => 'error', 'message' => 'All 4 answers are required.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $items = wfqLoadStore($wfqStorePath);
    $items[] = [
      'id' => 'q_' . bin2hex(random_bytes(6)),
      'question' => $question,
      // In RTL layout, the first answer appears on the right and is always the correct answer.
      'answers' => $answers,
      'createdAt' => date('Y-m-d H:i:s')
    ];

    if (!wfqSaveStore($wfqStorePath, $items)) {
      echo json_encode(['status' => 'error', 'message' => 'Failed to save question.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    echo json_encode(['status' => 'ok', 'message' => 'Question added.', 'items' => $items], JSON_UNESCAPED_UNICODE);
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
  .wfq-field[readonly] {
    background: #f8fafc;
    color: #334155;
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
        </tr>
      </thead>
      <tbody id="wfq-list-body">
        <tr><td colspan="2" class="muted">Loading questions...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const endpoint = 'Wheel%20of%20Fortune/WFQ.php';
  const form = document.getElementById('wfq-form');
  const input = document.getElementById('wfq-question-input');
  const body = document.getElementById('wfq-list-body');
  const statusEl = document.getElementById('wfq-status');
  if (!form || !input || !body || !statusEl) {
    return;
  }

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const setStatus = (message, isError = false) => {
    statusEl.textContent = message || '';
    statusEl.style.color = isError ? '#d1434a' : '';
  };

  const normalizeAnswers = (item) => {
    const list = Array.isArray(item?.answers) ? item.answers.slice(0, 4) : [];
    while (list.length < 4) {
      list.push('');
    }
    return list.map((value) => String(value ?? ''));
  };

  const render = (items) => {
    if (!Array.isArray(items) || !items.length) {
      body.innerHTML = '<tr><td colspan="2" class="muted">No questions added yet.</td></tr>';
      return;
    }

    body.innerHTML = items.map((item, index) => {
      const answers = normalizeAnswers(item);
      return `<tr>
        <td>${index + 1}</td>
        <td>
          <div class="wfq-row-grid">
            <input class="wfq-field" type="text" value="${esc(item.question || '')}" readonly />
            <div class="wfq-answer-grid">
              <input class="wfq-field" type="text" value="${esc(answers[0])}" readonly />
              <input class="wfq-field" type="text" value="${esc(answers[1])}" readonly />
              <input class="wfq-field" type="text" value="${esc(answers[2])}" readonly />
              <input class="wfq-field" type="text" value="${esc(answers[3])}" readonly />
            </div>
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

  const loadList = async () => {
    try {
      const data = await postAction('list');
      render(data.items);
      setStatus('');
    } catch (error) {
      render([]);
      setStatus(error?.message || 'Failed to load questions.', true);
    }
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const payload = {
      question: String(formData.get('question') ?? '').trim(),
      answer1: String(formData.get('answer1') ?? '').trim(),
      answer2: String(formData.get('answer2') ?? '').trim(),
      answer3: String(formData.get('answer3') ?? '').trim(),
      answer4: String(formData.get('answer4') ?? '').trim()
    };

    if (!payload.question) {
      setStatus('Question is required.', true);
      return;
    }
    if (!payload.answer1 || !payload.answer2 || !payload.answer3 || !payload.answer4) {
      setStatus('All 4 answers are required.', true);
      return;
    }

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
      const data = await postAction('add', payload);
      render(data.items);
      setStatus(data.message || 'Question added.');
      form.reset();
      input.focus();
    } catch (error) {
      setStatus(error?.message || 'Failed to add question.', true);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  loadList();
})();
</script>
