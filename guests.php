<section id="tab-guests" class="tab">
  <link rel="stylesheet" href="style/jalalidatepicker.min.css" />
  <div class="sub-layout" data-sub-layout>
    <aside class="sub-sidebar">
      <div class="sub-header">Guest list</div>
      <div class="sub-nav">
        <button type="button" class="sub-item active" data-pane="guest-upload-pane">
          Guest upload
        </button>
        <div class="sub-event-tabs" id="guest-event-tabs"></div>
      </div>
    </aside>
    <div class="sub-content">
      <div class="sub-pane active" data-pane="guest-upload-pane">
        <div class="card" data-event-section="event-info" id="event-info-section">
          <div id="guest-upload-card-progress" class="card-progress hidden" role="status" aria-live="polite">
            <div class="loader-ring" aria-hidden="true">
              <span></span>
              <span></span>
            </div>
            <p class="card-progress__message" data-card-progress-message>در حال آماده‌سازی فایل...</p>
          </div>
          <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
              <h3>List of guests</h3>
            </div>
            <button type="button" class="btn" id="open-manual-modal">Add guest manually</button>
          </div>
          <form id="guest-upload-form" class="form" enctype="multipart/form-data">
            <div class="form" style="max-width: 420px; gap: 12px;">
              <label class="field standard-width">
                <span>Name of event</span>
                <input id="guest-event-name" name="event_name" type="text" autocomplete="off" required />
              </label>
              <label class="field standard-width">
                <span>Event date (Shamsi)</span>
                <input
                  id="guest-event-date"
                  name="event_date"
                  type="text"
                  data-jdp
                  data-jdp-only-date="true"
                  placeholder="Example: 1403/10/01"
                  autocomplete="off"
                  readonly
                  required
                />
              </label>
              <label class="field standard-width">
                <span>Excel / CSV file</span>
                <div class="file-row" style="display:flex; align-items:center; gap:8px;">
                  <button type="button" class="btn" id="guest-file-trigger">Choose file</button>
                  <span id="guest-file-name" class="muted" aria-live="polite">No file chosen</span>
                  <input
                    id="guest-file"
                    name="guest_file"
                    type="file"
                    accept=".csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    required
                    style="display:none;"
                  />
                </div>
              </label>
            </div>
            <div class="section-footer">
              <button type="submit" class="btn primary" id="guest-upload-submit">Upload file</button>
            </div>
          </form>
        </div>
        <div class="card hidden" data-event-section="event-guests" id="event-guests-section">
          <div class="table-header">
            <h3>Saved events</h3>
          </div>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>Event name</th>
                  <th>Event date</th>
                  <th>Unique code</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="guest-event-list-body">
                <tr>
                  <td colspan="4" class="muted">No events yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>
      <div class="sub-pane" data-pane="guest-event-pane">
        <div
          class="default-top-tab-list"
          role="tablist"
          aria-label="Event sections"
          data-event-section-tabs
          data-style="default top tab list"
        >
          <button
            type="button"
            class="default-top-tab-list__tab active"
            data-event-section-target="event-info"
            aria-controls="event-info-section"
            aria-selected="true"
          >
            Event info
          </button>
          <button
            type="button"
            class="default-top-tab-list__tab"
            data-event-section-target="event-guests"
            aria-controls="event-guests-section"
            aria-selected="false"
          >
            Event guests
          </button>
          <button
            type="button"
            class="default-top-tab-list__tab"
            data-event-section-target="event-winners"
            aria-controls="event-winners-section"
            aria-selected="false"
          >
            Event winners
          </button>
          <button
            type="button"
            class="default-top-tab-list__tab"
            data-event-section-target="event-prizes"
            aria-controls="event-prizes-section"
            aria-selected="false"
          >
            Event prizes
          </button>
        </div>
        <div class="event-section" data-event-section="event-info" id="event-info-section">
          <div class="card">
            <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
              <div>
                <h3>Event info</h3>
              </div>
            </div>
            <form id="event-info-form" class="form">
              <div class="form" style="max-width: 420px; gap: 12px;">
                <label class="field standard-width">
                  <span>Event code</span>
                  <input id="event-info-slug" type="text" readonly />
                </label>
                <label class="field standard-width">
                  <span>Event name</span>
                  <input id="event-info-name" name="event_name" type="text" autocomplete="off" required />
                </label>
                <label class="field standard-width">
                  <span>Event date (Shamsi)</span>
                  <input
                    id="event-info-date"
                    name="event_date"
                    type="text"
                    data-jdp
                    data-jdp-only-date="true"
                    placeholder="Example: 1403/10/01"
                    autocomplete="off"
                    readonly
                    required
                  />
                </label>
              </div>
              <div class="section-footer" style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn primary" id="event-info-save">Save event</button>
                <button type="button" class="btn ghost" id="event-info-delete" disabled>Delete event</button>
              </div>
              <p id="event-info-empty" class="hidden" style="margin-top: 8px;">No events available yet.</p>
            </form>
          </div>
        </div>
        <div class="event-section hidden" data-event-section="event-guests" id="event-guests-section">
          <div class="card">
            <div class="table-header">
              <h3>Guest lists</h3>
              <div
                class="table-actions"
                style="align-items:flex-start; display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px;"
              >
                <div style="display:flex; align-items:center; gap:8px;">
                  <button type="button" class="btn primary" id="export-sms-link">Export SMS Link</button>
                  <button type="button" class="btn" id="export-present-guest-list">Export Present Guests List</button>
                </div>
                <button type="button" class="btn primary" id="open-manual-modal-event">Add guest manually</button>
              </div>
            </div>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>No.</th>
                    <th>Event</th>
                    <th>Event date</th>
                    <th>First name</th>
                    <th>Last name</th>
                    <th>Gender</th>
                    <th>National ID</th>
                    <th>Phone number</th>
                    <th>Join date</th>
                    <th>Join time</th>
                    <th>Left date</th>
                    <th>Left time</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="guest-list-body">
                  <tr>
                    <td colspan="13" class="muted">No guest lists yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="event-section hidden" data-event-section="event-winners" id="event-winners-section">
          <div class="card">
            <div class="table-header">
          <div>
            <h3>Event winners</h3>
          </div>
            </div>
            <p id="event-winners-status" class="muted small" aria-live="polite"></p>
            <div class="table-wrapper">
              <table>
                <thead>
                <tr>
                  <th style="width:56px;">No.</th>
                  <th>Full name</th>
                  <th>Invite code</th>
                  <th>Phone number</th>
                  <th>National ID</th>
                  <th>Timestamp</th>
                </tr>
                </thead>
                <tbody id="event-winner-list-body">
                  <tr>
                    <td colspan="6" class="muted">Loading winners...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="event-section hidden" data-event-section="event-prizes" id="event-prizes-section">
          <div class="card">
            <div class="table-header" style="flex-wrap:wrap;">
          <div>
            <h3>Event prizes</h3>
          </div>
              <form
                id="event-prize-add-form"
                class="form"
                style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; direction:rtl; min-width:320px;"
              >
                  <label class="field standard-width" style="flex:1 1 220px; direction:rtl; text-align:right;">
                    <span>Prize name</span>
                  <input
                    id="event-prize-name"
                    name="name"
                    type="text"
                    placeholder="Prize name or description"
                    autocomplete="off"
                    required
                    style="direction:rtl; text-align:right;"
                  />
                </label>
                <button type="submit" class="btn primary" id="event-prize-add-button">Add prize</button>
              </form>
            </div>
            <p id="event-prize-status" class="muted small" aria-live="polite" style="margin:0;"></p>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th style="width:80px;">#</th>
                    <th>Prize name</th>
                    <th style="width:190px;">Actions</th>
                  </tr>
                </thead>
                <tbody id="event-prize-list-body">
                  <tr>
                    <td colspan="3" class="muted">No prizes yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

  <div id="guest-mapping-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="guest-mapping-title">
    <div class="modal-card">
      <div class="modal-header">
        <h3 id="guest-mapping-title">Map columns to guest fields</h3>
        <button type="button" class="icon-btn" data-guest-mapping-close aria-label="Close mapping modal">X</button>
      </div>
      <div id="guest-mapping-progress" class="modal-progress hidden" role="status" aria-live="polite">
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="modal-progress__message" data-guest-mapping-progress-message>در حال آماده‌سازی...</p>
      </div>
      <div class="modal-body">
        <form id="guest-mapping-form" class="form">
          <div class="grid">
            <label class="field">
              <span>First name</span>
              <select data-guest-column="firstname" required></select>
            </label>
            <label class="field">
              <span>Last name</span>
              <select data-guest-column="lastname" required></select>
            </label>
            <label class="field">
              <span>Gender</span>
              <select data-guest-column="gender" required></select>
            </label>
            <label class="field">
              <span>National ID</span>
              <select data-guest-column="national_id" required></select>
            </label>
            <label class="field">
              <span>Phone number</span>
              <select data-guest-column="phone_number" required></select>
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn ghost" data-guest-mapping-close>Cancel</button>
            <button type="submit" class="btn primary" id="guest-mapping-submit">Save pure list</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="guest-edit-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="guest-edit-title">
    <div class="modal-card" style="max-width:520px;">
      <div class="modal-header">
        <h3 id="guest-edit-title">Edit guest</h3>
      </div>
      <div class="modal-body">
        <form id="guest-edit-form" class="form">
          <div class="grid">
            <label class="field">
              <span>First name</span>
              <input id="edit-firstname" name="firstname" type="text" required />
            </label>
            <label class="field">
              <span>Last name</span>
              <input id="edit-lastname" name="lastname" type="text" required />
            </label>
            <label class="field">
              <span>Gender</span>
              <select id="edit-gender" name="gender" required></select>
            </label>
            <label class="field">
              <span>National ID</span>
              <input id="edit-national-id" name="national_id" type="text" required />
            </label>
            <label class="field">
              <span>Phone number</span>
              <input id="edit-phone" name="phone_number" type="text" required />
            </label>
          </div>

          <div class="form" style="gap:12px; margin-top:8px;">
            <label class="field">
              <span>Date and time entered</span>
              <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input
                  id="edit-date-entered"
                  name="date_entered"
                  type="text"
                  data-jdp
                  data-jdp-only-date="true"
                  placeholder="Example: 1403/10/01"
                  autocomplete="off"
                  style="flex:1 1 180px;"
                />
                <select id="edit-time-entered" name="time_entered" class="field" style="flex:0 0 120px;"></select>
                <button type="button" class="btn ghost" id="edit-now-btn" style="flex:0 0 auto;">Current time and date</button>
                <button type="button" class="btn ghost" id="edit-clear-entered-btn" style="flex:0 0 auto;">Remove</button>
              </div>
            </label>
            <label class="field">
              <span>Date and time exited</span>
              <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input
                  id="edit-date-exited"
                  name="date_exited"
                  type="text"
                  data-jdp
                  data-jdp-only-date="true"
                  placeholder="Example: 1403/10/01"
                  autocomplete="off"
                  style="flex:1 1 180px;"
                />
                <select id="edit-time-exited" name="time_exited" class="field" style="flex:0 0 120px;"></select>
                <button type="button" class="btn ghost" id="edit-clear-exit-btn" style="flex:0 0 auto;">Remove</button>
              </div>
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn ghost" data-guest-edit-close>Cancel</button>
            <button type="submit" class="btn primary" id="guest-edit-submit">Save changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div id="guest-manual-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="guest-manual-title">
    <div class="modal-card" style="max-width:520px;">
      <div class="modal-header">
        <h3 id="guest-manual-title">Add guest manually</h3>
        <button type="button" class="icon-btn" data-guest-manual-close aria-label="Close manual modal">X</button>
      </div>
      <div class="modal-body">
        <form id="guest-manual-form" class="form">
          <div class="grid">
            <label class="field">
              <span>Event</span>
              <select id="manual-event-select" name="event_code" required>
                <option value="">Select an event</option>
              </select>
            </label>
            <label class="field">
              <span>Event date (Shamsi)</span>
              <input
                id="manual-event-date"
                name="event_date"
                type="text"
                data-jdp
                data-jdp-only-date="true"
                placeholder="Example: 1403/10/01"
                autocomplete="off"
                readonly
                required
              />
            </label>
            <label class="field">
              <span>First name</span>
              <input id="manual-firstname" name="firstname" type="text" required />
            </label>
            <label class="field">
              <span>Last name</span>
              <input id="manual-lastname" name="lastname" type="text" required />
            </label>
            <label class="field">
              <span>Gender</span>
              <select id="manual-gender" name="gender" required></select>
            </label>
            <label class="field">
              <span>National ID</span>
              <input id="manual-national-id" name="national_id" type="text" required />
            </label>
            <label class="field">
              <span>Phone number</span>
              <input id="manual-phone" name="phone_number" type="text" required />
            </label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn ghost" data-guest-manual-close>Cancel</button>
            <button type="submit" class="btn primary" id="guest-manual-submit">Add guest</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
