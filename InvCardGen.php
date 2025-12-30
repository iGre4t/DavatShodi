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
        <button type="button" class="btn primary" id="invite-card-map-photo">Map Photo</button>
      </div>
    </div>
  </div>
</section>
