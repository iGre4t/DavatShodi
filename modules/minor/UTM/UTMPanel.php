<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/utm-branches.php';
requireTabPermissionFromSession('utm-service', false);

function utmPanelJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function utmPanelReadRequestPayload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function utmPanelLoggerRedirectsForResponse(): array
{
    return array_values(array_map(static function (array $redirect): array {
        $path = (string)($redirect['path'] ?? '');
        return [
            'path' => $path,
            'campaign_url' => '/campaigns/' . $path,
            'target' => (string)($redirect['target'] ?? ''),
            'status_code' => (int)($redirect['status_code'] ?? 302),
            'updated_at' => (string)($redirect['updated_at'] ?? ''),
            'created_at' => (string)($redirect['created_at'] ?? '')
        ];
    }, utmBranchesLoggerRedirectMap()));
}

function utmPanelBranchesForResponse(): array
{
    $branches = utmBranchesList();
    $loggerRedirects = utmBranchesLoggerRedirectMap();
    $summaries = utmBranchesSummaries($branches);
    return array_values(array_map(static function (array $branch) use ($loggerRedirects, $summaries): array {
        $parentPath = (string)($branch['parent_path'] ?? '');
        $branchPath = (string)($branch['branch'] ?? '');
        $redirect = $loggerRedirects[$parentPath] ?? null;
        $summary = $summaries[utmBranchesKey($parentPath, $branchPath)] ?? [];
        return [
            'parent_path' => $parentPath,
            'branch' => $branchPath,
            'label' => (string)($branch['label'] ?? ''),
            'notes' => (string)($branch['notes'] ?? ''),
            'active' => !empty($branch['active']),
            'parent_exists' => is_array($redirect),
            'campaign_url' => '/campaigns/' . $parentPath,
            'branch_url' => utmBranchesBranchUrl($parentPath, $branchPath),
            'target' => is_array($redirect) ? (string)($redirect['target'] ?? '') : '',
            'status_code' => is_array($redirect) ? (int)($redirect['status_code'] ?? 302) : 302,
            'entries' => (int)($summary['entries'] ?? 0),
            'unique_visitors' => (int)($summary['unique_visitors'] ?? 0),
            'unique_sessions' => (int)($summary['unique_sessions'] ?? 0),
            'last_visit_at' => (string)($summary['last_visit_at'] ?? ''),
            'last_ip_address' => (string)($summary['last_ip_address'] ?? ''),
            'created_at' => (string)($branch['created_at'] ?? ''),
            'updated_at' => (string)($branch['updated_at'] ?? '')
        ];
    }, $branches));
}

function utmPanelRecentVisitsForResponse(): array
{
    return array_values(array_map(static function (array $entry): array {
        return [
            'timestamp' => (string)($entry['timestamp'] ?? ''),
            'parent_path' => (string)($entry['parent_path'] ?? ''),
            'branch' => (string)($entry['branch'] ?? ''),
            'branch_url' => (string)($entry['branch_url'] ?? ''),
            'target' => (string)($entry['target'] ?? ''),
            'ip_address' => (string)($entry['ip_address'] ?? ''),
            'visitor_id' => (string)($entry['visitor_id'] ?? ''),
            'session_id' => (string)($entry['session_id'] ?? ''),
            'referrer' => (string)($entry['referrer'] ?? ''),
            'user_agent' => (string)($entry['user_agent'] ?? ''),
            'query_string' => (string)($entry['query_string'] ?? '')
        ];
    }, utmBranchesReadVisitLogs(120)));
}

function utmPanelStateForResponse(): array
{
    $loggerRedirects = utmPanelLoggerRedirectsForResponse();
    $branches = utmPanelBranchesForResponse();
    $recentVisits = utmPanelRecentVisitsForResponse();
    $entryCount = 0;
    $sessionCount = 0;
    foreach ($branches as $branch) {
        $entryCount += (int)($branch['entries'] ?? 0);
        $sessionCount += (int)($branch['unique_sessions'] ?? 0);
    }
    return [
        'logger_redirects' => $loggerRedirects,
        'branches' => $branches,
        'recent_visits' => $recentVisits,
        'totals' => [
            'logger_redirects' => count($loggerRedirects),
            'branches' => count($branches),
            'entries' => $entryCount,
            'unique_sessions' => $sessionCount
        ]
    ];
}

