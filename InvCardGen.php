<?php
/**
 * Invite Card Generator tab markup.
 */
?>
<section id="tab-invite-card" class="tab">
  <div class="card">
    <div class="section-header">
      <h3>Invite Card Generator</h3>
    </div>
    <div class="form grid one-column">
      <label class="field standard-width">
        <span>Coordinates (x, y, scale)</span>
        <div class="coordinate-group">
          <input
            id="invite-card-x"
            name="invite-card-x"
            type="text"
            inputmode="decimal"
            placeholder="x"
          />
          <input
            id="invite-card-y"
            name="invite-card-y"
            type="text"
            inputmode="decimal"
            placeholder="y"
          />
          <input
            id="invite-card-scale"
            name="invite-card-scale"
            type="text"
            inputmode="decimal"
            placeholder="scale"
          />
        </div>
      </label>
      <label class="field standard-width">
        <span>Alignment</span>
        <select id="invite-card-alignment" name="invite-card-alignment">
          <option value="rtl">RTL</option>
          <option value="ltr">LTR</option>
          <option value="center">Center</option>
        </select>
      </label>
      <label class="field standard-width">
        <span>Name</span>
        <input id="invite-card-name" type="text" />
      </label>
      <label class="field standard-width">
        <span>National ID code</span>
        <input
          id="invite-card-national-id"
          type="text"
          inputmode="numeric"
          pattern="\d{0,10}"
          maxlength="10"
        />
      </label>
      <label class="field standard-width">
        <span>Guest code</span>
        <input id="invite-card-guest-code" type="text" />
      </label>
      <label class="field full">
        <span>Choose photo</span>
        <div class="photo-actions">
          <button type="button" class="btn ghost" id="invite-card-choose-photo">
            Choose photo
          </button>
          <span class="muted" data-invite-card-photo-label>No photo selected.</span>
        </div>
        <div class="photo-preview" data-invite-card-photo-preview>
          <img
            data-invite-card-photo-preview-image
            class="hidden"
            alt="Selected invite card photo"
          />
          <div class="photo-placeholder" data-invite-card-photo-placeholder>
            Select a photo from the gallery.
          </div>
        </div>
        <p class="hint">Pick the image via the photo chooser modal.</p>
      </label>
      <div class="section-footer">
        <!-- Map Photo button removed per request -->
      </div>
    </div>
  </div>
</section>
