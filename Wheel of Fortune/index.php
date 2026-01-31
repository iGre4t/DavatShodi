<div class="card">
  <div class="section-header">
    <h3>Active</h3>
  </div>
  <div class="form" style="gap:12px;">
    <label class="switch">
      <span class="switch-label">Active</span>
      <span class="switch-toggle">
        <input type="checkbox" id="wheel-active-toggle" aria-label="Active" />
        <span class="switch-track"><span class="switch-thumb"></span></span>
      </span>
    </label>
    <label class="switch">
      <span class="switch-label">Duration</span>
      <span class="switch-toggle">
        <input type="checkbox" id="wheel-duration-toggle" aria-label="Duration" />
        <span class="switch-track"><span class="switch-thumb"></span></span>
      </span>
    </label>
    <div class="form grid two-column-fields">
      <label class="field">
        <span>Start</span>
        <input
          type="text"
          id="wheel-duration-start"
          data-jdp
          data-jdp-only-date="true"
          placeholder="YYYY/MM/DD"
          readonly
        />
      </label>
      <label class="field">
        <span>End</span>
        <input
          type="text"
          id="wheel-duration-end"
          data-jdp
          data-jdp-only-date="true"
          placeholder="YYYY/MM/DD"
          readonly
        />
      </label>
    </div>
  </div>
</div>

<div class="card">
  <div class="section-header">
    <h3>Add Prize</h3>
  </div>
  <form id="wf-prize-form" class="form grid two-column-fields">
    <label class="field">
      <span>Name</span>
      <input id="wf-prize-name" name="name" type="text" autocomplete="off" required />
    </label>
    <label class="field">
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
    <div class="field full">
      <button type="submit" class="btn primary">Add</button>
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
        </tr>
      </thead>
      <tbody id="wf-prize-list"></tbody>
    </table>
  </div>
</div>

<script src="Wheel%20of%20Fortune/WF%20Prizes.js" defer></script>

<script>
  window.addEventListener("DOMContentLoaded", () => {
    if (!window.jalaliDatepicker) {
      return;
    }
    window.jalaliDatepicker.startWatch({
      selector: "#wheel-duration-start, #wheel-duration-end",
      viewMode: "day",
      autoClose: true,
      format: "YYYY/MM/DD",
      initViewGregorian: false
    });
  });
</script>
