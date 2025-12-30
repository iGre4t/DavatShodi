<?php
$fontsDir = __DIR__ . '/style/fonts';
$allowedFormats = [
  'ttf' => 'truetype',
  'otf' => 'opentype',
  'woff' => 'woff',
  'woff2' => 'woff2',
  'eot' => 'embedded-opentype',
  'svg' => 'svg'
];
$fonts = [];
if (is_dir($fontsDir)) {
  foreach (scandir($fontsDir, SCANDIR_SORT_ASCENDING) as $file) {
    if ($file === '.' || $file === '..') {
      continue;
    }
    $path = $fontsDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
      continue;
    }
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($allowedFormats[$extension])) {
      continue;
    }
    $fonts[] = [
      'name' => $file,
      'url' => 'style/fonts/' . rawurlencode($file),
      'format' => $allowedFormats[$extension]
    ];
  }
}
usort($fonts, static function ($first, $second) {
  return strcasecmp($first['name'], $second['name']);
});
foreach ($fonts as $index => &$font) {
  $font['family'] = 'typography-preview-' . $index;
}
unset($font);
$previewText = 'کلاغ پیر رفت به گردش';
?>
<style>
  <?php if (!empty($fonts)): ?>
    <?php foreach ($fonts as $font): ?>
      @font-face {
        font-family: '<?= $font['family'] ?>';
        src: url('<?= htmlspecialchars($font['url'], ENT_QUOTES, 'UTF-8') ?>') format('<?= htmlspecialchars($font['format'], ENT_QUOTES, 'UTF-8') ?>');
        font-weight: 400;
        font-style: normal;
      }
    <?php endforeach; ?>
  <?php endif; ?>
  .tab-typography .font-preview-text {
    display: block;
    font-size: 18px;
    line-height: 1.6;
  }
  .tab-typography table td {
    vertical-align: middle;
  }
  .tab-typography .action-cell {
    text-align: left;
    direction: ltr;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
</style>
<section id="tab-typography" class="tab">
  <div class="cards">
    <div class="card">
      <div class="table-header">
        <h3>Upload font</h3>
      </div>
      <form
        class="form"
        action="api/data.php"
        method="post"
        enctype="multipart/form-data"
        novalidate
      >
        <input type="hidden" name="action" value="upload_font" />
        <div class="photo-uploader font-uploader">
          <div class="photo-preview" aria-live="polite">
            <div class="photo-placeholder">Choose a font file to upload</div>
          </div>
          <div class="photo-actions">
            <input
              type="file"
              id="typography-font-input"
              name="font_file"
              accept=".woff,.woff2,.ttf,.otf,.eot,.svg"
              hidden
            />
            <label for="typography-font-input" class="btn ghost small">Choose file</label>
            <button type="submit" class="btn primary">Upload</button>
          </div>
          <p class="muted">Supported: WOFF, WOFF2, TTF, OTF, EOT, SVG.</p>
        </div>
      </form>
    </div>
    <div class="card">
      <div class="table-header">
        <h3>Fonts List</h3>
      </div>
      <div class="table-wrapper">
        <table class="fonts-table">
          <thead>
            <tr>
              <th>Font name</th>
              <th>Preview</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($fonts)): ?>
              <tr>
                <td colspan="3" class="muted">No fonts available in style/fonts.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($fonts as $font): ?>
                <tr>
                  <td><?= htmlspecialchars($font['name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <span
                      class="font-preview-text"
                      style="font-family: '<?= htmlspecialchars($font['family'], ENT_QUOTES, 'UTF-8') ?>';"
                    >
                      <?= htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </td>
                  <td class="action-cell">
                    <button
                      type="button"
                      class="btn ghost small"
                      aria-label="Delete <?= htmlspecialchars($font['name'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                      <span class="ri ri-delete-bin-line" aria-hidden="true"></span>
                      <span>Delete</span>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
