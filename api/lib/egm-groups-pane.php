<?php
declare(strict_types=1);

function renderEgmGroupsPane(string $endpoint, bool $active = false): void
{
    ?>
    <div class="sub-pane<?= $active ? ' active' : '' ?>" data-pane="egm-groups" data-egm-groups-pane data-endpoint="<?= htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') ?>" dir="rtl">
      <div class="card egm-groups-card">
        <div class="section-header"><div><h3>گروه‌ها</h3><p class="muted">گروه‌ها تعیین می‌کنند هنگام ورود هر مهمان دقیقاً کدام کارت‌ها و بلیت‌ها چاپ شوند.</p></div></div>
        <form class="egm-groups-create" data-egm-group-create>
          <label class="field"><span>عنوان گروه جدید</span><input name="title" type="text" maxlength="100" placeholder="مثلاً گروه A" required /></label>
          <button type="submit" class="btn primary">افزودن گروه</button>
        </form>
        <p class="hint" data-egm-groups-status aria-live="polite"></p>
      </div>
      <div class="card egm-groups-card">
        <div class="section-header"><h3>فهرست گروه‌ها</h3><button type="button" class="btn ghost" data-egm-groups-refresh>بازخوانی</button></div>
        <div class="table-wrapper"><table><thead><tr><th>عنوان</th><th>خروجی‌های ورود</th><th>عملیات</th></tr></thead><tbody data-egm-groups-list><tr><td colspan="3" class="muted">در حال دریافت…</td></tr></tbody></table></div>
      </div>
      <div class="egm-groups-modal" data-egm-groups-modal hidden>
        <div class="egm-groups-dialog" role="dialog" aria-modal="true" aria-labelledby="egm-group-dialog-title">
          <div class="section-header"><h3 id="egm-group-dialog-title">تنظیمات گروه</h3><button type="button" class="btn ghost" data-egm-group-close>بستن</button></div>
          <form data-egm-group-settings>
            <input type="hidden" name="id" />
            <label class="field"><span>عنوان گروه</span><input name="title" type="text" maxlength="100" required /></label>
            <fieldset class="egm-groups-output-fieldset"><legend>موارد قابل چاپ پس از ورود موفق</legend><div data-egm-group-outputs></div></fieldset>
            <p class="hint">این انتخاب‌ها برای اعضای گروه جایگزین همه کلیدهای پیش‌فرض چاپ می‌شوند و خروجی‌های انتخاب‌شده خودکار وارد صف چاپ خواهند شد. اگر هیچ موردی فعال نباشد، برای اعضای این گروه چیزی چاپ نمی‌شود.</p>
            <div class="egm-groups-dialog-actions"><button type="submit" class="btn primary">ذخیره تنظیمات</button><button type="button" class="btn danger" data-egm-group-delete>حذف گروه</button></div>
          </form>
        </div>
      </div>
    </div>
    <?php
}
