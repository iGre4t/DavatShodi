(() => {
  const API_URL = "mini%20apps/Task%20Club/tc_store.php";
  const TC_PRIZE_STATUS_INTERVAL_KEY = "__tcPrizeStatusInterval";
  const TC_PRIZE_STATUS_START_KEY = "__tcPrizeStatusStart";
  const TC_PRIZE_STATUS_STOP_KEY = "__tcPrizeStatusStop";
  const TC_REWARDS_ACTIVATION_HANDLER_KEY = "__tcRewardsActivationHandler";
  const TC_REWARDS_VISIBILITY_HANDLER_KEY = "__tcRewardsVisibilityHandler";
  const tcShellEl = document.querySelector(".tc-shell");
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || "").trim()
    : "";
  let prizeInventoryVersion = "";
  let prizeLevelsVersion = "";

  function isRewardsPaneActive() {
    const rewardsPane = document.querySelector('.tc-shell .sub-pane[data-pane="tc-rewards-config"]');
    const taskClubTab = document.getElementById("tab-task-club");
    return rewardsPane instanceof HTMLElement
      && rewardsPane.classList.contains("active")
      && (!(taskClubTab instanceof HTMLElement) || taskClubTab.classList.contains("active"));
  }

  function getActiveRewardSectionKey() {
    const rewardsPane = document.querySelector('.tc-shell .sub-pane[data-pane="tc-rewards-config"]');
    if (!(rewardsPane instanceof HTMLElement)) return "";
    const activeSection = Array.from(rewardsPane.querySelectorAll("[data-tc-reward-config-section]"))
      .find(section => section instanceof HTMLElement && !section.hidden);
    return activeSection instanceof HTMLElement
      ? String(activeSection.getAttribute("data-tc-reward-config-section") || "")
      : "";
  }

  function makePrizeId() {
    const randomPart = globalThis.crypto?.getRandomValues
      ? Array.from(globalThis.crypto.getRandomValues(new Uint32Array(2)), value => value.toString(36)).join("")
      : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
    return `prize_${randomPart}`;
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function parsePrizeValue(rawValue, fallback = 0) {
    const normalized = String(rawValue ?? "")
      .replace(/,/g, "")
      .replace(/\s+/g, "")
      .replace(/[^\d.]/g, "");
    const parsed = Number.parseFloat(normalized);
    if (!Number.isFinite(parsed) || parsed < 0) {
      return fallback;
    }
    return parsed;
  }

  function formatPrizeValue(value) {
    const normalized = parsePrizeValue(value, 0);
    const rounded = Math.round(normalized * 100) / 100;
    const integerPart = Math.trunc(rounded);
    const decimalPart = rounded - integerPart;
    const integerText = integerPart.toLocaleString("en-US");
    if (decimalPart === 0) {
      return integerText;
    }
    const decimalText = String(rounded.toFixed(2)).replace(/^\d+\./, "").replace(/0+$/, "");
    return decimalText ? `${integerText}.${decimalText}` : integerText;
  }

  function normalizeLevelScore(rawValue) {
    const parsed = Number.parseInt(String(rawValue ?? "").trim(), 10);
    if (!Number.isFinite(parsed) || parsed <= 0) {
      return 0;
    }
    return parsed;
  }

  function normalizeLevelName(rawValue, fallback = "") {
    const name = String(rawValue ?? "").trim();
    return name || String(fallback ?? "").trim();
  }

  function normalizeLevelType(rawValue) {
    const token = String(rawValue ?? "").trim().toLowerCase();
    if (token === "out_of_value" || token === "pot") {
      return token;
    }
    return "value_sum";
  }

  function normalizePotSettings(rawValue = {}, fallbackTitle = "") {
    const source = rawValue && typeof rawValue === "object" ? rawValue : {};
    const winnerLimit = Number.parseInt(source.winnerLimit ?? source.winner_limit ?? 1, 10);
    return {
      title: String(source.title ?? fallbackTitle ?? "").trim().slice(0, 160),
      winnerLimit: Math.max(1, Math.min(1000, Number.isFinite(winnerLimit) ? winnerLimit : 1)),
      prizeName: String(source.prizeName ?? source.prize_name ?? "").trim().slice(0, 160),
      locked: Boolean(source.locked)
    };
  }

  function normalizeLevelDescription(rawValue) {
    return String(rawValue ?? "").replace(/\r\n?/g, "\n").trim();
  }

  function normalizeLevelButtonText(rawValue) {
    return String(rawValue ?? "").trim().slice(0, 80);
  }

  function replaceDescriptionRange(textarea, start, end, replacement, selectionStart = null, selectionEnd = null) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const safeStart = Math.max(0, Math.min(value.length, Number.isFinite(start) ? start : 0));
    const safeEnd = Math.max(safeStart, Math.min(value.length, Number.isFinite(end) ? end : safeStart));
    const nextValue = `${value.slice(0, safeStart)}${replacement}${value.slice(safeEnd)}`;
    textarea.value = nextValue;
    const defaultCaret = safeStart + replacement.length;
    textarea.selectionStart = Number.isFinite(selectionStart) ? selectionStart : defaultCaret;
    textarea.selectionEnd = Number.isFinite(selectionEnd) ? selectionEnd : textarea.selectionStart;
    textarea.focus();
    textarea.dispatchEvent(new Event("input", {bubbles: true}));
  }

  function applyDescriptionFormat(textarea, format) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);
    if (format === "bold") {
      const selected = value.slice(start, end);
      const replacement = `<b>${selected}</b>`;
      const contentStart = start + 3;
      replaceDescriptionRange(textarea, start, end, replacement, contentStart, contentStart + selected.length);
      return;
    }
    if (format === "list") {
      const lineStart = value.lastIndexOf("\n", Math.max(0, start - 1)) + 1;
      const lineEndIndex = value.indexOf("\n", end);
      const lineEnd = lineEndIndex >= 0 ? lineEndIndex : value.length;
      const lines = value.slice(lineStart, lineEnd)
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean);
      if (!lines.length) return;
      const list = `<ul>\n${lines.map((line) => `  <li>${line}</li>`).join("\n")}\n</ul>`;
      replaceDescriptionRange(textarea, lineStart, lineEnd, list);
    }
  }

  function makeLevelId() {
    return `lvl_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
  }

  async function loadPrizes() {
    try {
      const response = await fetch(`${API_URL}?action=get_prizes`, { credentials: "same-origin" });
      const payload = await response.json();
      if (payload?.status === "ok" && Array.isArray(payload.data)) {
        prizeInventoryVersion = String(payload.version || "").trim();
        return payload.data
          .map(item => {
            const quantity = Number.parseInt(item?.quantity ?? 0, 10);
            const last = Number.parseInt(item?.last ?? quantity, 10);
            const name = String(item?.name ?? "").trim();
            const onWheelName = String(item?.onWheelName ?? name).trim();
            const value = parsePrizeValue(item?.value ?? 0, 0);
            return {
              id: String(item?.id ?? "").trim() || makePrizeId(),
              name,
              onWheelName,
              quantity,
              last: Number.isFinite(last) ? last : quantity,
              value,
              isFake: Boolean(item?.isFake)
            };
          })
          .filter(item => item.name !== "");
      }
    } catch {}
    return [];
  }

  async function savePrizes(prizes, { confirmEmpty = false } = {}) {
    try {
      const response = await fetch(`${API_URL}?action=save_prizes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          prizes,
          version: prizeInventoryVersion,
          confirmEmptyInventory: confirmEmpty === true,
          csrf: csrfToken
        })
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.status !== "ok") {
        window.alert(payload?.message || "Saving prize inventory failed. No data was overwritten; reload and try again.");
        window.location.reload();
        return false;
      }
      prizeInventoryVersion = String(payload.version || "").trim();
      return true;
    } catch {
      window.alert("Saving prize inventory failed. No data was overwritten; reload and try again.");
      window.location.reload();
      return false;
    }
  }

  async function loadPrizeLevels() {
    try {
      const response = await fetch(`${API_URL}?action=get_prize_levels`, { credentials: "same-origin" });
      const payload = await response.json();
      if (payload?.status === "ok" && Array.isArray(payload.data)) {
        prizeLevelsVersion = String(payload.version || "").trim();
        return payload.data
          .map((item) => {
            const id = String(item?.id ?? "").trim() || makeLevelId();
            const score = normalizeLevelScore(
              item?.score ?? item?.levelScore ?? item?.level_score ?? 0
            );
            const name = normalizeLevelName(
              item?.name ?? item?.levelName ?? item?.level_name ?? item?.label ?? "",
              `Level ${score || ""}`.trim()
            );
            const type = normalizeLevelType(item?.type ?? item?.levelType ?? item?.level_type ?? "value_sum");
            const description = normalizeLevelDescription(item?.description ?? item?.describe ?? item?.infoText ?? item?.info_text ?? "");
            const buttonText = normalizeLevelButtonText(item?.buttonText ?? item?.button_text ?? "");
            const potSettings = normalizePotSettings(item?.potSettings ?? item?.pot_settings, name);
            return { id, name, type, score, description, buttonText, potSettings };
          })
          .filter((item) => item.score > 0 && item.name !== "")
          .sort((a, b) => a.score - b.score);
      }
    } catch {}
    return [];
  }

  async function savePrizeLevels(levels, { confirmEmpty = false } = {}) {
    try {
      const response = await fetch(`${API_URL}?action=save_prize_levels`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          levels,
          version: prizeLevelsVersion,
          confirmEmptyLevels: confirmEmpty === true,
          csrf: csrfToken
        })
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.status !== "ok") {
        window.alert(payload?.message || "Saving prize levels failed. No data was overwritten; reload and try again.");
        window.location.reload();
        return null;
      }
      prizeLevelsVersion = String(payload.version || "").trim();
      return payload;
    } catch {
      window.alert("Saving prize levels failed. No data was overwritten; reload and try again.");
      window.location.reload();
      return null;
    }
  }

  function renderPrizes(prizes, listEl) {
    if (!listEl) {
      return;
    }
    if (!prizes.length) {
      listEl.innerHTML = '<tr><td colspan="7" class="muted">هنوز جایزه‌ای اضافه نشده است.</td></tr>';
      return;
    }
    listEl.innerHTML = prizes
      .map((prize, index) => {
        const quantity = Number.isFinite(prize.quantity) && prize.quantity >= 0
          ? prize.quantity
          : 0;
        const last = Number.isFinite(prize.last) && prize.last >= 0 ? prize.last : quantity;
        const value = parsePrizeValue(prize.value, 0);
        return `
          <tr data-index="${index}" data-prize-name="${escapeHtml(prize.name)}">
            <td>
              <label class="field standard-width" style="margin:0;">
                <input type="text" data-field="name" value="${escapeHtml(prize.name)}" />
              </label>
            </td>
            <td>
              <label class="field standard-width" style="margin:0;">
                <input type="text" data-field="onWheelName" value="${escapeHtml(prize.onWheelName ?? prize.name)}" />
              </label>
            </td>
            <td>
              <span class="tc-status-pill">
                <span class="tc-status-last">${escapeHtml(last)}</span>
                <span class="tc-status-divider">/</span>
                <span class="tc-status-qty">${escapeHtml(quantity)}</span>
              </span>
            </td>
            <td>
              <label class="field tc-standard-third tc-prize-qty" style="margin:0;">
                <input type="number" data-field="quantity" min="0" step="1" value="${escapeHtml(quantity)}" />
              </label>
            </td>
            <td>
              <label class="field standard-width" style="margin:0;">
                <input type="text" data-field="value" inputmode="decimal" value="${escapeHtml(formatPrizeValue(value))}" />
              </label>
            </td>
            <td>
              <div class="tc-count-control">
                <button type="button" class="btn tc-btn-count-add" data-action="add-count" title="افزودن به موجودی" aria-label="افزودن به موجودی">
                  <span class="ri ri-add-line" aria-hidden="true"></span>
                </button>
                <button type="button" class="btn tc-btn-count-sub" data-action="sub-count" title="کم کردن از موجودی" aria-label="کم کردن از موجودی">
                  <span class="ri ri-subtract-line" aria-hidden="true"></span>
                </button>
                <button type="button" class="btn tc-btn-count-reset" data-action="reset-count" title="بازنشانی موجودی" aria-label="بازنشانی موجودی">
                  <span class="ri ri-refresh-line" aria-hidden="true"></span>
                </button>
              </div>
            </td>
            <td>
              <div class="tc-action-bar">
                <button type="button" class="btn tc-btn-danger" data-action="delete">حذف</button>
                <button type="button" class="btn primary" data-action="save">ذخیره</button>
              </div>
            </td>
          </tr>
        `;
      })
      .join("");
  }

  async function initPrizeForm() {
    const form = document.getElementById("tc-prize-form");
    const nameInput = document.getElementById("tc-prize-name");
    const quantityInput = document.getElementById("tc-prize-quantity");
    const valueInput = document.getElementById("tc-prize-value");
    const listEl = document.getElementById("tc-prize-list");
    const fakeForm = document.getElementById("tc-fake-form");
    const fakeNameInput = document.getElementById("tc-fake-name");
    const fakeListEl = document.getElementById("tc-fake-list");

    if (!form || !nameInput || !quantityInput || !valueInput || !listEl) {
      return;
    }

    const allItems = await loadPrizes();
    let prizes = allItems.filter(item => !item.isFake);
    let fakeItems = allItems.filter(item => item.isFake);
    renderPrizes(prizes, listEl);
    renderFakeItems(fakeItems, fakeListEl);
    const editState = { index: null };

    function parseQuantity(rawValue, fallback = 0) {
      const parsed = Number.parseInt(rawValue ?? "", 10);
      return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    function parseValue(rawValue, fallback = 0) {
      return parsePrizeValue(rawValue, fallback);
    }

    function getRowDraft(row) {
      const rowNameInput = row?.querySelector('[data-field="name"]');
      const rowOnWheelNameInput = row?.querySelector('[data-field="onWheelName"]');
      const rowQuantityInput = row?.querySelector('[data-field="quantity"]');
      const rowValueInput = row?.querySelector('[data-field="value"]');
      return {
        name: String(rowNameInput?.value ?? "").trim(),
        onWheelName: String(rowOnWheelNameInput?.value ?? "").trim(),
        quantity: parseQuantity(rowQuantityInput?.value, 0),
        value: parseValue(rowValueInput?.value, 0)
      };
    }

    function isDirtyRow(index, row) {
      if (!row || !Number.isInteger(index) || index < 0 || index >= prizes.length) {
        return false;
      }
      const original = prizes[index];
      const draft = getRowDraft(row);
      const originalName = String(original?.name ?? "").trim();
      const originalOnWheelName = String(original?.onWheelName ?? originalName).trim();
      const originalQuantity = parseQuantity(original?.quantity, 0);
      const originalValue = parseValue(original?.value, 0);
      return (
        draft.name !== originalName ||
        draft.onWheelName !== originalOnWheelName ||
        draft.quantity !== originalQuantity ||
        Math.abs(draft.value - originalValue) > 0.000001
      );
    }

    function syncEditStateUI() {
      const lockActive = Number.isInteger(editState.index);

      listEl.querySelectorAll("tr[data-index]").forEach(row => {
        const index = Number.parseInt(row.dataset.index ?? "", 10);
        const isActiveRow = lockActive && index === editState.index;
        const isLockedRow = lockActive && !isActiveRow;
        const dirty = isDirtyRow(index, row);

        const rowNameInput = row.querySelector('[data-field="name"]');
        const rowOnWheelNameInput = row.querySelector('[data-field="onWheelName"]');
        const rowQuantityInput = row.querySelector('[data-field="quantity"]');
        const rowValueInput = row.querySelector('[data-field="value"]');
        const saveBtn = row.querySelector('button[data-action="save"]');
        const deleteBtn = row.querySelector('button[data-action="delete"]');
        const countButtons = row.querySelectorAll('button[data-action="add-count"], button[data-action="sub-count"]');

        row.classList.toggle("tc-prize-row-locked", isLockedRow);

        if (rowNameInput) rowNameInput.disabled = isLockedRow;
        if (rowOnWheelNameInput) rowOnWheelNameInput.disabled = isLockedRow;
        if (rowQuantityInput) rowQuantityInput.disabled = isLockedRow;
        if (rowValueInput) rowValueInput.disabled = isLockedRow;

        if (deleteBtn) {
          deleteBtn.disabled = isLockedRow || (isActiveRow && dirty);
          deleteBtn.classList.toggle("tc-action-disabled", deleteBtn.disabled);
        }

        if (saveBtn) {
          const canSave = isActiveRow && dirty;
          saveBtn.disabled = !canSave;
          saveBtn.classList.toggle("tc-save-active", canSave);
          saveBtn.classList.toggle("tc-action-disabled", saveBtn.disabled);
        }

        countButtons.forEach(btn => {
          btn.disabled = isLockedRow || (isActiveRow && dirty);
          btn.classList.toggle("tc-action-disabled", btn.disabled);
        });
      });

      form.querySelectorAll("input, button").forEach(control => {
        control.disabled = lockActive;
      });
      form.classList.toggle("tc-prize-form-locked", lockActive);
    }

    async function refreshStatus() {
      if (!listEl.isConnected) {
        if (typeof window[TC_PRIZE_STATUS_STOP_KEY] === "function") {
          window[TC_PRIZE_STATUS_STOP_KEY]();
        }
        return;
      }
      if (!isRewardsPaneActive() || document.visibilityState !== "visible") {
        return;
      }
      try {
        const response = await fetch(`${API_URL}?action=get_prizes`, {
          cache: "no-store",
          credentials: "same-origin"
        });
        const result = await response.json();
        const payload = result?.status === "ok" && Array.isArray(result.data) ? result.data : [];
        if (!Array.isArray(payload) || !payload.length) {
          return;
        }
        const byName = new Map();
        payload.forEach(item => {
          const name = String(item?.name ?? "").trim();
          if (!name) return;
          const quantity = Number.parseInt(item?.quantity ?? 0, 10);
          const last = Number.parseInt(item?.last ?? quantity, 10);
          byName.set(name, {
            quantity: Number.isFinite(quantity) ? quantity : 0,
            last: Number.isFinite(last) ? last : (Number.isFinite(quantity) ? quantity : 0)
          });
        });
        listEl.querySelectorAll("tr[data-index]").forEach(row => {
          const nameInput = row.querySelector('[data-field="name"]');
          const name = String(nameInput?.value ?? "").trim();
          if (!name || !byName.has(name)) {
            return;
          }
          const status = byName.get(name);
          const lastEl = row.querySelector(".tc-status-last");
          const qtyEl = row.querySelector(".tc-status-qty");
          if (lastEl) lastEl.textContent = String(status.last);
          if (qtyEl) qtyEl.textContent = String(status.quantity);
        });
      } catch {}
    }

    const stopStatusPolling = () => {
      const interval = window[TC_PRIZE_STATUS_INTERVAL_KEY];
      if (typeof interval === "number") {
        clearInterval(interval);
      }
      delete window[TC_PRIZE_STATUS_INTERVAL_KEY];
    };
    const startStatusPolling = (refreshImmediately = true) => {
      stopStatusPolling();
      if (!listEl.isConnected || !isRewardsPaneActive() || document.visibilityState !== "visible") {
        return;
      }
      if (refreshImmediately) {
        void refreshStatus();
      }
    };
    const previousStopStatusPolling = window[TC_PRIZE_STATUS_STOP_KEY];
    if (typeof previousStopStatusPolling === "function") {
      previousStopStatusPolling();
    }
    window[TC_PRIZE_STATUS_START_KEY] = startStatusPolling;
    window[TC_PRIZE_STATUS_STOP_KEY] = stopStatusPolling;
    startStatusPolling(false);

    listEl.addEventListener("input", event => {
      const field = event.target.closest('[data-field="name"], [data-field="onWheelName"], [data-field="quantity"], [data-field="value"]');
      if (!field) {
        return;
      }
      const row = field.closest("tr[data-index]");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isInteger(index) || index < 0 || index >= prizes.length) {
        return;
      }

      const dirty = isDirtyRow(index, row);
      if (!Number.isInteger(editState.index) && dirty) {
        editState.index = index;
      } else if (editState.index === index && !dirty) {
        editState.index = null;
      }

      syncEditStateUI();
    });

    listEl.addEventListener("click", async event => {
      const button = event.target.closest("button");
      if (!button) {
        return;
      }
      const row = button.closest("tr");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= prizes.length) {
        return;
      }
      const action = button.dataset.action;
      if (action === "add-count" || action === "sub-count" || action === "reset-count") {
        if (Number.isInteger(editState.index) && editState.index !== index) {
          return;
        }
        if (editState.index === index && isDirtyRow(index, row)) {
          return;
        }
        const currentQuantity = parseQuantity(prizes[index]?.quantity, 0);
        const currentLast = parseQuantity(prizes[index]?.last, currentQuantity);
        const nextQuantity = action === "reset-count"
          ? currentQuantity
          : Math.max(0, currentQuantity + (action === "add-count" ? 1 : -1));
        const nextLast = action === "reset-count"
          ? nextQuantity
          : Math.max(0, currentLast + (action === "add-count" ? 1 : -1));
        const nextPrizes = prizes.slice();
        nextPrizes[index] = {
          ...prizes[index],
          quantity: nextQuantity,
          last: nextLast
        };
        if (!await savePrizes([...nextPrizes, ...fakeItems])) return;
        prizes = nextPrizes;
        editState.index = null;
        renderPrizes(prizes, listEl);
        syncEditStateUI();
        return;
      }
      if (action === "delete") {
        if (Number.isInteger(editState.index) && editState.index !== index) {
          return;
        }
        if (editState.index === index && isDirtyRow(index, row)) {
          return;
        }
        const nextPrizes = prizes.filter((_, itemIndex) => itemIndex !== index);
        const combinedItems = [...nextPrizes, ...fakeItems];
        const confirmEmpty = combinedItems.length === 0;
        if (confirmEmpty && !window.confirm("Delete the final prize item? The inventory will become empty.")) return;
        if (!await savePrizes(combinedItems, { confirmEmpty })) return;
        prizes = nextPrizes;
        editState.index = null;
        renderPrizes(prizes, listEl);
        syncEditStateUI();
        return;
      }
      if (action === "save") {
        if (Number.isInteger(editState.index) && editState.index !== index) {
          return;
        }
        const nameInput = row.querySelector('[data-field="name"]');
        const onWheelNameInput = row.querySelector('[data-field="onWheelName"]');
        const quantityInput = row.querySelector('[data-field="quantity"]');
        const valueInput = row.querySelector('[data-field="value"]');
        const name = String(nameInput?.value ?? "").trim();
        const onWheelName = String(onWheelNameInput?.value ?? "").trim();
        if (!name) {
          nameInput?.focus();
          return;
        }
        const quantity = parseQuantity(quantityInput?.value, 0);
        const value = parseValue(valueInput?.value, 0);
        const previousQuantity = parseQuantity(prizes[index]?.quantity, 0);
        const previousLast = parseQuantity(prizes[index]?.last, previousQuantity);
        const previousOnWheelName = String(prizes[index]?.onWheelName ?? "").trim();
        const nextOnWheelName = onWheelName || previousOnWheelName || name;
        const nextLast = quantity === previousQuantity
          ? Math.min(previousLast, quantity)
          : quantity;
        const nextPrizes = prizes.slice();
        nextPrizes[index] = {
          ...prizes[index],
          name,
          onWheelName: nextOnWheelName,
          quantity,
          last: nextLast,
          value,
          isFake: false
        };
        if (!await savePrizes([...nextPrizes, ...fakeItems])) return;
        prizes = nextPrizes;
        editState.index = null;
        renderPrizes(prizes, listEl);
        syncEditStateUI();
      }
    });

    form.addEventListener("submit", async event => {
      event.preventDefault();
      if (Number.isInteger(editState.index)) {
        return;
      }
      const name = String(nameInput.value ?? "").trim();
      if (!name) {
        nameInput.focus();
        return;
      }
      const quantity = parseQuantity(quantityInput.value, 1);
      const value = parseValue(valueInput.value, 0);
      const nextPrizes = [...prizes, { id: makePrizeId(), name, onWheelName: name, quantity, last: quantity, value, isFake: false }];
      if (!await savePrizes([...nextPrizes, ...fakeItems])) return;
      prizes = nextPrizes;
      renderPrizes(prizes, listEl);
      nameInput.value = "";
      quantityInput.value = "1";
      valueInput.value = "";
      nameInput.focus();
      syncEditStateUI();
    });

    fakeForm?.addEventListener("submit", async event => {
      event.preventDefault();
      event.stopPropagation();
      const name = String(fakeNameInput?.value ?? "").trim();
      if (!name) {
        fakeNameInput?.focus();
        return;
      }
      const nextFakeItems = [...fakeItems, { id: makePrizeId(), name, onWheelName: name, quantity: 1, last: 1, isFake: true }];
      if (!await savePrizes([...prizes, ...nextFakeItems])) return;
      fakeItems = nextFakeItems;
      renderFakeItems(fakeItems, fakeListEl);
      fakeNameInput.value = "";
      fakeNameInput.focus();
    });

    fakeListEl?.addEventListener("click", async event => {
      const button = event.target.closest("button");
      if (!button) return;
      const row = button.closest("tr");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= fakeItems.length) {
        return;
      }
      const action = button.dataset.action;
      if (action === "save") {
        const nameInput = row.querySelector('[data-field="fake-name"]');
        const name = String(nameInput?.value ?? "").trim();
        if (!name) {
          nameInput?.focus();
          return;
        }
        const nextFakeItems = fakeItems.slice();
        nextFakeItems[index] = {
          ...fakeItems[index],
          name,
          onWheelName: name
        };
        if (!await savePrizes([...prizes, ...nextFakeItems])) return;
        fakeItems = nextFakeItems;
        renderFakeItems(fakeItems, fakeListEl);
        return;
      }
      if (action === "delete") {
        const nextFakeItems = fakeItems.filter((_, itemIndex) => itemIndex !== index);
        const combinedItems = [...prizes, ...nextFakeItems];
        const confirmEmpty = combinedItems.length === 0;
        if (confirmEmpty && !window.confirm("Delete the final prize item? The inventory will become empty.")) return;
        if (!await savePrizes(combinedItems, { confirmEmpty })) return;
        fakeItems = nextFakeItems;
        renderFakeItems(fakeItems, fakeListEl);
      }
    });

    syncEditStateUI();
  }

  function renderPrizeLevels(levels, listEl) {
    if (!listEl) {
      return;
    }
    if (!levels.length) {
      listEl.innerHTML = '<tr><td colspan="5" class="muted">هنوز سطحی اضافه نشده است.</td></tr>';
      return;
    }
    listEl.innerHTML = levels
      .map((level, index) => {
        const isOutOfValue = normalizeLevelType(level.type) === "out_of_value";
        const isPot = normalizeLevelType(level.type) === "pot";
        return `
        <tr data-index="${index}" data-level-id="${escapeHtml(level.id)}">
          <td>${index + 1}</td>
          <td>
            <input
              type="text"
              class="tc-prize-level-control"
              data-field="level-name"
              value="${escapeHtml(level.name || `سطح ${level.score || ""}`)}"
            />
          </td>
          <td>
            <select class="tc-prize-level-control" data-field="level-type">
              <option value="value_sum" ${normalizeLevelType(level.type) === "value_sum" ? "selected" : ""}>مجموع ارزش جوایز</option>
              <option value="out_of_value" ${normalizeLevelType(level.type) === "out_of_value" ? "selected" : ""}>خارج از ارزش جایزه</option>
              <option value="pot" ${isPot ? "selected" : ""}>Pot</option>
            </select>
          </td>
          <td>
            <input
              type="number"
              min="1"
              step="1"
              class="tc-prize-level-control"
              data-field="level-score"
              value="${escapeHtml(level.score)}"
            />
          </td>
          <td>
            <div class="tct-action-wrap">
              ${isOutOfValue || isPot ? '<button type="button" class="btn ghost" data-action="edit-level-description">توضیحات</button>' : ''}
              ${isPot ? '<button type="button" class="btn ghost" data-action="edit-pot-settings">Pot Settings</button>' : ''}
              ${isPot ? `<a class="btn ghost" href="mini%20apps/Task%20Club/pot_draw.php?level_id=${encodeURIComponent(level.id)}" target="_blank" rel="noopener">Open Draw</a>` : ''}
              ${isPot ? `<a class="btn ghost" href="mini%20apps/Task%20Club/pot_export.php?level_id=${encodeURIComponent(level.id)}">Export</a>` : ''}
              ${isPot ? '<button type="button" class="btn ghost" data-action="reset-pot-winners">Reset Winners</button>' : ''}
              <button type="button" class="btn ghost" data-action="remove-level">حذف</button>
            </div>
          </td>
        </tr>
      `;
      })
      .join("");
  }

  async function initPrizeLevels() {
    const form = document.getElementById("tc-prize-level-form");
    const nameInput = document.getElementById("tc-prize-level-name");
    const typeInput = document.getElementById("tc-prize-level-type");
    const scoreInput = document.getElementById("tc-prize-level-score");
    const statusEl = document.getElementById("tc-prize-level-status");
    const listEl = document.getElementById("tc-prize-level-list");
    if (!form || !nameInput || !typeInput || !scoreInput || !statusEl || !listEl) {
      return;
    }

    const setStatus = (message, isError = false) => {
      statusEl.textContent = message || "";
      statusEl.style.color = isError ? "#d1434a" : "";
    };

    let levels = await loadPrizeLevels();
    renderPrizeLevels(levels, listEl);

    const openPotSettingsDialog = (index) => {
      if (!Number.isFinite(index) || index < 0 || index >= levels.length) return;
      const level = levels[index] || {};
      const settings = normalizePotSettings(level.potSettings, level.name);
      const overlay = document.createElement("div");
      overlay.className = "tc-prize-level-modal";
      overlay.innerHTML = `
        <div class="tc-prize-level-modal-card" role="dialog" aria-modal="true" aria-label="Pot settings">
          <div class="section-header"><h3>Pot Settings</h3></div>
          <label class="field full"><span>Draw title</span><input type="text" data-pot-field="title" maxlength="160" value="${escapeHtml(settings.title)}" /></label>
          <label class="field full"><span>Maximum winners</span><input type="number" data-pot-field="winnerLimit" min="1" max="1000" step="1" value="${settings.winnerLimit}" /></label>
          <label class="field full"><span>Prize name</span><input type="text" data-pot-field="prizeName" maxlength="160" value="${escapeHtml(settings.prizeName)}" /></label>
          <label class="switch tc-switch">
            <span class="switch-label">Lock draw and publish the final result</span>
            <span class="switch-toggle">
              <input type="checkbox" data-pot-field="locked" ${settings.locked ? "checked" : ""} />
              <span class="switch-track"><span class="switch-thumb"></span></span>
            </span>
          </label>
          <p class="muted small" data-pot-status aria-live="polite"></p>
          <div class="tc-action-bar">
            <button type="button" class="btn primary standard-primary-button" data-action="save-pot-settings">Save</button>
            <button type="button" class="btn ghost" data-action="close-pot-settings">Close</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      overlay.addEventListener("click", async (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (target === overlay || target.closest('[data-action="close-pot-settings"]')) {
          overlay.remove();
          return;
        }
        if (!target.closest('[data-action="save-pot-settings"]')) return;
        const titleField = overlay.querySelector('[data-pot-field="title"]');
        const limitField = overlay.querySelector('[data-pot-field="winnerLimit"]');
        const prizeNameField = overlay.querySelector('[data-pot-field="prizeName"]');
        const lockedField = overlay.querySelector('[data-pot-field="locked"]');
        const nextSettings = normalizePotSettings({
          title: titleField?.value ?? "",
          winnerLimit: limitField?.value ?? 1,
          prizeName: prizeNameField?.value ?? "",
          locked: lockedField?.checked ?? false
        }, level.name);
        const dialogStatus = overlay.querySelector("[data-pot-status]");
        if (nextSettings.locked && !nextSettings.prizeName) {
          if (dialogStatus) dialogStatus.textContent = "Prize name is required before locking the draw.";
          prizeNameField?.focus();
          return;
        }
        if (nextSettings.locked) {
          try {
            const response = await fetch("mini%20apps/Task%20Club/pot_api.php", {
              method: "POST",
              credentials: "same-origin",
              headers: {"Content-Type": "application/json"},
              body: JSON.stringify({action: "state", levelId: level.id, csrf: csrfToken})
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload?.status !== "ok") {
              throw new Error(payload?.message || "Unable to check Pot winners.");
            }
            if (!Array.isArray(payload.winners) || payload.winners.length < 1) {
              if (dialogStatus) dialogStatus.textContent = "Confirm at least one winner before locking the draw.";
              return;
            }
          } catch (error) {
            if (dialogStatus) dialogStatus.textContent = error?.message || "Unable to check Pot winners.";
            return;
          }
        }
        const nextLevels = levels.map((item, itemIndex) => itemIndex === index
          ? { ...item, potSettings: nextSettings }
          : item);
        if (await persistLevels(nextLevels, "Pot settings saved.")) {
          overlay.remove();
        }
      });
      overlay.querySelector('[data-pot-field="title"]')?.focus();
    };

    const openDescriptionDialog = (index) => {
      if (!Number.isFinite(index) || index < 0 || index >= levels.length) return;
      const level = levels[index] || {};
      const overlay = document.createElement("div");
      overlay.className = "tc-prize-level-modal";
      overlay.innerHTML = `
        <div class="tc-prize-level-modal-card" role="dialog" aria-modal="true" aria-label="توضیحات سطح جایزه">
          <div class="section-header">
            <h3>توضیحات</h3>
          </div>
          <label class="field full">
            <span>Button Text</span>
            <input type="text" data-level-description-field="buttonText" autocomplete="off" maxlength="80" value="${escapeHtml(level.buttonText || "")}" />
          </label>
          <label class="field full">
            <span>توضیحات</span>
            <div class="tc-rich-text-tools" aria-label="ابزارهای ویرایش توضیحات">
              <button type="button" class="btn ghost" data-level-description-format="bold" title="ضخیم" aria-label="ضخیم"><strong>B</strong></button>
              <button type="button" class="btn ghost" data-level-description-format="list" title="فهرست" aria-label="فهرست">List</button>
            </div>
            <textarea data-level-description-field="description" rows="9">${escapeHtml(level.description || "")}</textarea>
          </label>
          <p class="muted small" data-level-description-status aria-live="polite"></p>
          <div class="tc-action-bar">
            <button type="button" class="btn primary standard-primary-button" data-action="save-level-description">ذخیره</button>
            <button type="button" class="btn ghost" data-action="close-level-description">بستن</button>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
      const buttonTextField = overlay.querySelector('[data-level-description-field="buttonText"]');
      const descriptionField = overlay.querySelector('[data-level-description-field="description"]');
      const status = overlay.querySelector("[data-level-description-status]");
      const close = () => overlay.remove();
      overlay.addEventListener("click", async (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const formatButton = target.closest("[data-level-description-format]");
        if (formatButton instanceof HTMLButtonElement) {
          applyDescriptionFormat(descriptionField, formatButton.dataset.levelDescriptionFormat || "");
          return;
        }
        if (target === overlay || target.closest('[data-action="close-level-description"]')) {
          close();
          return;
        }
        if (!target.closest('[data-action="save-level-description"]')) return;
        const nextLevels = levels.map((item, itemIndex) => {
          if (itemIndex !== index) return item;
          return {
            ...item,
            description: normalizeLevelDescription(descriptionField?.value ?? ""),
            buttonText: normalizeLevelButtonText(buttonTextField?.value ?? "")
          };
        });
        const saved = await persistLevels(nextLevels, "توضیحات سطح ذخیره شد.");
        if (saved) {
          close();
        } else if (status instanceof HTMLElement) {
          status.textContent = "ذخیره توضیحات ناموفق بود.";
          status.style.color = "#d1434a";
        }
      });
      if (buttonTextField instanceof HTMLInputElement) {
        buttonTextField.focus();
      }
    };

    const persistLevels = async (
      nextLevels,
      successMessage = "سطح‌ها ذخیره شدند.",
      { confirmEmpty = false } = {}
    ) => {
      const normalized = nextLevels
        .map((item) => ({
          id: String(item?.id ?? "").trim() || makeLevelId(),
          name: normalizeLevelName(item?.name ?? item?.levelName ?? item?.label ?? ""),
          type: normalizeLevelType(item?.type ?? "value_sum"),
          score: normalizeLevelScore(item?.score ?? item?.levelScore ?? 0),
          description: normalizeLevelDescription(item?.description ?? item?.describe ?? item?.infoText ?? ""),
          buttonText: normalizeLevelButtonText(item?.buttonText ?? item?.button_text ?? ""),
          potSettings: normalizePotSettings(item?.potSettings ?? item?.pot_settings, item?.name ?? "")
        }))
        .filter((item) => item.score > 0 && item.name !== "")
        .sort((a, b) => a.score - b.score);
      const saved = await savePrizeLevels(normalized, { confirmEmpty });
      if (!saved) {
        setStatus("ذخیره سطح‌ها ناموفق بود.", true);
        return false;
      }
      const committedRows = Array.isArray(saved.data) ? saved.data : normalized;
      levels = committedRows
        .map((item) => ({
          id: String(item?.id ?? "").trim() || makeLevelId(),
          name: normalizeLevelName(item?.name ?? item?.levelName ?? item?.label ?? ""),
          type: normalizeLevelType(item?.type ?? "value_sum"),
          score: normalizeLevelScore(item?.score ?? item?.levelScore ?? 0),
          description: normalizeLevelDescription(item?.description ?? item?.describe ?? item?.infoText ?? ""),
          buttonText: normalizeLevelButtonText(item?.buttonText ?? item?.button_text ?? ""),
          potSettings: normalizePotSettings(item?.potSettings ?? item?.pot_settings, item?.name ?? "")
        }))
        .filter((item) => item.score > 0 && item.name !== "")
        .sort((a, b) => a.score - b.score);
      renderPrizeLevels(levels, listEl);
      setStatus(successMessage, false);
      return true;
    };

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = normalizeLevelName(nameInput.value);
      if (!name) {
        setStatus("نام سطح الزامی است.", true);
        nameInput.focus();
        return;
      }
      const type = normalizeLevelType(typeInput.value);
      const score = normalizeLevelScore(scoreInput.value);
      if (score <= 0) {
        setStatus("امتیاز باید بیشتر از صفر باشد.", true);
        scoreInput.focus();
        return;
      }
      const nextLevels = [...levels, {
        id: makeLevelId(),
        name,
        type,
        score,
        description: "",
        buttonText: "",
        potSettings: normalizePotSettings({}, name)
      }];
      const saved = await persistLevels(nextLevels, "سطح اضافه شد.");
      if (saved) {
        nameInput.value = "";
        typeInput.value = "value_sum";
        scoreInput.value = "";
        nameInput.focus();
      }
    });

    listEl.addEventListener("click", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const removeBtn = target.closest('[data-action="remove-level"]');
      const descriptionBtn = target.closest('[data-action="edit-level-description"]');
      const potSettingsBtn = target.closest('[data-action="edit-pot-settings"]');
      const resetPotBtn = target.closest('[data-action="reset-pot-winners"]');
      if (!removeBtn && !descriptionBtn && !potSettingsBtn && !resetPotBtn) return;
      const row = (removeBtn || descriptionBtn || potSettingsBtn || resetPotBtn).closest("tr[data-index]");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= levels.length) return;
      if (descriptionBtn) {
        openDescriptionDialog(index);
        return;
      }
      if (potSettingsBtn) {
        openPotSettingsDialog(index);
        return;
      }
      if (resetPotBtn) {
        if (!window.confirm("Clear every confirmed winner for this Pot? This cannot be undone.")) return;
        resetPotBtn.disabled = true;
        try {
          const response = await fetch("mini%20apps/Task%20Club/pot_api.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "reset", levelId: levels[index].id, csrf: csrfToken })
          });
          const payload = await response.json();
          if (!response.ok || payload?.status !== "ok") {
            throw new Error(payload?.message || "Failed to reset Pot winners.");
          }
          setStatus("Pot winners cleared.");
        } catch (error) {
          setStatus(error?.message || "Failed to reset Pot winners.", true);
        } finally {
          resetPotBtn.disabled = false;
        }
        return;
      }
      const nextLevels = levels.filter((_, itemIndex) => itemIndex !== index);
      const confirmEmpty = nextLevels.length === 0;
      if (confirmEmpty && !window.confirm("Delete the final prize level? No card level will remain.")) return;
      await persistLevels(nextLevels, "سطح حذف شد.", { confirmEmpty });
    });

    listEl.addEventListener("change", async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const fieldName = target.getAttribute("data-field");
      if (fieldName !== "level-score" && fieldName !== "level-name" && fieldName !== "level-type") return;
      const row = target.closest("tr[data-index]");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= levels.length) return;
      const nameField = row?.querySelector('[data-field="level-name"]');
      const typeField = row?.querySelector('[data-field="level-type"]');
      const scoreField = row?.querySelector('[data-field="level-score"]');
      const nextName = normalizeLevelName(nameField?.value ?? "", `سطح ${levels[index]?.score || ""}`.trim());
      if (!nextName) {
        if (nameField) {
          nameField.value = String(levels[index].name || "");
        }
        setStatus("نام سطح الزامی است.", true);
        return;
      }
      const nextType = normalizeLevelType(typeField?.value ?? levels[index]?.type ?? "value_sum");
      const nextScore = normalizeLevelScore(scoreField?.value ?? 0);
      if (nextScore <= 0) {
        if (scoreField) {
          scoreField.value = String(levels[index].score);
        }
        setStatus("امتیاز باید بیشتر از صفر باشد.", true);
        return;
      }
      const nextLevels = levels.map((item, itemIndex) => {
        if (itemIndex !== index) return item;
        return { ...item, name: nextName, type: nextType, score: nextScore };
      });
      await persistLevels(nextLevels, "سطح به‌روزرسانی شد.");
    });
  }

  async function initPrizeAwards() {
    const listEl = document.getElementById("tc-prize-awards-list");
    const statusEl = document.getElementById("tc-prize-awards-status");
    const refreshBtn = document.getElementById("tc-prize-awards-refresh");
    const resetAllBtn = document.getElementById("tc-prize-awards-reset-all");
    if (!listEl) return;
    let currentItems = [];
    let currentTotal = 0;

    const setStatus = (message, error = false) => {
      if (!statusEl) return;
      statusEl.textContent = String(message || "");
      statusEl.style.color = error ? "#b42318" : "";
    };
    const render = (items, total = 0) => {
      currentItems = Array.isArray(items) ? items : [];
      currentTotal = Math.max(currentItems.length, Number.parseInt(total, 10) || 0);
      if (resetAllBtn) resetAllBtn.disabled = currentTotal === 0;
      if (!items.length) {
        listEl.innerHTML = '<tr><td colspan="7" class="muted">No confirmed prize records.</td></tr>';
        return;
      }
      listEl.innerHTML = items.map((item) => {
        const pending = item.status === "reset_pending";
        return `<tr>
          <td>${escapeHtml(item.userName || "-")}</td>
          <td>${escapeHtml(formatPrizeValue(item.prizeValue ?? 0))}</td>
          <td>${escapeHtml(item.cardNumber || "-")}</td>
          <td>${escapeHtml(item.levelName || "-")}</td>
          <td>${escapeHtml(item.prizeName || "-")}</td>
          <td>${escapeHtml(item.wonAt || "-")}</td>
          <td><button type="button" class="btn tc-btn-danger" data-reset-award-id="${escapeHtml(item.awardId)}">${pending ? "Retry reset" : "Reset prize"}</button></td>
        </tr>`;
      }).join("");
    };
    const load = async () => {
      if (refreshBtn) refreshBtn.disabled = true;
      if (resetAllBtn) resetAllBtn.disabled = true;
      setStatus("Loading prize records...");
      try {
        const response = await fetch(`${API_URL}?action=get_prize_awards&limit=100`, {credentials: "same-origin"});
        const payload = await response.json();
        if (!response.ok || payload?.status !== "ok" || !Array.isArray(payload.data)) {
          throw new Error(payload?.message || "Failed to load prize records.");
        }
        render(payload.data, payload.total);
        const shown = payload.data.length;
        setStatus(currentTotal > shown
          ? `Showing the newest ${shown} of ${currentTotal} active prize records.`
          : `${currentTotal} active prize record${currentTotal === 1 ? "" : "s"}.`);
      } catch (error) {
        setStatus(error?.message || "Failed to load prize records.", true);
      } finally {
        if (refreshBtn) refreshBtn.disabled = false;
        if (resetAllBtn) resetAllBtn.disabled = currentTotal === 0;
      }
    };

    refreshBtn?.addEventListener("click", load);
    resetAllBtn?.addEventListener("click", async () => {
      const count = currentTotal;
      if (count <= 0) return;
      if (!window.confirm(`Reset all ${count} recorded prize${count === 1 ? "" : "s"}?\n\nThis removes every matching win from user CSV histories, restores the inventory items, and lets the users win those levels again. This cannot be undone.`)) return;
      resetAllBtn.disabled = true;
      if (refreshBtn) refreshBtn.disabled = true;
      setStatus(`Resetting ${count} prize${count === 1 ? "" : "s"} safely...`);
      try {
        const response = await fetch(`${API_URL}?action=reset_all_prize_awards`, {
          method: "POST",
          credentials: "same-origin",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({confirmResetAll: true, csrf: csrfToken})
        });
        const payload = await response.json();
        const message = payload?.message || (response.ok ? "All prizes reset successfully." : "Reset all did not finish.");
        await load();
        setStatus(message, !response.ok || payload?.status !== "ok");
      } catch (error) {
        const message = error?.message || "Reset all did not finish. Completed resets remain consistent and the action can be retried.";
        await load();
        setStatus(message, true);
      } finally {
        if (refreshBtn) refreshBtn.disabled = false;
        if (resetAllBtn) resetAllBtn.disabled = currentTotal === 0;
      }
    });
    listEl.addEventListener("click", async (event) => {
      const button = event.target instanceof HTMLElement ? event.target.closest("[data-reset-award-id]") : null;
      if (!(button instanceof HTMLButtonElement)) return;
      const row = button.closest("tr");
      const cells = row ? Array.from(row.cells).map((cell) => cell.textContent?.trim() || "") : [];
      const description = `${cells[0] || "This user"} - ${cells[4] || "prize"}`;
      if (!window.confirm(`Reset ${description}?\n\nThis removes the matching win from the user's CSV history, restores one inventory item, and lets the user win that level again.`)) return;
      button.disabled = true;
      setStatus("Resetting prize safely...");
      try {
        const response = await fetch(`${API_URL}?action=reset_prize_award`, {
          method: "POST",
          credentials: "same-origin",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({awardId: button.dataset.resetAwardId || "", csrf: csrfToken})
        });
        const payload = await response.json();
        if (!response.ok || payload?.status !== "ok") throw new Error(payload?.message || "Prize reset did not finish.");
        await load();
        setStatus(payload.message || "Prize reset successfully.");
      } catch (error) {
        const message = error?.message || "Prize reset did not finish. No unsafe CSV replacement was made.";
        await load();
        setStatus(message, true);
      }
    });
    await load();
  }

  function renderFakeItems(fakeItems, listEl) {
    if (!listEl) {
      return;
    }
    if (!fakeItems.length) {
      listEl.innerHTML = '<tr><td colspan="2" class="muted">هنوز آیتم نمایشی اضافه نشده است.</td></tr>';
      return;
    }
    listEl.innerHTML = fakeItems
      .map((item, index) => `
        <tr data-index="${index}">
          <td>
            <label class="field standard-width" style="margin:0;">
              <input type="text" data-field="fake-name" value="${escapeHtml(item.name)}" />
            </label>
          </td>
          <td>
            <div class="tc-action-bar">
              <button type="button" class="btn primary" data-action="save">ذخیره</button>
              <button type="button" class="btn tc-btn-danger" data-action="delete">حذف</button>
            </div>
          </td>
        </tr>
      `)
      .join("");
  }

  let prizeLevelsInitializationStarted = false;
  let prizeStorageInitializationStarted = false;

  function initializeActiveRewardSection(sectionKey) {
    if (sectionKey === "levels" && !prizeLevelsInitializationStarted) {
      prizeLevelsInitializationStarted = true;
      void initPrizeLevels();
      return;
    }
    if (sectionKey === "storage" && !prizeStorageInitializationStarted) {
      prizeStorageInitializationStarted = true;
      void initPrizeForm();
      void initPrizeAwards();
    }
  }

  function syncRewardsActivity() {
    if (isRewardsPaneActive()) {
      const sectionKey = getActiveRewardSectionKey();
      initializeActiveRewardSection(sectionKey);
      if (sectionKey === "storage" && typeof window[TC_PRIZE_STATUS_START_KEY] === "function") {
        window[TC_PRIZE_STATUS_START_KEY]();
      } else if (typeof window[TC_PRIZE_STATUS_STOP_KEY] === "function") {
        window[TC_PRIZE_STATUS_STOP_KEY]();
      }
      return;
    }
    if (typeof window[TC_PRIZE_STATUS_STOP_KEY] === "function") {
      window[TC_PRIZE_STATUS_STOP_KEY]();
    }
  }

  function bindRewardsLifecycle() {
    const previousActivationHandler = window[TC_REWARDS_ACTIVATION_HANDLER_KEY];
    if (typeof previousActivationHandler === "function") {
      document.removeEventListener("click", previousActivationHandler);
    }
    const activationHandler = (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('.sub-item[data-pane], [data-tab], [data-tc-reward-config-trigger]')
        : null;
      if (!target) return;
      window.setTimeout(syncRewardsActivity, 0);
    };
    window[TC_REWARDS_ACTIVATION_HANDLER_KEY] = activationHandler;
    document.addEventListener("click", activationHandler);

    const previousVisibilityHandler = window[TC_REWARDS_VISIBILITY_HANDLER_KEY];
    if (typeof previousVisibilityHandler === "function") {
      document.removeEventListener("visibilitychange", previousVisibilityHandler);
    }
    const visibilityHandler = () => {
      if (document.visibilityState === "visible") {
        syncRewardsActivity();
      } else if (typeof window[TC_PRIZE_STATUS_STOP_KEY] === "function") {
        window[TC_PRIZE_STATUS_STOP_KEY]();
      }
    };
    window[TC_REWARDS_VISIBILITY_HANDLER_KEY] = visibilityHandler;
    document.addEventListener("visibilitychange", visibilityHandler);

    if (typeof window[TC_PRIZE_STATUS_STOP_KEY] === "function") {
      window[TC_PRIZE_STATUS_STOP_KEY]();
    }
    syncRewardsActivity();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindRewardsLifecycle, { once: true });
  } else {
    bindRewardsLifecycle();
  }
})();
