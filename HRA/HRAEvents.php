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
          ?>
          <div class="sub-pane<?= $isActive ? ' active' : '' ?>" data-pane="<?= htmlspecialchars($paneId, ENT_QUOTES, 'UTF-8') ?>">
            <div class="card">
              <h3><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h3>
              <p class="muted">This event tab is ready. Content coming soon.</p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
