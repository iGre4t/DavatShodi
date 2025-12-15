const DEFAULT_USERS = [
  {
    code: "demo-user-1",
    username: "demo-user-1",
    email: "demo1@example.com",
    name: "Demo User 1",
    phone: "09123456789",
    work_id: "EMP-1001",
    id_number: "007000111",
    active: true
  },
  {
    code: "demo-user-2",
    username: "demo-user-2",
    email: "demo2@example.com",
    name: "Demo User 2",
    phone: "09350001122",
    work_id: "EMP-1002",
    id_number: "007000112",
    active: false
  }
];
let USER_DB = [...DEFAULT_USERS];
let editingUserCode = "";
let deletingUserCode = "";
// Keys stored in localStorage plus appearance defaults keep the UI consistent between sessions.

const TITLE_KEY = "frontend_panel_title";
const TIMEZONE_KEY = "frontend_panel_timezone";
const API_ENDPOINT = "./api/data.php"; // Shared handler supplying data for both users and gallery tabs.
const PANEL_TITLE_KEY = "frontend_panel_name";
const PANEL_TITLE_DEFAULT = "Frontend panel";
const DEFAULT_SETTINGS = {
  title: "Great Panel",
  timezone: "Asia/Tehran",
  panelName: PANEL_TITLE_DEFAULT,
  backupSettings: {
    autoIntervalMinutes: 0,
    autoLimit: 0,
    lastAutoBackupAt: null
  }
};
const SITE_ICON_DEFAULT_DATA_URI = "data:,";
const DEFAULT_APPEARANCE = {
  primary: "#e11d2e",
  background: "#ffffff",
  text: "#111111",
  toggle: "#e11d2e"
};
const APPEARANCE_PRIMARY_KEY = "frontend_appearance_primary";
const APPEARANCE_BACKGROUND_KEY = "frontend_appearance_background";
const APPEARANCE_TEXT_KEY = "frontend_appearance_text";
const APPEARANCE_TOGGLE_KEY = "frontend_appearance_toggle";
const APPEARANCE_HINT_DEFAULT = "Fine-tune the UI colors directly from the developer lab.";
const LOCAL_UNCATEGORIZED_CATEGORY_NAME = "--no category--";
let currentAppearanceState = { ...DEFAULT_APPEARANCE };
let savedAppearanceState = { ...DEFAULT_APPEARANCE };
const APPEARANCE_KEYS = ["primary", "background", "text", "toggle"];
const APPEARANCE_HSL_BASE = {
  primary: { s: 0.78, l: 0.54 },
  background: { s: 0.12, l: 0.96 },
  text: { s: 0.65, l: 0.18 },
  toggle: { s: 0.65, l: 0.75 }
};
const APPEARANCE_LABELS = {
  primary: "Primary color",
  background: "Background color",
  text: "Text color",
  toggle: "Toggle button color"
};
const COLOR_PICKER_GRID = {
  minS: 0.08,
  maxS: 0.95,
  minL: 0.35,
  maxL: 0.95
};
const appearancePickerGridState = {};
let activeAppearancePickerKey = null;
let SERVER_SETTINGS = { ...DEFAULT_SETTINGS };
let SERVER_DATA_LOADED = false;
let SERVER_DATABASE_CONNECTED = false;
let GALLERY_CATEGORIES = [];
let GALLERY_PHOTOS = [];
const GALLERY_GRID_INITIAL_COUNT = 8;
const GALLERY_GRID_LOAD_STEP = 8;
const GALLERY_THUMB_PLACEHOLDER = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==";
const GALLERY_THUMB_DEFAULT_MODE = "default";
const GALLERY_THUMB_PREVIEW_MODE = "preview";
const GALLERY_THUMB_LAZY_ROOT_MARGIN = "250px";
const GALLERY_THUMB_LOAD_BATCH = 2;
let galleryVisibleCount = GALLERY_GRID_INITIAL_COUNT;
let photoChooserVisibleCount = GALLERY_GRID_INITIAL_COUNT;
let gallerySearchTerm = "";
let photoChooserSearchTerm = "";
let gallerySearchInputElement = null;
let gallerySearchCountElement = null;
let photoChooserSearchInputElement = null;
let photoChooserSearchCountElement = null;
const photoChooserSelectedIds = new Set();
let photoChooserAllowMultiple = true;
let photoChooserOnChoose = null;
let photoChooserModalElement;
let photoChooserGridElement;
let photoChooserEmptyElement;
let photoChooserLoadMoreButton;
let photoChooserChooseButton;
let photoChooserCancelButton;
let photoChooserUploadButton;
let photoChooserReopenContext = null;
let siteIconValue = "";
let siteIconPreviewImage = null;
let siteIconPlaceholder = null;
let siteIconAddButton = null;
let siteIconClearButton = null;
let faviconLinkElement = null;
let sidebarLogoImage = null;
let sidebarLogoText = null;
let galleryCategorySubmitDefaultText = "";
let galleryImageObserver = null;
let galleryThumbLoadQueue = [];
let galleryThumbLoadScheduled = false;
const galleryPendingCounts = new WeakMap();
const galleryGridLoaderMap = new WeakMap();
const galleryImageGridMap = new WeakMap();
let BACKUP_ENTRIES = [];
let backupIntervalInput = null;
let backupLimitInput = null;
let instantBackupButton = null;
let backupImportInput = null;
let backupImportTrigger = null;
let backupFileChosen = null;
let pendingBackupImportFile = null;
let backupSettingsFormElement = null;
let backupSaveButton = null;

function normalizeValue(value) {
  if (value === null || value === undefined) {
    return "";
  }
  return String(value);
}

function parseNumericUserCode(value) {
  const candidate = String(value ?? "").replace(/\D/g, "");
  if (candidate === "") {
    return NaN;
  }
  return Number.parseInt(candidate, 10);
}

function getNextUserCode() {
  const highest = USER_DB.reduce((max, user) => {
    const parsed = parseNumericUserCode(user.code);
    if (Number.isFinite(parsed)) {
      return Math.max(max, parsed);
    }
    return max;
  }, 0);
  return String(highest + 1).padStart(6, "0");
}

function loadGalleryThumbnailImage(image) {
  if (!image || image.dataset.galleryThumbLoaded === "1") {
    return;
  }
  const nextSrc = image.dataset.galleryThumbSrc;
  if (!nextSrc) {
    return;
  }
  enqueueGalleryThumbLoad(image, nextSrc);
}

function enqueueGalleryThumbLoad(image, src) {
  galleryThumbLoadQueue.push({ image, src });
  scheduleGalleryThumbLoad();
}

function scheduleGalleryThumbLoad() {
  if (galleryThumbLoadScheduled) {
    return;
  }
  galleryThumbLoadScheduled = true;
  requestAnimationFrame(processGalleryThumbLoadQueue);
}

function processGalleryThumbLoadQueue() {
  galleryThumbLoadScheduled = false;
  if (!galleryThumbLoadQueue.length) {
    return;
  }
  const batch = galleryThumbLoadQueue.splice(
    0,
    Math.max(1, Math.min(galleryThumbLoadQueue.length, GALLERY_THUMB_LOAD_BATCH))
  );
  batch.forEach(({ image, src }) => {
    if (!image) {
      return;
    }
    const grid = galleryImageGridMap.get(image);
    image.dataset.galleryThumbLoaded = "1";
    delete image.dataset.galleryThumbSrc;
    image.src = src;
    if (grid) {
      decrementGalleryGridPending(grid);
      galleryImageGridMap.delete(image);
    }
  });
  if (galleryThumbLoadQueue.length) {
    requestAnimationFrame(processGalleryThumbLoadQueue);
  }
}

function getGalleryLoaderForGrid(grid) {
  if (!grid) {
    return null;
  }
  if (galleryGridLoaderMap.has(grid)) {
    return galleryGridLoaderMap.get(grid);
  }
  let loader = grid.parentElement?.querySelector("[data-gallery-loading]");
  if (!loader) {
    const chooserScroll = grid.closest(".photo-chooser-scroll");
    loader = chooserScroll?.querySelector("[data-gallery-loading]") ?? null;
  }
  galleryGridLoaderMap.set(grid, loader);
  return loader;
}

function updateGalleryLoaderVisibility(grid) {
  const loader = getGalleryLoaderForGrid(grid);
  if (!loader) {
    return;
  }
  const pending = Math.max(0, galleryPendingCounts.get(grid) ?? 0);
  loader.classList.toggle("hidden", pending === 0);
}

function setGalleryGridPendingCount(grid, count) {
  if (!grid) {
    return;
  }
  const normalized = Math.max(0, Number.isFinite(count) ? count : 0);
  galleryPendingCounts.set(grid, normalized);
  updateGalleryLoaderVisibility(grid);
}

function decrementGalleryGridPending(grid) {
  if (!grid) {
    return;
  }
  const current = galleryPendingCounts.get(grid) ?? 0;
  const next = Math.max(0, current - 1);
  galleryPendingCounts.set(grid, next);
  updateGalleryLoaderVisibility(grid);
}

function initializeGalleryGridLoading(grid, visibleCount) {
  if (!grid) {
    return;
  }
  setGalleryGridPendingCount(grid, visibleCount);
}

function getGalleryImageObserver() {
  if (galleryImageObserver !== null || typeof IntersectionObserver === "undefined") {
    return galleryImageObserver;
  }
  galleryImageObserver = new IntersectionObserver(
    entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) {
          return;
        }
        const img = entry.target;
        galleryImageObserver?.unobserve(img);
        loadGalleryThumbnailImage(img);
      });
    },
    {
      rootMargin: GALLERY_THUMB_LAZY_ROOT_MARGIN,
      threshold: 0
    }
  );
  return galleryImageObserver;
}

function scheduleGalleryThumbnailLoad(image) {
  if (!image) {
    return;
  }
  const observer = getGalleryImageObserver();
  if (observer) {
    observer.observe(image);
    return;
  }
  loadGalleryThumbnailImage(image);
}

function scrollGalleryGridByRows(grid, rows = 1) {
  if (!grid || rows <= 0) {
    return;
  }
  const card = grid.querySelector(".gallery-thumb-card");
  const baseHeight = card ? card.offsetHeight : grid.offsetHeight;
  if (!baseHeight) {
    return;
  }
  const doc = grid.ownerDocument ?? document;
  const computedStyle =
    doc.defaultView && typeof doc.defaultView.getComputedStyle === "function"
      ? doc.defaultView.getComputedStyle(grid)
      : null;
  const rowGap = computedStyle ? parseFloat(computedStyle.rowGap) || 0 : 0;
  const scrollHeight = (baseHeight + rowGap) * rows;
  if (!scrollHeight) {
    return;
  }
  const scrollParent = findScrollableAncestor(grid);
  const docScrolling =
    doc.scrollingElement || doc.documentElement || window;
  const target = scrollParent || docScrolling || window;
  animateVerticalScroll(target, scrollHeight);
}

function animateVerticalScroll(target, delta, duration = 360) {
  if (!delta || duration <= 0) {
    return;
  }
  const isWindow = target === window;
  const getPosition = () => {
    if (isWindow) {
      return window.scrollY || window.pageYOffset || 0;
    }
    return (target && typeof target.scrollTop === "number" ? target.scrollTop : 0);
  };
  const setPosition = value => {
    if (isWindow) {
      window.scrollTo({ top: value, behavior: "auto" });
    } else if (target && typeof target.scrollTo === "function") {
      target.scrollTo({ top: value, behavior: "auto" });
    } else if (target && typeof target.scrollTop === "number") {
      target.scrollTop = value;
    }
  };
  const start = getPosition();
  const end = start + delta;
  const startTime = performance.now();
  const step = now => {
    const progress = Math.min(1, (now - startTime) / duration);
    const eased = 0.5 - Math.cos(progress * Math.PI) / 2;
    const next = start + (end - start) * eased;
    setPosition(next);
    if (progress < 1) {
      requestAnimationFrame(step);
    } else {
      setPosition(end);
    }
  };
  requestAnimationFrame(step);
}

function findScrollableAncestor(element) {
  if (!element) {
    return null;
  }
  let current = element.parentElement;
  while (current && current !== document.body) {
    if (
      current.scrollHeight > current.clientHeight &&
      current instanceof HTMLElement
    ) {
      const style =
        current.ownerDocument?.defaultView &&
        typeof current.ownerDocument.defaultView.getComputedStyle === "function"
          ? current.ownerDocument.defaultView.getComputedStyle(current)
          : null;
      const overflowY = style?.overflowY || "";
      if (["auto", "scroll", "overlay"].includes(overflowY)) {
        return current;
      }
    }
    current = current.parentElement;
  }
  return null;
}

function normalizeDigits(value) {
  return String(value ?? "").replace(/\D/g, "");
}

function isValidEmail(value) {
  const email = String(value ?? "").trim();
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

async function loadServerData() {
  SERVER_DATABASE_CONNECTED = false;
  try {
    const response = await fetch(API_ENDPOINT);
    if (!response.ok) {
      throw new Error(`Failed to fetch backend data (${response.status})`);
    }
    const payload = await response.json();
    if (Array.isArray(payload.users) && payload.users.length) {
      USER_DB = payload.users.map(u => {
        const username = normalizeValue(u.username) || normalizeValue(u.code);
        return {
          code: normalizeValue(u.code),
          username,
          name:
            normalizeValue(u.name) ||
            normalizeValue(u.fullname) ||
            username ||
            normalizeValue(u.code),
          phone: normalizeValue(u.phone),
          work_id: normalizeValue(u.work_id),
          id_number: normalizeValue(u.id_number),
          email: normalizeValue(u.email),
          active: Boolean(u.active)
        };
      });
    } else {
      USER_DB = [...DEFAULT_USERS];
    }
    SERVER_DATABASE_CONNECTED = Boolean(payload.databaseConnected);
    renderSqlRunnerStatus();
    if (payload.settings && typeof payload.settings === "object") {
      SERVER_SETTINGS = { ...SERVER_SETTINGS, ...payload.settings };
    }
    updateGalleryStateFromPayload(payload);
    updateBackupListFromPayload(payload);
    applyServerSettings(); // Save server-provided defaults for the next session.
    SERVER_DATA_LOADED = true;
  } catch (err) {
    console.warn("Failed to load data from backend:", err);
    SERVER_DATABASE_CONNECTED = false;
    renderSqlRunnerStatus();
    USER_DB = [...DEFAULT_USERS];
    updateGalleryStateFromPayload();
  }
}

function applyServerSettings() {
  if (SERVER_SETTINGS.title && !localStorage.getItem(TITLE_KEY)) {
    localStorage.setItem(TITLE_KEY, SERVER_SETTINGS.title);
  }
  if (SERVER_SETTINGS.timezone && !localStorage.getItem(TIMEZONE_KEY)) {
    localStorage.setItem(TIMEZONE_KEY, SERVER_SETTINGS.timezone);
  }
  const incomingBackupSettings = SERVER_SETTINGS.backupSettings;
  SERVER_SETTINGS.backupSettings =
    incomingBackupSettings && typeof incomingBackupSettings === "object"
      ? {
          ...DEFAULT_SETTINGS.backupSettings,
          ...incomingBackupSettings
        }
      : { ...DEFAULT_SETTINGS.backupSettings };
  applyBackupSettingsInputs();
}

function renderSqlRunnerStatus() {
  const statusEl = qs("#dev-sql-status");
  if (!statusEl) {
    return;
  }
  statusEl.textContent = SERVER_DATABASE_CONNECTED
    ? "Connected to the configured database."
    : "No active database connection.";
}

function formatBytes(value) {
  const size = Number(value ?? 0);
  if (!Number.isFinite(size) || size <= 0) {
    return "0 B";
  }
  const units = ["B", "KB", "MB", "GB", "TB"];
  let count = size;
  let index = 0;
  while (count >= 1024 && index < units.length - 1) {
    count /= 1024;
    index += 1;
  }
  const precision = count < 10 && index > 0 ? 1 : 0;
  return `${count.toFixed(precision)} ${units[index]}`;
}

function formatBackupTimestamp(value) {
  if (!value) {
    return "Unknown time";
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  try {
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short"
    }).format(parsed);
  } catch {
    return parsed.toLocaleString();
  }
}

