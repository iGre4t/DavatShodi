(() => {
  function getEl(id) {
    return document.getElementById(id);
  }

  const API_URL = "Wheel%20of%20Fortune/wf_store.php";

  async function loadSettings() {
    try {
      const response = await fetch(`${API_URL}?action=get_settings`, { credentials: "same-origin" });
      const payload = await response.json();
      if (payload?.status === "ok" && payload.data && typeof payload.data === "object") {
        return payload.data;
      }
    } catch {}
    return {};
  }

  async function saveSettings(settings) {
    try {
      await fetch(`${API_URL}?action=save_settings`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ settings })
      });
    } catch {}
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
    const setStatus = (label, tone) => {
      statusEl.textContent = label;
      statusEl.classList.remove(
        "wf-status--active",
        "wf-status--ended",
        "wf-status--upcoming",
        "wf-status--inactive"
      );
      if (tone) {
        statusEl.classList.add(`wf-status--${tone}`);
      }
    };
    const setPrizeAccess = (enabled) => {
      const prizeForm = document.getElementById("wf-prize-form");
      const prizeList = document.getElementById("wf-prize-list");
      const prizeSection = document.getElementById("wf-prize-section");
      const targets = [
        ...(prizeForm?.querySelectorAll("input, select, button") || []),
        ...(prizeList?.querySelectorAll("input, select, button") || [])
      ];
      targets.forEach(el => {
        el.disabled = !enabled;
      });
      if (prizeSection) {
        prizeSection.classList.toggle("wf-prize-disabled", !enabled);
      }
    };

    if (durationToggle?.checked) {
      const normalizedStartDate = (startDate?.value || "").trim();
      const normalizedEndDate = (endDate?.value || "").trim();
      const todayParts = getTehranDateTimeParts();
      const startRelation = compareGregorianDates(normalizedStartDate, todayParts.date);
      const endRelation = compareGregorianDates(normalizedEndDate, todayParts.date);

      if (!normalizedStartDate || !todayParts.date) {
        setStatus("Not Active", "inactive");
        setPrizeAccess(true);
        return;
      }

      if (startRelation === 1) {
        setStatus("Upcoming", "upcoming");
        setPrizeAccess(true);
        return;
      }

      if (endRelation !== null && endRelation === -1) {
        setStatus("Ended", "ended");
        setPrizeAccess(true);
        return;
      }

      if (startRelation === 0) {
        const state = describeSameDayDurationState(
          startTime?.value ?? "",
          endTime?.value ?? ""
        );
        if (state === "Ended") {
          setStatus(state, "ended");
          setPrizeAccess(true);
        } else if (state === "Active") {
          setStatus(state, "active");
          setPrizeAccess(false);
        } else {
          setStatus(state, "upcoming");
          setPrizeAccess(true);
        }
        return;
      }

      if (endRelation === 0) {
        const state = describeSameDayDurationState(
          startTime?.value ?? "",
          endTime?.value ?? ""
        );
        if (state === "Ended") {
          setStatus(state, "ended");
          setPrizeAccess(true);
        } else if (state === "Active") {
          setStatus(state, "active");
          setPrizeAccess(false);
        } else {
          setStatus(state, "upcoming");
          setPrizeAccess(true);
        }
        return;
      }

      setStatus("Active", "active");
      setPrizeAccess(false);
      return;
    }

    const isActive = Boolean(activeToggle?.checked);
    setStatus(isActive ? "Active" : "Not Active", isActive ? "active" : "inactive");
    setPrizeAccess(true);
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

  const settingsCache = {};
  let hintDirty = false;

  function applySettings(settings) {
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");
    const hintText = getEl("wheel-hint-text");
    const textCard = getEl("wf-texts-card");

    Object.assign(settingsCache, settings);
    hintDirty = false;
    if (activeToggle) activeToggle.checked = Boolean(settings.active);
    if (durationToggle) durationToggle.checked = Boolean(settings.duration);
    if (startDate && typeof settings.startDate === "string") startDate.value = settings.startDate;
    if (startTime && typeof settings.startTime === "string") startTime.value = settings.startTime;
    if (endDate && typeof settings.endDate === "string") endDate.value = settings.endDate;
    if (endTime && typeof settings.endTime === "string") endTime.value = settings.endTime;
    if (hintText && typeof settings.hintHtml === "string" && settings.hintHtml.trim() !== "") {
      hintText.innerHTML = settings.hintHtml;
    } else if (hintText && typeof settings.hint === "string" && settings.hint.trim() !== "") {
      hintText.textContent = settings.hint;
    } else if (hintText) {
      hintText.textContent = "شانس خودت رو امتحان کن و جایزه ببر";
    }
    if (hintText && textCard) {
      const align = String(settings.hintAlign ?? "").trim() || "right";
      hintText.dataset.align = align;
      hintText.style.textAlign = align;
      textCard.querySelectorAll("[data-align]").forEach(btn => {
        btn.classList.toggle("active", btn.dataset.align === align);
      });
    }
    syncToggles({ activeToggle, durationToggle });
  }

  function collectSettings() {
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");
    const hintText = getEl("wheel-hint-text");

    return {
      active: Boolean(activeToggle?.checked),
      duration: Boolean(durationToggle?.checked),
      startDate: startDate?.value ?? "",
      startTime: startTime?.value ?? "",
      endDate: endDate?.value ?? "",
      endTime: endTime?.value ?? "",
      hint: hintText?.textContent ?? "",
      hintHtml: hintText?.innerHTML ?? "",
      hintAlign: hintText?.dataset?.align ?? "right"
    };
  }

  function setOtherControlsDisabled(disabled) {
    const wheelTab = getEl("tab-wheel-of-fortune");
    if (!wheelTab) {
      return;
    }
    const textCard = getEl("wf-texts-card");
    const allowed = new Set(
      textCard ? Array.from(textCard.querySelectorAll("input, textarea, select, button")) : []
    );
    wheelTab.querySelectorAll("input, textarea, select, button").forEach(control => {
      if (allowed.has(control) || control.dataset.wfTextControl === "true") {
        return;
      }
      control.disabled = disabled;
      control.classList.toggle("wf-action-disabled", disabled);
    });
  }

  function isHintDirty() {
    if (hintDirty) {
      return true;
    }
    const hintText = getEl("wheel-hint-text");
    if (!hintText) {
      return false;
    }
    const normalize = (value) => String(value ?? "").replace(/\s+/g, " ").trim();
    const normalizeHtml = (value, textValue) => {
      const text = String(textValue ?? "").trim();
      if (!text) {
        return "";
      }
      return normalize(String(value ?? "")
        .replace(/<br\s*\/?>/gi, "")
        .replace(/&nbsp;/gi, " "));
    };
    const originalHtml = normalizeHtml(settingsCache.hintHtml ?? settingsCache.hint ?? "", settingsCache.hint ?? "");
    const currentHtml = normalizeHtml(hintText.innerHTML ?? "", hintText.textContent ?? "");
    const originalAlign = String(settingsCache.hintAlign ?? "right");
    const currentAlign = String(hintText.dataset.align ?? "right");
    return originalHtml !== currentHtml || originalAlign !== currentAlign;
  }

  function syncHintLockState() {
    setOtherControlsDisabled(isHintDirty());
  }

  async function initSettings() {
    const saveBtn = getEl("wheel-settings-save");
    const saveTextsBtn = getEl("wheel-texts-save");
    const activeToggle = getEl("wheel-active-toggle");
    const durationToggle = getEl("wheel-duration-toggle");
    const startDate = getEl("wheel-duration-start");
    const startTime = getEl("wheel-duration-start-time");
    const endDate = getEl("wheel-duration-end");
    const endTime = getEl("wheel-duration-end-time");
    applySettings(await loadSettings());
    syncHintLockState();
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
    const hintText = getEl("wheel-hint-text");
    let savedRange = null;
    const saveSelection = () => {
      if (!hintText) return;
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) return;
      const range = selection.getRangeAt(0);
      if (hintText.contains(range.startContainer)) {
        savedRange = range;
      }
    };
    const restoreSelection = () => {
      if (!savedRange) return;
      const selection = window.getSelection();
      if (!selection) return;
      selection.removeAllRanges();
      selection.addRange(savedRange);
    };
    hintText?.addEventListener("input", () => {
      hintDirty = true;
      saveSelection();
      syncHintLockState();
    });
    hintText?.addEventListener("change", syncHintLockState);
    hintText?.addEventListener("keyup", saveSelection);
    hintText?.addEventListener("mouseup", saveSelection);
    hintText?.addEventListener("focus", saveSelection);
    if (hintText && !hintText.dataset.placeholder) {
      hintText.dataset.placeholder = "متن راهنما";
    }
    const textCard = getEl("wf-texts-card");
    textCard?.addEventListener("mousedown", event => {
      const button = event.target.closest("button");
      if (button) {
        event.preventDefault();
      }
    });
    textCard?.addEventListener("click", event => {
      const button = event.target.closest("button");
      if (!button || !hintText) {
        return;
      }
      hintText.focus();
      restoreSelection();
      const align = button.dataset.align;
      const action = button.dataset.action;
      if (align) {
        hintText.dataset.align = align;
        hintText.style.textAlign = align;
        textCard.querySelectorAll("[data-align]").forEach(btn => {
          btn.classList.toggle("active", btn.dataset.align === align);
        });
        syncHintLockState();
        return;
      }
      if (action === "bold") {
        document.execCommand("bold");
        syncHintLockState();
        return;
      }
      if (action === "link") {
        const url = window.prompt("لینک را وارد کنید");
        if (url) {
          document.execCommand("createLink", false, url);
        }
        syncHintLockState();
      }
    });
    const wheelTab = getEl("tab-wheel-of-fortune");
    if (wheelTab && window.MutationObserver) {
      const observer = new MutationObserver(() => {
        if (isHintDirty()) {
          setOtherControlsDisabled(true);
        }
      });
      observer.observe(wheelTab, { subtree: true, childList: true });
    }
    saveBtn?.addEventListener("click", async () => {
      await saveSettings(collectSettings());
    });
    saveTextsBtn?.addEventListener("click", async () => {
      const settings = collectSettings();
      await saveSettings(settings);
      settingsCache.hint = String(settings.hint ?? "");
      settingsCache.hintHtml = String(settings.hintHtml ?? "");
      settingsCache.hintAlign = String(settings.hintAlign ?? "right");
      hintDirty = false;
      syncHintLockState();
    });
    updateStatus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSettings);
  } else {
    initSettings();
  }
})();
