<?php
$csvPath = __DIR__ . '/prizelist.csv';

function respondPrizesJson(array $payload, int $statusCode = 200): void {
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
      respondPrizesJson(['status' => 'ok', 'prizes' => $prizes]);
      break;
    case 'add':
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') {
        respondPrizesJson(['status' => 'error', 'message' => 'Prize name is required.'], 422);
      }
      $ids = array_column($prizes, 'id');
      $nextId = $ids ? (max($ids) + 1) : 1;
      $prizes[] = ['id' => $nextId, 'name' => $name];
      if (!writePrizes($csvPath, $prizes)) {
        respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondPrizesJson([
        'status' => 'ok',
        'message' => 'Prize added.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    case 'update':
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      if ($id <= 0 || $name === '') {
        respondPrizesJson(['status' => 'error', 'message' => 'Prize id and a valid name are required.'], 422);
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
        respondPrizesJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
      }
      if (!writePrizes($csvPath, $prizes)) {
        respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondPrizesJson([
        'status' => 'ok',
        'message' => 'Prize updated.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    case 'delete':
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        respondPrizesJson(['status' => 'error', 'message' => 'Prize id is required for deletion.'], 422);
      }
      $filtered = array_filter($prizes, fn($entry) => $entry['id'] !== $id);
      if (count($filtered) === count($prizes)) {
        respondPrizesJson(['status' => 'error', 'message' => 'Prize not found.'], 404);
      }
      if (!writePrizes($csvPath, array_values($filtered))) {
        respondPrizesJson(['status' => 'error', 'message' => 'Unable to persist the prize list.'], 500);
      }
      respondPrizesJson([
        'status' => 'ok',
        'message' => 'Prize removed.',
        'prizes' => readPrizes($csvPath)
      ]);
      break;
    default:
      respondPrizesJson(['status' => 'error', 'message' => 'Unknown action.'], 400);
      break;
  }
}
?>

<section id="tab-prizes" class="tab">
  <div class="card">
    <div
      class="table-header"
      style="display:flex; flex-direction:column; align-items:flex-end; gap:12px; flex-wrap:nowrap; direction:rtl;"
    >
      <div style="text-align:right; align-self:flex-end;">
        <h3>Prizes</h3>
      </div>
      <form
        id="prize-add-form"
        class="form"
        style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; min-width:320px; justify-content:flex-end;"
      >
        <label class="field standard-width" style="flex:1 1 240px; direction: rtl; text-align:right;">
          <span>Prize name</span>
          <input
            id="prize-name"
            name="name"
            type="text"
            placeholder="Enter prize name"
            autocomplete="off"
            required
            style="direction: rtl; text-align: right;"
          />
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
            <td style="width:100%;">
              <label class="field standard-width" style="margin:0;">
                <input
                  type="text"
                  class="prize-inline-input"
                  data-prize-id="${prize.id}"
                  value="${escapeHtml(prize.name)}"
                  data-original="${escapeHtml(prize.name)}"
                  style="direction:rtl; text-align:right;"
                />
              </label>
            </td>
            <td>
              <button type="button" class="btn ghost" data-prize-action="delete" data-prize-id="${prize.id}">Delete</button>
            </td>
          </tr>
        `)
        .join('');
    }

    function handleInlineUpdate(input) {
      const id = input?.getAttribute('data-prize-id') ?? '';
      if (!id) {
        return;
      }
      const original = (input.getAttribute('data-original') ?? '').trim();
      const value = (input.value ?? '').trim();
      if (value === original) {
        input.value = original;
        return;
      }
      input.setAttribute('disabled', 'disabled');
      sendAction('update', { id, name: value })
        .then(() => {
          input.setAttribute('data-original', value);
        })
        .catch((error) => {
          setStatus(error?.message || 'Unable to update prize.', true);
          input.value = original;
        })
        .finally(() => {
          input.removeAttribute('disabled');
        });
    }

    listBody.addEventListener('focusin', (event) => {
      const input = event.target.closest('.prize-inline-input');
      if (input) {
        input.setAttribute('data-original', input.value ?? '');
      }
    });

    listBody.addEventListener('focusout', (event) => {
      const input = event.target.closest('.prize-inline-input');
      if (!input) {
        return;
      }
      const next = event.relatedTarget;
      if (next && next.closest && next.closest('[data-prize-action="delete"]')) {
        return;
      }
      handleInlineUpdate(input);
    });

    listBody.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') {
        return;
      }
      const input = event.target.closest('.prize-inline-input');
      if (!input) {
        return;
      }
      event.preventDefault();
      input.blur();
    });

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
      const button = event.target.closest('[data-prize-action="delete"]');
      if (!button) {
        return;
      }
      const id = button.getAttribute('data-prize-id');
      if (!id) {
        return;
      }
      button.setAttribute('disabled', 'disabled');
      const confirmDeletion =
        typeof showDialog === 'function'
          ? await showDialog('Delete this prize?', {
              confirm: true,
              title: 'Delete prize',
              okText: 'Delete',
              cancelText: 'Cancel'
            })
          : confirm('Delete this prize?');
      if (!confirmDeletion) {
        button.removeAttribute('disabled');
        return;
      }
      try {
        await sendAction('delete', { id });
      } catch (error) {
        setStatus(error.message, true);
      } finally {
        button.removeAttribute('disabled');
      }
    });

    fetchPrizes();
  })();
</script>
