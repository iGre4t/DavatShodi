<?php
declare(strict_types=1);

$dataPath = __DIR__ . '/FNum.json';
$startNumber = '';
$endNumber = '';

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
?>

<div class="card">
  <div class="section-header">
    <h3>Fortune Number Range</h3>
  </div>
  <div class="form">
    <div class="grid full">
      <label class="field">
        <span>Start number</span>
        <input type="text" value="<?= htmlspecialchars($startNumber, ENT_QUOTES, 'UTF-8') ?>" readonly />
      </label>
      <label class="field">
        <span>End number</span>
        <input type="text" value="<?= htmlspecialchars($endNumber, ENT_QUOTES, 'UTF-8') ?>" readonly />
      </label>
    </div>
  </div>
</div>
