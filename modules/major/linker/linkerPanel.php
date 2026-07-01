<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../api/lib/tab-permissions.php';
require_once __DIR__ . '/../../../api/lib/campaign-redirects.php';
requireTabPermissionFromSession('linker-service', false);

function linkerPanelJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function linkerPanelReadRequestPayload(): array
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

function linkerPanelRedirectsForResponse(): array
{
    return array_map(static function (array $redirect): array {
        $path = (string)($redirect['path'] ?? '');
        return [
            'path' => $path,
            'campaign_url' => '/campaigns/' . $path,
            'target' => (string)($redirect['target'] ?? ''),
            'redirect_type' => (string)($redirect['redirect_type'] ?? campaignRedirectsTypeForTarget((string)($redirect['target'] ?? ''))),
            'redirect_type_label' => campaignRedirectsTypeLabel((string)($redirect['redirect_type'] ?? campaignRedirectsTypeForTarget((string)($redirect['target'] ?? '')))),
            'status_code' => (int)($redirect['status_code'] ?? 302),
            'created_at' => (string)($redirect['created_at'] ?? ''),
            'updated_at' => (string)($redirect['updated_at'] ?? '')
        ];
    }, campaignRedirectsList());
}

function linkerPanelHandleSave(array $payload): void
{
    $originalPath = campaignRedirectsNormalizePath($payload['original_path'] ?? '');
    $path = campaignRedirectsNormalizePath($payload['path'] ?? '');
    $target = campaignRedirectsNormalizeTarget($payload['target'] ?? '');
    $statusCode = (int)($payload['status_code'] ?? 302);

    $pathError = campaignRedirectsPathError($path);
    if ($pathError !== '') {
        linkerPanelJsonResponse(['status' => 'error', 'message' => $pathError], 422);
    }
    $targetError = campaignRedirectsTargetError($target);
    if ($targetError !== '') {
        linkerPanelJsonResponse(['status' => 'error', 'message' => $targetError], 422);
    }
    $statusError = campaignRedirectsStatusCodeError($statusCode);
    if ($statusError !== '') {
        linkerPanelJsonResponse(['status' => 'error', 'message' => $statusError], 422);
    }

    $redirects = campaignRedirectsList();
    $map = [];
    foreach ($redirects as $redirect) {
        $redirectPath = (string)($redirect['path'] ?? '');
        if ($redirectPath !== '') {
            $map[$redirectPath] = $redirect;
        }
    }

    $isEdit = $originalPath !== '';
    if ($isEdit && campaignRedirectsPathError($originalPath) !== '') {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'Original campaign path is invalid.'], 422);
    }
    if (!$isEdit && isset($map[$path])) {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'This campaign path already exists.'], 409);
    }
    if ($isEdit && $originalPath !== $path && isset($map[$path])) {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'Another redirect already uses this campaign path.'], 409);
    }

    $now = gmdate('c');
    $existing = $isEdit ? ($map[$originalPath] ?? []) : [];
    if ($isEdit && $originalPath !== $path) {
        unset($map[$originalPath]);
    }
    $map[$path] = [
        'path' => $path,
        'target' => $target,
        'status_code' => campaignRedirectsNormalizeStatusCode($statusCode),
        'created_at' => campaignRedirectsCleanTimestamp($existing['created_at'] ?? '') ?: $now,
        'updated_at' => $now
    ];

    if (!campaignRedirectsSaveList(array_values($map))) {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'Failed to save campaign redirects.'], 500);
    }
    linkerPanelJsonResponse([
        'status' => 'ok',
        'message' => 'Campaign redirect saved.',
        'redirects' => linkerPanelRedirectsForResponse()
    ]);
}

