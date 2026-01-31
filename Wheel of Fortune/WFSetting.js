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

  function getTehranDateTimeParts(date = new Date()) {
    try {
      const formatter = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Tehran",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false
      });
      const parts = formatter.formatToParts(date);
      const year = parts.find(p => p.type === "year")?.value ?? "";
      const month = parts.find(p => p.type === "month")?.value ?? "";
      const day = parts.find(p => p.type === "day")?.value ?? "";
      const hour = parts.find(p => p.type === "hour")?.value ?? "00";
      const minute = parts.find(p => p.type === "minute")?.value ?? "00";
      return {
        date: `${year}-${month}-${day}`,
        time: `${hour}:${minute}`
      };
    } catch {
      const fallback = new Date();
      const year = String(fallback.getFullYear());
      const month = String(fallback.getMonth() + 1).padStart(2, "0");
      const day = String(fallback.getDate()).padStart(2, "0");
      const hour = String(fallback.getHours()).padStart(2, "0");
      const minute = String(fallback.getMinutes()).padStart(2, "0");
      return {
        date: `${year}-${month}-${day}`,
        time: `${hour}:${minute}`
      };
    }
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

  function compareGregorianDates(a = "", b = "") {
    const left = (a || "").trim();
    const right = (b || "").trim();
    if (!left || !right) return null;
    if (left === right) return 0;
    return left > right ? 1 : -1;
  }

  function getCurrentLocalSeconds() {
    const now = new Date();
    return now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
  }

  function describeSameDayDurationState(startTime, endTime) {
    const nowSeconds = getCurrentTehranSeconds();
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

  function getCurrentTehranSeconds() {
    const parts = getTehranDateTimeParts();
    return parseTimeToSeconds(parts.time) ?? getCurrentLocalSeconds();
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
      const normalizedStartDate = (startDate?.value || "").trim();
      const normalizedEndDate = (endDate?.value || "").trim();
      const todayParts = getTehranDateTimeParts();
      const startRelation = compareGregorianDates(normalizedStartDate, todayParts.date);
      const endRelation = compareGregorianDates(normalizedEndDate, todayParts.date);

      if (!normalizedStartDate || !todayParts.date) {
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