function renderBackupList(entries = []) {
  const container = qs("#backup-history");
  if (!container) {
    return;
  }
  container.innerHTML = "";
  const wrapper = document.createElement("div");
  wrapper.className = "table-wrapper backup-table-wrapper";
  const table = document.createElement("table");
  table.className = "backup-table";
  table.setAttribute("dir", "rtl");
  const thead = document.createElement("thead");
  const headerRow = document.createElement("tr");
  ["When", "Type", "File", "Size", "Action"].forEach(text => {
    const th = document.createElement("th");
    th.textContent = text;
    headerRow.appendChild(th);
  });
  thead.appendChild(headerRow);
  const tbody = document.createElement("tbody");
  if (!entries.length) {
    const emptyRow = document.createElement("tr");
    const emptyCell = document.createElement("td");
    emptyCell.colSpan = 5;
    emptyCell.className = "muted backup-table__empty";
    emptyCell.textContent = "No backups recorded yet.";
    emptyRow.appendChild(emptyCell);
    tbody.appendChild(emptyRow);
  } else {
    entries.forEach(entry => {
      const row = document.createElement("tr");
      const whenCell = document.createElement("td");
      whenCell.textContent = formatBackupTimestamp(entry.created_at);
      const typeCell = document.createElement("td");
      typeCell.textContent =
        entry.type === "auto" ? "Automatic backup" : "Instant backup";
      const fileCell = document.createElement("td");
      fileCell.textContent = entry.filename ?? "backup.json";
      const sizeCell = document.createElement("td");
      sizeCell.textContent = formatBytes(entry.size ?? 0);
      const actionCell = document.createElement("td");
      const actionsWrapper = document.createElement("div");
      actionsWrapper.className = "backup-actions";
      const downloadBtn = document.createElement("button");
      downloadBtn.type = "button";
      downloadBtn.className = "btn ghost small";
      downloadBtn.textContent = "Download";
      downloadBtn.dataset.backupAction = "download";
      downloadBtn.dataset.backupFilename = entry.filename ?? "";
      const applyBtn = document.createElement("button");
      applyBtn.type = "button";
      applyBtn.className = "btn primary small";
      applyBtn.textContent = "Apply";
      applyBtn.dataset.backupAction = "apply";
      applyBtn.dataset.backupFilename = entry.filename ?? "";
      const deleteBtn = document.createElement("button");
      deleteBtn.type = "button";
      deleteBtn.className = "btn ghost small";
      deleteBtn.textContent = "Delete";
      deleteBtn.dataset.backupAction = "delete";
      deleteBtn.dataset.backupFilename = entry.filename ?? "";
      actionsWrapper.append(downloadBtn, applyBtn, deleteBtn);
      actionCell.appendChild(actionsWrapper);
      row.appendChild(whenCell);
      row.appendChild(typeCell);
      row.appendChild(fileCell);
      row.appendChild(sizeCell);
      row.appendChild(actionCell);
      tbody.appendChild(row);
    });
  }
  table.appendChild(thead);
  table.appendChild(tbody);
  wrapper.appendChild(table);
  container.appendChild(wrapper);
}

function setBackupFileLabel(text) {
  if (!backupFileChosen) {
    return;
  }
  backupFileChosen.textContent = text || "No file selected.";
}

