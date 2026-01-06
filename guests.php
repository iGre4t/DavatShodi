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
          <button
            type="button"
            class="default-top-tab-list__tab"
            data-event-section-target="event-invite-card"
            aria-controls="event-invite-card-section"
            aria-selected="false"
          >
            Invite Card Generator
          </button>
          <button
            type="button"
            class="default-top-tab-list__tab"
            data-event-section-target="event-setting"
            aria-controls="event-setting-section"
            aria-selected="false"
          >
            Event Setting
          </button>
        </div>
        <div class="event-section" data-event-section="event-info" id="event-info-section">
          <div class="card">
            <div class="card-progress hidden" role="status" aria-live="polite">
              <div class="loader-ring" aria-hidden="true">
                <span></span>
                <span></span>
              </div>
              <p class="card-progress__message" data-card-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ ÙØ§ÛŒÙ„...</p>
            </div>
            <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
              <div>
                <h3>Event info</h3>
              </div>
            </div>
            <p id="event-info-live-status" class="muted small" style="margin-top:4px;">غیرفعال</p>
            <div
              class="section-actions"
              style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:12px;"
            >
              <button type="button" class="btn primary" id="event-pot-open">Event Pot</button>
              <button type="button" class="btn ghost" id="copy-event-pot-link">Copy Event Pot Link</button>
              <button type="button" class="btn ghost" id="event-info-open-invite" disabled>Invite</button>
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
              <div class="field standard-width">
                <span>Event entry time period</span>
                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:4px;">
                  <label class="field" style="flex:1 1 180px; margin:0;">
                    <span>Event join start time</span>
                    <select id="event-info-join-start-time" name="join_start_time" class="field"></select>
                  </label>
                  <label class="field" style="flex:1 1 180px; margin:0;">
                    <span>Event join limit time</span>
                    <select id="event-info-join-limit-time" name="join_limit_time" class="field"></select>
                  </label>
                  <label class="field" style="flex:1 1 180px; margin:0;">
                    <span>Event left time</span>
                    <select id="event-info-left-time" name="join_left_time" class="field"></select>
                  </label>
                  <label class="field" style="flex:1 1 180px; margin:0;">
                    <span>Event end time</span>
                    <select id="event-info-join-end-time" name="join_end_time" class="field"></select>
                  </label>
                </div>
              </div>
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
            <div class="card-progress hidden" role="status" aria-live="polite">
              <div class="loader-ring" aria-hidden="true">
                <span></span>
                <span></span>
              </div>
              <p class="card-progress__message" data-card-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ ÙØ§ÛŒÙ„...</p>
            </div>
            <div class="table-header">
              <h3>Guest lists</h3>
              <div
                class="table-actions"
                style="align-items:flex-start; display:flex; flex-wrap:wrap; justify-content:space-between; gap:12px;"
              >
                <div style="display:flex; align-items:center; gap:8px;">
                  <button type="button" class="btn" id="export-sms-link">Export SMS Link</button>
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
            <div class="card-progress hidden" role="status" aria-live="polite">
              <div class="loader-ring" aria-hidden="true">
                <span></span>
                <span></span>
              </div>
              <p class="card-progress__message" data-card-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ ÙØ§ÛŒÙ„...</p>
            </div>
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
            <div class="card-progress hidden" role="status" aria-live="polite">
              <div class="loader-ring" aria-hidden="true">
                <span></span>
                <span></span>
              </div>
              <p class="card-progress__message" data-card-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ ÙØ§ÛŒÙ„...</p>
            </div>
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

        <div class="event-section hidden" data-event-section="event-invite-card" id="event-invite-card-section">
          <?php include __DIR__ . '/InvCardGen.php'; ?>
        </div>

        <div class="event-section hidden" data-event-section="event-setting" id="event-setting-section">
          <div class="card">
            <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
              <div>
                <h3>تنظیمات ورود و خروج</h3>
                <p class="muted small" style="margin:0;">با فعال بودن این گزینه، بعد از ثبت ورود مهمان پنجره چاپ رسید نشان داده می‌شود و در غیر این‌صورت صرفاً ورود ثبت می‌شود.</p>
              </div>
              <label class="switch">
                <span class="switch-label">چاپ ورود</span>
                <span class="switch-toggle">
                  <input type="checkbox" id="event-setting-print-entry" aria-label="چاپ ورود" />
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </span>
              </label>
            </div>
          </div>
          <div class="card">
              <div class="section-header" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                  <h3>Event Setting</h3>
                <p class="muted small" style="margin:0;">از اینجا می‌توانید برای رویدادهای گذشته، صفحات دعوت را بسازید.</p>
              </div>
              <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" class="btn primary" id="event-setting-create-invite">
                  Create Invite Page
                </button>
                <button type="button" class="btn ghost" id="event-setting-refresh-export">
                  Refresh SMS Export
                </button>
              </div>
            </div>
            <p id="event-setting-status" class="muted small" style="margin:0;">Select an event to enable invite creation.</p>
          </div>
        </div>

