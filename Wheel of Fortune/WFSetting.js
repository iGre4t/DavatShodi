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

  function getJalaliDateString(date = new Date()) {
    try {
      const formatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit"
      });
      const parts = formatter.formatToParts(date);
      const year = parts.find(p => p.type === "year")?.value ?? "";
      const month = parts.find(p => p.type === "month")?.value ?? "";
      const day = parts.find(p => p.type === "day")?.value ?? "";
      return `${year}/${month}/${day}`;
    } catch {
      return "";
    }
  }

  function getTimeString(date = new Date()) {
    const hours = String(date.getHours()).padStart(2, "0");
    const minutes = String(date.getMinutes()).padStart(2, "0");
    return `${hours}:${minutes}`;
  }

  function compareDateTime(aDate, aTime, bDate, bTime) {
    if (!aDate || !bDate) return null;
    const left = `${aDate} ${aTime || "00:00"}`;
    const right = `${bDate} ${bTime || "00:00"}`;
    if (left === right) return 0;
    return left < right ? -1 : 1;
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
      const nowDate = getJalaliDateString();
      const nowTime = getTimeString();
      const startCmp = compareDateTime(nowDate, nowTime, startDate?.value ?? "", startTime?.value ?? "");
      const endCmp = compareDateTime(nowDate, nowTime, endDate?.value ?? "", endTime?.value ?? "");

      if (startCmp === null || endCmp === null) {
        statusEl.textContent = "Upcoming";
        return;
      }
      if (startCmp < 0) {
        statusEl.textContent = "Upcoming";
      } else if (endCmp > 0) {
        statusEl.textContent = "Ended";
      } else {
        statusEl.textContent = "Active";
      }
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
