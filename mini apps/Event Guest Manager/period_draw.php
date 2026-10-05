<?php
declare(strict_types=1);


$siteRoot = dirname(__DIR__, is_file(__DIR__ . '/../../api/lib/tab-permissions.php') ? 2 : 3);
require_once __DIR__ . '/egm-database-runtime.php';
require_once $siteRoot . '/api/lib/tab-permissions.php';
require_once __DIR__ . '/egm-security.php';
require_once $siteRoot . '/api/lib/egm-period-draws.php';

$cspNonce = egmSecurityCreateCspNonce();
egmSecuritySendPageHeaders($cspNonce);
egmSecurityHardenSessionSettings();
$user = requireTabPermissionFromSession('event-guest-manager', false);
$context = egmPeriodInvitesContext(__DIR__);
try {
  $periodCode = egmPeriodDrawActiveCode($context);
} catch (InvalidArgumentException $error) {
  http_response_code(409);
  exit(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'));
}
$requestedPeriod = trim((string)($_GET['period_code'] ?? ''));
if ($requestedPeriod !== '' && $requestedPeriod !== $periodCode) {
  http_response_code(409);
  exit('این قرعه‌کشی فقط در بازهٔ فعال قابل اجراست.');
}
if (!egmPeriodDrawCanAccess($context, $user, $periodCode)) denyPanelAccess(403, 'دسترسی مدیریت قرعه‌کشی مجاز نیست.', false);
$levelId = trim((string)($_GET['level_id'] ?? ''));
try {
  try {
    $state = egmPeriodDrawRun($context, $periodCode, 'state', ['levelId' => $levelId], (string)$user['code']);
  } catch (InvalidArgumentException $error) {
    if (egmPeriodDrawPotLevel($context, $levelId) === null) throw $error;
    egmPeriodDrawRun($context, $periodCode, 'ensure_pot', ['levelId' => $levelId], (string)$user['code']);
    $state = egmPeriodDrawRun($context, $periodCode, 'state', ['levelId' => $levelId], (string)$user['code']);
  }
  $level = $state['level'];
} catch (InvalidArgumentException $error) { http_response_code(404); exit('قرعه‌کشی پیدا نشد.'); }
$csrf = egmSecurityGetCsrfToken();
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
    @font-face{font-family:'Peyda';font-weight:400;font-style:normal;src:url("/style/fonts/PeydaWebFaNum-Regular.woff2") format("woff2")}
    @font-face{font-family:'Peyda';font-weight:700;font-style:normal;src:url("/style/fonts/PeydaWebFaNum-Bold.woff2") format("woff2")}
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
    .code-display{max-width:100%}.code-display .code-digit{width:min(90px,calc((100vw - 80px)/var(--digit-count,4)));flex:none}
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
    .competition-shell{width:min(900px,100%);padding:26px;border-radius:30px;background:linear-gradient(155deg,rgba(12,28,67,.88),rgba(9,15,46,.94));border:1px solid rgba(220,241,255,.2);box-shadow:0 24px 60px rgba(1,8,38,.35);position:relative;z-index:1}
    .competition-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
    .competition-top h2{margin:0;font-size:1.4rem}
    .competition-progress{color:#cde8ff;font-weight:700}
    .competition-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:clamp(9px,2vw,20px);max-width:720px;margin:auto;direction:ltr}
    .competition-card{appearance:none;border:0;padding:0;background:transparent;aspect-ratio:3/4;min-height:0;perspective:900px;cursor:pointer;box-shadow:none;border-radius:20px;position:relative}
    .competition-card:hover:not(:disabled){transform:translateY(-4px)}
    .competition-card:disabled{opacity:1;cursor:default}
    .competition-card-inner{position:absolute;inset:0;transform-style:preserve-3d;transition:transform .55s cubic-bezier(.2,.75,.2,1)}
    .competition-card.flipped .competition-card-inner{transform:rotateY(180deg)}
    .competition-card-face{position:absolute;inset:0;border-radius:20px;display:flex;align-items:center;justify-content:center;backface-visibility:hidden;overflow:hidden;border:1px solid rgba(255,255,255,.4);box-shadow:inset 0 1px 0 rgba(255,255,255,.4),0 12px 26px rgba(2,8,39,.35)}
    .competition-card-front{font-size:clamp(2.5rem,7vw,5rem);font-weight:700;color:#f8fcff;background:linear-gradient(150deg,rgba(245,252,255,.35),rgba(105,169,244,.12) 42%,rgba(255,255,255,.04));backdrop-filter:blur(16px)}
    .competition-card-front::after{content:'';position:absolute;inset:-90%;background:linear-gradient(110deg,transparent 43%,rgba(255,255,255,.6) 50%,transparent 57%);transform:translateX(-60%) rotate(18deg);opacity:0}
    .competition-card.shine .competition-card-front::after,.competition-card.winner .competition-card-back::after{animation:competition-shine 1.1s ease forwards;opacity:1}
    .competition-card-back{transform:rotateY(180deg);padding:12px;font-size:clamp(.85rem,2.4vw,1.35rem);font-weight:700;line-height:1.35;color:#083052;background:linear-gradient(145deg,#fff,#c6ecff 70%,#8ccaff);overflow-wrap:anywhere}
    .competition-card-back::after{content:'';position:absolute;inset:-90%;background:linear-gradient(110deg,transparent 43%,rgba(255,255,255,.9) 50%,transparent 57%);transform:translateX(-60%) rotate(18deg);opacity:0;pointer-events:none}
    .competition-card.selected .competition-card-front{outline:3px solid #9deaff;outline-offset:-5px;box-shadow:inset 0 0 30px rgba(184,239,255,.5),0 0 28px rgba(100,220,255,.55)}
    .competition-card.winner{filter:drop-shadow(0 0 24px rgba(167,235,255,.8));animation:competition-win .9s ease-in-out 2}
    .competition-actions{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;margin-top:24px}
    .competition-status{min-height:26px;margin:16px 0 0;color:#d9f2ff}
    .competition-history{margin:18px auto 0;max-width:720px;text-align:right;color:#d4e9ff}
    .competition-history:empty{display:none}
    .competition-history-item{padding:9px 12px;border-top:1px solid rgba(255,255,255,.15)}
    @keyframes competition-shine{from{transform:translateX(-60%) rotate(18deg)}to{transform:translateX(60%) rotate(18deg)}}
    @keyframes competition-win{50%{transform:scale(1.06)}}
    @media(max-width:560px){.competition-shell{padding:16px}.competition-card-face{border-radius:14px}.competition-cards{gap:9px}}
    @media(prefers-reduced-motion:reduce){.competition-card-inner,.competition-card{transition:none!important;animation:none!important}}
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
      <a id="competition-tab" class="menu-item" href="#competition">جوایز رقابت‌ها</a>
      <a class="menu-item" href="/panel.php">بازگشت به پنل</a>
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
  <div id="competition-view" class="page-view" hidden data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <div class="competition-shell">
      <div class="competition-top"><h2>جوایز رقابت‌ها</h2><span class="competition-progress" id="competition-progress"></span></div>
      <div class="competition-cards" id="competition-cards" aria-label="کارت‌های جوایز">
        <?php for ($card = 1; $card <= 9; $card++): ?><button type="button" class="competition-card" data-card="<?= $card ?>" aria-label="انتخاب کارت <?= $card ?>"><span class="competition-card-inner"><span class="competition-card-face competition-card-front"><?= $card ?></span><span class="competition-card-face competition-card-back"></span></span></button><?php endfor; ?>
      </div>
      <div class="competition-actions"><button type="button" class="start-btn" id="competition-roll" disabled>چرخش</button><button type="button" class="confirm-btn" id="competition-confirm" hidden>تأیید جایزه</button><button type="button" class="confirm-btn" id="competition-cancel" hidden>لغو انتخاب</button></div>
      <p class="competition-status" id="competition-status" role="status" aria-live="polite"></p>
      <div class="competition-history" id="competition-history"></div>
    </div>
  </div>

  <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
    (() => {
      const periodCode = <?= json_encode($periodCode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const levelId = <?= json_encode($levelId, JSON_UNESCAPED_UNICODE) ?>;
      const csrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
      const winnerLimit = <?= $limit ?>;
      const codeDisplay = document.getElementById("code-display");
      let digitElements = [...document.querySelectorAll(".code-digit")];
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
      const competitionTab = document.getElementById("competition-tab");
      const drawView = document.getElementById("draw-view");
      const eligibleView = document.getElementById("eligible-view");
      const competitionView = document.getElementById("competition-view");
      const eligibleItemsEl = document.getElementById("eligible-items");
      const eligibleListCountEl = document.getElementById("eligible-list-count");
      let animationInterval = null;
      let stopTimeouts = [];
      let winners = [];
      let candidateKey = null;
      let eligibleParticipants = [];
      let eligibleCount = 0;
      let drawLocked = <?= !empty($level['potSettings']['locked']) ? 'true' : 'false' ?>;

      const api = async (action) => {
        const response = await fetch("period_draws.php", {
          method: "POST",
          credentials: "same-origin",
          headers: {"Content-Type": "application/json"},
          body: JSON.stringify({action, levelId, csrf, period_code: periodCode, candidateKey})
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== "ok") {
          throw new Error(payload.message || "درخواست ناموفق بود.");
        }
        return payload;
      };
      const normalizeCode = (value) => {
        const digits = String(value || "").replace(/\D+/g, "");
        return (digits || "0000").padStart(4, "0");
      };
      const randomDigit = () => Math.floor(Math.random() * 10).toString();
      const setCode = (code, locks = []) => {
        const normalized = normalizeCode(code);
        if (digitElements.length !== normalized.length) {
          codeDisplay.replaceChildren(...[...normalized].map((_, index) => {
            const digit = document.createElement("span");
            digit.className = "code-digit code-digit--animating";
            digit.dataset.index = String(index);
            return digit;
          }));
          digitElements = [...codeDisplay.querySelectorAll(".code-digit")];
        }
        codeDisplay.style.setProperty("--digit-count", String(digitElements.length));
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
            participant.guestType === "walk_in" ? "مهمان ناخوانده" : "مهمان دعوت‌شده"
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
            winner.guestType === "walk_in" ? "مهمان ناخوانده" : "مهمان دعوت‌شده"
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
        const showCompetition = view === "competition";
        drawView.hidden = showEligible || showCompetition;
        eligibleView.hidden = !showEligible;
        competitionView.hidden = !showCompetition;
        drawTab.classList.toggle("active", !showEligible && !showCompetition);
        eligibleTab.classList.toggle("active", showEligible);
        competitionTab.classList.toggle("active", showCompetition);
        if (showCompetition) { window.dispatchEvent(new Event("egmcompetitionopen")); return; }
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
        const currentDigits = Array(targetDigits.length).fill("0");
        const locks = Array(targetDigits.length).fill(false);
        cancelAnimation();
        animationInterval = setInterval(() => {
          for (let index = 0; index < targetDigits.length; index += 1) {
            if (!locks[index]) currentDigits[index] = randomDigit();
          }
          setCode(currentDigits.join(""), locks);
        }, 90);
        targetDigits.forEach((_, index) => {
          const delay = 1200 + 2000 * index;
          const timeout = setTimeout(() => {
            locks[index] = true;
            currentDigits[index] = targetDigits[index] || "0";
            setCode(currentDigits.join(""), locks);
            if (index === targetDigits.length - 1) {
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
          candidateKey = payload.participant.key;
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
          candidateKey = null;
          applyState(payload);
          metaEl.textContent = "برنده ذخیره شد.";
        } catch (error) {
          statusEl.textContent = error.message;
          confirmBtn.disabled = false;
        }
      });
      document.addEventListener("keydown", (event) => {
        if (drawView.hidden) return;
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
      competitionTab.addEventListener("click", (event) => {
        event.preventDefault();
        history.replaceState(null, "", "#competition");
        showView("competition");
      });

      setCode("0000");
      setIdleWinnerText();
      api("state").then((payload) => {
        applyState(payload);
        showView(location.hash === "#competition" ? "competition" : (location.hash === "#eligible" ? "eligible" : "draw"));
      }).catch((error) => {
        statusEl.textContent = error.message;
        render();
      });
    })();
  </script>
  <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>" src="competition-prizes.js?v=<?= (int)@filemtime(__DIR__ . '/competition-prizes.js') ?>"></script>
</body>
</html>
