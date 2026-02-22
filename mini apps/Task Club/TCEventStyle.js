(() => {
  const API_URL = 'mini%20apps/Task%20Club/tc_store.php';
  const tcShellEl = document.querySelector('.tc-shell');
  const csrfToken = tcShellEl instanceof HTMLElement
    ? String(tcShellEl.dataset.tcCsrf || '').trim()
    : '';

  const DEFAULT_COLORS = {
    secondary: '#2F8FFF',
    highlight: '#20C997',
    accentSoft: '#FFB347'
  };

  const refs = {
    logoImage: null,
    logoPlaceholder: null,
    logoPickBtn: null,
    logoClearBtn: null,
    logoStatus: null,
    colorInputs: {},
    colorPreviews: {},
    colorPickers: {},
    saveBtn: null,
    saveStatus: null
  };

  const state = {
    logo: '',
    colors: { ...DEFAULT_COLORS }
  };

  function isHexColor(value) {
    return /^#[0-9A-F]{6}$/.test(String(value || '').toUpperCase());
  }

  function normalizeHex(value, fallback = '') {
    const color = String(value || '').trim().toUpperCase();
    if (isHexColor(color)) return color;
    const safeFallback = String(fallback || '').trim().toUpperCase();
    return isHexColor(safeFallback) ? safeFallback : '';
  }

  function normalizeLogoPath(value) {
    return String(value || '').trim();
  }

  function normalizeLogoStoredValue(value) {
    const token = normalizeLogoPath(value);
    if (!token) return '';
    if (/^(?:https?:|data:|\/)/i.test(token)) return token;
    if (token.includes('/')) return token;
    return `uploads/gallery/${token}`;
  }

  function buildLogoUrl(value) {
    const token = normalizeLogoPath(value);
    if (!token) return '';
    if (/^(?:https?:|data:|\/)/i.test(token) || token.includes('/')) {
      return token;
    }
    return `uploads/gallery/${encodeURIComponent(token)}`;
  }

  function setStatus(message, isError = false) {
    if (!(refs.saveStatus instanceof HTMLElement)) return;
    refs.saveStatus.textContent = String(message || '').trim();
    refs.saveStatus.style.color = isError ? '#d1434a' : '';
  }

  function setLogoStatus(message, isError = false) {
    if (!(refs.logoStatus instanceof HTMLElement)) return;
    refs.logoStatus.textContent = String(message || '').trim();
    refs.logoStatus.style.color = isError ? '#d1434a' : '';
  }

  function updateLogoPreview() {
    const url = buildLogoUrl(state.logo);
    const hasLogo = url !== '';

    if (refs.logoImage instanceof HTMLImageElement) {
      if (hasLogo) {
        refs.logoImage.src = url;
        refs.logoImage.classList.remove('hidden');
      } else {
        refs.logoImage.removeAttribute('src');
        refs.logoImage.classList.add('hidden');
      }
    }

    if (refs.logoPlaceholder instanceof HTMLElement) {
      refs.logoPlaceholder.classList.toggle('hidden', hasLogo);
    }

    if (refs.logoClearBtn instanceof HTMLButtonElement) {
      refs.logoClearBtn.disabled = !hasLogo;
    }
  }

  function updateColorPreview(key) {
    const color = normalizeHex(state.colors[key], DEFAULT_COLORS[key] || '#2F8FFF');
    state.colors[key] = color;

    const input = refs.colorInputs[key];
    if (input instanceof HTMLInputElement) {
      input.value = color;
    }

    const preview = refs.colorPreviews[key];
    if (preview instanceof HTMLElement) {
      preview.style.background = color;
    }
  }

  function applySettings(settings) {
    const source = settings && typeof settings === 'object' ? settings : {};
    state.logo = normalizeLogoPath(source.eventLogo || '');
    const sourceColors = source.eventColors && typeof source.eventColors === 'object'
      ? source.eventColors
      : {};
    state.colors.secondary = normalizeHex(sourceColors.secondary, DEFAULT_COLORS.secondary);
    state.colors.highlight = normalizeHex(sourceColors.highlight, DEFAULT_COLORS.highlight);
    state.colors.accentSoft = normalizeHex(sourceColors.accentSoft, DEFAULT_COLORS.accentSoft);

    updateLogoPreview();
    updateColorPreview('secondary');
    updateColorPreview('highlight');
    updateColorPreview('accentSoft');
  }

  async function getSettings() {
    const response = await fetch(`${API_URL}?action=get_settings`, { credentials: 'same-origin' });
    const payload = await response.json();
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'Failed to load settings.');
    }
    return payload.data && typeof payload.data === 'object' ? payload.data : {};
  }

  async function saveSettings(mergedSettings) {
    const response = await fetch(`${API_URL}?action=save_settings`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ settings: mergedSettings, csrf: csrfToken })
    });
    const payload = await response.json();
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'Failed to save settings.');
    }
    return payload;
  }

  function openLogoChooser() {
    if (typeof window.openPhotoChooserModal !== 'function') {
      setLogoStatus('Photo chooser is not available.', true);
      return;
    }

    setLogoStatus('');
    window.openPhotoChooserModal({
      allowMultiple: false,
      onChoose: (selectedPhotos = []) => {
        const photo = selectedPhotos[0] || null;
        if (!photo || typeof photo !== 'object') {
          return;
        }
        const filename = normalizeLogoPath(photo.filename || photo.url || '');
        if (!filename) {
          setLogoStatus('Invalid photo selected.', true);
          return;
        }
        state.logo = filename;
        updateLogoPreview();
        void persistLogoOnly('Logo saved.');
      }
    });
  }

  function openColorPicker(key, title) {
    const current = normalizeHex(state.colors[key], DEFAULT_COLORS[key]);
    if (typeof window.openStyleColorPicker === 'function') {
      window.openStyleColorPicker(
        key,
        current,
        (nextColor) => {
          state.colors[key] = normalizeHex(nextColor, current);
          updateColorPreview(key);
        },
        title
      );
      return;
    }

    const fallbackColor = window.prompt('Enter hex color (#RRGGBB)', current);
    state.colors[key] = normalizeHex(fallbackColor, current);
    updateColorPreview(key);
  }

  async function handleSave() {
    if (!(refs.saveBtn instanceof HTMLButtonElement)) return;

    refs.saveBtn.disabled = true;
    setStatus('Saving...');

    try {
      const current = await getSettings();
      const merged = {
        ...current,
        eventLogo: normalizeLogoStoredValue(state.logo),
        eventColors: {
          secondary: normalizeHex(state.colors.secondary, DEFAULT_COLORS.secondary),
          highlight: normalizeHex(state.colors.highlight, DEFAULT_COLORS.highlight),
          accentSoft: normalizeHex(state.colors.accentSoft, DEFAULT_COLORS.accentSoft)
        }
      };
      await saveSettings(merged);
      setStatus('Event style saved.');
      if (typeof window.showDefaultToast === 'function') {
        window.showDefaultToast('Event style saved.');
      }
    } catch (error) {
      setStatus(error?.message || 'Failed to save settings.', true);
    } finally {
      refs.saveBtn.disabled = false;
    }
  }

  async function persistLogoOnly(successMessage = 'Logo saved.') {
    try {
      const current = await getSettings();
      const merged = {
        ...current,
        eventLogo: normalizeLogoStoredValue(state.logo)
      };
      await saveSettings(merged);
      setLogoStatus(successMessage);
    } catch (error) {
      setLogoStatus(error?.message || 'Failed to save logo.', true);
    }
  }

  function bindInputs() {
    Object.entries(refs.colorInputs).forEach(([key, input]) => {
      if (!(input instanceof HTMLInputElement)) return;
      input.addEventListener('input', () => {
        const normalized = normalizeHex(input.value, state.colors[key] || DEFAULT_COLORS[key]);
        state.colors[key] = normalized;
        updateColorPreview(key);
      });
      input.addEventListener('blur', () => {
        state.colors[key] = normalizeHex(input.value, DEFAULT_COLORS[key]);
        updateColorPreview(key);
      });
    });

    refs.logoPickBtn?.addEventListener('click', openLogoChooser);
    refs.logoClearBtn?.addEventListener('click', () => {
      state.logo = '';
      updateLogoPreview();
      void persistLogoOnly('Logo removed.');
    });

    refs.colorPickers.secondary?.addEventListener('click', () => openColorPicker('secondary', 'Choose secondary color'));
    refs.colorPickers.highlight?.addEventListener('click', () => openColorPicker('highlight', 'Choose highlight color'));
    refs.colorPickers.accentSoft?.addEventListener('click', () => openColorPicker('accentSoft', 'Choose soft accent color'));

    refs.colorPreviews.secondary?.addEventListener('click', () => openColorPicker('secondary', 'Choose secondary color'));
    refs.colorPreviews.highlight?.addEventListener('click', () => openColorPicker('highlight', 'Choose highlight color'));
    refs.colorPreviews.accentSoft?.addEventListener('click', () => openColorPicker('accentSoft', 'Choose soft accent color'));

    refs.saveBtn?.addEventListener('click', () => {
      void handleSave();
    });
  }

  async function init() {
    refs.logoImage = document.getElementById('tc-event-logo-image');
    refs.logoPlaceholder = document.getElementById('tc-event-logo-placeholder');
    refs.logoPickBtn = document.getElementById('tc-event-logo-pick');
    refs.logoClearBtn = document.getElementById('tc-event-logo-clear');
    refs.logoStatus = document.getElementById('tc-event-style-logo-status');

    refs.colorInputs = {
      secondary: document.getElementById('tc-event-color-secondary'),
      highlight: document.getElementById('tc-event-color-highlight'),
      accentSoft: document.getElementById('tc-event-color-accent-soft')
    };

    refs.colorPreviews = {
      secondary: document.getElementById('tc-event-preview-secondary'),
      highlight: document.getElementById('tc-event-preview-highlight'),
      accentSoft: document.getElementById('tc-event-preview-accent-soft')
    };

    refs.colorPickers = {
      secondary: document.getElementById('tc-event-picker-secondary'),
      highlight: document.getElementById('tc-event-picker-highlight'),
      accentSoft: document.getElementById('tc-event-picker-accent-soft')
    };

    refs.saveBtn = document.getElementById('tc-event-style-save');
    refs.saveStatus = document.getElementById('tc-event-style-save-status');

    if (!(refs.saveBtn instanceof HTMLButtonElement)) {
      return;
    }

    bindInputs();

    try {
      const settings = await getSettings();
      applySettings(settings);
      setStatus('');
      setLogoStatus('');
    } catch (error) {
      applySettings({});
      setStatus(error?.message || 'Failed to load settings.', true);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      void init();
    });
  } else {
    void init();
  }
})();
