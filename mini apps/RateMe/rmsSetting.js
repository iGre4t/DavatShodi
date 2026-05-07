(() => {
  function getEl(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  const API_URL = "mini%20apps/RateMe/rms_store.php";
  const tcShellEl = document.querySelector(".rms-shell");
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.rmsCsrf || "").trim()
    : "";

  async function requestStoreGet(action, params = {}) {
    const url = new URL(API_URL, window.location.href);
    url.searchParams.set("action", String(action || "").trim());
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined) return;
      url.searchParams.set(String(key), String(value));
    });
    const response = await fetch(url.toString(), { credentials: "same-origin" });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.status !== "ok") {
      throw new Error(payload?.message || "Request failed.");
    }
    return payload?.data && typeof payload.data === "object" ? payload.data : {};
  }

  async function requestStorePost(action, body = {}) {
    const response = await fetch(`${API_URL}?action=${encodeURIComponent(String(action || "").trim())}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify({ ...body, csrf: csrfToken })
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.status !== "ok") {
      throw new Error(payload?.message || "Request failed.");
    }
    return payload;
  }

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
        body: JSON.stringify({ settings, csrf: csrfToken })
      });
    } catch {}
  }

  function initAssignAdmin() {
    const workIdInput = getEl("tc-admin-workid");
    const searchBtn = getEl("tc-admin-search-btn");
    const searchResultEl = getEl("tc-admin-search-result");
    const statusEl = getEl("tc-admin-status");
    const adminListEl = getEl("tc-admin-list");
    if (
      !(workIdInput instanceof HTMLInputElement) ||
      !(searchBtn instanceof HTMLButtonElement) ||
      !(searchResultEl instanceof HTMLElement) ||
      !(statusEl instanceof HTMLElement) ||
      !(adminListEl instanceof HTMLElement)
    ) {
      return;
    }

    let currentInvitee = null;

    const setStatus = (message, isError = false) => {
      statusEl.textContent = String(message || "").trim();
      statusEl.style.color = isError ? "#d1434a" : "";
    };

    const renderAdminList = (admins) => {
      const rows = Array.isArray(admins) ? admins : [];
      if (!rows.length) {
        adminListEl.innerHTML = '<tr><td colspan="4" class="muted">No admins assigned yet.</td></tr>';
        return;
      }
      adminListEl.innerHTML = rows.map((item) => {
        const firstName = String(item?.firstName || "").trim();
        const lastName = String(item?.lastName || "").trim();
        const fullName = `${firstName} ${lastName}`.trim() || String(item?.fullName || "").trim() || "-";
        const workId = String(item?.workId || "").trim();
        const phone = String(item?.phone || "").trim() || "-";
        return `<tr data-admin-work-id="${escapeHtml(workId)}">
          <td>${escapeHtml(fullName)}</td>
          <td><code>${escapeHtml(workId)}</code></td>
          <td>${escapeHtml(phone)}</td>
          <td>
            <div class="tc-admin-action">
              <button type="button" class="btn ghost" data-action="remove-admin" data-work-id="${escapeHtml(workId)}">Remove</button>
            </div>
          </td>
        </tr>`;
      }).join("");
    };

    const renderSearchResult = (invitee) => {
      currentInvitee = invitee && typeof invitee === "object" ? invitee : null;
      if (!currentInvitee) {
        searchResultEl.classList.add("hidden");
        searchResultEl.innerHTML = "";
        return;
      }
      const firstName = String(currentInvitee.firstName || "").trim();
      const lastName = String(currentInvitee.lastName || "").trim();
      const fullName = `${firstName} ${lastName}`.trim() || String(currentInvitee.fullName || "").trim() || "-";
      const workId = String(currentInvitee.workId || "").trim();
      const phone = String(currentInvitee.phone || "").trim() || "-";
      const isAdmin = Boolean(currentInvitee.isAdmin);
      const buttonLabel = isAdmin ? "Already Admin" : "Add as Admin";
      const buttonDisabled = isAdmin ? " disabled" : "";
      searchResultEl.innerHTML = `<div class="tc-admin-search-meta">
          <strong>${escapeHtml(fullName)}</strong>
          <div><code>${escapeHtml(workId)}</code></div>
          <div class="muted small">${escapeHtml(phone)}</div>
        </div>
        <button type="button" class="btn primary"${buttonDisabled} data-action="assign-admin" data-work-id="${escapeHtml(workId)}">${escapeHtml(buttonLabel)}</button>`;
      searchResultEl.classList.remove("hidden");
    };

    const loadAdmins = async () => {
      const data = await requestStoreGet("get_admin_assignments");
      renderAdminList(data?.admins || []);
    };

    const applyAssignment = async (workId, isAdmin) => {
      const normalizedWorkId = String(workId || "").trim();
      if (!normalizedWorkId) return;
      setStatus("Saving...");
      const payload = await requestStorePost("set_admin_assignment", {
        workId: normalizedWorkId,
        isAdmin: Boolean(isAdmin)
      });
      const data = payload?.data && typeof payload.data === "object" ? payload.data : {};
      renderAdminList(data?.admins || []);
      const invitee = data?.invitee && typeof data.invitee === "object" ? data.invitee : null;
      if (invitee && String(invitee.workId || "").trim() === normalizedWorkId) {
        renderSearchResult(invitee);
      }
      setStatus(payload?.message || (isAdmin ? "Admin assigned." : "Admin removed."));
    };

    const searchInvitee = async () => {
      const workId = String(workIdInput.value || "").trim();
      if (!workId) {
        setStatus("Please enter Work ID.", true);
        renderSearchResult(null);
        return;
      }
      setStatus("Searching...");
      try {
        const data = await requestStoreGet("search_invitee_admin", { work_id: workId });
        const invitee = data?.invitee && typeof data.invitee === "object" ? data.invitee : null;
        if (!invitee) {
          renderSearchResult(null);
          setStatus("User not found.", true);
          return;
        }
        renderSearchResult(invitee);
        setStatus("User found.");
      } catch (error) {
        renderSearchResult(null);
        setStatus(error?.message || "Search failed.", true);
      }
    };

    searchBtn.addEventListener("click", () => {
      void searchInvitee();
    });

    workIdInput.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;
      event.preventDefault();
      void searchInvitee();
    });

    searchResultEl.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const button = target.closest('[data-action="assign-admin"]');
      if (!(button instanceof HTMLButtonElement)) return;
      const workId = String(button.getAttribute("data-work-id") || "").trim();
      if (!workId) return;
      button.disabled = true;
      void applyAssignment(workId, true).catch((error) => {
        setStatus(error?.message || "Failed to assign admin.", true);
      }).finally(() => {
        button.disabled = false;
      });
    });

    adminListEl.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const button = target.closest('[data-action="remove-admin"]');
      if (!(button instanceof HTMLButtonElement)) return;
      const workId = String(button.getAttribute("data-work-id") || "").trim();
      if (!workId) return;
      button.disabled = true;
      void applyAssignment(workId, false).catch((error) => {
        setStatus(error?.message || "Failed to remove admin.", true);
      }).finally(() => {
        button.disabled = false;
      });
    });

    void loadAdmins().catch((error) => {
      renderAdminList([]);
      setStatus(error?.message || "Failed to load admins.", true);
    });
  }

  function setDurationFieldsEnabled(enabled) {
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

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
    const statusEl = getEl("tc-status-text");
    const activeToggle = getEl("tc-active-toggle");
    const durationToggle = getEl("tc-duration-toggle");
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

    if (!statusEl) return;
    const setStatus = (label, tone) => {
      statusEl.textContent = label;
      statusEl.classList.remove(
        "tc-status--active",
        "tc-status--ended",
        "tc-status--upcoming",
        "tc-status--inactive"
      );
      if (tone) {
        statusEl.classList.add(`tc-status--${tone}`);
      }
    };
    const setPrizeAccess = (enabled) => {
      const prizeForm = document.getElementById("tc-prize-form");
      const prizeList = document.getElementById("tc-prize-list");
      const prizeSection = document.getElementById("tc-prize-section");
      const targets = [
        ...(prizeForm?.querySelectorAll("input, select, button") || []),
        ...(prizeList?.querySelectorAll("input, select, button") || [])
      ];
      targets.forEach(el => {
        el.disabled = !enabled;
      });
      if (prizeSection) {
        prizeSection.classList.toggle("tc-prize-disabled", !enabled);
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

  function applySettings(settings) {
    const activeToggle = getEl("tc-active-toggle");
    const durationToggle = getEl("tc-duration-toggle");
    const maintenanceToggle = getEl("tc-maintenance-toggle");
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

    Object.assign(settingsCache, settings);
    if (activeToggle) activeToggle.checked = Boolean(settings.active);
    if (durationToggle) durationToggle.checked = Boolean(settings.duration);
    if (maintenanceToggle) maintenanceToggle.checked = Boolean(settings.maintenanceMode);
    if (startDate && typeof settings.startDate === "string") startDate.value = settings.startDate;
    if (startTime && typeof settings.startTime === "string") startTime.value = settings.startTime;
    if (endDate && typeof settings.endDate === "string") endDate.value = settings.endDate;
    if (endTime && typeof settings.endTime === "string") endTime.value = settings.endTime;
    syncToggles({ activeToggle, durationToggle });
  }

  function collectSettings() {
    const activeToggle = getEl("tc-active-toggle");
    const durationToggle = getEl("tc-duration-toggle");
    const maintenanceToggle = getEl("tc-maintenance-toggle");
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

    return {
      active: Boolean(activeToggle?.checked),
      duration: Boolean(durationToggle?.checked),
      maintenanceMode: Boolean(maintenanceToggle?.checked),
      startDate: startDate?.value ?? "",
      startTime: startTime?.value ?? "",
      endDate: endDate?.value ?? "",
      endTime: endTime?.value ?? ""
    };
  }

  async function initSettings() {
    const saveBtn = getEl("tc-settings-save");
    const activeToggle = getEl("tc-active-toggle");
    const durationToggle = getEl("tc-duration-toggle");
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

    applySettings(await loadSettings());
    initAssignAdmin();
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
    saveBtn?.addEventListener("click", async () => {
      await saveSettings(collectSettings());
      try {
        localStorage.setItem("tcSettingsUpdated", String(Date.now()));
      } catch {}
    });
    updateStatus();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initSettings);
  } else {
    initSettings();
  }
})();


