<section id="tab-invite" class="tab">
    <div class="card">
      <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
          <h3>اطلاعات ورود</h3>
          <p class="muted small">کد ملی مهمان را وارد کنید یا اسکن کنید.</p>
        </div>
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
      <div
        class="card-progress hidden"
        data-invite-entry-progress
        role="status"
        aria-live="polite"
      >
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="card-progress__message" data-invite-entry-progress-message>
          در حال بررسی مهمان...
        </p>
      </div>
      </form>

      <div class="invite-stats-shell">
        <div class="invite-stats-title">آمار مهمان‌ها</div>
        <div class="invite-stats-grid" id="invite-stats-grid"></div>
      </div>
    </div>

  <div class="card">
    <div class="section-header">
      <h3>مهمان‌های حاضر</h3>
    </div>
    <div id="invite-log-list" class="invite-log-list" role="list"></div>
  </div>

  <div id="invite-entry-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-entry-title">
    <div class="modal-card default-modal-card" style="max-width:420px;">
      <div class="modal-card-header">
        <div class="modal-card-header-start">
          <h3 id="invite-entry-title">چاپ رسید ورود</h3>
        </div>
      </div>
      <div class="modal-card-body invite-entry-body">
        <div class="invite-summary">
          <div class="invite-summary-name" id="invite-guest-name">-</div>
          <div class="muted small" id="invite-guest-id">-</div>
          <div class="muted small" id="invite-guest-code">-</div>
        </div>
      </div>
      <div class="modal-actions guide-modal-actions">
        <button type="button" class="btn ghost" data-invite-entry-close>لغو</button>
        <button type="button" class="btn primary" id="invite-print-btn">چاپ کارت</button>
      </div>
    </div>
  </div>

  <div id="invite-exited-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="invite-exited-title">
    <div class="modal-card default-modal-card" style="max-width:420px;">
      <div class="modal-card-header">
        <div class="modal-card-header-start">
          <h3 id="invite-exited-title">خروج تکراری</h3>
        </div>
        <button type="button" class="icon-btn" data-invite-exited-close aria-label="بستن">×</button>
      </div>
      <div class="modal-card-body">
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
      <p class="invite-print-greeting">به رویداد <span id="invite-print-event-name">همراه با نامی آشنا</span> خوش آمدید</p>
      <div class="invite-print-label">کد قرعه کشی شما</div>
      <div class="invite-print-code" id="invite-print-code"></div>
      <div class="invite-print-entry-info">
        <span id="invite-print-entry-line"></span>
      </div>
    </div>
  </div>

  <style>
    #invite-status {
      display: none;
    }
    #tab-invite .invite-log-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    #invite-status {
      display: none;
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
    .invite-stats-shell {
      margin-top: 18px;
      border: 1px solid rgba(15, 23, 42, 0.12);
      border-radius: 12px;
      padding: 12px;
      background: #fff;
    }
    .invite-stats-title {
      margin: 0 0 8px;
      font-size: 13px;
      color: #475569;
      font-weight: 600;
    }
    .invite-stats-grid {
      display: grid;
      gap: 6px;
    }
    .invite-stats-item {
      display: flex;
      justify-content: space-between;
      font-size: 12pt;
      direction: rtl;
      gap: 8px;
      align-items: baseline;
    }
    .invite-stats-value {
      display: flex;
      gap: 2px;
      align-items: baseline;
    }
    .invite-stats-primary {
      font-weight: 700;
      color: var(--primary);
    }
    .invite-stats-secondary {
      font-weight: 500;
      color: #475569;
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
    #invite-entry-modal .modal-card-header,
    #invite-entry-modal .modal-card-header-start {
      justify-content: flex-end;
    }
    #invite-entry-modal .modal-card-header-start {
      margin-right: 0;
      margin-left: auto;
      text-align: right;
      width: 100%;
      display: flex;
      justify-content: flex-end;
    }
    .invite-entry-body {
      max-height: 70vh;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .guide-modal-actions {
      justify-content: flex-start;
      gap: 8px;
      flex-wrap: wrap;
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
      const scopedEventCode = (window.EVENT_SCOPED_EVENT_CODE || "").trim();
      const inviteAssetBasePath = String(window.INVITE_ASSET_BASE_PATH || "").replace(/\/+$/, "");
      const resolveInviteAssetPath = (relativePath) => {
        const trimmedRel = (relativePath || "").replace(/^\/+/, "");
        if (!trimmedRel) {
          return inviteAssetBasePath || "/";
        }
        if (inviteAssetBasePath) {
          return `${inviteAssetBasePath}/${trimmedRel}`;
        }
        return `/${trimmedRel}`;
      };
      const inviteApiBase = resolveInviteAssetPath("api/guests.php");
      const inviteApiEndpoint = scopedEventCode ? `${inviteApiBase}?event_code=${encodeURIComponent(scopedEventCode)}` : inviteApiBase;
      const nationalInput = document.getElementById("invite-national-id");
      const inviteForm = document.getElementById("invite-form");
      const statusBox = document.getElementById("invite-status");
      const logList = document.getElementById("invite-log-list");
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
      const statsGrid = document.getElementById("invite-stats-grid");
      const entryLineEl = document.getElementById("invite-print-entry-line");
      const inviteEntryProgressElement = document.querySelector("[data-invite-entry-progress]");
      const inviteEntryProgressMessage = inviteEntryProgressElement?.querySelector("[data-invite-entry-progress-message]");
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
      let entryModalEnabled = true;

      const statusLabels = {
        enter: "ورود",
        exit: "خروج",
        spam: "اسکن تکراری",
        more_exit: "خروج دوباره",
        not_found: "یافت نشد"
      };

      const messageTranslations = {
        "Guest marked as entered.": "مهمان وارد شده",
        "Guest marked as exited.": "مهمان خارج شده",
        "Repeated scan too soon after entry.": "اسکن تکراری ثبت شد",
        "Guest already exited earlier.": "مهمان قبلاً خارج شده بود.",
        "National ID not found in the active event list.": "کد ملی در فهرست فعال یافت نشد."
      };

      function showToast(message) {
        if (!message) return;
        window.showDefaultToast?.({ message });
      }

      function showSnackbar(message) {
        if (!message) return;
        window.showErrorSnackbar?.({ message });
      }

      const toneByOutcome = {
        enter: "success",
        exit: "info",
        spam: "warn",
        more_exit: "warn",
        not_found: "error"
      };
      let currentStats = null;
      const printEventNameEl = document.getElementById("invite-print-event-name");
      let currentEventName = (printEventNameEl?.textContent || "همراه با نامی آشنا").trim();
      function updateEventName(name) {
        currentEventName = (name || "").trim() || "همراه با نامی آشنا";
        if (printEventNameEl) {
          printEventNameEl.textContent = currentEventName;
        }
      }
      updateEventName(currentEventName);

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

      function toggleInviteEntryProgress(show) {
        if (!inviteEntryProgressElement) {
          return;
        }
        inviteEntryProgressElement.classList.toggle("hidden", !show);
      }

      function setInviteEntryProgressMessage(message) {
        if (!inviteEntryProgressMessage) {
          return;
        }
        inviteEntryProgressMessage.textContent = message || "";
      }

      function startInviteEntryProgress(message = "در حال بررسی مهمان...") {
        if (!inviteEntryProgressElement) {
          return;
        }
        toggleInviteEntryProgress(true);
        setInviteEntryProgressMessage(message);
      }

      function stopInviteEntryProgress() {
        if (!inviteEntryProgressElement) {
          return;
        }
        toggleInviteEntryProgress(false);
        setInviteEntryProgressMessage("");
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

      function parseShamsiDateTimeParts(value) {
        const trimmed = (value || "").trim();
        if (!trimmed) {
          return null;
        }
        const normalized = trimmed.replace(/\s+/g, " ").replace(/[Tt]/g, " ");
        const segments = normalized.split(" ").filter(Boolean);
        let dateSegment = segments[0] || "";
        let timeSegment = segments[1] || "";
        if (!timeSegment && dateSegment.includes("T")) {
          const splitted = dateSegment.split("T");
          dateSegment = splitted[0] || dateSegment;
          timeSegment = splitted[1] || "";
        }
        const dateParts = dateSegment.split(/[\\/\\-]/).filter(Boolean);
        if (dateParts.length < 3) {
          return null;
        }
        const year = parseInt(dateParts[0], 10);
        const month = parseInt(dateParts[1], 10);
        const day = parseInt(dateParts[2], 10);
        if ([year, month, day].some((n) => Number.isNaN(n))) {
          return null;
        }
        const timeParts = timeSegment.split(":").filter(Boolean);
        const hour = parseInt(timeParts[0], 10);
        const minute = parseInt(timeParts[1], 10);
        const second = parseInt(timeParts[2], 10);
        return {
          year,
          month,
          day,
          hour: Number.isNaN(hour) ? 0 : hour,
          minute: Number.isNaN(minute) ? 0 : minute,
          second: Number.isNaN(second) ? 0 : second
        };
      }

      function jalaliToJdn(jy, jm, jd) {
        if (jm < 1 || jm > 12 || jd < 1) {
          return null;
        }
        const epBase = jy - (jy >= 0 ? 474 : 473);
        const epYear = 474 + ((epBase % 2820) + 2820) % 2820;
        const monthDays = jm <= 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186;
        const dayNo =
          jd +
          monthDays +
          Math.floor((epYear * 682 - 110) / 2816) +
          (epYear - 1) * 365 +
          Math.floor(epBase / 2820) * 1029983;
        return dayNo + 1948320;
      }

      function jdnToGregorian(jdn) {
        const j = jdn + 32044;
        const g = Math.floor((4 * j + 3) / 146097);
        const dg = j - Math.floor((146097 * g) / 4);
        const c = Math.floor((4 * dg + 3) / 1461);
        const dc = dg - Math.floor((1461 * c) / 4);
        const b = Math.floor((5 * dc + 2) / 153);
        const day = dc - Math.floor((153 * b + 2) / 5) + 1;
        const month = b + 3 - 12 * Math.floor(b / 10);
        const year = g * 100 + c - 4800 + Math.floor(b / 10);
        return { year, month, day };
      }

      function convertShamsiToGregorian(value) {
        const parts = parseShamsiDateTimeParts(value);
        if (!parts) {
          return null;
        }
        const jdn = jalaliToJdn(parts.year, parts.month, parts.day);
        if (jdn === null) {
          return null;
        }
        const gregorian = jdnToGregorian(jdn);
        return {
          year: gregorian.year,
          month: gregorian.month,
          day: gregorian.day,
          hour: parts.hour,
          minute: parts.minute,
          second: parts.second
        };
      }

      function translateLogMessage(value) {
        if (!value) return "";
        return messageTranslations[value] || value;
      }

      function updateEntryModalPreference(eventData) {
        if (eventData && typeof eventData.print_entry_modal === "boolean") {
          entryModalEnabled = Boolean(eventData.print_entry_modal);
        }
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
        const shamsiConverted = convertShamsiToGregorian(trimmed);
        if (shamsiConverted) {
          const candidate = new Date(
            shamsiConverted.year,
            shamsiConverted.month - 1,
            shamsiConverted.day,
            shamsiConverted.hour,
            shamsiConverted.minute,
            shamsiConverted.second
          );
          if (!Number.isNaN(candidate.getTime())) {
            return candidate;
          }
        }
        return null;
      }

      function buildEntryLine(value) {
        const dateObj = parseEntryTimestamp(value);
        if (!dateObj) {
          return "";
        }
        const timeText = persianTimeFormatter.format(dateObj);
        const dateParts = persianDateFormatter.formatToParts(dateObj);
        const dayPart = dateParts.find((part) => part.type === "day")?.value || "";
        const monthPart = dateParts.find((part) => part.type === "month")?.value || "";
        const yearPart = dateParts.find((part) => part.type === "year")?.value || "";
        const weekday = persianWeekdayFormatter.format(dateObj);
        const hasFullDate = Boolean(weekday && dayPart && monthPart && yearPart);
        const dateSegment = hasFullDate ? `روز ${weekday} ${dayPart} ${monthPart} ${yearPart}` : "";
        if (timeText && dateSegment) {
          return `ورود ساعت ${timeText} ${dateSegment}`;
        }
        if (timeText) {
          return `ورود ساعت ${timeText}`;
        }
        if (dateSegment) {
          return `ورود ${dateSegment}`;
        }
        return "";
      }

      function updateEntryInfo(value) {
        const entryLine = buildEntryLine(value);
        if (entryLineEl) {
          entryLineEl.textContent = entryLine;
        }
      }

      function sortLogs(list) {
        return [...list].sort((a, b) => {
          const aTime = String(a?.timestamp || "");
          const bTime = String(b?.timestamp || "");
          return bTime.localeCompare(aTime);
        });
      }

      function createRatioItem(label, present, total) {
        const item = document.createElement("div");
        item.className = "invite-stats-item";
        const labelEl = document.createElement("span");
        labelEl.textContent = label;
        const valueEl = document.createElement("span");
        valueEl.className = "invite-stats-value";
        const primary = document.createElement("span");
        primary.className = "invite-stats-primary";
        primary.textContent = String(present ?? 0);
        const secondary = document.createElement("span");
        secondary.className = "invite-stats-secondary";
        secondary.textContent = `/${total ?? 0}`;
        valueEl.appendChild(primary);
        valueEl.appendChild(secondary);
        item.appendChild(labelEl);
        item.appendChild(valueEl);
        return item;
      }

      function renderStats(stats) {
        if (!statsGrid) return;
        if (stats) {
          currentStats = stats;
        }
        statsGrid.innerHTML = "";
        if (!currentStats) {
          statsGrid.textContent = "در حال بارگذاری آمار...";
          return;
        }
        statsGrid.appendChild(
          createRatioItem(
            "تعداد مهمانان حاضر",
            currentStats.total_present ?? 0,
            currentStats.total_invited ?? 0
          )
        );
        const genders = Array.from(
          new Set([
            ...Object.keys(currentStats.present_by_gender || {}),
            ...Object.keys(currentStats.invited_by_gender || {})
          ])
        );
        genders.forEach((gender) => {
          const present = currentStats.present_by_gender?.[gender] ?? 0;
          const invited = currentStats.invited_by_gender?.[gender] ?? 0;
          statsGrid.appendChild(createRatioItem(`${gender}`, present, invited));
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
          const labelWithName = log?.guest_name ? `${label} · ${log.guest_name}` : label;
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
                join_date: log?.join_date,
                join_time: log?.join_time,
                left_date: log?.left_date,
                left_time: log?.left_time,
                date_entered: log?.date_entered,
                date_exited: log?.date_exited,
                entryTimestamp: log?.timestamp
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
        const entryValue =
          guest.entryTimestamp ||
          guest.date_entered ||
          (guest.join_date ? `${guest.join_date} ${guest.join_time || ""}`.trim() : "");
        updateEntryInfo(entryValue);
      }

      function openEntryModal(guest = {}) {
        lastGuest = guest;
        const fullName = guest.full_name || `${guest.firstname || ""} ${guest.lastname || ""}`.trim() || "مهمان";
        if (guestNameEl) guestNameEl.textContent = fullName;
        if (guestIdEl) guestIdEl.textContent = guest.national_id ? `کد ملی: ${guest.national_id}` : "";
        if (guestCodeEl) guestCodeEl.textContent = guest.invite_code ? `کد مهمانی: ${guest.invite_code}` : "";
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
          const response = await fetch(inviteApiEndpoint);
          const data = await response.json().catch(() => ({}));
          if (response.ok && data.status === "ok" && Array.isArray(data.logs)) {
            logs = data.logs;
            renderLogs();
            renderStats(data.stats);
            updateEventName(data.event_name);
            updateEntryModalPreference(data.active_event);
          }
        } catch (_) {
          // ignore initial load errors
        }
      }

      async function scanNationalId(value) {
        if (loading) return;
        loading = true;
        startInviteEntryProgress();
        setStatus("در حال بررسی مهمان...", "info");
        const formData = new FormData();
        formData.append("action", "scan_invite");
        formData.append("national_id", value);
        if (scopedEventCode) {
          formData.append("event_code", scopedEventCode);
        }
        try {
          const response = await fetch(inviteApiBase, { method: "POST", body: formData });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || data.status !== "ok") {
            throw new Error(data?.message || "عدم امکان اسکن مهمان.");
          }
          if (Array.isArray(data.logs)) {
            mergeLogs(data.logs);
          } else if (data.log) {
            mergeLogs([data.log]);
          }
          renderStats(data.stats);
          const guest = data.guest || {};
          updateEventName(data.event_name || guest.event_name);
          updateEntryModalPreference(data.active_event);
          const outcome = data.outcome;
          const guestDisplayName =
            guest.full_name ||
            `${guest.firstname || ""} ${guest.lastname || ""}`.trim() ||
            "مهمان";
          const tone = toneByOutcome[outcome] || "";
          if (outcome === "enter") {
            const successMessage = `ورود ${guestDisplayName} ثبت شد.`;
            setStatus(successMessage, "success");
            showToast(successMessage);
            const entryTimestamp = guest.join_date
              ? `${guest.join_date} ${guest.join_time || ""}`.trim()
              : data.log?.timestamp || guest.date_entered;
            const guestForModal = { ...guest, entryTimestamp };
            lastGuest = guestForModal;
            if (entryModalEnabled) {
              openEntryModal(guestForModal);
            }
          } else if (outcome === "exit") {
            const successMessage = `خروج ${guestDisplayName} ثبت شد.`;
            setStatus(successMessage, tone || "info");
            showToast(successMessage);
          } else if (outcome === "spam") {
            const warnMessage = "اسکن تکراری ثبت شد (کمتر از ۵ دقیقه).";
            setStatus(warnMessage, tone || "warn");
            showSnackbar("اسکن تکراری ثبت شد.");
          } else if (outcome === "more_exit") {
            const exitValue = guest?.left_date
              ? `${guest.left_date} ${guest.left_time || ""}`.trim()
              : guest.date_exited || "";
            const exitedParts = exitValue ? formatTimestampParts(exitValue) : null;
            const hasExit = Boolean(exitedParts);
            const exitMessage = hasExit
              ? `UcOOñO"Oñ ${guestDisplayName} O_Oñ ${exitedParts.dateText}OO O3OO1O¦ ${exitedParts.timeText} OrOOñOª O'O_UØ O"U^O_.`
              : `UcOOñO"Oñ ${guestDisplayName} U,O"U,OU< O_Oñ OUOU+ OªU,O3UØ OrOOñOª O'O_UØ O"U^O_.`;
            setStatus(`U.UØU.OU+ U,O"U,OU< OrOOñOª O'O_UØ O"U^O_.`, tone || "warn");
            openExitedModal(exitMessage);
          } else if (outcome === "not_found") {
            setStatus("کد ملی در فهرست فعال یافت نشد.", "error");
            showSnackbar("کد ملی در فهرست فعال یافت نشد.");
          } else {
            setStatus(data?.message || "وضعیت به‌روزرسانی شد.", tone || "");
          }
        } catch (error) {
          const errMsg = error?.message || "خطا در اسکن مهمان.";
          setStatus(errMsg, "error");
          showSnackbar(errMsg);
        } finally {
          loading = false;
          stopInviteEntryProgress();
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
          const invalidMsg = "کد ملی باید ۱۰ رقم باشد.";
          setStatus(invalidMsg, "warn");
          showSnackbar(invalidMsg);
        }
      });

      printButton?.addEventListener("click", () => triggerPrint());

      entryCloseButtons?.forEach((btn) => btn.addEventListener("click", () => hideModal(entryModal)));
      exitCloseButtons?.forEach((btn) => btn.addEventListener("click", () => hideModal(exitModal)));
      [entryModal, exitModal].forEach((modal) => {
        modal?.addEventListener("click", (event) => {
          if (event.target === modal) {
            hideModal(modal);
          }
        });
      });

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
      renderStats();
      document.addEventListener("DOMContentLoaded", () => {
        loadInviteData();
        setInterval(() => {
          if (!loading) {
            loadInviteData();
          }
        }, 5000);
      });
    })();
  </script>
</section>
