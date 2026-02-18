<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/tab-permissions.php';
requireTabPermissionFromSession('wheel-of-fortune', false);
?>

<link rel="stylesheet" href="mini%20apps/Wheel%20of%20Fortune/wf-panel.css" />
<div class="wf-shell">
<div class="sub-layout" data-wf-sub-layout>
  <aside class="sub-sidebar">
    <div class="sub-header">Wheel of Fortune</div>
    <div class="sub-nav">
      <button type="button" class="sub-item active" data-pane="wf-main">Main Panel</button>
      <button type="button" class="sub-item" data-pane="wf-invitees">Invitees</button>
      <button type="button" class="sub-item" data-pane="wf-question">Question</button>
    </div>
  </aside>
  <div class="sub-content">
    <div class="sub-pane active" data-pane="wf-main">
      <div class="card">
  <div class="section-header">
    <h3>Status</h3>
  </div>
  <div class="field">
    <a class="btn primary standard-primary-button" href="mini%20apps/Wheel%20of%20Fortune/WFM.php" target="_blank" rel="noopener">Open Wheel</a>
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
    <h3>Fake Items</h3>
  </div>
  <form id="wf-fake-form" class="form">
    <label class="field standard-width">
      <span>Fake Item Name</span>
      <input id="wf-fake-name" name="fake-name" type="text" autocomplete="off" required />
    </label>
    <div class="field full">
      <button type="submit" class="btn primary standard-primary-button">Add</button>
    </div>
  </form>
  <div class="table-wrapper wf-fake-table">
    <table class="wf-prize-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="wf-fake-list"></tbody>
    </table>
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
    </div>
    <div class="sub-pane" data-pane="wf-invitees">
      <?php include __DIR__ . '/invitees.php'; ?>
    </div>
    <div class="sub-pane" data-pane="wf-question">
      <?php include __DIR__ . '/WFQ.php'; ?>
    </div>
  </div>
</div>
</div>

<script src="mini%20apps/Wheel%20of%20Fortune/wf-panel-local.js" defer></script>
<script src="mini%20apps/Wheel%20of%20Fortune/WF%20Prizes.js" defer></script>
<script src="mini%20apps/Wheel%20of%20Fortune/WFSetting.js" defer></script>









