<div class="card">
  <div class="section-header">
    <h3>Wheel of Fortune</h3>
  </div>
  <div class="form" style="gap:12px; align-items:center;">
    <canvas id="wf-wheel-canvas" width="420" height="420" style="max-width:100%;"></canvas>
    <div class="field">
      <button id="wf-wheel-spin" class="btn primary">Spin</button>
    </div>
    <div id="wf-wheel-result" class="muted" style="font-weight:600;"></div>
  </div>
</div>

<script>
  (() => {
    const STORAGE_KEY = "wf_prizes";
    const defaultPrizes = [
      { name: "Gold Coin", quantity: 1 },
      { name: "T-Shirt", quantity: 5 }
    ];

    const canvas = document.getElementById("wf-wheel-canvas");
    const ctx = canvas?.getContext("2d");
    const spinButton = document.getElementById("wf-wheel-spin");
    const resultEl = document.getElementById("wf-wheel-result");
    if (!canvas || !ctx || !spinButton) return;

    function loadPrizes() {
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return defaultPrizes.slice();
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) {
          const cleaned = parsed
            .map(item => ({
              name: String(item?.name ?? "").trim(),
              quantity: Number.parseInt(item?.quantity ?? 0, 10)
            }))
            .filter(item => item.name);
          return cleaned.length ? cleaned : defaultPrizes.slice();
        }
      } catch {}
      return defaultPrizes.slice();
    }

    function expandPrizes(list) {
      const expanded = [];
      list.forEach(item => {
        const qty = Number.isFinite(item.quantity) && item.quantity > 0 ? item.quantity : 1;
        for (let i = 0; i < qty; i++) {
          expanded.push(item.name);
        }
      });
      return expanded.length ? expanded : ["No Prize"];
    }

    function drawWheel(names, angle = 0) {
      const size = canvas.width;
      const center = size / 2;
      const radius = center - 10;
      const count = names.length;
      const slice = (Math.PI * 2) / count;
      ctx.clearRect(0, 0, size, size);
      ctx.save();
      ctx.translate(center, center);
      ctx.rotate(angle);
      for (let i = 0; i < count; i++) {
        const start = i * slice;
        const end = start + slice;
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, radius, start, end);
        ctx.closePath();
        ctx.fillStyle = i % 2 === 0 ? "#f1f5f9" : "#e2e8f0";
        ctx.fill();
        ctx.strokeStyle = "#cbd5f5";
        ctx.stroke();
        ctx.save();
        ctx.rotate(start + slice / 2);
        ctx.textAlign = "right";
        ctx.fillStyle = "#111";
        ctx.font = "14px sans-serif";
        ctx.fillText(names[i], radius - 12, 5);
        ctx.restore();
      }
      ctx.restore();

      // Pointer
      ctx.fillStyle = "#111";
      ctx.beginPath();
      ctx.moveTo(center, 10);
      ctx.lineTo(center - 10, 30);
      ctx.lineTo(center + 10, 30);
      ctx.closePath();
      ctx.fill();
    }

    function pickResult(names, angle) {
      const count = names.length;
      const slice = (Math.PI * 2) / count;
      const normalized = (Math.PI * 2 - (angle % (Math.PI * 2)) + slice / 2) % (Math.PI * 2);
      const index = Math.floor(normalized / slice);
      return names[index] ?? names[0];
    }

    let spinning = false;
    let currentAngle = 0;
    const names = expandPrizes(loadPrizes());
    drawWheel(names, currentAngle);

    spinButton.addEventListener("click", () => {
      if (spinning) return;
      spinning = true;
      const spinTurns = 6 + Math.random() * 3;
      const targetAngle = currentAngle + spinTurns * Math.PI * 2 + Math.random() * Math.PI * 2;
      const start = performance.now();
      const duration = 2400;

      function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
      }

      function animate(now) {
        const elapsed = now - start;
        const progress = Math.min(1, elapsed / duration);
        const eased = easeOutCubic(progress);
        currentAngle = currentAngle + (targetAngle - currentAngle) * eased;
        drawWheel(names, currentAngle);
        if (progress < 1) {
          requestAnimationFrame(animate);
        } else {
          spinning = false;
          const result = pickResult(names, currentAngle);
          if (resultEl) resultEl.textContent = `Result: ${result}`;
        }
      }
      requestAnimationFrame(animate);
    });
  })();
</script>
