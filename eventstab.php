<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

const EVENTSTAB_STORE_PATH = __DIR__ . '/data/guests.json';
const EVENTSTAB_EVENTS_ROOT = __DIR__ . '/events';

function respondEventsJson(array $payload, int $statusCode = 200): void
{
  if (!headers_sent()) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function eventstabNormalizeGuestStore(array $store): array
{
  return [
    'events' => is_array($store['events'] ?? null) ? array_values($store['events']) : [],
    'logs' => is_array($store['logs'] ?? null) ? array_values($store['logs']) : [],
    'active_event_code' => trim((string)($store['active_event_code'] ?? ''))
  ];
}

function eventstabLoadGuestStore(): array
{
  if (!is_file(EVENTSTAB_STORE_PATH)) {
    return eventstabNormalizeGuestStore([]);
  }
  $content = file_get_contents(EVENTSTAB_STORE_PATH);
  if ($content === false) {
    return eventstabNormalizeGuestStore([]);
  }
  $decoded = json_decode($content, true);
  if (!is_array($decoded)) {
    return eventstabNormalizeGuestStore([]);
  }
  return eventstabNormalizeGuestStore($decoded);
}

function eventstabSaveGuestStore(array $store): bool
{
  $normalized = eventstabNormalizeGuestStore($store);
  $directory = dirname(EVENTSTAB_STORE_PATH);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    return false;
  }
  $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents(EVENTSTAB_STORE_PATH, $encoded, LOCK_EX) !== false;
}

function eventstabFindEventIndexByCode(array $events, string $code): int
{
  $normalizedCode = trim($code);
  if ($normalizedCode === '') {
    return -1;
  }
  foreach ($events as $index => $event) {
    if (!is_array($event)) {
      continue;
    }
    if ((string)($event['code'] ?? '') === $normalizedCode) {
      return $index;
    }
  }
  return -1;
}

function eventstabNormalizeEventsForResponse(array $events): array
{
  return array_values(array_map(static function ($event) {
    if (!is_array($event)) {
      return [
        'code' => '',
        'name' => '',
        'date' => '',
        'guest_count' => 0,
        'created_at' => '',
        'updated_at' => ''
      ];
    }
    $code = (string)($event['code'] ?? '');
    $guests = [];
    $guestCount = null;
    $guestPath = eventstabGetEventGuestsPath($code);
    if ($guestPath !== '' && is_file($guestPath)) {
      $guests = eventstabLoadEventGuests($guestPath);
      $guestCount = count($guests);
    }
    if ($guestCount === null) {
      $legacyGuests = is_array($event['guests'] ?? null) ? array_values($event['guests']) : [];
      $guestCount = (int)($event['guest_count'] ?? count($legacyGuests));
    }
    return [
      'code' => (string)($event['code'] ?? ''),
      'name' => (string)($event['name'] ?? ''),
      'date' => (string)($event['date'] ?? ''),
      'guest_count' => $guestCount,
      'created_at' => (string)($event['created_at'] ?? ''),
      'updated_at' => (string)($event['updated_at'] ?? '')
    ];
  }, $events));
}

function eventstabGetEventGuestsPath(string $code): string
{
  $trimmed = trim($code);
  if ($trimmed === '') {
    return '';
  }
  return rtrim(EVENTSTAB_EVENTS_ROOT, '/\\') . DIRECTORY_SEPARATOR . $trimmed . DIRECTORY_SEPARATOR . 'eventguests.json';
}

function eventstabLoadEventGuests(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? array_values($decoded) : [];
}

function eventstabGetEventsRootPath(): string
{
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $real = realpath(EVENTSTAB_EVENTS_ROOT);
  $cached = $real !== false ? $real : EVENTSTAB_EVENTS_ROOT;
  return $cached;
}

function eventstabNormalizePathForComparison(string $value): string
{
  $trimmed = trim($value);
  $normalized = str_replace('\\', '/', $trimmed);
  return rtrim($normalized, '/');
}

function eventstabDeleteDirectoryRecursive(string $directory): bool
{
  if (!is_dir($directory)) {
    return true;
  }
  $entries = scandir($directory);
  if ($entries === false) {
    return false;
  }
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
      continue;
    }
    $target = $directory . DIRECTORY_SEPARATOR . $entry;
    if (is_dir($target)) {
      if (!eventstabDeleteDirectoryRecursive($target)) {
        return false;
      }
      continue;
    }
    if (is_file($target) && !unlink($target)) {
      return false;
    }
  }
  return rmdir($directory);
}

