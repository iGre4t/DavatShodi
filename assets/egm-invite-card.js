(function () {
  'use strict';

  const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
  const MAX_IMAGE_PIXELS = 40000000;
  const MAX_FONT_BYTES = 6 * 1024 * 1024;
  const ACCEPTED_IMAGE_TYPES = new Set(['image/png', 'image/jpeg', 'image/webp']);
  const DEFAULT_INVITE_FONT_FAMILY = "'PeydaWebFaNum', 'Segoe UI', Tahoma, Arial, sans-serif";
  const CUSTOM_INVITE_FONT_NAME = 'EGMInviteCardCustomFont';
  let loadedCustomFontFace = null;
  let loadedCustomFontSource = '';
  const CONDITIONAL_FIELD_LABELS = Object.freeze({
    fullname: 'نام کامل', firstname: 'نام', lastname: 'نام خانوادگی', nationalid: 'کد ملی',
    workid: 'کد پرسنلی', guestnumber: 'شماره مهمان', phonenumber: 'شماره تلفن',
    deputy: 'معاونت', generaldepartment: 'اداره کل', department: 'اداره', gender: 'جنسیت',
    postallevel: 'سطح پستی', score: 'امتیاز'
  });
  const CONDITIONAL_OPERATOR_LABELS = Object.freeze({
    equals: 'برابر است با', not_equals: 'برابر نیست با', contains: 'شامل می‌شود',
    not_contains: 'شامل نمی‌شود', empty: 'خالی است', not_empty: 'خالی نیست'
  });
  const BUILTIN_MERGE_KEYS = new Set(Object.keys(CONDITIONAL_FIELD_LABELS));

  function one(root, selector) {
    return root.querySelector(selector);
  }

  function cloneRect(rect) {
    return rect ? { x: rect.x, y: rect.y, width: rect.width, height: rect.height } : null;
  }

  function normalizedRect(value) {
    if (!value || typeof value !== 'object') return null;
    const rect = {
      x: Number(value.x),
      y: Number(value.y),
      width: Number(value.width),
      height: Number(value.height)
    };
    if (Object.values(rect).some((item) => !Number.isFinite(item))) return null;
    if (rect.x < 0 || rect.y < 0 || rect.width < 0.5 || rect.height < 0.5) return null;
    if (rect.x + rect.width > 100.001 || rect.y + rect.height > 100.001) return null;
    return rect;
  }

  function applyRect(element, rect) {
    if (!(element instanceof HTMLElement)) return;
    if (!rect) {
      element.hidden = true;
      return;
    }
    element.hidden = false;
    element.style.left = rect.x + '%';
    element.style.top = rect.y + '%';
    element.style.width = rect.width + '%';
    element.style.height = rect.height + '%';
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ''));
      reader.onerror = () => reject(new Error('خواندن فایل تصویر ناموفق بود.'));
      reader.readAsDataURL(file);
    });
  }

  function readImageDimensions(dataUrl) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
      image.onerror = () => reject(new Error('فایل انتخاب‌شده یک تصویر معتبر نیست.'));
      image.src = dataUrl;
    });
  }

  function fontFormatFromBytes(buffer) {
    const bytes = new Uint8Array(buffer.slice(0, 4));
    const signature = Array.from(bytes).map((value) => String.fromCharCode(value)).join('');
    if (bytes[0] === 0 && bytes[1] === 1 && bytes[2] === 0 && bytes[3] === 0) return { mime: 'font/ttf', extension: 'ttf' };
    if (signature === 'true') return { mime: 'font/ttf', extension: 'ttf' };
    if (signature === 'OTTO') return { mime: 'font/otf', extension: 'otf' };
    if (signature === 'wOFF') return { mime: 'font/woff', extension: 'woff' };
    if (signature === 'wOF2') return { mime: 'font/woff2', extension: 'woff2' };
    return null;
  }

  async function normalizedFontDataUrl(file) {
    if (!(file instanceof File)) throw new Error('فایل فونت انتخاب نشده است.');
    if (file.size > MAX_FONT_BYTES) throw new Error('حجم فونت نباید بیشتر از 6 مگابایت باشد.');
    const buffer = await file.arrayBuffer();
    const format = fontFormatFromBytes(buffer);
    if (!format) throw new Error('فقط فونت‌های TTF، OTF، WOFF و WOFF2 مجاز هستند.');
    const rawDataUrl = await readFileAsDataUrl(file);
    const comma = rawDataUrl.indexOf(',');
    if (comma < 0) throw new Error('خواندن فایل فونت ناموفق بود.');
    return { data: `data:${format.mime};base64,${rawDataUrl.slice(comma + 1)}`, mime: format.mime, bytes: file.size };
  }

  async function ensureInviteCardFont(fontData) {
    const source = String(fontData || '').trim();
    if (!source) {
      if (loadedCustomFontFace && document.fonts?.delete) document.fonts.delete(loadedCustomFontFace);
      loadedCustomFontFace = null;
      loadedCustomFontSource = '';
      return DEFAULT_INVITE_FONT_FAMILY;
    }
    if (loadedCustomFontFace && loadedCustomFontSource === source) {
      return `'${CUSTOM_INVITE_FONT_NAME}', ${DEFAULT_INVITE_FONT_FAMILY}`;
    }
    if (typeof FontFace !== 'function' || !document.fonts) throw new Error('مرورگر امکان بارگذاری فونت اختصاصی را ندارد.');
    const face = new FontFace(CUSTOM_INVITE_FONT_NAME, `url(${source})`, { style: 'normal', weight: 'normal' });
    await face.load();
    document.fonts.add(face);
    if (loadedCustomFontFace && document.fonts.delete) document.fonts.delete(loadedCustomFontFace);
    loadedCustomFontFace = face;
    loadedCustomFontSource = source;
    return `'${CUSTOM_INVITE_FONT_NAME}', ${DEFAULT_INVITE_FONT_FAMILY}`;
  }

  function loadCanvasImage(source) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('بارگذاری یکی از اجزای تصویر نهایی ناموفق بود.'));
      image.src = source;
    });
  }

  function canvasToBlob(canvas, mimeType = 'image/png', quality) {
    return new Promise((resolve, reject) => {
      canvas.toBlob((blob) => {
        if (blob instanceof Blob) resolve(blob);
        else reject(new Error('تبدیل کارت دعوت به فایل تصویر ناموفق بود.'));
      }, mimeType, quality);
    });
  }

  function canvasToPngBlob(canvas) {
    return canvasToBlob(canvas, 'image/png');
  }

  function normalizedEditorColor(value) {
    const text = String(value || '').trim().toLowerCase();
    const shortHex = text.match(/^#([0-9a-f]{3})$/i);
    if (shortHex) return '#' + Array.from(shortHex[1]).map((part) => part + part).join('');
    const hex = text.match(/^#([0-9a-f]{6})$/i);
    if (hex) return '#' + hex[1].toLowerCase();
    const rgb = text.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(?:1(?:\.0+)?))?\s*\)$/i);
    if (!rgb) return '';
    const channels = rgb.slice(1, 4).map(Number);
    if (channels.some((channel) => channel < 0 || channel > 255)) return '';
    return '#' + channels.map((channel) => channel.toString(16).padStart(2, '0')).join('');
  }

  function sanitizeEditorHtml(rawHtml) {
    const template = document.createElement('template');
    template.innerHTML = String(rawHtml || '').slice(0, 50000);
    const allowed = new Set(['STRONG', 'B', 'UL', 'OL', 'LI', 'BR', 'P', 'DIV', 'SPAN', 'FONT']);
    const dangerous = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH']);
    Array.from(template.content.querySelectorAll('*')).reverse().forEach((element) => {
      if (dangerous.has(element.tagName)) {
        element.remove();
        return;
      }
      if (!allowed.has(element.tagName)) {
        element.replaceWith(...Array.from(element.childNodes));
        return;
      }
      if (element.tagName === 'SPAN' || element.tagName === 'FONT') {
        const color = normalizedEditorColor(element.style?.color || element.getAttribute('color'));
        if (!color) {
          element.replaceWith(...Array.from(element.childNodes));
          return;
        }
        if (element.tagName === 'FONT') {
          const span = document.createElement('span');
          span.style.color = color;
          span.append(...Array.from(element.childNodes));
          element.replaceWith(span);
          return;
        }
        Array.from(element.attributes).forEach((attribute) => element.removeAttribute(attribute.name));
        element.style.color = color;
        return;
      }
      Array.from(element.attributes).forEach((attribute) => element.removeAttribute(attribute.name));
    });
    return template.innerHTML.trim();
  }

  function plainTextToEditorHtml(value) {
    const holder = document.createElement('div');
    holder.textContent = String(value || '');
    return holder.innerHTML.replace(/\r\n?|\n/g, '<br>');
  }

  function inviteeMergeValues(invitee) {
    const firstName = String(invitee?.firstName || '').trim();
    const lastName = String(invitee?.lastName || '').trim();
    return {
      fullname: [firstName, lastName].filter(Boolean).join(' '),
      firstname: firstName,
      lastname: lastName,
      nationalid: String(invitee?.nationalId || ''),
      workid: String(invitee?.workId || ''),
      guestnumber: String(invitee?.guestNumber || ''),
      phonenumber: String(invitee?.phoneNumber || ''),
      deputy: String(invitee?.deputy || ''),
      generaldepartment: String(invitee?.generalDepartment || ''),
      department: String(invitee?.department || ''),
      gender: String(invitee?.gender || ''),
      postallevel: String(invitee?.postalLevel || ''),
      score: String(invitee?.score || '0')
    };
  }

  function inviteeQrIdentifier(invitee) {
    const normalizeDigits = (value) => String(value || '').trim()
      .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
      .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660));
    const nationalId = normalizeDigits(invitee?.nationalId).replace(/[\s-]+/g, '');
    if (/^\d{10}$/.test(nationalId)) return nationalId;

    const workId = normalizeDigits(invitee?.workId);
    if (/^\d{4,9}$/.test(workId)) return workId;

    const name = inviteeMergeValues(invitee).fullname || invitee?.workId || 'دعوت‌شونده انتخاب‌شده';
    throw new Error(`برای ${name} کد ملی ۱۰ رقمی یا کد پرسنلی عددی ۴ تا ۹ رقمی ثبت نشده است؛ ساخت QR ممکن نیست.`);
  }

  function normalizedConditionalVariables(value) {
    if (!Array.isArray(value)) return [];
    const seen = new Set();
    return value.slice(0, 50).map((definition) => {
      if (!definition || typeof definition !== 'object') return null;
      const token = String(definition.token || '').trim().replace(/^\[|\]$/g, '').toLowerCase();
      const field = String(definition.field || '').trim().toLowerCase();
      if (!/^[a-z][a-z0-9_]{0,31}$/.test(token) || BUILTIN_MERGE_KEYS.has(token)
        || seen.has(token) || !Object.hasOwn(CONDITIONAL_FIELD_LABELS, field)) return null;
      const rules = Array.isArray(definition.rules) ? definition.rules.slice(0, 20).map((rule) => {
        if (!rule || typeof rule !== 'object') return null;
        const operator = String(rule.operator || '').trim().toLowerCase();
        if (!Object.hasOwn(CONDITIONAL_OPERATOR_LABELS, operator)) return null;
        const expected = operator === 'empty' || operator === 'not_empty' ? '' : String(rule.value || '').trim().slice(0, 500);
        if (!expected && operator !== 'empty' && operator !== 'not_empty') return null;
        return { operator, value: expected, text: String(rule.text || '').trim().slice(0, 500) };
      }).filter(Boolean) : [];
      if (!rules.length) return null;
      seen.add(token);
      return { token, field, rules, fallback: String(definition.fallback || '').trim().slice(0, 500) };
    }).filter(Boolean);
  }

  function normalizedComparable(value) {
    return String(value || '').trim().replace(/ي/g, 'ی').replace(/ك/g, 'ک').toLocaleLowerCase('fa-IR');
  }

  function conditionalRuleMatches(actualValue, rule) {
    const actual = normalizedComparable(actualValue);
    const expected = normalizedComparable(rule?.value);
    switch (String(rule?.operator || '')) {
      case 'equals': return actual === expected;
      case 'not_equals': return actual !== expected;
      case 'contains': return actual.includes(expected);
      case 'not_contains': return !actual.includes(expected);
      case 'empty': return actual === '';
      case 'not_empty': return actual !== '';
      default: return false;
    }
  }

  function replaceInviteeMergeTags(value, invitee, conditionalVariables = []) {
    const values = inviteeMergeValues(invitee);
    let output = String(value || '');
    normalizedConditionalVariables(conditionalVariables).forEach((definition) => {
      const matched = definition.rules.find((rule) => conditionalRuleMatches(values[definition.field], rule));
      const replacement = matched ? matched.text : definition.fallback;
      output = output.replace(new RegExp(`\\[${definition.token}\\]`, 'gi'), () => replacement);
    });
    return output.replace(/\[(fullname|firstname|lastname|nationalid|workid|guestnumber|phonenumber|deputy|generaldepartment|department|gender|postallevel|score)\]/gi, (_, key) => values[String(key).toLowerCase()] ?? '');
  }

  function richTextBlocks(html, invitee, conditionalVariables = []) {
    const template = document.createElement('template');
    template.innerHTML = sanitizeEditorHtml(html);
    const blocks = [];
    let looseRuns = [];
    const collectRuns = (node, bold, color, runs) => {
      if (node.nodeType === Node.TEXT_NODE) {
        const text = replaceInviteeMergeTags(node.nodeValue || '', invitee, conditionalVariables);
        if (text) runs.push({ text, bold, color });
        return;
      }
      if (!(node instanceof HTMLElement)) return;
      if (node.tagName === 'BR') {
        runs.push({ text: '\n', bold, color });
        return;
      }
      const nextBold = bold || node.tagName === 'STRONG' || node.tagName === 'B';
      const nextColor = node.tagName === 'SPAN' ? (normalizedEditorColor(node.style.color) || color) : color;
      Array.from(node.childNodes).forEach((child) => collectRuns(child, nextBold, nextColor, runs));
    };
    const flushLoose = () => {
      if (looseRuns.length) {
        blocks.push({ runs: looseRuns });
        looseRuns = [];
      }
    };
    Array.from(template.content.childNodes).forEach((node) => {
      if (node instanceof HTMLElement && (node.tagName === 'UL' || node.tagName === 'OL')) {
        flushLoose();
        const ordered = node.tagName === 'OL';
        Array.from(node.children).filter((child) => child.tagName === 'LI').forEach((item, index) => {
          const runs = [{ text: ordered ? `${index + 1}. ` : '• ', bold: true, color: '' }];
          collectRuns(item, false, '', runs);
          blocks.push({ runs });
        });
        return;
      }
      if (node instanceof HTMLElement && (node.tagName === 'P' || node.tagName === 'DIV')) {
        flushLoose();
        const runs = [];
        collectRuns(node, false, '', runs);
        blocks.push({ runs: runs.length ? runs : [{ text: '', bold: false, color: '' }] });
        return;
      }
      collectRuns(node, false, '', looseRuns);
    });
    flushLoose();
    return blocks.length ? blocks : [{ runs: [{ text: '', bold: false, color: '' }] }];
  }

  function setCanvasRunFont(context, size, bold, family) {
    context.font = `${bold ? 700 : 400} ${size}px ${family}`;
  }

  function splitRichWord(context, word, maxWidth, size, bold, family) {
    setCanvasRunFont(context, size, bold, family);
    if (context.measureText(word).width <= maxWidth) return [word];
    const parts = [];
    let current = '';
    Array.from(word).forEach((character) => {
      const candidate = current + character;
      if (current && context.measureText(candidate).width > maxWidth) {
        parts.push(current);
        current = character;
      } else {
        current = candidate;
      }
    });
    if (current) parts.push(current);
    return parts;
  }

  function layoutRichLines(context, blocks, maxWidth, size, family) {
    const lines = [];
    blocks.forEach((block) => {
      let line = [];
      let lineWidth = 0;
      let pendingSpace = false;
      const flush = (allowEmpty) => {
        if (line.length || allowEmpty) lines.push({ runs: line, width: lineWidth });
        line = [];
        lineWidth = 0;
        pendingSpace = false;
      };
      block.runs.forEach((run) => {
        String(run.text || '').split(/(\n|\s+)/u).forEach((token) => {
          if (!token) return;
          if (token === '\n') {
            flush(true);
            return;
          }
          if (/^\s+$/u.test(token)) {
            pendingSpace = line.length > 0;
            return;
          }
          splitRichWord(context, token, maxWidth, size, Boolean(run.bold), family).forEach((piece) => {
            setCanvasRunFont(context, size, Boolean(run.bold), family);
            const pieceWidth = context.measureText(piece).width;
            const spaceWidth = pendingSpace ? context.measureText(' ').width : 0;
            if (line.length && lineWidth + spaceWidth + pieceWidth > maxWidth) flush(false);
            if (pendingSpace && line.length) {
              line.push({ text: ' ', bold: Boolean(run.bold), color: run.color || '', width: spaceWidth });
              lineWidth += spaceWidth;
            }
            line.push({ text: piece, bold: Boolean(run.bold), color: run.color || '', width: pieceWidth });
            lineWidth += pieceWidth;
            pendingSpace = false;
          });
        });
      });
      flush(lines.length === 0);
    });
    return lines.length ? lines : [{ runs: [], width: 0 }];
  }

  function fitRichCanvasText(context, blocks, maxWidth, maxHeight, fontFamily = DEFAULT_INVITE_FONT_FAMILY) {
    const family = fontFamily || DEFAULT_INVITE_FONT_FAMILY;
    const minimum = 4;
    let low = minimum;
    let high = Math.max(minimum, Math.floor(maxHeight / 1.4));
    let best = null;
    while (low <= high) {
      const size = Math.floor((low + high) / 2);
      const lines = layoutRichLines(context, blocks, maxWidth, size, family);
      const lineHeight = size * 1.55;
      if (lines.length * lineHeight <= maxHeight) {
        best = { size, lineHeight, lines, family };
        low = size + 1;
      } else {
        high = size - 1;
      }
    }
    if (best) return best;
    return { size: minimum, lineHeight: minimum * 1.55, lines: layoutRichLines(context, blocks, maxWidth, minimum, family), family };
  }

  function drawInviteText(context, textHtml, invitee, rect, conditionalVariables = [], fontFamily = DEFAULT_INVITE_FONT_FAMILY) {
    const padding = Math.max(2, Math.min(rect.width, rect.height) * 0.04);
    const innerWidth = Math.max(1, rect.width - padding * 2);
    const innerHeight = Math.max(1, rect.height - padding * 2);
    const layout = fitRichCanvasText(context, richTextBlocks(textHtml, invitee, conditionalVariables), innerWidth, innerHeight, fontFamily);
    context.save();
    context.beginPath();
    context.rect(rect.x, rect.y, rect.width, rect.height);
    context.clip();
    context.direction = 'rtl';
    context.textAlign = 'right';
    context.textBaseline = 'middle';
    context.fillStyle = '#111827';
    const totalHeight = layout.lines.length * layout.lineHeight;
    const startY = rect.y + (rect.height - totalHeight) / 2 + layout.lineHeight / 2;
    layout.lines.forEach((line, index) => {
      let cursorX = rect.x + rect.width / 2 + line.width / 2;
      line.runs.forEach((run) => {
        setCanvasRunFont(context, layout.size, run.bold, layout.family);
        context.fillStyle = normalizedEditorColor(run.color) || '#111827';
        context.fillText(run.text, cursorX, startY + index * layout.lineHeight);
        if (run.bold && run.text.trim()) {
          context.fillText(run.text, cursorX - Math.max(.25, layout.size * .012), startY + index * layout.lineHeight);
        }
        cursorX -= run.width;
      });
    });
    context.restore();
  }

  async function renderInviteCardImage(config, invitee, _qrData, options = {}) {
    const fontFamily = await ensureInviteCardFont(config?.fontData);
    const background = await loadCanvasImage(String(config?.imageData || ''));
    const width = background.naturalWidth;
    const height = background.naturalHeight;
    if (width < 1 || height < 1 || width * height > MAX_IMAGE_PIXELS) {
      throw new Error('ابعاد تصویر برای خروجی امن بسیار بزرگ است؛ حداکثر 40 میلیون پیکسل مجاز است.');
    }
    const qrEndpoint = String(options.qrEndpoint || '').trim();
    if (!qrEndpoint) throw new Error('ماژول ساخت QR Code در دسترس نیست.');
    const params = new URLSearchParams({
      data: inviteeQrIdentifier(invitee), size: '1024', margin: '2', ecc: 'M', response: 'svg'
    });
    const qrImage = await loadCanvasImage(`${qrEndpoint}?${params.toString()}`);
    if (document.fonts && document.fonts.ready) await document.fonts.ready;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { alpha: false });
    if (!context) throw new Error('Canvas مرورگر برای ساخت تصویر در دسترس نیست.');
    context.drawImage(background, 0, 0, width, height);
    const qrRect = config?.qrRect || {};
    const textRect = config?.textRect || {};
    const qrArea = {
      x: width * Number(qrRect.x || 0) / 100,
      y: height * Number(qrRect.y || 0) / 100,
      width: width * Number(qrRect.width || 0) / 100,
      height: height * Number(qrRect.height || 0) / 100
    };
    const qrSide = Math.min(qrArea.width, qrArea.height);
    context.drawImage(qrImage, qrArea.x + (qrArea.width - qrSide) / 2,
      qrArea.y + (qrArea.height - qrSide) / 2, qrSide, qrSide);
    drawInviteText(context, String(config?.textHtml || config?.text || ''), invitee, {
      x: width * Number(textRect.x || 0) / 100,
      y: height * Number(textRect.y || 0) / 100,
      width: width * Number(textRect.width || 0) / 100,
      height: height * Number(textRect.height || 0) / 100
    }, config?.conditionalVariables, fontFamily);
    const mimeType = options.mimeType === 'image/jpeg' ? 'image/jpeg' : 'image/png';
    const blob = await canvasToBlob(canvas, mimeType, mimeType === 'image/jpeg' ? Number(options.quality || 0.92) : undefined);
    return { blob, width, height };
  }

  window.EGMInviteCardRenderer = Object.freeze({
    render: renderInviteCardImage,
    resolveText: replaceInviteeMergeTags
  });

  function responseMessage(data, fallback) {
    return data && typeof data.message === 'string' && data.message.trim() ? data.message : fallback;
  }

  function initPane(pane) {
    if (!(pane instanceof HTMLElement) || pane.dataset.egmInviteCardReady === '1') return;
    pane.dataset.egmInviteCardReady = '1';

    const shell = pane.closest('.egm-shell');
    const endpoint = String(pane.dataset.storeEndpoint || '').trim();
    const qrEndpoint = String(pane.dataset.qrEndpoint || '').trim();
    const fileInput = one(pane, '[data-invite-card-file]');
    const fileName = one(pane, '[data-invite-card-file-name]');
    const sourceEmpty = one(pane, '.egm-invite-card-empty');
    const sourceImage = one(pane, '[data-invite-card-source-image]');
    const chooseButton = one(pane, '[data-action="choose-invite-card-photo"]');
    const selectionButton = one(pane, '[data-action="open-invite-card-selection"]');
    const saveButton = one(pane, '[data-action="save-invite-card"]');
    const generateButton = one(pane, '[data-action="test-generate-invite-card"]');
    const editor = one(pane, '[data-invite-card-editor]');
    const textColorInput = one(pane, '[data-invite-card-text-color]');
    const fontFileInput = one(pane, '[data-invite-card-font-file]');
    const chooseFontButton = one(pane, '[data-action="choose-invite-card-font"]');
    const removeFontButton = one(pane, '[data-action="remove-invite-card-font"]');
    const fontStatus = one(pane, '[data-invite-card-font-status]');
    const mergeTagSelect = one(pane, '[data-invite-card-merge-tag]');
    const insertMergeTagButton = one(pane, '[data-action="insert-invite-card-merge-tag"]');
    const conditionalBuilder = one(pane, '[data-invite-card-conditional-builder]');
    const conditionalTokenInput = one(pane, '[data-conditional-token]');
    const conditionalTokenPreview = one(pane, '[data-conditional-token-preview]');
    const conditionalFieldSelect = one(pane, '[data-conditional-field]');
    const conditionalRules = one(pane, '[data-conditional-rules]');
    const conditionalFallbackInput = one(pane, '[data-conditional-fallback]');
    const conditionalList = one(pane, '[data-conditional-list]');
    const conditionalStatus = one(pane, '[data-conditional-status]');
    const inviteeSearch = one(pane, '[data-invite-card-invitee-search]');
    const inviteeSelect = one(pane, '[data-invite-card-invitee-select]');
    const inviteeStatus = one(pane, '[data-invite-card-invitee-status]');
    const qrDataInput = one(pane, '[data-invite-card-qr-data]');
    const status = one(pane, '[data-invite-card-status]');
    const autosaveStatus = one(pane, '[data-invite-card-autosave-status]');
    const modal = one(pane, '[data-invite-card-selection-modal]');
    const selectionImage = one(pane, '[data-invite-card-selection-image]');
    const selectionLayer = one(pane, '[data-invite-card-selection-layer]');
    const qrBox = one(pane, '[data-selection-box="qr"]');
    const textBox = one(pane, '[data-selection-box="text"]');
    const draftBox = one(pane, '[data-selection-draft]');
    const selectionHelp = one(pane, '[data-selection-help]');
    const preview = one(pane, '[data-invite-card-preview]');
    const previewEmpty = one(pane, '[data-invite-card-preview-empty]');
    const outputImage = one(pane, '[data-invite-card-output-image]');
    const outputMeta = one(pane, '[data-invite-card-output-meta]');
    const exportRow = one(pane, '[data-invite-card-export-row]');
    const downloadLink = one(pane, '[data-invite-card-download]');

    if (!(fileInput instanceof HTMLInputElement) || !(sourceImage instanceof HTMLImageElement)
      || !(selectionImage instanceof HTMLImageElement) || !(selectionLayer instanceof HTMLElement)
      || !(editor instanceof HTMLElement) || !(qrDataInput instanceof HTMLInputElement)
      || !(inviteeSearch instanceof HTMLInputElement) || !(inviteeSelect instanceof HTMLSelectElement)) return;

    const state = {
      imageData: '',
      imageName: '',
      imageWidth: 0,
      imageHeight: 0,
      fontData: '',
      fontName: '',
      fontMime: '',
      fontBytes: 0,
      fontFamily: DEFAULT_INVITE_FONT_FAMILY,
      qrRect: null,
      textRect: null,
      workingQrRect: null,
      workingTextRect: null,
      activeTool: 'qr',
      dragStart: null,
      pointerId: null,
      previousBodyOverflow: '',
      generatedUrl: '',
      inviteesById: new Map(),
      inviteeRequest: 0,
      savedEditorRange: null,
      conditionalVariables: [],
      editingConditionalIndex: -1,
      conditionalValueOptions: [],
      conditionalValueField: '',
      conditionalValueRequest: 0,
      draftReady: false,
      draftTimer: 0,
      draftSaving: false,
      imageSaving: false,
      pendingDraftSections: new Set()
    };

    qrDataInput.value = '[nationalid]';
    qrDataInput.readOnly = true;

    function setStatus(message, isError) {
      if (!(status instanceof HTMLElement)) return;
      status.textContent = message || '';
      status.style.color = isError ? '#b91c1c' : '';
    }

    function setAutosaveStatus(message, isError) {
      if (!(autosaveStatus instanceof HTMLElement)) return;
      autosaveStatus.textContent = message || '';
      autosaveStatus.style.color = isError ? '#b91c1c' : '';
    }

    function collectConditionalBuilderDraft() {
      const token = cleanConditionalToken(conditionalTokenInput instanceof HTMLInputElement ? conditionalTokenInput.value : '') || 'code';
      const field = conditionalFieldSelect instanceof HTMLSelectElement ? conditionalFieldSelect.value : 'gender';
      const rules = Array.from(conditionalRules?.querySelectorAll('.egm-invite-card-condition-row') || []).map((row) => {
        const operator = String(one(row, '[data-conditional-operator]')?.value || 'equals');
        return {
          operator,
          value: operator === 'empty' || operator === 'not_empty' ? '' : String(one(row, '[data-conditional-value]')?.value || ''),
          text: String(one(row, '[data-conditional-text]')?.value || '')
        };
      });
      return {
        token,
        field,
        rules: rules.length ? rules : [{ operator: 'equals', value: '', text: '' }],
        fallback: conditionalFallbackInput instanceof HTMLInputElement ? conditionalFallbackInput.value : '',
        editingToken: state.editingConditionalIndex >= 0
          ? String(state.conditionalVariables[state.editingConditionalIndex]?.token || '')
          : ''
      };
    }

    function draftPayloadForSections(sections) {
      const payload = { sections };
      if (sections.includes('content')) {
        payload.textHtml = sanitizeEditorHtml(editor.innerHTML);
        payload.qrData = '[nationalid]';
        payload.conditionalVariables = normalizedConditionalVariables(state.conditionalVariables);
        payload.conditionalBuilderDraft = collectConditionalBuilderDraft();
      }
      if (sections.includes('font')) {
        payload.fontData = state.fontData;
        payload.fontName = state.fontName;
      }
      if (sections.includes('layout')) {
        payload.qrRect = cloneRect(state.qrRect);
        payload.textRect = cloneRect(state.textRect);
      }
      return payload;
    }

    async function flushInviteCardDraft() {
      window.clearTimeout(state.draftTimer);
      state.draftTimer = 0;
      if (!state.draftReady || state.draftSaving || !state.pendingDraftSections.size || !endpoint) return;
      if (state.imageSaving) {
        state.draftTimer = window.setTimeout(() => { void flushInviteCardDraft(); }, 250);
        return;
      }
      const sections = Array.from(state.pendingDraftSections);
      state.pendingDraftSections.clear();
      state.draftSaving = true;
      setAutosaveStatus('در حال ذخیره خودکار تغییرات...', false);
      try {
        const csrf = shell instanceof HTMLElement ? String(shell.dataset.egmCsrf || '') : '';
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('action', 'draft');
        const response = await fetch(url.toString(), {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: sections.includes('font') ? false : true,
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ ...draftPayloadForSections(sections), csrf })
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.status !== 'ok') {
          throw new Error(responseMessage(data, 'ذخیره خودکار تغییرات ناموفق بود.'));
        }
        setAutosaveStatus('همه تغییرات در پایگاه داده ذخیره شده‌اند.', false);
      } catch (error) {
        sections.forEach((section) => state.pendingDraftSections.add(section));
        setAutosaveStatus(error instanceof Error ? error.message : 'ذخیره خودکار تغییرات ناموفق بود.', true);
      } finally {
        state.draftSaving = false;
        if (state.pendingDraftSections.size) {
          state.draftTimer = window.setTimeout(() => { void flushInviteCardDraft(); }, 1200);
        }
      }
    }

    function scheduleInviteCardDraft(sections, delay = 650) {
      if (!state.draftReady) return;
      (Array.isArray(sections) ? sections : [sections]).forEach((section) => state.pendingDraftSections.add(section));
      window.clearTimeout(state.draftTimer);
      setAutosaveStatus('تغییرات جدید در انتظار ذخیره خودکار است...', false);
      state.draftTimer = window.setTimeout(() => { void flushInviteCardDraft(); }, Math.max(0, delay));
    }

    function setInviteeStatus(message, isError) {
      if (!(inviteeStatus instanceof HTMLElement)) return;
      inviteeStatus.textContent = message || '';
      inviteeStatus.style.color = isError ? '#b91c1c' : '';
    }

    function saveEditorRange() {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0) return;
      const range = selection.getRangeAt(0);
      if (editor.contains(range.commonAncestorContainer)) state.savedEditorRange = range.cloneRange();
    }

    function restoreEditorRange() {
      editor.focus();
      const selection = window.getSelection();
      if (!selection) return;
      let range = state.savedEditorRange;
      if (!range || !range.commonAncestorContainer?.isConnected || !editor.contains(range.commonAncestorContainer)) {
        range = document.createRange();
        range.selectNodeContents(editor);
        range.collapse(false);
      }
      selection.removeAllRanges();
      selection.addRange(range);
    }

    function runEditorCommand(command, value) {
      saveEditorRange();
      restoreEditorRange();
      if (command === 'insertText') {
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return;
        const range = selection.getRangeAt(0);
        range.deleteContents();
        const textNode = document.createTextNode(String(value || ''));
        range.insertNode(textNode);
        range.setStartAfter(textNode);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        saveEditorRange();
        markPreviewStale();
        scheduleInviteCardDraft('content');
        return;
      }
      if (command === 'bold') document.execCommand('styleWithCSS', false, false);
      document.execCommand(command, false, value ?? null);
      saveEditorRange();
      markPreviewStale();
      scheduleInviteCardDraft('content');
    }

    function applyEditorColor(color) {
      const normalized = normalizedEditorColor(color);
      if (!normalized) {
        setStatus('رنگ انتخاب‌شده معتبر نیست.', true);
        return;
      }
      restoreEditorRange();
      const selection = window.getSelection();
      const range = selection && selection.rangeCount ? selection.getRangeAt(0) : null;
      if (!range || !editor.contains(range.commonAncestorContainer)) {
        setStatus('ابتدا بخشی از متن را انتخاب کنید.', true);
        return;
      }
      if (range.collapsed) {
        setStatus('برای تغییر رنگ، ابتدا بخشی از متن یا یک متغیر را انتخاب کنید.', true);
        return;
      }
      const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
      const selectedNodes = [];
      let node = walker.nextNode();
      while (node) {
        try {
          if (range.intersectsNode(node)) selectedNodes.push(node);
        } catch (_) { /* A detached text node is ignored. */ }
        node = walker.nextNode();
      }
      selectedNodes.reverse().forEach((textNode) => {
        const length = textNode.nodeValue?.length || 0;
        const start = textNode === range.startContainer ? Math.max(0, Math.min(length, range.startOffset)) : 0;
        const end = textNode === range.endContainer ? Math.max(start, Math.min(length, range.endOffset)) : length;
        if (end <= start) return;
        const selectedText = start > 0 ? textNode.splitText(start) : textNode;
        if (end - start < (selectedText.nodeValue?.length || 0)) selectedText.splitText(end - start);
        const span = document.createElement('span');
        span.style.color = normalized;
        selectedText.parentNode?.insertBefore(span, selectedText);
        span.append(selectedText);
      });
      editor.innerHTML = sanitizeEditorHtml(editor.innerHTML);
      state.savedEditorRange = null;
      editor.focus();
      markPreviewStale();
      setStatus(`رنگ ${normalized} روی بخش انتخاب‌شده اعمال شد.`, false);
      scheduleInviteCardDraft('content');
    }

    function updateFontUi() {
      if (fontStatus instanceof HTMLElement) {
        fontStatus.textContent = state.fontData
          ? `${state.fontName || 'فونت اختصاصی'} — ${(state.fontBytes / 1024).toFixed(0)} KB`
          : 'فونت پیش‌فرض استفاده می‌شود.';
      }
      if (removeFontButton instanceof HTMLButtonElement) removeFontButton.hidden = !state.fontData;
    }

    async function applyStateFont() {
      state.fontFamily = await ensureInviteCardFont(state.fontData);
      editor.style.fontFamily = state.fontFamily;
      updateFontUi();
    }

    function setConditionalStatus(message, isError) {
      if (!(conditionalStatus instanceof HTMLElement)) return;
      conditionalStatus.textContent = message || '';
      conditionalStatus.style.color = isError ? '#b91c1c' : '';
    }

    function cleanConditionalToken(value) {
      return String(value || '').trim().replace(/^\[|\]$/g, '').toLowerCase();
    }

    function fillConditionalValueSelect(select, selectedValue = '') {
      if (!(select instanceof HTMLSelectElement)) return;
      const current = String(selectedValue || select.value || '');
      select.replaceChildren(new Option('انتخاب مقدار از اطلاعات کاربران', ''));
      state.conditionalValueOptions.forEach((value) => select.add(new Option(value, value)));
      if (current && !state.conditionalValueOptions.includes(current)) {
        select.add(new Option(`${current} — مقدار ذخیره‌شده`, current));
      }
      select.value = current;
    }

    function updateConditionalValueSelects() {
      conditionalRules?.querySelectorAll('[data-conditional-value]').forEach((select) => {
        fillConditionalValueSelect(select, select.value);
      });
    }

    async function loadConditionalFieldValues(field) {
      const normalizedField = String(field || '').trim().toLowerCase();
      if (!Object.hasOwn(CONDITIONAL_FIELD_LABELS, normalizedField) || !endpoint) return;
      const requestId = ++state.conditionalValueRequest;
      state.conditionalValueField = normalizedField;
      state.conditionalValueOptions = [];
      updateConditionalValueSelects();
      setConditionalStatus(`در حال دریافت مقادیر ستون «${CONDITIONAL_FIELD_LABELS[normalizedField]}»...`, false);
      try {
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('action', 'conditional_values');
        url.searchParams.set('field', normalizedField);
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const data = await response.json().catch(() => null);
        if (requestId !== state.conditionalValueRequest) return;
        if (!response.ok || !data || data.status !== 'ok' || !Array.isArray(data.rows)) {
          throw new Error(responseMessage(data, 'دریافت مقادیر ستون ناموفق بود.'));
        }
        state.conditionalValueOptions = Array.from(new Set(data.rows.map((value) => String(value || '').trim()).filter(Boolean)));
        updateConditionalValueSelects();
        const more = data.hasMore ? '؛ فقط 2000 مقدار اول نمایش داده می‌شود.' : '';
        setConditionalStatus(`${state.conditionalValueOptions.length} مقدار از ستون «${CONDITIONAL_FIELD_LABELS[normalizedField]}» دریافت شد${more}`, false);
      } catch (error) {
        if (requestId !== state.conditionalValueRequest) return;
        setConditionalStatus(error instanceof Error ? error.message : 'دریافت مقادیر ستون ناموفق بود.', true);
      }
    }

    function createConditionalRuleRow(rule = {}) {
      if (!(conditionalRules instanceof HTMLElement)) return null;
      const row = document.createElement('div');
      row.className = 'egm-invite-card-condition-row';
      const operator = document.createElement('select');
      operator.dataset.conditionalOperator = '1';
      operator.setAttribute('aria-label', 'نوع شرط');
      Object.entries(CONDITIONAL_OPERATOR_LABELS).forEach(([value, label]) => operator.add(new Option(label, value)));
      operator.value = Object.hasOwn(CONDITIONAL_OPERATOR_LABELS, rule.operator) ? rule.operator : 'equals';
      const expected = document.createElement('select');
      expected.dataset.conditionalValue = '1';
      expected.setAttribute('aria-label', 'مقدار شرط');
      fillConditionalValueSelect(expected, String(rule.value || ''));
      const replacement = document.createElement('input');
      replacement.type = 'text';
      replacement.maxLength = 500;
      replacement.dataset.conditionalText = '1';
      replacement.setAttribute('aria-label', 'متن جایگزین');
      replacement.placeholder = 'مثلاً جناب آقای';
      replacement.value = String(rule.text || '');
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn ghost';
      remove.dataset.action = 'remove-invite-card-condition';
      remove.setAttribute('aria-label', 'حذف شرط');
      remove.title = 'حذف شرط';
      remove.textContent = '×';
      const updateExpectedState = () => {
        const notUsed = operator.value === 'empty' || operator.value === 'not_empty';
        expected.disabled = notUsed;
        expected.classList.toggle('is-not-used', notUsed);
        if (notUsed) expected.value = '';
      };
      operator.addEventListener('change', updateExpectedState);
      remove.addEventListener('click', () => {
        if (conditionalRules.children.length <= 1) {
          setConditionalStatus('هر متغیر باید حداقل یک شرط داشته باشد.', true);
          return;
        }
        row.remove();
        setConditionalStatus('', false);
        scheduleInviteCardDraft('content');
      });
      row.append(operator, expected, replacement, remove);
      conditionalRules.append(row);
      updateExpectedState();
      return row;
    }

    function defaultConditionalDefinition() {
      return {
        token: 'code',
        field: 'gender',
        rules: [{ operator: 'equals', value: '', text: '' }],
        fallback: ''
      };
    }

    function fillConditionalBuilder(definition = defaultConditionalDefinition(), editingIndex = -1) {
      state.editingConditionalIndex = editingIndex;
      if (conditionalTokenInput instanceof HTMLInputElement) conditionalTokenInput.value = String(definition.token || 'code');
      if (conditionalFieldSelect instanceof HTMLSelectElement) conditionalFieldSelect.value = String(definition.field || 'gender');
      if (conditionalFallbackInput instanceof HTMLInputElement) conditionalFallbackInput.value = String(definition.fallback || '');
      if (conditionalRules instanceof HTMLElement) conditionalRules.replaceChildren();
      const rules = Array.isArray(definition.rules) && definition.rules.length ? definition.rules : [{ operator: 'equals', value: '', text: '' }];
      rules.forEach((rule) => createConditionalRuleRow(rule));
      const token = cleanConditionalToken(definition.token || 'code') || 'code';
      if (conditionalTokenPreview instanceof HTMLElement) conditionalTokenPreview.textContent = `[${token}]`;
      const saveConditionButton = one(pane, '[data-action="save-invite-card-condition"]');
      if (saveConditionButton instanceof HTMLButtonElement) {
        saveConditionButton.textContent = editingIndex >= 0 ? 'ذخیره تغییرات متغیر' : 'ثبت متغیر شرطی';
      }
      setConditionalStatus('', false);
      void loadConditionalFieldValues(String(definition.field || 'gender'));
    }

    function readConditionalBuilder() {
      const token = cleanConditionalToken(conditionalTokenInput instanceof HTMLInputElement ? conditionalTokenInput.value : '');
      if (!/^[a-z][a-z0-9_]{0,31}$/.test(token)) {
        throw new Error('نام متغیر باید با حرف انگلیسی شروع شود و فقط حروف، عدد یا _ داشته باشد.');
      }
      if (BUILTIN_MERGE_KEYS.has(token)) throw new Error(`[${token}] یک متغیر اصلی سیستم است؛ نام دیگری انتخاب کنید.`);
      const duplicateIndex = state.conditionalVariables.findIndex((item) => item.token === token);
      if (duplicateIndex >= 0 && duplicateIndex !== state.editingConditionalIndex) throw new Error(`متغیر [${token}] قبلاً ساخته شده است.`);
      const field = conditionalFieldSelect instanceof HTMLSelectElement ? conditionalFieldSelect.value : '';
      if (!Object.hasOwn(CONDITIONAL_FIELD_LABELS, field)) throw new Error('اطلاعات مورد بررسی را انتخاب کنید.');
      const rules = Array.from(conditionalRules?.querySelectorAll('.egm-invite-card-condition-row') || []).map((row) => {
        const operator = one(row, '[data-conditional-operator]')?.value || '';
        const value = operator === 'empty' || operator === 'not_empty' ? '' : String(one(row, '[data-conditional-value]')?.value || '').trim();
        const text = String(one(row, '[data-conditional-text]')?.value || '').trim();
        if (!Object.hasOwn(CONDITIONAL_OPERATOR_LABELS, operator)) throw new Error('نوع یکی از شرط‌ها معتبر نیست.');
        if (!value && operator !== 'empty' && operator !== 'not_empty') throw new Error('مقدار همه شرط‌ها را وارد کنید.');
        return { operator, value, text };
      });
      if (!rules.length) throw new Error('حداقل یک شرط اضافه کنید.');
      return {
        token,
        field,
        rules,
        fallback: conditionalFallbackInput instanceof HTMLInputElement ? conditionalFallbackInput.value.trim() : ''
      };
    }

    function renderConditionalVariables() {
      if (mergeTagSelect instanceof HTMLSelectElement) {
        mergeTagSelect.querySelectorAll('[data-conditional-option]').forEach((option) => option.remove());
        state.conditionalVariables.forEach((definition) => {
          const option = new Option(`[${definition.token}] — متغیر شرطی`, `[${definition.token}]`);
          option.dataset.conditionalOption = '1';
          mergeTagSelect.add(option);
        });
      }
      if (!(conditionalList instanceof HTMLElement)) return;
      conditionalList.replaceChildren();
      if (!state.conditionalVariables.length) {
        const empty = document.createElement('span');
        empty.className = 'muted small';
        empty.textContent = 'هنوز متغیر شرطی ثبت نشده است.';
        conditionalList.append(empty);
        return;
      }
      state.conditionalVariables.forEach((definition, index) => {
        const item = document.createElement('div');
        item.className = 'egm-invite-card-conditional-item';
        const description = document.createElement('div');
        const token = document.createElement('span');
        token.className = 'egm-invite-card-conditional-token';
        token.dir = 'ltr';
        token.textContent = `[${definition.token}]`;
        const summary = document.createElement('span');
        summary.className = 'muted small';
        summary.textContent = ` — بر اساس ${CONDITIONAL_FIELD_LABELS[definition.field]}، ${definition.rules.length} شرط`;
        description.append(token, summary);
        const actions = document.createElement('div');
        actions.className = 'egm-invite-card-conditional-item-actions';
        const insert = document.createElement('button');
        insert.type = 'button';
        insert.className = 'btn ghost';
        insert.textContent = 'درج در متن';
        insert.addEventListener('mousedown', (event) => event.preventDefault());
        insert.addEventListener('click', () => runEditorCommand('insertText', `[${definition.token}]`));
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn ghost';
        edit.textContent = 'ویرایش';
        edit.addEventListener('click', () => {
          fillConditionalBuilder(definition, index);
          conditionalBuilder?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn ghost';
        remove.textContent = 'حذف';
        remove.addEventListener('click', () => {
          if (!window.confirm(`متغیر [${definition.token}] حذف شود؟ متن‌هایی که از آن استفاده می‌کنند تغییر نمی‌کنند.`)) return;
          state.conditionalVariables.splice(index, 1);
          renderConditionalVariables();
          fillConditionalBuilder();
          markPreviewStale();
          scheduleInviteCardDraft('content', 0);
          setConditionalStatus('متغیر حذف شد و تغییر در حال ذخیره خودکار است.', false);
        });
        actions.append(insert, edit, remove);
        item.append(description, actions);
        conditionalList.append(item);
      });
    }

    function selectedInvitee() {
      return state.inviteesById.get(inviteeSelect.value) || null;
    }

    async function loadInvitees(query) {
      const requestId = ++state.inviteeRequest;
      const previousValue = inviteeSelect.value;
      setInviteeStatus('در حال بارگذاری دعوت‌شدگان...', false);
      try {
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('action', 'invitees');
        if (String(query || '').trim()) url.searchParams.set('q', String(query).trim());
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const result = await response.json().catch(() => null);
        if (requestId !== state.inviteeRequest) return;
        if (!response.ok || !result || result.status !== 'ok' || !Array.isArray(result.rows)) {
          throw new Error(responseMessage(result, 'بارگذاری دعوت‌شدگان ناموفق بود.'));
        }
        state.inviteesById = new Map();
        inviteeSelect.replaceChildren(new Option('ابتدا دعوت‌شونده را انتخاب کنید', ''));
        result.rows.forEach((invitee) => {
          const id = String(invitee?.id || '');
          if (!id) return;
          state.inviteesById.set(id, invitee);
          const fullName = [invitee.firstName, invitee.lastName].map((value) => String(value || '').trim()).filter(Boolean).join(' ') || 'بدون نام';
          const details = [invitee.guestNumber ? `مهمان ${invitee.guestNumber}` : '', invitee.workId ? `کد ${invitee.workId}` : '', invitee.nationalId ? `ملی ${invitee.nationalId}` : ''].filter(Boolean).join(' — ');
          inviteeSelect.add(new Option(details ? `${fullName} — ${details}` : fullName, id));
        });
        if (previousValue && state.inviteesById.has(previousValue)) inviteeSelect.value = previousValue;
        const suffix = result.hasMore ? '؛ برای نتایج بیشتر جستجو را دقیق‌تر کنید.' : '';
        setInviteeStatus(`${state.inviteesById.size} دعوت‌شونده نمایش داده شد${suffix}`, false);
      } catch (error) {
        if (requestId !== state.inviteeRequest) return;
        setInviteeStatus(error instanceof Error ? error.message : 'بارگذاری دعوت‌شدگان ناموفق بود.', true);
      }
    }

    function markPreviewStale() {
      if (state.generatedUrl) {
        URL.revokeObjectURL(state.generatedUrl);
        state.generatedUrl = '';
      }
      if (preview instanceof HTMLElement) preview.hidden = true;
      if (exportRow instanceof HTMLElement) exportRow.hidden = true;
      if (outputImage instanceof HTMLImageElement) outputImage.removeAttribute('src');
      if (downloadLink instanceof HTMLAnchorElement) downloadLink.removeAttribute('href');
      if (previewEmpty instanceof HTMLElement) {
        previewEmpty.hidden = false;
        previewEmpty.textContent = 'برای مشاهده خروجی به‌روز روی Test Generate کلیک کنید.';
      }
    }

    function updateSource() {
      const hasImage = state.imageData !== '';
      sourceImage.hidden = !hasImage;
      if (hasImage) sourceImage.src = state.imageData;
      else sourceImage.removeAttribute('src');
      if (sourceEmpty instanceof HTMLElement) sourceEmpty.hidden = hasImage;
      if (selectionButton instanceof HTMLButtonElement) selectionButton.disabled = !hasImage;
      if (fileName instanceof HTMLElement) {
        fileName.textContent = hasImage
          ? `${state.imageName || 'invite-card'} — ${state.imageWidth}×${state.imageHeight}`
          : 'هنوز تصویری انتخاب نشده است.';
      }
    }

    function updateAreaStates() {
      const entries = [
        ['qr', state.qrRect, 'QR'],
        ['text', state.textRect, 'متن']
      ];
      entries.forEach(([key, rect, label]) => {
        const element = one(pane, `[data-area-state="${key}"]`);
        if (!(element instanceof HTMLElement)) return;
        element.classList.toggle('is-ready', Boolean(rect));
        element.textContent = rect
          ? `ناحیه ${label}: ${rect.width.toFixed(1)}×${rect.height.toFixed(1)}٪`
          : `ناحیه ${label}: تعیین نشده`;
      });
    }

    function updateSelectionBoxes() {
      applyRect(qrBox, state.workingQrRect);
      applyRect(textBox, state.workingTextRect);
    }

    function setActiveTool(tool) {
      state.activeTool = tool === 'text' ? 'text' : 'qr';
      pane.querySelectorAll('[data-selection-tool]').forEach((button) => {
        const active = button instanceof HTMLElement && button.dataset.selectionTool === state.activeTool;
        button.classList.toggle('active', active);
        button.classList.toggle('primary', active);
        button.classList.toggle('standard-primary-button', active);
        button.classList.toggle('ghost', !active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      if (selectionHelp instanceof HTMLElement) {
        selectionHelp.textContent = state.activeTool === 'qr'
          ? 'ابزار QR Code فعال است؛ روی تصویر بکشید.'
          : 'ابزار Invite Text Area فعال است؛ روی تصویر بکشید.';
      }
    }

    function openSelectionModal() {
      if (!state.imageData || !(modal instanceof HTMLElement)) return;
      state.workingQrRect = cloneRect(state.qrRect);
      state.workingTextRect = cloneRect(state.textRect);
      state.dragStart = null;
      state.pointerId = null;
      if (draftBox instanceof HTMLElement) draftBox.hidden = true;
      selectionImage.src = state.imageData;
      updateSelectionBoxes();
      setActiveTool(state.activeTool);
      modal.classList.remove('hidden');
      modal.setAttribute('aria-hidden', 'false');
      state.previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    }

    function closeSelectionModal(commit) {
      if (!(modal instanceof HTMLElement)) return;
      if (commit) {
        state.qrRect = cloneRect(state.workingQrRect);
        state.textRect = cloneRect(state.workingTextRect);
        updateAreaStates();
        markPreviewStale();
        scheduleInviteCardDraft('layout', 0);
      }
      state.dragStart = null;
      state.pointerId = null;
      if (draftBox instanceof HTMLElement) draftBox.hidden = true;
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = state.previousBodyOverflow;
    }

    function pointForEvent(event) {
      const bounds = selectionLayer.getBoundingClientRect();
      if (bounds.width <= 0 || bounds.height <= 0) return null;
      return {
        x: Math.max(0, Math.min(100, ((event.clientX - bounds.left) / bounds.width) * 100)),
        y: Math.max(0, Math.min(100, ((event.clientY - bounds.top) / bounds.height) * 100))
      };
    }

    function rectFromPoints(first, second) {
      return {
        x: Math.min(first.x, second.x),
        y: Math.min(first.y, second.y),
        width: Math.abs(second.x - first.x),
        height: Math.abs(second.y - first.y)
      };
    }

    selectionLayer.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 && event.pointerType !== 'touch') return;
      const point = pointForEvent(event);
      if (!point) return;
      event.preventDefault();
      state.dragStart = point;
      state.pointerId = event.pointerId;
      try { selectionLayer.setPointerCapture(event.pointerId); } catch (_) { /* no-op */ }
      applyRect(draftBox, { x: point.x, y: point.y, width: 0.01, height: 0.01 });
    });

    selectionLayer.addEventListener('pointermove', (event) => {
      if (!state.dragStart || state.pointerId !== event.pointerId) return;
      const point = pointForEvent(event);
      if (!point) return;
      event.preventDefault();
      applyRect(draftBox, rectFromPoints(state.dragStart, point));
    });

    function finishDrag(event) {
      if (!state.dragStart || state.pointerId !== event.pointerId) return;
      const point = pointForEvent(event);
      const nextRect = point ? rectFromPoints(state.dragStart, point) : null;
      state.dragStart = null;
      state.pointerId = null;
      if (draftBox instanceof HTMLElement) draftBox.hidden = true;
      if (!nextRect || nextRect.width < 0.5 || nextRect.height < 0.5) {
        if (selectionHelp instanceof HTMLElement) selectionHelp.textContent = 'کادر خیلی کوچک بود؛ یک ناحیه بزرگ‌تر بکشید.';
        return;
      }
      if (state.activeTool === 'qr') state.workingQrRect = nextRect;
      else state.workingTextRect = nextRect;
      updateSelectionBoxes();
      if (selectionHelp instanceof HTMLElement) {
        selectionHelp.textContent = state.activeTool === 'qr'
          ? 'ناحیه QR Code ثبت شد. اکنون می‌توانید ناحیه متن را تعیین کنید.'
          : 'ناحیه Invite Text Area ثبت شد.';
      }
    }

    selectionLayer.addEventListener('pointerup', finishDrag);
    selectionLayer.addEventListener('pointercancel', finishDrag);

    pane.querySelectorAll('[data-selection-tool]').forEach((button) => {
      button.addEventListener('click', () => setActiveTool(button.dataset.selectionTool));
    });

    pane.querySelectorAll('[data-action="cancel-invite-card-selection"]').forEach((button) => {
      button.addEventListener('click', () => closeSelectionModal(false));
    });

    one(pane, '[data-action="confirm-invite-card-selection"]')?.addEventListener('click', () => closeSelectionModal(true));
    one(pane, '[data-action="clear-current-selection"]')?.addEventListener('click', () => {
      if (state.activeTool === 'qr') state.workingQrRect = null;
      else state.workingTextRect = null;
      updateSelectionBoxes();
      if (selectionHelp instanceof HTMLElement) selectionHelp.textContent = 'ناحیه فعال پاک شد.';
    });

    chooseButton?.addEventListener('click', () => fileInput.click());
    selectionButton?.addEventListener('click', openSelectionModal);

    fileInput.addEventListener('change', async () => {
      const file = fileInput.files && fileInput.files[0];
      if (!(file instanceof File)) return;
      setStatus('', false);
      if (!ACCEPTED_IMAGE_TYPES.has(file.type)) {
        setStatus('فرمت تصویر باید PNG، JPG یا WebP باشد.', true);
        fileInput.value = '';
        return;
      }
      if (file.size > MAX_IMAGE_BYTES) {
        setStatus('حجم تصویر نباید بیشتر از 8 مگابایت باشد.', true);
        fileInput.value = '';
        return;
      }
      try {
        const dataUrl = await readFileAsDataUrl(file);
        const dimensions = await readImageDimensions(dataUrl);
        if (dimensions.width * dimensions.height > MAX_IMAGE_PIXELS) {
          throw new Error('ابعاد تصویر نباید بیشتر از 40 میلیون پیکسل باشد.');
        }
        state.imageData = dataUrl;
        state.imageName = file.name;
        state.imageWidth = dimensions.width;
        state.imageHeight = dimensions.height;
        state.qrRect = null;
        state.textRect = null;
        updateSource();
        updateAreaStates();
        markPreviewStale();
        setStatus('در حال ذخیره تصویر در پایگاه داده...', false);
        const saved = await saveUploadedImage();
        setStatus(responseMessage(saved, 'تصویر ذخیره شد؛ با Selection Tool ناحیه QR و متن را مشخص کنید.'), false);
        setAutosaveStatus('تصویر کارت در پایگاه داده ذخیره شده است.', false);
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'بارگذاری تصویر ناموفق بود.', true);
      } finally {
        fileInput.value = '';
      }
    });

    editor.addEventListener('input', () => {
      saveEditorRange();
      markPreviewStale();
      scheduleInviteCardDraft('content');
    });
    editor.addEventListener('keyup', saveEditorRange);
    editor.addEventListener('mouseup', saveEditorRange);
    editor.addEventListener('focus', saveEditorRange);
    editor.addEventListener('keydown', (event) => {
      if (!(event.ctrlKey || event.metaKey)) return;
      const key = String(event.key || '').toLowerCase();
      if (key === 'b') {
        event.preventDefault();
        runEditorCommand('bold');
      } else if (key === 'l') {
        event.preventDefault();
        runEditorCommand('insertUnorderedList');
      }
    });
    editor.addEventListener('paste', (event) => {
      event.preventDefault();
      const plainText = event.clipboardData?.getData('text/plain') || '';
      runEditorCommand('insertText', plainText);
    });
    one(pane, '[data-action="apply-invite-card-text-color"]')?.addEventListener('mousedown', (event) => event.preventDefault());
    one(pane, '[data-action="apply-invite-card-text-color"]')?.addEventListener('click', () => {
      applyEditorColor(textColorInput instanceof HTMLInputElement ? textColorInput.value : '#111827');
    });
    one(pane, '[data-action="clear-invite-card-text-color"]')?.addEventListener('mousedown', (event) => event.preventDefault());
    one(pane, '[data-action="clear-invite-card-text-color"]')?.addEventListener('click', () => applyEditorColor('#111827'));
    chooseFontButton?.addEventListener('click', () => {
      if (fontFileInput instanceof HTMLInputElement) fontFileInput.click();
    });
    fontFileInput?.addEventListener('change', async () => {
      const file = fontFileInput.files && fontFileInput.files[0];
      if (!(file instanceof File)) return;
      if (fontStatus instanceof HTMLElement) fontStatus.textContent = 'در حال بررسی و بارگذاری فونت...';
      try {
        const normalized = await normalizedFontDataUrl(file);
        const family = await ensureInviteCardFont(normalized.data);
        state.fontData = normalized.data;
        state.fontName = file.name;
        state.fontMime = normalized.mime;
        state.fontBytes = normalized.bytes;
        state.fontFamily = family;
        editor.style.fontFamily = family;
        updateFontUi();
        markPreviewStale();
        scheduleInviteCardDraft('font', 0);
        setStatus('فونت آماده است و به‌صورت خودکار ذخیره می‌شود.', false);
      } catch (error) {
        updateFontUi();
        setStatus(error instanceof Error ? error.message : 'بارگذاری فونت ناموفق بود.', true);
      } finally {
        fontFileInput.value = '';
      }
    });
    removeFontButton?.addEventListener('click', async () => {
      state.fontData = '';
      state.fontName = '';
      state.fontMime = '';
      state.fontBytes = 0;
      try {
        await applyStateFont();
        markPreviewStale();
        scheduleInviteCardDraft('font', 0);
        setStatus('فونت اختصاصی حذف شد و تغییر به‌صورت خودکار ذخیره می‌شود.', false);
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'بازنشانی فونت ناموفق بود.', true);
      }
    });
    pane.querySelectorAll('[data-editor-command]').forEach((button) => {
      button.addEventListener('mousedown', (event) => event.preventDefault());
      button.addEventListener('click', () => runEditorCommand(String(button.dataset.editorCommand || '')));
    });
    insertMergeTagButton?.addEventListener('mousedown', (event) => event.preventDefault());
    insertMergeTagButton?.addEventListener('click', () => {
      const tag = mergeTagSelect instanceof HTMLSelectElement ? mergeTagSelect.value : '[fullname]';
      runEditorCommand('insertText', tag || '[fullname]');
    });
    conditionalTokenInput?.addEventListener('input', () => {
      const token = cleanConditionalToken(conditionalTokenInput.value) || 'code';
      if (conditionalTokenPreview instanceof HTMLElement) conditionalTokenPreview.textContent = `[${token}]`;
    });
    conditionalBuilder?.addEventListener('input', () => scheduleInviteCardDraft('content'));
    conditionalBuilder?.addEventListener('change', () => scheduleInviteCardDraft('content'));
    conditionalFieldSelect?.addEventListener('change', () => {
      conditionalRules?.querySelectorAll('[data-conditional-value]').forEach((select) => { select.value = ''; });
      void loadConditionalFieldValues(conditionalFieldSelect.value);
    });
    one(pane, '[data-action="add-invite-card-condition"]')?.addEventListener('click', () => {
      if ((conditionalRules?.children.length || 0) >= 20) {
        setConditionalStatus('برای هر متغیر حداکثر 20 شرط قابل تعریف است.', true);
        return;
      }
      createConditionalRuleRow({ operator: 'equals', value: '', text: '' });
      setConditionalStatus('', false);
      scheduleInviteCardDraft('content');
    });
    one(pane, '[data-action="reset-invite-card-condition"]')?.addEventListener('click', () => {
      fillConditionalBuilder();
      scheduleInviteCardDraft('content');
    });
    function saveConditionalBuilder(insertAfterSave) {
      try {
        const definition = readConditionalBuilder();
        if (state.editingConditionalIndex >= 0) state.conditionalVariables[state.editingConditionalIndex] = definition;
        else {
          if (state.conditionalVariables.length >= 50) throw new Error('حداکثر 50 متغیر شرطی قابل تعریف است.');
          state.conditionalVariables.push(definition);
        }
        state.conditionalVariables = normalizedConditionalVariables(state.conditionalVariables);
        renderConditionalVariables();
        const savedToken = definition.token;
        fillConditionalBuilder();
        markPreviewStale();
        scheduleInviteCardDraft('content', 0);
        if (insertAfterSave) runEditorCommand('insertText', `[${savedToken}]`);
        setConditionalStatus(
          insertAfterSave
            ? `متغیر [${savedToken}] ثبت و داخل متن درج شد؛ تغییرات در حال ذخیره خودکار است.`
            : `متغیر [${savedToken}] آماده است و به‌صورت خودکار ذخیره می‌شود.`,
          false
        );
      } catch (error) {
        setConditionalStatus(error instanceof Error ? error.message : 'ثبت متغیر شرطی ناموفق بود.', true);
      }
    }
    one(pane, '[data-action="save-invite-card-condition"]')?.addEventListener('click', () => saveConditionalBuilder(false));
    const saveAndInsertCondition = one(pane, '[data-action="save-invite-card-condition-and-insert"]');
    saveAndInsertCondition?.addEventListener('mousedown', (event) => event.preventDefault());
    saveAndInsertCondition?.addEventListener('click', () => saveConditionalBuilder(true));
    fillConditionalBuilder();
    renderConditionalVariables();
    updateFontUi();
    let inviteeSearchTimer = 0;
    inviteeSearch.addEventListener('input', () => {
      window.clearTimeout(inviteeSearchTimer);
      inviteeSearchTimer = window.setTimeout(() => { void loadInvitees(inviteeSearch.value); }, 250);
    });
    inviteeSelect.addEventListener('change', markPreviewStale);
    qrDataInput.addEventListener('input', () => {
      markPreviewStale();
      scheduleInviteCardDraft('content');
    });

    function collectConfig() {
      if (!state.imageData) throw new Error('ابتدا تصویر کارت دعوت را انتخاب کنید.');
      if (!state.qrRect) throw new Error('ناحیه QR Code را با Selection Tool تعیین کنید.');
      if (!state.textRect) throw new Error('ناحیه Invite Text Area را با Selection Tool تعیین کنید.');
      const textHtml = sanitizeEditorHtml(editor.innerHTML);
      const text = editor.innerText.trim();
      const qrData = '[nationalid]';
      if (!text) throw new Error('متن کارت دعوت را وارد کنید.');
      return {
        imageData: state.imageData,
        imageName: state.imageName,
        fontData: state.fontData,
        fontName: state.fontName,
        qrRect: cloneRect(state.qrRect),
        textRect: cloneRect(state.textRect),
        text,
        textHtml,
        conditionalVariables: normalizedConditionalVariables(state.conditionalVariables),
        conditionalBuilderDraft: collectConditionalBuilderDraft(),
        qrData
      };
    }

    async function saveUploadedImage() {
      if (!endpoint) throw new Error('آدرس ذخیره‌سازی کارت دعوت تنظیم نشده است.');
      while (state.draftSaving) {
        await new Promise((resolve) => window.setTimeout(resolve, 50));
      }
      state.imageSaving = true;
      const csrf = shell instanceof HTMLElement ? String(shell.dataset.egmCsrf || '') : '';
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('action', 'image');
      try {
        const response = await fetch(url.toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ imageData: state.imageData, imageName: state.imageName, csrf })
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data || data.status !== 'ok') {
          throw new Error(responseMessage(data, 'ذخیره تصویر کارت دعوت ناموفق بود.'));
        }
        return data;
      } finally {
        state.imageSaving = false;
        if (state.pendingDraftSections.size) scheduleInviteCardDraft(Array.from(state.pendingDraftSections), 0);
      }
    }

    async function saveConfig(config = collectConfig()) {
      if (!endpoint) throw new Error('آدرس ذخیره‌سازی کارت دعوت تنظیم نشده است.');
      const csrf = shell instanceof HTMLElement ? String(shell.dataset.egmCsrf || '') : '';
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ ...config, csrf })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data || data.status !== 'ok') {
        throw new Error(responseMessage(data, 'ذخیره تنظیمات کارت دعوت ناموفق بود.'));
      }
      return data;
    }

    saveButton?.addEventListener('click', async () => {
      if (saveButton instanceof HTMLButtonElement) saveButton.disabled = true;
      setStatus('در حال ذخیره در پایگاه داده...', false);
      try {
        const data = await saveConfig();
        window.clearTimeout(state.draftTimer);
        state.pendingDraftSections.clear();
        setAutosaveStatus('همه تنظیمات در پایگاه داده ذخیره شده‌اند.', false);
        setStatus(responseMessage(data, 'تنظیمات کارت دعوت ذخیره شد.'), false);
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'ذخیره تنظیمات کارت دعوت ناموفق بود.', true);
      } finally {
        if (saveButton instanceof HTMLButtonElement) saveButton.disabled = false;
      }
    });

    generateButton?.addEventListener('click', async () => {
      if (generateButton instanceof HTMLButtonElement) generateButton.disabled = true;
      try {
        const config = collectConfig();
        setStatus('ابتدا تنظیمات کارت دعوت در پایگاه داده ذخیره می‌شود...', false);
        await saveConfig(config);
        window.clearTimeout(state.draftTimer);
        state.pendingDraftSections.clear();
        setAutosaveStatus('همه تنظیمات در پایگاه داده ذخیره شده‌اند.', false);
        setStatus('تنظیمات ذخیره شد؛ در حال ساخت تصویر PNG با ابعاد کامل...', false);
        const invitee = selectedInvitee();
        if (!invitee) throw new Error('برای Test Generate یک دعوت‌شونده را از فهرست انتخاب کنید.');
        if (!(preview instanceof HTMLElement) || !(outputImage instanceof HTMLImageElement)
          || !(downloadLink instanceof HTMLAnchorElement)) return;
        const background = await loadCanvasImage(config.imageData);
        const fontFamily = await ensureInviteCardFont(config.fontData);
        const width = background.naturalWidth;
        const height = background.naturalHeight;
        if (width < 1 || height < 1 || width * height > MAX_IMAGE_PIXELS) {
          throw new Error('ابعاد تصویر برای خروجی امن بسیار بزرگ است؛ حداکثر 40 میلیون پیکسل مجاز است.');
        }
        const params = new URLSearchParams({
          data: inviteeQrIdentifier(invitee),
          size: '1024',
          margin: '2',
          ecc: 'M',
          response: 'svg'
        });
        const qrImage = await loadCanvasImage(`${qrEndpoint}?${params.toString()}`);
        if (document.fonts && document.fonts.ready) await document.fonts.ready;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error('Canvas مرورگر برای ساخت تصویر در دسترس نیست.');
        context.drawImage(background, 0, 0, width, height);
        const qrArea = {
          x: width * config.qrRect.x / 100,
          y: height * config.qrRect.y / 100,
          width: width * config.qrRect.width / 100,
          height: height * config.qrRect.height / 100
        };
        const qrSide = Math.min(qrArea.width, qrArea.height);
        context.drawImage(
          qrImage,
          qrArea.x + (qrArea.width - qrSide) / 2,
          qrArea.y + (qrArea.height - qrSide) / 2,
          qrSide,
          qrSide
        );
        drawInviteText(context, config.textHtml, invitee, {
          x: width * config.textRect.x / 100,
          y: height * config.textRect.y / 100,
          width: width * config.textRect.width / 100,
          height: height * config.textRect.height / 100
        }, config.conditionalVariables, fontFamily);
        const pngBlob = await canvasToPngBlob(canvas);
        if (state.generatedUrl) URL.revokeObjectURL(state.generatedUrl);
        state.generatedUrl = URL.createObjectURL(pngBlob);
        outputImage.src = state.generatedUrl;
        downloadLink.href = state.generatedUrl;
        const baseName = (state.imageName || 'invite-card').replace(/\.[^.]+$/, '').replace(/[\\/:*?"<>|]+/g, '-');
        const inviteeCode = String(invitee.workId || invitee.nationalId || invitee.id || '').replace(/[^A-Za-z0-9_-]+/g, '-');
        downloadLink.download = `${baseName || 'invite-card'}-${inviteeCode || 'generated'}.png`;
        preview.hidden = false;
        if (previewEmpty instanceof HTMLElement) previewEmpty.hidden = true;
        if (exportRow instanceof HTMLElement) exportRow.hidden = false;
        if (outputMeta instanceof HTMLElement) {
          const fullName = inviteeMergeValues(invitee).fullname || invitee.workId || 'دعوت‌شونده';
          outputMeta.textContent = `PNG واقعی برای ${fullName} — ${width}×${height} پیکسل — ${(pngBlob.size / 1024 / 1024).toFixed(2)} MB`;
        }
        setStatus('تصویر نهایی PNG ساخته شد؛ همه عناصر داخل خود فایل تصویر هستند.', false);
        preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } catch (error) {
        setStatus(error instanceof Error ? error.message : 'ساخت پیش‌نمایش ناموفق بود.', true);
      } finally {
        if (generateButton instanceof HTMLButtonElement) generateButton.disabled = false;
      }
    });

    async function loadConfig() {
      updateSource();
      updateAreaStates();
      if (!endpoint) return;
      setStatus('در حال بارگذاری تنظیمات کارت دعوت...', false);
      try {
        const response = await fetch(endpoint, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || result.status !== 'ok') {
          throw new Error(responseMessage(result, 'بارگذاری تنظیمات کارت دعوت ناموفق بود.'));
        }
        const config = result.data;
        if (!config || typeof config !== 'object') {
          state.draftReady = true;
          setAutosaveStatus('ذخیره خودکار فعال است.', false);
          setStatus('هنوز تنظیماتی برای کارت دعوت ذخیره نشده است.', false);
          return;
        }
        state.imageData = typeof config.imageData === 'string' ? config.imageData : '';
        state.imageName = typeof config.imageName === 'string' ? config.imageName : 'invite-card';
        state.imageWidth = Number(config.imageWidth) || 0;
        state.imageHeight = Number(config.imageHeight) || 0;
        state.fontData = typeof config.fontData === 'string' ? config.fontData : '';
        state.fontName = typeof config.fontName === 'string' ? config.fontName : '';
        state.fontMime = typeof config.fontMime === 'string' ? config.fontMime : '';
        state.fontBytes = Number(config.fontBytes) || 0;
        state.qrRect = normalizedRect(config.qrRect);
        state.textRect = normalizedRect(config.textRect);
        editor.innerHTML = sanitizeEditorHtml(
          typeof config.textHtml === 'string' && config.textHtml
            ? config.textHtml
            : plainTextToEditorHtml(typeof config.text === 'string' ? config.text : '')
        );
        state.conditionalVariables = normalizedConditionalVariables(config.conditionalVariables);
        renderConditionalVariables();
        const builderDraft = config.conditionalBuilderDraft && typeof config.conditionalBuilderDraft === 'object'
          ? config.conditionalBuilderDraft
          : defaultConditionalDefinition();
        const editingToken = cleanConditionalToken(builderDraft.editingToken || '');
        const editingIndex = editingToken
          ? state.conditionalVariables.findIndex((definition) => definition.token === editingToken)
          : -1;
        fillConditionalBuilder(builderDraft, editingIndex);
        let fontWarning = '';
        try {
          await applyStateFont();
        } catch (fontError) {
          state.fontFamily = DEFAULT_INVITE_FONT_FAMILY;
          editor.style.fontFamily = state.fontFamily;
          updateFontUi();
          fontWarning = fontError instanceof Error ? `فونت ذخیره‌شده بارگذاری نشد: ${fontError.message}` : 'فونت ذخیره‌شده بارگذاری نشد.';
        }
        qrDataInput.value = '[nationalid]';
        updateSource();
        updateAreaStates();
        state.draftReady = true;
        setAutosaveStatus('همه اطلاعات ذخیره‌شده بازیابی شد؛ ذخیره خودکار فعال است.', false);
        setStatus(fontWarning || `تنظیمات ذخیره‌شده EGM ${result.egmCode || ''} بارگذاری شد.`, Boolean(fontWarning));
      } catch (error) {
        state.draftReady = false;
        setAutosaveStatus('ذخیره خودکار به‌دلیل خطای بارگذاری غیرفعال است.', true);
        setStatus(error instanceof Error ? error.message : 'بارگذاری تنظیمات کارت دعوت ناموفق بود.', true);
      }
    }

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && modal instanceof HTMLElement && !modal.classList.contains('hidden')) {
        closeSelectionModal(false);
      }
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') void flushInviteCardDraft();
    });
    window.addEventListener('pagehide', () => { void flushInviteCardDraft(); });

    void Promise.all([loadConfig(), loadInvitees('')]);
  }

  function initAll() {
    document.querySelectorAll('[data-egm-invite-card-pane]').forEach(initPane);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll, { once: true });
  else initAll();
})();
