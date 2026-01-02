<?php
if (!defined('EVENTS_ROOT')) {
  define('EVENTS_ROOT', __DIR__ . '/events');
}
const GUEST_STORE_PATH = __DIR__ . '/data/guests.json';

function respondWinnersJson(array $payload, int $statusCode = 200): void
{
  if (!headers_sent()) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function normalizeGuestStore(array $store): array
{
  $result = $store;
  $result['events'] = is_array($result['events'] ?? null) ? array_values($result['events']) : [];
  $result['logs'] = is_array($result['logs'] ?? null) ? array_values($result['logs']) : [];
  $result['active_event_slug'] = trim((string)($result['active_event_slug'] ?? ''));
  $result['active_event_code'] = trim((string)($result['active_event_code'] ?? ''));
  if ($result['active_event_code'] === '') {
    $result['active_event_code'] = $result['active_event_slug'];
  }
  return $result;
}

function loadGuestStore(): array
{
  if (!is_file(GUEST_STORE_PATH)) {
    return ['events' => [], 'logs' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  $content = file_get_contents(GUEST_STORE_PATH);
  if ($content === false) {
    return ['events' => [], 'logs' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return ['events' => [], 'logs' => [], 'active_event_slug' => '', 'active_event_code' => ''];
  }
  return normalizeGuestStore($decoded);
}

function saveGuestStore(array $store): bool
{
  $store = normalizeGuestStore($store);
  $directory = dirname(GUEST_STORE_PATH);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    return false;
  }
  $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents(GUEST_STORE_PATH, $encoded) !== false;
}

function ensureEventCodeExists(array $events, string $code): bool
{
  if ($code === '') {
    return true;
  }
  foreach ($events as $event) {
    if (!is_array($event)) {
      continue;
    }
    if ((string)($event['code'] ?? '') === $code) {
      return true;
    }
  }
  return false;
}

function getEventsRootPath(): string
{
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $real = realpath(EVENTS_ROOT);
  $cached = $real !== false ? $real : EVENTS_ROOT;
  return $cached;
}

function normalizePathForComparison(string $value): string
{
  $trimmed = trim($value);
  $forward = str_replace('\\', '/', $trimmed);
  return rtrim($forward, '/');
}

function makeRelativeEventPath(string $filePath): string
{
  $root = normalizePathForComparison(getEventsRootPath());
  $target = normalizePathForComparison($filePath);
  if ($root !== '' && strpos($target, $root . '/') === 0) {
    return substr($target, strlen($root) + 1);
  }
  return $filePath;
}

function convertRelativeToAbsoluteEventPath(string $relative): ?string
{
  $root = getEventsRootPath();
  if ($root === '') {
    return null;
  }
  $clean = trim(str_replace('\\', '/', $relative), '/');
  if ($clean === '') {
    return null;
  }
  $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
  $real = realpath($candidate);
  return $real !== false ? $real : null;
}

function readCsvRowsWithHeaders(string $path): array
{
  $headers = [];
  $rows = [];
  if (!is_file($path)) {
    return ['headers' => $headers, 'rows' => $rows];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return ['headers' => $headers, 'rows' => $rows];
  }
  $readHeaders = fgetcsv($handle);
  if ($readHeaders === false) {
    fclose($handle);
    return ['headers' => $headers, 'rows' => $rows];
  }
  $headers = $readHeaders;
  $lineIndex = 0;
  while (($line = fgetcsv($handle)) !== false) {
    $lineIndex++;
    if (count($line) !== count($headers)) {
      $line = array_pad($line, count($headers), '');
    }
    $combined = @array_combine($headers, $line);
    if ($combined === false) {
      continue;
    }
    $combined['__line'] = $lineIndex;
    $combined['__source'] = $path;
    $rows[] = $combined;
  }
  fclose($handle);
  return ['headers' => $headers, 'rows' => $rows];
}

function rewriteCsvRowsWithHeaders(string $path, array $headers, array $rows): bool
{
  $handle = fopen($path, 'w');
  if ($handle === false) {
    return false;
  }
  fputcsv($handle, $headers);
  foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $name) {
      $line[] = $row[$name] ?? '';
    }
    fputcsv($handle, $line);
  }
  fclose($handle);
  return true;
}

function loadWinnersList(string $eventsRoot): array
{
  $list = [];
  $rootPath = getEventsRootPath();
  if (!is_dir($rootPath)) {
    return $list;
  }
  $entries = scandir($rootPath);
  if ($entries === false) {
    return $list;
  }
  foreach ($entries as $dir) {
    if ($dir === '.' || $dir === '..') {
      continue;
    }
    $eventPath = $rootPath . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($eventPath)) {
      continue;
    }
    $files = glob($eventPath . DIRECTORY_SEPARATOR . 'winners of *.csv');
    if ($files === false) {
      continue;
    }
    foreach ($files as $filePath) {
      $data = readCsvRowsWithHeaders($filePath);
      foreach ($data['rows'] as $row) {
        $entry = $row;
        $entry['source'] = makeRelativeEventPath($filePath);
        $entry['line'] = (int)($row['__line'] ?? 0);
        unset($entry['__source'], $entry['__line']);
        $list[] = $entry;
      }
    }
  }
  usort($list, static fn($a, $b) => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));
  return array_values($list);
}

