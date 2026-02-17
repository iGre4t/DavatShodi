        <section id="tab-devsettings" class="tab">
            <div class="sub-layout" data-sub-layout>
              <aside class="sub-sidebar">
            <div class="sub-header">ØªÙ†Ø¸ÛŒÙ…Ø§Øª ØªÙˆØ³Ø¹Ù‡â€ŒØ¯Ù‡Ù†Ø¯Ù‡</div>
            <div class="sub-nav">
              <button type="button" class="sub-item active" data-pane="panel-settings">
                Ø¹Ù…ÙˆÙ…ÛŒ
              </button>
              <button type="button" class="sub-item" data-pane="appearance">
                Ø¸Ø§Ù‡Ø±
              </button>
              <button type="button" class="sub-item" data-pane="database">
                Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡
              </button>
              <button type="button" class="sub-item" data-pane="printer-settings">
                Printer Setting
              </button>
                </div>
              </aside>
              <div class="sub-content">
                <!-- General pane hosts backend configuration controls. -->
                <div class="sub-pane active" data-pane="panel-settings">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>General settings</h3>
                    </div>
                    <div class="form grid">
                      <label class="field">
                        <span>Time zone</span>
                        <select id="timezone-select"></select>
                      </label>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-general-settings">Save general settings</button>
                    </div>
                    <p class="hint">Setting the timezone to your local hours keeps dashboard clocks and reports accurate.</p>
                  </div>
                </div>
                <!-- Appearance pane exposes color pickers and panel metadata managed via app.js state helpers. -->
                <div class="sub-pane" data-pane="appearance">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>ØªÙ†Ø¸ÛŒÙ…Ø§Øª Ø¸Ø§Ù‡Ø±ÛŒ Ø¹Ù…ÙˆÙ…ÛŒ</h3>
                    </div>
                    <div class="form grid one-column">
                      <label class="field">
                        <span>Ø¹Ù†ÙˆØ§Ù† Ù¾Ù†Ù„</span>
                        <input id="dev-panel-name" type="text" value="<?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>" />
                      </label>
                      <label class="field icon-field">
                        <span>Ø¢ÛŒÚ©ÙˆÙ† Ø³Ø§ÛŒØª</span>
                        <div class="photo-uploader" data-site-icon-uploader>
                          <div class="photo-preview" data-site-icon-preview aria-live="polite">
                            <img data-site-icon-image class="hidden" alt="Ù¾ÛŒØ´â€ŒÙ†Ù…Ø§ÛŒØ´ Ø¢ÛŒÚ©ÙˆÙ† Ø§Ù†ØªØ®Ø§Ø¨â€ŒØ´Ø¯Ù‡" />
                            <div class="photo-placeholder" data-site-icon-placeholder>ØªØµÙˆÛŒØ±ÛŒ Ù†ÛŒØ³Øª</div>
                          </div>
                          <div class="photo-actions">
                            <button
                              type="button"
                              class="btn ghost small"
                              data-open-photo-chooser
                              aria-label="Ø§ÙØ²ÙˆØ¯Ù† Ø¢ÛŒÚ©ÙˆÙ† Ø³Ø§ÛŒØª Ø§Ø² Ú©ØªØ§Ø¨Ø®Ø§Ù†Ù‡ Ø¹Ú©Ø³"
                            >
                              Ø§ÙØ²ÙˆØ¯Ù† Ø¹Ú©Ø³
                            </button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-clear-site-icon
                              aria-label="Ù¾Ø§Ú© Ú©Ø±Ø¯Ù† Ø¢ÛŒÚ©ÙˆÙ† Ø³Ø§ÛŒØª Ø§Ù†ØªØ®Ø§Ø¨â€ŒØ´Ø¯Ù‡"
                            >
                              Ù¾Ø§Ú© Ú©Ø±Ø¯Ù†
                            </button>
                          </div>
                        </div>
                      </label>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-panel-settings">Ø°Ø®ÛŒØ±Ù‡ Ø¹Ù†ÙˆØ§Ù† Ù¾Ù†Ù„</button>
                    </div>
                    <p class="hint">Ù…ØªÙ† Ù†Ù…Ø§ÛŒØ´ Ø¯Ø§Ø¯Ù‡â€ŒØ´Ø¯Ù‡ Ø¯Ø± Ù†ÙˆØ§Ø± Ú©Ù†Ø§Ø±ÛŒ Ùˆ ØªØ¨ Ù…Ø±ÙˆØ±Ú¯Ø± Ø±Ø§ Ø¨Ø±Ø§ÛŒ Ù‡Ù…Ù‡ Ú©Ø§Ø±Ø¨Ø±Ø§Ù† Ø¨Ù‡â€ŒØ±ÙˆØ² Ú©Ù†ÛŒØ¯.</p>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>ØªÙ†Ø¸ÛŒÙ… Ø±Ù†Ú¯â€ŒÙ‡Ø§</h3>
                    </div>
                    <div class="appearance-grid">
                      <?php foreach ([
                        "primary" => "Ø±Ù†Ú¯ Ø§ØµÙ„ÛŒ",
                        "background" => "Ø±Ù†Ú¯ Ù¾Ø³â€ŒØ²Ù…ÛŒÙ†Ù‡",
                        "text" => "Ø±Ù†Ú¯ Ù…ØªÙ†",
                        "toggle" => "Ø±Ù†Ú¯ Ø¯Ú©Ù…Ù‡ ØªØºÛŒÛŒØ± ÙˆØ¶Ø¹ÛŒØª"
                      ] as $key => $label): ?>
                        <div class="appearance-row">
                          <span class="appearance-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                          <div class="appearance-input-group">
                            <input
                              type="text"
                              class="appearance-hex-field"
                              data-appearance-hex="<?= $key ?>"
                              aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> Ù…Ù‚Ø¯Ø§Ø± Ù‡Ú¯Ø²"
                              maxlength="7"
                              placeholder="#000000"
                            />
                            <button
                              type="button"
                              class="appearance-preview"
                              data-appearance-preview="<?= $key ?>"
                              data-show-appearance-picker="<?= $key ?>"
                              aria-label="Ø¨Ø§Ø² Ú©Ø±Ø¯Ù† Ø§Ù†ØªØ®Ø§Ø¨ Ø±Ù†Ú¯ Ø¨Ø±Ø§ÛŒ <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            ></button>
                            <button
                              type="button"
                              class="btn ghost small"
                              data-show-appearance-picker="<?= $key ?>"
                            >
                              Ø§Ù†ØªØ®Ø§Ø¨ Ø±Ù†Ú¯
                            </button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="section-footer">
                      <button type="button" class="btn primary" id="save-appearance-settings">Ø§Ø¹Ù…Ø§Ù„</button>
                      <button type="button" class="btn ghost" id="reset-appearance-settings">Ø¨Ø§Ø²Ù†Ø´Ø§Ù†ÛŒ</button>
                    </div>
                    <p class="hint" id="appearance-hint">Ø±Ù†Ú¯â€ŒÙ‡Ø§ÛŒ Ø±Ø§Ø¨Ø· Ú©Ø§Ø±Ø¨Ø±ÛŒ Ø±Ø§ Ù…Ø³ØªÙ‚ÛŒÙ…Ø§Ù‹ Ø§Ø² Ø¢Ø²Ù…Ø§ÛŒØ´Ú¯Ø§Ù‡ ØªÙˆØ³Ø¹Ù‡ ØªÙ†Ø¸ÛŒÙ… Ú©Ù†ÛŒØ¯.</p>
                  </div>
                </div>
                <div class="sub-pane" data-pane="database">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>ÙˆØ§Ø±Ø¯Ø§Øª / ØµØ§Ø¯Ø±Ø§Øª Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡</h3>
                    </div>
                    <p class="hint">
                      ØªØµÙˆÛŒØ± ÙØ¹Ù„ÛŒ Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡ Ø±Ø§ ØµØ§Ø¯Ø± Ú©Ù†ÛŒØ¯ØŒ ÙØ§ÛŒÙ„ Ù‚Ø¨Ù„ÛŒ Ø±Ø§ ÙˆØ§Ø±Ø¯ Ú©Ù†ÛŒØ¯ Ùˆ Ù¾Ø´ØªÛŒØ¨Ø§Ù†â€ŒÚ¯ÛŒØ±ÛŒ Ø®ÙˆØ¯Ú©Ø§Ø± Ø±Ø§ Ø¨Ø¯ÙˆÙ† ØªØ±Ú© Ù¾Ù†Ù„ Ù¾ÛŒÚ©Ø±Ø¨Ù†Ø¯ÛŒ Ú©Ù†ÛŒØ¯.
                    </p>
                    <div class="form single-column">
                      <label class="field standard-width">
                        <span>Ù¾Ø´ØªÛŒØ¨Ø§Ù†â€ŒÚ¯ÛŒØ±ÛŒ ÙÙˆØ±ÛŒ</span>
                        <button type="button" class="btn primary full-width" id="instant-backup-btn">
                          Ø¯Ø§Ù†Ù„ÙˆØ¯ Ù¾Ø´ØªÛŒØ¨Ø§Ù†
                        </button>
                      </label>
                      <label class="field standard-width">
                        <span>Ø¯Ø±ÙˆÙ†â€ŒØ±ÛŒØ²ÛŒ Ù¾Ø´ØªÛŒØ¨Ø§Ù†</span>
                        <div class="backup-import-control">
                          <button type="button" class="btn ghost small" id="backup-import-trigger">
                            Ø§Ù†ØªØ®Ø§Ø¨ ÙØ§ÛŒÙ„
                          </button>
                          <span id="backup-file-chosen" class="backup-file-chosen">ÙØ§ÛŒÙ„ÛŒ Ø§Ù†ØªØ®Ø§Ø¨ Ù†Ø´Ø¯Ù‡ Ø§Ø³Øª.</span>
                          <input id="dev-db-backup-file" type="file" accept=".json" class="backup-file-input" hidden />
                        </div>
                      </label>
                    </div>
                    <form id="backup-settings-form" class="form single-column">
                      <label class="field standard-width">
                        <span>ÙØ§ØµÙ„Ù‡ Ù¾Ø´ØªÛŒØ¨Ø§Ù†â€ŒÚ¯ÛŒØ±ÛŒ Ø®ÙˆØ¯Ú©Ø§Ø± (Ø¯Ù‚ÛŒÙ‚Ù‡)</span>
                        <input
                          id="auto-backup-interval"
                          type="number"
                          min="0"
                          placeholder="Û° = ØºÛŒØ±ÙØ¹Ø§Ù„"
                          class="numeric-field"
                        />
                      </label>
                      <label class="field standard-width">
                        <span>Ø­Ø¯Ø§Ú©Ø«Ø± ÙØ¶Ø§ÛŒ Ø°Ø®ÛŒØ±Ù‡â€ŒØ³Ø§Ø²ÛŒ Ù¾Ø´ØªÛŒØ¨Ø§Ù† Ø®ÙˆØ¯Ú©Ø§Ø±</span>
                        <input
                          id="auto-backup-limit"
                          type="number"
                          min="0"
                          placeholder="Û° = Ù†Ø§Ù…Ø­Ø¯ÙˆØ¯"
                          class="numeric-field"
                        />
                      </label>
                      <div class="section-footer auto-backup-actions">
                        <button type="submit" class="btn primary" id="save-backup-settings">
                          Ø°Ø®ÛŒØ±Ù‡ ØªÙ†Ø¸ÛŒÙ…Ø§Øª Ù¾Ø´ØªÛŒØ¨Ø§Ù† Ø®ÙˆØ¯Ú©Ø§Ø±
                        </button>
                      </div>
                    </form>
                    <p class="hint backup-history-hint">
                      Ù¾Ø´ØªÛŒØ¨Ø§Ù†â€ŒÙ‡Ø§ÛŒ Ø²ÛŒØ± Ø´Ø§Ù…Ù„ ØªØµÙˆÛŒØ± ÙÙˆØ±ÛŒ Ùˆ Ù†Ø³Ø®Ù‡â€ŒÙ‡Ø§ÛŒ Ø²Ù…Ø§Ù†â€ŒØ¨Ù†Ø¯ÛŒâ€ŒØ´Ø¯Ù‡ Ù‡Ø³ØªÙ†Ø¯. Ù†Ø³Ø®Ù‡â€ŒÙ‡Ø§ÛŒ Ø®ÙˆØ¯Ú©Ø§Ø± Ù…Ø·Ø§Ø¨Ù‚ Ø¨Ø§ Ù…Ø­Ø¯ÙˆØ¯ÛŒØª Ø°Ø®ÛŒØ±Ù‡â€ŒØ³Ø§Ø²ÛŒ ØªÙ†Ø¸ÛŒÙ…â€ŒØ´Ø¯Ù‡ Ø¹Ù…Ù„ Ù…ÛŒâ€ŒÚ©Ù†Ù†Ø¯.
                    </p>
                    <div class="backup-history" id="backup-history"></div>
                  </div>
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Ú©Ù†Ø³ÙˆÙ„ SQL Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡</h3>
                    </div>
                    <p class="hint sql-console-hint">
                      Ø§Ø¬Ø±Ø§ÛŒ Ù…Ø³ØªÙ‚ÛŒÙ… SQL Ø±ÙˆÛŒ Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡ Ù…ØªØµÙ„ Ø¨Ø¯ÙˆÙ† Ø¨Ø§Ø² Ú©Ø±Ø¯Ù† phpMyAdmin.
                    </p>
                    <p class="hint sql-console-status muted" id="dev-sql-status">
                      Ø¯Ø± Ø­Ø§Ù„ Ø¨Ø±Ø±Ø³ÛŒ Ø§ØªØµØ§Ù„ Ù¾Ø§ÛŒÚ¯Ø§Ù‡ Ø¯Ø§Ø¯Ù‡...
                    </p>
                    <form id="developer-sql-form" class="form">
                      <label class="field full">
                        <span>Ù¾Ø±Ø³â€ŒÙˆØ¬ÙˆÛŒ SQL</span>
                        <textarea
                          id="dev-db-sql"
                          class="sql-editor"
                          dir="ltr"
                          spellcheck="false"
                          rows="10"
                          placeholder="SELECT * FROM gallery ORDER BY uploaded_at DESC LIMIT 10"
                        ></textarea>
                      </label>
                      <div class="section-footer sql-console-footer">
                        <div class="sql-console-actions">
                          <button type="submit" class="btn primary" id="run-sql-query">
                            Ø§Ø¬Ø±Ø§ÛŒ SQL
                          </button>
                          <button type="button" class="btn ghost" id="clear-sql-query">
                            Ù¾Ø§Ú© Ú©Ø±Ø¯Ù†
                          </button>
                        </div>
                      </div>
                    </form>
                    <div id="dev-sql-result" class="sql-result hidden" aria-live="polite">
                      <p class="muted" data-sql-result-message></p>
                      <div data-sql-result-body></div>
    </div>
  </div>
