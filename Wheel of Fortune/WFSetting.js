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
  let hintEditor = null;
  let applyingHint = false;

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
    settingsCache.hintAlign = String(settingsCache.hintAlign ?? "right") || "right";
    hintDirty = false;
    if (activeToggle) activeToggle.checked = Boolean(settings.active);
    if (durationToggle) durationToggle.checked = Boolean(settings.duration);
    if (startDate && typeof settings.startDate === "string") startDate.value = settings.startDate;
    if (startTime && typeof settings.startTime === "string") startTime.value = settings.startTime;
    if (endDate && typeof settings.endDate === "string") endDate.value = settings.endDate;
    if (endTime && typeof settings.endTime === "string") endTime.value = settings.endTime;
    if (hintEditor) {
      applyingHint = true;
      const html = typeof settings.hintHtml === "string" && settings.hintHtml.trim() !== ""
        ? settings.hintHtml
        : (typeof settings.hint === "string" && settings.hint.trim() !== "")
          ? settings.hint
          : "شانس خودت رو امتحان کن و جایزه ببر";
      hintEditor.clipboard.dangerouslyPasteHTML(html);
      const align = String(settings.hintAlign ?? "").trim() || "right";
      hintEditor.formatLine(0, hintEditor.getLength(), { align }, "silent");
      setTimeout(() => {
        applyingHint = false;
        hintDirty = false;
        syncHintLockState();
      }, 0);
    } else if (hintText) {
      hintText.textContent = "شانس خودت رو امتحان کن و جایزه ببر";
    }
    if (hintText && textCard) {
      const align = String(settings.hintAlign ?? "").trim() || "right";
      hintText.dataset.align = align;
      hintText.style.textAlign = align;
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
    const hintHtml = hintEditor?.root?.innerHTML ?? hintText?.innerHTML ?? "";
    const hintPlain = hintEditor?.getText?.() ?? hintText?.textContent ?? "";
    const format = hintEditor?.getFormat?.() ?? {};

    return {
      active: Boolean(activeToggle?.checked),
      duration: Boolean(durationToggle?.checked),
      startDate: startDate?.value ?? "",
      startTime: startTime?.value ?? "",
      endDate: endDate?.value ?? "",
      endTime: endTime?.value ?? "",
      hint: hintPlain ?? "",
      hintHtml: hintHtml ?? "",
      hintAlign: (format.align ?? hintText?.dataset?.align ?? "right") || "right"
    };
  }

  function setOtherControlsDisabled(disabled) {
    const wheelTab = getEl("tab-wheel-of-fortune");
    if (!wheelTab) {
      return;
    }
    const textCard = getEl("wf-texts-card");
    if (textCard?.hasAttribute("hidden")) {
      return;
    }
    wheelTab.querySelectorAll("input, textarea, select, button").forEach(control => {
      if (control.closest('[data-wf-text-control="true"]')) {
        return;
      }
      control.disabled = disabled;
      control.classList.toggle("wf-action-disabled", disabled);
    });
  }

  function isHintDirty() {
    const textCard = getEl("wf-texts-card");
    if (textCard?.hasAttribute("hidden")) {
      return false;
    }
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
    const currentHtml = normalizeHtml(hintEditor?.root?.innerHTML ?? hintText.innerHTML ?? "", hintEditor?.getText?.() ?? hintText.textContent ?? "");
    const originalAlign = String(settingsCache.hintAlign ?? "right") || "right";
    const currentAlign = String(hintEditor?.getFormat?.().align ?? hintText.dataset.align ?? "right") || "right";
    return originalHtml !== currentHtml || originalAlign !== currentAlign;
  }

  function insertLineBreak() {
    if (!hintEditor) {
      return;
    }
    const range = hintEditor.getSelection(true);
    const index = range ? range.index : hintEditor.getLength();
    hintEditor.insertText(index, "\n", "user");
    hintEditor.setSelection(index + 1, 0, "silent");
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
    const textCard = getEl("wf-texts-card");
    const hintText = getEl("wheel-hint-text");
    const hintDisabled = textCard?.hasAttribute("hidden");
    if (hintText && hintDisabled) {
      hintText.setAttribute("contenteditable", "false");
      hintDirty = false;
    } else if (hintText && window.Quill) {
      hintEditor = new Quill(hintText, {
        theme: "snow",
        modules: {
          toolbar: {
            container: "#wheel-hint-toolbar",
            handlers: {
              br: insertLineBreak
            }
          }
        },
        formats: ["bold", "link", "align"],
        placeholder: "متن راهنما"
      });
      hintEditor.on("text-change", () => {
        if (applyingHint) {
          return;
        }
        hintDirty = true;
        syncHintLockState();
      });
      // Prevent autofocus on load; only focus when user clicks the editor.
      const editorRoot = hintEditor.root;
      const toolbarEl = getEl("wheel-hint-toolbar");
      const toolbarButtons = toolbarEl ? Array.from(toolbarEl.querySelectorAll("button")) : [];
      toolbarButtons.forEach((btn) => {
        btn.setAttribute("tabindex", "-1");
      });
      let pointerFocusAllowed = false;
      const allowPointerFocus = () => {
        pointerFocusAllowed = true;
        setTimeout(() => {
          pointerFocusAllowed = false;
        }, 300);
      };
      editorRoot.setAttribute("tabindex", "-1");
      editorRoot.addEventListener("pointerdown", allowPointerFocus);
      toolbarEl?.addEventListener("pointerdown", allowPointerFocus);
      editorRoot.addEventListener("focusin", (event) => {
        if (!pointerFocusAllowed) {
          editorRoot.blur();
          event.preventDefault();
          return;
        }
        editorRoot.setAttribute("tabindex", "0");
        toolbarButtons.forEach((btn) => {
          btn.setAttribute("tabindex", "0");
        });
      });
      editorRoot.addEventListener("blur", () => {
        editorRoot.setAttribute("tabindex", "-1");
        toolbarButtons.forEach((btn) => {
          btn.setAttribute("tabindex", "-1");
        });
      });
      document.addEventListener("keydown", (event) => {
        const target = event.target;
        if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable) {
          return;
        }
        if (document.activeElement === editorRoot && !pointerFocusAllowed) {
          editorRoot.blur();
        }
      });
    }
    applySettings(await loadSettings());
    hintDirty = false;
    if (!hintDisabled) {
      syncHintLockState();
    } else {
      setOtherControlsDisabled(false);
    }
    setTimeout(() => {
      if (!hintDirty) {
        syncHintLockState();
      }
    }, 0);
    if (hintEditor) {
      // Prevent auto-focus on load; focus only when user clicks the editor.
      hintEditor.blur();
      const toolbarEl = getEl("wheel-hint-toolbar");
      document.addEventListener("click", (event) => {
        const target = event.target;
        if (!target || !hintText) return;
        if (hintText.contains(target) || toolbarEl?.contains(target)) {
          return;
        }
        hintEditor?.blur();
      });
    }
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
    const wheelTab = getEl("tab-wheel-of-fortune");
    if (wheelTab && window.MutationObserver && !hintDisabled) {
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
