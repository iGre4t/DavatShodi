<?php
session_start();
$cspNonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'nonce-{$cspNonce}'; img-src 'self' data: https: http:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");

if (!(isset($_SESSION['tc_authed']) && $_SESSION['tc_authed'] === true)) {
  header('Location: TCM.php');
  exit;
}

if (empty($_SESSION['tc_csrf'])) {
  $_SESSION['tc_csrf'] = bin2hex(random_bytes(16));
}

function loadJsonPayload(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $content = file_get_contents($path);
  if ($content === false) {
    return [];
  }
  $decoded = json_decode($content, true);
  return is_array($decoded) ? $decoded : [];
}

function formatAssetUrl(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\/\/|\/\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "../../{$trimmed}";
}

function normalizeHexColorForTheme($value, string $fallback): string
{
  $color = strtoupper(trim((string)$value));
  if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
    return $color;
  }
  return strtoupper($fallback);
}

$wheelSettings = loadJsonPayload(__DIR__ . '/Setting.json');
$panelStore = loadJsonPayload(__DIR__ . '/../../data/store.json');
$panelSettings = is_array($panelStore['settings'] ?? null) ? $panelStore['settings'] : [];
$eventLogoUrl = formatAssetUrl((string)($wheelSettings['eventLogo'] ?? ''));
$panelIconUrl = formatAssetUrl((string)($panelSettings['siteIcon'] ?? ''));
$faviconUrl = $eventLogoUrl !== '' ? $eventLogoUrl : $panelIconUrl;
$eventColors = is_array($wheelSettings['eventColors'] ?? null) ? $wheelSettings['eventColors'] : [];
$eventSecondary = normalizeHexColorForTheme($eventColors['secondary'] ?? '', '#2F8FFF');
$eventHighlight = normalizeHexColorForTheme($eventColors['highlight'] ?? '', '#20C997');
$eventAccentSoft = normalizeHexColorForTheme($eventColors['accentSoft'] ?? '', '#FFB347');
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>جوایز باشگاه</title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../../style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2'),
          url('/style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
        font-weight: 400;
        font-style: normal;
        font-display: swap;
      }

      @font-face {
        font-family: 'Peyda Fa Num';
        src:
          url('../../style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2'),
          url('/style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
        font-weight: 700;
        font-style: normal;
        font-display: swap;
      }

      :root {
        color-scheme: light;
        --bg: #eef4ff;
        --phone: #ffffff;
        --line: #dce6f8;
        --ink: #20365c;
        --muted: #6b7a99;
        --accent: #2f8fff;
        --tc-secondary: <?= htmlspecialchars($eventSecondary, ENT_QUOTES, 'UTF-8') ?>;
        --tc-highlight: <?= htmlspecialchars($eventHighlight, ENT_QUOTES, 'UTF-8') ?>;
        --tc-accent-soft: <?= htmlspecialchars($eventAccentSoft, ENT_QUOTES, 'UTF-8') ?>;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        min-height: 100vh;
        background:
          linear-gradient(160deg, rgba(206, 227, 255, 0.5), rgba(244, 247, 251, 0) 40%),
          linear-gradient(330deg, rgba(215, 230, 255, 0.5), rgba(244, 247, 251, 0) 42%),
          var(--bg);
        font-family: 'Peyda Fa Num', 'Segoe UI', Tahoma, Arial, sans-serif;
        color: var(--ink);
        display: grid;
        place-items: center;
        padding: 18px;
      }

      .app {
        width: min(460px, 100%);
      }

      .phone {
        width: 100%;
        min-height: min(860px, calc(100vh - 36px));
        background: var(--phone);
        border: 1px solid var(--line);
        border-radius: 28px;
        box-shadow: 0 26px 50px rgba(29, 55, 96, 0.14), inset 0 1px 0 #fff;
        overflow: hidden;
        padding: 16px;
      }

      .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 2px 2px 10px;
      }

      .brand {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        font-size: 0.96rem;
        color: var(--tc-secondary);
        letter-spacing: 0.04em;
      }

      .brand-icon {
        width: 24px;
        height: 24px;
        border-radius: 7px;
        border: 1px solid rgba(255, 255, 255, 0.85);
        box-shadow: 0 6px 14px rgba(15, 40, 70, 0.15);
        object-fit: cover;
        background: #ffffff;
      }

      .back-btn {
        border: none;
        background: transparent;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-family: inherit;
        font-size: 0.82rem;
        color: #6b7a99;
        cursor: pointer;
        padding: 4px 6px;
      }

      .back-btn span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #c7d2e5;
      }

      .back-btn:hover {
        color: var(--tc-secondary);
      }

      .result {
        border: 1px solid #dce7f9;
        border-radius: 16px;
        background: #f8fbff;
        box-shadow: 0 16px 30px rgba(44, 86, 146, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.86);
        padding: 12px;
      }

      .time-counter-result {
        margin-top: 12px;
      }

      .result-label {
        display: block;
        font-size: 0.74rem;
        color: var(--muted);
      }

      .result-value {
        margin: 4px 0 0;
        font-weight: 700;
      }

      .hidden {
        display: none !important;
      }

      .event-notice {
        margin-top: 12px;
        text-align: center;
        font-size: 0.9rem;
        font-weight: 700;
        color: #39548a;
      }

      .summary-grid {
        margin-top: 12px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
      }

      .summary-item {
        border: 1px solid #dce7f9;
        border-radius: 12px;
        background: #f8fbff;
        padding: 8px;
      }

      .summary-item .k {
        font-size: 0.72rem;
        color: var(--muted);
      }

      .summary-item .v {
        margin-top: 3px;
        font-size: 0.95rem;
        font-weight: 700;
      }

      .cards-box {
        margin-top: 12px;
        border-radius: 18px;
        border: 1px solid #d9e7fb;
        background: linear-gradient(160deg, #f8fbff, #edf4ff 56%, #f9fcff);
        box-shadow: 0 16px 30px rgba(44, 86, 146, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.84);
        padding: 12px;
      }

      .cards-box.is-disabled {
        opacity: 0.58;
        filter: grayscale(0.4);
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
      }

      .flip-card {
        perspective: 700px;
        border: 0;
        background: transparent;
        padding: 0;
        cursor: pointer;
      }

      .flip-card:disabled {
        cursor: not-allowed;
      }

      .flip-card-inner {
        position: relative;
        width: 100%;
        padding-top: 125%;
        transform-style: preserve-3d;
        transform: rotateY(0deg);
        transition: transform 420ms ease;
      }

      .flip-card.is-revealed .flip-card-inner {
        transform: rotateY(180deg);
      }

      .flip-face {
        position: absolute;
        inset: 0;
        border-radius: 12px;
        border: 1px solid #c7d8f5;
        backface-visibility: hidden;
        display: grid;
        place-items: center;
        text-align: center;
        padding: 8px;
      }

      .flip-front {
        background: linear-gradient(150deg, #fefefe, #edf3ff);
        color: #2b4370;
        font-size: 0.75rem;
        font-weight: 700;
      }

      .flip-back {
        transform: rotateY(180deg);
        background: linear-gradient(150deg, #1f4f96, #2d78d6 52%, #54a9f0 100%);
        color: #fff;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45);
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1.45;
      }

      .status-line {
        margin: 6px 0 0;
        min-height: 1.2em;
        font-size: 0.8rem;
      }

      .status-error { color: #c43b3b; }
      .status-ok { color: #1f7f44; }

      .roadmap-box {
        margin-top: 12px;
      }

      .roadmap-title {
        margin: 0 0 8px;
        font-size: 0.88rem;
      }

      .roadmap-list {
        display: grid;
        gap: 0;
      }

      .roadmap-item {
        position: relative;
        padding: 0 14px 14px 0;
        border-right: 2px solid #bfd2f4;
      }

      .roadmap-item:last-child {
        padding-bottom: 0;
        border-right-color: transparent;
      }

      .roadmap-node {
        position: absolute;
        right: -8px;
        top: 3px;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid #7aa6e9;
        background: #fff;
        animation: roadmap-node-pulse 1.8s ease-in-out infinite;
      }

      .roadmap-item.reached .roadmap-node {
        background: var(--tc-secondary);
        border-color: var(--tc-secondary);
        animation: roadmap-node-reached 1.25s ease-in-out infinite;
      }

      .roadmap-item::after {
        content: '';
        position: absolute;
        right: -2px;
        top: 18px;
        bottom: -2px;
        width: 2px;
        opacity: 0;
        background: var(--tc-secondary);
        background-size: 2px 36px;
      }

      .roadmap-item.reached::after {
        opacity: 1;
        animation: roadmap-line-flow 1.7s linear infinite;
      }

      .roadmap-content {
        border: 1px solid #dce7f9;
        border-radius: 10px;
        background: #f8fbff;
        padding: 7px 9px;
        font-size: 0.78rem;
        margin-right: 10px;
      }

      .roadmap-left {
        color: var(--muted);
        font-size: 0.74rem;
      }

      .roadmap-state {
        margin-top: 4px;
        font-size: 0.73rem;
        font-weight: 700;
      }

      .roadmap-state.can-flip { color: #1f7f44; }
      .roadmap-state.won { color: var(--tc-secondary); }
      .roadmap-state.locked { color: #9aa8c4; }
      .roadmap-state.reached { color: #cc7a00; }

      @keyframes roadmap-node-pulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(122, 166, 233, 0.35); }
        50% { transform: scale(1.08); box-shadow: 0 0 0 6px rgba(122, 166, 233, 0); }
      }

      @keyframes roadmap-node-reached {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(47, 143, 255, 0.4); }
        50% { transform: scale(1.1); box-shadow: 0 0 0 7px rgba(47, 143, 255, 0); }
      }

      @keyframes roadmap-line-flow {
        0% { background-position: 0 0; }
        100% { background-position: 0 36px; }
      }

      @media (max-width: 440px) {
        body {
          padding: 10px;
        }

        .phone {
          border-radius: 22px;
          min-height: calc(100vh - 20px);
        }
      }

      @supports (height: 100dvh) {
        .phone {
          min-height: min(860px, calc(100dvh - 36px));
        }
      }
    </style>
  </head>
  <body>
    <main class="app">
      <section class="phone">
        <h1 class="title">جوایز باشگاه</h1>

        <div id="reward-time-box" class="result time-counter-result">
          <span id="tc-time-counter-label" class="result-label">تا اتمام شگفتانه</span>
          <p id="tc-time-counter" class="result-value">—</p>
        </div>

        <section id="reward-event-notice" class="result event-notice hidden" aria-live="polite"></section>

        <div id="reward-summary" class="summary-grid" aria-label="خلاصه کاربر">
          <div class="summary-item">
            <div class="k">Score</div>
            <div id="reward-score" class="v">0</div>
          </div>
          <div class="summary-item">
            <div class="k">Card Flips</div>
            <div id="reward-flips" class="v">0</div>
          </div>
          <div class="summary-item">
            <div class="k">Total Prize Won</div>
            <div id="reward-total-won" class="v">0</div>
          </div>
        </div>

        <section id="reward-levels-box" class="levels-box result" aria-label="سطح جوایز">
          <h2 class="levels-title">Prize Levels</h2>
          <div id="reward-levels" class="levels-list"></div>
        </section>

        <section id="reward-roadmap-box" class="roadmap-box result hidden" aria-label="نقشه مسیر جوایز">
          <h2 class="roadmap-title">Reward Roadmap</h2>
          <div id="reward-roadmap" class="roadmap-list"></div>
        </section>

        <section id="reward-cards-box" class="cards-box" aria-label="کارت‌های جایزه">
          <div id="reward-cards" class="cards-grid"></div>
        </section>

        <section id="reward-status-box" class="status-box result" aria-label="وضعیت">
          <p id="reward-status" class="status-line"></p>
          <p id="reward-level-prizes" class="status-line"></p>
        </section>
      </section>
    </main>

    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
      (() => {
        const API_URL = 'TCM.php';
        const eventLogoUrl = <?= json_encode($faviconUrl, JSON_UNESCAPED_UNICODE); ?>;
        const csrfToken = <?= json_encode($_SESSION['tc_csrf'], JSON_UNESCAPED_UNICODE); ?>;
        const scoreEl = document.getElementById('reward-score');
        const flipsEl = document.getElementById('reward-flips');
        const totalWonEl = document.getElementById('reward-total-won');
        let backBtnEl = document.getElementById('reward-back-btn');
        const eventNoticeEl = document.getElementById('reward-event-notice');
        const timeBoxEl = document.getElementById('reward-time-box');
        const summaryEl = document.getElementById('reward-summary');
        let summaryFlipsItemEl = document.getElementById('reward-summary-flips-item');
        let summaryTotalItemEl = document.getElementById('reward-summary-total-item');
        const roadmapBoxEl = document.getElementById('reward-roadmap-box');
        const roadmapEl = document.getElementById('reward-roadmap');
        const cardsBoxEl = document.getElementById('reward-cards-box');
        const cardsEl = document.getElementById('reward-cards');
        const statusEl = document.getElementById('reward-status');
        const levelPrizesEl = document.getElementById('reward-level-prizes');
        const timeCounterLabelEl = document.getElementById('tc-time-counter-label');
        const timeCounterEl = document.getElementById('tc-time-counter');

        const initRewardsLayout = () => {
          const phoneEl = document.querySelector('.phone');
          if (!(phoneEl instanceof HTMLElement)) return;

          const titleEl = phoneEl.querySelector('.title');
          const existingTopbar = phoneEl.querySelector('.topbar');
          if (!(existingTopbar instanceof HTMLElement)) {
            const topbar = document.createElement('div');
            topbar.className = 'topbar';
            const brand = document.createElement('p');
            brand.className = 'brand';
            if (eventLogoUrl) {
              const brandIcon = document.createElement('img');
              brandIcon.className = 'brand-icon';
              brandIcon.src = String(eventLogoUrl);
              brandIcon.alt = 'لوگوی کمپین';
              brand.appendChild(brandIcon);
            }
            const brandText = document.createElement('span');
            brandText.textContent = 'کمپین به نام خدا';
            brand.appendChild(brandText);
            const back = document.createElement('button');
            back.id = 'reward-back-btn';
            back.className = 'back-btn';
            back.type = 'button';
            back.innerHTML = '<span aria-hidden="true"></span>برگشت';
            topbar.appendChild(brand);
            topbar.appendChild(back);
            phoneEl.insertBefore(topbar, phoneEl.firstChild);
            backBtnEl = back;
          }
          if (titleEl instanceof HTMLElement) {
            titleEl.remove();
          }

          if (summaryEl instanceof HTMLElement) {
            const summaryItems = Array.from(summaryEl.querySelectorAll('.summary-item'));
            if (summaryItems[1] instanceof HTMLElement) {
              summaryItems[1].id = 'reward-summary-flips-item';
              summaryFlipsItemEl = summaryItems[1];
            }
            if (summaryItems[2] instanceof HTMLElement) {
              summaryItems[2].id = 'reward-summary-total-item';
              summaryTotalItemEl = summaryItems[2];
            }
          }

          const levelsBox = document.getElementById('reward-levels-box');
          if (levelsBox instanceof HTMLElement) {
            levelsBox.remove();
          }

          if (roadmapBoxEl instanceof HTMLElement) {
            roadmapBoxEl.classList.remove('hidden');
            const roadmapTitleEl = roadmapBoxEl.querySelector('.roadmap-title');
            if (roadmapTitleEl instanceof HTMLElement) {
              roadmapTitleEl.textContent = 'Prize Levels';
            }
          }

          const statusBox = document.getElementById('reward-status-box');
          if (timeBoxEl instanceof HTMLElement) {
            if (statusEl instanceof HTMLElement && statusEl.parentElement !== timeBoxEl) {
              timeBoxEl.appendChild(statusEl);
            }
            if (levelPrizesEl instanceof HTMLElement && levelPrizesEl.parentElement !== timeBoxEl) {
              timeBoxEl.appendChild(levelPrizesEl);
            }
          }
          if (statusBox instanceof HTMLElement) {
            statusBox.remove();
          }
        };

        let rewardState = null;
        let currentLevel = null;
        let roundBusy = false;
        let roundLockUntil = 0;
        let counterTickHandle = null;
        let globalEventStatus = 'inactive';

        initRewardsLayout();

        const formatNumber = (value) => {
          const n = Number(value || 0);
          if (!Number.isFinite(n)) return '0';
          return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(n);
        };

        const shuffleArray = (list) => {
          const arr = Array.isArray(list) ? list.slice() : [];
          for (let i = arr.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = arr[i];
            arr[i] = arr[j];
            arr[j] = tmp;
          }
          return arr;
        };

        const pickRandom = (list, fallback = '-') => {
          if (!Array.isArray(list) || !list.length) return fallback;
          return String(list[Math.floor(Math.random() * list.length)] ?? fallback).trim() || fallback;
        };

        const postJson = async (body) => {
          const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...body, csrf: csrfToken })
          });
          let payload = null;
          try {
            payload = await response.json();
          } catch {
            payload = { status: 'error', message: 'Invalid server response.' };
          }
          if (!response.ok || payload?.status !== 'ok') {
            throw new Error(payload?.message || 'Request failed.');
          }
          return payload;
        };

        const setStatus = (message, isError = false) => {
          if (!statusEl) return;
          statusEl.textContent = String(message || '').trim();
          statusEl.classList.toggle('status-error', isError);
          statusEl.classList.toggle('status-ok', !isError && statusEl.textContent !== '');
        };

        const renderSummary = () => {
          if (!rewardState) return;
          if (scoreEl) scoreEl.textContent = formatNumber(rewardState.score || 0);
          if (flipsEl) flipsEl.textContent = formatNumber(rewardState.cardFlipsCount || 0);
          if (totalWonEl) totalWonEl.textContent = formatNumber(rewardState.totalPrizeWon || 0);
          if (levelPrizesEl) {
            const prizesText = String(rewardState.eachLevelWonPrize || '').trim();
            levelPrizesEl.textContent = prizesText ? `Each Level Won Prize: ${prizesText}` : 'Each Level Won Prize: -';
          }
        };

        const renderRoadmap = () => {
          if (!roadmapEl) return;
          const levels = Array.isArray(rewardState?.levels) ? rewardState.levels : [];
          const score = Number(rewardState?.score ?? 0);
          if (!levels.length) {
            roadmapEl.innerHTML = '<div class="roadmap-item"><span class="roadmap-node"></span><div class="roadmap-content">No prize level configured.</div></div>';
            return;
          }
          roadmapEl.innerHTML = levels.map((level) => {
            const target = Number(level?.score ?? 0);
            const reached = score >= target;
            const left = Math.max(0, target - score);
            const nodeClass = reached ? 'roadmap-item reached' : 'roadmap-item';
            let stateClass = 'locked';
            let stateText = 'Locked';
            if (level?.won) {
              stateClass = 'won';
              stateText = 'Claimed';
            } else if (level?.canFlip) {
              stateClass = 'can-flip';
              stateText = 'Can Flip';
            } else if (level?.reached && String(level?.type || '') === 'out_of_value') {
              stateClass = 'reached';
              stateText = 'Reached';
            } else if (level?.reached) {
              stateClass = 'reached';
              stateText = 'Reached';
            }
            return `<div class="${nodeClass}">
              <span class="roadmap-node" aria-hidden="true"></span>
              <div class="roadmap-content">
                <div>${String(level?.name || '-')} | ${formatNumber(target)}</div>
                <div class="roadmap-left">${reached ? 'Reached' : `${formatNumber(score)} collected | ${formatNumber(left)} left`}</div>
                <div class="roadmap-state ${stateClass}">${stateText}</div>
              </div>
            </div>`;
          }).join('');
        };

        const applyRewardsMode = (status) => {
          globalEventStatus = status;
          if (eventNoticeEl) {
            eventNoticeEl.classList.add('hidden');
            eventNoticeEl.textContent = '';
          }
          if (summaryEl) summaryEl.classList.remove('hidden');
          if (timeBoxEl) timeBoxEl.classList.remove('hidden');
          if (summaryFlipsItemEl) summaryFlipsItemEl.classList.remove('hidden');
          if (summaryTotalItemEl) summaryTotalItemEl.classList.remove('hidden');
          if (cardsBoxEl) cardsBoxEl.classList.remove('hidden');
          if (roadmapBoxEl) roadmapBoxEl.classList.remove('hidden');
          if (cardsBoxEl) cardsBoxEl.classList.remove('is-disabled');
          renderRoadmap();

          if (status === 'inactive') {
            if (summaryEl) summaryEl.classList.add('hidden');
            if (timeBoxEl) timeBoxEl.classList.add('hidden');
            if (summaryFlipsItemEl) summaryFlipsItemEl.classList.add('hidden');
            if (summaryTotalItemEl) summaryTotalItemEl.classList.add('hidden');
            if (cardsBoxEl) cardsBoxEl.classList.add('hidden');
            if (roadmapBoxEl) roadmapBoxEl.classList.add('hidden');
            if (eventNoticeEl) {
              eventNoticeEl.textContent = 'oh sorry no events running';
              eventNoticeEl.classList.remove('hidden');
            }
            return;
          }

          if (status === 'upcoming') {
            if (summaryFlipsItemEl) summaryFlipsItemEl.classList.add('hidden');
            if (summaryTotalItemEl) summaryTotalItemEl.classList.add('hidden');
            if (cardsBoxEl) cardsBoxEl.classList.add('hidden');
            setStatus('Cards will be available when event status becomes Active.', false);
            return;
          }

          if (status === 'ended') {
            if (cardsBoxEl) cardsBoxEl.classList.remove('hidden');
            if (cardsBoxEl) cardsBoxEl.classList.add('is-disabled');
            setStatus('sorry, end reached', true);
            return;
          }
        };

        const getNextFlippableLevel = () => {
          const levels = Array.isArray(rewardState?.levels) ? rewardState.levels : [];
          return levels.find((level) => level?.canFlip) || null;
        };

        const createDeck = () => {
          const names = Array.isArray(rewardState?.availablePrizeNames) ? rewardState.availablePrizeNames.filter((n) => String(n || '').trim() !== '') : [];
          const cards = [];
          for (let i = 0; i < 9; i += 1) {
            cards.push({
              id: `c_${Date.now()}_${i}_${Math.random().toString(36).slice(2, 7)}`,
              label: pickRandom(names, '—')
            });
          }
          return shuffleArray(cards);
        };

        const renderCards = (cards) => {
          if (!cardsEl) return;
          cardsEl.innerHTML = cards.map((card, index) => `
            <button class="flip-card" type="button" data-card-index="${index}">
              <div class="flip-card-inner">
                <div class="flip-face flip-front">Flip Card</div>
                <div class="flip-face flip-back" data-back-label>${String(card.label || '—')}</div>
              </div>
            </button>
          `).join('');
        };

        const setCardsEnabled = (enabled) => {
          if (!cardsEl) return;
          cardsEl.querySelectorAll('.flip-card').forEach((btn) => {
            if (btn instanceof HTMLButtonElement) {
              btn.disabled = !enabled || globalEventStatus !== 'active';
            }
          });
        };

        const setTimeCounter = (label, value) => {
          if (timeCounterLabelEl) timeCounterLabelEl.textContent = label;
          if (timeCounterEl) timeCounterEl.textContent = value;
        };

        const loadWheelSettings = async () => {
          try {
            const response = await fetch('tc_store.php?action=get_settings', { cache: 'no-store' });
            const payload = await response.json();
            if (payload?.status === 'ok' && payload.data && typeof payload.data === 'object') {
              return payload.data;
            }
          } catch {}
          return {};
        };

        const TEHRAN_OFFSET_MINUTES = 210;
        const getTehranDateTimeParts = (date = new Date()) => {
          const utcMs = date.getTime() + date.getTimezoneOffset() * 60000;
          const tehran = new Date(utcMs + TEHRAN_OFFSET_MINUTES * 60000);
          const year = String(tehran.getFullYear());
          const month = String(tehran.getMonth() + 1).padStart(2, '0');
          const day = String(tehran.getDate()).padStart(2, '0');
          const hour = String(tehran.getHours()).padStart(2, '0');
          const minute = String(tehran.getMinutes()).padStart(2, '0');
          const second = String(tehran.getSeconds()).padStart(2, '0');
          return { date: `${year}-${month}-${day}`, time: `${hour}:${minute}:${second}` };
        };

        const getTehranTargetDate = (dateStr, timeStr) => {
          const dateParts = String(dateStr || '').split('-').map((n) => Number(n));
          const timeParts = String(timeStr || '').split(':').map((n) => Number(n));
          if (dateParts.length !== 3 || timeParts.length < 2) return null;
          const [year, month, day] = dateParts;
          const [hour, minute, second = 0] = timeParts;
          if (![year, month, day, hour, minute, second].every((n) => Number.isFinite(n))) return null;
          const utcMs = Date.UTC(year, month - 1, day, hour, minute, second) - TEHRAN_OFFSET_MINUTES * 60000;
          return new Date(utcMs);
        };

        const parseTimeToSeconds = (value) => {
          if (!value) return null;
          const normalized = String(value).trim();
          const parts = normalized.split(':').map((part) => Number(part));
          if (parts.length < 2 || parts.length > 3 || parts.some((n) => !Number.isFinite(n))) return null;
          const [hours, minutes, seconds = 0] = parts;
          return hours * 3600 + minutes * 60 + seconds;
        };

        const compareDates = (a = '', b = '') => {
          const left = (a || '').trim();
          const right = (b || '').trim();
          if (!left || !right) return null;
          if (left === right) return 0;
          return left > right ? 1 : -1;
        };

        const getCurrentTehranSeconds = () => {
          const parts = getTehranDateTimeParts();
          return parseTimeToSeconds(parts.time) ?? 0;
        };

        const describeStatus = (settings) => {
          const active = Boolean(settings?.active);
          const duration = Boolean(settings?.duration);
          if (!duration) return active ? 'active' : 'inactive';
          const startDate = String(settings?.startDate ?? '').trim();
          const endDate = String(settings?.endDate ?? '').trim();
          const startTime = String(settings?.startTime ?? '').trim();
          const endTime = String(settings?.endTime ?? '').trim();
          const today = getTehranDateTimeParts();
          const startRelation = compareDates(startDate, today.date);
          const endRelation = compareDates(endDate, today.date);
          if (!startDate || !today.date) return 'inactive';
          if (startRelation === 1) return 'upcoming';
          if (endRelation !== null && endRelation === -1) return 'ended';
          const nowSeconds = getCurrentTehranSeconds();
          if (startRelation === 0 || endRelation === 0) {
            const startSeconds = parseTimeToSeconds(startTime);
            const endSeconds = parseTimeToSeconds(endTime);
            if (endSeconds !== null && nowSeconds >= endSeconds) return 'ended';
            if (startSeconds !== null && nowSeconds >= startSeconds) return 'active';
            if (startSeconds !== null && nowSeconds < startSeconds) return 'upcoming';
          }
          return 'active';
        };

        const updateCounter = (status, settings) => {
          if (counterTickHandle) {
            clearInterval(counterTickHandle);
            counterTickHandle = null;
          }

          const fallback = () => {
            if (status === 'upcoming') {
              setTimeCounter('تا شروع شگفتانه', 'در انتظار شروع');
            } else if (status === 'active') {
              setTimeCounter('تا اتمام شگفتانه', '-');
            } else if (status === 'inactive') {
              setTimeCounter('وضعیت', 'غیرفعال');
            } else {
              setTimeCounter('وضعیت', 'تمام شده');
            }
          };

          if (status === 'inactive' || status === 'ended' || !Boolean(settings?.duration)) {
            fallback();
            return;
          }

          const targetDate = status === 'upcoming' ? String(settings?.startDate ?? '').trim() : String(settings?.endDate ?? '').trim();
          const targetTime = status === 'upcoming' ? String(settings?.startTime ?? '').trim() : String(settings?.endTime ?? '').trim();
          const label = status === 'upcoming' ? 'تا شروع شگفتانه' : 'تا اتمام شگفتانه';

          const tick = () => {
            if (!targetDate || !targetTime) {
              fallback();
              return;
            }
            const target = getTehranTargetDate(targetDate, targetTime);
            if (!target) {
              fallback();
              return;
            }
            let diff = Math.max(0, Math.floor((target.getTime() - Date.now()) / 1000));
            const hours = Math.floor(diff / 3600);
            diff -= hours * 3600;
            const minutes = Math.floor(diff / 60);
            const seconds = diff - minutes * 60;
            setTimeCounter(label, `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`);
          };

          tick();
          counterTickHandle = setInterval(tick, 1000);
        };

        const refreshCounter = async () => {
          const settings = await loadWheelSettings();
          const status = describeStatus(settings);
          updateCounter(status, settings);
          applyRewardsMode(status);
        };

        const loadRewardState = async () => {
          const payload = await postJson({ action: 'reward_state' });
          rewardState = payload?.data || null;
          if (rewardState?.eventStatus) {
            globalEventStatus = String(rewardState.eventStatus);
          }
          renderSummary();
          renderRoadmap();
          applyRewardsMode(globalEventStatus);
          if (globalEventStatus === 'inactive') {
            currentLevel = null;
            return;
          }
          if (globalEventStatus === 'upcoming') {
            currentLevel = null;
            setCardsEnabled(false);
            renderCards(createDeck());
            return;
          }
          if (globalEventStatus === 'ended') {
            currentLevel = null;
            setCardsEnabled(false);
            renderCards(createDeck());
            return;
          }
          currentLevel = getNextFlippableLevel();

          if (!currentLevel) {
            setCardsEnabled(false);
            renderCards(createDeck());
            const levels = Array.isArray(rewardState?.levels) ? rewardState.levels : [];
            const reachedOutOfValue = levels.some((level) => level?.reached && !level?.won && String(level?.type || '') === 'out_of_value');
            if (reachedOutOfValue) {
              setStatus('You reached an Out of Value level. No card flip for that level.', false);
            } else {
              setStatus('No available flip right now. Go complete tasks to increase score.', false);
            }
            return;
          }

          roundBusy = false;
          renderCards(createDeck());
          setCardsEnabled(true);
          setStatus(`Ready for ${currentLevel.name}`, false);
        };

        const revealCards = (clickedButton, winningPrize) => {
          if (!cardsEl) return;
          const allButtons = Array.from(cardsEl.querySelectorAll('.flip-card'));
          const availableNames = Array.isArray(rewardState?.availablePrizeNames) ? rewardState.availablePrizeNames : [];

          allButtons.forEach((button) => {
            const backEl = button.querySelector('[data-back-label]');
            if (backEl) {
              backEl.textContent = pickRandom(availableNames, '—');
            }
            button.classList.add('is-revealed');
          });

          const clickedBack = clickedButton?.querySelector('[data-back-label]');
          if (clickedBack) clickedBack.textContent = winningPrize;
        };

        const flipBackAndShuffle = async () => {
          roundLockUntil = Date.now() + 2600;
          await new Promise((resolve) => setTimeout(resolve, 2600));
          renderCards(createDeck());
          try {
            await loadRewardState();
          } catch (error) {
            setStatus(error?.message || 'Failed to refresh reward state.', true);
          }
        };

        cardsEl?.addEventListener('click', async (event) => {
          const button = event.target.closest('.flip-card');
          if (!(button instanceof HTMLButtonElement)) return;
          if (globalEventStatus !== 'active') return;
          if (roundBusy || Date.now() < roundLockUntil) return;
          if (!currentLevel?.id) return;

          roundBusy = true;
          setCardsEnabled(false);
          try {
            const payload = await postJson({ action: 'reward_flip', levelId: currentLevel.id });
            const data = payload?.data || {};
            const prizeName = String(data.prizeName || '—');
            if (rewardState) {
              rewardState.cardFlipsCount = Number(data.cardFlipsCount ?? rewardState.cardFlipsCount ?? 0);
              rewardState.totalPrizeWon = Number(data.totalPrizeWon ?? rewardState.totalPrizeWon ?? 0);
              rewardState.eachLevelWonPrize = String(data.eachLevelWonPrize ?? rewardState.eachLevelWonPrize ?? '');
              rewardState.levels = Array.isArray(data.levels) ? data.levels : rewardState.levels;
            }
            renderSummary();
            revealCards(button, prizeName);
            setStatus(`You won "${prizeName}" in ${String(data.levelName || currentLevel.name || 'level')}.`, false);
            await flipBackAndShuffle();
          } catch (error) {
            setStatus(error?.message || 'Flip failed.', true);
            setCardsEnabled(Boolean(currentLevel?.canFlip));
          } finally {
            roundBusy = false;
          }
        });

        if (backBtnEl) {
          backBtnEl.addEventListener('click', () => {
            window.location.href = 'TCM.php';
          });
        }

        (async () => {
          try {
            await refreshCounter();
            await loadRewardState();
            setInterval(async () => {
              try {
                await refreshCounter();
                await loadRewardState();
              } catch {}
            }, 30 * 1000);
          } catch (error) {
            setStatus(error?.message || 'Failed to initialize rewards.', true);
          }
        })();
      })();
    </script>
  </body>
</html>