</div>

                <div class="sub-pane" data-pane="printer-settings">
                  <div class="card settings-section">
                    <div class="section-header">
                      <h3>Printer Setting</h3>
                    </div>
                    <form id="printer-settings-form" class="form grid one-column">
                      <label class="field">
                        <span>Printer device</span>
                        <select id="printer-device" name="printer-device">
                          <option value="">Loading printersâ€¦</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Layout</span>
                        <select id="printer-layout" name="printer-layout">
                          <option value="">Select layout</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Paper size</span>
                        <select id="printer-paper-size" name="printer-paper-size">
                          <option value="">Select paper size</option>
                        </select>
                      </label>
                      <p class="hint">Any custom size registered in the system will be used by default.</p>
                      <label class="field">
                        <span>Pages per paper</span>
                        <select id="printer-pages-per-paper" name="printer-pages-per-paper">
                          <option value="">Select pages per sheet</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Margin</span>
                        <select id="printer-margin" name="printer-margin">
                          <option value="">Select margin</option>
                        </select>
                      </label>
                      <label class="field">
                        <span>Scale</span>
                        <select id="printer-scale" name="printer-scale">
                          <option value="">Select scale</option>
                        </select>
                      </label>
                    </form>
                    <div class="section-footer">
                      <button type="button" id="printer-settings-save" class="btn primary">Save</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