function linkerPanelHandleDelete(array $payload): void
{
    $path = campaignRedirectsNormalizePath($payload['path'] ?? '');
    $pathError = campaignRedirectsPathError($path);
    if ($pathError !== '') {
        linkerPanelJsonResponse(['status' => 'error', 'message' => $pathError], 422);
    }

    $redirects = campaignRedirectsList();
    $nextRedirects = array_values(array_filter($redirects, static function (array $redirect) use ($path): bool {
        return (string)($redirect['path'] ?? '') !== $path;
    }));
    if (count($nextRedirects) === count($redirects)) {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'Campaign redirect was not found.'], 404);
    }
    if (!campaignRedirectsSaveList($nextRedirects)) {
        linkerPanelJsonResponse(['status' => 'error', 'message' => 'Failed to delete campaign redirect.'], 500);
    }
    linkerPanelJsonResponse([
        'status' => 'ok',
        'message' => 'Campaign redirect deleted.',
        'redirects' => linkerPanelRedirectsForResponse()
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = linkerPanelReadRequestPayload();
    $action = trim((string)($payload['action'] ?? ''));
    if ($action === 'list') {
        linkerPanelJsonResponse(['status' => 'ok', 'redirects' => linkerPanelRedirectsForResponse()]);
    }
    if ($action === 'save') {
        linkerPanelHandleSave($payload);
    }
    if ($action === 'delete') {
        linkerPanelHandleDelete($payload);
    }
    linkerPanelJsonResponse(['status' => 'error', 'message' => 'Unknown Linker Service action.'], 400);
}

$initialRedirects = linkerPanelRedirectsForResponse();
?>

<section id="tab-linker-service" class="tab">
  <div class="card settings-section">
    <div class="section-header">
      <h3>Linker Service</h3>
    </div>
    <form id="linker-redirect-form" class="form" autocomplete="off">
      <input type="hidden" id="linker-original-path" value="" />
      <div class="grid">
        <label class="field">
          <span>Campaign path</span>
          <div class="input-prefix-row">
            <span class="input-prefix">/campaigns/</span>
            <input id="linker-path" type="text" placeholder="campaign-name" required />
          </div>
        </label>
        <label class="field">
          <span>Redirect target</span>
          <input id="linker-target" type="text" placeholder="/mini%20apps/Task%20Club/index.php" required />
          <small class="hint">Task Club and generated missions are automatically classified as Logger Redirect.</small>
        </label>
        <label class="field standard-width">
          <span>Status</span>
          <select id="linker-status">
            <option value="302">302 Temporary</option>
            <option value="301">301 Permanent</option>
            <option value="307">307 Temporary</option>
            <option value="308">308 Permanent</option>
          </select>
        </label>
      </div>
      <div class="section-footer">
        <button type="button" class="btn ghost" id="linker-reset">Clear</button>
        <button type="submit" class="btn primary" id="linker-save">Save redirect</button>
      </div>
      <p id="linker-status-message" class="hint" aria-live="polite"></p>
    </form>
  </div>

  <div class="card settings-section">
    <div class="section-header">
      <h3>Campaign redirects</h3>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Campaign URL</th>
            <th>Type</th>
            <th>Target</th>
            <th>Status</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="linker-redirects-body"></tbody>
      </table>
    </div>
  </div>

  <style>
    #tab-linker-service .input-prefix-row {
      display: flex;
      align-items: stretch;
      gap: 0;
      width: 100%;
    }
    #tab-linker-service .input-prefix {
      display: inline-flex;
      align-items: center;
      padding: 0 12px;
      border: 1px solid var(--border);
      border-inline-end: 0;
      border-radius: 8px 0 0 8px;
      background: #f8fafc;
      color: var(--muted);
      direction: ltr;
      white-space: nowrap;
    }
    #tab-linker-service .input-prefix-row input {
      border-radius: 0 8px 8px 0;
      direction: ltr;
    }
    #tab-linker-service td {
      direction: ltr;
    }
    #tab-linker-service .linker-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    #tab-linker-service .linker-empty {
      text-align: center;
      color: var(--muted);
    }
    #tab-linker-service .linker-type-badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 3px 9px;
      border-radius: 999px;
      border: 1px solid #dbe5f2;
      background: #f8fafc;
      color: #475569;
      font-size: 12px;
      font-weight: 600;
      white-space: nowrap;
    }
    #tab-linker-service .linker-type-badge--logger {
      border-color: #b7e3cb;
      background: #ecfdf3;
      color: #166534;
    }
  </style>

  <script>
    (() => {
      const root = document.currentScript.closest("#tab-linker-service") || document.getElementById("tab-linker-service");
      if (!root) return;

      const endpoint = "modules/major/linker/linkerPanel.php";
      let redirects = <?= json_encode($initialRedirects, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;

      const form = root.querySelector("#linker-redirect-form");
      const originalInput = root.querySelector("#linker-original-path");
      const pathInput = root.querySelector("#linker-path");
      const targetInput = root.querySelector("#linker-target");
      const statusInput = root.querySelector("#linker-status");
      const resetButton = root.querySelector("#linker-reset");
      const saveButton = root.querySelector("#linker-save");
      const statusMessage = root.querySelector("#linker-status-message");
      const body = root.querySelector("#linker-redirects-body");

      const setStatus = (message, isError = false) => {
        if (!statusMessage) return;
        statusMessage.textContent = message || "";
        statusMessage.style.color = isError ? "#b91c1c" : "";
      };

      const escapeText = (value) => String(value ?? "");

      const normalizePath = (value) => String(value ?? "")
        .trim()
        .replace(/\\/g, "/")
        .replace(/^https?:\/\/[^/]+\/campaigns\//i, "")
        .replace(/^\/?campaigns\//i, "")
        .replace(/^\/+|\/+$/g, "")
        .replace(/\/+/g, "/");

      const clearForm = () => {
        if (originalInput) originalInput.value = "";
        if (pathInput) pathInput.value = "";
        if (targetInput) targetInput.value = "";
        if (statusInput) statusInput.value = "302";
        if (saveButton) saveButton.textContent = "Save redirect";
        setStatus("");
      };

      const setBusy = (busy) => {
        [pathInput, targetInput, statusInput, resetButton, saveButton].forEach((node) => {
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

      const render = () => {
        if (!body) return;
        body.replaceChildren();
        if (!Array.isArray(redirects) || redirects.length === 0) {
          const row = document.createElement("tr");
          const cell = document.createElement("td");
          cell.className = "linker-empty";
          cell.colSpan = 6;
          cell.textContent = "No campaign redirects yet.";
          row.appendChild(cell);
          body.appendChild(row);
          return;
        }

        redirects.forEach((redirect) => {
          const row = document.createElement("tr");
          const campaignCell = document.createElement("td");
          const campaignLink = document.createElement("a");
          campaignLink.href = `campaigns/${encodeURI(redirect.path || "")}`;
          campaignLink.target = "_blank";
          campaignLink.rel = "noopener";
          campaignLink.textContent = redirect.campaign_url || `/campaigns/${redirect.path || ""}`;
          campaignCell.appendChild(campaignLink);

          const typeCell = document.createElement("td");
          const typeBadge = document.createElement("span");
          const redirectType = String(redirect.redirect_type || "normal");
          typeBadge.className = `linker-type-badge${redirectType === "logger" ? " linker-type-badge--logger" : ""}`;
          typeBadge.textContent = redirect.redirect_type_label || (redirectType === "logger" ? "Logger Redirect" : "Normal Redirect");
          typeCell.appendChild(typeBadge);

          const targetCell = document.createElement("td");
          targetCell.textContent = escapeText(redirect.target);

          const statusCell = document.createElement("td");
          statusCell.textContent = escapeText(redirect.status_code || 302);

          const updatedCell = document.createElement("td");
          updatedCell.textContent = escapeText(redirect.updated_at || redirect.created_at || "-");

          const actionsCell = document.createElement("td");
          const actions = document.createElement("div");
          actions.className = "linker-actions";

          const editButton = document.createElement("button");
          editButton.type = "button";
          editButton.className = "btn ghost";
          editButton.textContent = "Edit";
          editButton.addEventListener("click", () => {
            if (originalInput) originalInput.value = redirect.path || "";
            if (pathInput) pathInput.value = redirect.path || "";
            if (targetInput) targetInput.value = redirect.target || "";
            if (statusInput) statusInput.value = String(redirect.status_code || 302);
            if (saveButton) saveButton.textContent = "Update redirect";
            setStatus(`Editing /campaigns/${redirect.path || ""}`);
            pathInput?.focus();
          });

          const deleteButton = document.createElement("button");
          deleteButton.type = "button";
          deleteButton.className = "btn ghost";
          deleteButton.textContent = "Delete";
          deleteButton.addEventListener("click", async () => {
            if (!confirm(`Delete /campaigns/${redirect.path || ""}?`)) {
              return;
            }
            setBusy(true);
            try {
              const data = await request({ action: "delete", path: redirect.path || "" });
              redirects = Array.isArray(data.redirects) ? data.redirects : [];
              render();
              clearForm();
              setStatus(data.message || "Campaign redirect deleted.");
            } catch (error) {
              setStatus(error?.message || "Failed to delete campaign redirect.", true);
            } finally {
              setBusy(false);
            }
          });

          actions.append(editButton, deleteButton);
          actionsCell.appendChild(actions);
          row.append(campaignCell, typeCell, targetCell, statusCell, updatedCell, actionsCell);
          body.appendChild(row);
        });
      };

      form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        setBusy(true);
        setStatus("Saving redirect...");
        try {
          const data = await request({
            action: "save",
            original_path: normalizePath(originalInput?.value || ""),
            path: normalizePath(pathInput?.value || ""),
            target: targetInput?.value || "",
            status_code: statusInput?.value || "302"
          });
          redirects = Array.isArray(data.redirects) ? data.redirects : [];
          render();
          clearForm();
          setStatus(data.message || "Campaign redirect saved.");
        } catch (error) {
          setStatus(error?.message || "Failed to save campaign redirect.", true);
        } finally {
          setBusy(false);
        }
      });

      resetButton?.addEventListener("click", clearForm);
      render();
    })();
  </script>
</section>
