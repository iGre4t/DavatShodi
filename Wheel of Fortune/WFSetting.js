(() => {
  const STORAGE_KEY = "wf_settings";

  function getEl(id) {
    return document.getElementById(id);
  }

  function loadSettings() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch {
      return {};
    }
  }

  function saveSettings(settings) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
      // Ignore storage errors.
    }
  }

  function setDurationFieldsEnabled(enabled) {
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");

    [startDate, startTime, endDate, endTime].forEach(field => {
      if (field) {
        field.disabled = !enabled;
      }
    });
  }

  const digitTranslations = {
    "۰": "0",
    "۱": "1",
    "۲": "2",
    "۳": "3",
    "۴": "4",
    "۵": "5",
    "۶": "6",
    "۷": "7",
    "۸": "8",
    "۹": "9",
    "٠": "0",
    "١": "1",
    "٢": "2",
    "٣": "3",
    "٤": "4",
    "٥": "5",
    "٦": "6",
    "٧": "7",
    "٨": "8",
    "٩": "9"
  };

  function convertDigitsToEnglish(value) {
    return (value || "").replace(/[۰-۹٠-٩]/g, (ch) => digitTranslations[ch] || ch);
  }

  function normalizeShamsiDate(value = "") {
    let normalized = (value || "").trim();
    normalized = convertDigitsToEnglish(normalized);
    if (typeof toEnglishDigits === "function") {
      normalized = toEnglishDigits(normalized);
    }
    normalized = normalized.replace(/-/g, "/");
    normalized = normalized.replace(/[^\d/]/g, "");
    return normalized;
  }

  function compareNormalizedShamsiDates(a = "", b = "") {
    const left = (a || "").trim();
    const right = (b || "").trim();
    if (!left || !right) {
      return null;
    }
    if (left === right) {
      return 0;
    }
    return left > right ? 1 : -1;
  }

  function formatTodayShamsiWithIntl() {
    if (typeof Intl === "undefined") return "";
    try {
      const formatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
      });
      return formatter.format(new Date());
    } catch {
      return "";
    }
  }

  function resolveTodayShamsiDate() {
    const fromHelper = typeof getNowJalaliDate === "function" ? getNowJalaliDate() : "";
    if (fromHelper) {
      return normalizeShamsiDate(fromHelper);
    }
    const fromIntl = formatTodayShamsiWithIntl();
    if (fromIntl) {
      return normalizeShamsiDate(fromIntl);
    }
    return "";
  }

  function parseTimeToSeconds(value) {
    if (!value) return null;
    const normalized = String(value).trim();
    const parts = normalized.split(":").map((part) => Number(part));
    if (parts.length < 2 || parts.length > 3 || parts.some((n) => !Number.isFinite(n))) {
      return null;
    }
    const [hours, minutes, seconds = 0] = parts;
    return hours * 3600 + minutes * 60 + seconds;
  }

  function getCurrentLocalSeconds() {
    const now = new Date();
    return now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  }

  function describeSameDayDurationState(startTime, endTime) {
    const nowSeconds = getCurrentLocalSeconds();
    const startSeconds = parseTimeToSeconds(startTime);
    const endSeconds = parseTimeToSeconds(endTime);

    if (endSeconds !== null && nowSeconds >= endSeconds) {
      return "Ended";
    }
    if (startSeconds !== null && nowSeconds >= startSeconds) {
      return "Active";
    }
    if (startSeconds !== null && nowSeconds < startSeconds) {
      return "Upcoming";
    }
    return "Upcoming";
  }

  function updateStatus() {
    const statusEl = getEl("wf-status-text");
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");

    if (!statusEl) return;

    if (durationToggle?.checked) {
      const normalizedStartDate = normalizeShamsiDate(startDate?.value);
      const normalizedEndDate = normalizeShamsiDate(endDate?.value);
      const todayDate = resolveTodayShamsiDate();
      const startRelation = compareNormalizedShamsiDates(normalizedStartDate, todayDate);
      const endRelation = compareNormalizedShamsiDates(normalizedEndDate, todayDate);

      if (!normalizedStartDate || !todayDate) {
        statusEl.textContent = "Not Active";
        return;
      }

      if (startRelation === 1) {
        statusEl.textContent = "Upcoming";
        return;
      }

      if (endRelation !== null && endRelation === -1) {
        statusEl.textContent = "Ended";
        return;
      }

      if (startRelation === 0) {
        statusEl.textContent = describeSameDayDurationState(
          startTime?.value ?? "",
          endTime?.value ?? ""
        );
        return;
      }

      if (endRelation === 0) {
        statusEl.textContent = describeSameDayDurationState(
          startTime?.value ?? "",
          endTime?.value ?? ""
        );
        return;
      }

      statusEl.textContent = "Active";
      return;
    }

    statusEl.textContent = activeToggle?.checked ? "Active" : "Not Active";
  }

  function syncToggles({ activeToggle, durationToggle }) {
    if (!activeToggle || !durationToggle) return;
    if (activeToggle.checked) {
      durationToggle.checked = false;
    } else if (durationToggle.checked) {
      activeToggle.checked = false;
    }
    setDurationFieldsEnabled(durationToggle.checked);
    updateStatus();
  }

  function applySettings(settings) {
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");

    if (activeToggle) activeToggle.checked = Boolean(settings.active);
    if (durationToggle) durationToggle.checked = Boolean(settings.duration);
    if (startDate && typeof settings.startDate === "string") startDate.value = settings.startDate;
    if (startTime && typeof settings.startTime === "string") startTime.value = settings.startTime;
    if (endDate && typeof settings.endDate === "string") endDate.value = settings.endDate;
    if (endTime && typeof settings.endTime === "string") endTime.value = settings.endTime;
    syncToggles({ activeToggle, durationToggle });
  }

  function collectSettings() {
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");

    return {
      active: Boolean(activeToggle?.checked),
      duration: Boolean(durationToggle?.checked),
      startDate: startDate?.value ?? "",
      startTime: startTime?.value ?? "",
      endDate: endDate?.value ?? "",
      endTime: endTime?.value ?? ""
    };
  }

  function initSettings() {
    const saveBtn = getEl("wheel-settings-save");
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");
    applySettings(loadSettings());
    activeToggle?.addEventListener("change", () => {
      syncToggles({ activeToggle, durationToggle });
    });
    durationToggle?.addEventListener("change", () => {
      syncToggles({ activeToggle, durationToggle });
    });
    [startDate, startTime, endDate, endTime].forEach(field => {
      field?.addEventListener("change", updateStatus);
      field?.addEventListener("input", updateStatus);
    });
    saveBtn?.addEventListener("click", () => {
      saveSettings(collectSettings());
    });
    updateStatus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSettings);
  } else {
    initSettings();
  }
})();
