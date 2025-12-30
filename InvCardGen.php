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
      <div class="field-block" data-field-block="name">
        <label class="field standard-width">
          <span>Name</span>
          <input id="invite-card-name" type="text" />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
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
              <input id="invite-card-name-x" name="invite-card-name-x" type="text" inputmode="decimal" placeholder="x" />
              <input id="invite-card-name-y" name="invite-card-name-y" type="text" inputmode="decimal" placeholder="y" />
              <input
                id="invite-card-name-scale"
                name="invite-card-name-scale"
                type="text"
                inputmode="decimal"
                placeholder="scale"
                data-scale-input
              />
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
              <button
                type="button"
                class="btn ghost small color-picker-trigger"
                data-style-color-trigger
                data-style-field="name"
                aria-label="باز کردن انتخاب رنگ برای Name"
              >
                انتخاب رنگ
              </button>
            </div>
          </label>
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
          />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
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
              <input id="invite-card-national-id-x" name="invite-card-national-id-x" type="text" inputmode="decimal" placeholder="x" />
              <input id="invite-card-national-id-y" name="invite-card-national-id-y" type="text" inputmode="decimal" placeholder="y" />
              <input
                id="invite-card-national-id-scale"
                name="invite-card-national-id-scale"
                type="text"
                inputmode="decimal"
                placeholder="scale"
                data-scale-input
              />
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
              <button
                type="button"
                class="btn ghost small color-picker-trigger"
                data-style-color-trigger
                data-style-field="national-id"
                aria-label="باز کردن انتخاب رنگ برای National ID QR Code"
              >
                انتخاب رنگ
              </button>
            </div>
          </label>
        </div>
      </div>
      <div class="field-block" data-field-block="guest-code">
        <label class="field standard-width">
          <span>Guest code</span>
          <input id="invite-card-guest-code" type="text" />
        </label>
        <div class="field-controls">
          <label class="field standard-width">
            <span>Field type</span>
            <select data-field-type>
              <option value="photo" selected>Photo</option>
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
              <input id="invite-card-guest-code-x" name="invite-card-guest-code-x" type="text" inputmode="decimal" placeholder="x" />
              <input id="invite-card-guest-code-y" name="invite-card-guest-code-y" type="text" inputmode="decimal" placeholder="y" />
              <input
                id="invite-card-guest-code-scale"
                name="invite-card-guest-code-scale"
                type="text"
                inputmode="decimal"
                placeholder="scale"
                data-scale-input
              />
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
              <button
                type="button"
                class="btn ghost small color-picker-trigger"
                data-style-color-trigger
                data-style-field="guest-code"
                aria-label="باز کردن انتخاب رنگ برای Guest code"
              >
                انتخاب رنگ
              </button>
            </div>
          </label>
        </div>
      </div>
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
