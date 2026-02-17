        <section id="tab-devsettings" class="tab">
          <div class="sub-layout" data-sub-layout>
            <aside class="sub-sidebar">
              <div class="sub-header">تنظیمات توسعه‌دهنده</div>
              <div class="sub-nav">
                <button type="button" class="sub-item active" data-pane="panel-settings">
                  عمومی
                </button>
                <button type="button" class="sub-item" data-pane="appearance">
                  ظاهر
                </button>
                <button type="button" class="sub-item" data-pane="database">
                  پایگاه داده
                </button>
                <button type="button" class="sub-item" data-pane="printer-settings">
                  تنظیمات چاپگر
                </button>
              </div>
            </aside>
            <div class="sub-content">
              <div class="sub-pane active" data-pane="panel-settings">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>تنظیمات عمومی</h3>
                  </div>
                  <div class="form grid">
                    <label class="field">
                      <span>منطقه زمانی</span>
                      <select id="timezone-select"></select>
                    </label>
                  </div>
                  <div class="section-footer">
                    <button type="button" class="btn primary" id="save-general-settings">ذخیره تنظیمات عمومی</button>
                  </div>
                  <p class="hint">برای دقیق بودن ساعت و گزارش‌ها، منطقه زمانی صحیح را انتخاب کنید.</p>
                </div>
              </div>

              <div class="sub-pane" data-pane="appearance">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>تنظیمات عمومی ظاهر</h3>
                  </div>
                  <div class="form grid one-column">
                    <label class="field">
                      <span>عنوان پنل</span>
                      <input id="dev-panel-name" type="text" value="<?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>" />
                    </label>
                    <label class="field icon-field">
                      <span>آیکون سایت</span>
                      <div class="photo-uploader" data-site-icon-uploader>
                        <div class="photo-preview" data-site-icon-preview aria-live="polite">
                          <img data-site-icon-image class="hidden" alt="پیش‌نمایش آیکون انتخاب‌شده سایت" />
                          <div class="photo-placeholder" data-site-icon-placeholder>تصویری انتخاب نشده است</div>
                        </div>
                        <div class="photo-actions">
                          <button
                            type="button"
                            class="btn ghost small"
                            data-open-photo-chooser
                            aria-label="انتخاب آیکون سایت از کتابخانه تصاویر"
                          >
                            انتخاب تصویر
                          </button>
                          <button
                            type="button"
                            class="btn ghost small"
                            data-clear-site-icon
                            aria-label="پاک کردن آیکون انتخاب‌شده سایت"
                          >
                            پاک کردن
                          </button>
                        </div>
                      </div>
                    </label>
                  </div>
                  <div class="section-footer">
                    <button type="button" class="btn primary" id="save-panel-settings">ذخیره عنوان پنل</button>
                  </div>
                  <p class="hint">عنوان پنل نمایش‌داده‌شده در نوار کناری و تب مرورگر را برای همه کاربران به‌روزرسانی کنید.</p>
                </div>

                <div class="card settings-section">
                  <div class="section-header">
                    <h3>تنظیمات رنگ</h3>
                  </div>
                  <div class="appearance-grid">
                    <?php foreach ([
                      "primary" => "رنگ اصلی",
                      "background" => "رنگ پس‌زمینه",
                      "text" => "رنگ متن",
                      "toggle" => "رنگ دکمه تغییر وضعیت"
                    ] as $key => $label): ?>
                      <div class="appearance-row">
                        <span class="appearance-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="appearance-input-group">
                          <input
                            type="text"
                            class="appearance-hex-field"
                            data-appearance-hex="<?= $key ?>"
                            aria-label="مقدار هگز <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                            maxlength="7"
                            placeholder="#000000"
                          />
                          <button
                            type="button"
                            class="appearance-preview"
                            data-appearance-preview="<?= $key ?>"
                            data-show-appearance-picker="<?= $key ?>"
                            aria-label="باز کردن انتخابگر رنگ برای <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                          ></button>
                          <button
                            type="button"
                            class="btn ghost small"
                            data-show-appearance-picker="<?= $key ?>"
                          >
                            انتخاب رنگ
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="section-footer">
                    <button type="button" class="btn primary" id="save-appearance-settings">اعمال</button>
                    <button type="button" class="btn ghost" id="reset-appearance-settings">بازنشانی</button>
                  </div>
                  <p class="hint" id="appearance-hint">رنگ‌های رابط کاربری را مستقیما از پنل تنظیمات توسعه‌دهنده تنظیم کنید.</p>
                </div>
              </div>

              <div class="sub-pane" data-pane="database">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>درون‌ریزی / برون‌ریزی پایگاه داده</h3>
                  </div>
                  <p class="hint">
                    از همین پنل یک نسخه لحظه‌ای خروجی بگیرید، فایل پشتیبان موجود را درون‌ریزی کنید و پشتیبان‌گیری خودکار را تنظیم کنید.
                  </p>
                  <div class="form single-column">
                    <label class="field standard-width">
                      <span>پشتیبان فوری</span>
                      <button type="button" class="btn primary full-width" id="instant-backup-btn">
                        دانلود نسخه پشتیبان
                      </button>
                    </label>
                    <label class="field standard-width">
                      <span>درون‌ریزی پشتیبان</span>
                      <div class="backup-import-control">
                        <button type="button" class="btn ghost small" id="backup-import-trigger">
                          انتخاب فایل
                        </button>
                        <span id="backup-file-chosen" class="backup-file-chosen">فایلی انتخاب نشده است.</span>
                        <input id="dev-db-backup-file" type="file" accept=".json" class="backup-file-input" hidden />
                      </div>
                    </label>
                  </div>
                  <form id="backup-settings-form" class="form single-column">
                    <label class="field standard-width">
                      <span>بازه پشتیبان‌گیری خودکار (دقیقه)</span>
                      <input
                        id="auto-backup-interval"
                        type="number"
                        min="0"
                        placeholder="0 = غیرفعال"
                        class="numeric-field"
                      />
                    </label>
                    <label class="field standard-width">
                      <span>حداکثر نگه‌داری پشتیبان خودکار</span>
                      <input
                        id="auto-backup-limit"
                        type="number"
                        min="0"
                        placeholder="0 = نامحدود"
                        class="numeric-field"
                      />
                    </label>
                    <div class="section-footer auto-backup-actions">
                      <button type="submit" class="btn primary" id="save-backup-settings">
                        ذخیره تنظیمات پشتیبان‌گیری خودکار
                      </button>
                    </div>
                  </form>
                  <p class="hint backup-history-hint">
                    فهرست زیر شامل نسخه‌های فوری و پشتیبان‌های زمان‌بندی‌شده است. ورودی‌های خودکار بر اساس محدودیت نگه‌داری شما مدیریت می‌شوند.
                  </p>
                  <div class="backup-history" id="backup-history"></div>
                </div>

                <div class="card settings-section">
                  <div class="section-header">
                    <h3>کنسول SQL پایگاه داده</h3>
                  </div>
                  <p class="hint sql-console-hint">
                    بدون باز کردن phpMyAdmin، SQL را مستقیم روی پایگاه داده متصل اجرا کنید.
                  </p>
                  <p class="hint sql-console-status muted" id="dev-sql-status">
                    در حال بررسی اتصال پایگاه داده...
                  </p>
                  <form id="developer-sql-form" class="form">
                    <label class="field full">
                      <span>کوئری SQL</span>
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
                          اجرای SQL
                        </button>
                        <button type="button" class="btn ghost" id="clear-sql-query">
                          پاک کردن
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
                    <h3>تنظیمات چاپگر</h3>
                  </div>
                  <form id="printer-settings-form" class="form grid one-column">
                    <label class="field">
                      <span>دستگاه چاپگر</span>
                      <select id="printer-device" name="printer-device">
                        <option value="">در حال بارگذاری چاپگرها...</option>
                      </select>
                    </label>
                    <label class="field">
                      <span>چیدمان</span>
                      <select id="printer-layout" name="printer-layout">
                        <option value="">انتخاب چیدمان</option>
                      </select>
                    </label>
                    <label class="field">
                      <span>اندازه کاغذ</span>
                      <select id="printer-paper-size" name="printer-paper-size">
                        <option value="">انتخاب اندازه کاغذ</option>
                      </select>
                    </label>
                    <p class="hint">هر اندازه سفارشی ثبت‌شده در سیستم به‌صورت پیش‌فرض استفاده می‌شود.</p>
                    <label class="field">
                      <span>تعداد صفحه در هر برگه</span>
                      <select id="printer-pages-per-paper" name="printer-pages-per-paper">
                        <option value="">انتخاب تعداد صفحه در هر برگه</option>
                      </select>
                    </label>
                    <label class="field">
                      <span>حاشیه</span>
                      <select id="printer-margin" name="printer-margin">
                        <option value="">انتخاب حاشیه</option>
                      </select>
                    </label>
                    <label class="field">
                      <span>مقیاس</span>
                      <select id="printer-scale" name="printer-scale">
                        <option value="">انتخاب مقیاس</option>
                      </select>
                    </label>
                  </form>
                  <div class="section-footer">
                    <button type="button" id="printer-settings-save" class="btn primary">ذخیره</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
