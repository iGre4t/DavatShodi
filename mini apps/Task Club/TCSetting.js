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

  const API_URL = "mini%20apps/Task%20Club/tc_store.php";
  const tcShellEl = document.querySelector(".tc-shell");
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || "").trim()
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
        adminListEl.innerHTML = '<tr><td colspan="4" class="muted">هنوز ادمینی تعیین نشده است.</td></tr>';
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
              <button type="button" class="btn ghost" data-action="remove-admin" data-work-id="${escapeHtml(workId)}">حذف</button>
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
      const buttonLabel = isAdmin ? "ادمین است" : "افزودن به عنوان ادمین";
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
      setStatus("در حال ذخیره...");
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
      setStatus(payload?.message || (isAdmin ? "ادمین تعیین شد." : "ادمین حذف شد."));
    };

    const searchInvitee = async () => {
      const workId = String(workIdInput.value || "").trim();
      if (!workId) {
        setStatus("لطفا شناسه کاری را وارد کنید.", true);
        renderSearchResult(null);
        return;
      }
      setStatus("در حال جستجو...");
      try {
        const data = await requestStoreGet("search_invitee_admin", { work_id: workId });
        const invitee = data?.invitee && typeof data.invitee === "object" ? data.invitee : null;
        if (!invitee) {
          renderSearchResult(null);
          setStatus("کاربر پیدا نشد.", true);
          return;
        }
        renderSearchResult(invitee);
        setStatus("کاربر پیدا شد.");
      } catch (error) {
        renderSearchResult(null);
        setStatus(error?.message || "جستجو ناموفق بود.", true);
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
        setStatus(error?.message || "تعیین ادمین ناموفق بود.", true);
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
        setStatus(error?.message || "حذف ادمین ناموفق بود.", true);
      }).finally(() => {
        button.disabled = false;
      });
    });

    void loadAdmins().catch((error) => {
      renderAdminList([]);
      setStatus(error?.message || "بارگذاری ادمین‌ها ناموفق بود.", true);
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
      return "پایان یافته";
    }
    if (startSeconds !== null && nowSeconds >= startSeconds) {
      return "فعال";
    }
    if (startSeconds !== null && nowSeconds < startSeconds) {
      return "در انتظار شروع";
    }
    return "در انتظار شروع";
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
        setStatus("غیرفعال", "inactive");
        setPrizeAccess(true);
        return;
      }

      if (startRelation === 1) {
        setStatus("در انتظار شروع", "upcoming");
        setPrizeAccess(true);
        return;
      }

      if (endRelation !== null && endRelation === -1) {
        setStatus("پایان یافته", "ended");
        setPrizeAccess(true);
        return;
      }

      if (startRelation === 0) {
        const state = describeSameDayDurationState(
          startTime?.value ?? "",
          endTime?.value ?? ""
        );
        if (state === "پایان یافته") {
          setStatus(state, "ended");
          setPrizeAccess(true);
        } else if (state === "فعال") {
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
        if (state === "پایان یافته") {
          setStatus(state, "ended");
          setPrizeAccess(true);
        } else if (state === "فعال") {
          setStatus(state, "active");
          setPrizeAccess(false);
        } else {
          setStatus(state, "upcoming");
          setPrizeAccess(true);
        }
        return;
      }

      setStatus("فعال", "active");
      setPrizeAccess(false);
      return;
    }

    const isActive = Boolean(activeToggle?.checked);
    setStatus(isActive ? "فعال" : "غیرفعال", isActive ? "active" : "inactive");
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

  function normalizeLandingSection(section, index = 0, options = {}) {
    if (!section || typeof section !== "object") {
      return null;
    }
    const title = String(section.title ?? "").trim();
    const text = String(section.text ?? section.html ?? "").replace(/\r\n?/g, "\n").trim();
    if (!options.keepEmpty && !title && !text) {
      return null;
    }
    return {
      id: String(section.id ?? `landing_section_${Date.now()}_${index}`).trim() || `landing_section_${Date.now()}_${index}`,
      title,
      text
    };
  }

  function normalizeLandingPayload(raw) {
    const source = raw && typeof raw === "object" ? raw : {};
    const sections = Array.isArray(source.sections)
      ? source.sections.map(normalizeLandingSection).filter(Boolean)
      : [];
    return {
      title: String(source.title ?? "").trim(),
      subtitle: String(source.subtitle ?? "").replace(/\r\n?/g, "\n").trim(),
      sections
    };
  }

  function landingSectionTemplate(section, index, total) {
    const safe = normalizeLandingSection(section, index, { keepEmpty: true }) || { id: `landing_section_${Date.now()}_${index}`, title: "", text: "" };
    return `
      <div class="tc-landing-section" data-landing-section-id="${escapeHtml(safe.id)}">
        <div class="tc-landing-section-toolbar">
          <strong>Section ${index + 1}</strong>
          <div class="tc-landing-actions">
            <button type="button" class="btn ghost" data-landing-action="move-up" ${index <= 0 ? "disabled" : ""}>Up</button>
            <button type="button" class="btn ghost" data-landing-action="move-down" ${index >= total - 1 ? "disabled" : ""}>Down</button>
            <button type="button" class="btn ghost" data-landing-action="remove">Remove</button>
          </div>
        </div>
        <label class="field full">
          <span>Section title</span>
          <input type="text" data-landing-field="title" value="${escapeHtml(safe.title)}" autocomplete="off" />
        </label>
        <label class="field full">
          <span>Section text</span>
          <div class="tc-rich-text-tools" aria-label="Section text tools">
            <button type="button" class="btn ghost" data-landing-format="bold">B</button>
            <button type="button" class="btn ghost" data-landing-format="list">List</button>
            <button type="button" class="btn ghost" data-landing-format="header">H</button>
            <button type="button" class="btn ghost" data-landing-format="link">Link</button>
          </div>
          <textarea data-landing-field="text" rows="7">${escapeHtml(safe.text)}</textarea>
        </label>
      </div>
    `;
  }

  function getLandingEditorElements() {
    const titleInput = getEl("tc-landing-title");
    const subtitleInput = getEl("tc-landing-subtitle");
    const sectionsEl = getEl("tc-landing-sections");
    const addButton = getEl("tc-landing-add-section");
    const saveButton = getEl("tc-landing-save");
    const statusEl = getEl("tc-landing-status");
    if (
      !(titleInput instanceof HTMLInputElement) ||
      !(subtitleInput instanceof HTMLTextAreaElement) ||
      !(sectionsEl instanceof HTMLElement)
    ) {
      return null;
    }
    return {
      titleInput,
      subtitleInput,
      sectionsEl,
      addButton: addButton instanceof HTMLButtonElement ? addButton : null,
      saveButton: saveButton instanceof HTMLButtonElement ? saveButton : null,
      statusEl: statusEl instanceof HTMLElement ? statusEl : null
    };
  }

  function setLandingStatus(message, isError = false) {
    const statusEl = getEl("tc-landing-status");
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = String(message || "").trim();
    statusEl.style.color = isError ? "#d1434a" : "";
  }

  function renderLandingSections(sections) {
    const elements = getLandingEditorElements();
    if (!elements) return;
    const items = Array.isArray(sections)
      ? sections.map((section, index) => normalizeLandingSection(section, index, { keepEmpty: true })).filter(Boolean)
      : [];
    elements.sectionsEl.innerHTML = items
      .map((section, index) => landingSectionTemplate(section, index, items.length))
      .join("");
  }

  function collectLandingFromDom(options = {}) {
    const elements = getLandingEditorElements();
    if (!elements) return null;
    const sections = Array.from(elements.sectionsEl.querySelectorAll("[data-landing-section-id]"))
      .map((sectionEl, index) => {
        if (!(sectionEl instanceof HTMLElement)) return null;
        const titleField = sectionEl.querySelector('[data-landing-field="title"]');
        const textField = sectionEl.querySelector('[data-landing-field="text"]');
        return normalizeLandingSection({
          id: sectionEl.dataset.landingSectionId || `landing_section_${Date.now()}_${index}`,
          title: titleField instanceof HTMLInputElement ? titleField.value : "",
          text: textField instanceof HTMLTextAreaElement ? textField.value : ""
        }, index, options);
      })
      .filter(Boolean);
    return {
      title: elements.titleInput.value,
      subtitle: elements.subtitleInput.value,
      sections
    };
  }

  function applyLinkShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);
    const selectedText = value.slice(start, end) || "link text";
    const markup = `<a href="">${selectedText}</a>`;
    const labelStart = start + '<a href="">'.length;
    replaceTextareaRange(textarea, start, end, markup, labelStart, labelStart + selectedText.length);
  }

  function applyLandingFormat(textarea, format) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    if (format === "bold") {
      applyBoldShortcutToTextarea(textarea);
      return;
    }
    if (format === "list") {
      applyListShortcutToTextarea(textarea);
      return;
    }
    if (format === "header") {
      applyHeaderShortcutToTextarea(textarea);
      return;
    }
    if (format === "link") {
      applyLinkShortcutToTextarea(textarea);
    }
  }

  function initLandingEditor(initialSettings = {}) {
    const elements = getLandingEditorElements();
    if (!elements || elements.sectionsEl.dataset.landingReady === "1") return;
    elements.sectionsEl.dataset.landingReady = "1";
    const initialLanding = normalizeLandingPayload(initialSettings?.landing || {});
    elements.titleInput.value = initialLanding.title;
    elements.subtitleInput.value = initialLanding.subtitle;
    renderLandingSections(initialLanding.sections);

    elements.addButton?.addEventListener("click", () => {
      const current = collectLandingFromDom({ keepEmpty: true }) || { sections: [] };
      current.sections.push({
        id: `landing_section_${Date.now()}`,
        title: "",
        text: ""
      });
      renderLandingSections(current.sections);
      setLandingStatus("");
    });

    elements.sectionsEl.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Element)) return;
      const formatButton = target.closest("[data-landing-format]");
      if (formatButton instanceof HTMLElement) {
        const sectionEl = formatButton.closest("[data-landing-section-id]");
        const textarea = sectionEl?.querySelector('[data-landing-field="text"]');
        if (textarea instanceof HTMLTextAreaElement) {
          textarea.focus();
          applyLandingFormat(textarea, String(formatButton.getAttribute("data-landing-format") || ""));
          setLandingStatus("");
        }
        return;
      }
      const actionButton = target.closest("[data-landing-action]");
      if (!(actionButton instanceof HTMLElement)) return;
      const action = String(actionButton.getAttribute("data-landing-action") || "");
      const sectionEl = actionButton.closest("[data-landing-section-id]");
      const current = collectLandingFromDom({ keepEmpty: true });
      if (!sectionEl || !current) return;
      const index = Array.from(elements.sectionsEl.querySelectorAll("[data-landing-section-id]")).indexOf(sectionEl);
      if (index < 0) return;
      if (action === "remove") {
        current.sections.splice(index, 1);
      } else if (action === "move-up" && index > 0) {
        [current.sections[index - 1], current.sections[index]] = [current.sections[index], current.sections[index - 1]];
      } else if (action === "move-down" && index < current.sections.length - 1) {
        [current.sections[index + 1], current.sections[index]] = [current.sections[index], current.sections[index + 1]];
      }
      renderLandingSections(current.sections);
      setLandingStatus("");
    });

    elements.sectionsEl.addEventListener("keydown", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLTextAreaElement) || !target.matches('[data-landing-field="text"]')) return;
      const hasCtrl = event.ctrlKey || event.metaKey;
      if (hasCtrl && !event.altKey && isShortcutLetterKey(event, "B")) {
        event.preventDefault();
        applyBoldShortcutToTextarea(target);
        return;
      }
      if (hasCtrl && !event.altKey && isShortcutLetterKey(event, "L")) {
        event.preventDefault();
        applyListShortcutToTextarea(target);
        return;
      }
      const isOneKey = event.key === "1" || event.code === "Digit1" || event.code === "Numpad1";
      if (event.ctrlKey && event.altKey && isOneKey) {
        event.preventDefault();
        applyHeaderShortcutToTextarea(target);
      }
    });

    elements.saveButton?.addEventListener("click", async () => {
      const landing = collectLandingFromDom();
      if (!landing) return;
      elements.saveButton.disabled = true;
      setLandingStatus("Saving...");
      try {
        await requestStorePost("save_settings", { settings: { landing } });
        Object.assign(settingsCache, { landing });
        setLandingStatus("Saved.");
      } catch (error) {
        setLandingStatus(error?.message || "Failed to save landing.", true);
      } finally {
        elements.saveButton.disabled = false;
      }
    });
  }

  function setRewardGuideStatus(message, isError = false) {
    const statusEl = getEl("tc-reward-guide-status");
    if (!(statusEl instanceof HTMLElement)) return;
    statusEl.textContent = String(message || "").trim();
    statusEl.style.color = isError ? "#d1434a" : "";
  }

  function isShortcutLetterKey(event, letter) {
    const token = String(letter || "").trim().toUpperCase();
    if (!/^[A-Z]$/.test(token)) return false;
    const byKey = String(event?.key || "").toLowerCase() === token.toLowerCase();
    const byCode = String(event?.code || "").toUpperCase() === `KEY${token}`;
    return byKey || byCode;
  }

  function replaceTextareaRange(textarea, start, end, replacement, selectionStart = null, selectionEnd = null) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const safeStart = Math.max(0, Math.min(value.length, Number.isFinite(start) ? start : 0));
    const safeEnd = Math.max(safeStart, Math.min(value.length, Number.isFinite(end) ? end : safeStart));
    const nextValue = `${value.slice(0, safeStart)}${replacement}${value.slice(safeEnd)}`;
    textarea.value = nextValue;
    const defaultCaret = safeStart + String(replacement || "").length;
    const nextSelectionStart = Number.isFinite(selectionStart) ? Math.max(0, Math.min(nextValue.length, selectionStart)) : defaultCaret;
    const nextSelectionEnd = Number.isFinite(selectionEnd) ? Math.max(nextSelectionStart, Math.min(nextValue.length, selectionEnd)) : nextSelectionStart;
    textarea.selectionStart = nextSelectionStart;
    textarea.selectionEnd = nextSelectionEnd;
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function applyBoldShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);
    if (start === end) {
      const wrapped = "<b></b>";
      const caret = start + 3;
      replaceTextareaRange(textarea, start, end, wrapped, caret, caret);
      return;
    }
    const selectedText = value.slice(start, end);
    const wrapped = `<b>${selectedText}</b>`;
    replaceTextareaRange(textarea, start, end, wrapped, start + 3, start + 3 + selectedText.length);
  }

  function applyListShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const rawStart = Math.max(0, textarea.selectionStart ?? 0);
    const rawEnd = Math.max(rawStart, textarea.selectionEnd ?? rawStart);
    const lineStart = value.lastIndexOf("\n", Math.max(0, rawStart - 1)) + 1;
    const lineEndIndex = value.indexOf("\n", rawEnd);
    const lineEnd = lineEndIndex >= 0 ? lineEndIndex : value.length;
    const selectedBlock = value.slice(lineStart, lineEnd);
    const lines = selectedBlock
      .split("\n")
      .map((line) => String(line || "").trim())
      .filter((line) => line !== "");
    if (!lines.length) return;
    const listBody = lines.map((line) => `  <li>${line}</li>`).join("\n");
    replaceTextareaRange(textarea, lineStart, lineEnd, `<ul>\n${listBody}\n</ul>`);
  }

  function applyHeaderShortcutToTextarea(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const value = String(textarea.value || "");
    const start = Math.max(0, textarea.selectionStart ?? 0);
    const end = Math.max(start, textarea.selectionEnd ?? start);
    const lineStart = value.lastIndexOf("\n", start - 1) + 1;
    const lineEndIndex = value.indexOf("\n", end);
    const lineEnd = lineEndIndex >= 0 ? lineEndIndex : value.length;
    const line = value.slice(lineStart, lineEnd);
    const lineTrimmedLeft = line.replace(/^\s+/, "");
    const leftPaddingLength = line.length - lineTrimmedLeft.length;
    const leftPadding = line.slice(0, leftPaddingLength);
    const raw = lineTrimmedLeft.replace(/^#\s+/, "");
    const nextLine = `${leftPadding}# ${raw}`;
    textarea.value = `${value.slice(0, lineStart)}${nextLine}${value.slice(lineEnd)}`;
    const caret = lineStart + nextLine.length;
    textarea.selectionStart = caret;
    textarea.selectionEnd = caret;
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function applyRewardGuideEditorShortcut(event) {
    const target = event.target;
    if (!(target instanceof HTMLTextAreaElement) || target.id !== "tc-reward-guide-text") return;
    const hasCtrl = event.ctrlKey || event.metaKey;
    if (hasCtrl && !event.altKey && isShortcutLetterKey(event, "B")) {
      event.preventDefault();
      event.stopPropagation();
      applyBoldShortcutToTextarea(target);
      return;
    }
    if (hasCtrl && !event.altKey && isShortcutLetterKey(event, "L")) {
      event.preventDefault();
      event.stopPropagation();
      applyListShortcutToTextarea(target);
      return;
    }
    const isOneKey = event.key === "1" || event.code === "Digit1" || event.code === "Numpad1";
    if (event.ctrlKey && event.altKey && isOneKey) {
      event.preventDefault();
      applyHeaderShortcutToTextarea(target);
    }
  }

  function initControlPanelTabs() {
    const pane = document.querySelector('.sub-pane[data-pane="tc-main"]');
    if (!(pane instanceof HTMLElement)) return;
    if (pane.dataset.controlPanelTabsInitialized === "1") return;
    pane.dataset.controlPanelTabsInitialized = "1";

    const triggers = Array.from(pane.querySelectorAll("[data-tc-control-panel-trigger]"));
    const sections = Array.from(pane.querySelectorAll("[data-tc-control-panel-section]"));
    if (!triggers.length || !sections.length) return;

    const activate = (key) => {
      const fallbackKey = String(triggers[0]?.getAttribute("data-tc-control-panel-trigger") || "general").trim() || "general";
      const activeKey = String(key || fallbackKey).trim() || fallbackKey;
      triggers.forEach((trigger) => {
        if (!(trigger instanceof HTMLElement)) return;
        const isActive = String(trigger.getAttribute("data-tc-control-panel-trigger") || "") === activeKey;
        trigger.classList.toggle("active", isActive);
        trigger.setAttribute("aria-selected", isActive ? "true" : "false");
      });
      sections.forEach((section) => {
        if (!(section instanceof HTMLElement)) return;
        section.hidden = String(section.getAttribute("data-tc-control-panel-section") || "") !== activeKey;
      });
    };

    triggers.forEach((trigger) => {
      trigger.addEventListener("click", () => {
        activate(trigger.getAttribute("data-tc-control-panel-trigger") || "");
      });
    });

    const activeTrigger = triggers.find((trigger) => trigger instanceof HTMLElement && trigger.classList.contains("active"));
    activate(activeTrigger?.getAttribute("data-tc-control-panel-trigger") || "");
  }

  async function initRewardGuide(initialSettings = {}) {
    const pane = document.querySelector('.sub-pane[data-pane="tc-rewards-config"]');
    if (!(pane instanceof HTMLElement)) return;
    if (pane.dataset.rewardGuideInitialized === "1") return;
    pane.dataset.rewardGuideInitialized = "1";

    const triggers = Array.from(pane.querySelectorAll("[data-tc-reward-config-trigger]"));
    const sections = Array.from(pane.querySelectorAll("[data-tc-reward-config-section]"));
    const activate = (key) => {
      const activeKey = String(key || "guide").trim() || "guide";
      triggers.forEach((trigger) => {
        if (!(trigger instanceof HTMLElement)) return;
        const isActive = String(trigger.getAttribute("data-tc-reward-config-trigger") || "") === activeKey;
        trigger.classList.toggle("active", isActive);
        trigger.setAttribute("aria-selected", isActive ? "true" : "false");
      });
      sections.forEach((section) => {
        if (!(section instanceof HTMLElement)) return;
        section.hidden = String(section.getAttribute("data-tc-reward-config-section") || "") !== activeKey;
      });
    };
    triggers.forEach((trigger) => {
      trigger.addEventListener("click", () => {
        activate(trigger.getAttribute("data-tc-reward-config-trigger") || "guide");
      });
    });
    activate("guide");

    const titleInput = getEl("tc-reward-guide-title");
    const textInput = getEl("tc-reward-guide-text");
    if (!(titleInput instanceof HTMLInputElement) || !(textInput instanceof HTMLTextAreaElement)) {
      return;
    }
    const initialGuide = initialSettings?.rewardGuide && typeof initialSettings.rewardGuide === "object"
      ? initialSettings.rewardGuide
      : {};
    titleInput.value = String(initialGuide?.title || "راهنمای دریافت جایزه");
    textInput.value = String(initialGuide?.text || "");

    let rewardGuideDirty = false;
    [titleInput, textInput].forEach((field) => {
      field.addEventListener("input", () => {
        rewardGuideDirty = true;
        setRewardGuideStatus("");
      });
    });
    textInput.addEventListener("keydown", applyRewardGuideEditorShortcut);

    try {
      const guide = await requestStoreGet("get_reward_guide");
      if (!rewardGuideDirty) {
        titleInput.value = String(guide?.title || titleInput.value || "راهنمای دریافت جایزه");
        textInput.value = String(guide?.text || "");
      }
    } catch (error) {
      if (!rewardGuideDirty && (!initialGuide || Object.keys(initialGuide).length === 0)) {
        setRewardGuideStatus(error?.message || "بارگذاری راهنمای جایزه ناموفق بود.", true);
      }
    }
  }

  async function saveRewardGuideFromDom(saveBtn) {
    const titleInput = getEl("tc-reward-guide-title");
    const textInput = getEl("tc-reward-guide-text");
    if (!(titleInput instanceof HTMLInputElement) || !(textInput instanceof HTMLTextAreaElement)) {
      setRewardGuideStatus("فیلدهای راهنمای جایزه پیدا نشدند.", true);
      return;
    }
    if (saveBtn instanceof HTMLButtonElement) {
      saveBtn.disabled = true;
    }
    setRewardGuideStatus("در حال ذخیره...");
    try {
      const rewardGuide = {
        title: titleInput.value,
        text: textInput.value
      };
      let saveError = null;
      try {
        await requestStorePost("save_reward_guide", { rewardGuide });
      } catch (error) {
        saveError = error;
      }
      if (saveError) {
        await requestStorePost("save_settings", { settings: { rewardGuide } });
      } else {
        await saveSettings({ rewardGuide });
      }
      setRewardGuideStatus("ذخیره شد.");
    } catch (error) {
      setRewardGuideStatus(error?.message || "ذخیره راهنمای جایزه ناموفق بود.", true);
    } finally {
      if (saveBtn instanceof HTMLButtonElement) {
        saveBtn.disabled = false;
      }
    }
  }

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const button = target.closest("#tc-reward-guide-save");
    if (!(button instanceof HTMLButtonElement)) return;
    event.preventDefault();
    void saveRewardGuideFromDom(button);
  });

  async function initSettings() {
    const saveBtn = getEl("tc-settings-save");
    const activeToggle = getEl("tc-active-toggle");
    const durationToggle = getEl("tc-duration-toggle");
    const startDate = getEl("tc-duration-start");
    const startTime = getEl("tc-duration-start-time");
    const endDate = getEl("tc-duration-end");
    const endTime = getEl("tc-duration-end-time");

    initControlPanelTabs();
    initRewardGuide();
    const settings = await loadSettings();
    applySettings(settings);
    initLandingEditor(settings);
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