async function downloadBackupByFilename(filename) {
  if (!filename) {
    return;
  }
  setBackupControlsSubmitting(true);
  try {
    const payload = await postJsonAction({ action: "fetch_backup_file", filename });
    triggerBackupDownload(payload.content, payload.filename, payload.mime, payload.encoding);
    showDefaultToast(payload.message || "Backup downloaded.");
  } catch (error) {
    showErrorSnackbar({ message: error?.message || "Failed to download backup." });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

async function deleteBackupByFilename(filename) {
  if (!filename) {
    return;
  }
  const confirmed = await showDialog(
    'Delete this backup? This cannot be undone.',
    { confirm: true, title: 'Delete backup', okText: 'Delete', cancelText: 'Cancel' }
  );
  if (!confirmed) {
    return;
  }
  setBackupControlsSubmitting(true);
  try {
    const payload = await postJsonAction({ action: "delete_backup_file", filename });
    updateBackupListFromPayload(payload);
    showDefaultToast(payload.message || "Backup deleted.");
  } catch (error) {
    showErrorSnackbar({ message: error?.message || "Failed to delete backup." });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

async function applyBackupByFilename(filename) {
  if (!filename) {
    return;
  }
  const confirmed = await showDialog(
    'Applying this backup will replace the current data. Continue?',
    { confirm: true, title: 'Apply backup', okText: 'Apply', cancelText: 'Cancel' }
  );
  if (!confirmed) {
    return;
  }
  setBackupControlsSubmitting(true);
  try {
    const payload = await postJsonAction({ action: "apply_backup_by_filename", filename });
    updateBackupListFromPayload(payload);
    showDefaultToast(payload.message || "Backup applied.");
    await reloadGalleryData();
  } catch (error) {
    showErrorSnackbar({ message: error?.message || "Failed to apply backup." });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

function handleBackupActionClick(event) {
  const button = event.target instanceof HTMLElement ? event.target.closest("[data-backup-action]") : null;
  if (!button) {
    return;
  }
  const action = button.dataset.backupAction;
  const filename = button.dataset.backupFilename ?? "";
  if (!filename) {
    return;
  }
  if (action === "download") {
    void downloadBackupByFilename(filename);
  } else if (action === "delete") {
    void deleteBackupByFilename(filename);
  } else if (action === "apply") {
    void applyBackupByFilename(filename);
  }
}

function updateBackupListFromPayload(payload = {}) {
  const entries = Array.isArray(payload.backups) ? payload.backups : [];
  BACKUP_ENTRIES = entries.slice();
  renderBackupList(BACKUP_ENTRIES);
}

function applyBackupSettingsInputs() {
  if (!backupIntervalInput && !backupLimitInput) {
    return;
  }
  const settings = SERVER_SETTINGS.backupSettings || DEFAULT_SETTINGS.backupSettings;
  if (backupIntervalInput) {
    const interval =
      typeof settings.autoIntervalMinutes === "number"
        ? String(settings.autoIntervalMinutes)
        : "";
    backupIntervalInput.value = interval;
  }
  if (backupLimitInput) {
    const limit =
      typeof settings.autoLimit === "number" ? String(settings.autoLimit) : "";
    backupLimitInput.value = limit;
  }
}

function setBackupControlsSubmitting(isSubmitting) {
  [instantBackupButton, backupImportTrigger, backupImportInput, backupSaveButton].forEach(control => {
    if (!control) {
      return;
    }
    control.disabled = Boolean(isSubmitting);
  });
}

function triggerBackupDownload(content, filename, mime = "application/json", encoding) {
  if (!content) {
    return;
  }
  let blob;
  if (encoding === "base64" && typeof content === "string") {
    const binary = atob(content);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i);
    }
    blob = new Blob([bytes], { type: mime || "application/octet-stream" });
  } else {
    const type = mime || "application/json";
    blob = new Blob([content], { type });
  }
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename || `backup-${Date.now()}.json`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function postJsonAction(body) {
  let response;
  try {
    response = await fetch(API_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      body: JSON.stringify(body)
    });
  } catch {
    throw new Error("Unable to reach the server.");
  }
  let payload = {};
  try {
    payload = await response.json();
  } catch {
    throw new Error("Invalid response from server.");
  }
  if (!response.ok || payload?.status === "error") {
    throw new Error(payload?.message || "Server reported an error.");
  }
  return payload;
}

async function handleInstantBackup() {
  setBackupControlsSubmitting(true);
  try {
    const result = await postJsonAction({ action: "download_database_backup" });
    triggerBackupDownload(result.backup, result.filename, result.mime, result.encoding);
    if (result?.backups) {
      updateBackupListFromPayload(result);
    }
    showDefaultToast(result.message || "Backup downloaded.");
    await reloadGalleryData();
  } catch (error) {
    showErrorSnackbar({ message: error?.message || "Failed to download backup." });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

async function handleBackupImport(file) {
  if (!file) {
    return;
  }
  setBackupControlsSubmitting(true);
  try {
    const data = new FormData();
    data.append("action", "import_database_backup");
    data.append("backup", file, file.name);
    const response = await fetch(API_ENDPOINT, {
      method: "POST",
      body: data
    });
    let payload = {};
    try {
      payload = await response.json();
    } catch {
      throw new Error("Invalid response from server.");
    }
    if (!response.ok || payload?.status === "error") {
      throw new Error(payload?.message || "Failed to import the backup.");
    }
    showDefaultToast(payload?.message || "Backup imported.");
    if (payload?.backups) {
      updateBackupListFromPayload(payload);
    }
    if (backupImportInput) {
      backupImportInput.value = "";
    }
    setBackupFileLabel("No file selected.");
    await reloadGalleryData();
  } catch (error) {
    showErrorSnackbar({ message: error?.message || "Failed to import backup." });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

async function handleBackupSettingsSave(event) {
  event.preventDefault();
  const intervalValue = Number(backupIntervalInput?.value ?? "");
  const limitValue = Number(backupLimitInput?.value ?? "");
  const interval =
    Number.isFinite(intervalValue) && intervalValue >= 0 ? intervalValue : 0;
  const limit = Number.isFinite(limitValue) && limitValue >= 0 ? limitValue : 0;
  setBackupControlsSubmitting(true);
  try {
    const payload = await postJsonAction({
      action: "save_backup_settings",
      settings: {
        autoIntervalMinutes: interval,
        autoLimit: limit
      }
    });
    SERVER_SETTINGS.backupSettings = {
      ...DEFAULT_SETTINGS.backupSettings,
      ...SERVER_SETTINGS.backupSettings,
      autoIntervalMinutes: interval,
      autoLimit: limit
    };
    applyBackupSettingsInputs();
    showDefaultToast(payload.message || "Auto-backup settings saved.");
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to save auto-backup settings."
    });
  } finally {
    setBackupControlsSubmitting(false);
  }
}

function setSqlFormSubmitting(isSubmitting) {
  const runBtn = qs("#run-sql-query");
  const clearBtn = qs("#clear-sql-query");
  const textarea = qs("#dev-db-sql");
  [runBtn, clearBtn].forEach(button => {
    if (!button) {
      return;
    }
    button.disabled = Boolean(isSubmitting);
  });
  if (textarea) {
    textarea.readOnly = Boolean(isSubmitting);
  }
}

function clearSqlResult() {
  const container = qs("#dev-sql-result");
  if (!container) {
    return;
  }
  const message = qs("[data-sql-result-message]", container);
  const body = qs("[data-sql-result-body]", container);
  if (message) {
    message.textContent = "";
  }
  if (body) {
    body.innerHTML = "";
  }
  container.classList.add("hidden");
}

function renderSqlResult(result) {
  const container = qs("#dev-sql-result");
  if (!container) {
    return;
  }
  const messageNode = qs("[data-sql-result-message]", container);
  const body = qs("[data-sql-result-body]", container);
  if (!messageNode || !body) {
    return;
  }
  const displayMessage = result?.message ?? "SQL query executed.";
  messageNode.textContent = displayMessage;
  body.innerHTML = "";
  if (Array.isArray(result?.rows) && result.type === "select") {
    const rows = result.rows;
    const columns =
      Array.isArray(result.columns) && result.columns.length
        ? result.columns
        : rows[0]
        ? Object.keys(rows[0])
        : [];
    if (!rows.length) {
      const empty = document.createElement("p");
      empty.textContent = "Query returned no rows.";
      body.appendChild(empty);
    } else if (!columns.length) {
      const empty = document.createElement("p");
      empty.textContent = "Query produced no columns.";
      body.appendChild(empty);
    } else {
      const wrapper = document.createElement("div");
      wrapper.className = "sql-result-table-wrapper";
      const table = document.createElement("table");
      table.className = "sql-result-table";
      const thead = document.createElement("thead");
      const headRow = document.createElement("tr");
      columns.forEach(column => {
        const th = document.createElement("th");
        th.textContent = column;
        headRow.appendChild(th);
      });
      thead.appendChild(headRow);
      table.appendChild(thead);
      const tbody = document.createElement("tbody");
      rows.forEach(row => {
        const tr = document.createElement("tr");
        columns.forEach(column => {
          const td = document.createElement("td");
          const value = row[column];
          td.textContent = value === null || value === undefined ? "" : String(value);
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrapper.appendChild(table);
      body.appendChild(wrapper);
      if (result.hasMore && rows.length) {
        const notice = document.createElement("p");
        notice.className = "muted sql-result-limit";
        notice.textContent = `Showing first ${rows.length} rows.`;
        body.appendChild(notice);
      }
    }
  } else if (result?.type === "exec") {
    const summary = document.createElement("p");
    summary.className = "muted";
    summary.textContent = `Affected rows: ${result.affectedRows ?? 0}.`;
    body.appendChild(summary);
  } else {
    const note = document.createElement("p");
    note.textContent = result?.message ?? "SQL query executed.";
    body.appendChild(note);
  }
  container.classList.remove("hidden");
}

function ensureHexColor(value) {
  if (typeof value !== "string") {
    return "";
  }
  const trimmed = value.trim();
  if (/^#([0-9a-fA-F]{6})$/.test(trimmed)) {
    return trimmed.toLowerCase();
  }
  if (/^[0-9a-fA-F]{6}$/.test(trimmed)) {
    return `#${trimmed.toLowerCase()}`;
  }
  return "";
}

function hexToRgb(hex) {
  const cleaned = ensureHexColor(hex);
  if (!cleaned) {
    return null;
  }
  return {
    r: parseInt(cleaned.slice(1, 3), 16),
    g: parseInt(cleaned.slice(3, 5), 16),
    b: parseInt(cleaned.slice(5, 7), 16)
  };
}

function rgbToHsl(r, g, b) {
  r /= 255;
  g /= 255;
  b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const delta = max - min;
  let h = 0;
  if (delta) {
    if (max === r) {
      h = 60 * (((g - b) / delta) % 6);
    } else if (max === g) {
      h = 60 * ((b - r) / delta + 2);
    } else {
      h = 60 * ((r - g) / delta + 4);
    }
  }
  const l = (max + min) / 2;
  const s = delta === 0 ? 0 : delta / (1 - Math.abs(2 * l - 1));
  return { h: (h + 360) % 360, s, l };
}

function hexToRgba(hex, alpha = 1) {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return "";
  }
  const safeAlpha = clamp(alpha, 0, 1);
  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${safeAlpha})`;
}

function clamp(value, min, max) {
  if (typeof value !== "number") {
    return min;
  }
  return Math.min(Math.max(value, min), max);
}

function normalizeGridHsl(hsl, key = "primary") {
  const base = APPEARANCE_HSL_BASE[key] || APPEARANCE_HSL_BASE.primary;
  const source = hsl && typeof hsl === "object" ? hsl : base;
  return {
    s: clamp(source.s, COLOR_PICKER_GRID.minS, COLOR_PICKER_GRID.maxS),
    l: clamp(source.l, COLOR_PICKER_GRID.minL, COLOR_PICKER_GRID.maxL)
  };
}

function gridCoordsFromHsl(hsl) {
  const sRange = COLOR_PICKER_GRID.maxS - COLOR_PICKER_GRID.minS;
  const lRange = COLOR_PICKER_GRID.maxL - COLOR_PICKER_GRID.minL;
  const sPortion = sRange ? (clamp(hsl.s, COLOR_PICKER_GRID.minS, COLOR_PICKER_GRID.maxS) - COLOR_PICKER_GRID.minS) / sRange : 0;
  const lPortion = lRange ? (COLOR_PICKER_GRID.maxL - clamp(hsl.l, COLOR_PICKER_GRID.minL, COLOR_PICKER_GRID.maxL)) / lRange : 0;
  return {
    x: clamp(sPortion, 0, 1),
    y: clamp(lPortion, 0, 1)
  };
}

function hslFromGridCoords({ x = 0, y = 0 }) {
  const sRange = COLOR_PICKER_GRID.maxS - COLOR_PICKER_GRID.minS;
  const lRange = COLOR_PICKER_GRID.maxL - COLOR_PICKER_GRID.minL;
  const s = clamp(COLOR_PICKER_GRID.minS + clamp(x, 0, 1) * sRange, COLOR_PICKER_GRID.minS, COLOR_PICKER_GRID.maxS);
  const l = clamp(COLOR_PICKER_GRID.maxL - clamp(y, 0, 1) * lRange, COLOR_PICKER_GRID.minL, COLOR_PICKER_GRID.maxL);
  return { s, l };
}

function syncGridStateFromColor(key, hex) {
  if (!key) {
    return null;
  }
  const rgb = hexToRgb(hex);
  if (!rgb) {
    const normalized = normalizeGridHsl(APPEARANCE_HSL_BASE[key], key);
    appearancePickerGridState[key] = normalized;
    return normalized;
  }
  const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
  const normalized = normalizeGridHsl(hsl, key);
  appearancePickerGridState[key] = normalized;
  return normalized;
}

APPEARANCE_KEYS.forEach(key => {
  syncGridStateFromColor(key, DEFAULT_APPEARANCE[key]);
});

function componentToHex(value) {
  const rounded = Math.round(value);
  const hex = rounded.toString(16);
  return hex.length === 1 ? `0${hex}` : hex;
}

function hslToHex({ h, s, l }) {
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const hh = h / 60;
  const x = c * (1 - Math.abs((hh % 2) - 1));
  let r = 0;
  let g = 0;
  let b = 0;
  if (hh >= 0 && hh < 1) {
    r = c;
    g = x;
  } else if (hh >= 1 && hh < 2) {
    r = x;
    g = c;
  } else if (hh >= 2 && hh < 3) {
    g = c;
    b = x;
  } else if (hh >= 3 && hh < 4) {
    g = x;
    b = c;
  } else if (hh >= 4 && hh < 5) {
    r = x;
    b = c;
  } else if (hh >= 5 && hh < 6) {
    r = c;
    b = x;
  }
  const m = l - c / 2;
  return `#${componentToHex((r + m) * 255)}${componentToHex((g + m) * 255)}${componentToHex((b + m) * 255)}`;
}

function adjustHexLightness(hex, delta) {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return "";
  }
  const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
  hsl.l = Math.max(0, Math.min(1, hsl.l + delta));
  return hslToHex(hsl);
}

function getHueFromColor(hex) {
  const rgb = hexToRgb(hex);
  if (!rgb) {
    return 0;
  }
  const hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
  return Math.round(hsl.h);
}

function persistAppearanceState(state) {
  localStorage.setItem(APPEARANCE_PRIMARY_KEY, state.primary);
  localStorage.setItem(APPEARANCE_BACKGROUND_KEY, state.background);
  localStorage.setItem(APPEARANCE_TEXT_KEY, state.text);
  localStorage.setItem(APPEARANCE_TOGGLE_KEY, state.toggle);
  savedAppearanceState = { ...state };
}

function clearAppearanceStorage() {
  localStorage.removeItem(APPEARANCE_PRIMARY_KEY);
  localStorage.removeItem(APPEARANCE_BACKGROUND_KEY);
  localStorage.removeItem(APPEARANCE_TEXT_KEY);
  localStorage.removeItem(APPEARANCE_TOGGLE_KEY);
}

function loadAppearanceState() {
  return {
    primary: ensureHexColor(localStorage.getItem(APPEARANCE_PRIMARY_KEY)),
    background: ensureHexColor(localStorage.getItem(APPEARANCE_BACKGROUND_KEY)),
    text: ensureHexColor(localStorage.getItem(APPEARANCE_TEXT_KEY)),
    toggle: ensureHexColor(localStorage.getItem(APPEARANCE_TOGGLE_KEY))
  };
}

function applyAppearancePalette(state, { persist = false } = {}) {
  const palette = {
    primary: ensureHexColor(state.primary) || DEFAULT_APPEARANCE.primary,
    background: ensureHexColor(state.background) || DEFAULT_APPEARANCE.background,
    text: ensureHexColor(state.text) || DEFAULT_APPEARANCE.text,
    toggle: ensureHexColor(state.toggle) || DEFAULT_APPEARANCE.toggle
  };
  const root = document.documentElement;
  root.style.setProperty("--primary", palette.primary);
  const darker = adjustHexLightness(palette.primary, -0.18);
  root.style.setProperty("--primary-600", darker || palette.primary);
  root.style.setProperty("--bg", palette.background);
  root.style.setProperty("--text", palette.text);
  const toggleBg = hexToRgba(palette.toggle, 0.12) || "rgba(225,29,46,0.08)";
  const toggleBorder = hexToRgba(palette.toggle, 0.22) || "rgba(225,29,46,0.22)";
  root.style.setProperty("--sidebar-active", toggleBg);
  root.style.setProperty("--sidebar-active-border", toggleBorder);
  currentAppearanceState = { ...palette };
  APPEARANCE_KEYS.forEach(key => syncGridStateFromColor(key, palette[key]));
  if (persist) {
    persistAppearanceState(palette);
  }
  return palette;
}

function refreshFieldValue(key, color) {
  const normalized = ensureHexColor(color) || DEFAULT_APPEARANCE[key];
  const input = qs(`[data-appearance-hex="${key}"]`);
  if (input) {
    input.value = normalized;
  }
  const preview = qs(`[data-appearance-preview="${key}"]`);
  if (preview) {
    preview.style.background = normalized;
  }
}

function refreshModalPicker(key, color) {
  const modal = qs("#appearance-picker-modal");
  const picker = qs("[data-appearance-modal-picker]");
  if (!modal || !picker || !key) {
    return;
  }
  const normalized = ensureHexColor(color) || DEFAULT_APPEARANCE[key];
  const handle = picker.querySelector(".default-color-picker__handle");
  const slider = picker.querySelector("input[type='range']");
  const gridState = appearancePickerGridState[key] || normalizeGridHsl(APPEARANCE_HSL_BASE[key], key);
  appearancePickerGridState[key] = gridState;
  const handleCoords = gridCoordsFromHsl(gridState);
  if (handle) {
    handle.style.background = normalized;
    handle.style.left = `${handleCoords.x * 100}%`;
    handle.style.top = `${handleCoords.y * 100}%`;
  }
  if (slider) {
    const hue = getHueFromColor(normalized);
    slider.value = hue;
    const accent = adjustHexLightness(normalized, 0.18) || normalized;
    slider.style.setProperty("--slider-thumb", normalized);
    picker.style.setProperty("--picker-base", normalized);
    picker.style.setProperty("--picker-accent", accent);
  }
  const title = qs("#appearance-picker-title");
  if (title) {
    title.textContent = APPEARANCE_LABELS[key] || "Choose color";
  }
}

function updateAppearanceState(changes) {
  if (!changes || typeof changes !== "object") {
    return;
  }
  const merged = { ...currentAppearanceState, ...changes };
  applyAppearancePalette(merged, { persist: false });
  Object.keys(changes).forEach(key => {
    if (APPEARANCE_KEYS.includes(key)) {
      const color = ensureHexColor(changes[key]) || currentAppearanceState[key];
      if (color) {
        syncGridStateFromColor(key, color);
      }
      refreshFieldValue(key, currentAppearanceState[key]);
    }
  });
  if (activeAppearancePickerKey) {
    refreshModalPicker(activeAppearancePickerKey, currentAppearanceState[activeAppearancePickerKey]);
  }
  const hintEl = qs("#appearance-hint");
  if (hintEl) {
    hintEl.textContent = APPEARANCE_HINT_DEFAULT;
  }
}

function updateAppearanceInputs(state) {
  if (!state) {
    return;
  }
  APPEARANCE_KEYS.forEach(key => refreshFieldValue(key, state[key]));
  if (activeAppearancePickerKey) {
    refreshModalPicker(activeAppearancePickerKey, state[activeAppearancePickerKey]);
  }
}

function openColorPickerModal(key) {
  const modal = qs("#appearance-picker-modal");
  if (!modal || !key) {
    return;
  }
  activeAppearancePickerKey = key;
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");
  refreshModalPicker(key, currentAppearanceState[key]);
}

function closeColorPickerModal() {
  const modal = qs("#appearance-picker-modal");
  if (!modal) {
    return;
  }
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
  activeAppearancePickerKey = null;
}

function initAppearanceControls() {
  const stored = loadAppearanceState();
  if (stored.primary || stored.background || stored.text || stored.toggle) {
    currentAppearanceState = {
      ...DEFAULT_APPEARANCE,
      ...stored
    };
  }
  applyAppearancePalette(currentAppearanceState, { persist: false });
  updateAppearanceInputs(currentAppearanceState);
  savedAppearanceState = { ...currentAppearanceState };
  qsa("[data-show-appearance-picker]").forEach(button => {
    const key = button.dataset.showAppearancePicker;
    button.addEventListener("click", () => openColorPickerModal(key));
  });
  qsa("[data-appearance-hex]").forEach(input => {
    const key = input.dataset.appearanceHex;
    input.addEventListener("input", () => {
      const color = ensureHexColor(input.value);
      if (color) {
        updateAppearanceState({ [key]: color });
      }
    });
    input.addEventListener("blur", () => {
      const normalized = ensureHexColor(input.value) || currentAppearanceState[key];
      if (normalized) {
        refreshFieldValue(key, normalized);
      }
    });
  });
  const modalPicker = qs("[data-appearance-modal-picker]");
  const slider = modalPicker?.querySelector("input[type='range']");
  slider?.addEventListener("input", () => {
    if (!activeAppearancePickerKey) {
      return;
    }
    const hue = Number(slider.value) || 0;
    const gridHsl = appearancePickerGridState[activeAppearancePickerKey] || normalizeGridHsl(APPEARANCE_HSL_BASE[activeAppearancePickerKey], activeAppearancePickerKey);
    const nextColor = hslToHex({ h: hue, s: gridHsl.s, l: gridHsl.l });
    updateAppearanceState({ [activeAppearancePickerKey]: nextColor });
  });
  const pickerGrid = modalPicker?.querySelector(".default-color-picker__grid");
  let isPickerDragging = false;
  const handleGridInteraction = (event) => {
    if (!pickerGrid || !activeAppearancePickerKey) {
      return;
    }
    const rect = pickerGrid.getBoundingClientRect();
    const x = clamp((event.clientX - rect.left) / rect.width, 0, 1);
    const y = clamp((event.clientY - rect.top) / rect.height, 0, 1);
    const { s, l } = hslFromGridCoords({ x, y });
    appearancePickerGridState[activeAppearancePickerKey] = { s, l };
    const hue = Number(slider?.value ?? getHueFromColor(currentAppearanceState[activeAppearancePickerKey])) || 0;
    const nextColor = hslToHex({ h: hue, s, l });
    updateAppearanceState({ [activeAppearancePickerKey]: nextColor });
  };
  pickerGrid?.addEventListener("pointerdown", (event) => {
    if (!activeAppearancePickerKey) {
      return;
    }
    isPickerDragging = true;
    pickerGrid.setPointerCapture?.(event.pointerId);
    handleGridInteraction(event);
  });
  pickerGrid?.addEventListener("pointermove", (event) => {
    if (isPickerDragging) {
      handleGridInteraction(event);
    }
  });
  const stopGridInteraction = (event) => {
    if (!isPickerDragging) {
      return;
    }
    isPickerDragging = false;
    pickerGrid.releasePointerCapture?.(event.pointerId);
  };
  pickerGrid?.addEventListener("pointerup", stopGridInteraction);
  pickerGrid?.addEventListener("pointerleave", stopGridInteraction);
  pickerGrid?.addEventListener("pointercancel", stopGridInteraction);
  qs("[data-close-appearance-picker]")?.addEventListener("click", closeColorPickerModal);
  const modal = qs("#appearance-picker-modal");
  modal?.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeColorPickerModal();
    }
  });
  document.addEventListener("keyup", (event) => {
    if (event.key === "Escape" && activeAppearancePickerKey) {
      closeColorPickerModal();
    }
  });
  qs("#save-appearance-settings")?.addEventListener("click", () => {
    const previousAppearanceState = { ...savedAppearanceState };
    applyAppearancePalette(currentAppearanceState, { persist: true });
    updateAppearanceInputs(currentAppearanceState);
    showActionSnackbar({
      message: "Appearance preferences saved.",
      instructionLabel: "Undo change",
      onAction: () => {
        currentAppearanceState = { ...previousAppearanceState };
        applyAppearancePalette(currentAppearanceState, { persist: true });
        updateAppearanceInputs(currentAppearanceState);
        showDefaultToast("Appearance reverted to previous colors.");
      }
    });
  });
  qs("#reset-appearance-settings")?.addEventListener("click", () => {
    const previousAppearanceState = { ...savedAppearanceState };
    currentAppearanceState = { ...DEFAULT_APPEARANCE };
    applyAppearancePalette(currentAppearanceState, { persist: false });
    clearAppearanceStorage();
    updateAppearanceInputs(currentAppearanceState);
    savedAppearanceState = { ...DEFAULT_APPEARANCE };
    const hintEl = qs("#appearance-hint");
    if (hintEl) {
      hintEl.textContent = APPEARANCE_HINT_DEFAULT;
    }
    showActionSnackbar({
      message: "Appearance reverted to defaults.",
      instructionLabel: "Undo change",
      onAction: () => {
        currentAppearanceState = { ...previousAppearanceState };
        applyAppearancePalette(currentAppearanceState, { persist: true });
        updateAppearanceInputs(currentAppearanceState);
        showDefaultToast("Appearance restored.");
      }
    });
  });
}

function updateGalleryStateFromPayload(payload = {}) {
  const categories = Array.isArray(payload.galleryCategories)
    ? payload.galleryCategories
    : [];
  const photos = Array.isArray(payload.galleryPhotos)
    ? payload.galleryPhotos
    : [];
  GALLERY_CATEGORIES = categories
    .map(normalizeGalleryCategory)
    .filter(Boolean)
    .sort((a, b) => a.name.localeCompare(b.name, "fa"));
  GALLERY_PHOTOS = photos
    .map(normalizeGalleryPhoto)
    .filter(Boolean)
    .sort((a, b) => b.uploadedAtTs - a.uploadedAtTs);
  resetGalleryVisibleCount();
}

// Helper to guard and normalize gallery category rows fetched from the backend.
function normalizeGalleryCategory(row) {
  if (!row || typeof row !== "object") {
    return null;
  }
  const id = Number(row.id ?? 0);
  if (id <= 0) {
    return null;
  }
  const motherRaw = row.mother_category_id ?? row.motherCategoryId ?? null;
  const motherCandidate = Number(motherRaw ?? 0);
  const motherCategoryId =
    Number.isFinite(motherCandidate) && motherCandidate > 0
      ? motherCandidate
      : null;
  return {
    id,
    name: normalizeValue(row.name).trim(),
    slug: normalizeValue(row.slug).trim(),
    motherCategoryId
  };
}

function getCategoryLabel(category) {
  if (!category || typeof category !== "object") {
    return "";
  }
  const name = normalizeValue(category.name).trim();
  if (name) {
    return name;
  }
  const slug = normalizeValue(category.slug).trim();
  if (slug) {
    return slug;
  }
  return `Category ${category.id ?? ""}`;
}

// Normalizes gallery photo payloads so the frontend can read consistent fields.
function normalizeGalleryPhoto(row) {
  if (!row || typeof row !== "object") {
    return null;
  }
  const id = Number(row.photo_id ?? row.id ?? 0);
  if (id <= 0) {
    return null;
  }
  const uploadedAt = normalizeValue(row.uploaded_at ?? row.uploadedAt ?? "");
  const uploadedAtTs = Number.isFinite(Date.parse(uploadedAt))
    ? Date.parse(uploadedAt)
    : 0;
  const categoryId = Number(row.category_id ?? row.categoryId ?? 0);
  const rawCategoryName = normalizeValue(row.category_name ?? row.categoryName ?? "");
  const hasCategory = categoryId > 0;
  const normalizedCategoryName =
    hasCategory && rawCategoryName
      ? rawCategoryName
      : LOCAL_UNCATEGORIZED_CATEGORY_NAME;
  return {
    id,
    title: normalizeValue(row.title),
    altText: normalizeValue(row.alt_text ?? row.altText ?? ""),
    filename: normalizeValue(row.filename),
    categoryId,
    categoryName: normalizedCategoryName,
    uploadedAt,
    uploadedAtTs
  };
}

function resetGalleryVisibleCount() {
  galleryVisibleCount = GALLERY_GRID_INITIAL_COUNT;
}

// Keeps gallery table, dropdown, and CTA text aligned with the current data set.
function renderGalleryCategories() {
  populateGalleryCategoryMotherFormSelect();
  renderGalleryCategoryTable();
  updateGalleryPhotoCategorySelect();
}

function populateGalleryCategoryMotherFormSelect() {
  const select = qs("#gallery-category-mother");
  if (!select) {
    return;
  }
  fillMotherCategorySelect(select);
}

function fillMotherCategorySelect(select, { excludeId = null, selectedId = null } = {}) {
  if (!select) {
    return;
  }
  select.innerHTML = "";
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = "No mother category";
  select.appendChild(placeholder);
  GALLERY_CATEGORIES.forEach(category => {
    if (excludeId !== null && category.id === excludeId) {
      return;
    }
    const option = document.createElement("option");
    option.value = String(category.id);
    option.textContent = getCategoryLabel(category);
    if (selectedId !== null && category.id === selectedId) {
      option.selected = true;
    }
    select.appendChild(option);
  });
  if (selectedId === null) {
    select.value = "";
  } else {
    select.value = String(selectedId);
  }
}

// Builds the gallery category editor rows and wires the save/delete buttons to their handlers.
function renderGalleryCategoryTable() {
  const body = qs("#gallery-category-table-body");
  if (!body) {
    return;
  }
  body.innerHTML = "";
  if (!GALLERY_CATEGORIES.length) {
    const emptyRow = document.createElement("tr");
    const emptyCell = document.createElement("td");
    emptyCell.colSpan = 3;
    emptyCell.classList.add("muted");
    emptyCell.textContent = "No categories yet.";
    emptyRow.appendChild(emptyCell);
    body.appendChild(emptyRow);
    return;
  }
  GALLERY_CATEGORIES.forEach(category => {
    const row = document.createElement("tr");
    const nameCell = document.createElement("td");
    const nameInput = document.createElement("input");
    nameInput.type = "text";
    nameInput.className = "category-name-input";
    nameInput.value = category.name || "";
    nameInput.dataset.categoryId = String(category.id);
    nameCell.appendChild(nameInput);
    row.appendChild(nameCell);
    const motherCell = document.createElement("td");
    const motherSelect = document.createElement("select");
    motherSelect.className = "category-mother-select";
    motherSelect.dataset.categoryId = String(category.id);
    fillMotherCategorySelect(motherSelect, {
      excludeId: category.id,
      selectedId: category.motherCategoryId ?? null
    });
    motherCell.appendChild(motherSelect);
    row.appendChild(motherCell);
    const actionCell = document.createElement("td");
    actionCell.classList.add("category-actions");
    const saveBtn = document.createElement("button");
    saveBtn.type = "button";
    saveBtn.className = "btn primary";
    saveBtn.textContent = "Save";
    const inlineSave = () => {
      void updateGalleryCategoryNameInline(category.id, nameInput, motherSelect, saveBtn);
    };
    saveBtn.addEventListener("click", inlineSave);
    actionCell.appendChild(saveBtn);
    nameInput.addEventListener("keydown", event => {
      if (event.key === "Enter") {
        event.preventDefault();
        inlineSave();
      }
    });
    const deleteBtn = document.createElement("button");
    deleteBtn.type = "button";
    deleteBtn.className = "btn ghost";
    deleteBtn.textContent = "Delete";
    deleteBtn.addEventListener("click", () => deleteGalleryCategoryById(category));
    actionCell.appendChild(deleteBtn);
    row.appendChild(actionCell);
    body.appendChild(row);
  });
}
// Sends inline edits for gallery categories through the shared API endpoint.
async function updateGalleryCategoryNameInline(categoryId, inputEl, motherSelect, triggerButton) {
  if (!categoryId) {
    return;
  }
  const category = GALLERY_CATEGORIES.find(c => c.id === categoryId);
  const previousState = {
    name: category?.name || "",
    motherCategoryId: category?.motherCategoryId ?? null
  };
  const trimmedName = (inputEl?.value ?? "").trim();
  if (!trimmedName) {
    showErrorSnackbar({ message: "Category name is required." });
    inputEl?.focus();
    return;
  }
  triggerButton?.setAttribute("disabled", "disabled");
  const motherValue = (motherSelect?.value ?? "").trim();
  try {
    const payload = {
      action: "update_gallery_category",
      id: categoryId,
      name: trimmedName,
      mother_category_id: motherValue
    };
    const result = await sendGalleryRequest(payload);
    const successMessage = result?.message || "Category updated successfully.";
    await reloadGalleryData();
    const categoryLabel = getCategoryLabel({ id: categoryId, name: trimmedName });
    showActionSnackbar({
      message: successMessage,
      instructionLabel: "Undo change",
      onAction: () => {
        void undoGalleryCategoryUpdate(categoryId, previousState, categoryLabel);
      }
    });
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to update category."
    });
  } finally {
    triggerButton?.removeAttribute("disabled");
  }
}

// Rebuilds the photo category dropdown whenever backend data changes.
function updateGalleryPhotoCategorySelect() {
  const selects = qsa("[data-gallery-photo-category]");
  if (!selects.length) {
    return;
  }
  selects.forEach(select => {
    const placeholder = select.querySelector('option[value=""]');
    const placeholderText = placeholder?.textContent?.trim() || "Select a category";
    select.innerHTML = "";
    const emptyOption = document.createElement("option");
    emptyOption.value = "";
    emptyOption.textContent = placeholderText;
    select.appendChild(emptyOption);
    GALLERY_CATEGORIES.forEach(category => {
      const option = document.createElement("option");
      option.value = String(category.id);
      option.textContent = category.name || category.slug || `Category ${category.id}`;
      select.appendChild(option);
    });
  });
}

// Renders the photo thumbnails grid and toggles the "load more" button.
function renderGalleryGrid() {
  const grid = qs("#gallery-thumb-grid");
  if (!grid) {
    return;
  }
  const emptyMessage = qs("#gallery-thumb-empty");
  const loadMoreBtn = qs("#gallery-load-more");
  const filteredPhotos = getGalleryFilteredPhotos(false);
  const totalPhotos = filteredPhotos.length;
  const visible = Math.min(galleryVisibleCount, totalPhotos);
  initializeGalleryGridLoading(grid, visible);
  grid.innerHTML = "";
  if (emptyMessage) {
    emptyMessage.classList.toggle("hidden", totalPhotos > 0);
  }
  const fragment = document.createDocumentFragment();
  for (let i = 0; i < visible; i += 1) {
    const photo = filteredPhotos[i];
    if (!photo) {
      continue;
    }
    fragment.appendChild(
      createGalleryThumbnail(photo, {
        mode: GALLERY_THUMB_PREVIEW_MODE,
        gridElement: grid
      })
    );
  }
  grid.appendChild(fragment);
  if (loadMoreBtn) {
    const hasMore = visible < totalPhotos;
    loadMoreBtn.classList.toggle("hidden", !hasMore);
    loadMoreBtn.disabled = !hasMore;
  }
  updateGallerySearchCount(gallerySearchCountElement, totalPhotos, Boolean(gallerySearchTerm));
  renderPhotoChooserGrid();
}

function renderPhotoChooserGrid() {
  if (!photoChooserGridElement) {
    return;
  }
  const filteredPhotos = getGalleryFilteredPhotos(true);
  const totalPhotos = filteredPhotos.length;
  const visible = Math.min(photoChooserVisibleCount, totalPhotos);
  initializeGalleryGridLoading(photoChooserGridElement, visible);
  photoChooserGridElement.innerHTML = "";
  if (photoChooserEmptyElement) {
    photoChooserEmptyElement.classList.toggle("hidden", totalPhotos > 0);
  }
  const fragment = document.createDocumentFragment();
  for (let i = 0; i < visible; i += 1) {
    const photo = filteredPhotos[i];
    if (!photo) {
      continue;
    }
    const card = createGalleryThumbnail(photo, {
      mode: GALLERY_THUMB_PREVIEW_MODE,
      gridElement: photoChooserGridElement,
      onActivate: () => togglePhotoChooserSelection(photo.id)
    });
    card.classList.add("gallery-thumb-card--selectable");
    if (photoChooserSelectedIds.has(photo.id)) {
      card.classList.add("gallery-thumb-card--selected");
    }
    fragment.appendChild(card);
  }
  photoChooserGridElement.appendChild(fragment);
  if (photoChooserLoadMoreButton) {
    const hasMore = visible < totalPhotos;
    photoChooserLoadMoreButton.classList.toggle("hidden", !hasMore);
    photoChooserLoadMoreButton.disabled = !hasMore;
  }
  updateGallerySearchCount(photoChooserSearchCountElement, totalPhotos, Boolean(photoChooserSearchTerm));
  updatePhotoChooserChooseButtonState();
}

function normalizeGallerySearchTerm(value) {
  return String(value ?? "").trim();
}

function getGalleryFilteredPhotos(isChooser = false) {
  const term = isChooser ? photoChooserSearchTerm : gallerySearchTerm;
  if (!term) {
    return GALLERY_PHOTOS;
  }
  const normalizedTerm = term.toLowerCase();
  return GALLERY_PHOTOS.filter(photo => {
    const haystack = `${photo.title || ""} ${photo.categoryName || ""}`.toLowerCase();
    return haystack.includes(normalizedTerm);
  });
}

function updateGallerySearchCount(element, total, hasQuery) {
  if (!element) {
    return;
  }
  const plural = total === 1 ? "" : "s";
  const suffix = hasQuery ? " match" : " total";
  element.textContent = `${total} photo${plural}${suffix}`;
}

function applyGallerySearchValue(rawValue) {
  gallerySearchTerm = normalizeGallerySearchTerm(rawValue);
  resetGalleryVisibleCount();
  renderGalleryGrid();
}

function applyPhotoChooserSearchValue(rawValue) {
  photoChooserSearchTerm = normalizeGallerySearchTerm(rawValue);
  photoChooserVisibleCount = GALLERY_GRID_INITIAL_COUNT;
  renderPhotoChooserGrid();
}

function setPhotoChooserSelection(ids = []) {
  photoChooserSelectedIds.clear();
  ids.forEach(item => {
    const parsed = Number(item);
    if (Number.isFinite(parsed) && parsed > 0) {
      photoChooserSelectedIds.add(parsed);
    }
  });
}

function togglePhotoChooserSelection(photoId) {
  const normalized = Number(photoId);
  if (!Number.isFinite(normalized) || normalized <= 0) {
    return;
  }
  if (!photoChooserAllowMultiple) {
    photoChooserSelectedIds.clear();
  }
  if (photoChooserSelectedIds.has(normalized)) {
    photoChooserSelectedIds.delete(normalized);
  } else {
    if (!photoChooserAllowMultiple) {
      photoChooserSelectedIds.clear();
    }
    photoChooserSelectedIds.add(normalized);
  }
  renderPhotoChooserGrid();
}

function updatePhotoChooserChooseButtonState() {
  if (!photoChooserChooseButton) {
    return;
  }
  photoChooserChooseButton.disabled = photoChooserSelectedIds.size === 0;
}

function openPhotoChooserModal({ allowMultiple = true, initialSelection = [], onChoose = null } = {}) {
  if (!photoChooserModalElement) {
    return;
  }
  photoChooserAllowMultiple = Boolean(allowMultiple);
  photoChooserOnChoose = typeof onChoose === "function" ? onChoose : null;
  setPhotoChooserSelection(initialSelection);
  photoChooserVisibleCount = GALLERY_GRID_INITIAL_COUNT;
  renderPhotoChooserGrid();
  photoChooserModalElement.classList.remove("hidden");
  photoChooserModalElement.setAttribute("aria-hidden", "false");
}

function closePhotoChooserModal({ resetCallback = true } = {}) {
  if (!photoChooserModalElement) {
    return;
  }
  photoChooserModalElement.classList.add("hidden");
  photoChooserModalElement.setAttribute("aria-hidden", "true");
  photoChooserSelectedIds.clear();
  if (resetCallback) {
    photoChooserOnChoose = null;
  }
  photoChooserAllowMultiple = true;
}

// Builds each gallery card that appears inside the gallery grid.
function createGalleryThumbnail(photo, options = {}) {
  const card = document.createElement("article");
  card.className = "gallery-thumb-card";
  card.dataset.photoId = String(photo.id ?? "");
  const figure = document.createElement("figure");
  const image = document.createElement("img");
  image.alt = photo.altText || photo.title || "Gallery photo";
  image.loading = "lazy";
  image.decoding = "async";
  image.src = GALLERY_THUMB_PLACEHOLDER;
  const thumbMode =
    typeof options.mode === "string" && options.mode
      ? options.mode
      : GALLERY_THUMB_DEFAULT_MODE;
  const thumbUrl = getGalleryThumbnailUrl(photo, { mode: thumbMode });
  if (thumbUrl) {
    image.dataset.galleryThumbSrc = thumbUrl;
    scheduleGalleryThumbnailLoad(image);
    const gridElement = options.gridElement ?? null;
    if (gridElement) {
      galleryImageGridMap.set(image, gridElement);
    }
  }
  figure.appendChild(image);
  card.appendChild(figure);
  const body = document.createElement("div");
  body.className = "gallery-thumb-card-body";
  const title = document.createElement("span");
  title.className = "gallery-thumb-card-title";
  title.textContent = photo.title || "Untitled photo";
  const meta = document.createElement("div");
  meta.className = "gallery-thumb-card-meta";
  const categoryTag = document.createElement("span");
  categoryTag.textContent = photo.categoryName || "Uncategorized";
  meta.appendChild(categoryTag);
  body.appendChild(title);
  body.appendChild(meta);
  card.appendChild(body);
  const activateCard = () => {
    if (typeof options.onActivate === "function") {
      options.onActivate(photo, card);
      return;
    }
    openGalleryPhotoModal(photo);
  };
  card.tabIndex = 0;
  card.setAttribute("role", "button");
  card.addEventListener("click", activateCard);
  card.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      activateCard();
    }
  });
  return card;
}

