<?php
<<<<<<< ours
<<<<<<< ours
=======
=======
>>>>>>> theirs
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

function hraDeleteDirectoryRecursive(string $directory): bool
{
    if (!is_dir($directory)) {
        return true;
    }
    $items = array_diff(scandir($directory), ['.', '..']);
    foreach ($items as $item) {
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!hraDeleteDirectoryRecursive($path)) {
                return false;
            }
        } else {
            if (!unlink($path)) {
                return false;
            }
        }
    }
    return rmdir($directory);
}

<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
$hraAction = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hraAction === 'hra_save_mapping') {
    header('Content-Type: application/json; charset=utf-8');

    $eventName = trim((string)($_POST['event_name'] ?? ''));
    $totalScoreColumn = trim((string)($_POST['total_score_column'] ?? ''));
<<<<<<< ours

    if ($eventName === '' || $totalScoreColumn === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please provide an event name and total score column.'
=======
    $departmentColumn = trim((string)($_POST['department_column'] ?? ''));

    if ($eventName === '' || $totalScoreColumn === '' || $departmentColumn === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please provide an event name, total score column, and department column.'
>>>>>>> theirs
        ]);
        exit;
    }

<<<<<<< ours
<<<<<<< ours
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

=======
>>>>>>> theirs
=======
>>>>>>> theirs
    $maxCode = 0;
    foreach ($eventsData['events'] as $event) {
        $codeValue = is_array($event) ? ($event['code'] ?? '') : '';
        if (preg_match('/^\d+$/', (string)$codeValue)) {
            $numeric = (int)$codeValue;
            if ($numeric > $maxCode) {
                $maxCode = $numeric;
            }
        }
    }

    $nextCodeNumber = $maxCode + 1;
    $eventCode = str_pad((string)$nextCodeNumber, 3, '0', STR_PAD_LEFT);

    $eventsData['events'][] = [
        'code' => $eventCode,
        'name' => $eventName
    ];

    $eventsRoot = $hraBaseDir . '/events';
    if (!is_dir($eventsRoot) && !mkdir($eventsRoot, 0775, true) && !is_dir($eventsRoot)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to create the events directory.'
        ]);
        exit;
    }

    $eventDir = $eventsRoot . '/' . $eventCode;
    if (!is_dir($eventDir) && !mkdir($eventDir, 0775, true) && !is_dir($eventDir)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to create the event directory.'
        ]);
        exit;
    }

    $eventData = [
        'event_code' => $eventCode,
        'event_name' => $eventName,
<<<<<<< ours
        'event_total_score_column' => $totalScoreColumn
=======
        'event_total_score_column' => $totalScoreColumn,
        'event_department_column' => $departmentColumn
>>>>>>> theirs
    ];

    if (file_put_contents(
        $eventDir . '/eventdata.json',
        json_encode($eventData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to save the event mapping.'
        ]);
        exit;
    }

    if (file_put_contents(
        $eventsFile,
        json_encode($eventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update the event list.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'event_code' => $eventCode
    ]);
    exit;
}
<<<<<<< ours
<<<<<<< ours
=======
=======
>>>>>>> theirs

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hraAction === 'hra_delete_event') {
    header('Content-Type: application/json; charset=utf-8');
    $eventCode = trim((string)($_POST['event_code'] ?? ''));

    if ($eventCode === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Event code is required.'
        ]);
        exit;
    }

    $eventsRoot = $hraBaseDir . '/events';
    $eventDir = $eventsRoot . '/' . $eventCode;
    if (is_dir($eventDir) && !hraDeleteDirectoryRecursive($eventDir)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to delete the event directory.'
        ]);
        exit;
    }

    $eventsData['events'] = array_values(array_filter(
        $eventsData['events'],
        static fn($event) => is_array($event) && ($event['code'] ?? '') !== $eventCode
    ));

    if (file_put_contents(
        $eventsFile,
        json_encode($eventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update the event list.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true
    ]);
    exit;
}
<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
?>

