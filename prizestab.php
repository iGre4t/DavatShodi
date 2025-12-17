<?php
$csvPath = __DIR__ . '/prizelist.csv';

function respondJson(array $payload, int $statusCode = 200): void {
  if (!headers_sent()) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function readPrizes(string $path): array {
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $result = [];
  fgetcsv($handle); // skip header
  while (($line = fgetcsv($handle)) !== false) {
    if (!isset($line[0])) {
      continue;
    }
    $result[] = [
      'id' => (int)$line[0],
      'name' => isset($line[1]) ? (string)$line[1] : ''
    ];
  }
  fclose($handle);
  usort($result, fn($a, $b) => $a['id'] <=> $b['id']);
  return $result;
}

function writePrizes(string $path, array $prizes): bool {
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
  fputcsv($handle, ['id', 'name']);
  foreach ($prizes as $entry) {
    fputcsv($handle, [(int)$entry['id'], (string)($entry['name'] ?? '')]);
  }
  fflush($handle);
  flock($handle, LOCK_UN);
  fclose($handle);
  return true;
}

$action = trim((string)($_REQUEST['prize_action'] ?? ''));
if ($action !== '') {
  $action = strtolower($action);
  $prizes = readPrizes($csvPath);
  switch ($action) {
    case 'list':
      respondJson(['status' => 'ok', 'prizes' => $prizes]);
      break;
    case 'add':
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') {
        respondJson(['status' => 'error', 'message' => 'Prize name is required.'], 422);
      }
      $ids = array_column($prizes, 'id');
      $nextId = $ids ? (max($ids) + 1) : 1;
      $prizes[] = ['id' => $nextId, 'name' => $name];
      if (!writePrizes($csvPath, $prizes)) {
        respondJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondJson([
        'status' => 'ok',
        'message' => 'Prize added.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    case 'update':
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      if ($id <= 0 || $name === '') {
        respondJson(['status' => 'error', 'message' => 'Prize id and a valid name are required.'], 422);
      }
      $found = false;
      foreach ($prizes as &$entry) {
        if ($entry['id'] === $id) {
          $entry['name'] = $name;
          $found = true;
          break;
        }
      }
      unset($entry);
      if (!$found) {
        respondJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
      }
      if (!writePrizes($csvPath, $prizes)) {
        respondJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondJson([
        'status' => 'ok',
        'message' => 'Prize updated.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    case 'delete':
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        respondJson(['status' => 'error', 'message' => 'Prize id is required for deletion.'], 422);
      }
      $filtered = array_filter($prizes, fn($entry) => $entry['id'] !== $id);
      if (count($filtered) === count($prizes)) {
        respondJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
      }
      if (!writePrizes($csvPath, array_values($filtered))) {
        respondJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondJson([
        'status' => 'ok',
        'message' => 'Prize removed.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    default:
      respondJson(['status' => 'error', 'message' => 'Unknown action.'], 400);
      break;
  }
}
?>

<section id="tab-prizes" class="tab">
  <div class="card">
    <div class="table-header">
      <div>
        <h3>Prizes</h3>
        <p class="muted small">Add and manage prize names that will be saved in <code>prizelist.csv</code>.</p>
      </div>
      <form id="prize-add-form" class="form" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
        <label class="field standard-width" style="flex:1 1 240px;">
          <span>Prize name</span>
          <input id="prize-name" name="name" type="text" placeholder="Enter prize name" autocomplete="off" required />
        </label>
        <button type="submit" class="btn primary" id="prize-add-button">Add</button>
      </form>
    </div>
    <p id="prize-status" class="muted small" aria-live="polite" style="margin:0 16px 8px 16px;"></p>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:80px;">#</th>
            <th>Prize name</th>
            <th style="width:190px;">Action</th>
          </tr>
        </thead>
        <tbody id="prize-list-body">
          <tr>
            <td colspan="3" class="muted">Loading prizes...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
  (function () {
    const apiPath = 'prizestab.php';
    const listUrl = `${apiPath}?prize_action=list`;
    const addForm = document.getElementById('prize-add-form');
    const addInput = document.getElementById('prize-name');
    const addButton = document.getElementById('prize-add-button');
    const listBody = document.getElementById('prize-list-body');
    const statusEl = document.getElementById('prize-status');

    if (!listBody || !addForm || !addInput) {
      return;
    }

    let prizes = [];

    function setStatus(message, isError = false) {
      statusEl.textContent = message || '';
      if (isError) {
        statusEl.classList.add('muted');
        statusEl.style.color = 'var(--primary)';
      } else {
        statusEl.style.color = '';
      }
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function renderTable() {
      if (!prizes.length) {
        listBody.innerHTML = `<tr><td colspan="3" class="muted">No prizes defined yet.</td></tr>`;
        return;
      }
      listBody.innerHTML = prizes
        .map(prize => `
          <tr data-prize-id="${prize.id}">
            <td>${prize.id}</td>
            <td>${escapeHtml(prize.name)}</td>
            <td>
              <button type="button" class="btn ghost" data-prize-action="edit" data-prize-id="${prize.id}">Edit</button>
              <button type="button" class="btn ghost" data-prize-action="delete" data-prize-id="${prize.id}">Delete</button>
            </td>
          </tr>
        `)
        .join('');
    }

    async function fetchPrizes() {
      setStatus('Loading prizes...');
      try {
        const response = await fetch(listUrl, { cache: 'no-store' });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
          throw new Error(payload.message || 'Unable to load prizes.');
        }
        prizes = Array.isArray(payload.prizes) ? payload.prizes : [];
        renderTable();
        setStatus(prizes.length ? `Loaded ${prizes.length} prize(s).` : 'No prizes yet. Add one above.');
      } catch (error) {
        setStatus(error.message, true);
        listBody.innerHTML = `<tr><td colspan="3" class="muted">Unable to load prizes.</td></tr>`;
      }
    }

    async function sendAction(action, data = {}) {
      const formData = new FormData();
      formData.append('prize_action', action);
      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });
      const response = await fetch(apiPath, {
        method: 'POST',
        body: formData
      });
      const payload = await response.json();
      if (!response.ok || payload.status !== 'ok') {
        const message = payload.message || 'Unable to save changes.';
        throw new Error(message);
      }
      prizes = Array.isArray(payload.prizes) ? payload.prizes : prizes;
      renderTable();
      setStatus(payload.message || 'Changes saved.');
    }

    addForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const name = addInput.value.trim();
      if (!name) {
        setStatus('Prize name cannot be empty.', true);
        return;
      }
      addButton.setAttribute('disabled', 'disabled');
      try {
        await sendAction('add', { name });
        addForm.reset();
        addInput.focus();
      } catch (error) {
        setStatus(error.message, true);
      } finally {
        addButton.removeAttribute('disabled');
      }
    });

    listBody.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-prize-action]');
      if (!button) {
        return;
      }
      const action = button.getAttribute('data-prize-action');
      const id = button.getAttribute('data-prize-id');
      if (!id) {
        return;
      }
      const prize = prizes.find(item => String(item.id) === id);
      if (!prize) {
        setStatus('Selected prize was not found.', true);
        return;
      }
      if (action === 'edit') {
        const updatedName = prompt('Edit prize name', prize.name);
        if (updatedName === null) {
          return;
        }
        const trimmed = updatedName.trim();
        if (!trimmed) {
          setStatus('Prize name cannot be empty.', true);
          return;
        }
        try {
          await sendAction('update', { id, name: trimmed });
        } catch (error) {
          setStatus(error.message, true);
        }
      } else if (action === 'delete') {
        if (!confirm('Delete this prize?')) {
          return;
        }
        try {
          await sendAction('delete', { id });
        } catch (error) {
          setStatus(error.message, true);
        }
      }
    });

    fetchPrizes();
  })();
</script>
