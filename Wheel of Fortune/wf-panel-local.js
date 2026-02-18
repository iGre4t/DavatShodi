(() => {
  function initWheelSubLayouts() {
    const layouts = document.querySelectorAll('[data-wf-sub-layout]');
    layouts.forEach((layout) => {
      if (!(layout instanceof HTMLElement)) return;
      if (layout.dataset.wfSubLayoutReady === '1') return;
      layout.dataset.wfSubLayoutReady = '1';

      const nav = layout.querySelector('.sub-nav');
      if (!(nav instanceof HTMLElement)) return;

      nav.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const trigger = target.closest('.sub-item[data-pane]');
        if (!(trigger instanceof HTMLElement)) return;

        const targetPane = trigger.dataset.pane;
        if (!targetPane) return;

        event.preventDefault();

        nav.querySelectorAll('.sub-item').forEach((item) => {
          item.classList.toggle('active', item === trigger);
        });

        layout.querySelectorAll('.sub-pane').forEach((pane) => {
          if (!(pane instanceof HTMLElement)) return;
          pane.classList.toggle('active', pane.dataset.pane === targetPane);
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWheelSubLayouts);
  } else {
    initWheelSubLayouts();
  }
})();