function deleteWinnerEntry(string $relativeSource, int $lineIndex): bool
{
  if ($lineIndex <= 0) {
    return false;
  }
  $absolute = convertRelativeToAbsoluteEventPath($relativeSource);
  if ($absolute === null) {
    return false;
  }
  $data = readCsvRowsWithHeaders($absolute);
  if (empty($data['headers'])) {
    return false;
  }
  $remaining = [];
  $deleted = false;
  foreach ($data['rows'] as $row) {
    if (!$deleted && ((int)($row['__line'] ?? 0) === $lineIndex)) {
      $deleted = true;
      continue;
    }
    $remaining[] = $row;
  }
  if (!$deleted) {
    return false;
  }
  return rewriteCsvRowsWithHeaders($absolute, $data['headers'], $remaining);
}

$action = trim((string)($_REQUEST['winner_action'] ?? ''));
if ($action !== '') {
  $action = strtolower($action);
  switch ($action) {
    case 'list':
      $winners = loadWinnersList(EVENTS_ROOT);
      $store = loadGuestStore();
      respondWinnersJson([
        'status' => 'ok',
        'winners' => $winners,
        'activeEventCode' => $store['active_event_code']
      ]);
      break;
    case 'delete':
      $line = (int)($_POST['line'] ?? 0);
      $source = trim((string)($_POST['source'] ?? ''));
      if ($line <= 0 || $source === '') {
        respondWinnersJson(['status' => 'error', 'message' => 'Line and source are required.'], 400);
      }
      if (!deleteWinnerEntry($source, $line)) {
        respondWinnersJson(['status' => 'error', 'message' => 'Deletion failed. Entry not found or inaccessible.'], 404);
      }
      $winners = loadWinnersList(EVENTS_ROOT);
      $store = loadGuestStore();
      respondWinnersJson([
        'status' => 'ok',
        'message' => 'Winner removed.',
        'winners' => $winners,
        'activeEventCode' => $store['active_event_code']
      ]);
      break;
    case 'set_active_event':
      $code = trim((string)($_POST['code'] ?? ''));
      $store = loadGuestStore();
      if ($code !== '' && !ensureEventCodeExists($store['events'], $code)) {
        respondWinnersJson(['status' => 'error', 'message' => 'Selected event does not exist.'], 404);
      }
      $store['active_event_code'] = $code;
      if (!saveGuestStore($store)) {
        respondWinnersJson(['status' => 'error', 'message' => 'Unable to save active event.'], 500);
      }
      $winners = loadWinnersList(EVENTS_ROOT);
      respondWinnersJson([
        'status' => 'ok',
        'message' => 'Active event saved.',
        'activeEventCode' => $store['active_event_code'],
        'winners' => $winners
      ]);
      break;
    default:
      respondWinnersJson(['status' => 'error', 'message' => 'Unknown action.'], 400);
      break;
  }
}
?>

