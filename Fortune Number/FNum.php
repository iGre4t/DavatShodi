<?php
declare(strict_types=1);

$dataPath = __DIR__ . '/FNum.json';
$state = [
  'start' => '',
  'end' => '',
  'winners' => []
];

function readFnumData(string $path): array
{
  if (!is_file($path)) {
    return [
      'start' => '',
      'end' => '',
      'winners' => []
    ];
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    return [
      'start' => '',
      'end' => '',
      'winners' => []
    ];
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'start' => '',
      'end' => '',
      'winners' => []
    ];
  }
  $start = isset($decoded['start']) ? (string)$decoded['start'] : '';
  $end = isset($decoded['end']) ? (string)$decoded['end'] : '';
  $winners = is_array($decoded['winners'] ?? null) ? array_values($decoded['winners']) : [];
  return [
    'start' => $start,
    'end' => $end,
    'winners' => $winners
  ];
}

function writeFnumData(string $path, array $data): bool
{
  $payload = [
    'start' => (string)($data['start'] ?? ''),
    'end' => (string)($data['end'] ?? ''),
    'winners' => array_values(is_array($data['winners'] ?? null) ? $data['winners'] : [])
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function toPersianDigits(string $value): string
{
  $map = [
    '0' => '۰',
    '1' => '۱',
    '2' => '۲',
    '3' => '۳',
    '4' => '۴',
    '5' => '۵',
    '6' => '۶',
    '7' => '۷',
    '8' => '۸',
    '9' => '۹'
  ];
  return strtr($value, $map);
}

function isAjaxRequest(): bool
{
  return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function sendJson(array $payload): void
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

$state = readFnumData($dataPath);
$saveMessage = '';
$saveOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'clear_winners') {
    $state['winners'] = [];
    $saveOk = writeFnumData($dataPath, $state);
    $saveMessage = $saveOk ? 'Saved.' : 'Save failed.';
    if (isAjaxRequest()) {
      sendJson([
        'ok' => (bool)$saveOk,
        'message' => $saveMessage,
        'winners' => $state['winners']
      ]);
    }
  } elseif ($action === 'delete_winner') {
    $index = isset($_POST['winner_index']) ? (int)$_POST['winner_index'] : -1;
    if ($index >= 0 && $index < count($state['winners'])) {
      array_splice($state['winners'], $index, 1);
    }
    $saveOk = writeFnumData($dataPath, $state);
    $saveMessage = $saveOk ? 'Saved.' : 'Save failed.';
    if (isAjaxRequest()) {
      sendJson([
        'ok' => (bool)$saveOk,
        'message' => $saveMessage,
        'winners' => $state['winners']
      ]);
    }
  } else {
    $state['start'] = isset($_POST['start_number']) ? trim((string)$_POST['start_number']) : '';
    $state['end'] = isset($_POST['end_number']) ? trim((string)$_POST['end_number']) : '';
    $saveOk = writeFnumData($dataPath, $state);
    $saveMessage = $saveOk ? 'Saved.' : 'Save failed.';
    if (isAjaxRequest()) {
      sendJson([
        'ok' => (bool)$saveOk,
        'message' => $saveMessage
      ]);
    }
  }

  if (!isAjaxRequest()) {
    $redirectTo = $_SERVER['REQUEST_URI'] ?? 'panel.php';
    header('Location: ' . $redirectTo);
    exit;
  }
}

$startNumber = $state['start'];
$endNumber = $state['end'];
$existingWinners = $state['winners'];
?>

<div class="card">
  <div class="section-header">
    <h3>Control Panel</h3>
  </div>
  <form method="post" class="form" id="fnum-control-form">
    <div class="grid full">
      <label class="field">
        <span>Start number</span>
        <input type="number" name="start_number" value="<?= htmlspecialchars($startNumber, ENT_QUOTES, 'UTF-8') ?>" />
      </label>
      <label class="field">
        <span>End number</span>
        <input type="number" name="end_number" value="<?= htmlspecialchars($endNumber, ENT_QUOTES, 'UTF-8') ?>" />
      </label>
    </div>
    <div class="section-footer">
      <button type="submit" class="btn primary">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-header">
    <h3>List of Winners</h3>
  </div>
  <div class="section-footer" style="justify-content: flex-start;">
    <button type="button" class="btn danger" id="fnum-clear-winners">Clear All</button>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Winner Number</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="fnum-winners-body">
        <?php if (!empty($existingWinners)): ?>
          <?php foreach ($existingWinners as $idx => $winner): ?>
            <tr data-winner-index="<?= (int)$idx ?>">
              <td><?= htmlspecialchars(toPersianDigits((string)$winner), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <button type="button" class="btn danger fnum-delete-winner" data-winner-index="<?= (int)$idx ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="2">No winners yet.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  (() => {
    const form = document.getElementById('fnum-control-form');
    if (!form) return;

    const winnersBody = document.getElementById('fnum-winners-body');
    const clearBtn = document.getElementById('fnum-clear-winners');
    const persianDigits = ['\u06F0', '\u06F1', '\u06F2', '\u06F3', '\u06F4', '\u06F5', '\u06F6', '\u06F7', '\u06F8', '\u06F9'];

    const toPersianDigits = (value) => (value === null || value === undefined)
      ? ''
      : value.toString().replace(/\d/g, (digit) => persianDigits[digit] || digit);

    const renderWinners = (items) => {
      if (!winnersBody) return;
      const rows = Array.isArray(items) ? items : [];
      winnersBody.innerHTML = '';
      if (!rows.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 2;
        td.textContent = 'No winners yet.';
        tr.appendChild(td);
        winnersBody.appendChild(tr);
        return;
      }
      rows.forEach((winner, index) => {
        const tr = document.createElement('tr');
        tr.dataset.winnerIndex = String(index);
        const tdNumber = document.createElement('td');
        tdNumber.textContent = toPersianDigits(winner);
        const tdAction = document.createElement('td');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn danger fnum-delete-winner';
        btn.dataset.winnerIndex = String(index);
        btn.textContent = 'Delete';
        tdAction.appendChild(btn);
        tr.appendChild(tdNumber);
        tr.appendChild(tdAction);
        winnersBody.appendChild(tr);
      });
    };

    const showMessage = (message, ok = true) => {
      if (ok && typeof window.showActionSnackbar === 'function') {
        window.showActionSnackbar({ message, instructionLabel: '', shortcut: null });
        return;
      }
      if (!ok && typeof window.showErrorSnackbar === 'function') {
        window.showErrorSnackbar({ message });
        return;
      }
      alert(message);
    };

    const postAction = async (payload) => {
      const formData = new FormData();
      Object.keys(payload).forEach((key) => formData.append(key, payload[key]));
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });
      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }
      if (!response.ok || !data || !data.ok) {
        const message = data && data.message ? data.message : 'Save failed.';
        throw new Error(message);
      }
      return data;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      try {
        const response = await fetch(form.action || window.location.href, {
          method: 'POST',
          body: formData,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          }
        });
        const payload = await response.json();
        const ok = response.ok && payload && payload.ok;
        const message = payload && payload.message ? payload.message : (ok ? 'Saved.' : 'Save failed.');
        showMessage(message, ok);
      } catch (error) {
        showMessage(error && error.message ? error.message : 'Save failed.', false);
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', async () => {
        try {
          const data = await postAction({ action: 'clear_winners' });
          renderWinners(data.winners || []);
        } catch (error) {
          showMessage(error && error.message ? error.message : 'Save failed.', false);
        }
      });
    }

    if (winnersBody) {
      winnersBody.addEventListener('click', async (event) => {
        const target = event.target;
        if (!target || !target.classList || !target.classList.contains('fnum-delete-winner')) {
          return;
        }
        const index = target.dataset.winnerIndex;
        if (index === undefined) return;
        try {
          const data = await postAction({ action: 'delete_winner', winner_index: index });
          renderWinners(data.winners || []);
        } catch (error) {
          showMessage(error && error.message ? error.message : 'Save failed.', false);
        }
      });
    }
  })();
</script>
