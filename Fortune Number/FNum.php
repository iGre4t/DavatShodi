<?php
$dataPath = __DIR__ . '/FNum.json';
$startNumber = '';
$endNumber = '';
$saveMessage = '';

if (is_file($dataPath)) {
  $raw = file_get_contents($dataPath);
  if ($raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      $startNumber = isset($decoded['start']) ? (string)$decoded['start'] : '';
      $endNumber = isset($decoded['end']) ? (string)$decoded['end'] : '';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $startNumber = isset($_POST['start_number']) ? trim((string)$_POST['start_number']) : '';
  $endNumber = isset($_POST['end_number']) ? trim((string)$_POST['end_number']) : '';
  $payload = [
    'start' => $startNumber,
    'end' => $endNumber
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded !== false && file_put_contents($dataPath, $encoded) !== false) {
    $saveMessage = 'Saved.';
  } else {
    $saveMessage = 'Save failed.';
  }
}
?>

<div class="card">
  <div class="section-header">
    <h3>Control Panel</h3>
  </div>
  <form method="post" class="form">
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
    <?php if ($saveMessage !== ''): ?>
      <p class="hint"><?= htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </form>
</div>
