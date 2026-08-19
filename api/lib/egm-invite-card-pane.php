<?php
declare(strict_types=1);

function renderEgmInviteCardPane(string $storeEndpoint, bool $active = false): void
{
    $activeClass = $active ? ' active' : '';
    $endpoint = htmlspecialchars($storeEndpoint, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="sub-pane<?= $activeClass ?>" data-pane="egm-invite-card" data-egm-invite-card-pane="1" data-store-endpoint="<?= $endpoint ?>" data-qr-endpoint="modules/minor/QR%20Code%20Generator/generate.php">
      <div class="card egm-invite-card-artwork-card">
        <div class="section-header">
          <div>
            <h3>تصویر کارت دعوت</h3>
            <p class="muted small">تصویر مستقیماً برای همین EGM ذخیره می‌شود و وارد کتابخانه تصاویر نخواهد شد.</p>
          </div>
        </div>
        <input type="file" accept="image/png,image/jpeg,image/webp" data-invite-card-file hidden />
        <div class="egm-invite-card-upload-row">
          <button type="button" class="btn ghost" data-action="choose-invite-card-photo">انتخاب تصویر</button>
          <span class="muted small" data-invite-card-file-name>هنوز تصویری انتخاب نشده است.</span>
        </div>
        <div class="egm-invite-card-source" data-invite-card-source>
          <div class="egm-invite-card-empty">برای شروع یک تصویر PNG، JPG یا WebP انتخاب کنید.</div>
          <img data-invite-card-source-image alt="پیش‌نمایش تصویر کارت دعوت" hidden />
        </div>
        <div class="egm-invite-card-actions">
          <button type="button" class="btn primary standard-primary-button" data-action="open-invite-card-selection" disabled>Selection Tool</button>
          <div class="egm-invite-card-area-state" aria-live="polite">
            <span data-area-state="qr">ناحیه QR: تعیین نشده</span>
            <span data-area-state="text">ناحیه متن: تعیین نشده</span>
          </div>
        </div>
      </div>

      <div class="card egm-invite-card-content-card">
        <div class="section-header">
          <div>
            <h3>متن دعوت</h3>
            <p class="muted small">متن در همان ناحیه‌ای که با Selection Tool تعیین می‌کنید نمایش داده می‌شود.</p>
          </div>
        </div>
        <div class="form" style="gap:12px;">
          <div class="field full">
            <span>متن کارت دعوت</span>
            <div class="egm-invite-card-editor">
              <div class="egm-invite-card-editor-toolbar" role="toolbar" aria-label="ابزارهای ویرایش متن دعوت">
                <button type="button" class="btn ghost" data-editor-command="bold" title="Bold (Ctrl+B)" aria-label="Bold"><strong>B</strong></button>
                <button type="button" class="btn ghost" data-editor-command="insertUnorderedList" title="Bullet list (Ctrl+L)" aria-label="Bullet list">• List</button>
                <button type="button" class="btn ghost" data-editor-command="insertOrderedList" title="Numbered list" aria-label="Numbered list">1. List</button>
                <button type="button" class="btn ghost" data-editor-command="removeFormat" title="Remove formatting">پاک‌کردن فرمت</button>
                <div class="egm-invite-card-color-control">
                  <input type="color" value="#111827" data-invite-card-text-color aria-label="رنگ متن انتخاب‌شده" title="رنگ متن انتخاب‌شده" />
                  <button type="button" class="btn ghost" data-action="apply-invite-card-text-color">اعمال رنگ</button>
                  <button type="button" class="btn ghost" data-action="clear-invite-card-text-color">رنگ پیش‌فرض</button>
                </div>
                <div class="egm-invite-card-merge-control">
                  <select data-invite-card-merge-tag aria-label="انتخاب اطلاعات دعوت‌شونده">
                    <option value="[fullname]">[fullname] — نام کامل</option>
                    <option value="[firstname]">[firstname] — نام</option>
                    <option value="[lastname]">[lastname] — نام خانوادگی</option>
                    <option value="[nationalid]">[nationalid] — کد ملی</option>
                    <option value="[workid]">[workid] — کد پرسنلی</option>
                    <option value="[guestnumber]">[guestnumber] — شماره مهمان</option>
                    <option value="[phonenumber]">[phonenumber] — شماره تلفن</option>
                    <option value="[deputy]">[deputy] — معاونت</option>
                    <option value="[generaldepartment]">[generaldepartment] — اداره کل</option>
                    <option value="[department]">[department] — اداره</option>
                    <option value="[gender]">[gender] — جنسیت</option>
                    <option value="[postallevel]">[postallevel] — سطح پستی</option>
                    <option value="[score]">[score] — امتیاز</option>
                  </select>
                  <button type="button" class="btn ghost" data-action="insert-invite-card-merge-tag">درج متغیر</button>
                </div>
              </div>
              <div class="egm-invite-card-editor-surface" data-invite-card-editor contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="متن دعوت را بنویسید..."></div>
            </div>
            <div class="egm-invite-card-font-control">
              <input type="file" accept=".ttf,.otf,.woff,.woff2,font/ttf,font/otf,font/woff,font/woff2" data-invite-card-font-file hidden />
              <button type="button" class="btn ghost" data-action="choose-invite-card-font">بارگذاری فونت</button>
              <button type="button" class="btn ghost" data-action="remove-invite-card-font" hidden>حذف فونت</button>
              <span class="muted small" data-invite-card-font-status>فونت پیش‌فرض استفاده می‌شود.</span>
            </div>
            <span class="muted small">بخشی از متن را انتخاب کنید و Bold یا رنگ را اعمال کنید. فرمت متغیرها نیز پس از جایگزینی حفظ می‌شود.</span>
          </div>
          <div class="egm-invite-card-conditional-builder" data-invite-card-conditional-builder>
            <div class="egm-invite-card-conditional-heading">
              <div>
                <strong>متغیر شرطی</strong>
                <p class="muted small">بدون کدنویسی یک متغیر مثل <span dir="ltr">[code]</span> بسازید و خروجی آن را بر اساس اطلاعات دعوت‌شونده تعیین کنید.</p>
              </div>
            </div>
            <div class="egm-invite-card-conditional-grid">
              <label class="field">
                <span>نام متغیر</span>
                <input type="text" dir="ltr" value="code" maxlength="32" data-conditional-token placeholder="code" autocomplete="off" />
                <span class="muted small">فقط حروف انگلیسی، عدد و _؛ در متن به صورت <span data-conditional-token-preview dir="ltr">[code]</span></span>
              </label>
              <label class="field">
                <span>بررسی کدام اطلاعات؟</span>
                <select data-conditional-field>
                  <option value="gender">جنسیت</option>
                  <option value="firstname">نام</option>
                  <option value="lastname">نام خانوادگی</option>
                  <option value="fullname">نام کامل</option>
                  <option value="nationalid">کد ملی</option>
                  <option value="workid">کد پرسنلی</option>
                  <option value="guestnumber">شماره مهمان</option>
                  <option value="phonenumber">شماره تلفن</option>
                  <option value="deputy">معاونت</option>
                  <option value="generaldepartment">اداره کل</option>
                  <option value="department">اداره</option>
                  <option value="postallevel">سطح پستی</option>
                  <option value="score">امتیاز</option>
                </select>
              </label>
            </div>
            <div class="egm-invite-card-condition-labels" aria-hidden="true">
              <span>شرط</span><span>مقدار</span><span>متن جایگزین</span><span></span>
            </div>
            <div class="egm-invite-card-condition-rows" data-conditional-rules></div>
            <button type="button" class="btn ghost egm-invite-card-add-condition" data-action="add-invite-card-condition">+ افزودن شرط If / Else If</button>
            <label class="field full egm-invite-card-conditional-fallback">
              <span>Else — اگر هیچ شرطی برقرار نبود (اختیاری)</span>
              <input type="text" data-conditional-fallback maxlength="500" placeholder="مثلاً: مهمان گرامی" autocomplete="off" />
            </label>
            <div class="egm-invite-card-conditional-actions">
              <button type="button" class="btn primary standard-primary-button" data-action="save-invite-card-condition">ثبت متغیر شرطی</button>
              <button type="button" class="btn ghost" data-action="save-invite-card-condition-and-insert">ثبت و درج در متن</button>
              <button type="button" class="btn ghost" data-action="reset-invite-card-condition">فرم جدید</button>
            </div>
            <p class="muted small" data-conditional-status aria-live="polite"></p>
            <div class="egm-invite-card-conditional-list" data-conditional-list></div>
          </div>
          <div class="field full">
            <span>دعوت‌شونده برای Test Generate</span>
            <div class="egm-invite-card-invitee-picker">
              <input type="search" data-invite-card-invitee-search placeholder="جستجو با نام، کد ملی یا کد پرسنلی..." autocomplete="off" />
              <select data-invite-card-invitee-select aria-label="انتخاب دعوت‌شونده">
                <option value="">ابتدا دعوت‌شونده را انتخاب کنید</option>
              </select>
            </div>
            <span class="muted small" data-invite-card-invitee-status aria-live="polite"></span>
          </div>
          <label class="field full">
            <span>محتوای QR</span>
            <input type="text" dir="ltr" value="[nationalid]" data-invite-card-qr-data readonly />
            <span class="muted small">QR همیشه کد ملی ۱۰ رقمی دعوت‌شونده است و قابل تغییر نیست.</span>
          </label>
          <div class="egm-invite-card-command-row">
            <button type="button" class="btn ghost" data-action="save-invite-card">ذخیره تنظیمات</button>
            <button type="button" class="btn primary standard-primary-button" data-action="test-generate-invite-card">Test Generate</button>
          </div>
          <p class="muted small" data-invite-card-autosave-status aria-live="polite">ذخیره خودکار پس از بارگذاری اطلاعات فعال می‌شود.</p>
          <p class="muted small" data-invite-card-status aria-live="polite"></p>
        </div>
      </div>

      <div class="card egm-invite-card-preview-card">
        <div class="section-header">
          <div>
            <h3>پیش‌نمایش کارت دعوت</h3>
            <p class="muted small">خروجی یک تصویر PNG واقعی با ابعاد کامل تصویر اصلی است؛ QR و متن داخل خود فایل تصویر قرار می‌گیرند.</p>
          </div>
        </div>
        <div class="egm-invite-card-preview-empty" data-invite-card-preview-empty>برای مشاهده خروجی روی Test Generate کلیک کنید.</div>
        <div class="egm-invite-card-preview" data-invite-card-preview hidden>
          <img data-invite-card-output-image alt="تصویر نهایی کارت دعوت" />
        </div>
        <div class="egm-invite-card-export-row" data-invite-card-export-row hidden>
          <span class="muted small" data-invite-card-output-meta></span>
          <a class="btn primary standard-primary-button" data-invite-card-download download="invite-card.png">دانلود تصویر PNG</a>
        </div>
      </div>

      <div class="egm-invite-card-modal hidden" data-invite-card-selection-modal aria-hidden="true" role="dialog" aria-modal="true" aria-label="Selection Tool">
        <div class="egm-invite-card-modal-backdrop" data-action="cancel-invite-card-selection"></div>
        <div class="egm-invite-card-modal-panel">
          <div class="section-header egm-invite-card-modal-header">
            <div>
              <h3>Selection Tool</h3>
              <p class="muted small">نوع ناحیه را انتخاب کنید، سپس روی تصویر بکشید و یک کادر بسازید.</p>
            </div>
            <button type="button" class="btn ghost" data-action="cancel-invite-card-selection" aria-label="بستن">بستن</button>
          </div>
          <div class="egm-invite-card-tool-row" role="toolbar" aria-label="ابزارهای تعیین ناحیه">
            <button type="button" class="btn primary standard-primary-button active" data-selection-tool="qr" aria-pressed="true">ناحیه QR Code</button>
            <button type="button" class="btn ghost" data-selection-tool="text" aria-pressed="false">ناحیه Invite Text Area</button>
            <button type="button" class="btn ghost" data-action="clear-current-selection">پاک‌کردن ناحیه فعال</button>
          </div>
          <div class="egm-invite-card-selection-scroll">
            <div class="egm-invite-card-selection-stage" data-invite-card-selection-stage>
              <img data-invite-card-selection-image alt="تصویر کارت دعوت برای تعیین ناحیه" draggable="false" />
              <div class="egm-invite-card-selection-layer" data-invite-card-selection-layer>
                <div class="egm-invite-card-selection-box is-qr" data-selection-box="qr" hidden><span>QR Code</span></div>
                <div class="egm-invite-card-selection-box is-text" data-selection-box="text" hidden><span>Invite Text Area</span></div>
                <div class="egm-invite-card-selection-box is-draft" data-selection-draft hidden></div>
              </div>
            </div>
          </div>
          <p class="muted small" data-selection-help aria-live="polite">ابزار QR Code فعال است.</p>
          <div class="egm-invite-card-modal-footer">
            <button type="button" class="btn ghost" data-action="cancel-invite-card-selection">انصراف</button>
            <button type="button" class="btn primary standard-primary-button" data-action="confirm-invite-card-selection">تأیید نواحی</button>
          </div>
        </div>
      </div>
    </div>
    <?php
}