<section id="tab-winners" class="tab">
  <div class="card">
    <div class="table-header">
      <div>
        <h3>Winners</h3>
        <p class="muted small">Confirmed winners are pulled from each event’s <code>winners of {event name}.csv</code> file inside <code>events/</code>.</p>
      </div>
      <div class="table-actions">
        <button id="winner-export-button" class="btn ghost" disabled>Export winners to Excel</button>
      </div>
    </div>
    <div
      class="active-event-section"
      style="margin-top:16px; padding:16px; border:1px solid var(--sidebar-active-border); border-radius:12px; display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px;"
    >
      <div class="section-header" style="margin:0;">
        <h3>Active Event</h3>
      </div>
      <form
        id="winner-active-event-form"
        class="form"
        style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-inline-start:auto;"
      >
        <label class="field standard-width" style="flex:1 1 260px;">
          <span>Select event</span>
          <select id="winner-active-event-select">
            <option value="">Select an event</option>
          </select>
        </label>
        <button type="submit" class="btn primary">Save</button>
      </form>
      <p id="winner-active-event-status" class="muted small" aria-live="polite" style="margin:0;"></p>
    </div>
    <p id="winner-status" class="muted small" aria-live="polite"></p>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:48px;">#</th>
            <th>Event</th>
            <th>Timestamp</th>
            <th>Winner</th>
            <th>Number</th>
            <th>Code</th>
            <th>Phone</th>
            <th>National ID</th>
            <th style="width:130px;">Action</th>
          </tr>
        </thead>
        <tbody id="winner-list-body">
          <tr>
            <td colspan="9" class="muted">Loading winners...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
  (function () {
    const apiPath = 'winnerstab.php';
    const listUrl = `${apiPath}?winner_action=list`;
    const tableBody = document.getElementById('winner-list-body');
    const statusEl = document.getElementById('winner-status');
    const exportBtn = document.getElementById('winner-export-button');
    const activeEventForm = document.getElementById('winner-active-event-form');
    const activeEventSelect = document.getElementById('winner-active-event-select');
    const activeEventStatus = document.getElementById('winner-active-event-status');
    const eventsEndpoint = './api/guests.php';

    if (!tableBody || !statusEl) {
      return;
    }

    let winners = [];
    let activeEventCode = '';
    let eventsList = [];

    function setStatus(message, isError = false) {
      if (!statusEl) {
        return;
      }
      statusEl.textContent = message || '';
      if (isError) {
        statusEl.style.color = 'var(--primary)';
      } else {
        statusEl.style.color = '';
      }
    }

    function setActiveEventStatus(message, isError = false) {
      if (!activeEventStatus) {
        return;
      }
      activeEventStatus.textContent = message || '';
      if (isError) {
        activeEventStatus.style.color = 'var(--primary)';
      } else {
        activeEventStatus.style.color = '';
      }
    }

    function renderActiveEventOptions() {
      if (!activeEventSelect) {
        return;
      }
      const rows = ['<option value="">Select an event</option>'];
      eventsList.forEach(event => {
        const code = event.code ?? '';
        const name = event.name ?? code || 'Unnamed event';
        const date = event.date ?? '';
        const labelParts = [name];
        if (code) {
          labelParts.push(`Code: ${code}`);
        }
        if (date) {
          labelParts.push(date);
        }
        const label = labelParts.join(' - ');
        rows.push(`<option value="${escapeHtml(code)}">${escapeHtml(label)}</option>`);
      });
      activeEventSelect.innerHTML = rows.join('');
      if (activeEventCode) {
        activeEventSelect.value = activeEventCode;
      }
    }

    function updateActiveEventUi() {
      if (activeEventSelect) {
        activeEventSelect.value = activeEventCode;
      }
      const match = eventsList.find(event => (event.code ?? '') === activeEventCode);
      if (activeEventCode && match) {
        const labelParts = [];
        if (match.name) {
          labelParts.push(match.name);
        }
        if (match.code) {
          labelParts.push(`Code: ${match.code}`);
        }
        if (match.date) {
          labelParts.push(match.date);
        }
        const label = labelParts.join(' • ');
        setActiveEventStatus(`Active event: ${label}`);
      } else if (activeEventCode) {
        setActiveEventStatus('Active event saved.');
      } else {
        setActiveEventStatus('No active event selected.');
      }
    }

    async function loadEventOptions() {
      if (!activeEventSelect) {
        return;
      }
      setActiveEventStatus('Loading events...');
      try {
        const response = await fetch(eventsEndpoint, { cache: 'no-store' });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
          throw new Error(payload.message || 'Unable to load events.');
        }
        eventsList = Array.isArray(payload.events) ? payload.events : [];
        eventsList.sort((a, b) => {
          const dateA = (a.date ?? '').trim();
          const dateB = (b.date ?? '').trim();
          if (dateA === dateB) {
            return 0;
          }
          if (dateA === '') {
            return 1;
          }
          if (dateB === '') {
            return -1;
          }
          return dateB.localeCompare(dateA);
        });
        renderActiveEventOptions();
        updateActiveEventUi();
      } catch (error) {
        setActiveEventStatus(error?.message || 'Failed to load events.', true);
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

    function setExportState() {
      if (!exportBtn) {
        return;
      }
      if (winners.length) {
        exportBtn.removeAttribute('disabled');
      } else {
        exportBtn.setAttribute('disabled', 'disabled');
      }
    }

    function renderTable() {
      if (!winners.length) {
        tableBody.innerHTML = `<tr><td colspan="9" class="muted">No winners confirmed yet.</td></tr>`;
        if (exportBtn) {
          exportBtn.setAttribute('disabled', 'disabled');
        }
        return;
      }
      tableBody.innerHTML = winners
        .map((winner, index) => {
        const eventCode = escapeHtml(winner.event_code || winner.event_slug || '');
        const eventLabel = escapeHtml(winner.event_name || eventCode || 'Unknown event');
          const fullname = escapeHtml(
            [winner.firstname, winner.lastname].filter(Boolean).join(' ').trim() || '---'
          );
          const number = escapeHtml(winner.number ?? '');
          const timestamp = escapeHtml(winner.timestamp ?? '');
          const code = escapeHtml(winner.code ?? '');
          const phone = escapeHtml(winner.phone_number ?? '');
          const nationalId = escapeHtml(winner.national_id ?? '');
          const gender = escapeHtml(winner.gender ?? '');
          return `
            <tr>
              <td>${index + 1}</td>
              <td>
                <strong>${eventLabel}</strong>
                ${eventCode ? `<div class="muted small">Code: ${eventCode}</div>` : ''}
              </td>
              <td>${timestamp}</td>
              <td>
                ${fullname}
                ${gender ? `<div class="muted small">Gender: ${gender}</div>` : ''}
              </td>
              <td>${number}</td>
              <td>${code}</td>
              <td>${phone}</td>
              <td>${nationalId}</td>
              <td>
                <button
                  type="button"
                  class="btn ghost small"
                  data-winner-action="delete"
                  data-source="${escapeHtml(winner.source ?? '')}"
                  data-line="${winner.line ?? 0}"
                >
                  Delete
                </button>
              </td>
            </tr>
          `;
        })
        .join('');
      setExportState();
    }

    async function fetchWinners() {
      setStatus('Loading winners...');
      try {
        const response = await fetch(listUrl, { cache: 'no-store' });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
          throw new Error(payload.message || 'Unable to load winners.');
        }
        winners = Array.isArray(payload.winners) ? payload.winners : [];
        if (payload.activeEventCode !== undefined) {
          activeEventCode = payload.activeEventCode || '';
          updateActiveEventUi();
        }
        renderTable();
        setStatus(winners.length ? `Loaded ${winners.length} winner(s).` : 'No winners yet.');
      } catch (error) {
        setStatus(error?.message || 'Failed to load winners.', true);
        tableBody.innerHTML = `<tr><td colspan="9" class="muted">Unable to load winners.</td></tr>`;
        if (exportBtn) {
          exportBtn.setAttribute('disabled', 'disabled');
        }
      }
    }

    async function sendAction(action, data = {}) {
      const formData = new FormData();
      formData.append('winner_action', action);
      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });
      const response = await fetch(apiPath, {
        method: 'POST',
        body: formData
      });
      const payload = await response.json();
      if (!response.ok || payload.status !== 'ok') {
        throw new Error(payload.message || 'Unable to update winners.');
      }
      winners = Array.isArray(payload.winners) ? payload.winners : winners;
      if (payload.activeEventCode !== undefined) {
        activeEventCode = payload.activeEventCode || '';
        updateActiveEventUi();
      }
      renderTable();
      setStatus(payload.message || 'Changes saved.');
      return payload;
    }

    tableBody.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-winner-action]');
      if (!button) {
        return;
      }
      const action = button.dataset.winnerAction;
      const source = button.dataset.source;
      const line = button.dataset.line;
      if (!action || !source || !line) {
        return;
      }
      if (action === 'delete') {
        if (!confirm('Delete this winner entry?')) {
          return;
        }
        button.setAttribute('disabled', 'disabled');
        try {
          await sendAction('delete', { source, line });
        } catch (error) {
          setStatus(error?.message || 'Unable to delete winner.', true);
        } finally {
          button.removeAttribute('disabled');
        }
      }
    });

    activeEventForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const code = (activeEventSelect?.value ?? '').trim();
      setActiveEventStatus('Saving active event...');
      try {
        const payload = await sendAction('set_active_event', { code });
        setActiveEventStatus(payload.message || 'Active event saved.');
      } catch (error) {
        setActiveEventStatus(error?.message || 'Failed to save active event.', true);
      }
    });

    if (exportBtn) {
      exportBtn.addEventListener('click', () => {
        if (!winners.length) {
          setStatus('No winners to export.', true);
          return;
        }
        if (typeof window.XLSX === 'undefined') {
          setStatus('Excel export library is unavailable.', true);
          return;
        }
        const rows = winners.map((winner) => ({
          Timestamp: winner.timestamp ?? '',
          'Event name': winner.event_name ?? '',
          'Event code': winner.event_code ?? winner.event_slug ?? '',
          Code: winner.code ?? '',
          'Full name': [winner.firstname, winner.lastname].filter(Boolean).join(' ').trim(),
          Number: winner.number ?? '',
          Gender: winner.gender ?? '',
          'National ID': winner.national_id ?? '',
          'Phone number': winner.phone_number ?? '',
          'Invite code': winner.invite_code ?? ''
        }));
        const worksheet = window.XLSX.utils.json_to_sheet(rows, {
          header: [
            'Timestamp',
            'Event name',
            'Event code',
            'Code',
            'Full name',
            'Number',
            'Gender',
            'National ID',
            'Phone number',
            'Invite code'
          ],
          skipHeader: false
        });
        const workbook = window.XLSX.utils.book_new();
        window.XLSX.utils.book_append_sheet(workbook, worksheet, 'Winners');
        const arrayBuffer = window.XLSX.write(workbook, { bookType: 'xlsx', type: 'array' });
        const blob = new Blob([arrayBuffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'winners.xlsx';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
        setStatus('Winners exported.');
      });
    }

    fetchWinners();
    loadEventOptions();
  })();
</script>
