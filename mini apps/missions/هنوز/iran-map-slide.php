<?php // Included inside the authenticated Task Club phone layout. ?>
<section id="tc-iran-map-slide" class="tc-iran-map-slide" role="dialog" aria-modal="true" aria-label="نقشه ایران" hidden>
  <div class="main-area tc-iran-map-content">
    <?php if ($eventLogoUrl !== ''): ?>
      <img class="task-event-logo" src="<?= htmlspecialchars($eventLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگوی رویداد" />
    <?php endif; ?>
    <div class="tc-iran-map-art" aria-label="نقشه ایران">
      <?php readfile(__DIR__ . '/iran-map.svg'); ?>
    </div>
    <p id="tc-iran-map-status" class="tasks-empty" aria-live="polite"></p>
  </div>
  <div class="tc-bottom-cta">
    <button id="tc-iran-map-back" class="tc-bottom-cta-btn" type="button">برگشت</button>
  </div>
</section>
<style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
  .tc-iran-map-slide[hidden] { display: none; }
  .tc-iran-map-slide {
    position: absolute;
    inset: 0;
    z-index: 60;
    display: flex;
    flex-direction: column;
    background: var(--phone, #f6faff);
    animation: tcIranMapEnter 220ms ease-out;
  }
  .tc-iran-map-content.main-area {
    min-height: 0;
    overflow-y: auto;
    justify-content: flex-start;
    padding: 24px 18px 90px;
  }
  .tc-iran-map-content .task-event-logo { flex-shrink: 0; }
  .tc-iran-map-art {
    width: 100%;
    flex: 1;
    min-height: 160px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .tc-iran-map-art svg { display: block; width: 100%; max-height: 100%; }
  .tc-iran-map-art path { fill: none; stroke: var(--tc-secondary, #2f8fff); }
  .tc-iran-map-slide .tc-bottom-cta { padding-bottom: max(14px, env(safe-area-inset-bottom)); }
  #tc-open-iran-map { flex-shrink: 0; margin-bottom: 12px; }
  #tc-tasks-list { padding-bottom: 200px; }
  .tc-iran-map-dot { fill: var(--tc-secondary, #2f8fff); stroke: none; }
  .tc-iran-map-dot-new { animation: tcIranDotAppear 600ms ease-out; transform-box: fill-box; transform-origin: center; }
  @keyframes tcIranDotAppear { from { opacity: 0; transform: scale(0); } to { opacity: 1; transform: scale(1); } }
  @media (prefers-reduced-motion: reduce) { .tc-iran-map-dot-new { animation: none; } }
  @keyframes tcIranMapEnter { from { transform: translateX(100%); } to { transform: translateX(0); } }
  @media (prefers-reduced-motion: reduce) { .tc-iran-map-slide { animation: none; } }
</style>
<script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>"><?php readfile(__DIR__ . '/iran-map.js'); ?></script>
