<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Guest Invite Card Generator</title>
    <link rel="icon" href="data:," />
    <style>
      @font-face {
        font-family: 'Peyda';
        font-style: normal;
        font-weight: 400;
        src: url('/style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
      }
      @font-face {
        font-family: 'Peyda';
        font-style: normal;
        font-weight: 700;
        src: url('/style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
      }
      :root {
        color-scheme: only light;
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
      }
      * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: radial-gradient(circle at top, #fdf4ff 0%, #dbeafe 45%, #e2e8f0 100%);
        color: #0f172a;
      }
      .guest-invite-shell {
        width: min(720px, 100%);
        background: rgba(255, 255, 255, 0.95);
        border-radius: 36px;
        padding: 32px;
        box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
        display: flex;
        flex-direction: column;
        gap: 20px;
        text-align: center;
      }
      .guest-invite-label {
        margin: 0;
        font-size: 0.9rem;
        letter-spacing: 0.3em;
        text-transform: uppercase;
        color: #475569;
      }
      .guest-invite-name {
        margin: 6px 0;
        font-size: clamp(1.8rem, 4vw, 2.4rem);
      }
      .guest-invite-code {
        margin: 0;
        font-size: 1rem;
        color: #1d4ed8;
        font-weight: 600;
      }
      .guest-invite-event {
        margin: 0;
        font-size: 0.95rem;
        color: #475569;
      }
      .guest-invite-canvas-shell {
        display: flex;
        flex-direction: column;
        gap: 12px;
        align-items: center;
      }
      canvas {
        width: min(460px, 100%);
        max-width: 100%;
        border-radius: 28px;
        background: #fff;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
      }
      .guest-invite-status {
        margin: 0;
        font-size: 0.95rem;
        color: #475569;
        min-height: 1.2rem;
      }
      .guest-invite-status[data-tone='error'] {
        color: #dc2626;
      }
      .guest-invite-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 12px;
      }
      .btn {
        border: none;
        border-radius: 999px;
        padding: 12px 30px;
        font-size: 0.95rem;
        cursor: pointer;
        font-weight: 600;
        transition: transform 0.2s ease;
      }
      .btn.primary {
        background: #1d4ed8;
        color: #fff;
      }
      .btn.ghost {
        background: transparent;
        color: #1d4ed8;
        border: 2px solid #1d4ed8;
      }
      .btn.disabled {
        opacity: 0.4;
        cursor: not-allowed;
        transform: none;
      }
      .btn:focus-visible {
        outline: 3px solid rgba(37, 99, 235, 0.5);
        outline-offset: 2px;
      }
      .btn:not(.disabled):hover {
        transform: translateY(-2px);
      }
      @media (max-width: 600px) {
        .guest-invite-shell {
          padding: 24px;
        }
        canvas {
          width: 100%;
        }
        .btn {
          flex: 1 1 180px;
        }
      }
    </style>
  </head>
  <body>
    <main class="guest-invite-shell">
      <header class="guest-invite-header">
        <p class="guest-invite-label">Guest Invite Card Generator</p>
        <h1 id="guest-invite-name" class="guest-invite-name">مهمان</h1>
        <p id="guest-invite-code" class="guest-invite-code"></p>
        <p id="guest-invite-event-name" class="guest-invite-event"></p>
      </header>
      <div class="guest-invite-canvas-shell">
        <canvas id="guest-invite-canvas" aria-label="پیش‌نمایش کارت دعوت"></canvas>
        <p id="guest-invite-status" class="guest-invite-status" aria-live="polite">در حال آماده‌سازی کارت دعوت...</p>
      </div>
      <div class="guest-invite-actions">
        <a id="guest-invite-download" class="btn ghost disabled" aria-hidden="true">دانلود کارت</a>
        <button type="button" id="guest-invite-refresh" class="btn primary">تولید دوباره</button>
      </div>
    </main>
    <script>
      window.GUEST_INVITE_PAYLOAD = __GUEST_INVITE_PAYLOAD__;
    </script>
    <script>
      (function () {
        const INVITE_CARD_ALIGNMENT_MAP = { rtl: 'right', ltr: 'left', center: 'center' };
        const INVITE_CARD_DEFAULT_QR_SIZE = 160;

        const payload = window.GUEST_INVITE_PAYLOAD || {};
        const guest = payload.guest || {};
        const template = payload.template || {};
        const fallbackPhoto = (payload.fallbackPhoto || '').toString();
        const canvas = document.getElementById('guest-invite-canvas');
        const statusEl = document.getElementById('guest-invite-status');
        const downloadLink = document.getElementById('guest-invite-download');
        const refreshButton = document.getElementById('guest-invite-refresh');
        const nameEl = document.getElementById('guest-invite-name');
        const codeEl = document.getElementById('guest-invite-code');
        const eventNameEl = document.getElementById('guest-invite-event-name');

        function setText(element, value) {
          if (!element) {
            return;
          }
          element.textContent = value || '';
        }

        setText(nameEl, formatGuestName(guest, template) || 'مهمان');
        const inviteCode = guest.invite_code || guest.code || '';
        if (inviteCode) {
          setText(codeEl, `کد دعوت: ${inviteCode}`);
          codeEl.classList.remove('hidden');
        } else {
          codeEl.classList.add('hidden');
        }
        if (eventNameEl && payload.event && payload.event.name) {
          setText(eventNameEl, payload.event.name);
        }

        function setStatus(message, tone = '') {
          if (!statusEl) {
            return;
          }
          statusEl.textContent = message || '';
          if (tone) {
            statusEl.setAttribute('data-tone', tone);
          } else {
            statusEl.removeAttribute('data-tone');
          }
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
          const basePhotoUrl = photoUrl || fallbackPhoto;
          if (!basePhotoUrl) {
            throw new Error('تصویر پس‌زمینه کارت تعیین نشده است.');
          }
          const photo = await loadImage(basePhotoUrl);
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

        function formatDownloadName(guestData) {
          const codeCandidate = (guestData.invite_code || guestData.code || guestData.number || 'guest-card')
            .toString()
            .replace(/[^a-z0-9-]/gi, '-')
            .replace(/-+/g, '-');
          return `invite-card-${codeCandidate || 'guest'}.png`;
        }

        function setBusy(isBusy) {
          if (refreshButton) {
            refreshButton.disabled = Boolean(isBusy);
          }
        }

        async function generateCard() {
          if (!canvas) {
            setStatus('فضای رندر کارت در دسترس نیست.', 'error');
            return;
          }
          setStatus('در حال ساخت کارت دعوت...');
          setBusy(true);
          downloadLink?.classList.add('disabled');
          downloadLink?.setAttribute('aria-hidden', 'true');
          try {
            const fields = buildFields(template, guest);
            if (!fields.length) {
              throw new Error('قالب کارت دعوت تنظیم نشده است.');
            }
            const photoUrl =
              (template.photo_path ?? '') || (template.photo_filename ?? '') || fallbackPhoto;
            const previewCanvas = await renderInviteCardCanvas(canvas, photoUrl.toString().trim(), fields);
            if (downloadLink) {
              downloadLink.href = previewCanvas.toDataURL('image/png');
              downloadLink.download = formatDownloadName(guest);
              downloadLink.classList.remove('disabled');
              downloadLink.removeAttribute('aria-hidden');
            }
            setStatus('کارت دعوت آماده است.');
          } catch (error) {
            setStatus(
              (error && error.message) || 'امکان تولید کارت دعوت وجود ندارد.',
              'error'
            );
          } finally {
            setBusy(false);
          }
        }

        document.addEventListener('DOMContentLoaded', () => {
          refreshButton?.addEventListener('click', generateCard);
          generateCard();
        });
      })();
    </script>
  </body>
</html>
'@