function getGalleryThumbnailUrl(photoOrId, options = {}) {
  const id =
    Number(
      (typeof photoOrId === "object" ? photoOrId?.id : photoOrId) ?? 0
    );
  if (!id) {
    return "";
  }
  const timestamp =
    Number(
      (typeof photoOrId === "object"
        ? photoOrId?.uploadedAtTs
        : null) ?? Date.now()
    ) || Date.now();
  const mode =
    typeof options.mode === "string" && options.mode
      ? options.mode
      : GALLERY_THUMB_DEFAULT_MODE;
  return `./api/gallery-thumb.php?id=${encodeURIComponent(
    String(id)
  )}&mode=${encodeURIComponent(mode)}&v=${timestamp}`;
}

function formatGalleryPhotoDate(rawValue) {
  if (!rawValue) {
    return "";
  }
  const parsed = new Date(rawValue);
  if (Number.isNaN(parsed.getTime())) {
    return rawValue;
  }
  return new Intl.DateTimeFormat("fa-IR", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false
  }).format(parsed);
}

function resetGalleryCategoryForm() {
  const nameInput = qs("#gallery-category-name");
  if (nameInput) {
    nameInput.value = "";
  }
  const motherSelect = qs("#gallery-category-mother");
  if (motherSelect) {
    motherSelect.value = "";
  }
  const submitBtn = qs("#gallery-category-submit");
  if (submitBtn) {
    submitBtn.textContent =
      galleryCategorySubmitDefaultText || submitBtn.textContent || "";
  }
  const cancelBtn = qs("#gallery-category-cancel");
  if (cancelBtn) {
    cancelBtn.classList.add("hidden");
  }
}

async function deleteGalleryCategoryById(category) {
  if (!category || !category.id) {
    return;
  }
  const confirmed = await showDialog(
    `Delete "${category.name || category.slug}"?`,
    {
      confirm: true,
      title: "Delete category",
      okText: "Delete",
      cancelText: "Cancel"
    }
  );
  if (!confirmed) {
    return;
  }
  const categoryUndoCache = {
    name: category.name || "",
    motherCategoryId: Number.isFinite(category?.motherCategoryId)
      ? category.motherCategoryId
      : null
  };
  try {
    await sendGalleryRequest({
      action: "delete_gallery_category",
      id: category.id
    });
    await reloadGalleryData();
    resetGalleryCategoryForm();
    const categoryLabel = getCategoryLabel(category);
    showActionSnackbar({
      message: `${categoryLabel} deleted.`,
      instructionLabel: "Undo delete",
      onAction: () => {
        void restoreGalleryCategoryFromUndoCache(categoryUndoCache);
      }
    });
  } catch (err) {
    showErrorSnackbar({
      message: err.message || "Failed to delete category."
    });
  }
}

// Refreshes gallery/state by fetching from the backend and re-rendering affected sections.
async function reloadGalleryData() {
  SERVER_DATABASE_CONNECTED = false;
  try {
    const response = await fetch(API_ENDPOINT);
    if (!response.ok) {
      throw new Error("Failed to refresh gallery data.");
    }
    const payload = await response.json();
    SERVER_DATABASE_CONNECTED = Boolean(payload.databaseConnected);
    updateGalleryStateFromPayload(payload);
    updateBackupListFromPayload(payload);
    renderGalleryCategories();
    renderGalleryGrid();
    updateKpis();
    renderSqlRunnerStatus();
  } catch (err) {
    SERVER_DATABASE_CONNECTED = false;
    console.warn("Failed to refresh gallery data:", err);
    renderSqlRunnerStatus();
  }
}

// Helper to POST gallery actions to the shared API and fail loudly on backend errors.
async function sendGalleryRequest(body) {
  const response = await fetch(API_ENDPOINT, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body)
  });
  let payload = {};
  try {
    payload = await response.json();
  } catch {
    throw new Error("Invalid response from server.");
  }
  if (!response.ok) {
    throw new Error(
      payload?.message || "The server failed to process the request."
    );
  }
  if (payload && payload.status === "error") {
    throw new Error(payload.message || "The server reported an error.");
  }
  return payload;
}

// Syncs the panel title between the sidebar, document title, and localStorage.
function applyPanelTitle(value, persist = false) {
  const name = value ?? localStorage.getItem(PANEL_TITLE_KEY) ?? PANEL_TITLE_DEFAULT;
  const finalTitle = typeof name === 'string' && name.length ? name : PANEL_TITLE_DEFAULT;
  if (persist && typeof finalTitle === "string") {
    localStorage.setItem(PANEL_TITLE_KEY, finalTitle);
  }
  const el = qs('.sidebar .title');
  if (el) el.textContent = finalTitle;
  document.title = finalTitle;
  localStorage.setItem(TITLE_KEY, finalTitle);
}

// Sends add/update/delete user intent to the backend without waiting for a response.
async function syncUserToBackend(action, payload) {
  try {
    const body =
      action === "delete_user"
        ? { action, code: payload?.code ?? "" }
        : { action, user: payload };
    await fetch(API_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });
  } catch (err) {
    console.warn("Failed to sync user to backend:", err);
  }
}

// Persists panel settings changes to the backend store for the next load.
async function syncSettings(payload) {
  try {
    const response = await fetch(API_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "save_settings", settings: payload })
    });
    if (!response.ok) {
      console.warn("Failed to sync settings to backend:", response.statusText);
      return false;
    }
    return true;
  } catch (err) {
    console.warn("Failed to sync settings to backend:", err);
    return false;
  }
}

async function executeSqlQuery(sql) {
  const trimmed = (sql ?? "").trim();
  if (trimmed === "") {
    throw new Error("SQL query cannot be empty.");
  }
  let response;
  try {
    response = await fetch(API_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      body: JSON.stringify({ action: "run_sql_query", sql: trimmed })
    });
  } catch {
    throw new Error("Unable to reach the server.");
  }
  let payload = {};
  try {
    payload = await response.json();
  } catch {
    throw new Error("Failed to parse server response.");
  }
  if (!response.ok || payload?.status === "error") {
    throw new Error(payload?.message || "Failed to execute SQL query.");
  }
  return payload;
}

async function sendAccountAction(action, payload = {}) {
  if (!action) {
    throw new Error("Missing account action.");
  }
  const body = { action, ...payload };
  let response;
  try {
    response = await fetch(API_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json"
      },
      body: JSON.stringify(body)
    });
  } catch (error) {
    throw new Error("Unable to reach the server.");
  }
  let data = {};
  try {
    data = await response.json();
  } catch {
    data = {};
  }
  if (!response.ok || data.status === "error") {
    throw new Error(data.message || "Unable to save account information.");
  }
  return data;
}

