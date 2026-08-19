<?php
declare(strict_types=1);


require_once __DIR__ . '/tc-database-runtime.php';
require_once __DIR__ . '/../../api/lib/tab-permissions.php';
require_once __DIR__ . '/tc-security.php';
require_once __DIR__ . '/pot_service.php';

$cspNonce = tcSecurityCreateCspNonce();
tcSecuritySendPageHeaders($cspNonce);
tcSecurityHardenSessionSettings();
$user = requireTabPermissionFromSession('task-club', false);
if (!userHasPermissionId($user, 'task-club:main')) {
  denyPanelAccess(403, 'You do not have permission to manage Pot draws.', false);
}

$levelId = trim((string)($_GET['level_id'] ?? ''));
$level = tcPotLevel($levelId);
if (!$level) {
  http_response_code(404);
  exit('Pot level was not found.');
}

$csrf = tcSecurityGetCsrfToken();
$title = (string)$level['potSettings']['title'];
$limit = (int)$level['potSettings']['winnerLimit'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
    @font-face{font-family:'Peyda';font-weight:400;font-style:normal;src:url("../../style/fonts/PeydaWebFaNum-Regular.woff2") format("woff2")}
    @font-face{font-family:'Peyda';font-weight:700;font-style:normal;src:url("../../style/fonts/PeydaWebFaNum-Bold.woff2") format("woff2")}
    :root{font-family:'Peyda','Segoe UI',Tahoma,Arial,sans-serif;color-scheme:dark}
    *{box-sizing:border-box}
    body{margin:40px 0 0;min-height:100vh;display:flex;align-items:center;justify-content:flex-start;background:radial-gradient(circle at top,#7cb7ff,#1a3edb 55%,#07103b 100%);color:#f0f8ff;text-align:center;padding:32px 16px 48px;flex-direction:column;gap:24px;position:relative;overflow-x:hidden}
    .background-icon{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0;opacity:.25;padding:0 17.5vw}
    .background-icon svg{width:100%;max-width:65vw;height:auto;filter:drop-shadow(0 24px 48px rgba(3,9,43,.5))}
    .icon-outline{fill:none;stroke:rgba(255,255,255,.45);stroke-width:10;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:2000;stroke-dashoffset:2000;animation:draw-icon 5s ease-in-out infinite alternate}
    @keyframes draw-icon{to{stroke-dashoffset:0;stroke:rgba(255,255,255,.9)}}
    .page-header{width:min(640px,100%);display:flex;justify-content:center;align-items:center;position:relative;z-index:2}
    .page-menu{display:flex;gap:16px;padding:12px 18px;background:rgba(255,255,255,.05);border-radius:999px;border:1px solid rgba(255,255,255,.2);backdrop-filter:blur(6px);z-index:1;flex-wrap:wrap;justify-content:center}
    .menu-item{color:#f5f5f7;text-decoration:none;font-size:1rem;font-weight:600;letter-spacing:.02em;padding:4px 14px;border-radius:999px;transition:background .2s ease,color .2s ease}
    .menu-item.active{background:rgba(255,255,255,.2);color:#fff}
    .menu-item:hover:not(.active){background:rgba(255,255,255,.08)}
    .page-view{width:100%;display:flex;flex-direction:column;align-items:center;gap:24px}
    .page-view[hidden]{display:none}
    .draw-shell{width:min(540px,100%);padding:32px;border-radius:32px;background:linear-gradient(180deg,rgba(20,35,67,.92),rgba(6,21,57,.98));border:1px solid rgba(255,255,255,.12);box-shadow:0 16px 40px rgba(3,20,60,.55);display:flex;flex-direction:column;gap:28px;position:relative;z-index:1}
    .code-display{display:flex;justify-content:center;gap:clamp(.35rem,1vw,.8rem);margin:0 auto;direction:ltr;unicode-bidi:isolate}
    .code-digit{width:clamp(60px,14vw,90px);height:clamp(80px,20vw,120px);background:rgba(255,255,255,.95);position:relative;border-radius:18px;display:flex;align-items:center;justify-content:center;font-family:'Peyda','Segoe UI',sans-serif;font-size:clamp(3.5rem,8vw,7rem);letter-spacing:0;color:#0042a4;font-weight:700;line-height:1;padding-top:clamp(6px,1.2vw,12px);padding-bottom:clamp(4px,1vw,10px);box-shadow:inset 0 0 0 1px rgba(4,12,38,.15);transition:background .3s ease,color .3s ease;direction:ltr;text-align:center}
    .code-digit::after{content:'';position:absolute;inset:0;border-radius:inherit;border:2px solid rgba(0,66,164,.3);pointer-events:none}
    .code-digit--animating{background:linear-gradient(180deg,#d7ecff,#b4d8ff);color:#07245d}
    .code-digit--locked{background:#173972;color:#e9f5ff}
    .caption{font-size:1.1rem;letter-spacing:.18em;color:rgba(255,255,255,.72);margin:0}
    .winner-message{font-size:1.6rem;margin:0;letter-spacing:.02em}
    .winner-message--idle{color:rgba(205,230,255,.6)}
    .winner-message--active{color:#cde6ff}
    .meta{color:rgba(255,255,255,.72);min-height:24px;margin-top:-18px}
    .cta-group{display:flex;flex-wrap:wrap;justify-content:center;gap:16px}
    button,.button{font-family:'Peyda','Segoe UI',Tahoma,Arial,sans-serif;border:none;border-radius:999px;padding:14px 28px;font-size:1rem;font-weight:700;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;text-decoration:none}
    button:disabled{opacity:.35;cursor:not-allowed;transform:none;box-shadow:none}
    .start-btn{background:linear-gradient(135deg,#32c5ff,#0b74ff);color:#00112a;box-shadow:0 12px 24px rgba(11,116,255,.45)}
    .start-btn:hover:not(:disabled),.confirm-btn:hover:not(:disabled),.button:hover{transform:translateY(-2px)}
    .confirm-btn,.button{background:transparent;color:#a8e0ff;border:1px solid rgba(168,224,255,.6);box-shadow:inset 0 0 0 1px rgba(255,255,255,.2)}
    .status{font-size:.85rem;color:rgba(255,255,255,.8);letter-spacing:.08em;margin:0;min-height:22px}
    .winners-panel{width:min(540px,100%);border-radius:28px;background:rgba(4,12,38,.72);border:1px solid rgba(255,255,255,.08);padding:24px;box-shadow:0 16px 30px rgba(3,20,60,.4);position:relative;z-index:1}
    .winners-panel h3{margin:0 0 12px;font-size:1rem;letter-spacing:.2em;color:rgba(255,255,255,.6);text-transform:uppercase}
    .winner-summary{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;color:rgba(255,255,255,.76);font-size:.95rem;margin:-2px 0 14px}
    .winner-items{display:flex;flex-direction:column;gap:12px}
    .winner-item{padding:14px;border-radius:20px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:center;direction:ltr}
    .winner-code{font-size:1.35rem;font-weight:700;letter-spacing:.4rem;color:#8be1ff;direction:ltr}
    .winner-info{text-align:right;font-size:1rem;direction:rtl;color:#fff;font-weight:600}
    .winner-meta{display:block;color:rgba(255,255,255,.58);font-size:.82rem;font-weight:400;margin-top:4px}
    .eligible-panel{overflow:visible}
    @media(max-width:480px){.draw-shell,.winners-panel{padding:20px}.page-menu{gap:8px}.menu-item{font-size:.9rem;padding:4px 10px}.code-display{letter-spacing:.6rem;font-size:clamp(3.2rem,20vw,7rem)}}
  </style>
</head>
<body>
  <div class="background-icon" aria-hidden="true">
    <svg viewBox="0 0 1173 773" role="presentation" xmlns="http://www.w3.org/2000/svg">
      <path class="icon-outline" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
    </svg>
  </div>
  <nav class="page-header">
    <div class="page-menu">
      <a id="draw-tab" class="menu-item active" href="#draw">قرعه کشی</a>
      <a id="eligible-tab" class="menu-item" href="#eligible">واجدین شرایط</a>
      <a class="menu-item" href="../../panel.php">بازگشت به پنل</a>
    </div>
  </nav>
  <div id="draw-view" class="page-view">
    <div class="draw-shell" aria-live="polite">
      <p id="draw-caption" class="caption"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></p>
      <p id="code-display" class="code-display" aria-live="polite" aria-label="کد قرعه کشی فعلی">
        <?php for ($idx = 0; $idx < 4; $idx++): ?>
          <span class="code-digit code-digit--animating" data-index="<?= $idx ?>">0</span>
        <?php endfor; ?>
      </p>
      <p id="winner-name" class="winner-message winner-message--idle">برنده قرعه کشی</p>
      <div class="meta" id="candidate-meta"></div>
      <div class="cta-group">
        <button id="roll" class="start-btn" type="button">قرعه کشی</button>
        <button id="confirm" class="confirm-btn" type="button" disabled>تایید برنده</button>
      </div>
      <p class="status" id="status" aria-live="polite"></p>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>برندگان</h3>
      <div class="winner-summary"><span><b id="winner-count">0</b> / <?= $limit ?></span><span>واجد شرایط: <b id="eligible-count">0</b></span></div>
      <div id="winner-items" class="winner-items"></div>
    </div>
  </div>
  <div id="eligible-view" class="page-view" hidden>
    <div class="winners-panel eligible-panel" aria-live="polite">
      <h3>واجدین شرایط</h3>
      <div class="winner-summary">تعداد: <b id="eligible-list-count">0</b></div>
      <div id="eligible-items" class="winner-items"></div>
    </div>
  </div>

  <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
    (() => {
      const levelId = <?= json_encode($levelId, JSON_UNESCAPED_UNICODE) ?>;
      const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
      const winnerLimit = <?= $limit ?>;
      const digitElements = [...document.querySelectorAll(".code-digit")];
      const rollBtn = document.getElementById("roll");
      const confirmBtn = document.getElementById("confirm");
      const nameEl = document.getElementById("winner-name");
      const metaEl = document.getElementById("candidate-meta");
      const statusEl = document.getElementById("status");
      const winnersEl = document.getElementById("winner-items");
      const countEl = document.getElementById("winner-count");
      const eligibleEl = document.getElementById("eligible-count");
      const drawTab = document.getElementById("draw-tab");
      const eligibleTab = document.getElementById("eligible-tab");
      const drawView = document.getElementById("draw-view");
      const eligibleView = document.getElementById("eligible-view");
      const eligibleItemsEl = document.getElementById("eligible-items");
      const eligibleListCountEl = document.getElementById("eligible-list-count");
      let animationInterval = null;
      let stopTimeouts = [];
      let winners = [];
      let eligibleParticipants = [];
      let eligibleCount = 0;
      let drawLocked = <?= !empty($level['potSettings']['locked']) ? 'true' : 'false' ?>;

      const api = async (action) => {
        const response = await fetch("pot_api.php", {
          method: "POST",
          credentials: "same-origin",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({action, levelId, csrf})
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload.message || "Request failed.");
        }
        return payload;
      };
      const normalizeCode = (value) => {
        const digits = String(value || "").replace(/\D+/g, "");
        return (digits.length ? digits.slice(-4) : "0000").padStart(4, "0");
      };
      const randomDigit = () => Math.floor(Math.random() * 10).toString();
      const setCode = (code, locks = [false, false, false, false]) => {
        const normalized = normalizeCode(code);
        digitElements.forEach((element, index) => {
          element.textContent = normalized[index] || "0";
          element.classList.toggle("code-digit--locked", Boolean(locks[index]));
          element.classList.toggle("code-digit--animating", !locks[index]);
        });
      };
      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        stopTimeouts.forEach(clearTimeout);
        stopTimeouts = [];
      };
      const setIdleWinnerText = () => {
        nameEl.textContent = "برنده قرعه کشی";
        nameEl.classList.add("winner-message--idle");
        nameEl.classList.remove("winner-message--active");
      };
      const setWinnerText = (name) => {
        nameEl.textContent = name || "-";
        nameEl.classList.add("winner-message--active");
        nameEl.classList.remove("winner-message--idle");
      };
      const renderEligible = () => {
        eligibleListCountEl.textContent = String(eligibleParticipants.length);
        eligibleItemsEl.innerHTML = "";
        if (!eligibleParticipants.length) {
          const placeholder = document.createElement("p");
          placeholder.className = "status";
          placeholder.textContent = "کاربر واجد شرایطی باقی نمانده است";
          eligibleItemsEl.appendChild(placeholder);
          return;
        }
        eligibleParticipants.forEach((participant) => {
          const row = document.createElement("div");
          row.className = "winner-item";
          const codeEl = document.createElement("div");
          codeEl.className = "winner-code";
          codeEl.textContent = normalizeCode(participant.code);
          const infoEl = document.createElement("div");
          infoEl.className = "winner-info";
          infoEl.textContent = participant.fullName || "-";
          const meta = document.createElement("span");
          meta.className = "winner-meta";
          meta.textContent = [
            participant.workId ? `شناسه: ${participant.workId}` : "",
            `امتیاز: ${participant.score ?? 0}`
          ].filter(Boolean).join(" · ");
          infoEl.appendChild(meta);
          row.append(codeEl, infoEl);
          eligibleItemsEl.appendChild(row);
        });
      };
      const render = () => {
        countEl.textContent = String(winners.length);
        eligibleEl.textContent = String(eligibleCount);
        rollBtn.disabled = drawLocked || winners.length >= winnerLimit || eligibleCount < 1 || animationInterval !== null;
        if (drawLocked) statusEl.textContent = "این قرعه‌کشی قفل شده و نتیجه آن نهایی است.";
        winnersEl.innerHTML = "";
        renderEligible();
        if (!winners.length) {
          const placeholder = document.createElement("p");
          placeholder.className = "status";
          placeholder.textContent = "هنوز برنده‌ای تایید نشده است";
          winnersEl.appendChild(placeholder);
          return;
        }
        winners.forEach((winner) => {
          const row = document.createElement("div");
          row.className = "winner-item";
          const codeEl = document.createElement("div");
          codeEl.className = "winner-code";
          codeEl.textContent = normalizeCode(winner.code);
          const infoEl = document.createElement("div");
          infoEl.className = "winner-info";
          infoEl.textContent = winner.fullName || "-";
          const meta = document.createElement("span");
          meta.className = "winner-meta";
          meta.textContent = [
            winner.workId ? `کد پرسنلی: ${winner.workId}` : "",
            `امتیاز: ${winner.score ?? 0}`
          ].filter(Boolean).join(" · ");
          infoEl.appendChild(meta);
          row.append(codeEl, infoEl);
          winnersEl.appendChild(row);
        });
      };
      const applyState = (payload) => {
        winners = payload.winners || [];
        eligibleParticipants = payload.eligibleParticipants || [];
        eligibleCount = Number(payload.eligibleCount || 0);
        if (payload.level?.potSettings) drawLocked = Boolean(payload.level.potSettings.locked);
        render();
      };
      const showView = async (view) => {
        const showEligible = view === "eligible";
        drawView.hidden = showEligible;
        eligibleView.hidden = !showEligible;
        drawTab.classList.toggle("active", !showEligible);
        eligibleTab.classList.toggle("active", showEligible);
        if (!showEligible) return;
        try {
          applyState(await api("state"));
        } catch (error) {
          eligibleItemsEl.innerHTML = "";
          const message = document.createElement("p");
          message.className = "status";
          message.textContent = error.message;
          eligibleItemsEl.appendChild(message);
        }
      };
      const animateTo = (candidate) => new Promise((resolve) => {
        const targetDigits = normalizeCode(candidate.code).split("");
        const currentDigits = ["0", "0", "0", "0"];
        const locks = [false, false, false, false];
        cancelAnimation();
        animationInterval = setInterval(() => {
          for (let index = 0; index < 4; index += 1) {
            if (!locks[index]) currentDigits[index] = randomDigit();
          }
          setCode(currentDigits.join(""), locks);
        }, 90);
        [1200, 3200, 5200, 7200].forEach((delay, index) => {
          const timeout = setTimeout(() => {
            locks[index] = true;
            currentDigits[index] = targetDigits[index] || "0";
            setCode(currentDigits.join(""), locks);
            if (index === 3) {
              cancelAnimation();
              setWinnerText(candidate.fullName || "-");
              metaEl.textContent = candidate.workId ? `شناسه: ${candidate.workId}` : "";
              confirmBtn.disabled = false;
              render();
              resolve();
            }
          }, delay);
          stopTimeouts.push(timeout);
        });
      });

      rollBtn.addEventListener("click", async () => {
        statusEl.textContent = "";
        rollBtn.disabled = true;
        confirmBtn.disabled = true;
        setIdleWinnerText();
        nameEl.textContent = "در حال قرعه کشی...";
        metaEl.textContent = "";
        try {
          const payload = await api("roll");
          await animateTo(payload.participant);
        } catch (error) {
          cancelAnimation();
          statusEl.textContent = error.message;
          setIdleWinnerText();
          render();
        }
      });
      confirmBtn.addEventListener("click", async () => {
        confirmBtn.disabled = true;
        statusEl.textContent = "";
        try {
          const payload = await api("confirm");
          applyState(payload);
          metaEl.textContent = "برنده ذخیره شد.";
        } catch (error) {
          statusEl.textContent = error.message;
          confirmBtn.disabled = false;
        }
      });
      document.addEventListener("keydown", (event) => {
        const targetTag = event.target?.tagName ?? "";
        if (["INPUT", "TEXTAREA"].includes(targetTag)) return;
        if (event.code === "Enter" && !rollBtn.disabled) {
          rollBtn.click();
        } else if (event.code === "Space" && !confirmBtn.disabled) {
          event.preventDefault();
          confirmBtn.click();
        }
      });
      drawTab.addEventListener("click", (event) => {
        event.preventDefault();
        history.replaceState(null, "", "#draw");
        showView("draw");
      });
      eligibleTab.addEventListener("click", (event) => {
        event.preventDefault();
        history.replaceState(null, "", "#eligible");
        showView("eligible");
      });

      setCode("0000");
      setIdleWinnerText();
      api("state").then((payload) => {
        applyState(payload);
        showView(location.hash === "#eligible" ? "eligible" : "draw");
      }).catch((error) => {
        statusEl.textContent = error.message;
        render();
      });
    })();
  </script>
</body>
</html>
