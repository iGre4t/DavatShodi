<section id="tab-features" class="tab">
  <div class="sub-layout" data-sub-layout>
    <aside class="sub-sidebar">
      <div class="sub-header">Features</div>
      <div class="sub-nav">
        <button type="button" class="sub-item active" data-pane="preview">
          Preview
        </button>
        <button type="button" class="sub-item" data-pane="notifications">
          اطلاع رسانی ها
        </button>
        <button type="button" class="sub-item" data-pane="cards">
          کارت‌ها
        </button>
        <button type="button" class="sub-item" data-pane="modals">
          Modals
        </button>
        <button type="button" class="sub-item" data-pane="code-editor">
          Code Editor
        </button>
      </div>
    </aside>
    <div class="sub-content">
      <div class="sub-pane active" data-pane="preview">
        <div class="card">
          <h3>Feature preview</h3>
          <p class="muted">
            This preview panel highlights the latest tools and updates slated for the admin experience. As new
            functionality ships, the preview will refresh to show real content before it becomes fully available.
          </p>
        </div>
      </div>
      <div class="sub-pane notifications-pane" data-pane="notifications" dir="rtl">
        <div class="card settings-section">
          <div class="section-header">
            <h3>اطلاع‌رسانی‌ها</h3>
          </div>
          <div class="section-footer notifications-footer">
            <button type="button" class="btn secondary" data-test-toast aria-label="تست توست">
              آزمایش توست
            </button>
            <button
              type="button"
              class="btn secondary"
              data-test-snackbar
              aria-label="تست اسنک‌بار"
            >
              تست اسنک‌بار
            </button>
            <button
              type="button"
              class="btn secondary"
              data-test-error-snackbar
              aria-label="اسنک‌بار خطا"
            >
              اسنک‌بار خطا
            </button>
          </div>
          <p class="hint">نمایش یک اعلان تست برای تست.</p>
        </div>
      </div>
      <div class="sub-pane cards-pane" data-pane="cards" dir="rtl">
        <div class="card default-card">
          <div class="default-card-header">
            <h3>پیش‌نمایش کارت</h3>
          </div>
          <div class="default-card-body">
            <p class="muted">
              این کارت نوشته‌ها، فاصله‌ها و دکمه‌های پیش‌فرضی را نشان می‌دهد که در سرتاسر داشبورد استفاده می‌شوند.
            </p>
          </div>
          <div class="default-card-actions">
            <button type="button" class="btn primary">اقدام اصلی</button>
            <button type="button" class="btn ghost">ثانویه</button>
          </div>
        </div>
      </div>
      <div class="sub-pane modals-pane" data-pane="modals" dir="rtl">
        <div class="card settings-section">
          <div class="section-header">
            <h3>نمونه مدال</h3>
          </div>
          <div class="section-footer cards-footer">
            <button type="button" class="btn primary" data-open-modals-preview>
              باز کردن مدال
            </button>
          </div>
        </div>
        <div class="card settings-section">
          <div class="section-header">
            <h3>Guide Note</h3>
          </div>
          <div class="default-card-body">
            <p class="muted">
              Modal header is at the top only (no × button) and the body pledge sits beneath it aligned to the top.
              The header should run right-to-left (RTL) so content follows the surrounding layout direction.
              The body area can grow up to 70vh and scrolls internally when content is long. Actions sit at the bottom,
              left-aligned in their own bar. Clicking outside the modal dismisses it without extra close buttons, and
              the reusable <code>.default-modal-card</code> style keeps these regions consistent.
              The modal has no 'x' button, and clicking outside closes it.
            </p>
          </div>
        </div>
      </div>
      <div class="sub-pane code-editor-pane" data-pane="code-editor" dir="rtl">
        <div class="card default-card code-editor-card">
          <div class="default-card-header">
            <h3>Code Editor</h3>
          </div>
          <div class="default-card-body">
            <label class="code-editor-title" for="code-editor-input">Code Editor</label>
            <div class="code-editor-wrapper">
              <textarea
                id="code-editor-input"
                class="code-editor"
                placeholder="Paste shell snippets here; keep the monospace vibe."
                rows="8"
                spellcheck="false"
                readonly
              ></textarea>
              <button type="button" class="icon-btn code-editor-copy-btn" data-copy-code aria-label="Copy code">
                <span class="ri ri-clipboard-line"></span>
                <span class="copy-label">Copy</span>
              </button>
            </div>
            <p class="hint">
              This monospace area mimics a shell-like buffer. Use the actions below to clear the buffer or pretend to
              run the code. It remembers your text until you clear it.
            </p>
            <label class="code-editor-title" for="code-editor-editable">Editable Code Editor</label>
            <textarea
              id="code-editor-editable"
              class="code-editor code-editor-editable"
              placeholder="Type freely in this version."
              rows="6"
              spellcheck="false"
            ></textarea>
            <p class="hint">
              This editable version lets you briefly experiment; it follows the same styling but keeps the caret visible.
            </p>
          </div>
          <div class="default-card-actions">
            <button type="button" class="btn primary" data-run-code>
              Run (no-op)
            </button>
            <button type="button" class="btn ghost" data-clear-code>
              Clear
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<div id="modals-preview-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="modals-preview-modal-title">
  <div class="modal-card default-modal-card">
    <div class="modal-card-header">
      <div class="modal-card-header-start">
        <h3 id="modals-preview-modal-title">پیش‌نمایش مدال</h3>
      </div>
    </div>
    <div class="modal-card-body">
      <p class="hint">
        این مدال نحوه نمایش پیام‌های قطعی، هشدارها یا فرم‌های کوچک را به شما نشان می‌دهد. دکمه‌های پایین مدال به
        طور خودکار آن را می‌بندند.
      </p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn ghost" data-close-modals-preview>انصراف</button>
      <button type="button" class="btn primary" data-close-modals-preview>تأیید</button>
    </div>
  </div>
</div>
