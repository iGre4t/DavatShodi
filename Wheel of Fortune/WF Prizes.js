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
          .map(item => ({
            name: String(item?.name ?? "").trim(),
            quantity: Number.parseInt(item?.quantity ?? 0, 10)
          }))
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
      listEl.innerHTML = '<tr><td colspan="3" class="muted">No prizes added yet.</td></tr>';
      return;
    }
    listEl.innerHTML = prizes
      .map((prize, index) => {
        const quantity = Number.isFinite(prize.quantity) && prize.quantity > 0
          ? prize.quantity
          : 1;
        return `
          <tr data-index="${index}">
            <td>
              <label class="field standard-width" style="margin:0;">
                <input type="text" data-field="name" value="${escapeHtml(prize.name)}" />
              </label>
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
        prizes.splice(index, 1);
        await savePrizes(prizes);
        renderPrizes(prizes, listEl);
        return;
      }
      if (action === "save") {
        const nameInput = row.querySelector('[data-field="name"]');
        const quantityInput = row.querySelector('[data-field="quantity"]');
        const name = String(nameInput?.value ?? "").trim();
        if (!name) {
          nameInput?.focus();
          return;
        }
        const quantityValue = Number.parseInt(quantityInput?.value ?? "", 10);
        const quantity = Number.isFinite(quantityValue) && quantityValue > 0 ? quantityValue : 1;
        prizes[index] = { name, quantity };
        await savePrizes(prizes);
        renderPrizes(prizes, listEl);
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
      prizes.push({ name, quantity });
      await savePrizes(prizes);
      renderPrizes(prizes, listEl);
      nameInput.value = "";
      quantityInput.value = "1";
      nameInput.focus();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPrizeForm);
  } else {
    initPrizeForm();
  }
})();