<script src="style/jalalidatepicker.min.js" defer></script>
<script>
  (function() {
    const $qs = (sel, root = document) => root.querySelector(sel);
    const $qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const state = {
      columns: [],
      rows: [],
      file: null,
      eventName: "",
      eventDate: "",
      events: []
    };
    let activeEventCode = "";
    let manualLockedEventCode = "";

    const uploadForm = document.getElementById("guest-upload-form");
    const uploadSubmit = document.getElementById("guest-upload-submit");
    const eventNameInput = document.getElementById("guest-event-name");
    const eventDateInput = document.getElementById("guest-event-date");
    const fileInput = document.getElementById("guest-file");
    const fileTrigger = document.getElementById("guest-file-trigger");
    const fileNameLabel = document.getElementById("guest-file-name");
    const guestListBody = document.getElementById("guest-list-body");
    const mappingModal = document.getElementById("guest-mapping-modal");
    const mappingForm = document.getElementById("guest-mapping-form");
    const mappingSubmit = document.getElementById("guest-mapping-submit");
    const mappingSelects = mappingForm ? mappingForm.querySelectorAll("[data-guest-column]") : [];
    const mappingCloseButtons = $qsa("[data-guest-mapping-close]", mappingModal || document);
    const mappingProgressOverlay = document.getElementById("guest-mapping-progress");
    const mappingProgressMessage = mappingProgressOverlay?.querySelector("[data-guest-mapping-progress-message]");
    const defaultMappingProgressText = "در حال ساخت لیست مهمانان...";
    const guestUploadCardProgress = document.getElementById("guest-upload-card-progress");
    const cardProgressMessage = guestUploadCardProgress?.querySelector("[data-card-progress-message]");
    const defaultCardProgressText = "در حال آماده‌سازی فایل...";
    let jalaliPickerInitialized = false;

    const editModal = document.getElementById("guest-edit-modal");
    const editForm = document.getElementById("guest-edit-form");
    const editCloseButtons = $qsa("[data-guest-edit-close]", editModal || document);
    const editFirstnameInput = document.getElementById("edit-firstname");
    const editLastnameInput = document.getElementById("edit-lastname");
    const editGenderSelect = document.getElementById("edit-gender");
    const editNationalIdInput = document.getElementById("edit-national-id");
    const editPhoneInput = document.getElementById("edit-phone");
    const editDateEnteredInput = document.getElementById("edit-date-entered");
    const editDateExitedInput = document.getElementById("edit-date-exited");
    const editTimeEnteredInput = document.getElementById("edit-time-entered");
    const editTimeExitedInput = document.getElementById("edit-time-exited");
    const editNowButton = document.getElementById("edit-now-btn");
    const editClearExitButton = document.getElementById("edit-clear-exit-btn");
    let editContext = null;
    const manualModal = document.getElementById("guest-manual-modal");
    const manualCloseButtons = $qsa("[data-guest-manual-close]", manualModal || document);
    const manualOpenButton = document.getElementById("open-manual-modal");
    const manualForm = document.getElementById("guest-manual-form");
    const manualEventSelect = document.getElementById("manual-event-select");
    const manualEventDateInput = document.getElementById("manual-event-date");
    const manualFirstnameInput = document.getElementById("manual-firstname");
    const manualLastnameInput = document.getElementById("manual-lastname");
    const manualGenderSelect = document.getElementById("manual-gender");
    const manualNationalIdInput = document.getElementById("manual-national-id");
    const manualPhoneInput = document.getElementById("manual-phone");
    const manualEventPaneAddButton = document.getElementById("open-manual-modal-event");
    const exportSmsButton = document.getElementById("export-sms-link");
    const exportPresentGuestButton = document.getElementById("export-present-guest-list");
    const eventListBody = document.getElementById("guest-event-list-body");
    const eventTabsContainer = document.getElementById("guest-event-tabs");
    const eventInfoForm = document.getElementById("event-info-form");
    const eventInfoNameInput = document.getElementById("event-info-name");
    const eventInfoDateInput = document.getElementById("event-info-date");
    const eventInfoCodeInput = document.getElementById("event-info-slug");
    const eventInfoSaveButton = document.getElementById("event-info-save");
    const eventInfoDeleteButton = document.getElementById("event-info-delete");
    const eventInfoEmptyMessage = document.getElementById("event-info-empty");
    const guestEventPane = document.querySelector(".sub-pane[data-pane=\"guest-event-pane\"]");
    const eventSectionTabs = guestEventPane?.querySelector("[data-event-section-tabs]");
    const eventSections = Array.from(guestEventPane?.querySelectorAll("[data-event-section]") || []);
    const eventWinnerListBody = document.getElementById("event-winner-list-body");
    const eventWinnersStatus = document.getElementById("event-winners-status");
    const eventPrizeForm = document.getElementById("event-prize-add-form");
    const eventPrizeInput = document.getElementById("event-prize-name");
    const eventPrizeAddButton = document.getElementById("event-prize-add-button");
    const eventPrizeStatus = document.getElementById("event-prize-status");
    const eventPrizeListBody = document.getElementById("event-prize-list-body");
    const PURE_LIST_CSV_PATH = "./events/event/purelist.csv";
    const editClearEnteredButton = document.getElementById("edit-clear-entered-btn");
    const subPaneButtons = document.querySelectorAll(".sub-sidebar .sub-nav [data-pane]");
    const subPanes = document.querySelectorAll(".sub-content .sub-pane");
    let currentSubPane = "guest-upload-pane";
    let cachedWinners = [];
    let winnersLoaded = false;
    let eventPrizes = [];
    let currentEventPrizeCode = "";
    let eventPrizeFetchId = 0;

    function showModal(modal) {
      if (!modal) return;
      modal.classList.remove("hidden");
      modal.focus?.();
    }

    function hideModal(modal) {
      if (!modal) return;
      modal.classList.add("hidden");
    }

    function closeManualModal() {
      manualLockedEventCode = "";
      manualEventSelect?.removeAttribute("disabled");
      hideModal(manualModal);
    }

    mappingCloseButtons.forEach(btn => {
      btn.addEventListener("click", evt => {
        evt.preventDefault();
        hideModal(mappingModal);
        hideMappingProgress();
      });
    });

    mappingModal?.addEventListener("click", evt => {
      if (evt.target === mappingModal) {
        hideModal(mappingModal);
        hideMappingProgress();
      }
    });

    manualCloseButtons.forEach(btn => {
      btn.addEventListener("click", evt => {
        evt.preventDefault();
        closeManualModal();
      });
    });

    manualModal?.addEventListener("click", evt => {
      if (evt.target === manualModal) closeManualModal();
    });

    editCloseButtons.forEach(btn => {
      btn.addEventListener("click", evt => {
        evt.preventDefault();
        hideModal(editModal);
      });
    });

    editModal?.addEventListener("click", evt => {
      if (evt.target === editModal) hideModal(editModal);
    });

    function normalizeHeader(value, idx) {
      const label = String(value ?? "").trim();
      return label !== "" ? label : "Column " + (idx + 1);
    }

    function bestGuessSelection(columns, targets) {
      return targets.map(target => {
        const match = columns.find(col => col.toLowerCase().includes(target));
        return match || "";
      });
    }

    function populateMappingSelects(columns) {
      const guesses = bestGuessSelection(columns, ["first", "last", "gender", "national", "phone"]);
      mappingSelects.forEach((select, index) => {
        select.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Select a column";
        select.appendChild(placeholder);
        columns.forEach(col => {
          const option = document.createElement("option");
          option.value = col;
          option.textContent = col;
          select.appendChild(option);
        });
        const guess = guesses[index] || "";
        if (guess) {
          select.value = guess;
        }
      });
    }

    async function parseGuestFile(file) {
      if (!file) throw new Error("Please choose a file first.");
      if (typeof XLSX === "undefined") throw new Error("Excel parser not loaded yet. Please try again in a moment.");
      const buffer = await file.arrayBuffer();
      const workbook = XLSX.read(buffer, { type: "array" });
      const firstSheet = workbook.SheetNames[0];
      if (!firstSheet) throw new Error("No sheets found in the uploaded file.");
      const sheet = workbook.Sheets[firstSheet];
      const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "" });
      if (!rows.length) throw new Error("The uploaded file is empty.");
      const headers = (rows.shift() || []).map(normalizeHeader);
      if (!headers.length) throw new Error("No column headers were found in the first row.");
      const dataRows = rows.map((row) => {
        const obj = {};
        headers.forEach((header, idx) => {
          obj[header] = row[idx] ?? "";
        });
        return obj;
      });
      return { columns: headers, rows: dataRows };
    }

    function refreshEventControls() {
      renderManualEventOptions();
      renderEventTabs();
      renderGuestTable();
    }

    function renderManualEventOptions(forceEventCode = "") {
      if (!manualEventSelect) return;
      const previous = manualEventSelect.value;
      manualEventSelect.innerHTML = '<option value="">Select an event</option>';
      state.events.forEach(event => {
        const option = document.createElement("option");
        const code = event.code || "";
        option.value = code;
        option.textContent = event.name || code || "Unnamed event";
        manualEventSelect.appendChild(option);
      });
      const requestedCode = forceEventCode || manualLockedEventCode;
      const hasRequested = Boolean(
        requestedCode && state.events.some(ev => (ev.code || "") === requestedCode)
      );
      if (hasRequested) {
        manualEventSelect.value = requestedCode;
      } else if (previous && state.events.some(ev => (ev.code || "") === previous)) {
        manualEventSelect.value = previous;
      } else {
        manualEventSelect.value = "";
      }
      if (manualLockedEventCode && manualEventSelect.value !== manualLockedEventCode) {
        manualLockedEventCode = "";
      }
      updateManualEventDate();
    }

    function openManualModal({ forceEventCode = "", lockEventSelection = false } = {}) {
      const shouldLock =
        lockEventSelection &&
        Boolean(forceEventCode) &&
        state.events.some(ev => (ev.code || "") === forceEventCode);
      manualLockedEventCode = shouldLock ? forceEventCode : "";
      renderManualEventOptions(forceEventCode);
      if (manualEventSelect) {
        if (shouldLock) {
          manualEventSelect.setAttribute("disabled", "disabled");
        } else {
          manualEventSelect.removeAttribute("disabled");
        }
      }
      showModal(manualModal);
    }

    function getActiveGuestEvent() {
      const events = Array.isArray(state.events) ? state.events : [];
      if (!events.length) return null;
      if (!activeEventCode) {
        activeEventCode = events[0]?.code || "";
      }
      if (!activeEventCode) return null;
      return events.find(ev => (ev.code || "") === activeEventCode) || null;
    }

    function renderEventTabs() {
      if (!eventTabsContainer) return;
      eventTabsContainer.innerHTML = "";
      const events = Array.isArray(state.events) ? state.events : [];
      if (!events.length) {
        activeEventCode = "";
        const emptyState = document.createElement("div");
        emptyState.className = "muted small";
        emptyState.textContent = "No events yet.";
        eventTabsContainer.appendChild(emptyState);
        updateEventInfoForm();
        return;
      }
      const hasActive = events.some(ev => (ev.code || "") === activeEventCode);
      if (!hasActive) {
        activeEventCode = events[0]?.code || "";
      }
      events.forEach(event => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "sub-item event-tab";
        const code = event.code || "";
        button.dataset.code = code;
        button.textContent = event.name || code || "Unnamed event";
        button.addEventListener("click", () => handleEventTabSelect(button.dataset.code || ""));
        eventTabsContainer.appendChild(button);
      });
      updateEventInfoForm();
      refreshEventTabActiveState();
    }

    function getEventTabs() {
      if (!eventTabsContainer) return [];
      return Array.from(eventTabsContainer.querySelectorAll(".event-tab"));
    }

    function refreshEventTabActiveState() {
      const tabs = getEventTabs();
      tabs.forEach(tab => {
        const shouldBeActive = currentSubPane === "guest-event-pane" && tab.dataset.code === activeEventCode;
        tab.classList.toggle("active", shouldBeActive);
      });
    }

    function setActivePane(targetPane) {
      currentSubPane = targetPane;
      subPanes?.forEach(pane => {
        pane.classList.toggle("active", pane.dataset.pane === targetPane);
      });
      subPaneButtons?.forEach(button => {
        button.classList.toggle("active", button.dataset.pane === targetPane);
      });
      refreshEventTabActiveState();
    }

    function handleEventTabSelect(code) {
      if (!code) return;
      activeEventCode = code;
      updateEventInfoForm();
      setActivePane("guest-event-pane");
      renderGuestTable();
      renderEventWinners();
      loadEventPrizesForCode(activeEventCode);
    }

    function updateEventInfoForm() {
      const selectedEvent = state.events.find(ev => (ev.code || "") === activeEventCode) || null;
      const hasEvent = Boolean(selectedEvent && selectedEvent.code);
      if (eventInfoCodeInput) {
        eventInfoCodeInput.value = selectedEvent?.code || "";
      }
      if (eventInfoNameInput) {
        eventInfoNameInput.value = selectedEvent?.name || "";
        if (hasEvent) {
          eventInfoNameInput.removeAttribute("disabled");
        } else {
          eventInfoNameInput.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoDateInput) {
        eventInfoDateInput.value = selectedEvent?.date || "";
        if (hasEvent) {
          eventInfoDateInput.removeAttribute("disabled");
        } else {
          eventInfoDateInput.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoSaveButton) {
        if (hasEvent) {
          eventInfoSaveButton.removeAttribute("disabled");
        } else {
          eventInfoSaveButton.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoDeleteButton) {
        if (hasEvent) {
          eventInfoDeleteButton.removeAttribute("disabled");
        } else {
          eventInfoDeleteButton.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoEmptyMessage) {
        eventInfoEmptyMessage.classList.toggle("hidden", hasEvent);
      }
    }

    function updateManualEventDate() {
      if (!manualEventDateInput) return;
      const selectedCode = manualEventSelect?.value || "";
      const selectedEvent = state.events.find(ev => (ev.code || "") === selectedCode);
      manualEventDateInput.value = (selectedEvent?.date || "").trim();
    }

    function renderGuestTable() {
      if (!guestListBody) return;
      guestListBody.innerHTML = "";
      const events = Array.isArray(state.events) ? state.events : [];
      if (!events.length) {
        const emptyRow = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = 13;
        td.className = "muted";
        td.textContent = "No events yet.";
        emptyRow.appendChild(td);
        guestListBody.appendChild(emptyRow);
        renderEventList();
        return;
      }
      const activeEvent = getActiveGuestEvent();
      if (!activeEvent) {
        const emptyRow = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = 13;
        td.className = "muted";
        td.textContent = "Select an event to view its guests.";
        emptyRow.appendChild(td);
        guestListBody.appendChild(emptyRow);
        renderEventList();
        return;
      }
      const guests = Array.isArray(activeEvent.guests) ? activeEvent.guests : [];
      if (!guests.length) {
        const emptyRow = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = 13;
        td.className = "muted";
        td.textContent = "No guests yet for this event.";
        emptyRow.appendChild(td);
        guestListBody.appendChild(emptyRow);
        renderEventList();
        return;
      }
      const rows = guests.map((guest, index) => {
        const entrySource = guest.join_date
          ? `${guest.join_date} ${guest.join_time || ""}`.trim()
          : guest.date_entered || "";
        const exitSource = guest.left_date
          ? `${guest.left_date} ${guest.left_time || ""}`.trim()
          : guest.date_exited || "";
        const enteredParts = splitDateTime(entrySource);
        const exitedParts = splitDateTime(exitSource);
        return {
          number: guest.number || index + 1,
          event: activeEvent.name || "",
          date: activeEvent.date || "",
          firstname: guest.firstname || "",
          lastname: guest.lastname || "",
          gender: guest.gender || "",
          national_id: guest.national_id || "",
          phone_number: guest.phone_number || "",
          join_date: enteredParts.date,
          join_time: enteredParts.time,
          left_date: exitedParts.date,
          left_time: exitedParts.time,
          code: activeEvent.code || ""
        };
      });
      rows.forEach(row => {
        const tr = document.createElement("tr");
        ["number", "event", "date", "firstname", "lastname", "gender", "national_id", "phone_number", "join_date", "join_time", "left_date", "left_time"].forEach(key => {
          const td = document.createElement("td");
          td.textContent = row[key] ?? "";
          tr.appendChild(td);
        });
        const actionTd = document.createElement("td");
        actionTd.innerHTML = `
          <button type="button" class="btn small" data-guest-edit data-code="${row.code || ""}" data-number="${row.number}">Edit</button>
          <button type="button" class="btn ghost small" data-guest-delete data-code="${row.code || ""}" data-number="${row.number}">Delete</button>
        `;
        tr.appendChild(actionTd);
        guestListBody.appendChild(tr);
      });
      renderEventList();
    }

    function renderEventList() {
      if (!eventListBody) return;
      eventListBody.innerHTML = "";
      const events = Array.isArray(state.events) ? state.events : [];
      if (!events.length) {
        const emptyRow = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = 4;
        td.className = "muted";
        td.textContent = "No events yet.";
        emptyRow.appendChild(td);
        eventListBody.appendChild(emptyRow);
        return;
      }
      events.forEach(event => {
        const row = document.createElement("tr");
        const nameTd = document.createElement("td");
        nameTd.textContent = event.name || "";
        const dateTd = document.createElement("td");
        dateTd.textContent = event.date || "";
        const codeTd = document.createElement("td");
        const eventCode = event.code || event.slug || "";
        codeTd.textContent = eventCode;
        [nameTd, dateTd, codeTd].forEach(cell => row.appendChild(cell));
        const actionTd = document.createElement("td");
        const deleteButton = document.createElement("button");
        deleteButton.type = "button";
        deleteButton.className = "btn ghost small";
        deleteButton.setAttribute("data-event-delete", "");
        deleteButton.setAttribute("data-event-code", eventCode);
        deleteButton.textContent = "Delete";
        deleteButton.disabled = !eventCode;
        actionTd.appendChild(deleteButton);
        row.appendChild(actionTd);
        eventListBody.appendChild(row);
      });
    }

    function serializeGuests(rows, mapping) {
      return rows
        .map(row => ({
          firstname: String(row[mapping.firstname] ?? "").trim(),
          lastname: String(row[mapping.lastname] ?? "").trim(),
          gender: String(row[mapping.gender] ?? "").trim(),
          national_id: String(row[mapping.national_id] ?? "").trim(),
          phone_number: String(row[mapping.phone_number] ?? "").trim()
        }))
        .filter(entry =>
          entry.firstname !== "" ||
          entry.lastname !== "" ||
          entry.gender !== "" ||
          entry.national_id !== "" ||
          entry.phone_number !== ""
        );
    }

    function setMappingProgressText(message) {
      if (!mappingProgressMessage) return;
      mappingProgressMessage.textContent = message || defaultMappingProgressText;
    }

    function showMappingProgress(message) {
      if (!mappingProgressOverlay) return;
      setMappingProgressText(message);
      mappingProgressOverlay.classList.remove("hidden");
    }

    function hideMappingProgress() {
      if (!mappingProgressOverlay) return;
      mappingProgressOverlay.classList.add("hidden");
      setMappingProgressText(defaultMappingProgressText);
    }

    function setCardProgressText(message) {
      if (!cardProgressMessage) return;
      cardProgressMessage.textContent = message || defaultCardProgressText;
    }

    function showCardProgress(message) {
      if (!guestUploadCardProgress) return;
      setCardProgressText(message);
      guestUploadCardProgress.classList.remove("hidden");
    }

    function hideCardProgress() {
      if (!guestUploadCardProgress) return;
      guestUploadCardProgress.classList.add("hidden");
      setCardProgressText(defaultCardProgressText);
    }

    function getAvailableGenders() {
      const set = new Set();
      state.events.forEach(event => {
        (event.guests || []).forEach(g => {
          const val = String(g.gender || "").trim();
          if (val) set.add(val);
        });
      });
      if (!set.size) {
        ["Male", "Female"].forEach(g => set.add(g));
      }
      return Array.from(set);
    }

    function populateGenderSelect(select) {
      if (!select) return;
      const genders = getAvailableGenders();
      select.innerHTML = "";
      genders.forEach(g => {
        const option = document.createElement("option");
        option.value = g;
        option.textContent = g;
        select.appendChild(option);
      });
    }

    async function fetchGuestEvents() {
      try {
        const response = await fetch("./api/guests.php");
        if (!response.ok) throw new Error("Unable to load guest lists.");
        const payload = await response.json();
        if (payload.status !== "ok") throw new Error(payload.message || "Unable to load guest lists.");
        state.events = Array.isArray(payload.events) ? payload.events : [];
        refreshEventControls();
        renderEventWinners();
        loadEventPrizesForCode(activeEventCode);
        populateGenderSelect(manualGenderSelect);
        populateGenderSelect(editGenderSelect);
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Unable to load guest lists." });
      }
    }

    async function savePureList(mapping) {
      const guests = serializeGuests(state.rows, mapping);
      if (!guests.length) {
        throw new Error("No guest rows were found after mapping. Please review your selections.");
      }
      setMappingProgressText("در حال ارسال فایل به سرور...");
      const formData = new FormData();
      formData.append("action", "save_guest_purelist");
      formData.append("event_name", state.eventName);
      formData.append("event_date", state.eventDate);
      formData.append("mapping", JSON.stringify(mapping));
      formData.append("rows", JSON.stringify(state.rows));
      if (state.file) {
        formData.append("guest_file", state.file, state.file.name || "guest-list");
      }
      const response = await fetch("./api/guests.php", { method: "POST", body: formData });
      setMappingProgressText("در حال ساخت لیست مهمانان...");
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.status !== "ok") {
        const message = payload?.message || "Failed to save guest list.";
        throw new Error(message);
      }
      state.events = Array.isArray(payload.events) ? payload.events : state.events;
      refreshEventControls();
      showDefaultToast?.(payload.message || "Guest list saved.");
    }

    mappingForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const mapping = {};
      mappingSelects.forEach(select => {
        const key = select.getAttribute("data-guest-column");
        mapping[key] = select.value;
      });
      if (Object.values(mapping).some(value => !value)) {
        showErrorSnackbar?.({ message: "Please map all guest fields to a column." });
        return;
      }
      mappingSubmit?.setAttribute("disabled", "disabled");
      showMappingProgress("در حال بارگذاری فایل...");
      try {
        await savePureList(mapping);
        hideModal(mappingModal);
        uploadForm?.reset();
        state.columns = [];
        state.rows = [];
        state.file = null;
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to save guest list." });
      } finally {
        mappingSubmit?.removeAttribute("disabled");
        hideMappingProgress();
      }
    });

    uploadForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const name = (eventNameInput?.value || "").trim();
      const date = (eventDateInput?.value || "").trim();
      const file = fileInput?.files?.[0] || null;
      if (!name || !date) {
        showErrorSnackbar?.({ message: "Event name and date are required." });
        return;
      }
      if (!file) {
        showErrorSnackbar?.({ message: "Please choose an Excel or CSV file." });
        return;
      }
      uploadSubmit?.setAttribute("disabled", "disabled");
      showCardProgress("در حال آماده‌سازی فایل...");
      try {
        const parsed = await parseGuestFile(file);
        state.columns = parsed.columns;
        state.rows = parsed.rows;
        state.file = file;
        state.eventName = name;
        state.eventDate = date;
        populateMappingSelects(parsed.columns);
        hideCardProgress();
        hideMappingProgress();
        showModal(mappingModal);
      } catch (error) {
        hideCardProgress();
        showErrorSnackbar?.({ message: error?.message || "Failed to read the uploaded file." });
      } finally {
        uploadSubmit?.removeAttribute("disabled");
        hideCardProgress();
      }
    });

    manualForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const selectedCode = manualEventSelect?.value || "";
      const selectedEvent = state.events.find(ev => (ev.code || "") === selectedCode);
      if (!selectedEvent) {
        showErrorSnackbar?.({ message: "Please select an event before adding a guest." });
        return;
      }
      const eventName = (selectedEvent.name || "").trim();
      const eventDate = (manualEventDateInput?.value || "").trim();
      if (!eventName || !eventDate) {
        showErrorSnackbar?.({ message: "Please select an event and date before adding a guest." });
        return;
      }
      const payload = {
        action: "add_manual_guest",
        event_code: selectedCode,
        event_name: eventName,
        event_date: eventDate,
        firstname: (manualFirstnameInput?.value || "").trim(),
        lastname: (manualLastnameInput?.value || "").trim(),
        gender: manualGenderSelect?.value || "",
        national_id: (manualNationalIdInput?.value || "").trim(),
        phone_number: (manualPhoneInput?.value || "").trim()
      };
      const submitButton = manualForm.querySelector("button[type='submit']");
      submitButton?.setAttribute("disabled", "disabled");
      try {
        const formData = new FormData();
        Object.entries(payload).forEach(([k, v]) => formData.append(k, v));
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== "ok") {
          throw new Error(data?.message || "Failed to add guest.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        refreshEventControls();
        populateGenderSelect(manualGenderSelect);
        populateGenderSelect(editGenderSelect);
        manualForm.reset();
        updateManualEventDate();
        closeManualModal();
        showDefaultToast?.(data.message || "Guest added.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to add guest." });
      } finally {
        submitButton?.removeAttribute("disabled");
      }
    });

    eventInfoForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!activeEventCode) return;
      const name = (eventInfoNameInput?.value || "").trim();
      const date = (eventInfoDateInput?.value || "").trim();
      if (!name || !date) {
        showErrorSnackbar?.({ message: "Event name and date are required." });
        return;
      }
      const submitButton = eventInfoSaveButton;
      submitButton?.setAttribute("disabled", "disabled");
      try {
        const formData = new FormData();
        formData.append("action", "update_event");
        formData.append("code", activeEventCode);
        formData.append("name", name);
        formData.append("date", date);
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== "ok") {
          throw new Error(data?.message || "Failed to save event.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        refreshEventControls();
        showDefaultToast?.(data.message || "Event updated.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to save event." });
      } finally {
        submitButton?.removeAttribute("disabled");
      }
    });

    editForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!editContext) return;
      const payload = {
        action: "update_guest",
        event_code: editContext.code,
        number: editContext.number,
        firstname: (editFirstnameInput?.value || "").trim(),
        lastname: (editLastnameInput?.value || "").trim(),
        gender: editGenderSelect?.value || "",
        national_id: (editNationalIdInput?.value || "").trim(),
        phone_number: (editPhoneInput?.value || "").trim(),
        date_entered: composeDateTime(editDateEnteredInput?.value || "", editTimeEnteredInput?.value || ""),
        date_exited: composeDateTime(editDateExitedInput?.value || "", editTimeExitedInput?.value || "")
      };
      const submitButton = editForm.querySelector("button[type='submit']");
      submitButton?.setAttribute("disabled", "disabled");
      try {
        const formData = new FormData();
        Object.entries(payload).forEach(([k, v]) => formData.append(k, v));
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== "ok") {
          throw new Error(data?.message || "Failed to update guest.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        renderGuestTable();
        hideModal(editModal);
        showDefaultToast?.(data.message || "Guest updated.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to update guest." });
      } finally {
        submitButton?.removeAttribute("disabled");
      }
    });

      exportSmsButton?.addEventListener("click", async () => {
        exportSmsButton.setAttribute("disabled", "disabled");
        try {
          await exportSmsLinks();
          showDefaultToast?.("SMS links download started.");
        } catch (error) {
          showErrorSnackbar?.({ message: error?.message || "Failed to export SMS links." });
        } finally {
          exportSmsButton.removeAttribute("disabled");
        }
      });

    exportPresentGuestButton?.addEventListener("click", async () => {
      exportPresentGuestButton.setAttribute("disabled", "disabled");
      try {
        await exportPresentGuests();
        showDefaultToast?.("Present guests download started.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to export present guest list." });
      } finally {
        exportPresentGuestButton.removeAttribute("disabled");
      }
    });

    function openJalaliPicker(event) {
      event?.preventDefault?.();
      const targetInput = event?.currentTarget instanceof HTMLInputElement ? event.currentTarget : eventDateInput;
      if (!window.jalaliDatepicker || !targetInput) {
        showErrorSnackbar?.({ message: "Jalali datepicker failed to load." });
        return;
      }
      if (!jalaliPickerInitialized) {
        window.jalaliDatepicker.startWatch({
          selector: "[data-jdp]",
          viewMode: "day",
          autoClose: true,
          format: "YYYY/MM/DD",
          initViewGregorian: false
        });
        jalaliPickerInitialized = true;
      }
      window.jalaliDatepicker.show(targetInput);
    }

    function findGuest(code, number) {
      const event = state.events.find(ev => (ev.code || "") === code);
      if (!event) return null;
      const idx = (event.guests || []).findIndex(g => Number(g.number) === Number(number));
      if (idx < 0) return null;
      return { event, guest: event.guests[idx], index: idx };
    }

    function splitDateTime(value) {
      if (!value) return { date: "", time: "" };
      const parts = String(value).trim().split(/\s+/);
      if (parts.length === 1) return { date: parts[0], time: "" };
      return { date: parts[0], time: parts.slice(1).join(" ") };
    }

    function composeDateTime(date, time) {
      if (!date) return "";
      return time ? `${date} ${time}` : date;
    }

    function toEnglishDigits(value) {
      const map = { "۰":"0","۱":"1","۲":"2","۳":"3","۴":"4","۵":"5","۶":"6","۷":"7","۸":"8","۹":"9","٠":"0","١":"1","٢":"2","٣":"3","٤":"4","٥":"5","٦":"6","٧":"7","٨":"8","٩":"9" };
      return String(value).replace(/[۰-۹٠-٩]/g, d => map[d] || d);
    }

    function getNowJalaliDate() {
      try {
        const formatter = new Intl.DateTimeFormat("fa-IR-u-ca-persian", { year: "numeric", month: "2-digit", day: "2-digit" });
        const parts = formatter.format(new Date());
        return toEnglishDigits(parts).replace(/-/g, "/");
      } catch {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, "0");
        const dd = String(now.getDate()).padStart(2, "0");
        return `${yyyy}/${mm}/${dd}`;
      }
    }

    function buildTimeOptions(select) {
      if (!select) return;
      select.innerHTML = "";
      const times = [];
      for (let h = 0; h < 24; h++) {
        for (let m = 0; m < 60; m += 30) {
          const hh = String(h).padStart(2, "0");
          const mm = String(m).padStart(2, "0");
          times.push(`${hh}:${mm}`);
        }
      }
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = "--:--";
      select.appendChild(placeholder);
      times.forEach(t => {
        const option = document.createElement("option");
        option.value = t;
        option.textContent = t;
        select.appendChild(option);
      });
    }

    function openEditModal(code, number) {
      const found = findGuest(code, number);
      if (!found) {
        showErrorSnackbar?.({ message: "Guest not found." });
        return;
      }
      editContext = { code, number };
      const g = found.guest;
      const entryValue = g.join_date
        ? `${g.join_date} ${g.join_time || ""}`.trim()
        : g.date_entered || "";
      const exitValue = g.left_date
        ? `${g.left_date} ${g.left_time || ""}`.trim()
        : g.date_exited || "";
      const enteredParts = splitDateTime(entryValue);
      const exitedParts = splitDateTime(exitValue);
      editFirstnameInput.value = g.firstname || "";
      editLastnameInput.value = g.lastname || "";
      editNationalIdInput.value = g.national_id || "";
      editPhoneInput.value = g.phone_number || "";
      editDateEnteredInput.value = enteredParts.date;
      editTimeEnteredInput.value = enteredParts.time;
      editDateExitedInput.value = exitedParts.date;
      editTimeExitedInput.value = exitedParts.time;
      populateGenderSelect(editGenderSelect);
      editGenderSelect.value = g.gender || "";
      showModal(editModal);
    }

    async function deleteGuest(code, number) {
      if (!code || !number) {
        showErrorSnackbar?.({ message: "Invalid guest selected." });
        return;
      }
      const formData = new FormData();
      formData.append("action", "delete_guest");
      formData.append("event_code", code);
      formData.append("number", String(number));
      try {
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== "ok") {
          throw new Error(data?.message || "Failed to delete guest.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        renderGuestTable();
        showDefaultToast?.(data.message || "Guest deleted.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to delete guest." });
      }
    }

    async function deleteEvent(code) {
      if (!code) {
        throw new Error("Invalid event selected.");
      }
      const formData = new FormData();
      formData.append("action", "delete_event");
      formData.append("event_code", code);
      const response = await fetch("./api/guests.php", { method: "POST", body: formData });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.status !== "ok") {
        throw new Error(data?.message || "Failed to delete event.");
      }
      state.events = Array.isArray(data.events) ? data.events : state.events;
      refreshEventControls();
      renderEventList();
      populateGenderSelect(manualGenderSelect);
      populateGenderSelect(editGenderSelect);
      showDefaultToast?.(data.message || "Event deleted.");
    }

    function normalizeHeaderKey(value) {
      return String(value ?? "")
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]/g, "");
    }

    function headerNameMatches(value, target) {
      return normalizeHeaderKey(value) === normalizeHeaderKey(target);
    }

    async function loadPureListWorkbook(path = PURE_LIST_CSV_PATH) {
      const response = await fetch(path, { cache: "no-store" });
      if (!response.ok) {
        throw new Error("Unable to retrieve the pure CSV guest list.");
      }
      const raw = await response.text();
      if (!raw.trim()) {
        throw new Error("The pure CSV file is empty.");
      }
      const workbook = XLSX.read(raw, { type: "string", raw: false });
      const sheetName = workbook.SheetNames[0];
      if (!sheetName) {
        throw new Error("The pure CSV file does not contain any sheets.");
      }
      return { workbook, sheetName };
    }

    async function loadPureListSheetData(path = PURE_LIST_CSV_PATH) {
      const { workbook, sheetName } = await loadPureListWorkbook(path);
      const sheet = workbook.Sheets[sheetName];
      const headerRows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: "" });
      const headers = Array.isArray(headerRows[0])
        ? headerRows[0].map((header) => (header ?? ""))
        : [];
      const rows = XLSX.utils.sheet_to_json(sheet, { defval: "" });
      return { rows, headers };
    }

    function resolvePureListCsvPath() {
      const activeEvent = getActiveGuestEvent();
      const path = activeEvent && typeof activeEvent.purelist === "string"
        ? activeEvent.purelist.trim()
        : "";
      return path || PURE_LIST_CSV_PATH;
    }

    async function loadPureListCsvRows(path = PURE_LIST_CSV_PATH) {
      const data = await loadPureListSheetData(path);
      return data.rows;
    }

    function isSmsHeaderName(header) {
      const normalized = normalizeHeaderKey(header);
      return normalized === "smslink" || normalized === "invitesmslink";
    }

    function buildPresentGuestsWorkbook(rows, headers) {
      const worksheet = XLSX.utils.json_to_sheet(rows, { header: headers });
      worksheet["!cols"] = headers.map((header) => {
        const normalized = normalizeHeaderKey(header);
        if (normalized.includes("date")) {
          return { wch: 25 };
        }
        return { wch: 20 };
      });
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(workbook, worksheet, "Present guests");
      const hasViews = workbook.Workbook && Array.isArray(workbook.Workbook.Views);
      const existingView = hasViews ? workbook.Workbook.Views[0] : {};
      workbook.Workbook = workbook.Workbook || {};
      workbook.Workbook.Views = [{ ...existingView, RTL: true }];
      return workbook;
    }

    async function exportPresentGuests() {
      const { rows, headers } = await loadPureListSheetData(resolvePureListCsvPath());
      const headerKeys = headers.map((header) => header ?? "");
      const dateEnteredHeader =
        headerKeys.find(header => headerNameMatches(header, "join_date")) ||
        headerKeys.find(header => headerNameMatches(header, "date_entered"));
      const dateExitedHeader =
        headerKeys.find(header => headerNameMatches(header, "left_date")) ||
        headerKeys.find(header => headerNameMatches(header, "date_exited"));
      if (!dateEnteredHeader || !dateExitedHeader) {
        throw new Error("Entry and exit columns are missing from the guest list.");
      }
      const presentRows = rows.filter(row => {
        const entered = String(row[dateEnteredHeader] ?? "").trim();
        const exited = String(row[dateExitedHeader] ?? "").trim();
        return entered !== "" && exited !== "";
      });
      if (!presentRows.length) {
        throw new Error("No guests have both entry and exit timestamps yet.");
      }
      const exportHeaders = headerKeys.filter(header => header !== "" && !isSmsHeaderName(header));
      const normalizedRows = presentRows.map(row => {
        const normalized = {};
        exportHeaders.forEach(header => {
          normalized[header] = row[header] ?? "";
        });
        return normalized;
      });
      const workbook = buildPresentGuestsWorkbook(normalizedRows, exportHeaders);
      const arrayBuffer = XLSX.write(workbook, { bookType: "xlsx", type: "array" });
      const blob = new Blob([arrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = "present-guest-list.xlsx";
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
    }

    function buildSmsWorkbook(rows) {
      const normalized = rows
        .map(row => {
          const firstname = String(row.firstname || row.first_name || "").trim();
          const lastname = String(row.lastname || row.last_name || "").trim();
          const fullname = [firstname, lastname].filter(Boolean).join(" ").trim();
          const phone = String(row.phone_number || row.phone || "").trim();
          const link = String(row.sms_link || row.link || "").trim();
          const nationalId = String(row.national_id || row.nationalid || "").trim();
          const lotteryCode = String(row.number || row.lottery_code || row.unique_code || "").trim();
          return {
            "Lottery code": lotteryCode,
            "Full name": fullname,
            "National ID": nationalId,
            "Phone number": phone,
            "Invite link": link
          };
        })
        .filter(entry =>
          entry["Lottery code"] ||
          entry["Full name"] ||
          entry["National ID"] ||
          entry["Phone number"] ||
          entry["Invite link"]
        );
      if (!normalized.length) {
        throw new Error("The pure CSV file did not yield any guest rows.");
      }
      const worksheet = XLSX.utils.json_to_sheet(normalized, {
        header: ["Lottery code", "Full name", "National ID", "Phone number", "Invite link"]
      });
      worksheet["!cols"] = [{ wch: 15 }, { wch: 35 }, { wch: 18 }, { wch: 20 }, { wch: 45 }];
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(workbook, worksheet, "SMS Links");
      const hasViews = workbook.Workbook && Array.isArray(workbook.Workbook.Views);
      const existingView = hasViews ? workbook.Workbook.Views[0] : {};
      workbook.Workbook = workbook.Workbook || {};
      workbook.Workbook.Views = [{ ...existingView, RTL: true }];
      return workbook;
    }

    async function exportSmsLinks() {
      const rows = await loadPureListCsvRows(resolvePureListCsvPath());
      const workbook = buildSmsWorkbook(rows);
      const arrayBuffer = XLSX.write(workbook, { bookType: "xlsx", type: "array" });
      const blob = new Blob([arrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = "sms-link-list.xlsx";
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
    }

    function escapeHtml(value) {
      return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function setActiveEventSection(sectionKey) {
      if (!sectionKey) return;
      eventSections?.forEach(section => {
        if (!section) return;
        section.classList.toggle("hidden", section.dataset.eventSection !== sectionKey);
      });
      eventSectionTabs?.querySelectorAll("[data-event-section-target]").forEach(tab => {
        const isSelected = tab.dataset.eventSectionTarget === sectionKey;
        tab.classList.toggle("active", isSelected);
        tab.setAttribute("aria-selected", isSelected ? "true" : "false");
      });
    }

    function setEventWinnersStatus(message, isError = false) {
      if (!eventWinnersStatus) return;
      eventWinnersStatus.textContent = message || "";
      if (isError) {
        eventWinnersStatus.style.color = "var(--primary)";
      } else {
        eventWinnersStatus.style.color = "";
      }
    }

    function renderEventWinners(code = activeEventCode) {
      if (!eventWinnerListBody) return;
      const normalizedCode = String(code || "").trim();
      const rows = normalizedCode
        ? cachedWinners.filter(entry => {
          const entryCode = String(entry.event_code || entry.event_slug || "").trim();
          return entryCode && entryCode === normalizedCode;
        })
        : [];
      if (!rows.length) {
        const message = winnersLoaded ? "No winners yet for this event." : "Loading winners...";
        eventWinnerListBody.innerHTML = `<tr><td colspan="6" class="muted">${message}</td></tr>`;
        return;
      }
      eventWinnerListBody.innerHTML = rows
        .map((winner, index) => {
          const fullname = escapeHtml([winner.firstname, winner.lastname].filter(Boolean).join(" ").trim() || "—");
          return `
            <tr>
              <td>${index + 1}</td>
              <td>${fullname}</td>
              <td>${escapeHtml(winner.code || winner.invite_code || "")}</td>
              <td>${escapeHtml(winner.phone_number || winner.phone || "")}</td>
              <td>${escapeHtml(winner.national_id || "")}</td>
              <td>${escapeHtml(winner.timestamp || winner.created_at || "")}</td>
            </tr>
          `;
        })
        .join("");
    }

    async function fetchEventWinners() {
      if (!eventWinnerListBody) return;
      setEventWinnersStatus("Loading winners...");
      try {
        const response = await fetch("winnerstab.php?winner_action=list", { cache: "no-store" });
        const payload = await response.json();
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload.message || "Unable to load winners.");
        }
        cachedWinners = Array.isArray(payload.winners) ? payload.winners : [];
        winnersLoaded = true;
        renderEventWinners();
        const message = cachedWinners.length ? `Loaded ${cachedWinners.length} winner(s).` : "No winners yet.";
        setEventWinnersStatus(message);
      } catch (error) {
        winnersLoaded = true;
        setEventWinnersStatus(error?.message || "Failed to load winners.", true);
        eventWinnerListBody.innerHTML = `<tr><td colspan="6" class="muted">Unable to load winners.</td></tr>`;
      }
    }

    function setEventPrizeStatus(message, isError = false) {
      if (!eventPrizeStatus) return;
      eventPrizeStatus.textContent = message || "";
      if (isError) {
        eventPrizeStatus.style.color = "var(--primary)";
      } else {
        eventPrizeStatus.style.color = "";
      }
    }

    function renderEventPrizeTable() {
      if (!eventPrizeListBody) return;
      if (!eventPrizes.length) {
        eventPrizeListBody.innerHTML = `<tr><td colspan="3" class="muted">No prizes defined yet.</td></tr>`;
        return;
      }
      eventPrizeListBody.innerHTML = eventPrizes
        .map(prize => `
          <tr data-prize-id="${prize.id}">
            <td>${prize.id}</td>
            <td style="width:100%;">
              <label class="field standard-width" style="margin:0;">
                <input
                  type="text"
                  class="event-prize-inline-input"
                  data-prize-id="${prize.id}"
                  value="${escapeHtml(prize.name)}"
                  data-original="${escapeHtml(prize.name)}"
                  style="direction:rtl; text-align:right;"
                />
              </label>
            </td>
            <td>
              <button type="button" class="btn ghost" data-event-prize-action="delete" data-prize-id="${prize.id}">Delete</button>
            </td>
          </tr>
        `)
        .join("");
    }

    async function loadEventPrizesForCode(code = "") {
      if (!eventPrizeListBody) return;
      currentEventPrizeCode = String(code || "").trim();
      if (!currentEventPrizeCode) {
        eventPrizes = [];
        eventPrizeListBody.innerHTML = `<tr><td colspan="3" class="muted">Select an event to manage prizes.</td></tr>`;
        setEventPrizeStatus("Select an event to view prizes.");
        return;
      }
      const requestId = ++eventPrizeFetchId;
      setEventPrizeStatus("Loading prizes...");
      eventPrizeListBody.innerHTML = `<tr><td colspan="3" class="muted">Loading prizes...</td></tr>`;
      try {
        const params = new URLSearchParams({ prize_action: "list" });
        if (currentEventPrizeCode) {
          params.set("event_code", currentEventPrizeCode);
        }
        const response = await fetch(`prizestab.php?${params.toString()}`, { cache: "no-store" });
        const payload = await response.json();
        if (requestId !== eventPrizeFetchId) {
          return;
        }
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload.message || "Unable to load prizes.");
        }
        eventPrizes = Array.isArray(payload.prizes) ? payload.prizes : [];
        renderEventPrizeTable();
        setEventPrizeStatus(eventPrizes.length ? `Loaded ${eventPrizes.length} prize(s).` : "No prizes yet.");
      } catch (error) {
        if (requestId !== eventPrizeFetchId) {
          return;
        }
        setEventPrizeStatus(error?.message || "Unable to load prizes.", true);
        eventPrizeListBody.innerHTML = `<tr><td colspan="3" class="muted">Unable to load prizes.</td></tr>`;
      }
    }

    async function sendEventPrizeAction(action, data = {}) {
      if (!currentEventPrizeCode) {
        setEventPrizeStatus("Select an event before updating prizes.", true);
        return;
      }
      const formData = new FormData();
      formData.append("prize_action", action);
      formData.append("event_code", currentEventPrizeCode);
      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });
      const response = await fetch("prizestab.php", {
        method: "POST",
        body: formData
      });
      const payload = await response.json();
      if (!response.ok || payload.status !== "ok") {
        throw new Error(payload.message || "Unable to save prize changes.");
      }
      eventPrizes = Array.isArray(payload.prizes) ? payload.prizes : eventPrizes;
      renderEventPrizeTable();
      setEventPrizeStatus(payload.message || "Changes saved.");
      return payload;
    }

    function handleEventPrizeInlineUpdate(input) {
      if (!input) return;
      const id = input.getAttribute("data-prize-id");
      if (!id) return;
      const original = (input.getAttribute("data-original") ?? "").trim();
      const value = (input.value ?? "").trim();
      if (value === original) {
        input.value = original;
        return;
      }
      input.setAttribute("disabled", "disabled");
      sendEventPrizeAction("update", { id, name: value })
        .then(() => {
          input.setAttribute("data-original", value);
        })
        .catch(error => {
          setEventPrizeStatus(error?.message || "Unable to update prize.", true);
          input.value = original;
        })
        .finally(() => {
          input.removeAttribute("disabled");
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
      fileTrigger?.addEventListener("click", (e) => {
        e.preventDefault();
        fileInput?.click();
      });
      fileInput?.addEventListener("change", () => {
        const file = fileInput.files?.[0];
        fileNameLabel.textContent = file ? file.name : "No file chosen";
      });

      eventDateInput?.addEventListener("focus", openJalaliPicker);
      eventDateInput?.addEventListener("click", openJalaliPicker);
      eventDateInput?.addEventListener("keydown", (evt) => {
        openJalaliPicker(evt);
      });

      manualEventDateInput?.addEventListener("focus", openJalaliPicker);
      manualEventDateInput?.addEventListener("click", openJalaliPicker);
      manualEventDateInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      eventInfoDateInput?.addEventListener("focus", openJalaliPicker);
      eventInfoDateInput?.addEventListener("click", openJalaliPicker);
      eventInfoDateInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      manualEventSelect?.addEventListener("change", updateManualEventDate);

      editDateEnteredInput?.addEventListener("focus", openJalaliPicker);
      editDateEnteredInput?.addEventListener("click", openJalaliPicker);
      editDateEnteredInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      editDateExitedInput?.addEventListener("focus", openJalaliPicker);
      editDateExitedInput?.addEventListener("click", openJalaliPicker);
      editDateExitedInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      manualOpenButton?.addEventListener("click", () => {
        openManualModal();
      });

      manualEventPaneAddButton?.addEventListener("click", () => {
        if (!activeEventCode) {
          showErrorSnackbar?.({ message: "Please select an event before adding a guest manually." });
          return;
        }
        openManualModal({ forceEventCode: activeEventCode, lockEventSelection: true });
      });

      guestListBody?.addEventListener("click", (evt) => {
        const target = evt.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.hasAttribute("data-guest-edit")) {
          const code = target.getAttribute("data-code") || "";
          const number = Number(target.getAttribute("data-number") || 0);
          openEditModal(code, number);
        } else if (target.hasAttribute("data-guest-delete")) {
          const code = target.getAttribute("data-code") || "";
          const number = Number(target.getAttribute("data-number") || 0);
          deleteGuest(code, number);
        }
      });

      async function confirmEventDeletionPrompt() {
        const message = "Delete this event and all associated data including invites?";
        if (typeof showDialog === "function") {
          return await showDialog(message, {
            confirm: true,
            title: "Delete event",
            okText: "Delete",
            cancelText: "Cancel"
          });
        }
        return window.confirm(message);
      }

      eventListBody?.addEventListener("click", async (evt) => {
        const button = evt.target instanceof HTMLElement ? evt.target.closest("[data-event-delete]") : null;
        if (!button) {
          return;
        }
        const code = button.getAttribute("data-event-code") || "";
        if (!code) {
          return;
        }
        const confirmed = await confirmEventDeletionPrompt();
        if (!confirmed) {
          return;
        }
        button.setAttribute("disabled", "disabled");
        try {
          await deleteEvent(code);
        } catch (error) {
          showErrorSnackbar?.({ message: error?.message || "Failed to delete event." });
        } finally {
          button.removeAttribute("disabled");
        }
      });

      eventInfoDeleteButton?.addEventListener("click", async () => {
        if (!activeEventCode) {
          return;
        }
        const confirmed = await confirmEventDeletionPrompt();
        if (!confirmed) {
          return;
        }
        eventInfoDeleteButton.setAttribute("disabled", "disabled");
        try {
          await deleteEvent(activeEventCode);
        } catch (error) {
          showErrorSnackbar?.({ message: error?.message || "Failed to delete event." });
        } finally {
          eventInfoDeleteButton.removeAttribute("disabled");
        }
      });

      buildTimeOptions(editTimeEnteredInput);
      buildTimeOptions(editTimeExitedInput);

      editNowButton?.addEventListener("click", () => {
        const hh = String(new Date().getHours()).padStart(2, "0");
        const min = String(new Date().getMinutes()).padStart(2, "0");
        editDateEnteredInput.value = getNowJalaliDate();
        editTimeEnteredInput.value = `${hh}:${min}`;
      });

      editClearEnteredButton?.addEventListener("click", () => {
        editDateEnteredInput.value = "";
        editTimeEnteredInput.value = "";
      });

      editClearExitButton?.addEventListener("click", () => {
        editDateExitedInput.value = "";
        editTimeExitedInput.value = "";
      });

      eventSectionTabs?.addEventListener("click", (evt) => {
        const button = evt.target.closest("[data-event-section-target]");
        if (!button) return;
        setActiveEventSection(button.dataset.eventSectionTarget || "event-info");
      });
      setActiveEventSection("event-info");
      eventPrizeForm?.addEventListener("submit", async (evt) => {
        evt.preventDefault();
        if (!eventPrizeInput) return;
        const name = (eventPrizeInput.value || "").trim();
        if (!name) {
          setEventPrizeStatus("Prize name cannot be empty.", true);
          return;
        }
        eventPrizeAddButton?.setAttribute("disabled", "disabled");
        try {
          await sendEventPrizeAction("add", { name });
          eventPrizeForm.reset();
          eventPrizeInput.focus();
        } catch (error) {
          setEventPrizeStatus(error?.message || "Unable to add prize.", true);
        } finally {
          eventPrizeAddButton?.removeAttribute("disabled");
        }
      });
      eventPrizeListBody?.addEventListener("focusin", (evt) => {
        const input = evt.target.closest(".event-prize-inline-input");
        if (input) {
          input.setAttribute("data-original", input.value ?? "");
        }
      });
      eventPrizeListBody?.addEventListener("focusout", (evt) => {
        const input = evt.target.closest(".event-prize-inline-input");
        if (!input) return;
        if (
          evt.relatedTarget &&
          evt.relatedTarget.closest &&
          evt.relatedTarget.closest("[data-event-prize-action=\"delete\"]")
        ) {
          return;
        }
        handleEventPrizeInlineUpdate(input);
      });
      eventPrizeListBody?.addEventListener("keydown", (evt) => {
        if (evt.key !== "Enter") return;
        const input = evt.target.closest(".event-prize-inline-input");
        if (!input) return;
        evt.preventDefault();
        input.blur();
      });
      eventPrizeListBody?.addEventListener("click", async (evt) => {
        const button = evt.target.closest("[data-event-prize-action=\"delete\"]");
        if (!button) return;
        const id = button.getAttribute("data-prize-id");
        if (!id) return;
        const confirmDeletion =
          typeof showDialog === "function"
            ? await showDialog("Delete this prize?", {
                confirm: true,
                title: "Delete prize",
                okText: "Delete",
                cancelText: "Cancel"
              })
            : window.confirm("Delete this prize?");
        if (!confirmDeletion) {
          return;
        }
        button.setAttribute("disabled", "disabled");
        try {
          await sendEventPrizeAction("delete", { id });
        } catch (error) {
          setEventPrizeStatus(error?.message || "Unable to delete prize.", true);
        } finally {
          button.removeAttribute("disabled");
        }
      });
      fetchEventWinners();

      renderEventTabs();
      fetchGuestEvents();
      setActivePane("guest-upload-pane");
      subPaneButtons?.forEach(button => {
        button.addEventListener("click", () => {
          setActivePane(button.dataset.pane);
        });
      });
    });
  })();
</script>