<section id="tab-hra" class="tab">
  <div class="card">
    <div class="table-header" style="flex-direction:column; align-items:flex-start; gap:12px;">
      <h3>HR Analyze Panel</h3>
      <p class="muted">Upload an Excel or CSV file, then map the Event Total Score column.</p>
    </div>
    <div class="form grid one-column" style="margin-top:12px;">
      <label class="field standard-width">
        <span>Event Name</span>
        <input id="hra-event-name" type="text" placeholder="Enter event name" />
      </label>
      <label class="field standard-width">
        <span>Excel / CSV file</span>
        <input
          id="hra-file-input"
          type="file"
          accept=".csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        />
      </label>
      <div>
        <button type="button" class="btn primary" id="hra-upload-button">Upload</button>
      </div>
    </div>
  </div>
<<<<<<< ours
<<<<<<< ours
=======
=======
>>>>>>> theirs
  <div class="card" style="margin-top:16px;">
    <div class="table-header">
      <h3>Events</h3>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Event Code</th>
            <th>Event Name</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="hra-events-body">
          <?php if (empty($eventsData['events'])): ?>
            <tr data-hra-empty>
              <td colspan="3" class="muted">No events have been created yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($eventsData['events'] as $event): ?>
              <?php
                $eventCode = htmlspecialchars((string)($event['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                $eventName = htmlspecialchars((string)($event['name'] ?? ''), ENT_QUOTES, 'UTF-8');
              ?>
              <tr data-event-code="<?= $eventCode ?>">
                <td><?= $eventCode ?></td>
                <td><?= $eventName ?></td>
                <td>
                  <button type="button" class="btn ghost small" data-hra-delete-event data-event-code="<?= $eventCode ?>">
                    Delete
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
</section>

<div id="hra-mapping-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="hra-mapping-title">
  <div class="modal-card">
    <div class="modal-header">
      <h3 id="hra-mapping-title">Table Mapping</h3>
      <button type="button" class="icon-btn" data-hra-modal-close aria-label="Close mapping modal">X</button>
    </div>
    <div class="modal-body">
      <label class="field standard-width">
        <span>Event Total Score</span>
        <select id="hra-total-score-column"></select>
      </label>
<<<<<<< ours
=======
      <label class="field standard-width">
        <span>Department</span>
        <select id="hra-department-column"></select>
      </label>
>>>>>>> theirs
    </div>
    <div class="modal-actions">
      <button type="button" class="btn" data-hra-modal-close>Cancel</button>
      <button type="button" class="btn primary" id="hra-submit-mapping">Submit</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
<script>
  document.addEventListener("DOMContentLoaded", () => {
    const uploadButton = document.getElementById("hra-upload-button");
    const fileInput = document.getElementById("hra-file-input");
    const modal = document.getElementById("hra-mapping-modal");
    const modalCloseButtons = document.querySelectorAll("[data-hra-modal-close]");
    const totalScoreSelect = document.getElementById("hra-total-score-column");
<<<<<<< ours
    const submitButton = document.getElementById("hra-submit-mapping");
    const eventNameInput = document.getElementById("hra-event-name");
<<<<<<< ours
=======
    const eventsBody = document.getElementById("hra-events-body");
>>>>>>> theirs

    if (!uploadButton || !fileInput || !modal || !totalScoreSelect || !submitButton || !eventNameInput) {
=======
    const departmentSelect = document.getElementById("hra-department-column");
    const submitButton = document.getElementById("hra-submit-mapping");
    const eventNameInput = document.getElementById("hra-event-name");
    const eventsBody = document.getElementById("hra-events-body");

    if (!uploadButton || !fileInput || !modal || !totalScoreSelect || !departmentSelect || !submitButton || !eventNameInput) {
>>>>>>> theirs
      return;
    }

    function showModal() {
      modal.classList.remove("hidden");
      modal.focus?.();
    }

    function hideModal() {
      modal.classList.add("hidden");
    }

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

    modalCloseButtons.forEach((btn) => btn.addEventListener("click", hideModal));

    async function readHeadersFromFile(file) {
      if (typeof XLSX === "undefined") {
        throw new Error("Excel parser not loaded yet. Please try again in a moment.");
      }
      const buffer = await file.arrayBuffer();
      const workbook = XLSX.read(buffer, { type: "array" });
      const sheetName = workbook.SheetNames[0];
      if (!sheetName) {
        return [];
      }
      const sheet = workbook.Sheets[sheetName];
      const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "" });
      const headerRow = rows[0] || [];
      return headerRow.map((header) => String(header).trim()).filter(Boolean);
    }

    uploadButton.addEventListener("click", async () => {
      const file = fileInput.files?.[0];
      if (!file) {
        notify("Please choose an Excel or CSV file.", true);
        return;
      }
      try {
        const headers = await readHeadersFromFile(file);
        if (!headers.length) {
          notify("No columns found in the uploaded file.", true);
          return;
        }
        totalScoreSelect.innerHTML = "";
<<<<<<< ours
=======
        departmentSelect.innerHTML = "";
>>>>>>> theirs
        headers.forEach((header) => {
          const option = document.createElement("option");
          option.value = header;
          option.textContent = header;
          totalScoreSelect.appendChild(option);
<<<<<<< ours
=======
          const departmentOption = document.createElement("option");
          departmentOption.value = header;
          departmentOption.textContent = header;
          departmentSelect.appendChild(departmentOption);
>>>>>>> theirs
        });
        showModal();
      } catch (error) {
        notify(error?.message || "Unable to read the uploaded file.", true);
      }
    });

    submitButton.addEventListener("click", async () => {
      const eventName = eventNameInput.value.trim();
      const totalScoreColumn = totalScoreSelect.value.trim();
<<<<<<< ours
=======
      const departmentColumn = departmentSelect.value.trim();
>>>>>>> theirs

      if (!eventName) {
        notify("Please enter an event name.", true);
        return;
      }
      if (!totalScoreColumn) {
        notify("Please choose the Event Total Score column.", true);
        return;
      }
<<<<<<< ours
=======
      if (!departmentColumn) {
        notify("Please choose the Department column.", true);
        return;
      }
>>>>>>> theirs

      const formData = new FormData();
      formData.append("action", "hra_save_mapping");
      formData.append("event_name", eventName);
      formData.append("total_score_column", totalScoreColumn);
<<<<<<< ours
=======
      formData.append("department_column", departmentColumn);
>>>>>>> theirs

      try {
        const response = await fetch("HRA/HRAupload.php", {
          method: "POST",
          body: formData
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
          throw new Error(payload?.message || "Unable to save the mapping.");
        }
        hideModal();
        notify(`Event saved with code ${payload.event_code}.`);
<<<<<<< ours
<<<<<<< ours
=======
        window.location.reload();
>>>>>>> theirs
=======
        window.location.reload();
>>>>>>> theirs
        eventNameInput.value = "";
        fileInput.value = "";
      } catch (error) {
        notify(error?.message || "Unable to save the mapping.", true);
      }
    });
<<<<<<< ours
<<<<<<< ours
=======
=======
>>>>>>> theirs

    eventsBody?.addEventListener("click", async (event) => {
      const target = event.target instanceof HTMLElement ? event.target : null;
      const deleteButton = target?.closest?.("[data-hra-delete-event]");
      if (!deleteButton) {
        return;
      }
      const eventCode = deleteButton.getAttribute("data-event-code") || "";
      if (!eventCode) {
        return;
      }
      if (!confirm("Are you sure you want to delete this event?")) {
        return;
      }
      const formData = new FormData();
      formData.append("action", "hra_delete_event");
      formData.append("event_code", eventCode);
      try {
        const response = await fetch("HRA/HRAupload.php", {
          method: "POST",
          body: formData
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) {
          throw new Error(payload?.message || "Unable to delete the event.");
        }
        const row = deleteButton.closest("tr");
        row?.remove();
        const remainingRows = eventsBody?.querySelectorAll("tr[data-event-code]") ?? [];
        if (remainingRows.length === 0 && eventsBody) {
          eventsBody.innerHTML = '<tr data-hra-empty><td colspan="3" class="muted">No events have been created yet.</td></tr>';
        }
        notify("Event deleted.");
      } catch (error) {
        notify(error?.message || "Unable to delete the event.", true);
      }
    });
<<<<<<< ours
>>>>>>> theirs
=======
>>>>>>> theirs
  });
</script>