function eventstabDeleteEventDirectory(string $code): bool
{
  if ($code === '') {
    return true;
  }
  $root = eventstabGetEventsRootPath();
  if ($root === '') {
    return false;
  }
  $target = $root . DIRECTORY_SEPARATOR . $code;
  $normalizedRoot = eventstabNormalizePathForComparison($root);
  $normalizedTarget = eventstabNormalizePathForComparison($target);
  if ($normalizedRoot === '' || $normalizedTarget === '' || strpos($normalizedTarget, $normalizedRoot) !== 0) {
    return false;
  }
  return eventstabDeleteDirectoryRecursive($target);
}

$action = trim((string)($_REQUEST['event_action'] ?? ''));
if ($action !== '') {
  if (empty($_SESSION['authenticated'])) {
    respondEventsJson(['status' => 'error', 'message' => 'Authentication required.'], 403);
  }
  $store = eventstabLoadGuestStore();
  $action = strtolower($action);
  switch ($action) {
    case 'list':
      respondEventsJson([
        'status' => 'ok',
        'events' => eventstabNormalizeEventsForResponse($store['events'])
      ]);
      break;
    case 'update':
      $code = trim((string)($_POST['code'] ?? ''));
      $name = trim((string)($_POST['name'] ?? ''));
      $date = trim((string)($_POST['date'] ?? ''));
      if ($code === '' || $name === '' || $date === '') {
        respondEventsJson(['status' => 'error', 'message' => 'Event code, name, and date are required.'], 422);
      }
      $index = eventstabFindEventIndexByCode($store['events'], $code);
      if ($index < 0) {
        respondEventsJson(['status' => 'error', 'message' => 'Event not found.'], 404);
      }
      $store['events'][$index]['name'] = $name;
      $store['events'][$index]['date'] = $date;
      $store['events'][$index]['updated_at'] = date('c');
      if (!eventstabSaveGuestStore($store)) {
        respondEventsJson(['status' => 'error', 'message' => 'Unable to persist event list.'], 500);
      }
      respondEventsJson([
        'status' => 'ok',
        'message' => 'Event saved.',
        'events' => eventstabNormalizeEventsForResponse($store['events'])
      ]);
      break;
    case 'delete':
      $code = trim((string)($_POST['code'] ?? ''));
      if ($code === '') {
        respondEventsJson(['status' => 'error', 'message' => 'Event code is required for deletion.'], 422);
      }
      if (eventstabFindEventIndexByCode($store['events'], $code) < 0) {
        respondEventsJson(['status' => 'error', 'message' => 'Event not found.'], 404);
      }
      $targetDir = eventstabGetEventsRootPath() . DIRECTORY_SEPARATOR . $code;
      $directoryExisted = is_dir($targetDir);
      $store['events'] = array_values(array_filter($store['events'], static fn($event) => (string)($event['code'] ?? '') !== $code));
      if ($directoryExisted && !eventstabDeleteEventDirectory($code)) {
        respondEventsJson(['status' => 'error', 'message' => 'Failed to remove event directory.'], 500);
      }
      if (!eventstabSaveGuestStore($store)) {
        respondEventsJson(['status' => 'error', 'message' => 'Unable to persist event list.'], 500);
      }
      respondEventsJson([
        'status' => 'ok',
        'message' => 'Event removed.',
        'events' => eventstabNormalizeEventsForResponse($store['events'])
      ]);
      break;
    default:
      respondEventsJson(['status' => 'error', 'message' => 'Unknown action.'], 400);
      break;
  }
}
?>

