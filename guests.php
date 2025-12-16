<section id="tab-guests" class="tab">
  <link rel="stylesheet" href="style/jalalidatepicker.min.css" />

  <div class="card">
    <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <h3>List of guests</h3>
        <p class="muted small">Upload an event guest list or add guests manually, then generate a clean list.</p>
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

  <div style="height:16px;"></div>

    <div class="card">
      <div class="table-header">
        <h3>Guest lists</h3>
        <div class="table-actions">
          <label class="field inline">
            <span class="muted small">Event</span>
            <select id="guest-event-filter">
              <option value="">All events</option>
            </select>
          </label>
          <button type="button" class="btn primary" id="export-sms-link">Export SMS Link</button>
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
            <th>Entered</th>
            <th>Exited</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="guest-list-body">
          <tr>
            <td colspan="11" class="muted">No guest lists yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div id="guest-mapping-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="guest-mapping-title">
    <div class="modal-card">
      <div class="modal-header">
        <h3 id="guest-mapping-title">Map columns to guest fields</h3>
        <button type="button" class="icon-btn" data-guest-mapping-close aria-label="Close mapping modal">X</button>
      </div>
      <div class="modal-body">
        <p class="muted small">Choose which columns from your upload match each required field.</p>
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
              <select id="manual-event-select" name="event_slug" required>
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

    const uploadForm = document.getElementById("guest-upload-form");
    const uploadSubmit = document.getElementById("guest-upload-submit");
    const eventNameInput = document.getElementById("guest-event-name");
    const eventDateInput = document.getElementById("guest-event-date");
    const fileInput = document.getElementById("guest-file");
    const fileTrigger = document.getElementById("guest-file-trigger");
    const fileNameLabel = document.getElementById("guest-file-name");
    const eventFilter = document.getElementById("guest-event-filter");
    const guestListBody = document.getElementById("guest-list-body");
    const mappingModal = document.getElementById("guest-mapping-modal");
    const mappingForm = document.getElementById("guest-mapping-form");
    const mappingSubmit = document.getElementById("guest-mapping-submit");
    const mappingSelects = mappingForm ? mappingForm.querySelectorAll("[data-guest-column]") : [];
    const mappingCloseButtons = $qsa("[data-guest-mapping-close]", mappingModal || document);
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
    const exportSmsButton = document.getElementById("export-sms-link");
    const PURE_LIST_CSV_PATH = "./events/event/purelist.csv";
    const editClearEnteredButton = document.getElementById("edit-clear-entered-btn");

    function showModal(modal) {
      if (!modal) return;
      modal.classList.remove("hidden");
      modal.focus?.();
    }

    function hideModal(modal) {
      if (!modal) return;
      modal.classList.add("hidden");
    }

    mappingCloseButtons.forEach(btn => {
      btn.addEventListener("click", evt => {
        evt.preventDefault();
        hideModal(mappingModal);
      });
    });

    manualCloseButtons.forEach(btn => {
      btn.addEventListener("click", evt => {
        evt.preventDefault();
        hideModal(manualModal);
      });
    });

    manualModal?.addEventListener("click", evt => {
      if (evt.target === manualModal) hideModal(manualModal);
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

    function renderEventFilter() {
      if (!eventFilter) return;
      const current = eventFilter.value;
      eventFilter.innerHTML = '<option value="">All events</option>';
      state.events.forEach(event => {
        const option = document.createElement("option");
        option.value = event.slug;
        option.textContent = event.name;
        eventFilter.appendChild(option);
      });
      eventFilter.value = current || "";
      renderManualEventOptions();
    }

    function renderManualEventOptions() {
      if (!manualEventSelect) return;
      const current = manualEventSelect.value;
      manualEventSelect.innerHTML = '<option value="">Select an event</option>';
      state.events.forEach(event => {
        const option = document.createElement("option");
        option.value = event.slug || "";
        option.textContent = event.name || event.slug || "Unnamed event";
        manualEventSelect.appendChild(option);
      });
      if (current && state.events.some(ev => (ev.slug || "") === current)) {
        manualEventSelect.value = current;
      } else {
        manualEventSelect.value = "";
      }
      updateManualEventDate();
    }

    function updateManualEventDate() {
      if (!manualEventDateInput) return;
      const selectedSlug = manualEventSelect?.value || "";
      const selectedEvent = state.events.find(ev => (ev.slug || "") === selectedSlug);
      manualEventDateInput.value = (selectedEvent?.date || "").trim();
    }

    function renderGuestTable() {
      if (!guestListBody) return;
      guestListBody.innerHTML = "";
      const selectedSlug = eventFilter?.value || "";
      const rows = [];
      state.events.forEach(event => {
        if (selectedSlug && event.slug !== selectedSlug) return;
        (event.guests || []).forEach(guest => {
          rows.push({
            number: guest.number || rows.length + 1,
            event: event.name,
            date: event.date,
            firstname: guest.firstname || "",
            lastname: guest.lastname || "",
            gender: guest.gender || "",
            national_id: guest.national_id || "",
            phone_number: guest.phone_number || "",
            date_entered: guest.date_entered || "",
            date_exited: guest.date_exited || "",
            slug: event.slug
          });
        });
      });
      if (!rows.length) {
        const emptyRow = document.createElement("tr");
        const td = document.createElement("td");
        td.colSpan = 11;
        td.className = "muted";
        td.textContent = "No guest lists yet.";
        emptyRow.appendChild(td);
        guestListBody.appendChild(emptyRow);
        return;
      }
      rows.forEach(row => {
        const tr = document.createElement("tr");
        ["number", "event", "date", "firstname", "lastname", "gender", "national_id", "phone_number", "date_entered", "date_exited"].forEach(key => {
          const td = document.createElement("td");
          td.textContent = row[key] ?? "";
          tr.appendChild(td);
        });
        const actionTd = document.createElement("td");
        actionTd.innerHTML = `
          <button type="button" class="btn small" data-guest-edit data-slug="${row.slug || ""}" data-number="${row.number}">Edit</button>
          <button type="button" class="btn ghost small" data-guest-delete data-slug="${row.slug || ""}" data-number="${row.number}">Delete</button>
        `;
        tr.appendChild(actionTd);
        guestListBody.appendChild(tr);
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
        renderEventFilter();
        renderGuestTable();
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
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.status !== "ok") {
        const message = payload?.message || "Failed to save guest list.";
        throw new Error(message);
      }
      state.events = Array.isArray(payload.events) ? payload.events : state.events;
      renderEventFilter();
      renderGuestTable();
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
      try {
        const parsed = await parseGuestFile(file);
        state.columns = parsed.columns;
        state.rows = parsed.rows;
        state.file = file;
        state.eventName = name;
        state.eventDate = date;
        populateMappingSelects(parsed.columns);
        showModal(mappingModal);
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to read the uploaded file." });
      } finally {
        uploadSubmit?.removeAttribute("disabled");
      }
    });

    manualForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const selectedSlug = manualEventSelect?.value || "";
      const selectedEvent = state.events.find(ev => (ev.slug || "") === selectedSlug);
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
        renderEventFilter();
        renderGuestTable();
        populateGenderSelect(manualGenderSelect);
        populateGenderSelect(editGenderSelect);
        manualForm.reset();
        updateManualEventDate();
        hideModal(manualModal);
        showDefaultToast?.(data.message || "Guest added.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to add guest." });
      } finally {
        submitButton?.removeAttribute("disabled");
      }
    });

    editForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!editContext) return;
      const payload = {
        action: "update_guest",
        event_slug: editContext.slug,
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

    eventFilter?.addEventListener("change", renderGuestTable);

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

    function findGuest(slug, number) {
      const event = state.events.find(ev => (ev.slug || "") === slug);
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

    function openEditModal(slug, number) {
      const found = findGuest(slug, number);
      if (!found) {
        showErrorSnackbar?.({ message: "Guest not found." });
        return;
      }
      editContext = { slug, number };
      const g = found.guest;
      const enteredParts = splitDateTime(g.date_entered || "");
      const exitedParts = splitDateTime(g.date_exited || "");
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

    async function deleteGuest(slug, number) {
      if (!slug || !number) {
        showErrorSnackbar?.({ message: "Invalid guest selected." });
        return;
      }
      const formData = new FormData();
      formData.append("action", "delete_guest");
      formData.append("event_slug", slug);
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

    async function loadPureListCsvRows() {
      const response = await fetch(PURE_LIST_CSV_PATH, { cache: "no-store" });
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
      return XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { defval: "" });
    }

    function buildSmsWorkbook(rows) {
      const normalized = rows
        .map(row => {
          const firstname = String(row.firstname || row.first_name || "").trim();
          const lastname = String(row.lastname || row.last_name || "").trim();
          const fullname = [firstname, lastname].filter(Boolean).join(" ").trim();
          const phone = String(row.phone_number || row.phone || "").trim();
          const link = String(row.sms_link || row.link || "").trim();
          return {
            "نام کامل": fullname,
            "شماره تلفن": phone,
            "لینک کارت دعوت": link
          };
        })
        .filter(entry => entry["نام کامل"] || entry["شماره تلفن"] || entry["لینک کارت دعوت"]);
      if (!normalized.length) {
        throw new Error("The pure CSV file did not yield any guest rows.");
      }
      const worksheet = XLSX.utils.json_to_sheet(normalized, {
        header: ["نام کامل", "شماره تلفن", "لینک کارت دعوت"]
      });
      worksheet["!cols"] = [{ wch: 35 }, { wch: 20 }, { wch: 45 }];
      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(workbook, worksheet, "SMS Links");
      const hasViews = workbook.Workbook && Array.isArray(workbook.Workbook.Views);
      const existingView = hasViews ? workbook.Workbook.Views[0] : {};
      workbook.Workbook = workbook.Workbook || {};
      workbook.Workbook.Views = [{ ...existingView, RTL: true }];
      return workbook;
    }

    async function exportSmsLinks() {
      const rows = await loadPureListCsvRows();
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

      manualEventSelect?.addEventListener("change", updateManualEventDate);

      editDateEnteredInput?.addEventListener("focus", openJalaliPicker);
      editDateEnteredInput?.addEventListener("click", openJalaliPicker);
      editDateEnteredInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      editDateExitedInput?.addEventListener("focus", openJalaliPicker);
      editDateExitedInput?.addEventListener("click", openJalaliPicker);
      editDateExitedInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      manualOpenButton?.addEventListener("click", () => {
        renderManualEventOptions();
        showModal(manualModal);
      });

      guestListBody?.addEventListener("click", (evt) => {
        const target = evt.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.hasAttribute("data-guest-edit")) {
          const slug = target.getAttribute("data-slug") || "";
          const number = Number(target.getAttribute("data-number") || 0);
          openEditModal(slug, number);
        } else if (target.hasAttribute("data-guest-delete")) {
          const slug = target.getAttribute("data-slug") || "";
          const number = Number(target.getAttribute("data-number") || 0);
          deleteGuest(slug, number);
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

      fetchGuestEvents();
    });
  })();
</script>