function utmPanelHandleSave(array $payload): void
{
    $parentPath = campaignRedirectsNormalizePath($payload['parent_path'] ?? '');
    $branchPath = utmBranchesNormalizeBranch($payload['branch'] ?? '');
    $originalParentPath = campaignRedirectsNormalizePath($payload['original_parent_path'] ?? '');
    $originalBranchPath = utmBranchesNormalizeBranch($payload['original_branch'] ?? '');

    $parentError = campaignRedirectsPathError($parentPath);
    if ($parentError !== '') {
        utmPanelJsonResponse(['status' => 'error', 'message' => $parentError], 422);
    }
    $branchError = utmBranchesBranchError($branchPath);
    if ($branchError !== '') {
        utmPanelJsonResponse(['status' => 'error', 'message' => $branchError], 422);
    }
    $loggerRedirects = utmBranchesLoggerRedirectMap();
    if (!isset($loggerRedirects[$parentPath])) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'UTM branches can only be created from Logger redirects in Linker Service.'], 422);
    }

    $branches = utmBranchesList();
    $map = [];
    foreach ($branches as $branch) {
        $map[utmBranchesKey((string)$branch['parent_path'], (string)$branch['branch'])] = $branch;
    }

    $isEdit = $originalParentPath !== '' && $originalBranchPath !== '';
    $nextKey = utmBranchesKey($parentPath, $branchPath);
    $originalKey = $isEdit ? utmBranchesKey($originalParentPath, $originalBranchPath) : '';
    if (!$isEdit && isset($map[$nextKey])) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'This UTM branch already exists for the selected Logger redirect.'], 409);
    }
    if ($isEdit && !isset($map[$originalKey])) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'Original UTM branch was not found.'], 404);
    }
    if ($isEdit && $originalKey !== $nextKey && isset($map[$nextKey])) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'Another UTM branch already uses this path.'], 409);
    }

    $now = gmdate('c');
    $existing = $isEdit ? ($map[$originalKey] ?? []) : [];
    if ($isEdit && $originalKey !== $nextKey) {
        unset($map[$originalKey]);
    }
    $map[$nextKey] = [
        'parent_path' => $parentPath,
        'branch' => $branchPath,
        'label' => utmBranchesNormalizeText($payload['label'] ?? '', 120),
        'notes' => utmBranchesNormalizeText($payload['notes'] ?? '', 500),
        'active' => array_key_exists('active', $payload) ? utmBranchesNormalizeBoolean($payload['active']) : true,
        'created_at' => campaignRedirectsCleanTimestamp($existing['created_at'] ?? '') ?: $now,
        'updated_at' => $now
    ];

    if (!utmBranchesSaveList(array_values($map))) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'Failed to save UTM branches.'], 500);
    }
    utmPanelJsonResponse([
        'status' => 'ok',
        'message' => 'UTM branch saved.',
        'state' => utmPanelStateForResponse()
    ]);
}

