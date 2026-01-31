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
      "start-time start empty"
      "end-time end empty";
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
    align-items: end;
    justify-items: end;
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
    border: 1px solid var(--border);
    height: 2px;
    top: 50%;
    left: 6px;
    right: 6px;
    transform: translateY(-50%);
    border-radius: 999px;
  }
  .wf-switch .switch-thumb {
    width: 18px;
    height: 18px;
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
    transform: translate(30px, -50%);
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
    <h3>Active</h3>
  </div>
  <div class="form" style="gap:12px;">
    <div id="wf-status-text" class="muted" style="font-weight:600;">Not Active</div>
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
      <label class="field standard-width wf-datetime-start">
        <span>Start</span>
        <input
          type="date"
          id="wheel-duration-start"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width wf-datetime-start-time">
        <span>Start</span>
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
      <label class="field standard-width wf-datetime-end">
        <span>End</span>
        <input
          type="date"
          id="wheel-duration-end"
          placeholder="YYYY-MM-DD"
        />
      </label>
      <label class="field standard-width wf-datetime-end-time">
        <span>End</span>
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
    <h3>Add Prize</h3>
  </div>
  <form id="wf-prize-form" class="form grid wf-prize-grid">
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
    <div class="field standard-width">
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
          <th>Quantity</th>
          <th>Action Bar</th>
        </tr>
      </thead>
      <tbody id="wf-prize-list"></tbody>
    </table>
  </div>
</div>

<script src="Wheel%20of%20Fortune/WF%20Prizes.js" defer></script>
<script src="Wheel%20of%20Fortune/WFSetting.js" defer></script>
