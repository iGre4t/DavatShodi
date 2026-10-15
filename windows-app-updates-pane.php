<?php
require_once __DIR__ . '/api/lib/windows-releases.php';
requireTabPermissionFromSession('devsettings:panel-settings', false);
if (empty($_SESSION['windows_release_csrf'])) $_SESSION['windows_release_csrf'] = bin2hex(random_bytes(32));
$windowsRelease = windowsReleaseRead();
?>
<div class="card settings-section" data-windows-releases>
 <div class="section-header"><h3>برنامه ویندوز</h3></div>
 <div class="form grid">
  <div><h4>نسخه منتشرشده</h4><p data-release-version><?= htmlspecialchars($windowsRelease['version'] ?: 'منتشر نشده', ENT_QUOTES, 'UTF-8') ?></p></div>
  <div><a class="btn ghost" data-release-download <?= empty($windowsRelease['url']) ? 'hidden' : '' ?> href="<?= htmlspecialchars($windowsRelease['url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>">دانلود آخرین نسخه</a></div>
 </div>
 <hr>
 <form data-release-form>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['windows_release_csrf'], ENT_QUOTES, 'UTF-8') ?>">
  <div class="form grid">
   <label class="field"><span>نسخه جدید</span><input name="version" required pattern="[1-9][0-9]*\.[0-9]+\.[0-9]+" placeholder="3.1.0" dir="ltr"></label>
   <label class="field"><span>فایل برنامه</span><input type="file" name="build" required accept=".exe,.zip"></label>
   <label class="field" style="grid-column:1/-1"><span>تغییرات این نسخه</span><textarea name="notes" rows="3" maxlength="3000"></textarea></label>
  </div>
  <p class="hint">فایل مستقل exe یا زیپ حاوی همان یک فایل. حداکثر آپلود سرور: <?= htmlspecialchars(ini_get('upload_max_filesize'), ENT_QUOTES, 'UTF-8') ?>.</p>
  <div class="section-footer"><button type="submit" class="btn primary">انتشار نسخه جدید</button></div>
  <p role="status" data-release-status></p>
 </form>
</div>
<script src="assets/windows-app-updates.js?v=<?= filemtime(__DIR__.'/assets/windows-app-updates.js') ?>"></script>