function utmPanelHandleDelete(array $payload): void
{
    $parentPath = campaignRedirectsNormalizePath($payload['parent_path'] ?? '');
    $branchPath = utmBranchesNormalizeBranch($payload['branch'] ?? '');
    $branchError = utmBranchesBranchError($branchPath);
    if (campaignRedirectsPathError($parentPath) !== '' || $branchError !== '') {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'UTM branch path is invalid.'], 422);
    }

    $key = utmBranchesKey($parentPath, $branchPath);
    $branches = utmBranchesList();
    $nextBranches = array_values(array_filter($branches, static function (array $branch) use ($key): bool {
        return utmBranchesKey((string)$branch['parent_path'], (string)$branch['branch']) !== $key;
    }));
    if (count($nextBranches) === count($branches)) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'UTM branch was not found.'], 404);
    }
    if (!utmBranchesSaveList($nextBranches)) {
        utmPanelJsonResponse(['status' => 'error', 'message' => 'Failed to delete UTM branch.'], 500);
    }
    utmPanelJsonResponse([
        'status' => 'ok',
        'message' => 'UTM branch deleted.',
        'state' => utmPanelStateForResponse()
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = utmPanelReadRequestPayload();
    $action = trim((string)($payload['action'] ?? ''));
    if ($action === 'list') {
        utmPanelJsonResponse(['status' => 'ok', 'state' => utmPanelStateForResponse()]);
    }
    if ($action === 'save') {
        utmPanelHandleSave($payload);
    }
    if ($action === 'delete') {
        utmPanelHandleDelete($payload);
    }
    utmPanelJsonResponse(['status' => 'error', 'message' => 'Unknown UTM Service action.'], 400);
}

$initialState = utmPanelStateForResponse();
?>

<section id="tab-utm-service" class="tab">
  <div class="utm-shell">
    <div class="utm-metrics">
      <div class="card utm-metric">
        <span>Logger redirects</span>
        <strong data-utm-total="logger_redirects">0</strong>
      </div>
      <div class="card utm-metric">
        <span>UTM branches</span>
        <strong data-utm-total="branches">0</strong>
      </div>
      <div class="card utm-metric">
        <span>Tracked entries</span>
        <strong data-utm-total="entries">0</strong>
      </div>
      <div class="card utm-metric">
        <span>Sessions</span>
        <strong data-utm-total="unique_sessions">0</strong>
      </div>
    </div>

    <div class="card settings-section">
      <div class="section-header">
        <h3>UTM Service</h3>
      </div>
      <form id="utm-branch-form" class="form" autocomplete="off">
        <input type="hidden" id="utm-original-parent" value="" />
        <input type="hidden" id="utm-original-branch" value="" />
        <div class="grid">
          <label class="field">
            <span>Logger redirect</span>
            <select id="utm-parent-path" required></select>
            <small class="hint">Only Logger redirects created in Linker Service are available.</small>
          </label>
          <label class="field">
            <span>Branch path</span>
            <input id="utm-branch-path" type="text" placeholder="SMS" required />
            <small class="hint" id="utm-preview">/campaigns/{logger}/SMS</small>
          </label>
          <label class="field">
            <span>Label</span>
            <input id="utm-label" type="text" placeholder="SMS campaign" />
          </label>
          <label class="field">
            <span>Notes</span>
            <input id="utm-notes" type="text" placeholder="Optional context for this branch" />
          </label>
          <label class="field utm-toggle-field">
            <span>Branch status</span>
            <label class="utm-switch-row">
              <input id="utm-active" type="checkbox" checked />
              <span>Active</span>
            </label>
          </label>
        </div>
        <div class="section-footer">
          <button type="button" class="btn ghost" id="utm-reset">Clear</button>
          <button type="submit" class="btn primary" id="utm-save">Save branch</button>
        </div>
        <p id="utm-status-message" class="hint" aria-live="polite"></p>
      </form>
    </div>

    <div class="card settings-section">
      <div class="section-header">
        <h3>Branch redirects</h3>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Branch URL</th>
              <th>Logger source</th>
              <th>Target</th>
              <th>Entries</th>
              <th>Last entry</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="utm-branches-body"></tbody>
        </table>
      </div>
    </div>

    <div class="card settings-section">
      <div class="section-header">
        <h3>Recent entries</h3>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Branch</th>
              <th>IP address</th>
              <th>Session</th>
              <th>Referrer</th>
            </tr>
          </thead>
          <tbody id="utm-visits-body"></tbody>
        </table>
      </div>
    </div>
  </div>

  <style>
    #tab-utm-service .utm-shell {
      display: grid;
      gap: 18px;
    }
    #tab-utm-service .utm-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }
    #tab-utm-service .utm-metric {
      display: grid;
      gap: 6px;
      min-height: 88px;
      align-content: center;
    }
    #tab-utm-service .utm-metric span {
      color: var(--muted);
      font-size: 13px;
    }
    #tab-utm-service .utm-metric strong {
      font-size: 28px;
      line-height: 1;
    }
    #tab-utm-service td {
      direction: ltr;
      vertical-align: top;
    }
    #tab-utm-service .utm-toggle-field {
      justify-content: end;
    }
    #tab-utm-service .utm-switch-row {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 42px;
    }
    #tab-utm-service .utm-url-cell {
      display: grid;
      gap: 4px;
    }
    #tab-utm-service .utm-url-label {
      color: var(--muted);
      font-size: 12px;
    }
    #tab-utm-service .utm-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    #tab-utm-service .utm-badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 3px 9px;
      border: 1px solid #dbe5f2;
      border-radius: 999px;
      background: #f8fafc;
      color: #475569;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }
    #tab-utm-service .utm-badge--active {
      border-color: #b7e3cb;
      background: #ecfdf3;
      color: #166534;
    }
    #tab-utm-service .utm-badge--missing {
      border-color: #fecaca;
      background: #fef2f2;
      color: #991b1b;
    }
    #tab-utm-service .utm-empty {
      color: var(--muted);
      text-align: center;
    }
    #tab-utm-service .utm-muted {
      color: var(--muted);
    }
    @media (max-width: 980px) {
      #tab-utm-service .utm-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 640px) {
      #tab-utm-service .utm-metrics {
        grid-template-columns: 1fr;
      }
    }
  </style>

  <script>
    (() => {
      const root = document.currentScript.closest("#tab-utm-service") || document.getElementById("tab-utm-service");
      if (!root) return;

      const endpoint = "modules/minor/UTM/UTMPanel.php";
      let state = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

      const form = root.querySelector("#utm-branch-form");
      const originalParentInput = root.querySelector("#utm-original-parent");
      const originalBranchInput = root.querySelector("#utm-original-branch");
      const parentInput = root.querySelector("#utm-parent-path");
      const branchInput = root.querySelector("#utm-branch-path");
      const labelInput = root.querySelector("#utm-label");
      const notesInput = root.querySelector("#utm-notes");
      const activeInput = root.querySelector("#utm-active");
      const preview = root.querySelector("#utm-preview");
      const resetButton = root.querySelector("#utm-reset");
      const saveButton = root.querySelector("#utm-save");
      const statusMessage = root.querySelector("#utm-status-message");
      const branchesBody = root.querySelector("#utm-branches-body");
      const visitsBody = root.querySelector("#utm-visits-body");

      const escapeText = (value) => String(value ?? "");
      const asArray = (value) => Array.isArray(value) ? value : [];
      const metric = (key) => root.querySelector(`[data-utm-total="${key}"]`);

      const setStatus = (message, isError = false) => {
        if (!statusMessage) return;
        statusMessage.textContent = message || "";
        statusMessage.style.color = isError ? "#b91c1c" : "";
      };

      const normalizeBranch = (value) => String(value ?? "")
        .trim()
        .replace(/\\/g, "/")
        .replace(/^\/+|\/+$/g, "")
        .replace(/\s+/g, "-");

      const buildBranchUrl = (parentPath, branchPath) => {
        const parent = String(parentPath || "").replace(/^\/+|\/+$/g, "");
        const branch = encodeURIComponent(normalizeBranch(branchPath));
        return parent && branch ? `/campaigns/${parent}/${branch}` : "/campaigns/{logger}/SMS";
      };

      const currentParent = () => parentInput?.value || asArray(state.logger_redirects)[0]?.path || "";

      const updatePreview = () => {
        if (!preview) return;
        preview.textContent = buildBranchUrl(currentParent(), branchInput?.value || "SMS");
      };

      const setBusy = (busy) => {
        [parentInput, branchInput, labelInput, notesInput, activeInput, resetButton, saveButton].forEach((node) => {
          if (node) node.disabled = busy;
        });
      };

      const request = async (payload) => {
        const response = await fetch(endpoint, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        let data = null;
        try {
          data = await response.json();
        } catch (error) {
          data = null;
        }
        if (!response.ok || !data || data.status !== "ok") {
          throw new Error(data?.message || `Request failed (${response.status}).`);
        }
        return data;
      };

      const syncParents = () => {
        if (!parentInput) return;
        const previous = parentInput.value;
        parentInput.replaceChildren();
        const parents = asArray(state.logger_redirects);
        if (parents.length === 0) {
          const option = document.createElement("option");
          option.value = "";
          option.textContent = "No Logger redirects available";
          parentInput.appendChild(option);
          parentInput.disabled = true;
          [branchInput, labelInput, notesInput, activeInput, saveButton].forEach((node) => {
            if (node) node.disabled = true;
          });
          return;
        }
        parentInput.disabled = false;
        [branchInput, labelInput, notesInput, activeInput, saveButton].forEach((node) => {
          if (node) node.disabled = false;
        });
        parents.forEach((redirect) => {
          const option = document.createElement("option");
          option.value = redirect.path || "";
          option.textContent = `${redirect.campaign_url || `/campaigns/${redirect.path || ""}`} -> ${redirect.target || ""}`;
          parentInput.appendChild(option);
        });
        if (previous && parents.some((redirect) => redirect.path === previous)) {
          parentInput.value = previous;
        }
      };

      const renderTotals = () => {
        const totals = state.totals || {};
        ["logger_redirects", "branches", "entries", "unique_sessions"].forEach((key) => {
          const node = metric(key);
          if (node) node.textContent = String(totals[key] ?? 0);
        });
      };

      const emptyRow = (tbody, colSpan, message) => {
        if (!tbody) return;
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.className = "utm-empty";
        cell.colSpan = colSpan;
        cell.textContent = message;
        row.appendChild(cell);
        tbody.appendChild(row);
      };

      const copyText = async (value) => {
        const text = String(value || "");
        if (!text) return;
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
          return;
        }
        const textarea = document.createElement("textarea");
        textarea.value = text;
        textarea.style.position = "fixed";
        textarea.style.opacity = "0";
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand("copy");
        textarea.remove();
      };

      const editBranch = (branch) => {
        if (originalParentInput) originalParentInput.value = branch.parent_path || "";
        if (originalBranchInput) originalBranchInput.value = branch.branch || "";
        if (parentInput) {
          parentInput.value = branch.parent_path || "";
        }
        if (branchInput) branchInput.value = branch.branch || "";
        if (labelInput) labelInput.value = branch.label || "";
        if (notesInput) notesInput.value = branch.notes || "";
        if (activeInput) activeInput.checked = branch.active !== false;
        if (saveButton) saveButton.textContent = "Update branch";
        setStatus(`Editing ${branch.branch_url || ""}`);
        updatePreview();
        branchInput?.focus();
      };

      const clearForm = () => {
        if (originalParentInput) originalParentInput.value = "";
        if (originalBranchInput) originalBranchInput.value = "";
        if (branchInput) branchInput.value = "";
        if (labelInput) labelInput.value = "";
        if (notesInput) notesInput.value = "";
        if (activeInput) activeInput.checked = true;
        if (saveButton) saveButton.textContent = "Save branch";
        setStatus("");
        updatePreview();
      };

      const renderBranches = () => {
        if (!branchesBody) return;
        branchesBody.replaceChildren();
        const branches = asArray(state.branches);
        if (branches.length === 0) {
          emptyRow(branchesBody, 7, asArray(state.logger_redirects).length === 0
            ? "Create a Logger redirect in Linker Service first."
            : "No UTM branches yet.");
          return;
        }

        branches.forEach((branch) => {
          const row = document.createElement("tr");

          const urlCell = document.createElement("td");
          const urlWrap = document.createElement("div");
          urlWrap.className = "utm-url-cell";
          const urlLink = document.createElement("a");
          urlLink.href = branch.branch_url || "#";
          urlLink.target = "_blank";
          urlLink.rel = "noopener";
          urlLink.textContent = branch.branch_url || "";
          const urlLabel = document.createElement("span");
          urlLabel.className = "utm-url-label";
          urlLabel.textContent = branch.label || branch.notes || "";
          urlWrap.append(urlLink);
          if (urlLabel.textContent) {
            urlWrap.appendChild(urlLabel);
          }
          urlCell.appendChild(urlWrap);

          const sourceCell = document.createElement("td");
          sourceCell.textContent = branch.campaign_url || `/campaigns/${branch.parent_path || ""}`;

          const targetCell = document.createElement("td");
          targetCell.textContent = branch.target || "Logger redirect missing";
          if (!branch.parent_exists) {
            targetCell.classList.add("utm-muted");
          }

          const entriesCell = document.createElement("td");
          entriesCell.textContent = `${branch.entries || 0} entries / ${branch.unique_sessions || 0} sessions`;

          const lastCell = document.createElement("td");
          lastCell.textContent = branch.last_visit_at || "-";

          const statusCell = document.createElement("td");
          const badge = document.createElement("span");
          if (!branch.parent_exists) {
            badge.className = "utm-badge utm-badge--missing";
            badge.textContent = "Missing source";
          } else if (branch.active !== false) {
            badge.className = "utm-badge utm-badge--active";
            badge.textContent = "Active";
          } else {
            badge.className = "utm-badge";
            badge.textContent = "Paused";
          }
          statusCell.appendChild(badge);

          const actionsCell = document.createElement("td");
          const actions = document.createElement("div");
          actions.className = "utm-actions";

          const copyButton = document.createElement("button");
          copyButton.type = "button";
          copyButton.className = "btn ghost";
          copyButton.textContent = "Copy";
          copyButton.addEventListener("click", async () => {
            try {
              await copyText(branch.branch_url || "");
              setStatus("Branch URL copied.");
            } catch (error) {
              setStatus("Failed to copy branch URL.", true);
            }
          });

          const editButton = document.createElement("button");
          editButton.type = "button";
          editButton.className = "btn ghost";
          editButton.textContent = "Edit";
          editButton.addEventListener("click", () => editBranch(branch));

          const deleteButton = document.createElement("button");
          deleteButton.type = "button";
          deleteButton.className = "btn ghost";
          deleteButton.textContent = "Delete";
          deleteButton.addEventListener("click", async () => {
            if (!confirm(`Delete ${branch.branch_url || "this UTM branch"}?`)) {
              return;
            }
            setBusy(true);
            try {
              const data = await request({
                action: "delete",
                parent_path: branch.parent_path || "",
                branch: branch.branch || ""
              });
              state = data.state || state;
              render();
              clearForm();
              setStatus(data.message || "UTM branch deleted.");
            } catch (error) {
              setStatus(error?.message || "Failed to delete UTM branch.", true);
            } finally {
              setBusy(false);
            }
          });

          actions.append(copyButton, editButton, deleteButton);
          actionsCell.appendChild(actions);
          row.append(urlCell, sourceCell, targetCell, entriesCell, lastCell, statusCell, actionsCell);
          branchesBody.appendChild(row);
        });
      };

      const renderVisits = () => {
        if (!visitsBody) return;
        visitsBody.replaceChildren();
        const visits = asArray(state.recent_visits);
        if (visits.length === 0) {
          emptyRow(visitsBody, 5, "No UTM entries tracked yet.");
          return;
        }
        visits.forEach((visit) => {
          const row = document.createElement("tr");
          const dateCell = document.createElement("td");
          dateCell.textContent = visit.timestamp || "-";
          const branchCell = document.createElement("td");
          branchCell.textContent = visit.branch_url || buildBranchUrl(visit.parent_path, visit.branch);
          const ipCell = document.createElement("td");
          ipCell.textContent = visit.ip_address || "-";
          const sessionCell = document.createElement("td");
          sessionCell.textContent = visit.session_id ? `${visit.session_id.slice(0, 10)}...` : "-";
          const referrerCell = document.createElement("td");
          referrerCell.textContent = visit.referrer || "-";
          row.append(dateCell, branchCell, ipCell, sessionCell, referrerCell);
          visitsBody.appendChild(row);
        });
      };

      const render = () => {
        syncParents();
        renderTotals();
        renderBranches();
        renderVisits();
        updatePreview();
      };

      form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        setBusy(true);
        setStatus("Saving UTM branch...");
        try {
          const data = await request({
            action: "save",
            original_parent_path: originalParentInput?.value || "",
            original_branch: originalBranchInput?.value || "",
            parent_path: parentInput?.value || "",
            branch: branchInput?.value || "",
            label: labelInput?.value || "",
            notes: notesInput?.value || "",
            active: Boolean(activeInput?.checked)
          });
          state = data.state || state;
          render();
          clearForm();
          setStatus(data.message || "UTM branch saved.");
        } catch (error) {
          setStatus(error?.message || "Failed to save UTM branch.", true);
        } finally {
          setBusy(false);
        }
      });

      parentInput?.addEventListener("change", updatePreview);
      branchInput?.addEventListener("input", updatePreview);
      resetButton?.addEventListener("click", clearForm);
      render();
    })();
  </script>
</section>
