<?php
declare(strict_types=1);

session_start();
if (empty($_SESSION['authenticated'])) {
    header('Location: ../../login.php');
    exit;
}

const DATA_DIR = __DIR__ . '/../preopreties manager';
const PROPERTIES_FILE = DATA_DIR . '/properties.json';
const STORAGES_FILE = DATA_DIR . '/storages.json';
const ANCESTOR_PROPERTIES_FILE = DATA_DIR . '/ancestor_properties.json';

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    if (!is_file(PROPERTIES_FILE)) {
        @file_put_contents(PROPERTIES_FILE, "[]\n", LOCK_EX);
    }
    if (!is_file(STORAGES_FILE)) {
        @file_put_contents(STORAGES_FILE, "[]\n", LOCK_EX);
    }
    if (!is_file(ANCESTOR_PROPERTIES_FILE)) {
        @file_put_contents(ANCESTOR_PROPERTIES_FILE, "[]\n", LOCK_EX);
    }
}

function clean(string $value): string
{
    $trim = trim($value);
    return preg_replace('/\s+/', ' ', $trim) ?? $trim;
}

function readArray(string $path): array
{
    ensureDataDir();
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function writeArray(string $path, array $items): bool
{
    $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function loadStorages(): array
{
    $items = [];
    foreach (readArray(STORAGES_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function loadAncestors(): array
{
    $items = [];
    foreach (readArray(ANCESTOR_PROPERTIES_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => $name,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function loadProperties(array $ancestors = []): array
{
    $items = [];
    foreach (readArray(PROPERTIES_FILE) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        $ancestorId = trim((string)($row['ancestor_id'] ?? ''));
        $name = clean((string)($row['name'] ?? ''));
        if ($ancestorId === '' && $name !== '') {
            $ancestorId = ancestorIdByName($ancestors, $name);
        }
        $code = clean((string)($row['code'] ?? ''));
        $storageId = trim((string)($row['storage_id'] ?? ''));
        if ($id === '' || $code === '') {
            continue;
        }
        $items[] = [
            'id' => $id,
            'ancestor_id' => $ancestorId,
            'name' => $name,
            'code' => $code,
            'storage_id' => $storageId,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    return $items;
}

function idxById(array $items, string $id): int
{
    foreach ($items as $i => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            return (int)$i;
        }
    }
    return -1;
}

function storageExists(array $storages, string $storageId): bool
{
    return idxById($storages, $storageId) >= 0;
}

function ancestorExists(array $ancestors, string $ancestorId): bool
{
    return idxById($ancestors, $ancestorId) >= 0;
}

function storageNameExists(array $storages, string $name, string $except = ''): bool
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return false;
    }
    foreach ($storages as $s) {
        $id = (string)($s['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($s['name'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function ancestorNameExists(array $ancestors, string $name, string $except = ''): bool
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return false;
    }
    foreach ($ancestors as $a) {
        $id = (string)($a['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($a['name'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function propertyCodeExists(array $properties, string $code, string $except = ''): bool
{
    $needle = strtolower(clean($code));
    if ($needle === '') {
        return false;
    }
    foreach ($properties as $p) {
        $id = (string)($p['id'] ?? '');
        if ($except !== '' && $id === $except) {
            continue;
        }
        if (strtolower(clean((string)($p['code'] ?? ''))) === $needle) {
            return true;
        }
    }
    return false;
}

function ancestorNameById(array $ancestors, string $ancestorId): string
{
    foreach ($ancestors as $a) {
        if ((string)($a['id'] ?? '') === $ancestorId) {
            return clean((string)($a['name'] ?? ''));
        }
    }
    return '';
}

function ancestorIdByName(array $ancestors, string $name): string
{
    $needle = strtolower(clean($name));
    if ($needle === '') {
        return '';
    }
    foreach ($ancestors as $a) {
        if (strtolower(clean((string)($a['name'] ?? ''))) === $needle) {
            return (string)($a['id'] ?? '');
        }
    }
    return '';
}

function storageUsed(array $properties, string $storageId): bool
{
    foreach ($properties as $p) {
        if ((string)($p['storage_id'] ?? '') === $storageId) {
            return true;
        }
    }
    return false;
}

function ancestorUsed(array $properties, string $ancestorId): bool
{
    foreach ($properties as $p) {
        if ((string)($p['ancestor_id'] ?? '') === $ancestorId) {
            return true;
        }
    }
    return false;
}

function out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function okData(array $storages, array $ancestors, array $properties): array
{
    return [
        'status' => 'ok',
        'storages' => array_values($storages),
        'ancestors' => array_values($ancestors),
        'properties' => array_values($properties)
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = clean((string)($_POST['action'] ?? ''));
    $storages = loadStorages();
    $ancestors = loadAncestors();
    $properties = loadProperties($ancestors);

    if ($action === 'add_storage') {
        $name = clean((string)($_POST['name'] ?? ''));
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Storage name is required.'], 422);
        }
        if (storageNameExists($storages, $name)) {
            out(['status' => 'error', 'message' => 'Storage name must be unique.'], 422);
        }
        $now = date('c');
        $storages[] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
        if (!writeArray(STORAGES_FILE, $storages)) {
            out(['status' => 'error', 'message' => 'Unable to save storage.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'update_storage') {
        $id = trim((string)($_POST['id'] ?? ''));
        $name = clean((string)($_POST['value'] ?? ''));
        if ($id === '' || $name === '') {
            out(['status' => 'error', 'message' => 'Invalid storage update.'], 422);
        }
        $idx = idxById($storages, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Storage not found.'], 404);
        }
        if (storageNameExists($storages, $name, $id)) {
            out(['status' => 'error', 'message' => 'Storage name must be unique.'], 422);
        }
        $storages[$idx]['name'] = $name;
        $storages[$idx]['updated_at'] = date('c');
        if (!writeArray(STORAGES_FILE, $storages)) {
            out(['status' => 'error', 'message' => 'Unable to save storage changes.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'remove_storage') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Storage id is required.'], 422);
        }
        if (storageUsed($properties, $id)) {
            out(['status' => 'error', 'message' => 'Storage is used by properties and cannot be removed.'], 422);
        }
        $next = [];
        $removed = false;
        foreach ($storages as $s) {
            if ((string)($s['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $s;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Storage not found.'], 404);
        }
        if (!writeArray(STORAGES_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove storage.'], 500);
        }
        out(okData($next, $ancestors, $properties));
    }

    if ($action === 'add_ancestor') {
        $name = clean((string)($_POST['name'] ?? ''));
        if ($name === '') {
            out(['status' => 'error', 'message' => 'Ancestor property name is required.'], 422);
        }
        if (ancestorNameExists($ancestors, $name)) {
            out(['status' => 'error', 'message' => 'Ancestor property name must be unique.'], 422);
        }
        $now = date('c');
        $ancestors[] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
        if (!writeArray(ANCESTOR_PROPERTIES_FILE, $ancestors)) {
            out(['status' => 'error', 'message' => 'Unable to save ancestor property.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'update_ancestor') {
        $id = trim((string)($_POST['id'] ?? ''));
        $name = clean((string)($_POST['value'] ?? ''));
        if ($id === '' || $name === '') {
            out(['status' => 'error', 'message' => 'Invalid ancestor property update.'], 422);
        }
        $idx = idxById($ancestors, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Ancestor property not found.'], 404);
        }
        if (ancestorNameExists($ancestors, $name, $id)) {
            out(['status' => 'error', 'message' => 'Ancestor property name must be unique.'], 422);
        }
        $ancestors[$idx]['name'] = $name;
        $ancestors[$idx]['updated_at'] = date('c');
        if (!writeArray(ANCESTOR_PROPERTIES_FILE, $ancestors)) {
            out(['status' => 'error', 'message' => 'Unable to save ancestor property changes.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'remove_ancestor') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Ancestor property id is required.'], 422);
        }
        if (ancestorUsed($properties, $id)) {
            out(['status' => 'error', 'message' => 'Ancestor property is used by properties and cannot be removed.'], 422);
        }
        $next = [];
        $removed = false;
        foreach ($ancestors as $a) {
            if ((string)($a['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $a;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Ancestor property not found.'], 404);
        }
        if (!writeArray(ANCESTOR_PROPERTIES_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove ancestor property.'], 500);
        }
        out(okData($storages, $next, $properties));
    }

    if ($action === 'add_property') {
        $ancestorId = trim((string)($_POST['ancestor_id'] ?? ''));
        $code = clean((string)($_POST['code'] ?? ''));
        $storageId = trim((string)($_POST['storage_id'] ?? ''));
        if ($ancestorId === '' || $code === '' || $storageId === '') {
            out(['status' => 'error', 'message' => 'All property fields are required.'], 422);
        }
        if (!ancestorExists($ancestors, $ancestorId)) {
            out(['status' => 'error', 'message' => 'Selected ancestor property is invalid.'], 422);
        }
        if (!storageExists($storages, $storageId)) {
            out(['status' => 'error', 'message' => 'Selected storage is invalid.'], 422);
        }
        if (propertyCodeExists($properties, $code)) {
            out(['status' => 'error', 'message' => 'Property code must be unique.'], 422);
        }
        $now = date('c');
        $properties[] = [
            'id' => bin2hex(random_bytes(8)),
            'ancestor_id' => $ancestorId,
            'name' => ancestorNameById($ancestors, $ancestorId),
            'code' => $code,
            'storage_id' => $storageId,
            'created_at' => $now,
            'updated_at' => $now
        ];
        if (!writeArray(PROPERTIES_FILE, $properties)) {
            out(['status' => 'error', 'message' => 'Unable to save property.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'update_property') {
        $id = trim((string)($_POST['id'] ?? ''));
        $field = trim((string)($_POST['field'] ?? ''));
        $value = clean((string)($_POST['value'] ?? ''));
        if ($id === '' || $value === '') {
            out(['status' => 'error', 'message' => 'Invalid property update.'], 422);
        }
        if (!in_array($field, ['ancestor_id', 'code', 'storage_id'], true)) {
            out(['status' => 'error', 'message' => 'Invalid property field.'], 422);
        }
        $idx = idxById($properties, $id);
        if ($idx < 0) {
            out(['status' => 'error', 'message' => 'Property not found.'], 404);
        }
        if ($field === 'code' && propertyCodeExists($properties, $value, $id)) {
            out(['status' => 'error', 'message' => 'Property code must be unique.'], 422);
        }
        if ($field === 'storage_id' && !storageExists($storages, $value)) {
            out(['status' => 'error', 'message' => 'Selected storage is invalid.'], 422);
        }
        if ($field === 'ancestor_id' && !ancestorExists($ancestors, $value)) {
            out(['status' => 'error', 'message' => 'Selected ancestor property is invalid.'], 422);
        }
        $properties[$idx][$field] = $value;
        if ($field === 'ancestor_id') {
            $properties[$idx]['name'] = ancestorNameById($ancestors, $value);
        }
        $properties[$idx]['updated_at'] = date('c');
        if (!writeArray(PROPERTIES_FILE, $properties)) {
            out(['status' => 'error', 'message' => 'Unable to save property changes.'], 500);
        }
        out(okData($storages, $ancestors, $properties));
    }

    if ($action === 'remove_property') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            out(['status' => 'error', 'message' => 'Property id is required.'], 422);
        }
        $next = [];
        $removed = false;
        foreach ($properties as $p) {
            if ((string)($p['id'] ?? '') === $id) {
                $removed = true;
                continue;
            }
            $next[] = $p;
        }
        if (!$removed) {
            out(['status' => 'error', 'message' => 'Property not found.'], 404);
        }
        if (!writeArray(PROPERTIES_FILE, $next)) {
            out(['status' => 'error', 'message' => 'Unable to remove property.'], 500);
        }
        out(okData($storages, $ancestors, $next));
    }

    out(['status' => 'error', 'message' => 'Unsupported action.'], 400);
}

$storages = loadStorages();
$ancestors = loadAncestors();
$properties = loadProperties($ancestors);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Properties Manager</title>
  <style>
    :root { font-family: "Segoe UI", Tahoma, Arial, sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f3f6fb; color: #111827; }
    .layout { max-width: 1200px; margin: 0 auto; min-height: 100vh; display: grid; grid-template-columns: 250px 1fr; gap: 18px; padding: 18px; }
    .sidebar, .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 10px 24px rgba(16,24,40,.06); }
    .sidebar { padding: 14px; height: fit-content; }
    .sidebar h1 { margin: 0 0 12px; font-size: 1rem; }
    .nav { display: grid; gap: 8px; }
    .nav button { border: 1px solid #d1d5db; border-radius: 9px; background: #fff; padding: 10px 12px; text-align: left; cursor: pointer; }
    .nav button.active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
    .content { display: grid; gap: 14px; }
    .pane { display: none; gap: 14px; }
    .pane.active { display: grid; }
    .card { padding: 16px; }
    h2 { margin: 0 0 12px; font-size: 1.1rem; }
    .grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; }
    .grid.one { grid-template-columns: 1fr; }
    .field { display: grid; gap: 6px; }
    .field label { color: #4b5563; font-size: .9rem; }
    input, select { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 11px; font-size: .95rem; }
    input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.14); }
    .actions { margin-top: 12px; display: flex; gap: 10px; align-items: center; }
    .btn { border: 0; border-radius: 8px; padding: 9px 14px; font-size: .92rem; cursor: pointer; }
    .primary { background: #2563eb; color: #fff; }
    .danger { background: #ef4444; color: #fff; padding: 8px 12px; }
    .status { font-size: .9rem; color: #374151; }
    .status.error { color: #b91c1c; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; border-bottom: 1px solid #eef2f7; padding: 10px 8px; white-space: nowrap; }
    th { color: #6b7280; font-size: .85rem; }
    td input, td select { min-width: 180px; }
    .empty { color: #6b7280; }
    @media (max-width: 960px) { .layout { grid-template-columns: 1fr; } }
    @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="layout">
    <aside class="sidebar">
      <h1>properties manager</h1>
      <div class="nav">
        <button class="active" data-pane-target="properties-pane" type="button">add properties</button>
        <button data-pane-target="storages-pane" type="button">add storage</button>
        <button data-pane-target="ancestors-pane" type="button">Add Ancestor Properties</button>
      </div>
    </aside>

    <main class="content">
      <section class="pane active" data-pane="properties-pane">
        <section class="card">
          <h2>Add Properties</h2>
          <form id="add-property-form">
            <div class="grid">
              <div class="field"><label for="property-ancestor">Ancestor Property</label><select id="property-ancestor" name="ancestor_id" required></select></div>
              <div class="field"><label for="property-code">Property Code</label><input id="property-code" name="code" type="text" required /></div>
              <div class="field"><label for="property-storage">Storage</label><select id="property-storage" name="storage_id" required></select></div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Property</button>
              <span id="property-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Properties</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Ancestor Property</th><th>Property Code</th><th>Storage</th><th>Action</th></tr></thead>
              <tbody id="properties-body"></tbody>
            </table>
          </div>
        </section>
      </section>

      <section class="pane" data-pane="storages-pane">
        <section class="card">
          <h2>Add Storage</h2>
          <form id="add-storage-form">
            <div class="grid one">
              <div class="field"><label for="storage-name">Storage Name</label><input id="storage-name" name="name" type="text" required /></div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Storage</button>
              <span id="storage-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Storages</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Storage Name</th><th>Action</th></tr></thead>
              <tbody id="storages-body"></tbody>
            </table>
          </div>
        </section>
      </section>

      <section class="pane" data-pane="ancestors-pane">
        <section class="card">
          <h2>Add Ancestor Property</h2>
          <form id="add-ancestor-form">
            <div class="grid one">
              <div class="field"><label for="ancestor-name">Ancestor Property Name</label><input id="ancestor-name" name="name" type="text" required /></div>
            </div>
            <div class="actions">
              <button class="btn primary" type="submit">Add Ancestor Property</button>
              <span id="ancestor-status" class="status" aria-live="polite"></span>
            </div>
          </form>
        </section>
        <section class="card">
          <h2>Ancestor Properties</h2>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Ancestor Property Name</th><th>Action</th></tr></thead>
              <tbody id="ancestors-body"></tbody>
            </table>
          </div>
        </section>
      </section>
    </main>
  </div>

  <script>
    const state = {
      storages: <?= json_encode($storages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      ancestors: <?= json_encode($ancestors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      properties: <?= json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };

    const paneButtons = [...document.querySelectorAll("[data-pane-target]")];
    const panes = [...document.querySelectorAll("[data-pane]")];
    const propertyForm = document.getElementById("add-property-form");
    const storageForm = document.getElementById("add-storage-form");
    const ancestorForm = document.getElementById("add-ancestor-form");
    const propertyStatus = document.getElementById("property-status");
    const storageStatus = document.getElementById("storage-status");
    const ancestorStatus = document.getElementById("ancestor-status");
    const propertyAncestorSelect = document.getElementById("property-ancestor");
    const propertyStorageSelect = document.getElementById("property-storage");
    const propertiesBody = document.getElementById("properties-body");
    const storagesBody = document.getElementById("storages-body");
    const ancestorsBody = document.getElementById("ancestors-body");

    function setStatus(el, msg, isError = false) {
      el.textContent = msg || "";
      el.classList.toggle("error", !!isError);
    }

    function setPane(paneId) {
      paneButtons.forEach((b) => b.classList.toggle("active", b.dataset.paneTarget === paneId));
      panes.forEach((p) => p.classList.toggle("active", p.dataset.pane === paneId));
    }

    function fillStorageOptions(select, selected = "") {
      if (!select) return;
      const value = String(selected || "");
      select.innerHTML = "";
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = state.storages.length ? "Select storage" : "No storage available";
      select.appendChild(empty);
      state.storages.forEach((s) => {
        const o = document.createElement("option");
        o.value = String(s.id || "");
        o.textContent = String(s.name || "");
        if (o.value === value) o.selected = true;
        select.appendChild(o);
      });
      select.disabled = state.storages.length === 0;
    }

    function fillAncestorOptions(select, selected = "") {
      if (!select) return;
      const value = String(selected || "");
      select.innerHTML = "";
      const empty = document.createElement("option");
      empty.value = "";
      empty.textContent = state.ancestors.length ? "Select ancestor property" : "No ancestor property available";
      select.appendChild(empty);
      state.ancestors.forEach((a) => {
        const o = document.createElement("option");
        o.value = String(a.id || "");
        o.textContent = String(a.name || "");
        if (o.value === value) o.selected = true;
        select.appendChild(o);
      });
      select.disabled = state.ancestors.length === 0;
    }

    function renderProperties() {
      propertiesBody.innerHTML = "";
      if (!state.properties.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="4">No properties added yet.</td>';
        propertiesBody.appendChild(tr);
        return;
      }
      state.properties.forEach((p) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(p.id || "");
        const ancestorCell = document.createElement("td");
        const ancestorSelect = document.createElement("select");
        ancestorSelect.dataset.field = "ancestor_id";
        fillAncestorOptions(ancestorSelect, String(p.ancestor_id || ""));
        ancestorCell.appendChild(ancestorSelect);

        const codeCell = document.createElement("td");
        const codeInput = document.createElement("input");
        codeInput.type = "text";
        codeInput.dataset.field = "code";
        codeInput.value = String(p.code || "");
        codeCell.appendChild(codeInput);

        const storageCell = document.createElement("td");
        const storageSelect = document.createElement("select");
        storageSelect.dataset.field = "storage_id";
        fillStorageOptions(storageSelect, String(p.storage_id || ""));
        storageCell.appendChild(storageSelect);

        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-property";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);

        tr.append(ancestorCell, codeCell, storageCell, actionCell);
        propertiesBody.appendChild(tr);
      });
    }

    function renderStorages() {
      storagesBody.innerHTML = "";
      if (!state.storages.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="2">No storages added yet.</td>';
        storagesBody.appendChild(tr);
        return;
      }
      state.storages.forEach((s) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(s.id || "");
        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.dataset.field = "name";
        nameInput.value = String(s.name || "");
        nameCell.appendChild(nameInput);
        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-storage";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);
        tr.append(nameCell, actionCell);
        storagesBody.appendChild(tr);
      });
    }

    function renderAncestors() {
      ancestorsBody.innerHTML = "";
      if (!state.ancestors.length) {
        const tr = document.createElement("tr");
        tr.innerHTML = '<td class="empty" colspan="2">No ancestor properties added yet.</td>';
        ancestorsBody.appendChild(tr);
        return;
      }
      state.ancestors.forEach((a) => {
        const tr = document.createElement("tr");
        tr.dataset.id = String(a.id || "");
        const nameCell = document.createElement("td");
        const nameInput = document.createElement("input");
        nameInput.type = "text";
        nameInput.dataset.field = "name";
        nameInput.value = String(a.name || "");
        nameCell.appendChild(nameInput);
        const actionCell = document.createElement("td");
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "btn danger";
        remove.dataset.action = "remove-ancestor";
        remove.textContent = "Remove";
        actionCell.appendChild(remove);
        tr.append(nameCell, actionCell);
        ancestorsBody.appendChild(tr);
      });
    }

    function renderAll() {
      fillAncestorOptions(propertyAncestorSelect, propertyAncestorSelect.value);
      fillStorageOptions(propertyStorageSelect, propertyStorageSelect.value);
      renderProperties();
      renderStorages();
      renderAncestors();
    }

    async function action(name, payload = {}) {
      const fd = new FormData();
      fd.append("action", name);
      Object.entries(payload).forEach(([k, v]) => fd.append(k, String(v ?? "")));
      const res = await fetch("index.php", { method: "POST", body: fd });
      const data = await res.json();
      if (!res.ok || data.status !== "ok") throw new Error(data.message || "Request failed.");
      state.storages = Array.isArray(data.storages) ? data.storages : [];
      state.ancestors = Array.isArray(data.ancestors) ? data.ancestors : [];
      state.properties = Array.isArray(data.properties) ? data.properties : [];
      renderAll();
      return data;
    }

    paneButtons.forEach((btn) => btn.addEventListener("click", () => setPane(btn.dataset.paneTarget)));

    storageForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const name = storageForm.elements.name.value.trim();
      if (!name) return setStatus(storageStatus, "Storage name is required.", true);
      setStatus(storageStatus, "Saving...");
      try {
        await action("add_storage", { name });
        storageForm.reset();
        setStatus(storageStatus, "Storage added.");
      } catch (err) {
        setStatus(storageStatus, err.message || "Unable to add storage.", true);
      }
    });

    ancestorForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const name = ancestorForm.elements.name.value.trim();
      if (!name) return setStatus(ancestorStatus, "Ancestor property name is required.", true);
      setStatus(ancestorStatus, "Saving...");
      try {
        await action("add_ancestor", { name });
        ancestorForm.reset();
        setStatus(ancestorStatus, "Ancestor property added.");
      } catch (err) {
        setStatus(ancestorStatus, err.message || "Unable to add ancestor property.", true);
      }
    });

    propertyForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const ancestor_id = String(propertyForm.elements.ancestor_id.value || "").trim();
      const code = propertyForm.elements.code.value.trim();
      const storage_id = String(propertyForm.elements.storage_id.value || "").trim();
      if (!ancestor_id || !code || !storage_id) return setStatus(propertyStatus, "All fields are required.", true);
      setStatus(propertyStatus, "Saving...");
      try {
        await action("add_property", { ancestor_id, code, storage_id });
        propertyForm.reset();
        fillAncestorOptions(propertyAncestorSelect, "");
        fillStorageOptions(propertyStorageSelect, "");
        setStatus(propertyStatus, "Property added.");
      } catch (err) {
        setStatus(propertyStatus, err.message || "Unable to add property.", true);
      }
    });

    propertiesBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement || t instanceof HTMLSelectElement)) return;
      const field = String(t.dataset.field || "");
      if (!field) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(t.value || "").trim();
      if (!id || !value) return renderProperties();
      t.disabled = true;
      try {
        await action("update_property", { id, field, value });
      } catch (err) {
        renderProperties();
        alert(err.message || "Unable to save property changes.");
      } finally {
        t.disabled = false;
      }
    });

    storagesBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement)) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(t.value || "").trim();
      if (!id || !value) return renderStorages();
      t.disabled = true;
      try {
        await action("update_storage", { id, value });
      } catch (err) {
        renderStorages();
        alert(err.message || "Unable to save storage changes.");
      } finally {
        t.disabled = false;
      }
    });

    ancestorsBody.addEventListener("change", async (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement)) return;
      const row = t.closest("tr");
      const id = String(row?.dataset.id || "");
      const value = String(t.value || "").trim();
      if (!id || !value) return renderAncestors();
      t.disabled = true;
      try {
        await action("update_ancestor", { id, value });
      } catch (err) {
        renderAncestors();
        alert(err.message || "Unable to save ancestor property changes.");
      } finally {
        t.disabled = false;
      }
    });

    propertiesBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-property"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this property?")) return;
      btn.disabled = true;
      try { await action("remove_property", { id }); }
      catch (err) { alert(err.message || "Unable to remove property."); }
      finally { btn.disabled = false; }
    });

    storagesBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-storage"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this storage?")) return;
      btn.disabled = true;
      try { await action("remove_storage", { id }); }
      catch (err) { alert(err.message || "Unable to remove storage."); }
      finally { btn.disabled = false; }
    });

    ancestorsBody.addEventListener("click", async (e) => {
      const btn = e.target instanceof HTMLElement ? e.target.closest('[data-action="remove-ancestor"]') : null;
      if (!(btn instanceof HTMLButtonElement)) return;
      const id = String(btn.closest("tr")?.dataset.id || "");
      if (!id || !confirm("Remove this ancestor property?")) return;
      btn.disabled = true;
      try { await action("remove_ancestor", { id }); }
      catch (err) { alert(err.message || "Unable to remove ancestor property."); }
      finally { btn.disabled = false; }
    });

    renderAll();
    setPane("properties-pane");
  </script>
</body>
</html>
