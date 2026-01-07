<?php
$hraBaseDir = __DIR__;

function hraLoadJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function hraLoadEventFinal(string $baseDir): array
{
    $rootPath = $baseDir . '/EventFinal.json';
    $rootData = hraLoadJsonFile($rootPath);
    if (!empty($rootData)) {
        return $rootData;
    }

    $eventsData = hraLoadJsonFile($baseDir . '/events.json');
    $events = is_array($eventsData['events'] ?? null) ? $eventsData['events'] : [];
    if (!empty($events)) {
        for ($index = count($events) - 1; $index >= 0; $index -= 1) {
            $event = $events[$index];
            $eventCode = is_array($event) ? trim((string)($event['code'] ?? '')) : '';
            if ($eventCode === '') {
                continue;
            }
            $eventPath = $baseDir . '/events/' . $eventCode . '/EventFinal.json';
            $eventData = hraLoadJsonFile($eventPath);
            if (!empty($eventData)) {
                return $eventData;
            }
        }
    }

    $candidates = glob($baseDir . '/events/*/EventFinal.json') ?: [];
    if (!empty($candidates)) {
        usort($candidates, static function (string $left, string $right): int {
            return (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);
        });
        $latest = hraLoadJsonFile($candidates[0]);
        if (!empty($latest)) {
            return $latest;
        }
    }

    return [];
}

function hraExtractList(array $data, array $keys): array
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = $data[$key];
        if (!is_array($value)) {
            continue;
        }
        return array_values($value);
    }
    return [];
}

function hraNormalizeItems(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        if (is_string($item)) {
            $label = trim($item);
        } elseif (is_array($item)) {
            $label = trim((string)($item['name'] ?? $item['label'] ?? $item['title'] ?? ''));
        } else {
            $label = '';
        }
        if ($label !== '') {
            $normalized[] = $label;
        }
    }
    return $normalized;
}

function hraExtractDepartmentNames(array $eventFinalData): array
{
    if (isset($eventFinalData['departments']) && is_array($eventFinalData['departments'])) {
        return hraNormalizeItems(array_keys($eventFinalData['departments']));
    }
    return hraNormalizeItems(hraExtractList($eventFinalData, [
        'Departements',
        'Departments',
        'departements',
        'departments'
    ]));
}

function hraExtractEventItems(array $eventFinalData): array
{
    if (isset($eventFinalData['departments']) && is_array($eventFinalData['departments'])) {
        $items = [];
        foreach ($eventFinalData['departments'] as $department) {
            if (!is_array($department)) {
                continue;
            }
            $departmentItems = $department['items'] ?? [];
            if (!is_array($departmentItems)) {
                continue;
            }
            foreach (array_keys($departmentItems) as $title) {
                $items[] = $title;
            }
        }
        return hraNormalizeItems(array_unique($items));
    }
    return hraNormalizeItems(hraExtractList($eventFinalData, [
        'Event Items',
        'EventItems',
        'event_items',
        'eventItems'
    ]));
}

$eventFinalData = hraLoadEventFinal($hraBaseDir);
$departmentItems = hraExtractDepartmentNames($eventFinalData);
$eventItems = hraExtractEventItems($eventFinalData);
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>HRA Monitor</title>
    <meta name="color-scheme" content="light" />
    <script src="../style/appearance.js"></script>
    <link rel="stylesheet" href="../style/styles.css" />
    <link rel="stylesheet" href="../style/remixicon.css" />
    <style>
      .hra-monitor {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 200px 230px;
        grid-template-areas: "content events departments";
        min-height: 100vh;
        background: var(--bg);
        direction: ltr;
      }
      .hra-sidebar {
        padding: 12px 10px;
        width: 100%;
      }
      .hra-sidebar-left {
        grid-area: events;
      }
      .hra-sidebar-right {
        grid-area: departments;
      }
      .hra-sidebar .sidebar-header {
        padding: 4px 6px 10px;
      }
      .hra-filters {
        display: grid;
        gap: 16px;
        padding: 0 6px;
      }
      .hra-filters select[multiple] {
        min-height: 160px;
        background-image: none;
        padding-right: 12px;
        padding-inline-end: 12px;
      }
      .hra-content {
        padding: 24px;
        grid-area: content;
      }
      .hra-content .card + .card {
        margin-top: 16px;
      }
      @media (max-width: 900px) {
        .hra-monitor {
          grid-template-columns: 1fr;
          grid-template-areas:
            "departments"
            "events"
            "content";
        }
        .hra-sidebar {
          width: 100%;
          height: auto;
          position: static;
        }
      }
    </style>
  </head>
  <body>
    <header class="topbar">مانیتور منابع انسانی</header>
    <div class="hra-monitor">
      <aside class="sidebar hra-sidebar hra-sidebar-left" dir="rtl">
        <div class="sidebar-header">
          <div class="title">Event Items</div>
        </div>
        <div class="hra-filters">
          <label class="field">
            <span>Event Items</span>
            <select multiple size="8">
              <?php if (empty($eventItems)): ?>
                <option disabled>No event items yet.</option>
              <?php else: ?>
                <?php foreach ($eventItems as $item): ?>
                  <option><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </label>
        </div>
      </aside>
      <aside class="sidebar hra-sidebar hra-sidebar-right" dir="rtl">
        <div class="sidebar-header">
          <div class="title">Departments</div>
        </div>
        <div class="hra-filters">
          <label class="field">
            <span>Departments</span>
            <select multiple size="6">
              <?php if (empty($departmentItems)): ?>
                <option disabled>No departments yet.</option>
              <?php else: ?>
                <?php foreach ($departmentItems as $department): ?>
                  <option><?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </label>
        </div>
      </aside>
      <main class="hra-content" dir="rtl">
        <div class="card">
          <h3>HRA Monitor Dashboard</h3>
          <p class="muted">Monitor event progress and department participation at a glance.</p>
        </div>
        <div class="card">
          <h3>Department Summary</h3>
          <p class="muted">Select one or more filters to view the summarized results.</p>
        </div>
      </main>
    </div>
  </body>
</html>
