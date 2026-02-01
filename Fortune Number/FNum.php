<?php
$dataPath = __DIR__ . '/FNum.json';
$startNumber = '';
$endNumber = '';
$existingWinners = [];
$saveMessage = '';
$saveError = '';

if (is_file($dataPath)) {
  $raw = file_get_contents($dataPath);
  if ($raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      $startNumber = isset($decoded['start']) ? (string)$decoded['start'] : '';
      $endNumber = isset($decoded['end']) ? (string)$decoded['end'] : '';
      $existingWinners = is_array($decoded['winners'] ?? null) ? array_values($decoded['winners']) : [];
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $startNumber = isset($_POST['start_number']) ? trim((string)$_POST['start_number']) : '';
  $endNumber = isset($_POST['end_number']) ? trim((string)$_POST['end_number']) : '';
  $payload = [
    'start' => $startNumber,
    'end' => $endNumber,
    'winners' => $existingWinners
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  $saved = false;
  if ($encoded === false) {
    $saveError = 'Save failed: invalid JSON payload.';
  } else {
    $result = @file_put_contents($dataPath, $encoded, LOCK_EX);
    if ($result === false) {
      $errorInfo = error_get_last();
      $saveError = $errorInfo['message'] ?? 'Save failed: unable to write file.';
    } else {
      $saved = true;
    }
  }
  $saveMessage = $saved ? 'Saved.' : ($saveError ?: 'Save failed.');
  $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => $saved,
      'message' => $saveMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
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

<script>
  (() => {
    const form = document.getElementById('fnum-control-form');
    if (!form) {
      return;
    }
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
        let payload = null;
        try {
          payload = await response.json();
        } catch (error) {
          payload = null;
        }
        const ok = response.ok && payload && payload.ok;
        const message = (payload && payload.message) ? payload.message : (ok ? 'Saved.' : 'Save failed.');
        if (ok) {
          if (typeof window.showActionSnackbar === 'function') {
            window.showActionSnackbar({
              message,
              instructionLabel: '',
              shortcut: null
            });
          } else {
            alert(message);
          }
        } else if (typeof window.showErrorSnackbar === 'function') {
          window.showErrorSnackbar({ message });
        } else if (typeof window.showActionSnackbar === 'function') {
          window.showActionSnackbar({
            message,
            instructionLabel: '',
            shortcut: null
          });
        } else {
          alert(message);
        }
      } catch (error) {
        const message = (error && error.message) ? error.message : 'Save failed.';
        if (typeof window.showErrorSnackbar === 'function') {
          window.showErrorSnackbar({ message });
        } else if (typeof window.showActionSnackbar === 'function') {
          window.showActionSnackbar({
            message,
            instructionLabel: '',
            shortcut: null
          });
        } else {
          alert(message);
        }
      }
    });
  })();
</script>