const DEFAULT_TOAST_DURATION = 5000;
const DEFAULT_TOAST_ROOT_ID = "default-toast-root";

function ensureDefaultToastRoot() {
  let root = document.getElementById(DEFAULT_TOAST_ROOT_ID);
  if (root) {
    return root;
  }
  const host = document.body ?? document.documentElement;
  if (!host) {
    return null;
  }
  root = document.createElement("div");
  root.id = DEFAULT_TOAST_ROOT_ID;
  root.className = "default-toast-root";
  host.appendChild(root);
  return root;
}

function showDefaultToast(content, { duration = DEFAULT_TOAST_DURATION } = {}) {
  if (!content) {
    return null;
  }
  const root = ensureDefaultToastRoot();
  if (!root) {
    return null;
  }
  const toast = document.createElement("div");
  toast.className = "default-toast";
  toast.setAttribute("role", "status");
  toast.setAttribute("aria-live", "polite");
  toast.setAttribute("dir", "rtl");

  const messageNode = document.createElement("div");
  messageNode.className = "default-toast__message";
  if (content instanceof Node) {
    messageNode.appendChild(content);
  } else {
    messageNode.textContent = String(content);
  }
  toast.appendChild(messageNode);

  const progress = document.createElement("div");
  progress.className = "default-toast__progress";
  const progressBar = document.createElement("span");
  progressBar.className = "default-toast__progress-bar";
  progressBar.style.animation = `default-toast-progress ${duration}ms linear forwards`;
  progressBar.style.animationPlayState = "running";
  progressBar.style.animationPlayState = "running";
  progress.appendChild(progressBar);
  toast.appendChild(progress);

  root.appendChild(toast);
  requestAnimationFrame(() => {
    toast.classList.add("default-toast--visible");
  });

  let remaining = duration;
  let timerStart = performance.now();
  let dismissTimer = window.setTimeout(dismiss, remaining);
  let isDismissing = false;
  let paused = false;

  function dismiss() {
    if (isDismissing) {
      return;
    }
    isDismissing = true;
    clearTimeout(dismissTimer);
    toast.classList.remove("default-toast--visible");
    toast.addEventListener(
      "transitionend",
      event => {
        if (event.propertyName === "opacity") {
          toast.remove();
        }
      },
      { once: true }
    );
  }

  toast.addEventListener("click", dismiss);
  toast.addEventListener("mouseenter", () => {
    if (paused || isDismissing) {
      return;
    }
    paused = true;
    clearTimeout(dismissTimer);
    const elapsed = performance.now() - timerStart;
    remaining = Math.max(0, remaining - elapsed);
    progressBar.style.animationPlayState = "paused";
  });
  toast.addEventListener("mouseleave", () => {
    if (!paused || isDismissing || remaining <= 0) {
      return;
    }
    paused = false;
    timerStart = performance.now();
    dismissTimer = window.setTimeout(dismiss, remaining);
    progressBar.style.animationPlayState = "running";
  });

  return {
    dismiss
  };
}


const DEFAULT_SNACKBAR_ACTION_SHORTCUT = { key: "z", ctrlKey: true };
const DEFAULT_SNACKBAR_ROOT_ID = "default-snackbar-root";
const ERROR_SNACKBAR_COPY_HINT = "Click to copy error info";
const snackbarStack = [];
let isSnackbarShortcutBound = false;

function handleSnackbarKey(event) {
  if (!snackbarStack.length) {
    return;
  }
  const target = snackbarStack[0];
  if (!target || !target.shortcut) {
    return;
  }
  if (
    event.target instanceof HTMLInputElement ||
    event.target instanceof HTMLTextAreaElement ||
    (event.target && event.target.isContentEditable)
  ) {
    return;
  }
  const shortcut = target.shortcut;
  if (!shortcut.key || event.key.toLowerCase() !== shortcut.key.toLowerCase()) {
    return;
  }
  if (Boolean(shortcut.ctrlKey) !== event.ctrlKey) {
    return;
  }
  if (Boolean(shortcut.altKey) !== event.altKey) {
    return;
  }
  if (Boolean(shortcut.shiftKey) !== event.shiftKey) {
    return;
  }
  if (Boolean(shortcut.metaKey) !== event.metaKey) {
    return;
  }
  event.preventDefault();
  target.action?.();
}

function registerSnackbar(entry) {
  snackbarStack.push(entry);
  if (!isSnackbarShortcutBound) {
    document.addEventListener("keydown", handleSnackbarKey);
    isSnackbarShortcutBound = true;
  }
}

function unregisterSnackbar(entry) {
  const index = snackbarStack.indexOf(entry);
  if (index !== -1) {
    snackbarStack.splice(index, 1);
  }
  if (!snackbarStack.length && isSnackbarShortcutBound) {
    document.removeEventListener("keydown", handleSnackbarKey);
    isSnackbarShortcutBound = false;
  }
}

function ensureDefaultSnackbarRoot() {
  let root = document.getElementById(DEFAULT_SNACKBAR_ROOT_ID);
  if (root) {
    return root;
  }
  const host = document.body ?? document.documentElement;
  if (!host) {
    return null;
  }
  root = document.createElement("div");
  root.id = DEFAULT_SNACKBAR_ROOT_ID;
  root.className = "default-snackbar-root";
  host.appendChild(root);
  return root;
}

function fallbackCopyText(text) {
  return new Promise((resolve, reject) => {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    textarea.style.top = "-9999px";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    let successful = false;
    try {
      successful = document.execCommand("copy");
    } catch (error) {
      textarea.remove();
      return reject(error);
    }
    textarea.remove();
    if (successful) {
      resolve();
    } else {
      reject(new Error("copy command failed"));
    }
  });
}

function copyTextToClipboard(text) {
  if (!text) {
    return Promise.resolve();
  }
  if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
    return navigator.clipboard.writeText(text).catch(() => fallbackCopyText(text));
  }
  return fallbackCopyText(text);
}

function showActionSnackbar({
  message,
  duration = DEFAULT_TOAST_DURATION,
  onAction,
  shortcut = DEFAULT_SNACKBAR_ACTION_SHORTCUT,
  instructionLabel = "Click for undo or press Ctrl + Z"
} = {}) {
  if (!message) {
    return null;
  }
  const root = ensureDefaultSnackbarRoot();
  if (!root) {
    return null;
  }
  const snackbar = document.createElement("div");
  snackbar.className = "default-snackbar";
  snackbar.setAttribute("role", "status");
  snackbar.setAttribute("aria-live", "polite");
  snackbar.setAttribute("dir", "rtl");
  snackbar.tabIndex = 0;
  let entry = null;

  const content = document.createElement("div");
  content.className = "default-snackbar__content";
  const messageNode = document.createElement("div");
  messageNode.textContent = String(message);
  content.appendChild(messageNode);
  const timerNode = document.createElement("span");
  timerNode.className = "default-snackbar__timer";
  content.appendChild(timerNode);
  snackbar.appendChild(content);

  const instructionNode = document.createElement("div");
  instructionNode.className = "default-snackbar__instruction";
  instructionNode.textContent = instructionLabel;
  snackbar.appendChild(instructionNode);

  const progress = document.createElement("div");
  progress.className = "default-snackbar__progress";
  const progressBar = document.createElement("span");
  progressBar.className = "default-snackbar__progress-bar";
  progressBar.style.animation = `default-toast-progress ${duration}ms linear forwards`;
  progressBar.style.animationPlayState = "running";
  progress.appendChild(progressBar);
  snackbar.appendChild(progress);

  root.appendChild(snackbar);
  requestAnimationFrame(() => {
    snackbar.classList.add("default-snackbar--visible");
    progressBar.style.transform = "scaleX(0)";
  });

  let remaining = duration;
  let timerStart = performance.now();
  const updateTimer = () => {
    const elapsed = performance.now() - timerStart;
    const current = Math.max(0, remaining - elapsed);
    timerNode.textContent = `${Math.ceil(current / 1000)}s`;
  };
  updateTimer();
  let timerInterval = window.setInterval(updateTimer, 100);
  let dismissTimer = window.setTimeout(dismiss, remaining);
  let paused = false;

  function cleanup() {
    clearTimeout(dismissTimer);
    clearInterval(timerInterval);
    if (entry) {
      unregisterSnackbar(entry);
    }
  }

  let actionHandled = false;
  function performAction() {
    if (actionHandled) {
      return;
    }
    actionHandled = true;
    if (typeof onAction === "function") {
      onAction();
    }
    dismiss();
  }


  function dismiss() {
    if (!snackbar.parentElement) {
      return;
    }
    cleanup();
    snackbar.classList.remove("default-snackbar--visible");
    snackbar.addEventListener(
      "transitionend",
      event => {
        if (event.propertyName === "opacity" || event.propertyName === "transform") {
          snackbar.remove();
        }
      },
      { once: true }
    );
  }

  snackbar.addEventListener("click", performAction);
  snackbar.addEventListener("keydown", event => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      performAction();
    }
  });
  snackbar.addEventListener("mouseenter", () => {
    if (paused || !timerInterval) {
      return;
    }
    paused = true;
    clearTimeout(dismissTimer);
    clearInterval(timerInterval);
    const elapsed = performance.now() - timerStart;
    remaining = Math.max(0, remaining - elapsed);
    progressBar.style.animationPlayState = "paused";
    updateTimer();
  });
  snackbar.addEventListener("mouseleave", () => {
    if (!paused || remaining <= 0) {
      return;
    }
    paused = false;
    timerStart = performance.now();
    timerInterval = window.setInterval(updateTimer, 100);
    dismissTimer = window.setTimeout(dismiss, remaining);
    progressBar.style.animationPlayState = "running";
  });

  entry = {
    action: performAction,
    shortcut,
    snackbar
  };
  registerSnackbar(entry);

  return {
    dismiss,
    action: performAction
  };
}

function showErrorSnackbar({ message, duration = DEFAULT_TOAST_DURATION } = {}) {
  if (!message) {
    return null;
  }
  const root = ensureDefaultSnackbarRoot();
  if (!root) {
    return null;
  }
  const snackbar = document.createElement("div");
  snackbar.className = "default-snackbar default-snackbar--error";
  snackbar.setAttribute("role", "alert");
  snackbar.setAttribute("dir", "rtl");
  snackbar.tabIndex = 0;

  const messageNode = document.createElement("div");
  messageNode.className = "default-snackbar__content";
  const messageText = String(message);
  messageNode.textContent = messageText;
  snackbar.appendChild(messageNode);
  const instructionNode = document.createElement("div");
  instructionNode.className = "default-snackbar__instruction default-snackbar__instruction--error";
  instructionNode.textContent = ERROR_SNACKBAR_COPY_HINT;
  snackbar.appendChild(instructionNode);

  const progress = document.createElement("div");
  progress.className = "default-snackbar__progress";
  const progressBar = document.createElement("span");
  progressBar.className = "default-snackbar__progress-bar";
  progressBar.style.animation = `default-toast-progress ${duration}ms linear forwards`;
  progress.appendChild(progressBar);
  snackbar.appendChild(progress);

  root.appendChild(snackbar);
  requestAnimationFrame(() => {
    snackbar.classList.add("default-snackbar--visible");
    progressBar.style.transform = "scaleX(0)";
  });

  let remaining = duration;
  let timerStart = performance.now();
  let dismissTimer = window.setTimeout(dismiss, remaining);
  let paused = false;

  function dismiss() {
    if (!snackbar.parentElement) {
      return;
    }
    clearTimeout(dismissTimer);
    snackbar.classList.remove("default-snackbar--visible");
    snackbar.addEventListener(
      "transitionend",
      (event) => {
        if (event.propertyName === "opacity" || event.propertyName === "transform") {
          snackbar.remove();
        }
      },
      { once: true }
    );
  }

  snackbar.addEventListener("click", () => {
    void copyTextToClipboard(messageText);
    dismiss();
  });
  snackbar.addEventListener("mouseenter", () => {
    if (paused || remaining <= 0) {
      return;
    }
    paused = true;
    clearTimeout(dismissTimer);
    const elapsed = performance.now() - timerStart;
    remaining = Math.max(0, remaining - elapsed);
    progressBar.style.animationPlayState = "paused";
  });
  snackbar.addEventListener("mouseleave", () => {
    if (!paused || remaining <= 0) {
      return;
    }
    paused = false;
    timerStart = performance.now();
    dismissTimer = window.setTimeout(dismiss, remaining);
    progressBar.style.animationPlayState = "running";
  });

  return {
    dismiss
  };
}

function qs(sel, root = document) { return root.querySelector(sel); }
function qsa(sel, root = document) { return [...root.querySelectorAll(sel)]; }

// Sets up all uploaders in the developer area to show previews and clear buttons.
function initPhotoUploaders() {
  qsa("[data-photo-uploader]").forEach(uploader => {
    const input = qs("[data-photo-input]", uploader);
    const uploadBtn = qs("[data-photo-upload]", uploader);
    const clearBtn = qs("[data-photo-clear]", uploader);
    if (!input || !uploadBtn) {
      return;
    }
    uploadBtn.addEventListener("click", () => input.click());
    input.addEventListener("change", () => updatePhotoUploaderPreview(uploader));
    clearBtn?.addEventListener("click", () => {
      input.value = "";
      updatePhotoUploaderPreview(uploader);
    });
    updatePhotoUploaderPreview(uploader);
  });
}

function updatePhotoUploaderPreview(uploader) {
  if (!uploader) {
    return;
  }
  const input = qs("[data-photo-input]", uploader);
  const preview = qs("[data-photo-image]", uploader);
  const placeholder = qs("[data-photo-placeholder]", uploader);
  const clearBtn = qs("[data-photo-clear]", uploader);
  if (!input) {
    return;
  }
  const file = input.files?.[0] ?? null;
  if (file && preview) {
    const reader = new FileReader();
    reader.addEventListener("load", () => {
      preview.src = reader.result ?? "";
      preview.classList.remove("hidden");
      placeholder?.classList.add("hidden");
    });
    reader.readAsDataURL(file);
  } else {
    if (preview) {
      preview.removeAttribute("src");
      preview.classList.add("hidden");
    }
    placeholder?.classList.remove("hidden");
  }
  if (clearBtn) {
    clearBtn.classList.toggle("hidden", !file);
  }
}

let galleryUploadModalElement;
let galleryUploadModalFormElement;
let galleryUploadModalUploader;

function openGalleryUploadModal() {
  if (!galleryUploadModalElement) {
    return;
  }
  galleryUploadModalElement.classList.remove("hidden");
  galleryUploadModalElement.setAttribute("aria-hidden", "false");
  if (galleryUploadModalFormElement) {
    galleryUploadModalFormElement.reset();
    updatePhotoUploaderPreview(galleryUploadModalUploader);
    galleryUploadModalFormElement.querySelector('input[name="title"]')?.focus();
  }
}

function closeGalleryUploadModal() {
  if (!galleryUploadModalElement) {
    return;
  }
  galleryUploadModalElement.classList.add("hidden");
  galleryUploadModalElement.setAttribute("aria-hidden", "true");
}

let galleryPhotoModalElement;
let galleryPhotoModalFormElement;
let galleryPhotoModalImageElement;
let galleryPhotoModalLinkElement;
let galleryPhotoModalTitleInput;
let galleryPhotoModalAltInput;
let galleryPhotoModalReplaceInput;
let galleryPhotoModalReplaceButton;
let galleryPhotoModalDeleteButton;
let galleryPhotoModalCloseButton;
let galleryPhotoModalCategorySelect;
let galleryPhotoModalSaveButton;
let galleryPhotoModalCreatedElement;
let galleryModalActivePhotoId = 0;

function getGalleryPhotoFileUrl(photo) {
  if (!photo) {
    return "";
  }
  const filename = normalizeValue(photo.filename);
  if (!filename) {
    return getGalleryThumbnailUrl(photo);
  }
  if (filename.startsWith("/") || filename.startsWith("./")) {
    return filename;
  }
  return `./${filename}`;
}

function normalizeSiteIconHref(value) {
  if (!value) {
    return "";
  }
  const trimmed = value.trim();
  if (trimmed === "") {
    return "";
  }
  if (/^(?:data:|https?:\/\/|\/\/)/i.test(trimmed)) {
    return trimmed;
  }
  if (trimmed.startsWith("/") || trimmed.startsWith("./") || trimmed.startsWith("../")) {
    return trimmed;
  }
  return `./${trimmed}`;
}

function updateFaviconLink(iconUrl) {
  if (!faviconLinkElement) {
    return;
  }
  faviconLinkElement.href = iconUrl || SITE_ICON_DEFAULT_DATA_URI;
}

function updateSidebarLogoIcon(iconUrl) {
  const hasIcon = Boolean(iconUrl);
  if (sidebarLogoImage) {
    if (hasIcon) {
      sidebarLogoImage.src = iconUrl;
      sidebarLogoImage.classList.remove("hidden");
    } else {
      sidebarLogoImage.removeAttribute("src");
      sidebarLogoImage.classList.add("hidden");
    }
  }
  if (sidebarLogoText) {
    sidebarLogoText.classList.toggle("hidden", hasIcon);
  }
}

