<?php
$hraBaseDir = __DIR__;
$eventsFile = $hraBaseDir . '/events.json';
$eventsData = [
    'events' => []
];

if (is_file($eventsFile)) {
    $decoded = json_decode((string)file_get_contents($eventsFile), true);
    if (is_array($decoded)) {
        $eventsData = $decoded;
        if (!isset($eventsData['events']) || !is_array($eventsData['events'])) {
            $eventsData['events'] = [];
        }
    }
}

function hraBuildPaneId(string $value, int $fallbackIndex): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 'event-' . $fallbackIndex;
    }
    $normalized = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $trimmed);
    $normalized = trim((string)$normalized, '-');
    if ($normalized === '') {
        return 'event-' . $fallbackIndex;
    }
    return 'event-' . $normalized;
}

$events = is_array($eventsData['events'] ?? null) ? $eventsData['events'] : [];

$hraAction = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hraAction === 'hra_save_score_translation') {
    header('Content-Type: application/json; charset=utf-8');

    $eventCode = trim((string)($_POST['event_code'] ?? ''));
    $translationsJson = (string)($_POST['translations'] ?? '');
    $translations = json_decode($translationsJson, true);
    $eventFinalJson = (string)($_POST['event_final'] ?? '');
    $eventFinal = $eventFinalJson !== '' ? json_decode($eventFinalJson, true) : null;

    if ($eventCode === '' || !is_array($translations)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Event code and translations are required.'
        ]);
        exit;
    }

    $eventDir = $hraBaseDir . '/events/' . $eventCode;
    if (!is_dir($eventDir)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Event directory not found.'
        ]);
        exit;
    }

    $payload = [
        'event_code' => $eventCode,
        'max_score' => count($translations),
        'translations' => array_values($translations)
    ];

    if (file_put_contents(
        $eventDir . '/ScoreTranslation.json',
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save score translations.'
        ]);
        exit;
    }

    if ($eventFinal !== null) {
        if (!is_array($eventFinal)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Event summary data is invalid.'
            ]);
            exit;
        }
        $eventFinalPayload = [
            'event_code' => $eventCode,
            'departments' => $eventFinal['departments'] ?? new stdClass()
        ];
        if (file_put_contents(
            $eventDir . '/EventFinal.json',
            json_encode($eventFinalPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) === false) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to save event summary.'
            ]);
            exit;
        }
    }

    echo json_encode([
        'success' => true
    ]);
    exit;
}
?>

