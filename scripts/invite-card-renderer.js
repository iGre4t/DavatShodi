(function () {
  function clampNumber(value, min = 0, max = Infinity) {
    const numeric = Number(value);
    if (Number.isNaN(numeric)) {
      return min;
    }
    return Math.min(Math.max(numeric, min), max);
  }

  function loadImage(src, crossOrigin = false) {
    return new Promise((resolve, reject) => {
      if (!src) {
        reject(new Error("Invalid image source."));
        return;
      }
      const image = new Image();
      if (crossOrigin) {
        image.crossOrigin = "anonymous";
      }
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error("Failed to load image."));
      image.src = src;
    });
  }

  function getQrCodeUrl(data, size) {
    const encoded = encodeURIComponent(String(data ?? ""));
    const normalizedSize = Math.max(32, Math.min(Math.round(size), 768));
    return `https://api.qrserver.com/v1/create-qr-code/?size=${normalizedSize}x${normalizedSize}&margin=2&data=${encoded}`;
  }

  function drawInviteCardText(ctx, field) {
    ctx.save();
    ctx.fillStyle = field.color;
    ctx.font = `${field.fontWeight} ${field.fontSize}px ${field.fontFamily}`;
    const align = (field.alignment === "rtl" ? "right" : field.alignment === "ltr" ? "left" : "center");
    ctx.textAlign = align;
    ctx.textBaseline = "top";
    ctx.direction = field.alignment === "rtl" ? "rtl" : "ltr";
    const lineHeight = field.fontSize * 1.3;
    const chunks = String(field.value).split(/\r?\n/);
    const safeX = clampNumber(field.x, 0, ctx.canvas.width);
    const safeY = clampNumber(field.y, 0, ctx.canvas.height);
    chunks.forEach((chunk, index) => {
      ctx.fillText(chunk, safeX, safeY + index * lineHeight);
    });
    ctx.restore();
  }

  async function drawInviteCardQr(ctx, field) {
    const size = field.scale && field.scale > 0 ? field.scale : 200;
    const normalizedSize = Math.max(
      32,
      Math.min(Math.round(size), ctx.canvas.width, ctx.canvas.height)
    );
    const qrImage = await loadImage(getQrCodeUrl(field.value, normalizedSize), true);
    const startX = clampNumber(field.x, 0, ctx.canvas.width - normalizedSize);
    const startY = clampNumber(field.y, 0, ctx.canvas.height - normalizedSize);
    ctx.drawImage(qrImage, startX, startY, normalizedSize, normalizedSize);
  }

  async function renderInviteCardFromSource(photoUrl, fields) {
    if (!photoUrl) {
      throw new Error("Card photo is required.");
    }
    const baseImage = await loadImage(photoUrl, true);
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, baseImage.naturalWidth || baseImage.width);
    canvas.height = Math.max(1, baseImage.naturalHeight || baseImage.height);
    const ctx = canvas.getContext("2d");
    if (!ctx) {
      throw new Error("Unable to obtain canvas context.");
    }
    ctx.drawImage(baseImage, 0, 0, canvas.width, canvas.height);
    for (const field of fields) {
      if (field.type === "text" && field.value) {
        drawInviteCardText(ctx, field);
      } else if (field.type === "qr" && field.value) {
        await drawInviteCardQr(ctx, field);
      }
    }
    return canvas;
  }

  window.clampNumber = clampNumber;
  window.loadImage = loadImage;
  window.renderInviteCardFromSource = renderInviteCardFromSource;
})();
