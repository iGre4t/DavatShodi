(() => {
  const API_URL = "Wheel%20of%20Fortune/wf_store.php";

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
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
            return {
              name,
              onWheelName,
              quantity,
              last: Number.isFinite(last) ? last : quantity
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
        body: JSON.stringify({ prizes })
      });
    } catch {}
  }

  function renderPrizes(prizes, listEl) {
    if (!listEl) {
      return;
    }
    if (!prizes.length) {
      listEl.innerHTML = '<tr><td colspan="6" class="muted">No prizes added yet.</td></tr>';
      return;
    }
    listEl.innerHTML = prizes
      .map((prize, index) => {
        const quantity = Number.isFinite(prize.quantity) && prize.quantity >= 0
          ? prize.quantity
          : 0;
        const last = Number.isFinite(prize.last) && prize.last >= 0 ? prize.last : quantity;
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
              <span class="wf-status-pill">
                <span class="wf-status-last">${escapeHtml(last)}</span>
                <span class="wf-status-divider">/</span>
                <span class="wf-status-qty">${escapeHtml(quantity)}</span>
              </span>
            </td>
            <td>
              <label class="field wf-standard-third" style="margin:0;">
                <input type="number" data-field="quantity" min="0" step="1" value="${escapeHtml(quantity)}" />
              </label>
            </td>
            <td>
              <div class="wf-count-control">
                <button type="button" class="btn wf-btn-count-add" data-action="add-count">Add</button>
                <button type="button" class="btn wf-btn-count-sub" data-action="sub-count">Sub</button>
              </div>
            </td>
            <td>
              <div class="wf-action-bar">
                <button type="button" class="btn wf-btn-danger" data-action="delete">Delete</button>
                <button type="button" class="btn primary" data-action="save">Save</button>
              </div>
            </td>
          </tr>
        `;
      })
      .join("");
  }

  async function initPrizeForm() {
    const form = document.getElementById("wf-prize-form");
    const nameInput = document.getElementById("wf-prize-name");
    const quantityInput = document.getElementById("wf-prize-quantity");
    const listEl = document.getElementById("wf-prize-list");

    if (!form || !nameInput || !quantityInput || !listEl) {
      return;
    }

    const prizes = await loadPrizes();
    renderPrizes(prizes, listEl);
    const editState = { index: null };

    function parseQuantity(rawValue, fallback = 0) {
      const parsed = Number.parseInt(rawValue ?? "", 10);
      return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    function getRowDraft(row) {
      const rowNameInput = row?.querySelector('[data-field="name"]');
      const rowOnWheelNameInput = row?.querySelector('[data-field="onWheelName"]');
      const rowQuantityInput = row?.querySelector('[data-field="quantity"]');
      return {
        name: String(rowNameInput?.value ?? "").trim(),
        onWheelName: String(rowOnWheelNameInput?.value ?? "").trim(),
        quantity: parseQuantity(rowQuantityInput?.value, 0)
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
      return (
        draft.name !== originalName ||
        draft.onWheelName !== originalOnWheelName ||
        draft.quantity !== originalQuantity
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
        const saveBtn = row.querySelector('button[data-action="save"]');
        const deleteBtn = row.querySelector('button[data-action="delete"]');
        const countButtons = row.querySelectorAll('button[data-action="add-count"], button[data-action="sub-count"]');

        row.classList.toggle("wf-prize-row-locked", isLockedRow);

        if (rowNameInput) rowNameInput.disabled = isLockedRow;
        if (rowOnWheelNameInput) rowOnWheelNameInput.disabled = isLockedRow;
        if (rowQuantityInput) rowQuantityInput.disabled = isLockedRow;

        if (deleteBtn) {
          deleteBtn.disabled = isLockedRow || (isActiveRow && dirty);
          deleteBtn.classList.toggle("wf-action-disabled", deleteBtn.disabled);
        }

        if (saveBtn) {
          const canSave = isActiveRow && dirty;
          saveBtn.disabled = !canSave;
          saveBtn.classList.toggle("wf-save-active", canSave);
          saveBtn.classList.toggle("wf-action-disabled", saveBtn.disabled);
        }

        countButtons.forEach(btn => {
          btn.disabled = isLockedRow || (isActiveRow && dirty);
          btn.classList.toggle("wf-action-disabled", btn.disabled);
        });
      });

      form.querySelectorAll("input, button").forEach(control => {
        control.disabled = lockActive;
      });
      form.classList.toggle("wf-prize-form-locked", lockActive);
    }

    async function refreshStatus() {
      try {
        const response = await fetch("Wheel%20of%20Fortune/WF%20Prizes.json", { cache: "no-store" });
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
          const lastEl = row.querySelector(".wf-status-last");
          const qtyEl = row.querySelector(".wf-status-qty");
          if (lastEl) lastEl.textContent = String(status.last);
          if (qtyEl) qtyEl.textContent = String(status.quantity);
        });
      } catch {}
    }

    refreshStatus();
    setInterval(refreshStatus, 5000);

    listEl.addEventListener("input", event => {
      const field = event.target.closest('[data-field="name"], [data-field="onWheelName"], [data-field="quantity"]');
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
      if (action === "add-count" || action === "sub-count") {
        if (Number.isInteger(editState.index) && editState.index !== index) {
          return;
        }
        if (editState.index === index && isDirtyRow(index, row)) {
          return;
        }
        const delta = action === "add-count" ? 1 : -1;
        const currentQuantity = parseQuantity(prizes[index]?.quantity, 0);
        const currentLast = parseQuantity(prizes[index]?.last, currentQuantity);
        const nextQuantity = Math.max(0, currentQuantity + delta);
        const nextLast = Math.max(0, currentLast + delta);
        prizes[index] = {
          ...prizes[index],
          quantity: nextQuantity,
          last: nextLast
        };
        editState.index = null;
        await savePrizes(prizes);
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
        await savePrizes(prizes);
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
        const name = String(nameInput?.value ?? "").trim();
        const onWheelName = String(onWheelNameInput?.value ?? "").trim();
        if (!name) {
          nameInput?.focus();
          return;
        }
        const quantity = parseQuantity(quantityInput?.value, 0);
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
          last: nextLast
        };
        editState.index = null;
        await savePrizes(prizes);
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
      prizes.push({ name, onWheelName: name, quantity, last: quantity });
      await savePrizes(prizes);
      renderPrizes(prizes, listEl);
      nameInput.value = "";
      quantityInput.value = "1";
      nameInput.focus();
      syncEditStateUI();
    });

    syncEditStateUI();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPrizeForm);
  } else {
    initPrizeForm();
  }
})();