<section id="tab-hraevents" class="tab">
  <div class="sub-layout" data-sub-layout>
    <aside class="sub-sidebar">
      <div class="sub-header">HRA Events</div>
      <div class="sub-nav">
        <?php if (empty($events)): ?>
          <button type="button" class="sub-item active" data-pane="empty">No events</button>
        <?php else: ?>
          <?php foreach ($events as $index => $event): ?>
            <?php
              $eventCode = is_array($event) ? trim((string)($event['code'] ?? '')) : '';
              $eventName = is_array($event) ? trim((string)($event['name'] ?? '')) : '';
              $paneId = hraBuildPaneId($eventCode !== '' ? $eventCode : $eventName, $index + 1);
              $label = $eventName !== '' ? $eventName : ($eventCode !== '' ? "Event {$eventCode}" : ('Event ' . ($index + 1)));
              $isActive = $index === 0;
            ?>
            <button type="button" class="sub-item<?= $isActive ? ' active' : '' ?>" data-pane="<?= htmlspecialchars($paneId, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>
    <div class="sub-content">
      <?php if (empty($events)): ?>
        <div class="sub-pane active" data-pane="empty">
          <div class="card">
            <h3>HRA Events</h3>
            <p class="muted">No events yet. Create one in HR Analyze Panel.</p>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($events as $index => $event): ?>
          <?php
            $eventCode = is_array($event) ? trim((string)($event['code'] ?? '')) : '';
            $eventName = is_array($event) ? trim((string)($event['name'] ?? '')) : '';
            $paneId = hraBuildPaneId($eventCode !== '' ? $eventCode : $eventName, $index + 1);
            $label = $eventName !== '' ? $eventName : ($eventCode !== '' ? "Event {$eventCode}" : ('Event ' . ($index + 1)));
            $isActive = $index === 0;
            $eventData = [];
            if ($eventCode !== '') {
                $eventDataFile = $hraBaseDir . '/events/' . $eventCode . '/eventdata.json';
                if (is_file($eventDataFile)) {
                    $decoded = json_decode((string)file_get_contents($eventDataFile), true);
                    if (is_array($decoded)) {
                        $eventData = $decoded;
                    }
                }
            }
            $totalScoreColumn = trim((string)($eventData['event_total_score_column'] ?? ''));
            $departmentColumn = trim((string)($eventData['event_department_column'] ?? ''));
            $eventFile = trim((string)($eventData['event_file'] ?? ''));
            $eventFilePath = '';
            if ($eventCode !== '' && $eventFile !== '') {
                $fileParts = array_map('rawurlencode', array_filter(explode('/', $eventFile)));
                $eventFilePath = 'HRA/events/' . rawurlencode($eventCode) . '/' . implode('/', $fileParts);
            }
          ?>
          <div class="sub-pane<?= $isActive ? ' active' : '' ?>" data-pane="<?= htmlspecialchars($paneId, ENT_QUOTES, 'UTF-8') ?>">
            <div class="card">
              <h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="muted">This event tab is ready. Content coming soon.</p>
            </div>
            <div class="card" style="margin-top:16px;">
              <div class="table-header" style="flex-direction:column; align-items:flex-start; gap:8px;">
                <h3>Scores Translation</h3>
                <p class="muted">Load the uploaded file to generate translation fields from available data.</p>
              </div>
              <div class="form grid one-column" data-score-translation-form data-event-code="<?= htmlspecialchars($eventCode, ENT_QUOTES, 'UTF-8') ?>" data-total-score-column="<?= htmlspecialchars($totalScoreColumn, ENT_QUOTES, 'UTF-8') ?>" data-department-column="<?= htmlspecialchars($departmentColumn, ENT_QUOTES, 'UTF-8') ?>" data-event-file="<?= htmlspecialchars($eventFilePath, ENT_QUOTES, 'UTF-8') ?>" style="margin-top:12px;">
                <div>
                  <button type="button" class="btn primary" data-score-load>Load Uploaded File</button>
                </div>
                <div class="grid one-column" style="gap:12px;" data-score-fields></div>
                <div>
                  <button type="button" class="btn" data-score-submit>Submit</button>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const forms = document.querySelectorAll("[data-score-translation-form]");

    function notify(message, isError = false) {
      if (isError && typeof window.showErrorSnackbar === "function") {
        window.showErrorSnackbar({ message });
        return;
      }
      if (!isError && typeof window.showDefaultToast === "function") {
        window.showDefaultToast(message);
        return;
      }
      alert(message);
    }

    async function readRowsFromBuffer(buffer) {
      if (typeof XLSX === "undefined") {
        throw new Error("Excel parser not loaded yet. Please try again in a moment.");
      }
      const workbook = XLSX.read(buffer, { type: "array" });
      const sheetName = workbook.SheetNames[0];
      if (!sheetName) {
        return [];
      }
      const sheet = workbook.Sheets[sheetName];
      return XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "" });
    }

    function collectValues(rows, ignoredIndexes) {
      const values = [];
      const seen = new Set();
      rows.slice(1).forEach((row) => {
        row.forEach((cell, index) => {
          if (ignoredIndexes.has(index)) {
            return;
          }
          const text = String(cell).trim();
          if (!text || seen.has(text)) {
            return;
          }
          seen.add(text);
          values.push(text);
        });
      });
      return values;
    }

    function buildEventFinal(rows, headers, departmentColumn, ignoredIndexes) {
      const departmentIndex = headers.indexOf(departmentColumn);
      if (departmentIndex < 0) {
        throw new Error("Department column not found in the uploaded file.");
      }
      const eventItemIndexes = headers
        .map((title, index) => ({ title, index }))
        .filter(({ index }) => index !== departmentIndex && !ignoredIndexes.has(index));
      const departments = {};

      rows.slice(1).forEach((row) => {
        const departmentValue = String(row[departmentIndex] ?? "").trim();
        if (!departmentValue) {
          return;
        }
        if (!departments[departmentValue]) {
          departments[departmentValue] = {
            votes: 0,
            items: {}
          };
        }
        const departmentEntry = departments[departmentValue];
        departmentEntry.votes += 1;
        eventItemIndexes.forEach(({ title, index }) => {
          const itemValue = String(row[index] ?? "").trim();
          if (!itemValue) {
            return;
          }
          if (!departmentEntry.items[title]) {
            departmentEntry.items[title] = {};
          }
          departmentEntry.items[title][itemValue] =
            (departmentEntry.items[title][itemValue] || 0) + 1;
        });
      });

      return {
        departments
      };
    }

    forms.forEach((form) => {
      const loadButton = form.querySelector("[data-score-load]");
      const fieldsContainer = form.querySelector("[data-score-fields]");
      const submitButton = form.querySelector("[data-score-submit]");
      const eventCode = form.getAttribute("data-event-code") || "";
      const totalScoreColumn = form.getAttribute("data-total-score-column") || "";
      const departmentColumn = form.getAttribute("data-department-column") || "";
      const eventFilePath = form.getAttribute("data-event-file") || "";

      if (!loadButton || !fieldsContainer || !submitButton || !eventCode) {
        return;
      }

      loadButton.addEventListener("click", async () => {
        if (!eventFilePath) {
          notify("No uploaded file found for this event.", true);
          return;
        }
        try {
          const response = await fetch(eventFilePath);
          if (!response.ok) {
            throw new Error("Unable to load the uploaded file.");
          }
          const buffer = await response.arrayBuffer();
          const rows = await readRowsFromBuffer(buffer);
          if (!rows.length) {
            notify("No data found in the uploaded file.", true);
            return;
          }
          const headers = rows[0].map((header) => String(header).trim());
          const ignoredIndexes = new Set();
          if (totalScoreColumn) {
            const index = headers.indexOf(totalScoreColumn);
            if (index >= 0) {
              ignoredIndexes.add(index);
            }
          }
          if (departmentColumn) {
            const index = headers.indexOf(departmentColumn);
            if (index >= 0) {
              ignoredIndexes.add(index);
            }
          }
          const values = collectValues(rows, ignoredIndexes);
          if (!values.length) {
            notify("No translation values found after ignoring titles and excluded columns.", true);
            return;
          }
          fieldsContainer.innerHTML = "";
          const maxValue = values.length;
          values.forEach((value, idx) => {
            const field = document.createElement("label");
            field.className = "field standard-width";
            const label = document.createElement("span");
            label.textContent = value;
            const select = document.createElement("select");
            select.setAttribute("data-score-select", value);
            for (let i = 0; i < maxValue; i += 1) {
              const option = document.createElement("option");
              option.value = String(i);
              option.textContent = String(i);
              if (i === idx) {
                option.selected = true;
              }
              select.appendChild(option);
            }
            field.appendChild(label);
            field.appendChild(select);
            fieldsContainer.appendChild(field);
          });
        } catch (error) {
          notify(error?.message || "Unable to read the uploaded file.", true);
        }
      });

      submitButton.addEventListener("click", async () => {
        const selects = fieldsContainer.querySelectorAll("[data-score-select]");
        if (!selects.length) {
          notify("Please load the uploaded file to generate translation fields.", true);
          return;
        }
        if (!eventFilePath) {
          notify("No uploaded file found for this event.", true);
          return;
        }
        if (!departmentColumn) {
          notify("No department column configured for this event.", true);
          return;
        }
        const translations = Array.from(selects).map((select) => {
          const label = select.getAttribute("data-score-select") || "";
          return {
            label,
            value: label,
            score: Number(select.value)
          };
        });
        let eventFinal = null;
        try {
          const response = await fetch(eventFilePath);
          if (!response.ok) {
            throw new Error("Unable to load the uploaded file.");
          }
          const buffer = await response.arrayBuffer();
          const rows = await readRowsFromBuffer(buffer);
          if (!rows.length) {
            throw new Error("No data found in the uploaded file.");
          }
          const headers = rows[0].map((header) => String(header).trim());
          const ignoredIndexes = new Set();
          if (totalScoreColumn) {
            const index = headers.indexOf(totalScoreColumn);
            if (index >= 0) {
              ignoredIndexes.add(index);
            }
          }
          eventFinal = buildEventFinal(rows, headers, departmentColumn, ignoredIndexes);
        } catch (error) {
          notify(error?.message || "Unable to generate event summary.", true);
          return;
        }
        const formData = new FormData();
        formData.append("action", "hra_save_score_translation");
        formData.append("event_code", eventCode);
        formData.append("translations", JSON.stringify(translations));
        formData.append("event_final", JSON.stringify(eventFinal));

        try {
          const response = await fetch("HRA/HRAEvents.php", {
            method: "POST",
            body: formData
          });
          const payload = await response.json();
          if (!response.ok || !payload.success) {
            throw new Error(payload?.message || "Unable to save score translations.");
          }
          notify("Score translations saved.");
        } catch (error) {
          notify(error?.message || "Unable to save score translations.", true);
        }
      });
    });
  });
</script>
