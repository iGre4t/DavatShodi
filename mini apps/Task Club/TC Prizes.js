(() => {
  const API_URL = "mini%20apps/Task%20Club/tc_store.php";
  const TC_PRIZE_STATUS_INTERVAL_KEY = "__tcPrizeStatusInterval";

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
    if (token === "out_of_value") {
      return "out_of_value";
    }
    return "value_sum";
  }

  function makeLevelId() {
    return `lvl_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
  }

  async function loadPrizes() {
    try {
      const response = await fetch(`${API_URL}?action=get_prizes`, { credentials: "same-origin" });
      const payload = await response.json();
      if (payload?.status === "ok" && Array.isArray(payload.data)) {
        return payload.data
          .map(item => {
            const quantity = Number.parseInt(item?.quantity ?? 0, 10);
            const last = Number.parseInt(item?.last ?? quantity, 10);
            const name = String(item?.name ?? "").trim();
            const onWheelName = String(item?.onWheelName ?? name).trim();
            const value = parsePrizeValue(item?.value ?? 0, 0);
            return {
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

  async function savePrizes(prizes) {
    try {
      await fetch(`${API_URL}?action=save_prizes`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ prizes })
      });
    } catch {}
  }

  async function loadPrizeLevels() {
    try {
      const response = await fetch(`${API_URL}?action=get_prize_levels`, { credentials: "same-origin" });
      const payload = await response.json();
      if (payload?.status === "ok" && Array.isArray(payload.data)) {
        return payload.data
          .map((item) => {
            const id = String(item?.id ?? "").trim() || makeLevelId();
            const score = normalizeLevelScore(item?.score ?? 0);
            const name = normalizeLevelName(item?.name ?? "", `Level ${score || ""}`.trim());
            const type = normalizeLevelType(item?.type ?? "value_sum");
            return { id, name, type, score };
          })
          .filter((item) => item.score > 0 && item.name !== "")
          .sort((a, b) => a.score - b.score);
      }
    } catch {}
    return [];
  }

  async function savePrizeLevels(levels) {
    try {
      const response = await fetch(`${API_URL}?action=save_prize_levels`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ levels })
      });
      const payload = await response.json();
      return response.ok && payload?.status === "ok";
    } catch {}
    return false;
  }

  function renderPrizes(prizes, listEl) {
    if (!listEl) {
      return;
    }
    if (!prizes.length) {
      listEl.innerHTML = '<tr><td colspan="7" class="muted">No prizes added yet.</td></tr>';
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
                <button type="button" class="btn tc-btn-count-add" data-action="add-count" title="Add" aria-label="Add">
                  <span class="ri ri-add-line" aria-hidden="true"></span>
                </button>
                <button type="button" class="btn tc-btn-count-sub" data-action="sub-count" title="Sub" aria-label="Sub">
                  <span class="ri ri-subtract-line" aria-hidden="true"></span>
                </button>
                <button type="button" class="btn tc-btn-count-reset" data-action="reset-count" title="Reset" aria-label="Reset">
                  <span class="ri ri-refresh-line" aria-hidden="true"></span>
                </button>
              </div>
            </td>
            <td>
              <div class="tc-action-bar">
                <button type="button" class="btn tc-btn-danger" data-action="delete">Delete</button>
                <button type="button" class="btn primary" data-action="save">Save</button>
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
      try {
        const response = await fetch("mini%20apps/Task%20Club/TC%20Prizes.json", { cache: "no-store" });
        const payload = await response.json();
        if (!Array.isArray(payload)) {
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

    refreshStatus();
    const previousStatusInterval = window[TC_PRIZE_STATUS_INTERVAL_KEY];
    if (typeof previousStatusInterval === "number") {
      clearInterval(previousStatusInterval);
    }
    window[TC_PRIZE_STATUS_INTERVAL_KEY] = window.setInterval(refreshStatus, 5000);

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
        prizes[index] = {
          ...prizes[index],
          quantity: nextQuantity,
          last: nextLast
        };
        editState.index = null;
        await savePrizes([...prizes, ...fakeItems]);
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
        prizes.splice(index, 1);
        editState.index = null;
        await savePrizes([...prizes, ...fakeItems]);
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
        prizes[index] = {
          name,
          onWheelName: nextOnWheelName,
          quantity,
          last: nextLast,
          value,
          isFake: false
        };
        editState.index = null;
        await savePrizes([...prizes, ...fakeItems]);
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
      prizes.push({ name, onWheelName: name, quantity, last: quantity, value, isFake: false });
      await savePrizes([...prizes, ...fakeItems]);
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
      fakeItems.push({ name, onWheelName: name, quantity: 1, last: 1, isFake: true });
      await savePrizes([...prizes, ...fakeItems]);
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
        fakeItems[index] = {
          ...fakeItems[index],
          name,
          onWheelName: name
        };
        await savePrizes([...prizes, ...fakeItems]);
        renderFakeItems(fakeItems, fakeListEl);
        return;
      }
      if (action === "delete") {
        fakeItems.splice(index, 1);
        await savePrizes([...prizes, ...fakeItems]);
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
      listEl.innerHTML = '<tr><td colspan="5" class="muted">No levels added yet.</td></tr>';
      return;
    }
    listEl.innerHTML = levels
      .map((level, index) => `
        <tr data-index="${index}" data-level-id="${escapeHtml(level.id)}">
          <td>${index + 1}</td>
          <td>
            <input
              type="text"
              class="tc-prize-level-control"
              data-field="level-name"
              value="${escapeHtml(level.name || `Level ${level.score || ""}`)}"
            />
          </td>
          <td>
            <select class="tc-prize-level-control" data-field="level-type">
              <option value="value_sum"${normalizeLevelType(level.type) === "value_sum" ? " selected" : ""}>Value Sum</option>
              <option value="out_of_value"${normalizeLevelType(level.type) === "out_of_value" ? " selected" : ""}>Out of Value</option>
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
              <button type="button" class="btn ghost" data-action="remove-level">Remove</button>
            </div>
          </td>
        </tr>
      `)
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

    const persistLevels = async (nextLevels, successMessage = "Levels saved.") => {
      const normalized = nextLevels
        .map((item) => ({
          id: String(item?.id ?? "").trim() || makeLevelId(),
          name: normalizeLevelName(item?.name ?? ""),
          type: normalizeLevelType(item?.type ?? "value_sum"),
          score: normalizeLevelScore(item?.score ?? 0)
        }))
        .filter((item) => item.score > 0 && item.name !== "")
        .sort((a, b) => a.score - b.score);
      const saved = await savePrizeLevels(normalized);
      if (!saved) {
        setStatus("Failed to save levels.", true);
        return false;
      }
      levels = normalized;
      renderPrizeLevels(levels, listEl);
      setStatus(successMessage, false);
      return true;
    };

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = normalizeLevelName(nameInput.value);
      if (!name) {
        setStatus("Name is required.", true);
        nameInput.focus();
        return;
      }
      const type = normalizeLevelType(typeInput.value);
      const score = normalizeLevelScore(scoreInput.value);
      if (score <= 0) {
        setStatus("Score must be greater than zero.", true);
        scoreInput.focus();
        return;
      }
      const nextLevels = [...levels, { id: makeLevelId(), name, type, score }];
      const saved = await persistLevels(nextLevels, "Level added.");
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
      if (!removeBtn) return;
      const row = removeBtn.closest("tr[data-index]");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= levels.length) return;
      const nextLevels = levels.filter((_, itemIndex) => itemIndex !== index);
      await persistLevels(nextLevels, "Level removed.");
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
      const nextName = normalizeLevelName(nameField?.value ?? "");
      const nextType = normalizeLevelType(typeField?.value ?? "value_sum");
      const nextScore = normalizeLevelScore(scoreField?.value ?? 0);
      if (!nextName) {
        if (nameField) {
          nameField.value = String(levels[index].name ?? "");
          nameField.focus();
        }
        setStatus("Name is required.", true);
        return;
      }
      if (nextScore <= 0) {
        if (scoreField) {
          scoreField.value = String(levels[index].score);
        }
        setStatus("Score must be greater than zero.", true);
        return;
      }
      const nextLevels = levels.map((item, itemIndex) => {
        if (itemIndex !== index) return item;
        return { ...item, name: nextName, type: nextType, score: nextScore };
      });
      await persistLevels(nextLevels, "Level updated.");
    });
  }

  function renderFakeItems(fakeItems, listEl) {
    if (!listEl) {
      return;
    }
    if (!fakeItems.length) {
      listEl.innerHTML = '<tr><td colspan="2" class="muted">No fake items yet.</td></tr>';
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
              <button type="button" class="btn primary" data-action="save">Save</button>
              <button type="button" class="btn tc-btn-danger" data-action="delete">Delete</button>
            </div>
          </td>
        </tr>
      `)
      .join("");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      initPrizeForm();
      initPrizeLevels();
    });
  } else {
    initPrizeForm();
    initPrizeLevels();
  }
})();

