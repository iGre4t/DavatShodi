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
            return {
              name: String(item?.name ?? "").trim(),
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
      listEl.innerHTML = '<tr><td colspan="4" class="muted">No prizes added yet.</td></tr>';
      return;
    }
    listEl.innerHTML = prizes
      .map((prize, index) => {
        const quantity = Number.isFinite(prize.quantity) && prize.quantity > 0
          ? prize.quantity
          : 1;
        const last = Number.isFinite(prize.last) ? prize.last : quantity;
        return `
          <tr data-index="${index}" data-prize-name="${escapeHtml(prize.name)}">
            <td>
              <label class="field standard-width" style="margin:0;">
                <input type="text" data-field="name" value="${escapeHtml(prize.name)}" />
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
                <input type="number" data-field="quantity" min="1" step="1" value="${escapeHtml(quantity)}" />
              </label>
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
    let lockedEditIndex = null;

    function parseQuantity(rawValue, fallback = 1) {
      const parsed = Number.parseInt(rawValue ?? "", 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
    }

    function getRowDraft(row) {
      const rowNameInput = row?.querySelector('[data-field="name"]');
      const rowQuantityInput = row?.querySelector('[data-field="quantity"]');
      return {
        name: String(rowNameInput?.value ?? "").trim(),
        quantity: parseQuantity(rowQuantityInput?.value, 1)
      };
    }

    function isRowDirty(row, index) {
      if (!row || !Number.isFinite(index) || index < 0 || index >= prizes.length) {
        return false;
      }
      const draft = getRowDraft(row);
      const original = prizes[index] ?? {};
      const originalName = String(original.name ?? "").trim();
      const originalQuantity = parseQuantity(original.quantity, 1);
      return draft.name !== originalName || draft.quantity !== originalQuantity;
    }

    function syncRowAccess() {
      const lockActive = Number.isFinite(lockedEditIndex);
      const rows = listEl.querySelectorAll("tr[data-index]");
      rows.forEach(row => {
        const index = Number.parseInt(row?.dataset?.index ?? "", 10);
        const isLockedRow = lockActive && index !== lockedEditIndex;
        const dirty = isRowDirty(row, index);
        const isEditingRow = lockActive && index === lockedEditIndex;

        row.classList.toggle("wf-prize-row-locked", isLockedRow);

        const controls = row.querySelectorAll('input, button');
        controls.forEach(control => {
          const action = control?.dataset?.action ?? "";
          if (isLockedRow) {
            control.disabled = true;
            return;
          }
          if (action === "save") {
            control.disabled = !dirty;
            control.classList.toggle("wf-save-active", dirty);
            return;
          }
          if (action === "delete" && isEditingRow && dirty) {
            control.disabled = true;
            return;
          }
          control.disabled = false;
        });
      });

      const formControls = form.querySelectorAll("input, button");
      formControls.forEach(control => {
        control.disabled = lockActive;
      });
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
      const fieldInput = event.target.closest('[data-field="name"], [data-field="quantity"]');
      if (!fieldInput) {
        return;
      }
      const row = fieldInput.closest("tr[data-index]");
      const index = Number.parseInt(row?.dataset?.index ?? "", 10);
      if (!Number.isFinite(index) || index < 0 || index >= prizes.length) {
        return;
      }
      const dirty = isRowDirty(row, index);
      if (dirty && !Number.isFinite(lockedEditIndex)) {
        lockedEditIndex = index;
      }
      if (Number.isFinite(lockedEditIndex) && lockedEditIndex === index && !dirty) {
        lockedEditIndex = null;
      }
      syncRowAccess();
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
      if (action === "delete") {
        if (Number.isFinite(lockedEditIndex) && lockedEditIndex !== index) {
          return;
        }
        prizes.splice(index, 1);
        lockedEditIndex = null;
        await savePrizes(prizes);
        renderPrizes(prizes, listEl);
        syncRowAccess();
        return;
      }
      if (action === "save") {
        if (Number.isFinite(lockedEditIndex) && lockedEditIndex !== index) {
          return;
        }
        const nameInput = row.querySelector('[data-field="name"]');
        const quantityInput = row.querySelector('[data-field="quantity"]');
        const name = String(nameInput?.value ?? "").trim();
        if (!name) {
          nameInput?.focus();
          return;
        }
        const quantity = parseQuantity(quantityInput?.value, 1);
        prizes[index] = { name, quantity, last: quantity };
        lockedEditIndex = null;
        await savePrizes(prizes);
        renderPrizes(prizes, listEl);
        syncRowAccess();
      }
    });

    form.addEventListener("submit", async event => {
      event.preventDefault();
      const name = String(nameInput.value ?? "").trim();
      if (!name) {
        nameInput.focus();
        return;
      }
      const quantityValue = Number.parseInt(quantityInput.value ?? "", 10);
      const quantity = Number.isFinite(quantityValue) && quantityValue > 0 ? quantityValue : 1;
      prizes.push({ name, quantity, last: quantity });
      await savePrizes(prizes);
      renderPrizes(prizes, listEl);
      lockedEditIndex = null;
      syncRowAccess();
      nameInput.value = "";
      quantityInput.value = "1";
      nameInput.focus();
    });

    syncRowAccess();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPrizeForm);
  } else {
    initPrizeForm();
  }
})();
