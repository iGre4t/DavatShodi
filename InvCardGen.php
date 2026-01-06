<?php
/**
 * Invite Card Generator tab markup.
 */
?>
<section id="tab-invite-card">
      <div class="card">
        <div class="section-header">
          <h3>Invite Card Generator</h3>
        </div>
    <div class="invite-card-photo-row">
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
    </div>
        <div class="form grid one-column invite-card-field-grid">
          <div class="field-block" data-field-block="name">
        <label class="field standard-width">
          <span>Name</span>
          <input id="invite-card-name" type="text" data-field-value />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
              <option value="qr">QR Code</option>
              <option value="text">Text</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Alignment</span>
            <select data-field-alignment>
              <option value="rtl">RTL</option>
              <option value="ltr">LTR</option>
              <option value="center">Center</option>
            </select>
          </label>
          <div class="position-controller-set" data-field-position>
            <label class="controller-label">Position Controller</label>
            <div class="coordinate-group">
              <label class="coordinate-input">
                <span>X</span>
                <input id="invite-card-name-x" name="invite-card-name-x" type="text" inputmode="decimal" placeholder="x" data-field-coordinate="x" />
              </label>
              <label class="coordinate-input">
                <span>Y</span>
                <input id="invite-card-name-y" name="invite-card-name-y" type="text" inputmode="decimal" placeholder="y" data-field-coordinate="y" />
              </label>
              <label class="coordinate-input">
                <span>Scale</span>
                <input
                  id="invite-card-name-scale"
                  name="invite-card-name-scale"
                  type="text"
                  inputmode="decimal"
                  placeholder="scale"
                  data-scale-input
                />
              </label>
              <button
                type="button"
                class="btn ghost small position-picker-button"
                data-position-picker-button
              >
                Position Pick
              </button>
            </div>
          </div>
        </div>
        <div class="style-controller hidden" data-field-style-controller>
          <label class="field standard-width">
            <span>Font</span>
            <select data-field-font>
              <option value="PeydaWebFaNum">PeydaWebFaNum</option>
              <option value="PeydaWebFaNum-Bold">PeydaWebFaNum Bold</option>
              <option value="remixicon">Remixicon</option>
              <option value="IRANSansX-Bold">IRANSansX Bold</option>
              <option value="IRANSansXFaNum-Bold">IRANSansX FaNum Bold</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font weight</span>
            <select data-field-weight>
              <option value="400">400</option>
              <option value="500">500</option>
              <option value="600">600</option>
              <option value="700">700</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font size (px)</span>
            <input data-field-size type="number" min="8" value="16" />
          </label>
          <label class="field standard-width color-picker-field">
            <span>Font color</span>
            <div class="appearance-input-group invite-color-field">
              <input
                type="text"
                class="appearance-hex-field"
                readonly
                aria-label="Font color value for Name field"
                data-style-color-hex="name"
              />
              <button
                type="button"
                class="appearance-preview color-picker-trigger"
                data-style-color-trigger
                data-style-field="name"
                data-style-color-preview="name"
                aria-label="باز کردن انتخاب رنگ برای Name"
              ></button>
            </div>
          </label>
        </div>
        <div class="invite-card-prefixes" data-prefix-container>
          <p class="muted small" data-invite-prefix-placeholder>
            Set a gender-specific prefix so the name field can be rendered per guest gender.
          </p>
        </div>
      </div>
      <div class="field-block" data-field-block="national-id">
        <label class="field standard-width">
          <span>National ID QR Code</span>
          <input
            id="invite-card-national-id"
            type="text"
            inputmode="numeric"
            pattern="\d{0,10}"
            maxlength="10"
            data-field-value
          />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
              <option value="qr">QR Code</option>
              <option value="text">Text</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Alignment</span>
            <select data-field-alignment>
              <option value="rtl">RTL</option>
              <option value="ltr">LTR</option>
              <option value="center">Center</option>
            </select>
          </label>
          <div class="position-controller-set" data-field-position>
            <label class="controller-label">Position Controller</label>
            <div class="coordinate-group">
              <label class="coordinate-input">
                <span>X</span>
                <input id="invite-card-national-id-x" name="invite-card-national-id-x" type="text" inputmode="decimal" placeholder="x" data-field-coordinate="x" />
              </label>
              <label class="coordinate-input">
                <span>Y</span>
                <input id="invite-card-national-id-y" name="invite-card-national-id-y" type="text" inputmode="decimal" placeholder="y" data-field-coordinate="y" />
              </label>
              <label class="coordinate-input">
                <span>Scale</span>
                <input
                  id="invite-card-national-id-scale"
                  name="invite-card-national-id-scale"
                  type="text"
                  inputmode="decimal"
                  placeholder="scale"
                  data-scale-input
                />
              </label>
              <button
                type="button"
                class="btn ghost small position-picker-button"
                data-position-picker-button
              >
                Position Pick
              </button>
            </div>
          </div>
        </div>
        <div class="style-controller hidden" data-field-style-controller>
          <label class="field standard-width">
            <span>Font</span>
            <select data-field-font>
              <option value="PeydaWebFaNum">PeydaWebFaNum</option>
              <option value="PeydaWebFaNum-Bold">PeydaWebFaNum Bold</option>
              <option value="remixicon">Remixicon</option>
              <option value="IRANSansX-Bold">IRANSansX Bold</option>
              <option value="IRANSansXFaNum-Bold">IRANSansX FaNum Bold</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font weight</span>
            <select data-field-weight>
              <option value="400">400</option>
              <option value="500">500</option>
              <option value="600">600</option>
              <option value="700">700</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font size (px)</span>
            <input data-field-size type="number" min="8" value="16" />
          </label>
          <label class="field standard-width color-picker-field">
            <span>Font color</span>
            <div class="appearance-input-group invite-color-field">
              <input
                type="text"
                class="appearance-hex-field"
                readonly
                aria-label="Font color value for National ID QR Code"
                data-style-color-hex="national-id"
              />
              <button
                type="button"
                class="appearance-preview color-picker-trigger"
                data-style-color-trigger
                data-style-field="national-id"
                data-style-color-preview="national-id"
                aria-label="باز کردن انتخاب رنگ برای National ID QR Code"
              ></button>
            </div>
          </label>
        </div>
      </div>
      <div class="field-block" data-field-block="guest-code">
        <label class="field standard-width">
          <span>Guest code</span>
          <input id="invite-card-guest-code" type="text" data-field-value />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
              <option value="qr">QR Code</option>
              <option value="text">Text</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Alignment</span>
            <select data-field-alignment>
              <option value="rtl">RTL</option>
              <option value="ltr">LTR</option>
              <option value="center">Center</option>
            </select>
          </label>
          <div class="position-controller-set" data-field-position>
            <label class="controller-label">Position Controller</label>
            <div class="coordinate-group">
              <label class="coordinate-input">
                <span>X</span>
                <input id="invite-card-guest-code-x" name="invite-card-guest-code-x" type="text" inputmode="decimal" placeholder="x" data-field-coordinate="x" />
              </label>
              <label class="coordinate-input">
                <span>Y</span>
                <input id="invite-card-guest-code-y" name="invite-card-guest-code-y" type="text" inputmode="decimal" placeholder="y" data-field-coordinate="y" />
              </label>
              <label class="coordinate-input">
                <span>Scale</span>
                <input
                  id="invite-card-guest-code-scale"
                  name="invite-card-guest-code-scale"
                  type="text"
                  inputmode="decimal"
                  placeholder="scale"
                  data-scale-input
                />
              </label>
              <button
                type="button"
                class="btn ghost small position-picker-button"
                data-position-picker-button
              >
                Position Pick
              </button>
            </div>
          </div>
        </div>
        <div class="style-controller hidden" data-field-style-controller>
          <label class="field standard-width">
            <span>Font</span>
            <select data-field-font>
              <option value="PeydaWebFaNum">PeydaWebFaNum</option>
              <option value="PeydaWebFaNum-Bold">PeydaWebFaNum Bold</option>
              <option value="remixicon">Remixicon</option>
              <option value="IRANSansX-Bold">IRANSansX Bold</option>
              <option value="IRANSansXFaNum-Bold">IRANSansX FaNum Bold</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font weight</span>
            <select data-field-weight>
              <option value="400">400</option>
              <option value="500">500</option>
              <option value="600">600</option>
              <option value="700">700</option>
            </select>
          </label>
          <label class="field standard-width">
            <span>Font size (px)</span>
            <input data-field-size type="number" min="8" value="16" />
          </label>
          <label class="field standard-width color-picker-field">
            <span>Font color</span>
            <div class="appearance-input-group invite-color-field">
              <input
                type="text"
                class="appearance-hex-field"
                readonly
                aria-label="Font color value for Guest code"
                data-style-color-hex="guest-code"
              />
              <button
                type="button"
                class="appearance-preview color-picker-trigger"
                data-style-color-trigger
                data-style-field="guest-code"
                data-style-color-preview="guest-code"
                aria-label="باز کردن انتخاب رنگ برای Guest code"
              ></button>
            </div>
          </label>
        </div>
      </div>
    </div>
    <div class="invite-card-preview" data-invite-card-preview>
      <p class="muted invite-card-preview__placeholder" data-invite-card-preview-placeholder>
        Generated cards appear here after the generator runs.
      </p>
      <canvas
        data-invite-card-canvas
        class="hidden"
        role="img"
        aria-label="Generated invite card preview"
      ></canvas>
      <a
        class="btn primary hidden"
        data-invite-card-download
        download="invite-card.png"
      >
        Download invite card
      </a>
    </div>
    <div class="invite-card-generator">
      <label class="field standard-width">
        <span>Preview gender</span>
        <select data-invite-card-gender>
          <option value="">Use entered name</option>
        </select>
      </label>
      <button
        type="button"
        id="invite-card-generate"
        class="btn primary full-width"
        disabled
      >
        Generate Invite Card
      </button>
      <p class="muted" data-invite-card-status>
        Select a photo and fill every field to enable the generator.
      </p>
    </div>
    <div class="invite-card-bulk">
      <button
        type="button"
        class="btn ghost full-width"
        data-invite-card-create-all
        disabled
      >
        Create All Invite Cards
      </button>
      <button
        type="button"
        class="btn ghost full-width"
        data-invite-card-create-missing
        disabled
      >
        Create Lasted Invite Cards
      </button>
      <p class="muted small">
        Once the template is ready, generate InviteCard.jpg for every guest so their invite page
        directly shows the saved card.
      </p>
      <p class="muted small">
        The new action skips guests whose inv/{guest code}/InviteCard.jpg already exists.
      </p>
      <div class="progress-for-cards hidden" data-progress-for-cards>
        <div class="progress-for-cards__header">
          <span class="progress-for-cards__title">Progress for Cards</span>
          <span class="muted small" data-progress-for-cards-status></span>
        </div>
        <div class="progress-for-cards__stats">
          <div>
            <span class="progress-for-cards__count" data-progress-for-cards-completed>0</span>
            completed
          </div>
          <div>
            <span class="progress-for-cards__count" data-progress-for-cards-remaining>0</span>
            remaining
          </div>
        </div>
        <div class="progress-for-cards__bar">
          <span class="progress-for-cards__fill" data-progress-for-cards-fill style="width: 0%"></span>
        </div>
      </div>
    </div>
    <div
      class="position-picker-modal hidden"
      data-position-picker-modal
      aria-hidden="true"
      role="dialog"
      aria-modal="true"
    >
      <div class="position-picker-overlay" data-position-picker-overlay></div>
      <div class="position-picker-shell">
        <div class="position-picker-header">
          <h4>Pick position on photo</h4>
          <p>Click anywhere on the photo to store that position.</p>
        </div>
        <div class="position-picker-content">
          <div class="position-picker-image-shell">
            <img
              data-position-picker-image
              alt="Invite card photo preview"
              draggable="false"
            />
            <span
              data-position-picker-dot
              class="position-picker-dot hidden"
              aria-hidden="true"
            ></span>
          </div>
        </div>
        <div class="position-picker-actions">
          <button
            type="button"
            class="btn primary"
            data-position-picker-confirm
            disabled
          >
            Confirm Position
          </button>
          <button
            type="button"
            class="btn ghost"
            data-position-picker-cancel
          >
            Cancel
          </button>
        </div>
      </div>
    </div>

    <div class="card guest-invite-card">
      <div class="section-header">
        <h3>Guest Invite Card Generator</h3>
      </div>
      <div class="form guest-invite-form">
        <label class="field standard-width">
          <span>Guest code</span>
          <input
            type="text"
            data-guest-invite-code
            placeholder="Enter a guest code from the current event"
            autocomplete="off"
          />
        </label>
        <button
          type="button"
          class="btn primary full-width"
          data-guest-invite-generate
          disabled
        >
          Generate Guest Card
        </button>
        <p class="muted small" data-guest-invite-status>
          Enter a guest code from the event purelist to auto-fill the template and generate a card.
        </p>
      </div>
    </div>

  </div>
</section>
