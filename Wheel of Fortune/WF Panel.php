<style>
  .wf-switch-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }
  .wf-datetime-grid {
    gap: 8px;
    justify-items: end;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    grid-template-areas:
      "start-title start-title empty"
      "start start-time empty"
      "end-title end-title empty"
      "end end-time empty";
  }
  .wf-datetime-title {
    grid-column: 1 / span 2;
    justify-self: end;
    font-weight: 600;
    color: var(--muted);
    text-align: right;
    width: 100%;
  }
  .wf-datetime-title--start {
    grid-area: start-title;
  }
  .wf-datetime-title--end {
    grid-area: end-title;
  }
  .wf-datetime-start {
    grid-area: start;
  }
  .wf-datetime-start-time {
    grid-area: start-time;
  }
  .wf-datetime-end {
    grid-area: end;
  }
  .wf-datetime-end-time {
    grid-area: end-time;
  }
  .wf-datetime-empty {
    grid-area: empty;
  }
  .wf-prize-grid {
    gap: 8px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .wf-prize-grid .field.standard-width {
    width: 100%;
    margin-left: 0;
  }
  .wf-form-action {
    display: flex;
    justify-content: flex-end;
  }
  .wf-standard-third {
    width: min(140px, 100%);
    margin-left: auto;
    margin-right: 0;
  }
  .standard-primary-button {
    width: min(420px, 100%);
    margin-left: auto;
    margin-right: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
  .wf-action-bar {
    justify-content: center;
  }
  .wf-switch {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    min-height: 52px;
  }
  .wf-switch .switch-label {
    color: var(--muted);
  }
  .wf-switch .switch-track {
    background: transparent;
    border: 2px solid var(--border);
    inset: 2px;
    border-radius: 999px;
  }
  .wf-switch .switch-thumb {
    width: 24px;
    height: 24px;
    left: 2px;
    top: 50%;
    transform: translate(0, -50%);
    background: #fff;
    border: 2px solid var(--border);
    box-shadow: none;
  }
  .wf-switch .switch-toggle input:checked + .switch-track {
    border-color: var(--primary);
  }
  .wf-switch .switch-toggle input:checked + .switch-track .switch-thumb {
    transform: translate(26px, -50%);
    border-color: var(--primary);
  }
  .wf-switch .switch-toggle input:disabled + .switch-track {
    border-color: #e2e8f0;
  }
  .wf-switch .switch-toggle input:disabled + .switch-track .switch-thumb {
    border-color: #e2e8f0;
    background: #f8fafc;
  }
  .wf-action-bar {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }
  .wf-btn-danger {
    background: #e11d2e;
    border-color: #e11d2e;
    color: #fff;
  }
  .wf-btn-danger:hover {
    background: #b91c1c;
    border-color: #b91c1c;
  }
  .wf-count-control {
    display: flex;
    gap: 8px;
    justify-content: center;
  }
  .wf-count-control .btn {
    min-width: 56px;
  }
  .wf-btn-count-add {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
  }
  .wf-btn-count-add:hover {
    background: #15803d;
    border-color: #15803d;
  }
  .wf-btn-count-sub {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
  }
  .wf-btn-count-sub:hover {
    background: #0284c7;
    border-color: #0284c7;
  }
  .wf-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--muted);
    font-weight: 600;
    font-size: 13px;
  }
  .wf-status-last {
    color: var(--primary);
  }
  .wf-status-divider {
    color: var(--muted);
  }
  .wf-status-qty {
    color: var(--muted);
  }
  .wf-prize-disabled {
    opacity: 0.65;
  }
  .wf-prize-disabled input,
  .wf-prize-disabled select,
  .wf-prize-disabled button {
    background: #f1f5f9 !important;
    color: #94a3b8 !important;
    border-color: #e2e8f0 !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
  }
  .wf-prize-disabled .table-wrapper tbody tr {
    pointer-events: none;
  }
  .wf-prize-disabled .table-wrapper tbody tr:hover {
    background: inherit;
  }
  .wf-prize-row-locked {
    opacity: 0.56;
  }
  .wf-prize-row-locked td {
    background: #f8fafc;
  }
  .wf-action-disabled {
    opacity: 0.55;
    cursor: not-allowed !important;
    box-shadow: none !important;
  }
  .wf-save-active {
    box-shadow: 0 0 0 3px var(--primary-focus);
  }
  .wf-prize-form-locked {
    opacity: 0.65;
  }
  .wf-status {
    font-weight: 600;
  }
  .wf-status--active {
    color: var(--primary);
  }
  .wf-status--ended {
    color: #e11d2e;
  }
  .wf-status--upcoming {
    color: #f59e0b;
  }
  .wf-status--inactive {
    color: var(--muted);
  }
  .wf-datetime-grid input[type="date"] {
    appearance: none;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 15px;
    outline: none;
    background: #fff;
    color: #111;
    width: 100%;
  }
  .wf-datetime-grid input[type="date"]:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-focus);
  }
  .wf-text-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
  }
  .wf-text-toolbar .btn {
    min-width: 44px;
    padding: 6px 10px;
  }
  .wf-text-toolbar .btn.active {
    box-shadow: 0 0 0 3px var(--primary-focus);
  }
  .wf-text-editor {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 15px;
    outline: none;
    background: #fff;
    color: #111;
    min-height: 96px;
    width: 100%;
    line-height: 1.6;
  }
  .wf-text-editor:empty::before {
    content: attr(data-placeholder);
    color: var(--muted);
  }
  .wf-text-editor:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-focus);
  }
  @media (max-width: 900px) {
    .wf-switch-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 640px) {
    .wf-switch-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="card">
  <div class="section-header">
    <h3>Status</h3>
  </div>
  <div class="field">
    <a class="btn primary standard-primary-button" href="Wheel%20of%20Fortune/WFM.php" target="_blank" rel="noopener">Open Wheel</a>
  </div>
</div>

<div class="card" id="wf-texts-card">
  <div class="section-header">
    <h3>Control Panel</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div id="wf-status-text" class="wf-status wf-status--inactive">Not Active</div>
    <div class="wf-switch-grid">
      <label class="switch wf-switch">
        <span class="switch-label">Active</span>
        <span class="switch-toggle">
          <input type="checkbox" id="wheel-active-toggle" aria-label="Active" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
      <label class="switch wf-switch">
        <span class="switch-label">Duration</span>
        <span class="switch-toggle">
          <input type="checkbox" id="wheel-duration-toggle" aria-label="Duration" />
          <span class="switch-track"><span class="switch-thumb"></span></span>
        </span>
      </label>
    </div>
    <div class="form grid two-column-fields wf-datetime-grid">
      <div class="wf-datetime-title wf-datetime-title--start">Start</div>
      <label class="field standard-width wf-datetime-start">
        <span>Date</span>
        <input
          type="date"
          id="wheel-duration-start"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width wf-datetime-start-time">
        <span>Time</span>
        <select id="wheel-duration-start-time">
          <option value="">Select time</option>
          <option value="00:00">00:00</option>
          <option value="01:00">01:00</option>
          <option value="02:00">02:00</option>
          <option value="03:00">03:00</option>
          <option value="04:00">04:00</option>
          <option value="05:00">05:00</option>
          <option value="06:00">06:00</option>
          <option value="07:00">07:00</option>
          <option value="08:00">08:00</option>
          <option value="09:00">09:00</option>
          <option value="10:00">10:00</option>
          <option value="11:00">11:00</option>
          <option value="12:00">12:00</option>
          <option value="13:00">13:00</option>
          <option value="14:00">14:00</option>
          <option value="15:00">15:00</option>
          <option value="16:00">16:00</option>
          <option value="17:00">17:00</option>
          <option value="18:00">18:00</option>
          <option value="19:00">19:00</option>
          <option value="20:00">20:00</option>
          <option value="21:00">21:00</option>
          <option value="22:00">22:00</option>
          <option value="23:00">23:00</option>
        </select>
      </label>
      <div class="wf-datetime-title wf-datetime-title--end">End</div>
      <label class="field standard-width wf-datetime-end">
        <span>Date</span>
        <input
          type="date"
          id="wheel-duration-end"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width wf-datetime-end-time">
        <span>Time</span>
        <select id="wheel-duration-end-time">
          <option value="">Select time</option>
          <option value="00:00">00:00</option>
          <option value="01:00">01:00</option>
          <option value="02:00">02:00</option>
          <option value="03:00">03:00</option>
          <option value="04:00">04:00</option>
          <option value="05:00">05:00</option>
          <option value="06:00">06:00</option>
          <option value="07:00">07:00</option>
          <option value="08:00">08:00</option>
          <option value="09:00">09:00</option>
          <option value="10:00">10:00</option>
          <option value="11:00">11:00</option>
          <option value="12:00">12:00</option>
          <option value="13:00">13:00</option>
          <option value="14:00">14:00</option>
          <option value="15:00">15:00</option>
          <option value="16:00">16:00</option>
          <option value="17:00">17:00</option>
          <option value="18:00">18:00</option>
          <option value="19:00">19:00</option>
          <option value="20:00">20:00</option>
          <option value="21:00">21:00</option>
          <option value="22:00">22:00</option>
          <option value="23:00">23:00</option>
        </select>
      </label>
      <div class="wf-datetime-empty" aria-hidden="true"></div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="wheel-settings-save">Save</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Texts</h3>
  </div>
  <div class="form">
    <div class="field standard-width">
      <span>Hint</span>
      <div class="wf-text-toolbar" role="toolbar" aria-label="Hint tools">
        <button type="button" class="btn ghost small" data-align="right" aria-label="Align right" title="Align right">
          <span class="ri ri-align-right" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn ghost small" data-align="center" aria-label="Align center" title="Align center">
          <span class="ri ri-align-center" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn ghost small" data-align="left" aria-label="Align left" title="Align left">
          <span class="ri ri-align-left" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn ghost small" data-action="bold" aria-label="Bold" title="Bold">
          <span class="ri ri-bold" aria-hidden="true"></span>
        </button>
        <button type="button" class="btn ghost small" data-action="link" aria-label="Insert link" title="Insert link">
          <span class="ri ri-link" aria-hidden="true"></span>
        </button>
      </div>
      <div
        id="wheel-hint-text"
        class="wf-text-editor"
        contenteditable="true"
        role="textbox"
        aria-multiline="true"
        data-align="right"
        data-placeholder="متن راهنما"
        tabindex="0"
      ></div>
    </div>
    <div class="field full">
      <button type="button" class="btn primary standard-primary-button" id="wheel-texts-save">Save</button>
    </div>
  </div>
</div>

<div id="wf-prize-section">
  <div class="card">
  <div class="section-header">
    <h3>Add Prize</h3>
  </div>
  <form id="wf-prize-form" class="form">
    <div class="form grid wf-prize-grid">
      <label class="field standard-width">
        <span>Name</span>
        <input id="wf-prize-name" name="name" type="text" autocomplete="off" required />
      </label>
      <label class="field wf-standard-third">
        <span>Quantity</span>
        <input
          id="wf-prize-quantity"
          name="quantity"
          type="number"
          min="1"
          step="1"
          value="1"
          required
        />
      </label>
      <div></div>
    </div>
    <div class="field full wf-form-action">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
  </form>
</div>

  <div class="card">
  <div class="section-header">
    <h3>Prizes</h3>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>On Wheel Name</th>
          <th>Status</th>
          <th>Quantity</th>
          <th>Count Control</th>
          <th>Action Bar</th>
        </tr>
      </thead>
      <tbody id="wf-prize-list"></tbody>
    </table>
  </div>
</div>
</div>

<script src="Wheel%20of%20Fortune/WF%20Prizes.js" defer></script>
<script src="Wheel%20of%20Fortune/WFSetting.js" defer></script>