<section id="tab-events" class="tab">
  <div class="card">
    <div class="table-header" style="flex-direction:column; align-items:flex-start; gap:8px;">
      <div>
        <h3>Events</h3>
        <p class="muted small">Rename events, adjust their Shamsi date, or remove outdated directories from <code>events/</code>.</p>
      </div>
    </div>
    <p id="event-status" class="muted small" aria-live="polite" style="margin:0 16px 8px 16px;"></p>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:60px;">#</th>
            <th>Event name</th>
            <th style="width:210px;">Event date</th>
            <th style="width:120px;">Action</th>
          </tr>
        </thead>
        <tbody id="event-list-body">
          <tr>
            <td colspan="4" class="muted">Loading events...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
  (function () {
    const apiPath = 'eventstab.php';
    const listUrl = `${apiPath}?event_action=list`;
    const tableBody = document.getElementById('event-list-body');
    const statusEl = document.getElementById('event-status');

    if (!tableBody || !statusEl) {
      return;
    }

    let events = [];
    let jalaliPickerInitialized = false;

    function setStatus(message, isError = false) {
      statusEl.textContent = message || '';
      if (isError) {
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

    function sortEventsList() {
      events.sort((a, b) => {
        const dateA = (a.date ?? '').trim();
        const dateB = (b.date ?? '').trim();
        if (dateA === dateB) {
          const nameA = (a.name ?? '').trim();
          const nameB = (b.name ?? '').trim();
          return nameA.localeCompare(nameB);
        }
        if (!dateA) {
          return 1;
        }
        if (!dateB) {
          return -1;
        }
        return dateB.localeCompare(dateA);
      });
    }

    function initDatepicker() {
      if (jalaliPickerInitialized || typeof window.jalaliDatepicker === 'undefined') {
        return;
      }
      window.jalaliDatepicker.startWatch({
        selector: '[data-event-date]',
        viewMode: 'day',
        autoClose: true,
        format: 'YYYY/MM/DD',
        initViewGregorian: false
      });
      jalaliPickerInitialized = true;
    }

    function showDatepicker(input) {
      if (!input || typeof window.jalaliDatepicker === 'undefined') {
        return;
      }
      initDatepicker();
      window.jalaliDatepicker.show(input);
    }

    function renderTable() {
      if (!events.length) {
        tableBody.innerHTML = `<tr><td colspan="4" class="muted">No events have been imported yet.</td></tr>`;
        return;
      }
      tableBody.innerHTML = events
        .map((event, index) => {
        const code = (event.code ?? '').trim();
        const dateValue = escapeHtml(event.date || '');
        const nameValue = escapeHtml(event.name || '');
        const codeLabel = escapeHtml(code ? `Code: ${code}` : 'Code missing');
        return `
            <tr data-event-row="${escapeHtml(code)}">
              <td>${index + 1}</td>
              <td>
                <label class="field standard-width" style="margin:0;">
                  <input
                    type="text"
                    class="event-name-input"
                    data-event-code="${escapeHtml(code)}"
                    value="${nameValue}"
                    data-original="${nameValue}"
                    style="direction:rtl; text-align:right;"
                  />
                </label>
                <div class="muted small" style="margin-top:4px;">${codeLabel}</div>
              </td>
              <td>
                <label class="field standard-width" style="margin:0;">
                  <input
                    type="text"
                    class="event-date-input"
                    data-event-code="${escapeHtml(code)}"
                    value="${dateValue}"
                    data-original="${dateValue}"
                    data-event-date
                    data-jdp
                    data-jdp-only-date="true"
                    placeholder="YYYY/MM/DD"
                    readonly
                    style="direction:rtl; text-align:right;"
                  />
                </label>
              </td>
              <td>
                <button
                  type="button"
                  class="btn ghost small"
                  data-event-action="delete"
                  data-event-code="${escapeHtml(code)}"
                >
                  Delete
                </button>
              </td>
            </tr>
          `;
        })
        .join('');
      initDatepicker();
    }

    function getEventByCode(code) {
      return events.find(ev => (ev.code ?? '') === code) || null;
    }

    async function sendAction(action, data = {}) {
      const formData = new FormData();
      formData.append('event_action', action);
      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });
      const response = await fetch(apiPath, {
        method: 'POST',
        body: formData
      });
      const payload = await response.json();
      if (!response.ok || payload.status !== 'ok') {
        throw new Error(payload.message || 'Unable to update events.');
      }
      events = Array.isArray(payload.events) ? payload.events : events;
      sortEventsList();
      renderTable();
      setStatus(payload.message || 'Changes saved.');
      return payload;
    }

    function handleInlineUpdate(input, field) {
      const code = input?.getAttribute('data-event-code') ?? '';
      if (!code) {
        return;
      }
      const original = (input.getAttribute('data-original') ?? '').trim();
      const value = (input.value ?? '').trim();
      if (value === original) {
        input.value = original;
        return;
      }
      const existing = getEventByCode(code);
      const payload = {
        code,
        name: existing?.name ?? '',
        date: existing?.date ?? ''
      };
      if (field === 'name') {
        payload.name = value;
      } else if (field === 'date') {
        payload.date = value;
      }
      if (!payload.name) {
        setStatus('Event name cannot be empty.', true);
        input.value = original;
        return;
      }
      if (!payload.date) {
        setStatus('Event date cannot be empty.', true);
        input.value = original;
        return;
      }
      input.setAttribute('disabled', 'disabled');
      sendAction('update', payload)
        .then(() => {
          input.setAttribute('data-original', value);
        })
        .catch((error) => {
          setStatus(error?.message || 'Unable to update event.', true);
          input.value = original;
        })
        .finally(() => {
          input.removeAttribute('disabled');
        });
    }

    tableBody.addEventListener('focusin', (event) => {
      const target = event.target;
      const nameInput = target.closest('.event-name-input');
      if (nameInput) {
        nameInput.setAttribute('data-original', nameInput.value ?? '');
      }
      const dateInput = target.closest('.event-date-input');
      if (dateInput) {
        dateInput.setAttribute('data-original', dateInput.value ?? '');
        showDatepicker(dateInput);
      }
    });

    tableBody.addEventListener('focusout', (event) => {
      const target = event.target;
      const input = target.closest('.event-name-input, .event-date-input');
      if (!input) {
        return;
      }
      const next = event.relatedTarget;
      if (next && next.closest && next.closest('[data-event-action="delete"]')) {
        return;
      }
      const field = input.classList.contains('event-name-input') ? 'name' : 'date';
      handleInlineUpdate(input, field);
    });

    tableBody.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') {
        return;
      }
      const input = event.target.closest('.event-name-input, .event-date-input');
      if (!input) {
        return;
      }
      event.preventDefault();
      input.blur();
    });

    tableBody.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-event-action="delete"]');
      if (!button) {
        return;
      }
      const code = button.getAttribute('data-event-code') ?? '';
      if (!code) {
        return;
      }
      let confirmed = true;
      if (typeof showDialog === 'function') {
        confirmed = await showDialog('Delete this event and all associated files?', {
          confirm: true,
          title: 'Delete event',
          okText: 'Delete',
          cancelText: 'Cancel'
        });
      } else {
        confirmed = window.confirm('Delete this event and all associated files?');
      }
      if (!confirmed) {
        return;
      }
      button.setAttribute('disabled', 'disabled');
      try {
        await sendAction('delete', { code });
      } catch (error) {
        setStatus(error?.message || 'Unable to delete event.', true);
      } finally {
        button.removeAttribute('disabled');
      }
    });

    async function fetchEvents() {
      setStatus('Loading events...');
      try {
        const response = await fetch(listUrl, { cache: 'no-store' });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ok') {
          throw new Error(payload.message || 'Unable to load events.');
        }
        events = Array.isArray(payload.events) ? payload.events : [];
        sortEventsList();
        renderTable();
        setStatus(events.length ? `Loaded ${events.length} event(s).` : 'No events yet.');
      } catch (error) {
        setStatus(error?.message || 'Failed to load events.', true);
        tableBody.innerHTML = `<tr><td colspan="4" class="muted">Unable to load events.</td></tr>`;
      }
    }

    let eventsFetchTriggered = false;
    function triggerEventsLoad() {
      if (eventsFetchTriggered) {
        return;
      }
      eventsFetchTriggered = true;
      fetchEvents();
    }

    const eventsTabElement = document.getElementById('tab-events');
    if (eventsTabElement) {
      const observer = new MutationObserver(() => {
        if (eventsTabElement.classList.contains('active')) {
          triggerEventsLoad();
          observer.disconnect();
        }
      });
      observer.observe(eventsTabElement, { attributes: true, attributeFilter: ['class'] });
      if (eventsTabElement.classList.contains('active')) {
        triggerEventsLoad();
        observer.disconnect();
      }
    }
  })();
</script>
