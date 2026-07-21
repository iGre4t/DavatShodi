(() => {
  const API_URL = 'miniapps/EGMs/EGM/egm_store.php';
  const egmShellEl = document.querySelector('.egm-shell');
  const csrfToken = egmShellEl instanceof HTMLElement
    ? String(egmShellEl.dataset.egmCsrf || '').trim()
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
    eventNameInput: null,
    eventNameSaveBtn: null,
    eventNameStatus: null,
    missionLinkCard: null,
    missionLinkInput: null,
    missionLinkSaveBtn: null,
    missionLinkPreview: null,
    missionLinkStatus: null,
    colorInputs: {},
    colorPreviews: {},
    colorPickers: {},
    saveBtn: null,
    saveStatus: null
  };

  const state = {
    eventName: '',
    missionLinkCode: '',
    missionLinkAvailable: false,
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

  function normalizeEventName(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 120);
  }

  function normalizeMissionCode(value) {
    return String(value || '')
      .trim()
      .replace(/\s+/g, '-')
      .replace(/[^A-Za-z0-9._-]+/g, '-')
      .replace(/^[.-]+|[.-]+$/g, '')
      .slice(0, 80);
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

  function setEventNameStatus(message, isError = false) {
    if (!(refs.eventNameStatus instanceof HTMLElement)) return;
    refs.eventNameStatus.textContent = String(message || '').trim();
    refs.eventNameStatus.style.color = isError ? '#d1434a' : '';
  }

  function setMissionLinkStatus(message, isError = false) {
    if (!(refs.missionLinkStatus instanceof HTMLElement)) return;
    refs.missionLinkStatus.textContent = String(message || '').trim();
    refs.missionLinkStatus.style.color = isError ? '#d1434a' : '';
  }

  function updateGeneratedPanelTabName(eventName) {
    const tabName = normalizeEventName(eventName);
    if (!tabName || typeof window.updatePanelTabLabel !== 'function') return;
    const activeNavItem = document.querySelector('.nav-item.active[data-tab^="event-guest-manager-mission-"]');
    if (!(activeNavItem instanceof HTMLElement)) return;
    window.updatePanelTabLabel(activeNavItem.dataset.tab || '', tabName);
  }

  function updateMissionLinkPreview() {
    if (!(refs.missionLinkPreview instanceof HTMLElement)) return;
    const code = normalizeMissionCode(refs.missionLinkInput instanceof HTMLInputElement ? refs.missionLinkInput.value : state.missionLinkCode);
    refs.missionLinkPreview.textContent = code ? `/miniapps/EGMs/${code}` : '';
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
    state.eventName = normalizeEventName(source.eventName || '');
    if (refs.eventNameInput instanceof HTMLInputElement) {
      refs.eventNameInput.value = state.eventName;
    }
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
      throw new Error(payload?.message || 'بارگذاری تنظیمات ناموفق بود.');
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
      throw new Error(payload?.message || 'ذخیره تنظیمات ناموفق بود.');
    }
    return payload;
  }

  async function getMissionLink() {
    const response = await fetch(`${API_URL}?action=get_mission_link`, { credentials: 'same-origin' });
    const payload = await response.json();
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'بارگذاری لینک باشگاه ناموفق بود.');
    }
    return payload.data && typeof payload.data === 'object' ? payload.data : {};
  }

  async function saveMissionLink(code) {
    const response = await fetch(`${API_URL}?action=save_mission_link`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ code, csrf: csrfToken })
    });
    const payload = await response.json();
    if (!response.ok || payload?.status !== 'ok') {
      throw new Error(payload?.message || 'ذخیره لینک باشگاه ناموفق بود.');
    }
    return payload;
  }

  function openLogoChooser() {
    if (typeof window.openPhotoChooserModal !== 'function') {
      setLogoStatus('انتخاب‌گر تصویر در دسترس نیست.', true);
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
          setLogoStatus('تصویر انتخاب‌شده معتبر نیست.', true);
          return;
        }
        state.logo = filename;
        updateLogoPreview();
        void persistLogoOnly('لوگو ذخیره شد.');
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

    const fallbackColor = window.prompt('کد رنگ را وارد کنید (#RRGGBB)', current);
    state.colors[key] = normalizeHex(fallbackColor, current);
    updateColorPreview(key);
  }

  async function handleSave() {
    if (!(refs.saveBtn instanceof HTMLButtonElement)) return;

    refs.saveBtn.disabled = true;
    setStatus('در حال ذخیره...');

    try {
      const current = await getSettings();
      const merged = {
        ...current,
        eventName: normalizeEventName(state.eventName),
        eventLogo: normalizeLogoStoredValue(state.logo),
        eventColors: {
          secondary: normalizeHex(state.colors.secondary, DEFAULT_COLORS.secondary),
          highlight: normalizeHex(state.colors.highlight, DEFAULT_COLORS.highlight),
          accentSoft: normalizeHex(state.colors.accentSoft, DEFAULT_COLORS.accentSoft)
        }
      };
      await saveSettings(merged);
      updateGeneratedPanelTabName(merged.eventName);
      setStatus('استایل رویداد ذخیره شد.');
      if (typeof window.showDefaultToast === 'function') {
        window.showDefaultToast('استایل رویداد ذخیره شد.');
      }
    } catch (error) {
      setStatus(error?.message || 'ذخیره تنظیمات ناموفق بود.', true);
    } finally {
      refs.saveBtn.disabled = false;
    }
  }

  async function persistLogoOnly(successMessage = 'لوگو ذخیره شد.') {
    try {
      const current = await getSettings();
      const merged = {
        ...current,
        eventLogo: normalizeLogoStoredValue(state.logo)
      };
      await saveSettings(merged);
      setLogoStatus(successMessage);
    } catch (error) {
      setLogoStatus(error?.message || 'ذخیره لوگو ناموفق بود.', true);
    }
  }

  async function handleEventNameSave() {
    if (!(refs.eventNameSaveBtn instanceof HTMLButtonElement)) return;

    state.eventName = normalizeEventName(refs.eventNameInput instanceof HTMLInputElement ? refs.eventNameInput.value : '');
    if (refs.eventNameInput instanceof HTMLInputElement) {
      refs.eventNameInput.value = state.eventName;
    }

    refs.eventNameSaveBtn.disabled = true;
    setEventNameStatus('در حال ذخیره...');

    try {
      const current = await getSettings();
      await saveSettings({
        ...current,
        eventName: state.eventName
      });
      updateGeneratedPanelTabName(state.eventName);
      setEventNameStatus('نام رویداد ذخیره شد.');
      if (typeof window.showDefaultToast === 'function') {
        window.showDefaultToast('نام رویداد ذخیره شد.');
      }
    } catch (error) {
      setEventNameStatus(error?.message || 'ذخیره نام رویداد ناموفق بود.', true);
    } finally {
      refs.eventNameSaveBtn.disabled = false;
    }
  }

  async function handleMissionLinkSave() {
    if (!(refs.missionLinkSaveBtn instanceof HTMLButtonElement) || !state.missionLinkAvailable) return;
    const code = normalizeMissionCode(refs.missionLinkInput instanceof HTMLInputElement ? refs.missionLinkInput.value : '');
    if (!code) {
      setMissionLinkStatus('یک کد لینک معتبر وارد کنید.', true);
      return;
    }
    if (refs.missionLinkInput instanceof HTMLInputElement) {
      refs.missionLinkInput.value = code;
    }
    updateMissionLinkPreview();

    refs.missionLinkSaveBtn.disabled = true;
    setMissionLinkStatus('در حال ذخیره...');
    try {
      const payload = await saveMissionLink(code);
      const data = payload?.data && typeof payload.data === 'object' ? payload.data : {};
      state.missionLinkCode = normalizeMissionCode(data.code || code);
      if (refs.missionLinkInput instanceof HTMLInputElement) {
        refs.missionLinkInput.value = state.missionLinkCode;
      }
      updateMissionLinkPreview();
      setMissionLinkStatus(`لینک مدیریت مهمان رویداد ذخیره شد: /miniapps/EGMs/${state.missionLinkCode}`);
      if (typeof window.showDefaultToast === 'function') {
        window.showDefaultToast('لینک باشگاه ذخیره شد.');
      }
    } catch (error) {
      setMissionLinkStatus(error?.message || 'ذخیره لینک باشگاه ناموفق بود.', true);
    } finally {
      refs.missionLinkSaveBtn.disabled = false;
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
      void persistLogoOnly('لوگو حذف شد.');
    });
    refs.eventNameInput?.addEventListener('input', () => {
      state.eventName = normalizeEventName(refs.eventNameInput.value);
    });
    refs.eventNameInput?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      void handleEventNameSave();
    });
    refs.eventNameSaveBtn?.addEventListener('click', () => {
      void handleEventNameSave();
    });
    refs.missionLinkInput?.addEventListener('input', updateMissionLinkPreview);
    refs.missionLinkInput?.addEventListener('blur', () => {
      if (!(refs.missionLinkInput instanceof HTMLInputElement)) return;
      refs.missionLinkInput.value = normalizeMissionCode(refs.missionLinkInput.value);
      updateMissionLinkPreview();
    });
    refs.missionLinkInput?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      void handleMissionLinkSave();
    });
    refs.missionLinkSaveBtn?.addEventListener('click', () => {
      void handleMissionLinkSave();
    });

    refs.colorPickers.secondary?.addEventListener('click', () => openColorPicker('secondary', 'انتخاب رنگ دوم'));
    refs.colorPickers.highlight?.addEventListener('click', () => openColorPicker('highlight', 'انتخاب رنگ برجسته'));
    refs.colorPickers.accentSoft?.addEventListener('click', () => openColorPicker('accentSoft', 'انتخاب رنگ تاکیدی ملایم'));

    refs.colorPreviews.secondary?.addEventListener('click', () => openColorPicker('secondary', 'انتخاب رنگ دوم'));
    refs.colorPreviews.highlight?.addEventListener('click', () => openColorPicker('highlight', 'انتخاب رنگ برجسته'));
    refs.colorPreviews.accentSoft?.addEventListener('click', () => openColorPicker('accentSoft', 'انتخاب رنگ تاکیدی ملایم'));

    refs.saveBtn?.addEventListener('click', () => {
      void handleSave();
    });
  }

  async function init() {
    refs.logoImage = document.getElementById('egm-event-logo-image');
    refs.logoPlaceholder = document.getElementById('egm-event-logo-placeholder');
    refs.logoPickBtn = document.getElementById('egm-event-logo-pick');
    refs.logoClearBtn = document.getElementById('egm-event-logo-clear');
    refs.logoStatus = document.getElementById('egm-event-style-logo-status');
    refs.eventNameInput = document.getElementById('egm-event-name');
    refs.eventNameSaveBtn = document.getElementById('egm-event-name-save');
    refs.eventNameStatus = document.getElementById('egm-event-name-status');
    refs.missionLinkCard = document.getElementById('egm-mission-link-card');
    refs.missionLinkInput = document.getElementById('egm-mission-link-code');
    refs.missionLinkSaveBtn = document.getElementById('egm-mission-link-save');
    refs.missionLinkPreview = document.getElementById('egm-mission-link-preview');
    refs.missionLinkStatus = document.getElementById('egm-mission-link-status');

    refs.colorInputs = {
      secondary: document.getElementById('egm-event-color-secondary'),
      highlight: document.getElementById('egm-event-color-highlight'),
      accentSoft: document.getElementById('egm-event-color-accent-soft')
    };

    refs.colorPreviews = {
      secondary: document.getElementById('egm-event-preview-secondary'),
      highlight: document.getElementById('egm-event-preview-highlight'),
      accentSoft: document.getElementById('egm-event-preview-accent-soft')
    };

    refs.colorPickers = {
      secondary: document.getElementById('egm-event-picker-secondary'),
      highlight: document.getElementById('egm-event-picker-highlight'),
      accentSoft: document.getElementById('egm-event-picker-accent-soft')
    };

    refs.saveBtn = document.getElementById('egm-event-style-save');
    refs.saveStatus = document.getElementById('egm-event-style-save-status');

    if (!(refs.saveBtn instanceof HTMLButtonElement)) {
      return;
    }

    bindInputs();

    try {
      const settings = await getSettings();
      applySettings(settings);
      try {
        const missionLink = await getMissionLink();
        state.missionLinkAvailable = Boolean(missionLink.isMission);
        state.missionLinkCode = normalizeMissionCode(missionLink.code || '');
        if (refs.missionLinkCard instanceof HTMLElement) {
          refs.missionLinkCard.classList.toggle('hidden', !state.missionLinkAvailable);
        }
        if (refs.missionLinkInput instanceof HTMLInputElement) {
          refs.missionLinkInput.value = state.missionLinkCode;
          refs.missionLinkInput.disabled = !state.missionLinkAvailable;
        }
        if (refs.missionLinkSaveBtn instanceof HTMLButtonElement) {
          refs.missionLinkSaveBtn.disabled = !state.missionLinkAvailable;
        }
        updateMissionLinkPreview();
        setMissionLinkStatus(state.missionLinkAvailable ? '' : 'لینک فقط برای نمونه‌های ساخته‌شده در Event Guest Manager قابل تغییر است.');
      } catch (linkError) {
        state.missionLinkAvailable = false;
        if (refs.missionLinkCard instanceof HTMLElement) {
          refs.missionLinkCard.classList.add('hidden');
        }
        setMissionLinkStatus(linkError?.message || 'بارگذاری لینک باشگاه ناموفق بود.', true);
      }
      setStatus('');
      setLogoStatus('');
      setEventNameStatus('');
    } catch (error) {
      applySettings({});
      setStatus(error?.message || 'بارگذاری تنظیمات ناموفق بود.', true);
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
