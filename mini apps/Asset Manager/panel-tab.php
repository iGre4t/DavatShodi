<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/tab-permissions.php';
requireTabPermissionFromSession('asset-manager', false);

$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$allowedAssetPermissions = resolveAllowedPanelChildTabsForUser($sessionUser, 'asset-manager');
$assetPaneOrder = [
  'asset-manager:assets',
  'asset-manager:storages',
  'asset-manager:ancestors',
  'asset-manager:labels',
  'asset-manager:permissions'
];
$visibleAssetPanes = [];
foreach ($assetPaneOrder as $permissionId) {
  if (in_array($permissionId, $allowedAssetPermissions, true)) {
    $visibleAssetPanes[] = explode(':', $permissionId, 2)[1] ?? '';
  }
}
$activeAssetPane = $visibleAssetPanes[0] ?? '';
?>

        <section id="tab-asset-manager" class="tab">
          <div class="sub-layout pm-layout" data-pm-root>
            <aside class="sub-sidebar">
              <div class="sub-header">مدیریت اموال</div>
              <div class="sub-nav">
                <?php if (in_array('assets', $visibleAssetPanes, true)): ?>
                  <button type="button" class="sub-item<?= $activeAssetPane === 'assets' ? ' active' : '' ?>" data-pm-pane-target="assets">
                    افزودن مال
                  </button>
                <?php endif; ?>
                <?php if (in_array('storages', $visibleAssetPanes, true)): ?>
                  <button type="button" class="sub-item<?= $activeAssetPane === 'storages' ? ' active' : '' ?>" data-pm-pane-target="storages">
                    افزودن انبار
                  </button>
                <?php endif; ?>
                <?php if (in_array('ancestors', $visibleAssetPanes, true)): ?>
                  <button type="button" class="sub-item<?= $activeAssetPane === 'ancestors' ? ' active' : '' ?>" data-pm-pane-target="ancestors">
                    افزودن مال مرسوم
                  </button>
                <?php endif; ?>
                <?php if (in_array('labels', $visibleAssetPanes, true)): ?>
                  <button type="button" class="sub-item<?= $activeAssetPane === 'labels' ? ' active' : '' ?>" data-pm-pane-target="labels">
                    برچسب‌ها
                  </button>
                <?php endif; ?>
                <?php if (in_array('permissions', $visibleAssetPanes, true)): ?>
                  <button type="button" class="sub-item<?= $activeAssetPane === 'permissions' ? ' active' : '' ?>" data-pm-pane-target="permissions">
                    دسترسی‌ها
                  </button>
                <?php endif; ?>
              </div>
            </aside>
            <div class="sub-content">
              <?php if (empty($visibleAssetPanes)): ?>
                <div class="card settings-section">
                  <p class="hint">شما دسترسی به هیچ زیرتب مدیریت اموال ندارید.</p>
                </div>
              <?php endif; ?>
              <?php if (in_array('assets', $visibleAssetPanes, true)): ?>
              <div class="sub-pane<?= $activeAssetPane === 'assets' ? ' active' : '' ?>" data-pm-pane="assets">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>افزودن مال</h3>
                  </div>
                  <form id="pm-add-asset-form" class="form">
                    <div class="grid">
                      <label class="field">
                        <span>مال ویژه</span>
                        <span class="pm-switch">
                          <input id="pm-asset-special" type="checkbox" />
                          <span>فعال</span>
                        </span>
                      </label>
                      <label class="field" id="pm-asset-ancestor-field">
                        <span>مال مرسوم</span>
                        <select id="pm-asset-ancestor" required></select>
                      </label>
                      <label class="field hidden" id="pm-asset-name-field">
                        <span>نام مال ویژه</span>
                        <input id="pm-asset-name" type="text" disabled />
                      </label>
                      <label class="field">
                        <span>کد مال</span>
                        <input id="pm-asset-code" type="text" required />
                      </label>
                      <label class="field">
                        <span>انبار</span>
                        <select id="pm-asset-storage" required></select>
                      </label>
                      <div
                        id="pm-asset-label-values"
                        class="grid one-column hidden"
                        style="grid-column: 1 / -1;"
                      ></div>
                    </div>
                    <div class="section-footer">
                      <button type="submit" class="btn primary">افزودن مال</button>
                    </div>
                    <p id="pm-asset-status" class="hint pm-status" aria-live="polite"></p>
                  </form>
                </div>
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>اموال</h3>
                  </div>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <th>نام/مال مرسوم</th>
                          <th>کد مال</th>
                          <th>انبار</th>
                          <th>برچسب‌ها</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="pm-assets-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <?php if (in_array('storages', $visibleAssetPanes, true)): ?>
              <div class="sub-pane<?= $activeAssetPane === 'storages' ? ' active' : '' ?>" data-pm-pane="storages">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>افزودن انبار</h3>
                  </div>
                  <form id="pm-add-storage-form" class="form">
                    <div class="grid one-column">
                      <label class="field">
                        <span>نام انبار</span>
                        <input id="pm-storage-name" type="text" required />
                      </label>
                      <label class="field">
                        <span>نوع انبار</span>
                        <select id="pm-storage-kind" required>
                          <option value="branch">شعبه</option>
                          <option value="person">شخص</option>
                          <option value="repair_shop">تعمیرگاه</option>
                        </select>
                      </label>
                    </div>
                    <div class="section-footer">
                      <button type="submit" class="btn primary">افزودن انبار</button>
                    </div>
                    <p id="pm-storage-status" class="hint pm-status" aria-live="polite"></p>
                  </form>
                </div>
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>انبارها</h3>
                  </div>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <th>نام انبار</th>
                          <th>نوع انبار</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="pm-storages-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <?php if (in_array('ancestors', $visibleAssetPanes, true)): ?>
              <div class="sub-pane<?= $activeAssetPane === 'ancestors' ? ' active' : '' ?>" data-pm-pane="ancestors">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>افزودن مال مرسوم</h3>
                  </div>
                  <form id="pm-add-ancestor-form" class="form">
                    <div class="grid one-column">
                      <label class="field">
                        <span>نام مال مرسوم</span>
                        <input id="pm-ancestor-name" type="text" required />
                      </label>
                      <div class="field">
                        <span>زنجیره برچسب</span>
                        <div id="pm-ancestor-label-chain" class="grid one-column"></div>
                      </div>
                    </div>
                    <div class="section-footer">
                      <button type="submit" class="btn primary">افزودن مال مرسوم</button>
                    </div>
                    <p id="pm-ancestor-status" class="hint pm-status" aria-live="polite"></p>
                  </form>
                </div>
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>اموال مرسوم</h3>
                  </div>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <th>نام مال مرسوم</th>
                          <th>برچسب‌ها</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="pm-ancestors-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <?php if (in_array('labels', $visibleAssetPanes, true)): ?>
              <div class="sub-pane<?= $activeAssetPane === 'labels' ? ' active' : '' ?>" data-pm-pane="labels">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>افزودن برچسب</h3>
                  </div>
                  <form id="pm-add-label-form" class="form">
                    <div class="grid one-column">
                      <label class="field">
                        <span>نام برچسب</span>
                        <input id="pm-label-name" type="text" required />
                      </label>
                      <label class="field">
                        <span>برچسب والد</span>
                        <select id="pm-label-parent"></select>
                      </label>
                    </div>
                    <div class="section-footer">
                      <button type="submit" class="btn primary">افزودن برچسب</button>
                    </div>
                    <p id="pm-label-status" class="hint pm-status" aria-live="polite"></p>
                  </form>
                </div>
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>برچسب‌ها</h3>
                  </div>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <th>نام برچسب</th>
                          <th>برچسب والد</th>
                          <th>عملیات</th>
                        </tr>
                      </thead>
                      <tbody id="pm-labels-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <?php if (in_array('permissions', $visibleAssetPanes, true)): ?>
              <div class="sub-pane<?= $activeAssetPane === 'permissions' ? ' active' : '' ?>" data-pm-pane="permissions">
                <div class="card settings-section">
                  <div class="section-header">
                    <h3>دسترسی کاربران به انبارها</h3>
                  </div>
                  <p class="hint">
                    این دسترسی‌ها مخصوص ربات تلگرام است. جستجوی کد مال برای همه انبارها فعال است، اما ویرایش و انتقال فقط برای انبارهای مجاز هر کاربر انجام می‌شود.
                  </p>
                  <p id="pm-permissions-status" class="hint pm-status" aria-live="polite"></p>
                  <div class="table-wrapper">
                    <table>
                      <thead>
                        <tr>
                          <th>کاربر</th>
                          <th>انبارهای مجاز</th>
                          <th>هیات مدیره</th>
                        </tr>
                      </thead>
                      <tbody id="pm-permissions-body"></tbody>
                    </table>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </section>