function updateSiteIconPreview(iconUrl) {
  const hasIcon = Boolean(iconUrl);
  if (siteIconPreviewImage) {
    if (hasIcon) {
      siteIconPreviewImage.src = iconUrl;
      siteIconPreviewImage.classList.remove("hidden");
    } else {
      siteIconPreviewImage.removeAttribute("src");
      siteIconPreviewImage.classList.add("hidden");
    }
  }
  if (siteIconPlaceholder) {
    siteIconPlaceholder.classList.toggle("hidden", hasIcon);
  }
  if (siteIconClearButton) {
    siteIconClearButton.disabled = !hasIcon;
  }
}

function applySiteIconValue(value) {
  siteIconValue = typeof value === "string" ? value.trim() : "";
  const iconUrl = normalizeSiteIconHref(siteIconValue);
  updateSiteIconPreview(iconUrl);
  updateSidebarLogoIcon(iconUrl);
  updateFaviconLink(iconUrl);
}

function persistSiteIconChange(nextIcon, successMessage) {
  const previousIcon = siteIconValue;
  applySiteIconValue(nextIcon);
  SERVER_SETTINGS.siteIcon = nextIcon;
  void syncSettings({ siteIcon: nextIcon }).then(success => {
    if (success) {
      showActionSnackbar({
        message: successMessage,
        instructionLabel: "Undo change",
        onAction: () => {
          applySiteIconValue(previousIcon);
          SERVER_SETTINGS.siteIcon = previousIcon;
          void syncSettings({ siteIcon: previousIcon });
        }
      });
    } else {
      applySiteIconValue(previousIcon);
      SERVER_SETTINGS.siteIcon = previousIcon;
      showErrorSnackbar({ message: "Failed to save site icon." });
    }
  });
}

function findGalleryPhotoMatchingSiteIcon() {
  if (!siteIconValue) {
    return null;
  }
  return GALLERY_PHOTOS.find(photo => {
    const filename = normalizeValue(photo.filename);
    if (filename && filename === siteIconValue) {
      return true;
    }
    const url = getGalleryPhotoFileUrl(photo);
    return url === siteIconValue;
  });
}

function openGalleryPhotoModal(photo) {
  if (!photo || !galleryPhotoModalElement || !galleryPhotoModalFormElement) {
    return;
  }
  galleryModalActivePhotoId = photo.id;
  const photoUrl = getGalleryPhotoFileUrl(photo);
  if (galleryPhotoModalImageElement) {
    galleryPhotoModalImageElement.src = photoUrl;
    galleryPhotoModalImageElement.alt = photo.altText || photo.title || "Gallery photo preview";
  }
  if (galleryPhotoModalLinkElement) {
    galleryPhotoModalLinkElement.href = photoUrl;
  }
  if (galleryPhotoModalTitleInput) {
    galleryPhotoModalTitleInput.value = photo.title || "";
  }
  if (galleryPhotoModalAltInput) {
    galleryPhotoModalAltInput.value = photo.altText || "";
  }
  if (galleryPhotoModalCategorySelect) {
    galleryPhotoModalCategorySelect.value = String(photo.categoryId || "");
  }
  if (galleryPhotoModalCreatedElement) {
    const formattedDate = formatGalleryPhotoDate(photo.uploadedAt);
    galleryPhotoModalCreatedElement.textContent = formattedDate ? `Created ${formattedDate}` : "";
  }
  if (galleryPhotoModalReplaceInput) {
    galleryPhotoModalReplaceInput.value = "";
  }
  galleryPhotoModalElement.classList.remove("hidden");
  galleryPhotoModalElement.setAttribute("aria-hidden", "false");
}

function closeGalleryPhotoModal() {
  if (!galleryPhotoModalElement) {
    return;
  }
  galleryModalActivePhotoId = 0;
  galleryPhotoModalElement.classList.add("hidden");
  galleryPhotoModalElement.setAttribute("aria-hidden", "true");
  galleryPhotoModalFormElement?.reset();
  if (galleryPhotoModalReplaceInput) {
    galleryPhotoModalReplaceInput.value = "";
  }
}

function initGalleryPhotoModalKeyboardShortcuts() {
  document.addEventListener("keydown", (event) => {
    if (
      !galleryPhotoModalElement ||
      galleryPhotoModalElement.classList.contains("hidden")
    ) {
      return;
    }
    const target = event.target;
    const isInput =
      target instanceof HTMLInputElement ||
      target instanceof HTMLTextAreaElement ||
      (target && target.isContentEditable);
    if (event.key === "Escape") {
      event.preventDefault();
      closeGalleryPhotoModal();
      return;
    }
    if (event.key === "Delete" && !isInput) {
      event.preventDefault();
      galleryPhotoModalDeleteButton?.click();
      return;
    }
    if (event.key === "Enter" && !isInput) {
      event.preventDefault();
      galleryPhotoModalSaveButton?.click();
    }
  });
}

async function sendGalleryFormRequest(formData) {
  const response = await fetch(API_ENDPOINT, { method: "POST", body: formData });
  let payload = {};
  try {
    payload = await response.json();
  } catch {
    payload = {};
  }
  if (!response.ok) {
    throw new Error(payload?.message || "The server failed to process the request.");
  }
  if (payload && payload.status === "error") {
    throw new Error(payload.message || "The server reported an error.");
  }
  return payload;
}

async function handleGalleryPhotoModalSave(event) {
  event.preventDefault();
  if (!galleryModalActivePhotoId) {
    return;
  }
  const title = (galleryPhotoModalTitleInput?.value ?? "").trim();
  const alt = (galleryPhotoModalAltInput?.value ?? "").trim();
  if (!title) {
    showErrorSnackbar({ message: "Photo title is required." });
    galleryPhotoModalTitleInput?.focus();
    return;
  }
  const categoryValue = (galleryPhotoModalCategorySelect?.value ?? "").trim();
  const parsedCategoryId = Number(categoryValue);
  const normalizedCategoryId =
    Number.isFinite(parsedCategoryId) && parsedCategoryId > 0
      ? parsedCategoryId
      : null;
  const previousPhoto = GALLERY_PHOTOS.find(p => p.id === galleryModalActivePhotoId);
  const previousSnapshot = previousPhoto
    ? {
        title: previousPhoto.title || "",
        altText: previousPhoto.altText || "",
        categoryId: previousPhoto.categoryId ?? null
      }
    : null;
  galleryPhotoModalSaveButton?.setAttribute("disabled", "disabled");
  try {
    await sendGalleryRequest({
      action: "update_gallery_photo",
      photo_id: galleryModalActivePhotoId,
      title,
      alt_text: alt,
      category_id: normalizedCategoryId
    });
    await reloadGalleryData();
    const refreshedPhoto = GALLERY_PHOTOS.find(p => p.id === galleryModalActivePhotoId);
    if (refreshedPhoto) {
      openGalleryPhotoModal(refreshedPhoto);
    } else {
      closeGalleryPhotoModal();
    }
    showActionSnackbar({
      message: "Photo details updated.",
      instructionLabel: "Undo change",
      onAction: () => {
        void undoGalleryPhotoUpdate(galleryModalActivePhotoId, previousSnapshot);
      }
    });
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to update photo."
    });
  } finally {
    galleryPhotoModalSaveButton?.removeAttribute("disabled");
  }
}

async function handleGalleryPhotoReplace(file) {
  if (!file || !galleryModalActivePhotoId) {
    return;
  }
  galleryPhotoModalReplaceButton?.setAttribute("disabled", "disabled");
  const formData = new FormData();
  formData.set("action", "replace_gallery_photo");
  formData.set("photo_id", String(galleryModalActivePhotoId));
  formData.set("photo", file);
  try {
    await sendGalleryFormRequest(formData);
    await reloadGalleryData();
    showDefaultToast("Photo replaced successfully.");
    const refreshedPhoto = GALLERY_PHOTOS.find(p => p.id === galleryModalActivePhotoId);
    if (refreshedPhoto) {
      openGalleryPhotoModal(refreshedPhoto);
    } else {
      closeGalleryPhotoModal();
    }
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to replace photo."
    });
  } finally {
    galleryPhotoModalReplaceButton?.removeAttribute("disabled");
  }
}

async function handleGalleryPhotoDelete() {
  if (!galleryModalActivePhotoId) {
    return;
  }
  const photo = GALLERY_PHOTOS.find(p => p.id === galleryModalActivePhotoId);
  const title = photo?.title || photo?.filename || "this photo";
  const confirmed = await showDialog(
    `Delete "${title}"?`,
    {
      confirm: true,
      title: "Delete photo",
      okText: "Delete",
      cancelText: "Cancel"
    }
  );
  if (!confirmed) {
    return;
  }
  const undoCache = {
    title,
    altText: photo?.altText || "",
    categoryId: Number.isFinite(photo?.categoryId) ? photo.categoryId : null,
    filename: photo?.filename || ""
  };
  undoCache.blob = await downloadGalleryPhotoBlobForUndo(photo);
  try {
    await sendGalleryRequest({
      action: "delete_gallery_photo",
      photo_id: galleryModalActivePhotoId
    });
    closeGalleryPhotoModal();
    await reloadGalleryData();
    showActionSnackbar({
      message: `${title} deleted.`,
      instructionLabel: undoCache.blob ? "Undo delete" : "Click to refresh photos",
      onAction: undoCache.blob
        ? () => {
            void restoreGalleryPhotoFromUndoCache(undoCache);
          }
        : () => {
            reloadGalleryData();
          }
    });
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to delete photo."
    });
  }
}

async function downloadGalleryPhotoBlobForUndo(photo) {
  if (!photo) {
    return null;
  }
  const url = getGalleryPhotoFileUrl(photo);
  if (!url) {
    return null;
  }
  try {
    const response = await fetch(url);
    if (!response.ok) {
      return null;
    }
    return await response.blob();
  } catch (err) {
    console.warn("Failed to cache gallery photo for undo:", err);
    return null;
  }
}

async function restoreGalleryPhotoFromUndoCache(cache) {
  if (!cache?.blob) {
    showErrorSnackbar({
      message: "Unable to undo this action."
    });
    return;
  }
  const { title, altText, categoryId, filename } = cache;
  const formData = new FormData();
  formData.set("action", "add_gallery_photo");
  formData.set("title", title || "");
  formData.set("alt_text", altText || "");
  if (typeof categoryId === "number" && categoryId > 0) {
    formData.set("category_id", String(categoryId));
  }
  const fallbackName = filename ? filename.split("/").pop() : "";
  const blobName = fallbackName || `photo-restore-${Date.now()}`;
  formData.set("photo", cache.blob, blobName);
  try {
    const response = await fetch(API_ENDPOINT, { method: "POST", body: formData });
    let payload = {};
    try {
      payload = await response.json();
    } catch {
      payload = {};
    }
    if (!response.ok) {
      throw new Error(payload?.message || "The server failed to restore the photo.");
    }
    if (payload?.status === "error") {
      throw new Error(payload.message || "The server reported an error while restoring the photo.");
    }
    cache.blob = null;
    showDefaultToast(payload?.message || "Photo restored.");
    await reloadGalleryData();
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to restore photo."
    });
  }
}

async function restoreGalleryCategoryFromUndoCache(cache) {
  if (!cache?.name) {
    showErrorSnackbar({
      message: "Unable to undo this action."
    });
    return;
  }
  const payload = {
    action: "add_gallery_category",
    name: cache.name,
    mother_category_id: cache.motherCategoryId ?? ""
  };
  try {
    const result = await sendGalleryRequest(payload);
    showDefaultToast(result?.message || "Category restored.");
    await reloadGalleryData();
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to restore category."
    });
  }
}

async function undoGalleryCategoryUpdate(categoryId, previousState, label) {
  if (!categoryId || !previousState?.name) {
    showErrorSnackbar({
      message: "Unable to undo this change."
    });
    return;
  }
  const payload = {
    action: "update_gallery_category",
    id: categoryId,
    name: previousState.name,
    mother_category_id: previousState.motherCategoryId ?? ""
  };
  try {
    const result = await sendGalleryRequest(payload);
    await reloadGalleryData();
    showDefaultToast(`${label || "Category"} restored.`);
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to revert category."
    });
  }
}

async function undoGalleryPhotoUpdate(photoId, previousSnapshot) {
  if (!photoId || !previousSnapshot) {
    showErrorSnackbar({
      message: "Unable to undo this change."
    });
    return;
  }
  const payload = {
    action: "update_gallery_photo",
    photo_id: photoId,
    title: previousSnapshot.title || "",
    alt_text: previousSnapshot.altText || "",
    category_id: previousSnapshot.categoryId ?? ""
  };
  try {
    await sendGalleryRequest(payload);
    await reloadGalleryData();
    const restoredPhoto = GALLERY_PHOTOS.find(p => p.id === photoId);
    if (restoredPhoto) {
      openGalleryPhotoModal(restoredPhoto);
    }
    showDefaultToast("Changes reverted.");
  } catch (error) {
    showErrorSnackbar({
      message: error?.message || "Failed to revert photo."
    });
  }
}

// Highlights the requested tab and updates the page title bar.
function setActiveTab(tab) {
  qsa('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  qsa('.tab').forEach(t => t.classList.toggle('active', t.id === `tab-${tab}`));
  const titles = { home: 'Home', users: 'Users', devsettings: 'Developer Settings', settings: 'Settings' };
  const el = qs('#page-title');
  if (el) el.textContent = titles[tab] || '';
}

function createTextCell(value) {
  const td = document.createElement('td');
  td.textContent = value == null ? '' : String(value);
  return td;
}

function getUserDisplayName(user) {
  return (
    normalizeValue(user.name) ||
    normalizeValue(user.fullname) ||
    normalizeValue(user.username)
  );
}

// Rebuilds the users table from the current USER_DB snapshot.
function renderUsers() {
  const tbody = qs('#users-body');
  if (!tbody) return;
  tbody.innerHTML = '';
  USER_DB.forEach(user => {
    const tr = document.createElement('tr');
    tr.appendChild(createTextCell(user.code));
    tr.appendChild(createTextCell(getUserDisplayName(user)));
    tr.appendChild(createTextCell(user.phone));
    tr.appendChild(createTextCell(user.work_id));
    tr.appendChild(createTextCell(user.id_number));
    tr.appendChild(createTextCell(user.email));
    const actionCell = document.createElement('td');
    actionCell.classList.add('users-actions-cell');
    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'btn ghost';
    editBtn.textContent = 'Edit';
    editBtn.addEventListener('click', () => openUserModal(user));
    // The delete CTA uses the primary theme so it visually matches the requested styling.
    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'btn primary';
    deleteBtn.textContent = 'Delete';
    deleteBtn.addEventListener('click', () => openDeleteModal(user));
    actionCell.appendChild(editBtn);
    actionCell.appendChild(deleteBtn);
    tr.appendChild(actionCell);
    tbody.appendChild(tr);
  });
}

// Prepares and shows the modal that allows adding or editing a user record.
// Opens the user modal and pre-fills fields for edit mode (or blank state for new entries).
function openUserModal(user = null) {
  const form = qs('#user-form');
  if (form) {
    form.dataset.mode = user ? 'edit' : 'add';
  }
  editingUserCode = user?.code ?? '';
  const titleEl = qs('#user-modal-title');
  const submitBtn = qs('#user-form button[type="submit"]');
  if (titleEl) {
    titleEl.textContent = user ? 'Edit user' : 'Add user';
  }
  if (submitBtn) {
    submitBtn.textContent = user ? 'Save' : 'Add';
  }
  const codeInput = qs('#user-code');
  const usernameInput = qs('#user-name');
  const fullnameInput = qs('#user-fullname');
  const phoneInput = qs('#user-phone');
  const workInput = qs('#user-work-id');
  const idInput = qs('#user-id-number');
  const emailInput = qs('#user-email');
  const activeInput = qs('#user-active');
  if (codeInput) {
    codeInput.value = user?.code ?? getNextUserCode();
  }
  if (usernameInput) {
    usernameInput.value = user?.username ?? user?.name ?? '';
  }
  if (fullnameInput) {
    fullnameInput.value = user?.name ?? user?.fullname ?? '';
  }
  if (phoneInput) {
    phoneInput.value = user?.phone ?? '';
  }
  if (workInput) {
    workInput.value = user?.work_id ?? '';
  }
  if (emailInput) {
    emailInput.value = user?.email ?? '';
  }
  if (idInput) {
    idInput.value = user?.id_number ?? '';
  }
  if (activeInput) {
    activeInput.checked = Boolean(user ? user.active : true);
  }
  qs('#user-modal')?.classList.remove('hidden');
}

// Hides the user modal and resets its form.
function closeUserModal() {
  const modal = qs('#user-modal');
  if (!modal) return;
  modal.classList.add('hidden');
  const form = qs('#user-form');
  if (form) {
    form.reset();
    form.dataset.mode = 'add';
  }
  editingUserCode = '';
}

// Opens the delete confirmation modal with the target user's name.
function openDeleteModal(user) {
  if (!user) return;
  deletingUserCode = user.code ?? '';
  const nameHolder = qs('#user-delete-name');
  if (nameHolder) {
    nameHolder.textContent = user.name || user.username || 'this user';
  }
  qs('#user-delete-modal')?.classList.remove('hidden');
}

// Hides the delete modal and clears the temporary state.
function closeDeleteModal() {
  deletingUserCode = '';
  const nameHolder = qs('#user-delete-name');
  if (nameHolder) {
    nameHolder.textContent = '';
  }
  qs('#user-delete-modal')?.classList.add('hidden');
}

// Removes the user from local state and notifies the backend.
function confirmUserDeletion() {
  if (!deletingUserCode) {
    closeDeleteModal();
    return;
  }
  const index = USER_DB.findIndex(u => (u.code ?? '') === deletingUserCode);
  if (index >= 0) {
    const removedUser = { ...USER_DB[index] };
    USER_DB.splice(index, 1);
    renderUsers();
    updateKpis();
    syncUserToBackend('delete_user', { code: deletingUserCode });
    showActionSnackbar({
      message: `${removedUser.fullname || removedUser.username || 'User'} deleted.`,
      onAction: () => {
        const insertPos = Math.min(index, USER_DB.length);
        USER_DB.splice(insertPos, 0, removedUser);
        renderUsers();
        updateKpis();
        showDefaultToast('User restored.');
        syncUserToBackend('add_user', removedUser);
      }
    });
  }
  closeDeleteModal();
}

// Refreshes the KPI cards with the latest user/photo counts and database connectivity.
function updateKpis() {
  const total = USER_DB.length;
  const photoCount = GALLERY_PHOTOS.length;
  const setKpiValue = (selector, value) => {
    const el = qs(selector);
    if (el) el.textContent = value;
  };
  setKpiValue('#kpi-users', total);
  setKpiValue('#kpi-photos', photoCount);
  const statusEl = qs('#kpi-db-status');
  if (statusEl) {
    const connected = SERVER_DATABASE_CONNECTED;
    statusEl.textContent = connected ? 'Connected' : 'Disconnected';
    statusEl.classList.toggle('connected', connected);
    statusEl.classList.toggle('disconnected', !connected);
  }
}

// Updates the live clock in the top bar using the selected timezone.
function renderClock(){
  const el = qs('#live-clock'); if (!el) return;
  const tz = localStorage.getItem(TIMEZONE_KEY) || 'Asia/Tehran';
  const now = new Date();
  const time = now.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute:'2-digit',
    second:'2-digit',
    hour12:false,
    timeZone: tz
  });
  const englishParts = new Intl.DateTimeFormat('en-US', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    numberingSystem: 'latn',
    timeZone: tz
  }).formatToParts(now);
  const partValue = (type) => englishParts.find(p => p.type === type)?.value || '';
  const dateLabel = `${partValue('weekday')}, ${partValue('month')} ${partValue('day')} ${partValue('year')}`.trim();
  const displayName = (window.__CURRENT_USER_NAME || PANEL_TITLE_DEFAULT);
  el.innerHTML = `<span class="time">${time}</span><span class="date">${dateLabel}</span><span class="user">${displayName}</span>`;
}

