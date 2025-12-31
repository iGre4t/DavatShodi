<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <title>Guest Invite Card</title>
    <link rel="icon" href="data:," />
    <style>
      @font-face {
        font-family: 'Peyda';
        font-style: normal;
        font-weight: 400;
        src: url('https://davatshodi.ir/style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2'),
             url('https://davatshodi.ir/style/fonts/PeydaWebFaNum-Regular.woff') format('woff');
      }
      @font-face {
        font-family: 'Peyda';
        font-style: normal;
        font-weight: 700;
        src: url('https://davatshodi.ir/style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2'),
             url('https://davatshodi.ir/style/fonts/PeydaWebFaNum-Bold.woff') format('woff');
      }
      :root {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        --vh: 1vh;
        background-color: #020204;
      }
      * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        min-height: 100vh;
        height: calc(var(--vh, 1vh) * 100);
        background: linear-gradient(180deg, #030409, #07080f 70%, #040406);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
      }
      .guest-invite-loader,
      .guest-invite-canvas-shell {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        text-align: center;
      }
      .guest-invite-loader {
        background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, rgba(0, 0, 0, 0.85) 70%);
        transition: opacity 0.3s ease, visibility 0.3s ease;
      }
      .guest-invite-loader.hidden {
        opacity: 0;
        visibility: hidden;
      }
      .guest-invite-progress {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
        max-width: 320px;
      }
      .guest-invite-progress[data-tone='error'] p {
        color: #f87171;
      }
      .guest-invite-progress p {
        margin: 0;
        font-size: 1rem;
        line-height: 1.5;
      }
      .guest-invite-spinner {
        width: 78px;
        height: 78px;
        border-radius: 50%;
        border: 4px solid rgba(255, 255, 255, 0.18);
        border-top-color: #38bdf8;
        animation: spin 1.2s linear infinite;
      }
      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }
      .guest-invite-canvas-shell {
        background: #05060c;
        opacity: 0;
        transition: opacity 0.35s ease;
      }
      .guest-invite-canvas-shell.visible {
        opacity: 1;
      }
      .guest-invite-canvas-shell canvas {
        display: block;
        width: min(100vw, calc(var(--vh, 1vh) * 100 * 0.5625));
        max-width: 100%;
        max-height: calc(var(--vh, 1vh) * 100);
        aspect-ratio: 9 / 16;
        border-radius: 0;
        box-shadow: 0 0 40px rgba(0, 0, 0, 0.65);
      }
      @media (min-width: 768px) {
        .guest-invite-canvas-shell canvas {
          width: min(60vw, 480px);
        }
      }
    </style>
  </head>
  <body>
    <div class="guest-invite-loader" id="guest-invite-loader" aria-live="polite">
      <div class="guest-invite-progress" id="guest-invite-progress">
        <div class="guest-invite-spinner" aria-hidden="true"></div>
        <p id="guest-invite-loader-message">در حال آماده‌سازی کارت دعوت...</p>
      </div>
    </div>
    <div class="guest-invite-canvas-shell" id="guest-invite-canvas-shell" aria-live="polite">
      <canvas id="guest-invite-canvas" aria-label="کارت دعوت"></canvas>
    </div>
    <script>window.GUEST_INVITE_PAYLOAD = __GUEST_INVITE_PAYLOAD__;</script>
    <script>
      (function () {
        const INVITE_CARD_ALIGNMENT_MAP = { rtl: 'right', ltr: 'left', center: 'center' };
        const INVITE_CARD_DEFAULT_QR_SIZE = 160;

        const payload = window.GUEST_INVITE_PAYLOAD || {};
        const guest = payload.guest || {};
        const template = payload.template || {};
        const fallbackPhoto = (payload.fallbackPhoto || '').toString();
        const canvas = document.getElementById('guest-invite-canvas');
        const loader = document.getElementById('guest-invite-loader');
        const progressPanel = document.getElementById('guest-invite-progress');
        const loaderMessage = document.getElementById('guest-invite-loader-message');
        const canvasShell = document.getElementById('guest-invite-canvas-shell');

        function updateViewportHeight() {
          const vh = window.innerHeight * 0.01;
          document.documentElement.style.setProperty('--vh', `${vh}px`);
        }

        function setLoaderMessage(message, isError = false) {
          if (loaderMessage) {
            loaderMessage.textContent = message || '';
          }
          if (progressPanel) {
            if (isError) {
              progressPanel.setAttribute('data-tone', 'error');
            } else {
              progressPanel.removeAttribute('data-tone');
            }
          }
        }

        function hideLoader() {
          loader?.classList.add('hidden');
          canvasShell?.classList.add('visible');
        }

        function formatGuestName(guestData, templateData) {
          const first = (guestData.firstname || '').trim();
          const last = (guestData.lastname || '').trim();
          const base = [first, last].filter(Boolean).join(' ').trim() || 'مهمان';
          const prefix = findPrefixForGender(
            templateData.gender_prefixes || {},
            guestData.gender || templateData.preview_gender || ''
          );
          return prefix ? `${prefix} ${base}` : base;
        }

        function findPrefixForGender(prefixes, genderValue) {
          const normalized = (genderValue || '').toString().trim().toLowerCase();
          if (!normalized) {
            return '';
          }
          const map = {};
          Object.entries(prefixes || {}).forEach(([key, value]) => {
            const normalizedKey = (key ?? '').toString().trim().toLowerCase();
            if (normalizedKey) {
              map[normalizedKey] = (value ?? '').toString().trim();
            }
          });
          return map[normalized] || '';
        }

        function ensureHexColor(value) {
          if (!value) {
            return '#111111';
          }
          const trimmed = value.toString().trim();
          if (/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(trimmed)) {
            return trimmed;
          }
          return trimmed || '#111111';
        }

        function clampNumber(value, min = 0, max = Infinity) {
          const candidate = Number(value);
          if (Number.isNaN(candidate)) {
            return min;
          }
          return Math.min(Math.max(candidate, min), max);
        }

        function determineFieldValue(fieldId, fallback) {
          const normalized = (fieldId || '').toString().trim().toLowerCase();
          const normalizedDigits = normalized.replace(/[^a-z0-9]/g, '');
          const fallbackValue = (fallback ?? '').toString().trim();
          const fullName = formatGuestName(guest, template);
          const codeCandidate = guest.invite_code || guest.code || guest.number || '';
          if (normalizedDigits.includes('name')) {
            return fullName;
          }
          if (normalizedDigits.includes('code') || normalizedDigits.includes('invite')) {
            return codeCandidate;
          }
          if (normalizedDigits.includes('national') || normalizedDigits === 'id') {
            return guest.national_id || '';
          }
          if (normalizedDigits.includes('phone')) {
            return guest.phone_number || '';
          }
          if (normalizedDigits.includes('sms') || normalizedDigits.includes('link')) {
            return guest.sms_link || '';
          }
          return fallbackValue;
        }

        function buildFields(templateData, guestData) {
          const entries = Array.isArray(templateData.fields) ? templateData.fields : [];
          return entries
            .map((field) => {
              const fieldId = (field?.id ?? '').toString();
              const type = (field?.type ?? 'text').toString().trim().toLowerCase();
              if (type !== 'text' && type !== 'qr') {
                return null;
              }
              const resolvedValue = determineFieldValue(fieldId, field?.value ?? '');
              return {
                id: fieldId,
                type,
                value: resolvedValue,
                alignment: (field?.alignment ?? 'center').toString().trim() || 'center',
                fontFamily: (field?.fontFamily ?? field?.font_family ?? 'PeydaWebFaNum').toString(),
                fontWeight: (field?.fontWeight ?? field?.font_weight ?? '400').toString(),
                fontSize: Math.max(8, Number.parseFloat(field?.fontSize ?? field?.font_size ?? 16) || 16),
                x: clampNumber(field?.x ?? field?.coordinate_x ?? 0),
                y: clampNumber(field?.y ?? field?.coordinate_y ?? 0),
                scale: Number.isFinite(Number(field?.scale ?? 0)) ? Number(field?.scale ?? 0) : null,
                color: ensureHexColor(field?.color ?? '#111111')
              };
            })
            .filter(
              (field) => Boolean(field) && typeof field.value === 'string' && field.value.trim() !== ''
            );
        }

        function getQrCodeUrl(data, size) {
          const encoded = encodeURIComponent(String(data ?? ''));
          const normalizedSize = Math.max(32, Math.min(Math.round(size), 768));
          return `https://api.qrserver.com/v1/create-qr-code/?size=${normalizedSize}x${normalizedSize}&margin=2&data=${encoded}`;
        }

        function loadImage(src) {
          return new Promise((resolve, reject) => {
            const url = (src || '').toString().trim();
            if (!url) {
              reject(new Error('منبع تصویر کارت مشخص نشده است.'));
              return;
            }
            const img = new Image();
            if (!url.startsWith('data:')) {
              img.crossOrigin = 'anonymous';
            }
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('بارگذاری تصویر کارت موفقیت‌آمیز نبود.'));
            img.src = url;
          });
        }

        async function loadFirstAvailableImage(candidates) {
          const urls = Array.isArray(candidates) ? candidates : [candidates];
          for (const candidate of urls) {
            const trimmed = (candidate || '').toString().trim();
            if (!trimmed) {
              continue;
            }
            try {
              return await loadImage(trimmed);
            } catch (error) {
              console.debug('Fallback image failed:', trimmed, error);
            }
          }
          throw new Error('هیچکدام از تصاویر کارت قابل بارگذاری نبود.');
        }

        function drawInviteCardText(ctx, field) {
          ctx.save();
          ctx.fillStyle = field.color;
          ctx.font = `${field.fontWeight} ${field.fontSize}px ${field.fontFamily}`;
          const align = INVITE_CARD_ALIGNMENT_MAP[field.alignment] || 'center';
          ctx.textAlign = align;
          ctx.textBaseline = 'top';
          ctx.direction = field.alignment === 'rtl' ? 'rtl' : 'ltr';
          const lineHeight = field.fontSize * 1.3;
          const chunks = String(field.value ?? '').split(/\r?\n/);
          const safeX = clampNumber(field.x, 0, ctx.canvas.width);
          const safeY = clampNumber(field.y, 0, ctx.canvas.height);
          chunks.forEach((chunk, index) => {
            ctx.fillText(chunk, safeX, safeY + index * lineHeight);
          });
          ctx.restore();
        }

        async function drawInviteCardQr(ctx, field) {
          const size = field.scale && field.scale > 0 ? field.scale : INVITE_CARD_DEFAULT_QR_SIZE;
          const normalizedSize = Math.max(
            32,
            Math.min(Math.round(size), ctx.canvas.width, ctx.canvas.height)
          );
          const qrImage = await loadImage(getQrCodeUrl(field.value, normalizedSize));
          const startX = clampNumber(field.x, 0, ctx.canvas.width - normalizedSize);
          const startY = clampNumber(field.y, 0, ctx.canvas.height - normalizedSize);
          ctx.drawImage(qrImage, startX, startY, normalizedSize, normalizedSize);
        }

        async function renderInviteCardCanvas(canvasEl, photoUrl, fields) {
          if (!canvasEl) {
            throw new Error('فضای رندر کارت در دسترس نیست.');
          }
          const candidates = Array.isArray(photoUrl) ? photoUrl.slice() : [];
          if ((fallbackPhoto || '').toString().trim() !== '') {
            candidates.push(fallbackPhoto);
          }
          if (candidates.length === 0) {
            throw new Error('تصویر پس‌زمینه کارت تعیین نشده است.');
          }
          const photo = await loadFirstAvailableImage(candidates);
          const width = Math.max(1, photo.naturalWidth || photo.width || 1);
          const height = Math.max(1, photo.naturalHeight || photo.height || 1);
          canvasEl.width = width;
          canvasEl.height = height;
          const ctx = canvasEl.getContext('2d');
          if (!ctx) {
            throw new Error('امکان رندر کارت وجود ندارد.');
          }
          ctx.drawImage(photo, 0, 0, width, height);
          for (const field of fields) {
            if (field.type === 'text') {
              drawInviteCardText(ctx, field);
            } else if (field.type === 'qr') {
              await drawInviteCardQr(ctx, field);
            }
          }
          return canvasEl;
        }

        async function generateCard() {
          if (!canvas) {
            setLoaderMessage('فضای رندر کارت در دسترس نیست.', true);
            return;
          }
          setLoaderMessage('در حال ساخت کارت دعوت...');
          try {
            const fields = buildFields(template, guest);
            if (!fields.length) {
              throw new Error('قالب کارت دعوت تنظیم نشده است.');
            }
            const photoCandidates = [];
            if (template.photo_data) {
              photoCandidates.push(template.photo_data);
            }
            if (template.photo_path) {
              photoCandidates.push(template.photo_path);
            }
            if (template.photo_filename) {
              photoCandidates.push(template.photo_filename);
            }
            await renderInviteCardCanvas(canvas, photoCandidates, fields);
            hideLoader();
          } catch (error) {
            setLoaderMessage(
              (error && error.message) || 'امکان تولید کارت دعوت وجود ندارد.',
              true
            );
          }
        }

        document.addEventListener('DOMContentLoaded', () => {
          updateViewportHeight();
          window.addEventListener('resize', updateViewportHeight);
          generateCard();
        });
      })();
    </script>
  </body>
</html>
