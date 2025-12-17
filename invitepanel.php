<section id="tab-invite" class="tab">
  <div class="card">
    <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <h3>Invite</h3>
        <p class="muted small">Scan the active event guest list by national ID.</p>
      </div>
      <button type="button" class="btn ghost" id="invite-refresh">Refresh</button>
    </div>
    <form id="invite-form" class="form" autocomplete="off">
      <label class="field standard-width">
        <span>National ID (10 digits)</span>
        <input
          id="invite-national-id"
          name="national_id"
          type="text"
          inputmode="numeric"
          pattern="\d*"
          maxlength="10"
          placeholder="0000000000"
          autocomplete="off"
          required
        />
      </label>
      <p id="invite-status" class="hint" aria-live="polite"></p>
    </form>
  </div>

  <div class="card">
    <div class="section-header">
      <h3>Present guests</h3>
    </div>
    <div id="invite-log-list" class="invite-log-list" role="list"></div>
  </div>

  <div id="invite-entry-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-entry-title">
    <div class="modal-card" style="max-width:420px;">
      <div class="modal-header">
        <h3 id="invite-entry-title">Guest ready to enter</h3>
        <button type="button" class="icon-btn" data-invite-entry-close aria-label="Close entry modal">X</button>
      </div>
      <div class="modal-body">
        <div class="invite-summary">
          <div class="invite-summary-name" id="invite-guest-name">-</div>
          <div class="muted small" id="invite-guest-id">-</div>
          <div class="muted small" id="invite-guest-code">-</div>
        </div>
        <p class="muted small">Press Enter or click print to generate the 80mm badge.</p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn ghost" data-invite-entry-close>Cancel</button>
        <button type="button" class="btn primary" id="invite-print-btn">Print</button>
      </div>
    </div>
  </div>

  <div id="invite-exited-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-exited-title">
    <div class="modal-card" style="max-width:420px;">
      <div class="modal-header">
        <h3 id="invite-exited-title">Guest has left</h3>
        <button type="button" class="icon-btn" data-invite-exited-close aria-label="Close exit modal">X</button>
      </div>
      <div class="modal-body">
        <p id="invite-exited-message" class="muted"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn primary" data-invite-exited-close>OK</button>
      </div>
    </div>
  </div>

  <div id="invite-print-area" aria-hidden="true">
    <div class="invite-print-card">
      <div class="invite-print-name" id="invite-print-name"></div>
      <p class="invite-print-greeting">به رویداد همراه با نامی آشنا خوش آمدید</p>
      <div class="invite-print-label">کد قرعه کشی شما</div>
      <div class="invite-print-code" id="invite-print-code"></div>
      <p class="invite-print-note">لطفا در حفظ این برگه تا آخر مراسم کوشا باشید</p>
    </div>
  </div>

  <style>
    #tab-invite .invite-log-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    #tab-invite .invite-log {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 10px 12px;
      display: grid;
      gap: 4px;
      background: #f8fafc;
    }
    #tab-invite .invite-log-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 4px;
    }
    #tab-invite .invite-log-print-btn {
      padding: 4px 10px;
      font-size: 13px;
      border-radius: 8px;
    }
    #tab-invite .invite-log.enter {
      background: #e5f9ed;
      border-color: #16a34a;
    }
    #tab-invite .invite-log.spam {
      background: #fef9c3;
      border-color: #f59e0b;
    }
    #tab-invite .invite-log.exit {
      background: #e0f2fe;
      border-color: #2563eb;
    }
    #tab-invite .invite-log.more_exit,
    #tab-invite .invite-log.not_found {
      background: #fee2e2;
      border-color: #dc2626;
    }
    #tab-invite .invite-log-title {
      font-weight: 600;
    }
    #tab-invite .invite-log-meta {
      font-size: 12px;
      color: #475569;
    }
    #tab-invite .invite-log-code {
      font-weight: 600;
      font-size: 14px;
    }
    #tab-invite .invite-summary {
      display: grid;
      gap: 4px;
    }
    #tab-invite .invite-summary-name {
      font-weight: 700;
      font-size: 18px;
    }
    #invite-status[data-tone="error"] { color: #b91c1c; }
    #invite-status[data-tone="warn"] { color: #b45309; }
    #invite-status[data-tone="success"] { color: #15803d; }
    #invite-status[data-tone="info"] { color: #1d4ed8; }
    #invite-print-area {
      position: fixed;
      top: -9999px;
      left: -9999px;
    }
    #invite-print-area .invite-print-card {
      width: 700mm;
      height: 700mm;
      padding: 8mm 6mm;
      display: grid;
      gap: 6mm;
      text-align: center;
      font-family: 'PeydaWebFaNum', 'PeydaWebFaNum', sans-serif;
      line-height: 1.3;
      border: 1px solid #000;
      box-sizing: border-box;
      overflow: hidden;
    }
    #invite-print-area .invite-print-name {
      font-size: 16pt;
      font-weight: 700;
      word-break: break-word;
    }
    .invite-print-greeting,
    .invite-print-note {
      font-size: 12pt;
      margin: 0;
    }
    #invite-print-area .invite-print-label {
      font-size: 11pt;
      font-weight: 600;
      letter-spacing: 0.3pt;
    }
    #invite-print-area .invite-print-code {
      font-size: 20pt;
      font-weight: 800;
      letter-spacing: 1.5pt;
    }
    @page {
      size: 700mm 700mm;
      margin: 0;
    }
    @media print {
      html, body {
        width: 80mm;
        height: 80mm;
        margin: 0;
        padding: 0;
      }
      body * {
        visibility: hidden !important;
      }
      #invite-print-area, #invite-print-area * {
        visibility: visible !important;
      }
      #invite-print-area {
        position: absolute;
        inset: 0;
        margin: 0 auto;
      }
    }
  </style>

  <script>
    (() => {
      const nationalInput = document.getElementById("invite-national-id");
      const inviteForm = document.getElementById("invite-form");
      const statusBox = document.getElementById("invite-status");
      const logList = document.getElementById("invite-log-list");
      const refreshButton = document.getElementById("invite-refresh");
      const entryModal = document.getElementById("invite-entry-modal");
      const exitModal = document.getElementById("invite-exited-modal");
      const entryCloseButtons = entryModal ? entryModal.querySelectorAll("[data-invite-entry-close]") : [];
      const exitCloseButtons = exitModal ? exitModal.querySelectorAll("[data-invite-exited-close]") : [];
      const printButton = document.getElementById("invite-print-btn");
      const guestNameEl = document.getElementById("invite-guest-name");
      const guestIdEl = document.getElementById("invite-guest-id");
      const guestCodeEl = document.getElementById("invite-guest-code");
      const exitedMessageEl = document.getElementById("invite-exited-message");
      const printNameEl = document.getElementById("invite-print-name");
      const printCodeEl = document.getElementById("invite-print-code");
      let logs = [];
      let lastGuest = null;
      let loading = false;

      const statusLabels = {
        enter: "Entered",
        exit: "Exited",
        spam: "Spam scan",
        more_exit: "Second exit",
        not_found: "Not found"
      };

      const toneByOutcome = {
        enter: "success",
        exit: "info",
        spam: "warn",
        more_exit: "warn",
        not_found: "error"
      };

      function sanitizeDigits(value) {
        return (value || "").replace(/\D+/g, "").slice(0, 10);
      }

      function setStatus(message, tone = "") {
        if (!statusBox) return;
        statusBox.textContent = message || "";
        if (tone) {
          statusBox.setAttribute("data-tone", tone);
        } else {
          statusBox.removeAttribute("data-tone");
        }
      }

      function formatTimestamp(value) {
        if (!value) return "";
        const iso = value.includes("T") ? value : value.replace(" ", "T");
        const dt = new Date(iso);
        if (Number.isNaN(dt.getTime())) return value;
        return dt.toLocaleString();
      }

      function sortLogs(list) {
        return [...list].sort((a, b) => {
          const aTime = String(a?.timestamp || "");
          const bTime = String(b?.timestamp || "");
          return bTime.localeCompare(aTime);
        });
      }

      function renderLogs() {
        if (!logList) return;
        const items = sortLogs(logs);
        logList.innerHTML = "";
        if (!items.length) {
          const empty = document.createElement("p");
          empty.className = "muted";
          empty.textContent = "No scans yet.";
          logList.appendChild(empty);
          return;
        }
        items.forEach((log) => {
          const type = log?.type || "unknown";
          const container = document.createElement("div");
          container.className = `invite-log ${type}`;
          container.setAttribute("role", "listitem");

          const title = document.createElement("div");
          title.className = "invite-log-title";
          const label = statusLabels[type] || "Update";
          title.textContent = log?.event_name ? `${label} · ${log.event_name}` : label;

          const name = document.createElement("div");
          name.className = "invite-log-name";
          name.textContent = log?.guest_name || "Guest";

          const code = document.createElement("div");
          code.className = "invite-log-code";
          code.textContent = log?.invite_code ? `Code: ${log.invite_code}` : "";

          const meta = document.createElement("div");
          meta.className = "invite-log-meta";
          const parts = [];
          if (log?.timestamp) parts.push(formatTimestamp(log.timestamp));
          if (log?.national_id) parts.push(`ID: ${log.national_id}`);
          meta.textContent = parts.join(" · ");

          const note = document.createElement("div");
          note.className = "muted small";
          note.textContent = log?.message || "";

          container.appendChild(title);
          container.appendChild(name);
          if (code.textContent) container.appendChild(code);
      container.appendChild(meta);
      if (note.textContent) container.appendChild(note);
      if (type === "enter" && log?.guest_name) {
        const actions = document.createElement("div");
        actions.className = "invite-log-actions";
        const printAction = document.createElement("button");
        printAction.type = "button";
        printAction.className = "btn ghost small invite-log-print-btn";
        printAction.textContent = "Print receipt";
        printAction.addEventListener("click", () => {
          const guestForPrint = {
            full_name: log.guest_name,
            firstname: log?.firstname,
            lastname: log?.lastname,
            national_id: log?.national_id,
            invite_code: log?.invite_code
          };
          updatePrintArea(guestForPrint);
          window.print();
        });
        actions.appendChild(printAction);
        container.appendChild(actions);
      }
      logList.appendChild(container);
    });
  }

      function mergeLogs(nextLogs) {
        if (!Array.isArray(nextLogs)) return;
        const map = new Map();
        sortLogs([...logs, ...nextLogs]).forEach((log) => {
          if (log?.id) {
            map.set(log.id, log);
          }
        });
        logs = Array.from(map.values());
        renderLogs();
      }

      function showModal(modal) {
        if (!modal) return;
        modal.classList.remove("hidden");
      }

      function hideModal(modal) {
        if (!modal) return;
        modal.classList.add("hidden");
      }

      function updatePrintArea(guest = {}) {
        const fullName = guest.full_name || `${guest.firstname || ""} ${guest.lastname || ""}`.trim() || "Guest";
        if (printNameEl) printNameEl.textContent = fullName;
        if (printCodeEl) printCodeEl.textContent = guest.invite_code || "----";
      }

      function openEntryModal(guest = {}) {
        lastGuest = guest;
        const fullName = guest.full_name || `${guest.firstname || ""} ${guest.lastname || ""}`.trim() || "Guest";
        if (guestNameEl) guestNameEl.textContent = fullName;
        if (guestIdEl) guestIdEl.textContent = guest.national_id ? `National ID: ${guest.national_id}` : "";
        if (guestCodeEl) guestCodeEl.textContent = guest.invite_code ? `Unique code: ${guest.invite_code}` : "";
        updatePrintArea(guest);
        showModal(entryModal);
        printButton?.focus();
      }

      function openExitedModal(message) {
        if (exitedMessageEl) {
          exitedMessageEl.textContent = message || "Guest has already exited.";
        }
        showModal(exitModal);
      }

      function triggerPrint() {
        if (!lastGuest) return;
        updatePrintArea(lastGuest);
        window.print();
        hideModal(entryModal);
      }

      async function loadInviteData() {
        try {
          const response = await fetch("./api/guests.php");
          const data = await response.json().catch(() => ({}));
          if (response.ok && data.status === "ok" && Array.isArray(data.logs)) {
            logs = data.logs;
            renderLogs();
          }
        } catch (_) {
          // ignore initial load errors
        }
      }

      async function scanNationalId(value) {
        if (loading) return;
        loading = true;
        setStatus("Checking guest...", "info");
        const formData = new FormData();
        formData.append("action", "scan_invite");
        formData.append("national_id", value);
        try {
          const response = await fetch("./api/guests.php", { method: "POST", body: formData });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || data.status !== "ok") {
            throw new Error(data?.message || "Unable to scan guest.");
          }
          if (Array.isArray(data.logs)) {
            mergeLogs(data.logs);
          } else if (data.log) {
            mergeLogs([data.log]);
          }
          const outcome = data.outcome;
          const guest = data.guest || {};
          const tone = toneByOutcome[outcome] || "";
          if (outcome === "enter") {
            setStatus("Guest found. Ready to print badge.", "success");
            openEntryModal(guest);
            window.showDefaultToast?.({ message: "Entry recorded." });
          } else if (outcome === "exit") {
            setStatus("Guest left the ceremony.", tone || "info");
            window.showDefaultToast?.({ message: "Exit recorded." });
          } else if (outcome === "spam") {
            setStatus("Scan ignored (less than 5 minutes from entry).", tone || "warn");
            window.showDefaultToast?.({ message: "Spam scan saved." });
          } else if (outcome === "more_exit") {
            const exitedAt = guest?.date_exited ? formatTimestamp(guest.date_exited) : "";
            setStatus("Guest already exited earlier.", tone || "warn");
            openExitedModal(`User already left on ${exitedAt || "this session"}.`);
          } else if (outcome === "not_found") {
            setStatus("National ID not found in the active event list.", "error");
            window.showErrorSnackbar?.({ message: "National ID not found in the active event list." });
          } else {
            setStatus(data?.message || "Status updated.", tone || "");
          }
        } catch (error) {
          setStatus(error?.message || "Unable to scan guest.", "error");
          window.showErrorSnackbar?.({ message: error?.message || "Unable to scan guest." });
        } finally {
          loading = false;
          if (nationalInput) {
            nationalInput.value = "";
            nationalInput.focus();
          }
        }
      }

      nationalInput?.addEventListener("input", (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        const cleaned = sanitizeDigits(target.value);
        if (cleaned !== target.value) {
          target.value = cleaned;
        }
        if (cleaned.length === 10) {
          scanNationalId(cleaned);
        }
      });

      inviteForm?.addEventListener("submit", (event) => {
        event.preventDefault();
        const value = sanitizeDigits(nationalInput?.value || "");
        if (value.length === 10) {
          scanNationalId(value);
        } else {
          setStatus("National ID must be 10 digits.", "warn");
        }
      });

      refreshButton?.addEventListener("click", () => {
        loadInviteData();
        setStatus("Logs refreshed.", "info");
      });

      printButton?.addEventListener("click", () => triggerPrint());

      entryCloseButtons?.forEach((btn) => btn.addEventListener("click", () => hideModal(entryModal)));
      exitCloseButtons?.forEach((btn) => btn.addEventListener("click", () => hideModal(exitModal)));

      document.addEventListener("keydown", (evt) => {
        const isEntryOpen = entryModal && !entryModal.classList.contains("hidden");
        const isExitOpen = exitModal && !exitModal.classList.contains("hidden");
        if (isEntryOpen && evt.key === "Enter") {
          evt.preventDefault();
          triggerPrint();
        } else if (isEntryOpen && evt.key === "Escape") {
          hideModal(entryModal);
        } else if (isExitOpen && (evt.key === "Enter" || evt.key === "Escape")) {
          hideModal(exitModal);
        }
      });

      renderLogs();
      document.addEventListener("DOMContentLoaded", () => {
        loadInviteData();
      });
    })();
  </script>
</section>
