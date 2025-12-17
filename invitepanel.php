<section id="tab-invite" class="tab">
    <div class="card">
      <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
          <h3>دعوت</h3>
          <p class="muted small">کد ملی مهمان را وارد کنید تا برای مراسم آماده شود.</p>
        </div>
        <button type="button" class="btn ghost" id="invite-refresh">تازه‌سازی</button>
      </div>
      <form id="invite-form" class="form" autocomplete="off">
        <label class="field standard-width">
          <span>کد ملی (۱۰ رقم)</span>
      <input
          id="invite-national-id"
          name="national_id"
          type="text"
          inputmode="numeric"
          pattern="\d*"
          maxlength="10"
          placeholder="٠٠٠٠٠٠٠٠٠٠"
          autocomplete="off"
          required
        />
        </label>
      <p id="invite-status" class="hint" aria-live="polite"></p>
      </form>
    </div>

  <div class="card">
    <div class="section-header">
      <h3>مهمان‌های حاضر</h3>
    </div>
    <div id="invite-log-list" class="invite-log-list" role="list"></div>
  </div>

  <div id="invite-entry-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-entry-title">
    <div class="modal-card" style="max-width:420px;">
      <div class="modal-header">
        <h3 id="invite-entry-title">مهمان آماده ورود</h3>
        <button type="button" class="icon-btn" data-invite-entry-close aria-label="بستن">X</button>
      </div>
      <div class="modal-body">
        <div class="invite-summary">
          <div class="invite-summary-name" id="invite-guest-name">-</div>
          <div class="muted small" id="invite-guest-id">-</div>
          <div class="muted small" id="invite-guest-code">-</div>
        </div>
        <p class="muted small">برای چاپ کارت، Enter یا گزینه چاپ را بزنید.</p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn ghost" data-invite-entry-close>لغو</button>
        <button type="button" class="btn primary" id="invite-print-btn">چاپ کارت</button>
      </div>
    </div>
  </div>

  <div id="invite-exited-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-exited-title">
    <div class="modal-card" style="max-width:420px;">
      <div class="modal-header">
    <h3 id="invite-exited-title">مهمان مراسم را ترک کرده است</h3>
        <button type="button" class="icon-btn" data-invite-exited-close aria-label="Close exit modal">X</button>
      </div>
      <div class="modal-body">
        <p id="invite-exited-message" class="muted"></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn primary" data-invite-exited-close>تأیید</button>
      </div>
    </div>
  </div>

  <div id="invite-print-area" aria-hidden="true">
    <div class="invite-print-card">
      <div class="invite-print-salutation">مهمان محترم</div>
      <div class="invite-print-name" id="invite-print-name"></div>
      <p class="invite-print-greeting">به رویداد همراه با نامی آشنا خوش آمدید</p>
      <div class="invite-print-label">کد قرعه کشی شما</div>
      <div class="invite-print-code" id="invite-print-code"></div>
      <div class="invite-print-entry-info">
        <span id="invite-print-entry-time"></span>
        <span id="invite-print-entry-date-line"></span>
      </div>
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
    #tab-invite .invite-log-grid {
      display: grid;
      gap: 4px;
      border-top: 1px dashed rgba(15, 23, 42, 0.1);
      padding-top: 8px;
      margin-top: 6px;
    }
    #tab-invite .invite-log-row {
      display: flex;
      justify-content: space-between;
      gap: 6px;
      font-size: 10pt;
      direction: rtl;
    }
    #tab-invite .invite-log-label {
      color: #475569;
      opacity: 0.9;
      font-size: 9pt;
    }
    #tab-invite .invite-log-value {
      font-weight: 600;
      text-align: right;
      max-width: 60%;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
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
    #tab-invite .card + .card {
      margin-top: 20px;
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
      width: 72mm;
      height: 72mm;
      padding: 10mm 6mm;
      display: grid;
      gap: 4mm;
      text-align: center;
      font-family: 'PeydaWebFaNum', 'PeydaWebFaNum', sans-serif;
      line-height: 1.2;
      border: 1px solid #000;
      box-sizing: border-box;
      overflow: hidden;
      justify-items: center;
      align-content: center;
    }
    #invite-print-area .invite-print-name {
      font-size: 14pt;
      font-weight: 700;
      word-break: break-word;
    }
    .invite-print-salutation {
      font-size: 9pt;
      letter-spacing: 0.5pt;
      text-transform: uppercase;
      opacity: 0.8;
    }
    .invite-print-entry-info {
      display: grid;
      gap: 0.4mm;
      font-size: 8pt;
      line-height: 1.2;
      direction: rtl;
      margin-top: 1mm;
    }
    .invite-print-entry-info span {
      white-space: nowrap;
    }
    .invite-print-greeting,
    .invite-print-note {
      font-size: 10pt;
      margin: 0;
    }
    #invite-print-area .invite-print-label {
      font-size: 11pt;
      font-weight: 600;
      letter-spacing: 0.3pt;
    }
    #invite-print-area .invite-print-code {
      font-size: 18pt;
      font-weight: 800;
      letter-spacing: 1pt;
    }
    @page {
      size: 72mm 72mm portrait;
      margin: 0;
    }
    @media print {
      html, body {
        width: 72mm;
        height: 72mm;
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
        margin: 0;
        width: 72mm;
        height: 72mm;
        display: flex;
        align-items: center;
        justify-content: center;
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
      const entryTimeLineEl = document.getElementById("invite-print-entry-time");
      const entryDateLineEl = document.getElementById("invite-print-entry-date-line");
      const persianTimeFormatter = new Intl.DateTimeFormat("fa-IR", {
        hour: "2-digit",
        minute: "2-digit",
        hourCycle: "h23"
      });
      const persianWeekdayFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
        weekday: "long"
      });
      const persianDateFormatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
        day: "numeric",
        month: "long",
        year: "numeric"
      });
      let logs = [];
      let lastGuest = null;
      let loading = false;

      const statusLabels = {
        enter: "ورود",
        exit: "خروج",
        spam: "اسکن تکراری",
        more_exit: "خروج دوباره",
        not_found: "یافت نشد"
      };

      const messageTranslations = {
        "Guest marked as entered.": "مهمان وارد شده",
        "Guest marked as exited.": "مهمان خروج کرده",
        "Repeated scan too soon after entry.": "اسکن تکراری ثبت شد",
        "Guest already exited earlier.": "مهمان قبلاً خارج شده بود.",
        "National ID not found in the active event list.": "کد ملی در فهرست فعال یافت نشد."
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

      const logDateFormatter = new Intl.DateTimeFormat("fa-IR", {
        year: "numeric",
        month: "long",
        day: "numeric"
      });
      const logTimeFormatter = new Intl.DateTimeFormat("fa-IR", {
        hour: "2-digit",
        minute: "2-digit",
        hourCycle: "h23"
      });

      function formatTimestampParts(value) {
        if (!value) return null;
        const iso = value.includes("T") ? value : value.replace(" ", "T");
        const dt = new Date(iso);
        if (Number.isNaN(dt.getTime())) return null;
        return {
          dateText: logDateFormatter.format(dt),
          timeText: logTimeFormatter.format(dt)
        };
      }

      function formatTimestamp(value) {
        const parts = formatTimestampParts(value);
        if (!parts) return "";
        return `${parts.dateText} ${parts.timeText}`;
      }

      function translateLogMessage(value) {
        if (!value) return "";
        return messageTranslations[value] || value;
      }

      function parseEntryTimestamp(value) {
        const trimmed = (value || "").trim();
        if (!trimmed) return null;
        const isoCandidate = trimmed.replace(/\s+/g, "T");
        const parsed = new Date(isoCandidate);
        if (!Number.isNaN(parsed.getTime())) {
          return parsed;
        }
        const altCandidate = trimmed.replace(/[\\/]/g, "-").replace(/\s+/g, "T");
        const altParsed = new Date(altCandidate);
        if (!Number.isNaN(altParsed.getTime())) {
          return altParsed;
        }
        return null;
      }

      function buildEntryDateLines(value) {
        const dateObj = parseEntryTimestamp(value);
        if (!dateObj) {
          return { timeLine: "", dateLine: "" };
        }
        const timeText = persianTimeFormatter.format(dateObj);
        const dateParts = persianDateFormatter.formatToParts(dateObj);
        const dayPart = dateParts.find((part) => part.type === "day")?.value || "";
        let monthPart = dateParts.find((part) => part.type === "month")?.value || "";
        if (monthPart && !monthPart.endsWith("ماه")) {
          monthPart = `${monthPart}ماه`;
        }
        const yearPart = dateParts.find((part) => part.type === "year")?.value || "";
        const weekday = persianWeekdayFormatter.format(dateObj);
        const timeLine = timeText ? `ساعت ورود ${timeText} دقیقه` : "";
        const dateLine =
          weekday && dayPart && monthPart && yearPart
            ? `در روز ${weekday} ${dayPart} ${monthPart} ${yearPart}`
            : "";
        return { timeLine, dateLine };
      }

      function updateEntryInfo(value) {
        const { timeLine, dateLine } = buildEntryDateLines(value);
        if (entryTimeLineEl) {
          entryTimeLineEl.textContent = timeLine;
        }
        if (entryDateLineEl) {
          entryDateLineEl.textContent = dateLine;
        }
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
          empty.textContent = "هنوز اسکن انجام نشده.";
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
          const label = statusLabels[type] || "به‌روزرسانی";
          const labelWithName =
            log?.guest_name && label === statusLabels.enter ? `${label} · ${log.guest_name}` : label;
          title.textContent = labelWithName;

          const infoGrid = document.createElement("div");
          infoGrid.className = "invite-log-grid";

          const addRow = (labelText, valueText) => {
            if (!valueText) return;
            const row = document.createElement("div");
            row.className = "invite-log-row";
            const labelEl = document.createElement("span");
            labelEl.className = "invite-log-label";
            labelEl.textContent = labelText;
            const valueEl = document.createElement("span");
            valueEl.className = "invite-log-value";
            valueEl.textContent = valueText;
            row.appendChild(labelEl);
            row.appendChild(valueEl);
            infoGrid.appendChild(row);
          };

          if (log?.invite_code) {
            addRow("کد قرعه‌کشی", log.invite_code);
          }
          addRow("کد ملی", log?.national_id);
          const timestampParts = formatTimestampParts(log?.timestamp);
          if (timestampParts) {
            addRow("تاریخ ورود", timestampParts.dateText);
            addRow("زمان ورود", timestampParts.timeText);
          }
          const messageText = translateLogMessage(log?.message);
          if (messageText) {
            addRow("پیام", messageText);
          }

          container.appendChild(title);
          container.appendChild(infoGrid);
          if (type === "enter" && log?.guest_name) {
            const actions = document.createElement("div");
            actions.className = "invite-log-actions";
            const printAction = document.createElement("button");
            printAction.type = "button";
            printAction.className = "btn ghost small invite-log-print-btn";
            printAction.textContent = "چاپ رسید";
            printAction.addEventListener("click", () => {
              const guestForPrint = {
                full_name: log.guest_name,
                firstname: log?.firstname,
                lastname: log?.lastname,
                national_id: log?.national_id,
                invite_code: log?.invite_code,
                date_entered: log?.date_entered
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
        const fullName = guest.full_name || `${guest.firstname || ""} ${guest.lastname || ""}`.trim() || "مهمان";
        if (printNameEl) printNameEl.textContent = fullName;
        if (printCodeEl) printCodeEl.textContent = guest.invite_code || "----";
        updateEntryInfo(guest.date_entered);
      }

      function openEntryModal(guest = {}) {
        lastGuest = guest;
        const fullName = guest.full_name || `${guest.firstname || ""} ${guest.lastname || ""}`.trim() || "مهمان";
        if (guestNameEl) guestNameEl.textContent = fullName;
        if (guestIdEl) guestIdEl.textContent = guest.national_id ? `کد ملی: ${guest.national_id}` : "";
        if (guestCodeEl) guestCodeEl.textContent = guest.invite_code ? `کد یکتا: ${guest.invite_code}` : "";
        updatePrintArea(guest);
        showModal(entryModal);
        printButton?.focus();
      }

      function openExitedModal(message) {
        if (exitedMessageEl) {
          exitedMessageEl.textContent = message || "مهمان قبلاً خارج شده است.";
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
        setStatus("در حال بررسی مهمان...", "info");
        const formData = new FormData();
        formData.append("action", "scan_invite");
        formData.append("national_id", value);
        try {
          const response = await fetch("./api/guests.php", { method: "POST", body: formData });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || data.status !== "ok") {
            throw new Error(data?.message || "عدم امکان اسکن مهمان.");
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
            setStatus("مهمان پیدا شد. آماده چاپ کارت.", "success");
            openEntryModal(guest);
            window.showDefaultToast?.({ message: "ورود ثبت شد." });
          } else if (outcome === "exit") {
            setStatus("مهمان مراسم را ترک کرد.", tone || "info");
            window.showDefaultToast?.({ message: "خروج ثبت شد." });
          } else if (outcome === "spam") {
            setStatus("اسکن تکراری ثبت شد (کمتر از ۵ دقیقه).", tone || "warn");
            window.showDefaultToast?.({ message: "اسکن تکراری ذخیره شد." });
          } else if (outcome === "more_exit") {
            const exitedParts = guest?.date_exited ? formatTimestampParts(guest.date_exited) : null;
            const exitedAt = exitedParts ? `${exitedParts.dateText} ${exitedParts.timeText}` : "";
            setStatus("مهمان قبلاً خارج شده بود.", tone || "warn");
            openExitedModal(`کاربر قبلاً در ${exitedAt || "این جلسه"} خارج شده بود.`);
          } else if (outcome === "not_found") {
            setStatus("کد ملی در فهرست فعال یافت نشد.", "error");
            window.showErrorSnackbar?.({ message: "کد ملی در فهرست فعال یافت نشد." });
          } else {
            setStatus(data?.message || "وضعیت به‌روزرسانی شد.", tone || "");
          }
        } catch (error) {
          setStatus(error?.message || "خطا در اسکن مهمان.", "error");
          window.showErrorSnackbar?.({ message: error?.message || "خطا در اسکن مهمان." });
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
          setStatus("کد ملی باید ۱۰ رقم باشد.", "warn");
        }
      });

      refreshButton?.addEventListener("click", () => {
        loadInviteData();
        setStatus("لیست تازه شد.", "info");
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