</section>

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
      <div class="modal-progress hidden" role="status" aria-live="polite">
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="modal-progress__message" data-modal-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ...</p>
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
              <span>Join date and time</span>
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
              <span>Left date and time</span>
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
                <button type="button" class="btn ghost" id="edit-now-exit-btn" style="flex:0 0 auto;">Current date and time</button>
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
      <div class="modal-progress hidden" role="status" aria-live="polite">
        <div class="loader-ring" aria-hidden="true">
          <span></span>
          <span></span>
        </div>
        <p class="modal-progress__message" data-modal-progress-message>Ø¯Ø± Ø­Ø§Ù„ Ø¢Ù…Ø§Ø¯Ù‡â€ŒØ³Ø§Ø²ÛŒ...</p>
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

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js" defer></script>
<script src="style/jalalidatepicker.min.js" defer></script>
<script>
  (function() {
    const $qs = (sel, root = document) => root.querySelector(sel);
    const $qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

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

    const showDefaultToast = (...args) => window.showDefaultToast?.(...args);
    const showErrorSnackbar = (...args) => window.showErrorSnackbar?.(...args);

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
    const inviteCardFieldDefaults = {};
    const prefixStyleColorState = {};
    const prefixStyleFontOptions = [
      "PeydaWebFaNum",
      "PeydaWebFaNum-Bold",
      "remixicon",
      "IRANSansX-Bold",
      "IRANSansXFaNum-Bold"
    ];
    const prefixStyleWeightOptions = ["400", "500", "600", "700"];
    let lastInviteCardTemplateSignature = "";
    let lastInviteCardPrefixGenders = null;
    let lastInviteCardGenderOptions = null;
    const inviteCardPrefixContainer = document.querySelector("[data-prefix-container]");
    const inviteCardPrefixPlaceholder = document.querySelector("[data-invite-prefix-placeholder]");
    const inviteCardGenderSelect = document.querySelector("[data-invite-card-gender]");
    const PURE_LIST_CSV_PATH = "./events/event/purelist.csv";

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
    const editModalProgress = editModal?.querySelector(".modal-progress");
    const editModalProgressMessage = editModalProgress?.querySelector("[data-modal-progress-message]");
    const defaultEditModalProgressText = (editModalProgressMessage?.textContent || "").trim() || "در حال انجام...";
    const editNowExitButton = document.getElementById("edit-now-exit-btn");
    let editContext = null;
    const manualModal = document.getElementById("guest-manual-modal");
    const manualCloseButtons = $qsa("[data-guest-manual-close]", manualModal || document);
    const manualModalProgress = manualModal?.querySelector(".modal-progress");
    const manualModalProgressMessage = manualModalProgress?.querySelector("[data-modal-progress-message]");
    const defaultManualModalProgressText = (manualModalProgressMessage?.textContent || "").trim() || "در حال انجام...";
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
    const eventInfoJoinStartInput = document.getElementById("event-info-join-start-time");
    const eventInfoJoinLimitInput = document.getElementById("event-info-join-limit-time");
    const eventInfoLeftTimeInput = document.getElementById("event-info-left-time");
    const eventInfoJoinEndInput = document.getElementById("event-info-join-end-time");
    const eventInfoStatusText = document.getElementById("event-info-live-status");
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
    const eventInfoInviteButton = document.getElementById("event-info-open-invite");
    const eventSettingCreateInviteButton = document.getElementById("event-setting-create-invite");
    const eventSettingRefreshButton = document.getElementById("event-setting-refresh-export");
    const eventSettingStatusText = document.getElementById("event-setting-status");
    const eventSettingPrintToggle = document.getElementById("event-setting-print-entry");
    const INVITE_BASE_URL = "https://davatshodi.ir/l/inv";
    const EVENT_POT_BASE_URL = "https://davatshodi.ir/l/events";
    const eventPotOpenButton = document.getElementById("event-pot-open");
    const copyEventPotLinkButton = document.getElementById("copy-event-pot-link");
    const editClearEnteredButton = document.getElementById("edit-clear-entered-btn");
    const subPaneButtons = document.querySelectorAll(".sub-sidebar .sub-nav [data-pane]");
    const subPanes = document.querySelectorAll(".sub-content .sub-pane");
    let currentSubPane = "guest-upload-pane";
    let cachedWinners = [];
    let winnersLoaded = false;
    let eventPrizes = [];
    let currentEventPrizeCode = "";
    let eventSettingActiveEventCode = "";
    let eventSettingPrintUpdating = false;
    let eventSettingRefreshInProgress = false;
    let eventInfoCountdownTimer = null;
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

    function copyTextToClipboard(text) {
      if (!text) {
        return Promise.reject(new Error("No text to copy."));
      }
      if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
        return navigator.clipboard.writeText(text);
      }
      const textarea = document.createElement("textarea");
      textarea.value = text;
      textarea.setAttribute("readonly", "");
      textarea.style.position = "absolute";
      textarea.style.left = "-9999px";
      document.body.appendChild(textarea);
      textarea.select();
      textarea.setSelectionRange(0, textarea.value.length);
      const successful = document.execCommand("copy");
      document.body.removeChild(textarea);
      if (!successful) {
        return Promise.reject(new Error("Copy command failed."));
      }
      return Promise.resolve();
    }

    function buildEventPotDrawUrl(eventCode = "") {
      const code = String(eventCode || activeEventCode || "").trim();
      if (!code) {
        return "";
      }
      return `${EVENT_POT_BASE_URL}/${encodeURIComponent(code)}/draw.php`;
    }

    function closeManualModal() {
      manualLockedEventCode = "";
      manualEventSelect?.removeAttribute("disabled");
      hideManualModalProgress();
      hideModal(manualModal);
    }

    function setEditModalProgressText(message) {
      if (!editModalProgressMessage) return;
      editModalProgressMessage.textContent = message || defaultEditModalProgressText;
    }

    function showEditModalProgress(message) {
      if (!editModalProgress) return;
      setEditModalProgressText(message);
      editModalProgress.classList.remove("hidden");
    }

    function hideEditModalProgress() {
      if (!editModalProgress) return;
      editModalProgress.classList.add("hidden");
      setEditModalProgressText(defaultEditModalProgressText);
    }

    function closeEditModal() {
      editContext = null;
      hideEditModalProgress();
      hideModal(editModal);
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
        closeEditModal();
      });
    });

    editModal?.addEventListener("click", evt => {
      if (evt.target === editModal) closeEditModal();
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
      hideManualModalProgress();
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
      applyEventInviteCardTemplate();
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
      if (eventInfoJoinStartInput) {
        eventInfoJoinStartInput.value = selectTimeValue(selectedEvent?.join_start_time || "");
        if (hasEvent) {
          eventInfoJoinStartInput.removeAttribute("disabled");
        } else {
          eventInfoJoinStartInput.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoJoinLimitInput) {
        eventInfoJoinLimitInput.value = selectTimeValue(selectedEvent?.join_limit_time || "");
        if (hasEvent) {
          eventInfoJoinLimitInput.removeAttribute("disabled");
        } else {
          eventInfoJoinLimitInput.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoLeftTimeInput) {
        eventInfoLeftTimeInput.value = selectTimeValue(selectedEvent?.join_left_time || "");
        if (hasEvent) {
          eventInfoLeftTimeInput.removeAttribute("disabled");
        } else {
          eventInfoLeftTimeInput.setAttribute("disabled", "disabled");
        }
      }
      if (eventInfoJoinEndInput) {
        eventInfoJoinEndInput.value = selectTimeValue(selectedEvent?.join_end_time || "");
        if (hasEvent) {
          eventInfoJoinEndInput.removeAttribute("disabled");
        } else {
          eventInfoJoinEndInput.setAttribute("disabled", "disabled");
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
      updateEventSectionTabAccessibility(hasEvent);
      if (!hasEvent) {
        setActiveEventSection("event-info");
      }
      updateEventInfoLiveStatus();
      updateEventInfoInviteButton(selectedEvent);
      updateEventSettingControls();
    }

    function updateEventSectionTabAccessibility(isReady) {
      eventSectionTabs?.querySelectorAll("[data-event-section-target]").forEach(tab => {
        if (tab.dataset.eventSectionTarget === "event-info") {
          tab.removeAttribute("disabled");
          return;
        }
        tab.disabled = !isReady;
      });
    }

    function setEventSettingStatusMessage(message = "", state = "") {
      if (!eventSettingStatusText) return;
      eventSettingStatusText.textContent = message;
      if (state === "") {
        eventSettingStatusText.removeAttribute("data-state");
      } else {
        eventSettingStatusText.dataset.state = state;
      }
    }

    function updateEventSettingRefreshState() {
      if (!eventSettingRefreshButton) return;
      const selectedEvent = state.events.find(ev => (ev.code || "") === activeEventCode) || null;
      const hasEvent = Boolean(selectedEvent && selectedEvent.code);
      eventSettingRefreshButton.disabled = !hasEvent || eventSettingRefreshInProgress;
    }

    function updateEventSettingControls() {
      if (!eventSettingCreateInviteButton) return;
      const selectedEvent = state.events.find(ev => (ev.code || "") === activeEventCode) || null;
      const hasEvent = Boolean(selectedEvent && selectedEvent.code);
      const selectedCode = selectedEvent?.code || "";
      if (selectedCode !== eventSettingActiveEventCode) {
        eventSettingActiveEventCode = selectedCode;
        if (eventSettingStatusText) {
          eventSettingStatusText.removeAttribute("data-state");
        }
      }
      eventSettingCreateInviteButton.disabled = !hasEvent;
      updateEventSettingRefreshState();
      if (!hasEvent) {
        setEventSettingStatusMessage("Select an event to enable invite creation.", "idle");
        return;
      }
      const currentState = eventSettingStatusText?.dataset.state;
      if (currentState === "success" || currentState === "busy" || currentState === "error") {
        updateEventSettingPrintToggleState();
        return;
      }
      setEventSettingStatusMessage(`Ready to create invite page for ${selectedEvent.name || selectedEvent.code}.`, "idle");
      updateEventSettingPrintToggleState();
    }

    async function createEventInvitePage() {
      if (!eventSettingCreateInviteButton || !activeEventCode) {
        showErrorSnackbar?.({ message: "Select an event before creating the invite page." });
        return;
      }
      const button = eventSettingCreateInviteButton;
      button.setAttribute("disabled", "disabled");
      setEventSettingStatusMessage("Creating invite page, please wait...", "busy");
      try {
        const formData = new FormData();
        formData.append("action", "create_event_entrypoints");
        formData.append("event_code", activeEventCode);
        const response = await fetch("./api/guests.php", {
          method: "POST",
          body: formData
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload?.message || "Unable to create invite entry page.");
        }
        setEventSettingStatusMessage(payload.message || `Invite entry created for event ${activeEventCode}.`, "success");
        showDefaultToast?.(payload.message || "Invite entry points created.");
      } catch (error) {
        const message = error?.message || "Failed to create invite entry page.";
        setEventSettingStatusMessage(message, "error");
        showErrorSnackbar?.({ message });
      } finally {
        updateEventSettingControls();
      }
    }

    async function refreshEventPurelist() {
      if (!eventSettingRefreshButton || !activeEventCode) {
        showErrorSnackbar?.({ message: "Select an event before refreshing the SMS export data." });
        return;
      }
      eventSettingRefreshInProgress = true;
      updateEventSettingRefreshState();
      setEventSettingStatusMessage("Refreshing SMS export, please wait...", "busy");
      try {
        const formData = new FormData();
        formData.append("action", "refresh_event_purelist");
        formData.append("event_code", activeEventCode);
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload?.message || "Failed to refresh SMS export.");
        }
        state.events = Array.isArray(payload.events) ? payload.events : state.events;
        refreshEventControls();
        const message = payload.message || `SMS export refreshed for ${activeEventCode}.`;
        setEventSettingStatusMessage(message, "success");
        showDefaultToast?.(message);
      } catch (error) {
        const message = error?.message || "Failed to refresh SMS export.";
        setEventSettingStatusMessage(message, "error");
        showErrorSnackbar?.({ message });
      } finally {
        eventSettingRefreshInProgress = false;
        updateEventSettingRefreshState();
        updateEventSettingControls();
      }
    }

    async function handleEventPrintToggleChange(value) {
      if (!eventSettingPrintToggle) return;
      if (!activeEventCode) {
        eventSettingPrintToggle.checked = !value;
        return;
      }
      eventSettingPrintUpdating = true;
      updateEventSettingPrintToggleState();
      setEventSettingStatusMessage("در حال ذخیره تنظیمات چاپ ورود...", "busy");
      try {
        const formData = new FormData();
        formData.append("action", "update_event_setting");
        formData.append("event_code", activeEventCode);
        formData.append("print_entry_modal", value ? "1" : "0");
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload?.message || "Failed to save print setting.");
        }
        state.events = Array.isArray(payload.events) ? payload.events : state.events;
        refreshEventControls();
        setEventSettingStatusMessage(value ? "چاپ ورود فعال شد." : "چاپ ورود غیرفعال شد.", "success");
      } catch (error) {
        eventSettingPrintToggle.checked = !value;
        const message = error?.message || "Failed to save print setting.";
        setEventSettingStatusMessage(message, "error");
      } finally {
        eventSettingPrintUpdating = false;
        updateEventSettingPrintToggleState();
      }
    }

    function updateEventSettingPrintToggleState() {
      if (!eventSettingPrintToggle) return;
      const selectedEvent = state.events.find(ev => (ev.code || "") === activeEventCode) || null;
      const hasEvent = Boolean(selectedEvent && selectedEvent.code);
      const enabled = hasEvent ? Boolean(selectedEvent.print_entry_modal ?? true) : true;
      eventSettingPrintToggle.checked = enabled;
      eventSettingPrintToggle.disabled = !hasEvent || eventSettingPrintUpdating;
    }

    function getEventInviteUrl(event) {
      const code = (event?.code || "").trim();
      if (!code) {
        return "";
      }
      return `events/${encodeURIComponent(code)}/invite.php`;
    }

    function updateEventInfoInviteButton(selectedEvent) {
      if (!eventInfoInviteButton) return;
      const hasEvent = Boolean(selectedEvent && selectedEvent.code);
      eventInfoInviteButton.disabled = !hasEvent;
    }

    function openEventInvitePage() {
      if (!eventInfoInviteButton) return;
      const selectedEvent = state.events.find(ev => (ev.code || "") === activeEventCode) || null;
      const url = getEventInviteUrl(selectedEvent);
      if (!url) {
        showErrorSnackbar?.({ message: "Select an event to open its invite page." });
        return;
      }
      window.open(url, "_blank");
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

      function describeEventTiming(
        normalizedEventDate,
        normalizedTodayDate,
        joinStart,
        joinLimit,
        joinLeft,
        joinEnd
      ) {
        if (!normalizedEventDate || !normalizedTodayDate) {
          return { label: "غیرفعال", relation: null, state: "" };
        }
        const relation = compareNormalizedShamsiDates(normalizedEventDate, normalizedTodayDate);
        if (relation === 0) {
          const sameDayState = describeSameDayTimeState(joinStart, joinLimit, joinLeft, joinEnd);
          return { ...sameDayState, relation };
        }
        if (relation === 1) {
          const days = daysUntilEvent(normalizedEventDate, normalizedTodayDate);
          if (days === 1) {
            return { label: "شروع رویداد در کمتر از یک روز دیگر", relation, state: "future" };
          }
          const suffix = days !== null && days > 0 ? days : 1;
          return { label: `شروع رویداد در ${suffix} روز دیگر`, relation, state: "future" };
        }
        return { label: "رویداد به پایان رسیده", relation, state: "past" };
      }

    function getCurrentLocalSeconds() {
      const now = new Date();
      return now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
    }

    function describeSameDayTimeState(joinStart, joinLimit, joinLeft, joinEnd) {
      const nowSeconds = getCurrentLocalSeconds();
      const startSeconds = parseTimeToSeconds(joinStart);
      const limitSeconds = parseTimeToSeconds(joinLimit);
      const leftSeconds = parseTimeToSeconds(joinLeft);
      const endSeconds = parseTimeToSeconds(joinEnd);

      if (endSeconds !== null && nowSeconds >= endSeconds) {
        return { state: "after-end", label: "رویداد ساعاتی پیش به پایان رسیده" };
      }
      if (leftSeconds !== null && nowSeconds >= leftSeconds) {
        return { state: "post-limit", label: "رویداد در حال برگزاری" };
      }
      if (limitSeconds !== null && nowSeconds >= limitSeconds) {
        if (leftSeconds !== null) {
          return { state: "running", label: "رویداد در حال برگزاری (خروج مجاز)" };
        }
        return { state: "post-limit", label: "رویداد در حال برگزاری" };
      }
      if (startSeconds !== null && nowSeconds >= startSeconds) {
        return { state: "entry-open", label: "رویداد در حال برگزاری (ورود مجاز)" };
      }
      if (startSeconds !== null && nowSeconds < startSeconds) {
        return { state: "before-start", countdownSeconds: startSeconds - nowSeconds };
      }
      const countdownTarget = limitSeconds ?? leftSeconds ?? endSeconds;
      if (countdownTarget !== null && nowSeconds < countdownTarget) {
        return { state: "before-start", countdownSeconds: countdownTarget - nowSeconds };
      }
      return { state: "entry-open", label: "رویداد در حال برگزاری (ورود مجاز)" };
    }

    function parseTimeToSeconds(value) {
      if (!value) return null;
      const normalized = String(value).trim();
      const match = normalized.match(/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/);
      if (!match) return null;
      const hours = Number(match[1]);
      const minutes = Number(match[2]);
      const seconds = match[3] ? Number(match[3]) : 0;
      return hours * 3600 + minutes * 60 + seconds;
    }

    function formatDurationSeconds(seconds) {
      const total = Math.max(0, Number.isFinite(seconds) ? seconds : 0);
      const hh = String(Math.floor(total / 3600)).padStart(2, "0");
      const mm = String(Math.floor((total % 3600) / 60)).padStart(2, "0");
      const ss = String(total % 60).padStart(2, "0");
      return `${hh}:${mm}:${ss}`;
    }

    function parseNormalizedShamsiParts(value = "") {
      if (!value) return null;
      const parts = value.split("/");
      if (parts.length < 3) return null;
      const year = Number(parts[0]);
      const month = Number(parts[1]);
      const day = Number(parts[2]);
      if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) return null;
      return { year, month, day };
    }

    function jalaliToJdn(jy, jm, jd) {
      if (![jy, jm, jd].every(n => Number.isFinite(n))) return null;
      const epBase = jy - (jy >= 0 ? 474 : 473);
      const epYear = 474 + (epBase % 2820);
      const mdays = jm <= 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186;
      const days =
        jd +
        mdays +
        Math.floor((epYear * 682 - 110) / 2816) +
        (epYear - 1) * 365 +
        Math.floor(epBase / 2820) * 1029983;
      return days + 1948320;
    }

    function daysUntilEvent(normalizedEventDate, normalizedTodayDate) {
      const eventParts = parseNormalizedShamsiParts(normalizedEventDate);
      const todayParts = parseNormalizedShamsiParts(normalizedTodayDate);
      if (!eventParts || !todayParts) return null;
      const eventJdn = jalaliToJdn(eventParts.year, eventParts.month, eventParts.day);
      const todayJdn = jalaliToJdn(todayParts.year, todayParts.month, todayParts.day);
      if (eventJdn === null || todayJdn === null) return null;
      return eventJdn - todayJdn;
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
      } catch (error) {
        return "";
      }
    }

    function resolveTodayShamsiDate() {
      const fromHelper =
        typeof getNowJalaliDate === "function" ? getNowJalaliDate() : "";
      if (fromHelper) {
        return normalizeShamsiDate(fromHelper);
      }
      const fromIntl = formatTodayShamsiWithIntl();
      if (fromIntl) {
        return normalizeShamsiDate(fromIntl);
      }
      return "";
    }

    function clearEventInfoCountdown() {
      if (!eventInfoCountdownTimer) return;
      clearTimeout(eventInfoCountdownTimer);
      eventInfoCountdownTimer = null;
    }

    function scheduleEventInfoCountdown() {
      clearEventInfoCountdown();
      eventInfoCountdownTimer = window.setTimeout(() => {
        updateEventInfoLiveStatus();
      }, 1000);
    }

      function updateEventInfoLiveStatus() {
        if (!eventInfoStatusText) return;
        clearEventInfoCountdown();
        const normalizedEventDate = normalizeShamsiDate(eventInfoDateInput?.value);
        const todayDate = resolveTodayShamsiDate();
        const joinStart = (eventInfoJoinStartInput?.value || "").trim();
        const joinLimit = (eventInfoJoinLimitInput?.value || "").trim();
        const joinLeft = (eventInfoLeftTimeInput?.value || "").trim();
        const joinEnd = (eventInfoJoinEndInput?.value || "").trim();
        const timing = describeEventTiming(
          normalizedEventDate,
          todayDate,
          joinStart,
          joinLimit,
          joinLeft,
          joinEnd
        );
        if (timing.state === "before-start" && Number.isFinite(timing.countdownSeconds) && timing.countdownSeconds > 0) {
          const countdown = formatDurationSeconds(timing.countdownSeconds);
          eventInfoStatusText.textContent = `رویداد در کمتر از ${countdown} شروع می شود`;
          scheduleEventInfoCountdown();
        } else {
          eventInfoStatusText.textContent = timing.label || "وضعیت رویداد نامشخص است";
        }
        if (timing.relation === null) {
          eventInfoStatusText.dataset.timing = "";
        } else if (timing.relation === 0) {
          eventInfoStatusText.dataset.timing = "today";
        } else if (timing.relation === 1) {
          eventInfoStatusText.dataset.timing = "future";
        } else {
          eventInfoStatusText.dataset.timing = "past";
        }
        eventInfoStatusText.dataset.state = timing.state || "";
      }

    function updateManualEventDate() {
      if (!manualEventDateInput) return;
      const selectedCode = manualEventSelect?.value || "";
      const selectedEvent = state.events.find(ev => (ev.code || "") === selectedCode);
      manualEventDateInput.value = (selectedEvent?.date || "").trim();
    }

    function getGuestEntryAndExitParts(guest) {
      const entrySource = guest.join_date
        ? `${guest.join_date} ${guest.join_time || ""}`.trim()
        : guest.date_entered || "";
      const exitSource = guest.left_date
        ? `${guest.left_date} ${guest.left_time || ""}`.trim()
        : guest.date_exited || "";
      return {
        entered: splitDateTime(entrySource),
        exited: splitDateTime(exitSource)
      };
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
        const { entered: enteredParts, exited: exitedParts } = getGuestEntryAndExitParts(guest);
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

    function setManualModalProgressText(message) {
      if (!manualModalProgressMessage) return;
      manualModalProgressMessage.textContent = message || defaultManualModalProgressText;
    }

    function showManualModalProgress(message) {
      if (!manualModalProgress) return;
      setManualModalProgressText(message);
      manualModalProgress.classList.remove("hidden");
    }

    function hideManualModalProgress() {
      if (!manualModalProgress) return;
      manualModalProgress.classList.add("hidden");
      setManualModalProgressText(defaultManualModalProgressText);
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
        applyEventInviteCardTemplate();
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
      applyEventInviteCardTemplate();
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
        requestAnimationFrame(() => {
          hideMappingProgress();
          showModal(mappingModal);
        });
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
      const normalizedNationalId = normalizeNationalIdInput(manualNationalIdInput?.value || "");
      if (!normalizedNationalId) {
        showErrorSnackbar?.({ message: "National ID is required and must contain numbers only." });
        return;
      }
      const existingGuests = Array.isArray(selectedEvent.guests) ? selectedEvent.guests : [];
      const isDuplicateNationalId = existingGuests.some(guest =>
        normalizeNationalIdInput(guest.national_id) === normalizedNationalId
      );
      if (isDuplicateNationalId) {
        showErrorSnackbar?.({ message: "A guest with this national ID already exists for this event." });
        return;
      }
      if (manualNationalIdInput) {
        manualNationalIdInput.value = normalizedNationalId;
      }
      const payload = {
        action: "add_manual_guest",
        event_code: selectedCode,
        event_name: eventName,
        event_date: eventDate,
        firstname: (manualFirstnameInput?.value || "").trim(),
        lastname: (manualLastnameInput?.value || "").trim(),
        gender: manualGenderSelect?.value || "",
        national_id: normalizedNationalId,
        phone_number: (manualPhoneInput?.value || "").trim()
      };
      const submitButton = manualForm.querySelector("button[type='submit']");
      showManualModalProgress("Adding guest...");
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
        activeEventCode = selectedCode;
        refreshEventControls();
        applyEventInviteCardTemplate();
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
        hideManualModalProgress();
      }
    });

    eventInfoForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!activeEventCode) return;
      const name = (eventInfoNameInput?.value || "").trim();
      const date = (eventInfoDateInput?.value || "").trim();
      const joinStart = (eventInfoJoinStartInput?.value || "").trim();
      const joinLimit = (eventInfoJoinLimitInput?.value || "").trim();
      const joinLeft = (eventInfoLeftTimeInput?.value || "").trim();
      const joinEnd = (eventInfoJoinEndInput?.value || "").trim();
      if (!name || !date) {
        showErrorSnackbar?.({ message: "Event name and date are required." });
        return;
      }
      const startMinutes = parseTimeToMinutes(joinStart);
      const limitMinutes = parseTimeToMinutes(joinLimit);
      if (joinStart && startMinutes === null) {
        showErrorSnackbar?.({ message: "Join start time must be a valid 24-hour time." });
        return;
      }
      if (joinLimit && limitMinutes === null) {
        showErrorSnackbar?.({ message: "Join limit time must be a valid 24-hour time." });
        return;
      }
      if (startMinutes !== null && limitMinutes !== null && limitMinutes < startMinutes) {
        showErrorSnackbar?.({ message: "Join limit time cannot be before the start time." });
        return;
      }
      const submitButton = eventInfoSaveButton;
      const leftMinutes = parseTimeToMinutes(joinLeft);
      if (joinLeft && leftMinutes === null) {
        showErrorSnackbar?.({ message: "Event left time must be a valid 24-hour time." });
        return;
      }
      if (limitMinutes !== null && leftMinutes !== null && leftMinutes <= limitMinutes) {
        showErrorSnackbar?.({ message: "Event left time must be after the join limit time." });
        return;
      }
      const endMinutes = parseTimeToMinutes(joinEnd);
      if (joinEnd && endMinutes === null) {
        showErrorSnackbar?.({ message: "Event end time must be a valid 24-hour time." });
        return;
      }
      if (limitMinutes !== null && endMinutes !== null && endMinutes <= limitMinutes) {
        showErrorSnackbar?.({ message: "Event end time must be after the join limit time." });
        return;
      }
      if (leftMinutes !== null && endMinutes !== null && endMinutes <= leftMinutes) {
        showErrorSnackbar?.({ message: "Event left time must be before the end time." });
        return;
      }
      submitButton?.setAttribute("disabled", "disabled");
      try {
        const formData = new FormData();
        formData.append("action", "update_event");
        formData.append("code", activeEventCode);
        formData.append("name", name);
        formData.append("date", date);
        formData.append("join_start_time", joinStart);
        formData.append("join_limit_time", joinLimit);
        formData.append("join_left_time", joinLeft);
        formData.append("join_end_time", joinEnd);
        const response = await fetch("./api/guests.php", { method: "POST", body: formData });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== "ok") {
          throw new Error(data?.message || "Failed to save event.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        refreshEventControls();
        applyEventInviteCardTemplate();
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
      showEditModalProgress("در حال بروزرسانی مهمان...");
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
        closeEditModal();
        showDefaultToast?.(data.message || "Guest updated.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Failed to update guest." });
      } finally {
        submitButton?.removeAttribute("disabled");
        hideEditModalProgress();
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

    function normalizeNationalIdInput(value) {
      return String(value ?? "").replace(/\D/g, "");
    }

    function parseTimeToMinutes(value) {
      if (!value) return null;
      const normalized = String(value).trim();
      const match = normalized.match(/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/);
      if (!match) return null;
      return Number(match[1]) * 60 + Number(match[2]);
    }

    function normalizeTimeOption(value) {
      if (!value) return "";
      const normalized = String(value).trim();
      const match = normalized.match(/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/);
      if (!match) return "";
      const hh = match[1].padStart(2, "0");
      const mm = match[2];
      const ss = match[3];
      return ss ? `${hh}:${mm}:${ss}` : `${hh}:${mm}`;
    }

    function selectTimeValue(value) {
      const normalized = normalizeTimeOption(value);
      return normalized ? normalized.slice(0, 5) : "";
    }

    function ensureSelectHasTime(select, value) {
      if (!select) return;
      const timeValue = normalizeTimeOption(value);
      if (!timeValue) {
        select.value = "";
        return;
      }
      let option = Array.from(select.options).find(opt => opt.value === timeValue);
      if (!option) {
        option = document.createElement("option");
        option.value = timeValue;
        option.textContent = timeValue;
        select.appendChild(option);
      }
      select.value = timeValue;
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

    function arraysMatch(a, b) {
      if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) {
        return false;
      }
      return a.every((value, index) => value === b[index]);
    }

    function escapeCssSelector(value) {
      if (!value) {
        return "";
      }
      if (typeof CSS !== "undefined" && typeof CSS.escape === "function") {
        return CSS.escape(value);
      }
      return value.replace(/(["\\])/g, "\\$1");
    }

    function getSelectedPreviewGender() {
      return (inviteCardGenderSelect?.value ?? "").trim();
    }

    function renderInviteCardGenderDropdown(genders, selected = "", force = false) {
      if (!inviteCardGenderSelect) {
        return;
      }
      const normalizedGenders = Array.from(
        new Set((genders || []).map((gender) => (gender || "").trim()).filter(Boolean))
      );
      if (!force && arraysMatch(normalizedGenders, lastInviteCardGenderOptions)) {
        inviteCardGenderSelect.disabled = normalizedGenders.length === 0;
        if (normalizedGenders.includes(selected)) {
          inviteCardGenderSelect.value = selected;
        } else {
          inviteCardGenderSelect.value = "";
        }
        return;
      }
      lastInviteCardGenderOptions = normalizedGenders;
      inviteCardGenderSelect.innerHTML = "";
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = "Use entered name without prefix";
      inviteCardGenderSelect.appendChild(placeholder);
      normalizedGenders.forEach((gender) => {
        const option = document.createElement("option");
        option.value = gender;
        option.textContent = gender;
        inviteCardGenderSelect.appendChild(option);
      });
      inviteCardGenderSelect.value = normalizedGenders.includes(selected) ? selected : "";
      inviteCardGenderSelect.disabled = normalizedGenders.length === 0;
    }

    function getPrefixStyleStateKey(gender) {
      const normalized = (gender ?? "").trim();
      return normalized ? `prefix-${normalized}` : "prefix";
    }

    function updatePrefixStyleColorPreview(stateKey, color) {
      const normalizedColor = ensureHexColor(color) || "#111111";
      const preview = inviteCardPrefixContainer?.querySelector(
        `[data-prefix-style-color-preview="${stateKey}"]`
      );
      if (preview) {
        preview.style.background = normalizedColor;
      }
      const hexInput = inviteCardPrefixContainer?.querySelector(
        `[data-prefix-style-color-hex="${stateKey}"]`
      );
      if (hexInput) {
        hexInput.value = normalizedColor;
      }
    }

    function initPrefixStyleColorPickers() {
      inviteCardPrefixContainer?.querySelectorAll("[data-prefix-style-color-trigger]").forEach((trigger) => {
        const gender = (trigger.dataset.prefixStyleGender ?? "").trim();
        const stateKey = trigger.dataset.prefixStyleColorField;
        if (!stateKey) {
          return;
        }
        const applyColor = (nextColor) => {
          prefixStyleColorState[stateKey] = ensureHexColor(nextColor) || "#111111";
          updatePrefixStyleColorPreview(stateKey, prefixStyleColorState[stateKey]);
        };
        applyColor(prefixStyleColorState[stateKey] ?? "#111111");
        trigger.addEventListener("click", () => {
          openStyleColorPicker(
            stateKey,
            prefixStyleColorState[stateKey],
            (nextColor) => applyColor(nextColor),
            `Font color (prefix for ${gender || "Guest"})`
          );
        });
      });
    }

    function getNameFieldFontSizeValue() {
      const nameBlock = findInviteCardFieldBlock("name");
      return nameBlock?.querySelector("[data-field-size]")?.value ?? "";
    }

    function syncPrefixFontSizes() {
      if (!inviteCardPrefixContainer) {
        return;
      }
      const sizeValue = getNameFieldFontSizeValue();
      inviteCardPrefixContainer.querySelectorAll("[data-prefix-style-size]").forEach((input) => {
        input.value = sizeValue;
      });
    }

    function renderInviteCardPrefixInputs(genders, prefixes = {}, styles = {}, force = false) {
      if (!inviteCardPrefixContainer) {
        return;
      }
      const normalizedGenders = Array.from(
        new Set((genders || []).map((gender) => (gender || "").trim()).filter(Boolean))
      );
      if (!force && arraysMatch(normalizedGenders, lastInviteCardPrefixGenders)) {
        if (!normalizedGenders.length) {
          inviteCardPrefixPlaceholder?.classList.remove("hidden");
        } else {
          inviteCardPrefixPlaceholder?.classList.add("hidden");
        }
        return;
      }
      lastInviteCardPrefixGenders = normalizedGenders;
      inviteCardPrefixContainer.innerHTML = "";
      Object.keys(prefixStyleColorState).forEach((key) => delete prefixStyleColorState[key]);
      if (!normalizedGenders.length) {
        inviteCardPrefixPlaceholder?.classList.remove("hidden");
        return;
      }
      inviteCardPrefixPlaceholder?.classList.add("hidden");
      const normalizedPrefixes = {};
      Object.entries(prefixes || {}).forEach(([rawGender, value]) => {
        const normalizedGender = (rawGender ?? "").trim();
        if (!normalizedGender) return;
        normalizedPrefixes[normalizedGender] = value ?? "";
      });
      const normalizedStyles = {};
      Object.entries(styles || {}).forEach(([rawGender, value]) => {
        const normalizedGender = (rawGender ?? "").trim();
        if (!normalizedGender) return;
        normalizedStyles[normalizedGender] = value || {};
      });
      normalizedGenders.forEach((gender) => {
        const prefixValue = normalizedPrefixes[gender] ?? "";
        const styleValue = normalizedStyles[gender] ?? {};
        const fontFamily = styleValue.fontFamily || prefixStyleFontOptions[0];
        const fontWeight = styleValue.fontWeight || prefixStyleWeightOptions[0];
        const fontSizeRaw =
          typeof styleValue.fontSize !== "undefined"
            ? styleValue.fontSize
            : styleValue.font_size;
        const fontSizeValue =
          fontSizeRaw !== null && typeof fontSizeRaw !== "undefined" ? String(fontSizeRaw) : "";
        const colorValue = ensureHexColor(styleValue.color) || "#111111";
        const stateKey = getPrefixStyleStateKey(gender);
        const row = document.createElement("div");
        row.className = "invite-card-prefix-row";

        const label = document.createElement("label");
        label.className = "field standard-width invite-card-prefix-input";
        const span = document.createElement("span");
        span.textContent = `Prefix for ${gender}`;
        const input = document.createElement("input");
        input.type = "text";
        input.dataset.prefixInput = "";
        input.dataset.prefixGender = gender;
        input.value = prefixValue;
        label.appendChild(span);
        label.appendChild(input);
        row.appendChild(label);

        const styleController = document.createElement("div");
        styleController.className = "style-controller invite-card-prefix-style";
        styleController.dataset.prefixStyleController = gender;
        styleController.dataset.prefixStyleGender = gender;

        const fontLabel = document.createElement("label");
        fontLabel.className = "field standard-width";
        const fontSpan = document.createElement("span");
        fontSpan.textContent = "Font";
        const fontSelect = document.createElement("select");
        fontSelect.dataset.prefixStyleFont = "";
        prefixStyleFontOptions.forEach((optionValue) => {
          const option = document.createElement("option");
          option.value = optionValue;
          option.textContent = optionValue;
          if (optionValue === fontFamily) {
            option.selected = true;
          }
          fontSelect.appendChild(option);
        });
        fontLabel.appendChild(fontSpan);
        fontLabel.appendChild(fontSelect);

        const weightLabel = document.createElement("label");
        weightLabel.className = "field standard-width";
        const weightSpan = document.createElement("span");
        weightSpan.textContent = "Font weight";
        const weightSelect = document.createElement("select");
        weightSelect.dataset.prefixStyleWeight = "";
        prefixStyleWeightOptions.forEach((optionValue) => {
          const option = document.createElement("option");
          option.value = optionValue;
          option.textContent = optionValue;
          if (optionValue === fontWeight) {
            option.selected = true;
          }
          weightSelect.appendChild(option);
        });
        weightLabel.appendChild(weightSpan);
        weightLabel.appendChild(weightSelect);

        const sizeLabel = document.createElement("label");
        sizeLabel.className = "field standard-width";
        const sizeSpan = document.createElement("span");
        sizeSpan.textContent = "Font size (px)";
        const sizeInput = document.createElement("input");
        sizeInput.type = "number";
        sizeInput.min = "8";
        sizeInput.disabled = true;
        sizeInput.dataset.prefixStyleSize = "";
        sizeInput.value = fontSizeValue;
        sizeLabel.appendChild(sizeSpan);
        sizeLabel.appendChild(sizeInput);

        const colorLabel = document.createElement("label");
        colorLabel.className = "field standard-width color-picker-field";
        const colorSpan = document.createElement("span");
        colorSpan.textContent = "Font color";
        const colorGroup = document.createElement("div");
        colorGroup.className = "appearance-input-group invite-color-field";
        const colorHex = document.createElement("input");
        colorHex.type = "text";
        colorHex.className = "appearance-hex-field";
        colorHex.readOnly = true;
        colorHex.dataset.prefixStyleColorHex = stateKey;
        const colorButton = document.createElement("button");
        colorButton.type = "button";
        colorButton.className = "appearance-preview color-picker-trigger";
        colorButton.dataset.prefixStyleColorTrigger = "";
        colorButton.dataset.prefixStyleColorField = stateKey;
        colorButton.dataset.prefixStyleColorPreview = stateKey;
        colorButton.dataset.prefixStyleGender = gender;
        colorButton.setAttribute("aria-label", `Select color for prefix of ${gender}`);
        colorGroup.appendChild(colorHex);
        colorGroup.appendChild(colorButton);
        colorLabel.appendChild(colorSpan);
        colorLabel.appendChild(colorGroup);

        styleController.appendChild(fontLabel);
        styleController.appendChild(weightLabel);
        styleController.appendChild(sizeLabel);
        styleController.appendChild(colorLabel);
        row.appendChild(styleController);

        inviteCardPrefixContainer.appendChild(row);
        prefixStyleColorState[stateKey] = colorValue;
        updatePrefixStyleColorPreview(stateKey, colorValue);

        input.addEventListener("input", () => {
          refreshInviteCardActionState?.();
        });
      });
      initPrefixStyleColorPickers();
      syncPrefixFontSizes();
    }

    function readInviteCardGenderPrefixes() {
      const result = {};
      if (!inviteCardPrefixContainer) {
        return result;
      }
      inviteCardPrefixContainer.querySelectorAll("[data-prefix-input]").forEach((input) => {
        const gender = (input.dataset.prefixGender ?? "").trim();
        if (!gender) {
          return;
        }
        result[gender] = (input.value ?? "").trim();
      });
      return result;
    }

    function readInviteCardPrefixStyles() {
      const result = {};
      if (!inviteCardPrefixContainer) {
        return result;
      }
      inviteCardPrefixContainer.querySelectorAll("[data-prefix-style-controller]").forEach((controller) => {
        const gender = (controller.dataset.prefixStyleGender ?? "").trim();
        if (!gender) {
          return;
        }
        const fontFamily = controller.querySelector("[data-prefix-style-font]")?.value || prefixStyleFontOptions[0];
        const fontWeight = controller.querySelector("[data-prefix-style-weight]")?.value || prefixStyleWeightOptions[0];
        const sizeValue = controller.querySelector("[data-prefix-style-size]")?.value ?? "";
        const parsedSize = Number.parseFloat(sizeValue);
        const colorValue = controller.querySelector("[data-prefix-style-color-hex]")?.value ?? "";
        result[gender] = {
          fontFamily,
          fontWeight,
          fontSize: Number.isFinite(parsedSize) ? parsedSize : null,
          color: ensureHexColor(colorValue) || "#111111"
        };
      });
      return result;
    }

    function getNamePrefixForGender(gender) {
      if (!gender || !inviteCardPrefixContainer) {
        return "";
      }
      const normalizedGender = gender.trim();
      if (!normalizedGender) {
        return "";
      }
      const selector = `[data-prefix-input][data-prefix-gender="${escapeCssSelector(normalizedGender)}"]`;
      const input = inviteCardPrefixContainer.querySelector(selector);
      return (input?.value ?? "").trim();
    }

    function prepareInviteCardGenderControls(template = null, force = false) {
      const genders = getAvailableGenders();
      const prefixes = template?.gender_prefixes ?? {};
      const prefixStyles = template?.gender_prefix_styles ?? {};
      const previewGender = (template?.preview_gender ?? "") || "";
      renderInviteCardGenderDropdown(genders, previewGender, force);
      renderInviteCardPrefixInputs(genders, prefixes, prefixStyles, force);
    }

    if (typeof window === "object" && window) {
      window.getActiveGuestEvent = getActiveGuestEvent;
      window.getNamePrefixForGender = getNamePrefixForGender;
      window.getSelectedPreviewGender = getSelectedPreviewGender;
      window.readInviteCardGenderPrefixes = readInviteCardGenderPrefixes;
      window.readInviteCardPrefixStyles = readInviteCardPrefixStyles;
      window.syncPrefixFontSizes = syncPrefixFontSizes;
    }

    function buildQuarterHourOptions(select) {
      if (!select) return;
      select.innerHTML = "";
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = "--:--";
      select.appendChild(placeholder);
      for (let h = 0; h < 24; h++) {
        for (let m = 0; m < 60; m += 15) {
          const hh = String(h).padStart(2, "0");
          const mm = String(m).padStart(2, "0");
          const option = document.createElement("option");
          option.value = `${hh}:${mm}`;
          option.textContent = `${hh}:${mm}`;
          select.appendChild(option);
        }
      }
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
      applyEventInviteCardTemplate();
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

    const PRESENT_GUEST_EXPORT_HEADERS = [
      "No.",
      "Event",
      "Event date",
      "First name",
      "Last name",
      "Gender",
      "National ID",
      "Phone number",
      "Join date",
      "Join time",
      "Left date",
      "Left time"
    ];

    function sanitizeFileName(value) {
      if (!value) return "";
      return String(value)
        .trim()
        .replace(/[<>:"/\\|?*\x00-\x1F]/g, "-")
        .replace(/\s+/g, " ")
        .replace(/-+/g, "-")
        .replace(/^-+|-+$/g, "");
    }

    function getPresentGuestExportRows(event) {
      const rows = [];
      const guests = Array.isArray(event?.guests) ? event.guests : [];
      guests.forEach((guest, index) => {
        const { entered, exited } = getGuestEntryAndExitParts(guest);
        if (!entered.date || !entered.time || !exited.date || !exited.time) {
          return;
        }
        rows.push({
          "No.": guest.number || index + 1,
          Event: event.name || "",
          "Event date": event.date || "",
          "First name": guest.firstname || "",
          "Last name": guest.lastname || "",
          Gender: guest.gender || "",
          "National ID": guest.national_id || "",
          "Phone number": guest.phone_number || "",
          "Join date": entered.date,
          "Join time": entered.time,
          "Left date": exited.date,
          "Left time": exited.time
        });
      });
      return { headers: PRESENT_GUEST_EXPORT_HEADERS, rows };
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
      const activeEvent = getActiveGuestEvent();
      if (!activeEvent) {
        throw new Error("Select an event before exporting present guests.");
      }
      const { rows, headers } = getPresentGuestExportRows(activeEvent);
      if (!rows.length) {
        throw new Error("No guests have both entry and exit timestamps yet.");
      }
      const workbook = buildPresentGuestsWorkbook(rows, headers);
      const arrayBuffer = XLSX.write(workbook, { bookType: "xlsx", type: "array" });
      const blob = new Blob([arrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      const sanitizedName = sanitizeFileName(activeEvent.name || "");
      const fallbackName = sanitizeFileName(activeEvent.code || "") || "present-guest-list";
      const fileNameBase = sanitizedName || fallbackName;
      anchor.download = `${fileNameBase}-present-guest-list.xlsx`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
    }

    function buildInviteLink(code) {
      const raw = String(code ?? "").trim();
      if (!raw) {
        return "";
      }
      if (/^https?:\/\//i.test(raw)) {
        return raw;
      }
      const cleaned = raw.replace(/^\/+|\/+$/g, "");
      return `${INVITE_BASE_URL}/${cleaned}`;
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
        throw new Error("No guests contain enough data to export SMS links.");
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

      function getEventLabelForExport(event) {
        if (!event) return "";
        return (
          event.name ||
          event.event_name ||
          event.title ||
          event.slug ||
          event.code ||
          ""
        );
      }

      function sanitizeFilenameSegment(value) {
        const normalized = String(value ?? "").trim();
        if (!normalized) return "";
        return normalized
          .replace(/[<>:"/\\|?*\u0000-\u001F]/g, "-")
          .replace(/\s+/g, "-")
          .replace(/-+/g, "-")
          .replace(/^-+|-+$/g, "");
      }

      function buildSmsExportFilename(event) {
        const label = sanitizeFilenameSegment(getEventLabelForExport(event));
        const segments = ["sms-link-list"];
        if (label) {
          segments.push(label);
        }
        return `${segments.join("-")}.xlsx`;
      }

      async function exportSmsLinks() {
      const activeEvent = getActiveGuestEvent();
      if (!activeEvent) {
        throw new Error("Select an event before exporting SMS links.");
      }
      const guests = Array.isArray(activeEvent.guests) ? activeEvent.guests : [];
      if (!guests.length) {
        throw new Error("No guests have been added to this event yet.");
      }
      const rows = guests.map(guest => {
        const firstname = String(guest.firstname || guest.first_name || "").trim();
        const lastname = String(guest.lastname || guest.last_name || "").trim();
        const nationalId = String(guest.national_id || guest.nationalid || "").trim();
        const phone = String(guest.phone_number || guest.phone || "").trim();
        const inviteCode = String(guest.invite_code || guest.code || guest.number || "").trim();
        const providedLink = String(guest.sms_link || guest.link || guest.smsLink || "").trim();
        const link = providedLink || buildInviteLink(inviteCode);
        return {
          number: inviteCode || String(guest.number ?? ""),
          firstname,
          lastname,
          national_id: nationalId,
          phone_number: phone,
          sms_link: link
        };
      });
      const workbook = buildSmsWorkbook(rows);
      const arrayBuffer = XLSX.write(workbook, { bookType: "xlsx", type: "array" });
      const blob = new Blob([arrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
        anchor.href = url;
        anchor.download = buildSmsExportFilename(activeEvent);
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
        const sectionKeys = (section.dataset.eventSection || "").split(/\s+/).filter(Boolean);
        const matches = sectionKeys.includes(sectionKey);
        section.classList.toggle("hidden", !matches);
      });
      eventSectionTabs?.querySelectorAll("[data-event-section-target]").forEach(tab => {
        const isSelected = tab.dataset.eventSectionTarget === sectionKey;
        tab.classList.toggle("active", isSelected);
        tab.setAttribute("aria-selected", isSelected ? "true" : "false");
      });
    }

    function getInviteCardFieldBlocks() {
      const container = document.getElementById("tab-invite-card");
      if (!container) {
        return [];
      }
      return $qsa("[data-field-block]", container);
    }

    function findInviteCardFieldBlock(fieldId) {
      if (!fieldId) {
        return null;
      }
      const normalized = String(fieldId).trim();
      return getInviteCardFieldBlocks().find(
        (block) => (block.dataset.fieldBlock || "").trim() === normalized
      ) || null;
    }

    function cacheInviteCardFieldDefaults() {
      const colorState = typeof styleFieldColorState === "object" ? styleFieldColorState : null;
      getInviteCardFieldBlocks().forEach((block) => {
        const fieldId = (block.dataset.fieldBlock || "").trim();
        if (!fieldId) {
          return;
        }
        inviteCardFieldDefaults[fieldId] = {
          value: block.querySelector("[data-field-value]")?.value || "",
          type: block.querySelector("[data-field-type]")?.value || "",
          alignment: block.querySelector("[data-field-alignment]")?.value || "",
          fontFamily: block.querySelector("[data-field-font]")?.value || "",
          fontWeight: block.querySelector("[data-field-weight]")?.value || "",
          fontSize: block.querySelector("[data-field-size]")?.value || "",
          x: block.querySelector('input[data-field-coordinate="x"]')?.value || "",
          y: block.querySelector('input[data-field-coordinate="y"]')?.value || "",
          scale: block.querySelector("[data-scale-input]")?.value || "",
          color: ensureHexColor(colorState?.[getStyleFieldKey(fieldId)] ?? "") || "#111111"
        };
      });
    }

    function resetEventInviteCardFields() {
      const colorState = typeof styleFieldColorState === "object" ? styleFieldColorState : null;
      getInviteCardFieldBlocks().forEach((block) => {
        const fieldId = (block.dataset.fieldBlock || "").trim();
        const defaults = inviteCardFieldDefaults[fieldId] || {};
        const valueInput = block.querySelector("[data-field-value]");
        if (valueInput) {
          valueInput.value = defaults.value || "";
        }
        const typeSelect = block.querySelector("[data-field-type]");
        if (typeSelect) {
          typeSelect.value = defaults.type || typeSelect.options[0]?.value || "text";
        }
        const alignmentSelect = block.querySelector("[data-field-alignment]");
        if (alignmentSelect) {
          alignmentSelect.value = defaults.alignment || alignmentSelect.options[0]?.value || "";
        }
        const fontSelect = block.querySelector("[data-field-font]");
        if (fontSelect) {
          fontSelect.value = defaults.fontFamily || fontSelect.options[0]?.value || "";
        }
        const weightSelect = block.querySelector("[data-field-weight]");
        if (weightSelect) {
          weightSelect.value = defaults.fontWeight || weightSelect.options[0]?.value || "";
        }
        const sizeInput = block.querySelector("[data-field-size]");
        if (sizeInput) {
          sizeInput.value = defaults.fontSize || "";
        }
        const xInput = block.querySelector('input[data-field-coordinate="x"]');
        if (xInput) {
          xInput.value = defaults.x || "";
        }
        const yInput = block.querySelector('input[data-field-coordinate="y"]');
        if (yInput) {
          yInput.value = defaults.y || "";
        }
        const scaleInput = block.querySelector("[data-scale-input]");
        if (scaleInput) {
          scaleInput.value = defaults.scale || "";
        }
        const colorKey = getStyleFieldKey(fieldId);
        const colorValue = defaults.color || "#111111";
        if (colorState) {
          colorState[colorKey] = colorValue;
        }
        updateStyleColorPreview?.(fieldId, colorValue);
      updateFieldStyleState?.(block);
    });
    inviteCardSelectedPhoto = null;
    updateInviteCardPhotoPreview?.();
    resetInviteCardPreviewState?.();
    syncPrefixFontSizes();
    refreshInviteCardActionState?.();
    }

    function applyTemplateField(field) {
      const colorState = typeof styleFieldColorState === "object" ? styleFieldColorState : null;
      const fieldId = (field?.id ?? "").toString().trim();
      if (!fieldId) {
        return;
      }
      const block = findInviteCardFieldBlock(fieldId);
      if (!block) {
        return;
      }
      const valueInput = block.querySelector("[data-field-value]");
      if (valueInput && typeof field.value === "string") {
        valueInput.value = field.value;
      }
      const typeSelect = block.querySelector("[data-field-type]");
      if (typeSelect && field.type) {
        typeSelect.value = field.type;
      }
      const alignmentSelect = block.querySelector("[data-field-alignment]");
      if (alignmentSelect && field.alignment) {
        alignmentSelect.value = field.alignment;
      }
      const fontSelect = block.querySelector("[data-field-font]");
      if (fontSelect && field.fontFamily) {
        fontSelect.value = field.fontFamily;
      }
      const weightSelect = block.querySelector("[data-field-weight]");
      if (weightSelect && field.fontWeight) {
        weightSelect.value = field.fontWeight;
      }
      const sizeInput = block.querySelector("[data-field-size]");
      if (sizeInput && field.fontSize) {
        sizeInput.value = String(field.fontSize);
      }
      const xInput = block.querySelector('input[data-field-coordinate="x"]');
      if (xInput && typeof field.x !== "undefined") {
        xInput.value = String(field.x);
      }
      const yInput = block.querySelector('input[data-field-coordinate="y"]');
      if (yInput && typeof field.y !== "undefined") {
        yInput.value = String(field.y);
      }
      const scaleInput = block.querySelector("[data-scale-input]");
      if (scaleInput && typeof field.scale !== "undefined") {
        scaleInput.value = String(field.scale);
      }
      const colorKey = getStyleFieldKey(fieldId);
      const colorValue = ensureHexColor(field.color) || "#111111";
      if (colorState) {
        colorState[colorKey] = colorValue;
      }
      updateStyleColorPreview?.(fieldId, colorValue);
      updateFieldStyleState?.(block);
    }

    function applyEventInviteCardTemplate() {
      const events = Array.isArray(state.events) ? state.events : [];
      if (!activeEventCode) {
        resetEventInviteCardFields();
        return;
      }
      const selectedEvent = events.find(ev => (ev.code || "") === activeEventCode);
      const template = selectedEvent?.invite_card_template;
      const templateSignature = template ? JSON.stringify(template) : "";
      const templateChanged = templateSignature !== lastInviteCardTemplateSignature;
      prepareInviteCardGenderControls(template, templateChanged);
      lastInviteCardTemplateSignature = templateSignature;
      if (!template || !Array.isArray(template.fields) || !template.fields.length) {
        resetEventInviteCardFields();
        return;
      }
      const cachedPhoto =
        template.photo_id && template.photo_id !== ""
          ? (window.GALLERY_PHOTOS || []).find(
              photo => String(photo.id) === String(template.photo_id)
            ) || null
          : null;
      if (cachedPhoto) {
        inviteCardSelectedPhoto = cachedPhoto;
      } else {
        const photoSource =
          (template.photo_path || "").trim() ||
          (template.photo_filename || "").trim();
        if (photoSource) {
          inviteCardSelectedPhoto = {
            id: template.photo_id ? Number(template.photo_id) : null,
            filename: photoSource,
            title: template.photo_title || "",
            altText: template.photo_alt || ""
          };
        } else {
          inviteCardSelectedPhoto = null;
        }
      }
      updateInviteCardPhotoPreview?.();
      template.fields.forEach(field => applyTemplateField(field));
      syncPrefixFontSizes();
      refreshInviteCardActionState?.();
    }

    async function persistEventInviteCardTemplate(payload) {
      if (!activeEventCode) {
        showErrorSnackbar?.({ message: "Select an event before saving the invite card template." });
        return;
      }
      if (!payload || typeof payload !== "object") {
        return;
      }
      const formData = new FormData();
      formData.append("action", "save_invite_card_template");
      formData.append("event_code", activeEventCode);
      formData.append("template", JSON.stringify(payload));
      try {
        const response = await fetch("./api/guests.php", {
          method: "POST",
          body: formData
        });
        const data = await response.json();
        if (!response.ok || data.status !== "ok") {
          throw new Error(data.message || "Unable to save invite card template.");
        }
        state.events = Array.isArray(data.events) ? data.events : state.events;
        refreshEventControls();
        applyEventInviteCardTemplate();
        showDefaultToast?.("Invite card template saved.");
      } catch (error) {
        showErrorSnackbar?.({ message: error?.message || "Unable to save invite card template." });
      }
    }

    window.saveEventInviteCardTemplate = function(templatePayload) {
      void persistEventInviteCardTemplate(templatePayload);
    };

    function resolvePureListCsvPath() {
      if (!state.events.length) {
        return PURE_LIST_CSV_PATH;
      }
      const code = activeEventCode || state.events[0]?.code || "";
      const activeEvent = code
        ? state.events.find(ev => (ev.code || "") === code)
        : null;
      const path =
        activeEvent && typeof activeEvent.purelist === "string"
          ? activeEvent.purelist.trim()
          : "";
      return path || PURE_LIST_CSV_PATH;
    }

    async function loadPureListWorkbook(path = PURE_LIST_CSV_PATH) {
      const response = await fetch(path, { cache: "no-store" });
      if (!response.ok) {
        throw new Error("Unable to retrieve the guest list.");
      }
      const raw = await response.text();
      if (!raw.trim()) {
        throw new Error("The guest list file is empty.");
      }
      if (typeof XLSX === "undefined") {
        throw new Error("Guest list parser is unavailable.");
      }
      const workbook = XLSX.read(raw, { type: "string", raw: false });
      const sheetName = workbook.SheetNames[0];
      if (!sheetName) {
        throw new Error("The guest list file contains no sheets.");
      }
      return { workbook, sheetName };
    }

    async function loadPureListCsvRows(path = PURE_LIST_CSV_PATH) {
      const { workbook, sheetName } = await loadPureListWorkbook(path);
      const sheet = workbook.Sheets[sheetName];
      if (!sheet) {
        throw new Error("Unable to read the guest list sheet.");
      }
      return XLSX.utils.sheet_to_json(sheet, { defval: "" });
    }

    function normalizePureListRow(row) {
      const normalized = {};
      if (!row || typeof row !== "object") {
        return normalized;
      }
      Object.entries(row).forEach(([key, value]) => {
        const headerKey = normalizeHeaderKey(key);
        const rawValue = value ?? "";
        normalized[headerKey] =
          rawValue === null || rawValue === undefined ? "" : String(rawValue).trim();
      });
      return normalized;
    }

    function matchesGuestCode(normalizedRow, searchValue, numericSearch) {
      if (!normalizedRow || !searchValue) {
        return false;
      }
      const candidateKeys = [
        "number",
        "code",
        "guestcode",
        "guest_code",
        "invitecode",
        "invite_code",
        "unique_code"
      ];
      const loweredSearch = searchValue.toLowerCase();
      for (const key of candidateKeys) {
        const candidateValue = (normalizedRow[key] ?? "").toLowerCase();
        if (candidateValue && candidateValue === loweredSearch) {
          return true;
        }
      }
      if (numericSearch !== null && Number.isFinite(numericSearch)) {
        const rowNumber = Number.parseInt(normalizedRow.number || "", 10);
        if (!Number.isNaN(rowNumber) && rowNumber === numericSearch) {
          return true;
        }
      }
      return false;
    }

    async function fetchGuestInviteCardRow(code) {
      const trimmedCode = (code ?? "").toString().trim();
      if (!trimmedCode) {
        throw new Error("Guest code is required.");
      }
      const normalizedSearch = trimmedCode.toLowerCase();
      const numericCandidate = Number.parseInt(normalizedSearch, 10);
      const numericSearch = Number.isFinite(numericCandidate) ? numericCandidate : null;
      const rows = await loadPureListCsvRows(resolvePureListCsvPath());
      if (!Array.isArray(rows) || !rows.length) {
        throw new Error("The purelist file is empty.");
      }
      const matched = rows
        .map((row) => ({
          normalized: normalizePureListRow(row),
          source: row || {}
        }))
        .find(({ normalized }) =>
          matchesGuestCode(normalized, normalizedSearch, numericSearch)
        );
        if (!matched) {
          throw new Error(`Guest with code "${trimmedCode}" not found.`);
        }
      const normalizedRow = matched.normalized;
      return {
        number: normalizedRow.number || "",
        inviteCode: normalizedRow.invitecode || normalizedRow.code || normalizedRow.number || "",
        firstname: normalizedRow.firstname || "",
        lastname: normalizedRow.lastname || "",
        gender: normalizedRow.gender || "",
        nationalId: normalizedRow.nationalid || "",
        phoneNumber: normalizedRow.phonenumber || "",
        smsLink: normalizedRow.smslink || "",
        joinDate: normalizedRow.joindate || normalizedRow.dateentered || "",
        joinTime: normalizedRow.jointime || "",
        dateEntered: normalizedRow.dateentered || "",
        dateExited: normalizedRow.dateexited || "",
        raw: matched.source
      };
    }

    window.fetchGuestInviteCardRow = fetchGuestInviteCardRow;

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
      eventInfoDateInput?.addEventListener("input", updateEventInfoLiveStatus);
      eventInfoDateInput?.addEventListener("change", updateEventInfoLiveStatus);
      [eventInfoJoinStartInput, eventInfoJoinLimitInput, eventInfoLeftTimeInput, eventInfoJoinEndInput].forEach(input => {
        input?.addEventListener("input", updateEventInfoLiveStatus);
        input?.addEventListener("change", updateEventInfoLiveStatus);
      });
    eventSettingCreateInviteButton?.addEventListener("click", () => createEventInvitePage());
    eventSettingRefreshButton?.addEventListener("click", () => refreshEventPurelist());
    eventSettingPrintToggle?.addEventListener("change", () => handleEventPrintToggleChange(eventSettingPrintToggle.checked));
      eventInfoInviteButton?.addEventListener("click", () => openEventInvitePage());

      manualEventSelect?.addEventListener("change", updateManualEventDate);

      editDateEnteredInput?.addEventListener("focus", openJalaliPicker);
      editDateEnteredInput?.addEventListener("click", openJalaliPicker);
      editDateEnteredInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      editDateExitedInput?.addEventListener("focus", openJalaliPicker);
      editDateExitedInput?.addEventListener("click", openJalaliPicker);
      editDateExitedInput?.addEventListener("keydown", (evt) => openJalaliPicker(evt));

      manualEventPaneAddButton?.addEventListener("click", () => {
        if (!activeEventCode) {
          showErrorSnackbar?.({ message: "Please select an event before adding a guest manually." });
          return;
        }
        openManualModal({ forceEventCode: activeEventCode, lockEventSelection: true });
      });

      eventPotOpenButton?.addEventListener("click", () => {
        const drawUrl = buildEventPotDrawUrl();
        if (!drawUrl) {
          showErrorSnackbar?.({ message: "Please select an event before opening the Event Pot." });
          return;
        }
        window.open(drawUrl, "_blank");
      });
      copyEventPotLinkButton?.addEventListener("click", () => {
        const drawUrl = buildEventPotDrawUrl();
        if (!drawUrl) {
          showErrorSnackbar?.({ message: "Please select an event before copying the Event Pot link." });
          return;
        }
        copyTextToClipboard(drawUrl)
          .then(() => {
            showDefaultToast?.("Event Pot link copied to clipboard.");
          })
          .catch((error) => {
            showErrorSnackbar?.({ message: error?.message || "Failed to copy link." });
          });
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
      buildQuarterHourOptions(eventInfoJoinStartInput);
      buildQuarterHourOptions(eventInfoJoinLimitInput);
      buildQuarterHourOptions(eventInfoLeftTimeInput);
      buildQuarterHourOptions(eventInfoJoinEndInput);

      editNowButton?.addEventListener("click", () => {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, "0");
        const min = String(now.getMinutes()).padStart(2, "0");
        const sec = String(now.getSeconds()).padStart(2, "0");
        editDateEnteredInput.value = getNowJalaliDate();
        ensureSelectHasTime(editTimeEnteredInput, `${hh}:${min}:${sec}`);
      });

      editNowExitButton?.addEventListener("click", () => {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, "0");
        const min = String(now.getMinutes()).padStart(2, "0");
        const sec = String(now.getSeconds()).padStart(2, "0");
        editDateExitedInput.value = getNowJalaliDate();
        ensureSelectHasTime(editTimeExitedInput, `${hh}:${min}:${sec}`);
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
        if (!button || button.disabled) return;
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
      cacheInviteCardFieldDefaults();

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