// Removes the initial spinner after transition end to reveal the SPA.
function hideAppLoader(){
  const loader = qs('#app-loader');
  if (!loader) return;
  loader.classList.add('is-hidden');
  const removeLoader = () => loader.remove();
  loader.addEventListener('transitionend', removeLoader, { once: true });
  setTimeout(removeLoader, 700);
}

// Displays the reusable modal dialog for confirmations and alerts.
function showDialog(message, opts = {}){
  const modal = qs('#dialog-modal');
  const titleEl = qs('#dialog-title');
  const textEl = qs('#dialog-text');
  const okBtn = qs('#dialog-ok');
  const cancelBtn = qs('#dialog-cancel');
  if (!modal || !okBtn || !cancelBtn || !textEl) {
    if (opts.confirm) return confirm(message);
    alert(message);
    return true;
  }
  textEl.textContent = message;
  if (titleEl) titleEl.textContent = opts.title || 'Message';
  if (opts.okText) okBtn.textContent = opts.okText;
  if (opts.cancelText) cancelBtn.textContent = opts.cancelText;
  return new Promise((resolve) => {
    const handleKey = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        close(false);
        return;
      }
      if (event.key === "Enter") {
        if (
          event.target instanceof HTMLInputElement ||
          event.target instanceof HTMLTextAreaElement ||
          (event.target && event.target.isContentEditable)
        ) {
          return;
        }
        event.preventDefault();
        close(true);
      }
    };
    const close = (result) => {
      document.removeEventListener("keydown", handleKey);
      modal.classList.add('hidden');
      okBtn.onclick = null;
      cancelBtn.onclick = null;
      resolve(result);
    };
    okBtn.onclick = () => close(true);
    cancelBtn.onclick = () => close(false);
    document.addEventListener("keydown", handleKey);
    modal.classList.remove('hidden');
    if (!opts.confirm) cancelBtn.classList.add('hidden'); else cancelBtn.classList.remove('hidden');
  });
}

function populateTimezoneSelect(){
  const select = qs('#timezone-select');
  if (!select) return;
  const storedTz = localStorage.getItem(TIMEZONE_KEY) || 'Asia/Tehran';
  function supportedTimeZones(){
    if (typeof Intl !== 'undefined' && Intl.supportedValuesOf){
      try { return Intl.supportedValuesOf('timeZone'); } catch { /* noop */ }
    }
    return [
      'Asia/Tehran','Asia/Dubai','Asia/Baghdad','Asia/Qatar','Asia/Kolkata','Asia/Tokyo','Asia/Shanghai',
      'Europe/Moscow','Europe/Berlin','Europe/Paris','Europe/London','UTC',
      'Africa/Cairo','Africa/Nairobi',
      'America/Sao_Paulo','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Pacific/Auckland'
    ];
  }
  function tzOffsetLabel(tz){
    try {
      const parts = new Intl.DateTimeFormat('en-US',{ timeZone: tz, timeZoneName:'shortOffset'}).formatToParts(new Date());
      const v = parts.find(p=>p.type==='timeZoneName')?.value || '';
      return v.startsWith('GMT')?v:('GMT'+v.replace('UTC',''));
    } catch { return 'GMT'; }
  }
  const zones = supportedTimeZones();
  zones.sort((a,b)=>{
    const ao = tzOffsetLabel(a).replace('GMT','');
    const bo = tzOffsetLabel(b).replace('GMT','');
    return ao.localeCompare(bo) || a.localeCompare(b);
  });
  select.innerHTML = '';
  zones.forEach(z=>{
    const opt = document.createElement('option');
    const label = z.replace(/_/g,'/');
    opt.value = z;
    opt.textContent = `${label} (${tzOffsetLabel(z)})`;
    if (z === storedTz) opt.selected = true;
    select.appendChild(opt);
  });
}

