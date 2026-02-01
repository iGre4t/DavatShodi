<?php
declare(strict_types=1);

$dataPath = __DIR__ . '/FNum.json';
$startNumber = '';
$endNumber = '';
$saveMessage = '';

function readFnumRange(string $path): array
{
  if (!is_file($path)) {
    return ['start' => '', 'end' => ''];
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    return ['start' => '', 'end' => ''];
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return ['start' => '', 'end' => ''];
  }
  return [
    'start' => isset($decoded['start']) ? (string)$decoded['start'] : '',
    'end' => isset($decoded['end']) ? (string)$decoded['end'] : ''
  ];
}

function writeFnumData(string $path, string $start, string $end, array $winners): bool
{
  $payload = [
    'start' => $start,
    'end' => $end,
    'winners' => array_values($winners)
  ];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded, LOCK_EX) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $current = readFnumRange($dataPath);
  $currentStart = $current['start'];
  $currentEnd = $current['end'];
  $currentWinners = [];
  if (is_file($dataPath)) {
    $raw = file_get_contents($dataPath);
    if ($raw !== false) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded) && is_array($decoded['winners'] ?? null)) {
        $currentWinners = array_values($decoded['winners']);
      }
    }
  }

  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
  if ($action === 'delete_winner') {
    $index = isset($_POST['winner_index']) ? (int)$_POST['winner_index'] : -1;
    if ($index >= 0 && $index < count($currentWinners)) {
      array_splice($currentWinners, $index, 1);
    }
    $saved = writeFnumData($dataPath, $currentStart, $currentEnd, $currentWinners);
  } else {
    $startNumber = isset($_POST['start_number']) ? trim((string)$_POST['start_number']) : '';
    $endNumber = isset($_POST['end_number']) ? trim((string)$_POST['end_number']) : '';
    $currentStart = $startNumber;
    $currentEnd = $endNumber;
    $saved = writeFnumData($dataPath, $currentStart, $currentEnd, $currentWinners);
  }
  $saveMessage = $saved ? 'Saved.' : 'Save failed.';

  $redirectTo = $_SERVER['REQUEST_URI'] ?? 'panel.php';
  $separator = str_contains($redirectTo, '?') ? '&' : '?';
  header('Location: ' . $redirectTo . $separator . 'fnum_saved=' . ($saved ? '1' : '0'));
  exit;
}

$range = readFnumRange($dataPath);
$startNumber = $range['start'];
$endNumber = $range['end'];
$savedFlag = isset($_GET['fnum_saved']) ? (string)$_GET['fnum_saved'] : '';
if ($savedFlag !== '') {
  $saveMessage = $savedFlag === '1' ? 'Saved.' : 'Save failed.';
}
?>

<div class="card">
  <div class="section-header">
    <h3>Fortune Number Range</h3>
  </div>
  <form class="form" method="post">
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
      <?php if ($saveMessage !== ''): ?>
        <span class="muted"><?= htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="section-header">
    <h3>Winners</h3>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Winner Number</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $winners = [];
        if (is_file($dataPath)) {
          $raw = file_get_contents($dataPath);
          if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['winners'] ?? null)) {
              $winners = array_values($decoded['winners']);
            }
          }
        }
        ?>
        <?php if (!empty($winners)): ?>
          <?php foreach ($winners as $idx => $winner): ?>
            <tr>
              <td><?= htmlspecialchars((string)$winner, ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="delete_winner" />
                  <input type="hidden" name="winner_index" value="<?= (int)$idx ?>" />
                  <button type="submit" class="btn danger">Delete</button>
                </form>
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