function initSubSidebars(){
  qsa('.sub-layout').forEach(layout => {
    const nav = layout.querySelector('.sub-nav');
    if (!nav) return;
    nav.addEventListener('click', (event) => {
      const trigger = event.target instanceof Element ? event.target.closest('.sub-item[data-pane]') : null;
      if (!trigger) return;
      event.preventDefault();
      const targetPane = trigger.dataset.pane;
      if (!targetPane) return;
      nav.querySelectorAll('.sub-item').forEach(item => item.classList.toggle('active', item === trigger));
      layout.querySelectorAll('.sub-pane').forEach(pane => {
        pane.classList.toggle('active', pane.dataset.pane === targetPane);
      });
    });
  });
}
// Bootstraps the UI once DOM is ready: load data, render galleries/users, and attach all handlers.
document.addEventListener('DOMContentLoaded', async () => {
  try {
    await loadServerData();
  } finally {
    hideAppLoader();
  }
  setActiveTab('home');
  renderUsers();
  updateKpis();
  renderGalleryCategories();
  gallerySearchCountElement = qs("[data-gallery-search-count]");
  photoChooserSearchCountElement = qs("[data-photo-chooser-search-count]");
  renderGalleryGrid();
  initPhotoUploaders();
  const loadMoreGalleryPhotos = qs("#gallery-load-more");
  loadMoreGalleryPhotos?.addEventListener("click", () => {
    galleryVisibleCount += GALLERY_GRID_LOAD_STEP;
    renderGalleryGrid();
    requestAnimationFrame(() => {
      scrollGalleryGridByRows(qs("#gallery-thumb-grid"), 2);
    });
    loadMoreGalleryPhotos.blur();
  });

  // Clicking a nav-item switches tabs and refreshes the header/title.
  const navContainer = qs('.nav');
  navContainer?.addEventListener('click', (event) => {
    const target = event.target;
    const btn = target && target instanceof Element ? target.closest('.nav-item[data-tab]') : null;
    if (!btn) return;
    event.preventDefault();
    const tab = btn.dataset.tab;
    if (tab) {
      setActiveTab(tab);
    }
  });
  const isEditableShortcutTarget = (element) => {
    if (!element) return false;
    if (
      element instanceof HTMLInputElement ||
      element instanceof HTMLTextAreaElement ||
      element instanceof HTMLSelectElement
    ) {
      return true;
    }
    if (element instanceof HTMLElement && element.isContentEditable) {
      return true;
    }
    return false;
  };
  const handleHomeShortcut = (event) => {
    if (event.key !== 'Home' || event.ctrlKey || event.metaKey || event.altKey) {
      return;
    }
    const target = event.target instanceof Element ? event.target : document.activeElement;
    if (isEditableShortcutTarget(target)) {
      return;
    }
    event.preventDefault();
    setActiveTab('home');
  };
  document.addEventListener('keydown', handleHomeShortcut);

  renderClock();
  setInterval(renderClock, 1000);

  // Toggles the collapsed sidebar state.
  qs('#sidebarToggle')?.addEventListener('click', () => {
    const app = qs('#app-view');
    if (!app) return;
    app.classList.toggle('collapsed');
    app.style.gridTemplateColumns = '';
  });

  // Wire add/edit user button to show the modal.
  qs('#add-user')?.addEventListener('click', () => openUserModal());
  // Cancel button simply hides the modal.
  qs('#user-cancel')?.addEventListener('click', closeUserModal);
  // Handles form submissions by updating the local state and syncing back to the server.
  qs('#user-form')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const username = qs('#user-name').value.trim();
    const fullname = qs('#user-fullname').value.trim();
    const phone = qs('#user-phone').value.trim();
    const email = qs('#user-email').value.trim();
    const codeInput = qs('#user-code');
    const codeValue = (codeInput?.value ?? "").trim();
    const workId = qs('#user-work-id').value.trim();
    const idNumber = qs('#user-id-number').value.trim();
    const activeInput = qs('#user-active');
    const isActive = activeInput ? activeInput.checked : true;
    if (!username) {
      showErrorSnackbar({ message: 'Username is required.' });
      return;
    }
    if (!fullname) {
      showErrorSnackbar({ message: 'Full name is required.' });
      return;
    }
    const normalizedUsername = username.toLowerCase();
    const usernameExists = USER_DB.some(u => {
      if (editingUserCode && u.code === editingUserCode) {
        return false;
      }
      const existingUsername = String(u.username ?? '').toLowerCase();
      return existingUsername !== '' && existingUsername === normalizedUsername;
    });
    if (usernameExists) {
      showErrorSnackbar({ message: 'Username already exists.' });
      return;
    }
    if (!/^\d{11}$/.test(phone)) {
      showErrorSnackbar({ message: 'Phone number must be 11 digits.' });
      return;
    }
    if (!isValidEmail(email)) {
      showErrorSnackbar({ message: 'Please provide a valid email address.' });
      return;
    }
    const normalizedPhone = normalizeDigits(phone);
    if (normalizedPhone.length !== 11) {
      showErrorSnackbar({ message: 'Phone number must contain 11 digits.' });
      return;
    }
    const duplicatePhone = USER_DB.some(u => {
      if (editingUserCode && u.code === editingUserCode) {
        return false;
      }
      const existingPhone = normalizeDigits(u.phone);
      return existingPhone !== '' && existingPhone === normalizedPhone;
    });
    if (duplicatePhone) {
      showErrorSnackbar({ message: 'Phone number must be unique.' });
      return;
    }
    const normalizedEmail = email.toLowerCase();
    if (normalizedEmail) {
      const duplicateEmail = USER_DB.some(u => {
        if (editingUserCode && u.code === editingUserCode) {
          return false;
        }
        const existingEmail = String(u.email ?? '').toLowerCase();
        return existingEmail !== '' && existingEmail === normalizedEmail;
      });
      if (duplicateEmail) {
        showErrorSnackbar({ message: 'Email must be unique.' });
        return;
      }
    }
    const normalizedId = normalizeDigits(idNumber);
    if (normalizedId) {
      const duplicateIdNumber = USER_DB.some(u => {
        if (editingUserCode && u.code === editingUserCode) {
          return false;
        }
        const existingId = normalizeDigits(u.id_number ?? '');
        return existingId !== '' && existingId === normalizedId;
      });
      if (duplicateIdNumber) {
        showErrorSnackbar({ message: 'National ID must be unique.' });
        return;
      }
    }
    const code = codeValue || getNextUserCode();
    const payload = {
      code,
      username,
      fullname,
      phone: normalizedPhone,
      work_id: workId,
      id_number: normalizedId,
      active: isActive
    };
    payload.email = email;
    if (editingUserCode) {
      const index = USER_DB.findIndex(u => u.code === editingUserCode);
      if (index >= 0) {
        const previousUser = { ...USER_DB[index] };
        USER_DB[index] = {
          ...USER_DB[index],
          username,
          name: fullname,
          fullname,
          phone: normalizedPhone,
          work_id: workId,
          id_number: normalizedId,
          email,
          active: isActive
        };
        renderUsers();
        updateKpis();
        closeUserModal();
        showDefaultToast('User updated successfully.');
        showActionSnackbar({
          message: 'User changes saved.',
          onAction: () => {
            USER_DB[index] = previousUser;
            renderUsers();
            updateKpis();
            syncUserToBackend('update_user', previousUser);
            showDefaultToast('Changes reverted.');
          }
        });
        syncUserToBackend('update_user', payload);
        return;
      }
      showErrorSnackbar({ message: 'User not found.' });
      return;
    }
    const newUser = {
      ...payload,
      name: fullname,
      username,
      fullname
    };
    USER_DB.push(newUser);
    renderUsers();
    updateKpis();
    closeUserModal();
    showDefaultToast('User added successfully.');
    syncUserToBackend('add_user', newUser);
  });
  // Delete modal buttons drive the confirm/cancel flow.
  qs('#user-delete-cancel')?.addEventListener('click', closeDeleteModal);
  qs('#user-delete-confirm')?.addEventListener('click', confirmUserDeletion);

  const personalInfoForm = qs('#personal-info-form');
  const personalFullnameInput = qs('#personal-fullname');
  let personalInfoLastFullname = (personalFullnameInput?.value ?? '').trim();
  personalInfoForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const nextFullname = (personalFullnameInput?.value ?? '').trim();
    if (!nextFullname) {
      const errMsg = 'Full name is required.';
      showDefaultToast(errMsg);
      return;
    }
    const previousFullname = personalInfoLastFullname;
    try {
      const result = await sendAccountAction('update_user_personal', { fullname: nextFullname });
      personalInfoLastFullname = nextFullname;
      const successMessage = result.message || 'Personal information saved.';
      showActionSnackbar({
        message: successMessage,
        onAction: async () => {
          try {
            await sendAccountAction('update_user_personal', { fullname: previousFullname });
            personalInfoLastFullname = previousFullname;
            if (personalFullnameInput) {
              personalFullnameInput.value = previousFullname;
            }
            const revertMessage = 'Changes reverted.';
            showDefaultToast(revertMessage);
          } catch (revertError) {
            const revertMsg = revertError?.message || 'Unable to revert changes.';
            showDefaultToast(revertMsg);
          }
        }
      });
    } catch (error) {
      const errMsg = error?.message || 'Failed to save personal information.';
      showDefaultToast(errMsg);
    }
  });

  const accountInfoForm = qs('#account-info-form');
  accountInfoForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const username = (qs('#account-username')?.value ?? '').trim();
    const phone = (qs('#account-phone')?.value ?? '').trim();
    const email = (qs('#account-email')?.value ?? '').trim();
    try {
      const result = await sendAccountAction('update_user_account', { username, phone, email });
      const successMessage = result.message || 'Account information updated.';
      showDefaultToast(successMessage);
    } catch (error) {
      const errMsg = error?.message || 'Failed to save account information.';
      showDefaultToast(errMsg);
    }
  });

  const privacyForm = qs('#privacy-form');
  privacyForm?.addEventListener('submit', async event => {
    event.preventDefault();
    const currentPassword = qs('#current-password')?.value ?? '';
    const newPassword = qs('#new-password')?.value ?? '';
    const confirmPassword = qs('#confirm-password')?.value ?? '';
    if (!newPassword || !confirmPassword) {
      showErrorSnackbar({
        message: 'لطفاً رمز عبور جدید و تکرار آن را وارد کنید.',
        duration: DEFAULT_TOAST_DURATION
      });
      return;
    }
    if (newPassword !== confirmPassword) {
      showErrorSnackbar({
        message: 'رمز عبور جدید با تکرار آن مطابقت ندارد.',
        duration: DEFAULT_TOAST_DURATION
      });
      return;
    }
    try {
      const result = await sendAccountAction('update_user_password', {
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword
      });
      privacyForm.reset();
      const successMessage = result.message || 'Password updated successfully.';
      showDefaultToast(successMessage);
    } catch (error) {
      const errMsg = error?.message || 'Failed to update password.';
      showErrorSnackbar({ message: errMsg, duration: DEFAULT_TOAST_DURATION });
    }
  });

  const panelInput = qs('#dev-panel-name');
  const panelSaveBtn = qs('#save-panel-settings');
  const storedPanel = localStorage.getItem(PANEL_TITLE_KEY);
  const serverPanel = SERVER_DATA_LOADED ? SERVER_SETTINGS.panelName : null;
  const initialPanel = serverPanel ?? storedPanel ?? PANEL_TITLE_DEFAULT;
  if (panelInput) {
    panelInput.value = initialPanel;
  }
  applyPanelTitle(initialPanel, true);
  panelSaveBtn?.addEventListener('click', () => {
    const previousPanelName =
      qs('.sidebar .title')?.textContent?.trim() ||
      PANEL_TITLE_DEFAULT;
    const value = (panelInput?.value ?? '').trim();
    const panelName = value || PANEL_TITLE_DEFAULT;
    applyPanelTitle(panelName, true);
    SERVER_SETTINGS.panelName = panelName;
    void syncSettings({ panelName }).then(success => {
      if (success) {
        showActionSnackbar({
          message: "Panel title updated.",
          instructionLabel: "Undo change",
          onAction: () => {
            applyPanelTitle(previousPanelName, true);
            SERVER_SETTINGS.panelName = previousPanelName;
            void syncSettings({ panelName: previousPanelName });
          }
        });
      } else {
        applyPanelTitle(previousPanelName, true);
        SERVER_SETTINGS.panelName = previousPanelName;
        showErrorSnackbar({ message: "Failed to save panel title." });
      }
    });
  });

  populateTimezoneSelect();
  initAppearanceControls();
  faviconLinkElement = qs('#site-icon-link');
  sidebarLogoImage = qs('[data-sidebar-site-icon]');
  sidebarLogoText = qs('[data-sidebar-logo-text]');
  siteIconPreviewImage = qs('[data-site-icon-image]');
  siteIconPlaceholder = qs('[data-site-icon-placeholder]');
  siteIconAddButton = qs('[data-open-photo-chooser]');
  siteIconClearButton = qs('[data-clear-site-icon]');
  applySiteIconValue(SERVER_SETTINGS.siteIcon ?? "");
  siteIconAddButton?.addEventListener('click', () => {
    const activePhoto = findGalleryPhotoMatchingSiteIcon();
    const initialSelection = activePhoto ? [activePhoto.id] : [];
    openPhotoChooserModal({
      allowMultiple: false,
      initialSelection,
      onChoose: (selectedPhotos = []) => {
        const photo = selectedPhotos[0];
        if (!photo) {
          return;
        }
        const filename = normalizeValue(photo.filename);
        persistSiteIconChange(filename, "Site icon saved.");
      }
    });
  });
  siteIconClearButton?.addEventListener('click', () => {
    persistSiteIconChange("", "Site icon removed.");
  });
  const generalSaveBtn = qs('#save-general-settings');
  const timezoneSelect = qs('#timezone-select');
  generalSaveBtn?.addEventListener('click', () => {
    const previousTimezone = SERVER_SETTINGS.timezone || DEFAULT_SETTINGS.timezone;
    const timezoneValue = timezoneSelect?.value || DEFAULT_SETTINGS.timezone;
    if (timezoneSelect) {
      localStorage.setItem(TIMEZONE_KEY, timezoneValue);
    }
    SERVER_SETTINGS.timezone = timezoneValue;
    renderClock();
    void syncSettings({ timezone: timezoneValue }).then(success => {
      if (success) {
        showActionSnackbar({
          message: "Timezone saved.",
          instructionLabel: "Undo change",
          onAction: () => {
            SERVER_SETTINGS.timezone = previousTimezone;
            if (timezoneSelect) {
              timezoneSelect.value = previousTimezone;
            }
            localStorage.setItem(TIMEZONE_KEY, previousTimezone);
            renderClock();
            void syncSettings({ timezone: previousTimezone });
          }
        });
      } else {
        SERVER_SETTINGS.timezone = previousTimezone;
        if (timezoneSelect) {
          timezoneSelect.value = previousTimezone;
        }
        localStorage.setItem(TIMEZONE_KEY, previousTimezone);
        renderClock();
        showErrorSnackbar({ message: "Failed to save timezone." });
      }
    });
  });
  instantBackupButton = qs("#instant-backup-btn");
  backupImportInput = qs("#dev-db-backup-file");
  backupImportTrigger = qs("#backup-import-trigger");
  backupFileChosen = qs("#backup-file-chosen");
  backupSettingsFormElement = qs("#backup-settings-form");
  backupIntervalInput = qs("#auto-backup-interval");
  backupLimitInput = qs("#auto-backup-limit");
  backupSaveButton = qs("#save-backup-settings");
  instantBackupButton?.addEventListener("click", () => {
    void handleInstantBackup();
  });
  backupImportTrigger?.addEventListener("click", () => {
    backupImportInput?.click();
  });
  backupImportInput?.addEventListener("change", async () => {
    const file = backupImportInput?.files?.[0] ?? null;
    if (!file) {
      return;
    }
    setBackupFileLabel(file.name);
    const confirmed = await showDialog(
      'This will replace the current panel data with the imported backup. Continue?',
      { confirm: true, title: 'Import backup', okText: 'Import', cancelText: 'Cancel' }
    );
    if (confirmed) {
      await handleBackupImport(file);
    } else {
      backupImportInput.value = "";
      setBackupFileLabel("No file selected.");
    }
  });
  backupSettingsFormElement?.addEventListener("submit", handleBackupSettingsSave);
  const backupHistoryContainer = qs("#backup-history");
  backupHistoryContainer?.addEventListener("click", handleBackupActionClick);
  applyBackupSettingsInputs();
  setBackupFileLabel("No file selected.");
  const developerSqlForm = qs("#developer-sql-form");
  const sqlTextarea = qs("#dev-db-sql");
  const clearSqlBtn = qs("#clear-sql-query");
  developerSqlForm?.addEventListener("submit", async event => {
    event.preventDefault();
    const query = (sqlTextarea?.value ?? "").trim();
    if (query === "") {
      showErrorSnackbar({ message: "Please enter a SQL statement." });
      return;
    }
    clearSqlResult();
    setSqlFormSubmitting(true);
    try {
      const response = await executeSqlQuery(query);
      renderSqlResult(response);
    } catch (error) {
      showErrorSnackbar({
        message: error?.message || "Failed to execute SQL query."
      });
    } finally {
      setSqlFormSubmitting(false);
    }
  });
  clearSqlBtn?.addEventListener("click", () => {
    if (sqlTextarea) {
      sqlTextarea.value = "";
    }
    clearSqlResult();
  });
  const galleryPhotoForms = qsa('[data-gallery-photo-form]');
  galleryPhotoModalElement = qs('#gallery-photo-modal');
  galleryPhotoModalFormElement = qs('[data-gallery-photo-modal-form]');
  galleryPhotoModalImageElement = galleryPhotoModalElement
    ? qs('[data-gallery-photo-preview]', galleryPhotoModalElement)
    : null;
  galleryPhotoModalLinkElement = galleryPhotoModalElement
    ? qs('[data-gallery-photo-link]', galleryPhotoModalElement)
    : null;
  galleryPhotoModalTitleInput = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-modal-title]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalAltInput = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-modal-alt]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalCategorySelect = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-category]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalCreatedElement = galleryPhotoModalElement
    ? qs('[data-gallery-photo-created]', galleryPhotoModalElement)
    : null;
  galleryPhotoModalReplaceInput = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-replace-input]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalReplaceButton = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-replace]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalDeleteButton = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-delete]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalSaveButton = galleryPhotoModalFormElement
    ? qs('[data-gallery-photo-save]', galleryPhotoModalFormElement)
    : null;
  galleryPhotoModalCloseButton = galleryPhotoModalElement
    ? qs('[data-gallery-photo-close]', galleryPhotoModalElement)
    : null;
  galleryUploadModalElement = qs('#gallery-upload-modal');
  galleryUploadModalFormElement = galleryUploadModalElement
    ? qs('[data-gallery-photo-form]', galleryUploadModalElement)
    : null;
  galleryUploadModalUploader = galleryUploadModalFormElement
    ? qs('[data-photo-uploader="gallery"]', galleryUploadModalFormElement)
    : null;
  photoChooserModalElement = qs('#photo-chooser-modal');
  photoChooserGridElement = qs('#photo-chooser-thumb-grid');
  photoChooserEmptyElement = qs('#photo-chooser-thumb-empty');
  photoChooserLoadMoreButton = qs('#photo-chooser-load-more');
  photoChooserChooseButton = qs('#photo-chooser-choose');
  photoChooserCancelButton = qs('[data-photo-chooser-cancel]');
  photoChooserUploadButton = qs('[data-photo-chooser-upload]');
  gallerySearchInputElement = qs('[data-gallery-search]');
  photoChooserSearchInputElement = qs('[data-photo-chooser-search]');
  const photoChooserCloseButtons = photoChooserModalElement
    ? qsa('[data-photo-chooser-close]', photoChooserModalElement)
    : [];
  const attachSearchInput = (inputEl, handler) => {
    const runSearch = (sourceEvent) => {
      handler(sourceEvent?.target?.value ?? inputEl?.value ?? "");
    };
    inputEl?.addEventListener("input", runSearch);
    inputEl?.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        runSearch(event);
      }
    });
  };
  attachSearchInput(gallerySearchInputElement, applyGallerySearchValue);
  attachSearchInput(photoChooserSearchInputElement, applyPhotoChooserSearchValue);
  photoChooserLoadMoreButton?.addEventListener('click', () => {
    photoChooserVisibleCount += GALLERY_GRID_LOAD_STEP;
    renderPhotoChooserGrid();
    requestAnimationFrame(() => {
      scrollGalleryGridByRows(photoChooserGridElement, 2);
    });
    photoChooserLoadMoreButton.blur();
  });
  photoChooserChooseButton?.addEventListener('click', () => {
    if (!photoChooserOnChoose) {
      closePhotoChooserModal();
      return;
    }
    photoChooserReopenContext = null;
    const selectedPhotos = [];
    photoChooserSelectedIds.forEach(id => {
      const photo = GALLERY_PHOTOS.find(p => p.id === id);
      if (photo) {
        selectedPhotos.push(photo);
      }
    });
    photoChooserOnChoose(selectedPhotos);
    closePhotoChooserModal();
  });
  photoChooserUploadButton?.addEventListener('click', () => {
    photoChooserReopenContext = {
      allowMultiple: photoChooserAllowMultiple,
      onChoose: photoChooserOnChoose
    };
    closePhotoChooserModal({ resetCallback: false });
    openGalleryUploadModal();
  });
  photoChooserCancelButton?.addEventListener('click', closePhotoChooserModal);
  photoChooserCloseButtons.forEach(btn => btn.addEventListener('click', closePhotoChooserModal));
  photoChooserModalElement?.addEventListener('click', (event) => {
    if (event.target === photoChooserModalElement) {
      closePhotoChooserModal();
    }
  });
  renderPhotoChooserGrid();
  const galleryCategoryForm = qs('#gallery-category-form');
  const galleryCategorySubmitButton = qs('#gallery-category-submit');
  const galleryCategoryCancelButton = qs('#gallery-category-cancel');
  galleryCategorySubmitDefaultText =
    galleryCategorySubmitButton?.textContent?.trim() ||
    galleryCategorySubmitDefaultText ||
    "Submit";
  galleryCategoryCancelButton?.addEventListener('click', (event) => {
    event.preventDefault();
    resetGalleryCategoryForm();
  });
  galleryPhotoForms.forEach(form => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const uploader = qs('[data-photo-uploader="gallery"]', form);
      const fileInput = qs('[data-photo-input]', uploader ?? form);
      if (!fileInput || !fileInput.files?.length) {
        showErrorSnackbar({ message: 'Please select a photo first.' });
        return;
      }
      const submitButton = qs('button[type="submit"]', form);
      submitButton?.setAttribute('disabled', 'disabled');
      const formData = new FormData(form);
      formData.set('action', 'add_gallery_photo');
      try {
        const response = await fetch(API_ENDPOINT, { method: 'POST', body: formData });
        let payload = {};
        try {
          payload = await response.json();
        } catch {
          payload = {};
        }
        if (!response.ok) {
          throw new Error(payload['message'] ?? 'Failed to upload photo.');
        }
        if (payload && payload.status === 'error') {
          throw new Error(payload.message || 'The server reported an error.');
        }
        const successMessage = payload.message || 'Photo uploaded successfully.';
        form.reset();
        if (uploader) {
          updatePhotoUploaderPreview(uploader);
        }
        showDefaultToast(successMessage);
        await reloadGalleryData();
        if (photoChooserReopenContext) {
          const latestPhoto = GALLERY_PHOTOS[0];
          const selection = latestPhoto ? [latestPhoto.id] : [];
          const { allowMultiple, onChoose } = photoChooserReopenContext;
          photoChooserReopenContext = null;
          closeGalleryUploadModal();
          openPhotoChooserModal({
            allowMultiple: Boolean(allowMultiple),
            initialSelection: selection,
            onChoose
          });
        }
      } catch (error) {
        showErrorSnackbar({
          message:
            error && typeof error.message === 'string'
              ? error.message
              : 'Failed to upload photo.'
        });
      } finally {
        submitButton?.removeAttribute('disabled');
      }
    });
  });
  galleryCategoryForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = qs('#gallery-category-name');
    const motherSelect = qs('#gallery-category-mother');
    const name = (input?.value ?? '').trim();
    const motherValue = (motherSelect?.value ?? '').trim();
    if (!name) {
      showErrorSnackbar({ message: 'Category name is required.' });
      return;
    }
    const payload = {
      action: 'add_gallery_category',
      name,
      mother_category_id: motherValue
    };
    galleryCategorySubmitButton?.setAttribute('disabled', 'disabled');
    try {
      const result = await sendGalleryRequest(payload);
      const successMessage =
        result.message || 'Category saved successfully.';
      showDefaultToast(successMessage);
      resetGalleryCategoryForm();
      await reloadGalleryData();
    } catch (error) {
      showErrorSnackbar({
        message: error?.message || 'Failed to save category.'
      });
    } finally {
      galleryCategorySubmitButton?.removeAttribute('disabled');
    }
  });
  resetGalleryCategoryForm();
  initSubSidebars();

  const galleryUploadModalOpenButton = qs('#open-gallery-upload-modal');
  galleryUploadModalOpenButton?.addEventListener('click', (event) => {
    event.preventDefault();
    openGalleryUploadModal();
  });
  const galleryUploadModalCloseButtons = galleryUploadModalElement
    ? qsa('[data-gallery-upload-modal-close]', galleryUploadModalElement)
    : [];
  galleryUploadModalCloseButtons.forEach(button => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      closeGalleryUploadModal();
    });
  });
  galleryUploadModalElement?.addEventListener('click', (event) => {
    if (event.target === galleryUploadModalElement) {
      closeGalleryUploadModal();
    }
  });

  galleryPhotoModalCloseButton?.addEventListener('click', closeGalleryPhotoModal);
  galleryPhotoModalElement?.addEventListener('click', (event) => {
    if (event.target === galleryPhotoModalElement) {
      closeGalleryPhotoModal();
    }
  });
  initGalleryPhotoModalKeyboardShortcuts();
  galleryPhotoModalFormElement?.addEventListener('submit', handleGalleryPhotoModalSave);
  galleryPhotoModalReplaceButton?.addEventListener('click', () => {
    galleryPhotoModalReplaceInput?.click();
  });
  galleryPhotoModalReplaceInput?.addEventListener('change', async (event) => {
    const file = event.currentTarget?.files?.[0] ?? null;
    event.currentTarget.value = '';
    if (file) {
      await handleGalleryPhotoReplace(file);
    }
  });
  galleryPhotoModalDeleteButton?.addEventListener('click', handleGalleryPhotoDelete);

  const testToastTrigger = qs('[data-test-toast]');
  testToastTrigger?.addEventListener('click', () => {
    showDefaultToast('این اعلان تست سبک پیش‌فرض است.', {
      duration: DEFAULT_TOAST_DURATION
    });
  });

  const testSnackbarTrigger = qs('[data-test-snackbar]');
  testSnackbarTrigger?.addEventListener('click', () => {
    showActionSnackbar({
      message: 'اقدام انجام شد. اگر نیاز دارید، آن را بازگردانید.',
      actionLabel: 'Undo (Ctrl+Z)',
      duration: DEFAULT_TOAST_DURATION,
      onAction: () => {
        showDefaultToast('بازگشت انجام شد.', { duration: 2500 });
      }
    });
  });

  const testErrorSnackbarTrigger = qs('[data-test-error-snackbar]');
  testErrorSnackbarTrigger?.addEventListener('click', () => {
    showErrorSnackbar({
      message: 'عملیات با خطا مواجه شد. لطفاً دوباره تلاش کنید.',
      duration: DEFAULT_TOAST_DURATION
    });
  });

  window.addEventListener('storage', updateKpis);
});
