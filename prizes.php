<?php
session_start();

date_default_timezone_set('Asia/Tehran');

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

const STORE_PATH = __DIR__ . '/data/store.json';
const DEFAULT_PANEL_SETTINGS = [
  'panelName' => 'Great Panel',
  'siteIcon' => ''
];
const PRIZE_STATE_PATH = __DIR__ . '/data/prize_draw_state.json';
const PRIZE_GRID_CARD_COUNT = 12;
const PRIZE_LIST_PATH = __DIR__ . '/prizelist.csv';

function respondJson(array $payload, int $statusCode = 200): void
{
  if (!headers_sent()) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function saveJsonPayload(string $path, array $payload): bool
{
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    return false;
  }
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($encoded === false) {
    return false;
  }
  return file_put_contents($path, $encoded) !== false;
}

function loadPrizeDrawState(): array
{
  $payload = loadJsonPayload(PRIZE_STATE_PATH);
  $drawn = is_array($payload['drawn_ids'] ?? null) ? $payload['drawn_ids'] : [];
  $cards = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
  return [
    'drawn_ids' => array_values(array_map('intval', $drawn)),
    'cards' => $cards
  ];
}

function persistPrizeDrawState(array $state): bool
{
  return saveJsonPayload(PRIZE_STATE_PATH, $state);
}

function normalizeCardAssignments(array $cards): array
{
  $result = array_fill(0, PRIZE_GRID_CARD_COUNT, null);
  foreach ($cards as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $index = (int)($entry['index'] ?? -1);
    if ($index < 0 || $index >= PRIZE_GRID_CARD_COUNT) {
      continue;
    }
    $result[$index] = [
      'index' => $index,
      'prize_id' => (int)($entry['prize_id'] ?? 0),
      'prize_name' => (string)($entry['prize_name'] ?? ''),
      'row' => (int)($entry['row'] ?? 0)
    ];
  }
  return $result;
}

function handleDrawPrizeAction(): void
{
  $prizeList = loadPrizeList(PRIZE_LIST_PATH);
  $state = loadPrizeDrawState();
  $normalized = normalizeCardAssignments($state['cards']);
  $drawnIds = array_unique($state['drawn_ids']);
  $available = array_values(array_filter($prizeList, static fn ($entry) => !in_array($entry['id'], $drawnIds, true)));
  if (!$available) {
    respondJson(['status' => 'error', 'message' => 'No prizes remaining.'], 400);
  }
  $emptyIndexes = [];
  foreach ($normalized as $idx => $entry) {
    if ($entry === null) {
      $emptyIndexes[] = $idx;
    }
  }
  if (!$emptyIndexes) {
    respondJson(['status' => 'error', 'message' => 'Grid is full.'], 400);
  }
  $cardIndex = $emptyIndexes[array_rand($emptyIndexes)];
  $selected = $available[array_rand($available)];
  $state['drawn_ids'][] = $selected['id'];
  $state['cards'][] = [
    'index' => $cardIndex,
    'prize_id' => $selected['id'],
    'prize_name' => $selected['name'],
    'row' => $selected['row']
  ];
  $state['cards'] = array_values(array_filter($state['cards'], static fn ($entry) => is_array($entry)));
  if (!persistPrizeDrawState($state)) {
    respondJson(['status' => 'error', 'message' => 'Unable to persist prize state.'], 500);
  }
  $normalized = normalizeCardAssignments($state['cards']);
  $remaining = count($available) - 1;
  $emptySlots = count(array_filter($normalized, static fn ($entry) => $entry === null));
  $canDraw = $remaining > 0 && $emptySlots > 0;
  respondJson([
    'status' => 'ok',
    'prize' => [
      'id' => $selected['id'],
      'name' => $selected['name'],
      'row' => $selected['row']
    ],
    'card_index' => $cardIndex,
    'state' => $normalized,
    'remaining' => $remaining,
    'can_draw' => $canDraw,
    'drawn_ids' => array_values(array_unique($state['drawn_ids']))
  ]);
}

function handleResetPrizeState(): void
{
  $prizeList = loadPrizeList(PRIZE_LIST_PATH);
  $state = ['drawn_ids' => [], 'cards' => []];
  if (!persistPrizeDrawState($state)) {
    respondJson(['status' => 'error', 'message' => 'Unable to reset prize state.'], 500);
  }
  $normalized = normalizeCardAssignments($state['cards']);
  $remaining = count($prizeList);
  $canDraw = $remaining > 0;
  respondJson([
    'status' => 'ok',
    'state' => $normalized,
    'remaining' => $remaining,
    'can_draw' => $canDraw,
    'drawn_ids' => []
  ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput ?: '', true);
  $action = strtolower(trim((string)($payload['action'] ?? '')));
  if ($action === 'draw_prize') {
    handleDrawPrizeAction();
  }
  if ($action === 'reset_draw') {
    handleResetPrizeState();
  }
  respondJson(['status' => 'error', 'message' => 'Unsupported action.'], 400);
}
function loadPanelSettings(): array
{
  $payload = loadJsonPayload(STORE_PATH);
  $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
  return array_merge(DEFAULT_PANEL_SETTINGS, $settings);
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

function formatSiteIconUrlForHtml(string $value): string
{
  $trimmed = trim($value);
  if ($trimmed === '') {
    return '';
  }
  if (preg_match('/^(?:data:|https?:\\/\\/|\\/\\/)/i', $trimmed)) {
    return $trimmed;
  }
  if (strncmp($trimmed, '/', 1) === 0 || strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
    return $trimmed;
  }
  return "./{$trimmed}";
}

function loadPrizeList(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $handle = fopen($path, 'r');
  if ($handle === false) {
    return [];
  }
  $list = [];
  fgetcsv($handle);
  $rowNumber = 1;
  while (($row = fgetcsv($handle)) !== false) {
    if (!is_array($row)) {
      continue;
    }
    $rowNumber++;
    $name = trim((string)($row[1] ?? $row[0] ?? ''));
    if ($name === '') {
      continue;
    }
    $list[] = [
      'id' => (int)($row[0] ?? 0),
      'name' => $name,
      'row' => $rowNumber
    ];
  }
  fclose($handle);
  usort($list, static fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
  return array_values($list);
}

$panelSettings = loadPanelSettings();
$pageTitle = (string)($panelSettings['panelName'] ?? DEFAULT_PANEL_SETTINGS['panelName']);
$faviconUrl = formatSiteIconUrlForHtml((string)($panelSettings['siteIcon'] ?? ''));
$prizeList = loadPrizeList(PRIZE_LIST_PATH);
$drawState = loadPrizeDrawState();
$cardAssignments = normalizeCardAssignments($drawState['cards']);
$drawnPrizeIds = array_values(array_unique($drawState['drawn_ids']));
$remainingPrizes = max(0, count($prizeList) - count($drawnPrizeIds));
$filledCardSlots = count(array_filter($cardAssignments, static fn ($entry) => $entry !== null));
$canDraw = $remainingPrizes > 0 && $filledCardSlots < PRIZE_GRID_CARD_COUNT;

?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= htmlspecialchars($faviconUrl ?: 'data:,', ENT_QUOTES, 'UTF-8') ?>" />
    <style>
      @font-face {
        font-family: 'Peyda';
        font-weight: 400;
        font-style: normal;
        src: url('style/fonts/PeydaWebFaNum-Regular.woff2') format('woff2');
      }
      @font-face {
        font-family: 'Peyda';
        font-weight: 700;
        font-style: normal;
        src: url('style/fonts/PeydaWebFaNum-Bold.woff2') format('woff2');
      }

      :root {
        font-family: 'Peyda', 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: dark;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 0;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        text-align: center;
        padding: 16px 16px 24px;
        flex-direction: column;
        gap: 12px;
        position: relative;
        overflow: hidden;
      }

      .background-icon {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        z-index: 0;
        opacity: 0.25;
        padding: 0 17.5vw;
      }

      .background-icon svg {
        width: 100%;
        max-width: 65vw;
        height: auto;
        filter: drop-shadow(0 24px 48px rgba(3, 9, 43, 0.5));
      }

      .icon-outline {
        fill: none;
        stroke: rgba(255, 255, 255, 0.45);
        stroke-width: 10;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-dasharray: 2000;
        stroke-dashoffset: 2000;
        animation: draw-icon 5s ease-in-out infinite alternate;
      }

      @keyframes draw-icon {
        to {
          stroke-dashoffset: 0;
          stroke: rgba(255, 255, 255, 0.9);
        }
      }

      .page-header {
        width: min(640px, 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        z-index: 2;
      }

      .page-menu {
        display: flex;
        gap: 24px;
        padding: 12px 24px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(6px);
        z-index: 1;
      }

      .menu-item {
        color: #f5f5f7;
        text-decoration: none;
        font-size: 1rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        padding: 4px 16px;
        border-radius: 999px;
        transition: background 0.2s ease, color 0.2s ease;
      }

      .menu-item.active {
        background: rgba(255, 255, 255, 0.2);
        color: #ffffff;
      }

      .menu-item:hover:not(.active) {
        background: rgba(255, 255, 255, 0.08);
      }

      .draw-shell {
        width: min(540px, 100%);
        padding: 32px;
        border-radius: 32px;
        background: linear-gradient(180deg, rgba(20, 35, 67, 0.92), rgba(6, 21, 57, 0.98));
        border: 1px solid rgba(255, 255, 255, 0.12);
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.55);
        display: flex;
        flex-direction: column;
        gap: 24px;
        position: relative;
        z-index: 1;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
      }

      body.prizes-page .draw-shell {
        width: min(1010px, 100%);
        align-items: center;
      }

      body.prizes-page .prize-grid {
        width: min(1010px, 100%);
        margin: 0 auto;
      }

      .prize-display {
        min-height: clamp(110px, 14vw, 160px);
        width: min(640px, 100%);
        min-width: min(550px, 90vw);
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
      }

      .prize-value {
        font-size: clamp(1.8rem, 4vw, 3rem);
        font-weight: 700;
        letter-spacing: 0.2em;
        color: #0d1c48;
        background: #ffffff;
        border-radius: 24px;
        padding: 20px 34px;
        border: 1px solid rgba(13, 28, 72, 0.3);
        width: 100%;
        max-width: 100%;
        line-height: 1.2;
        white-space: nowrap;
        direction: rtl;
        transition: background 0.3s ease, color 0.3s ease, transform 0.3s ease;
      }

      .prize-value--animating {
        background: linear-gradient(180deg, #d7ecff, #b4d8ff);
        color: #07245d;
        transform: scale(1.02);
      }

      .prize-value--locked {
        background: #173972;
        color: #e9f5ff;
        border-color: rgba(255, 255, 255, 0.4);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
      }

      .prize-grid {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        grid-template-rows: repeat(3, 1fr);
        gap: 12px;
        padding-top: 12px;
      }

      .prize-card {
        position: relative;
        height: 55px;
        border-radius: 20px;
        overflow: hidden;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 12px 30px rgba(3, 9, 43, 0.35);
        perspective: 1100px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.4s ease, border-color 0.4s ease;
      }

      .prize-card .card-inner {
        position: relative;
        width: 100%;
        height: 100%;
        transition: transform 1.2s cubic-bezier(0.645, 0.045, 0.355, 1);
        transform-style: preserve-3d;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .prize-card.card-flip .card-inner {
        transform: rotateY(180deg);
      }

      .prize-card.card-flip {
        background: linear-gradient(180deg, #041236, #0b1f63);
        border-color: rgba(77, 162, 255, 0.9);
      }

      .prize-card.card-flip .card-front {
        background: rgba(4, 9, 40, 0.85);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
      }

      .prize-card.card-highlight {
        box-shadow: 0 0 25px rgba(50, 197, 255, 0.55);
        border-color: rgba(50, 197, 255, 0.8);
      }

      .card-face {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: #f0f8ff;
        backface-visibility: hidden;
      }

      .card-front {
        background: rgba(255, 255, 255, 0.04);
      }

      .card-back {
        background: transparent;
        transform: rotateY(180deg);
        padding: 0 8px;
        text-align: center;
        direction: rtl;
        font-size: 1rem;
        white-space: normal;
        position: relative;
        overflow: hidden;
      }

      .card-back::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(120deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.6) 45%, rgba(255, 255, 255, 0) 75%);
        transform: translateX(-130%);
        opacity: 0;
      }

      .card-flip .card-back::after {
        animation: card-shine 1.2s ease;
      }

      @keyframes card-shine {
        0% {
          transform: translateX(-150%);
          opacity: 0;
        }
        40% {
          opacity: 0.75;
        }
        100% {
          transform: translateX(150%);
          opacity: 0;
        }
      }

      .cta-group {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 16px;
      }

      button {
        border: none;
        border-radius: 999px;
        padding: 14px 28px;
        font-size: 1rem;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
      }

      button:disabled {
        opacity: 0.35;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
      }

      .start-btn {
        background: linear-gradient(135deg, #32c5ff, #0b74ff);
        color: #00112a;
        box-shadow: 0 12px 24px rgba(11, 116, 255, 0.45);
      }

      .start-btn:hover:not(:disabled) {
        transform: translateY(-2px);
      }

      @media (max-width: 480px) {
        .draw-shell {
          padding: 20px;
        }
        .prize-value {
          font-size: clamp(1.8rem, 10vw, 2.8rem);
          padding: 16px 24px;
        }
      }
    </style>
  </head>
  <body class="prizes-page">
    <div class="background-icon" aria-hidden="true">
      <svg viewBox="0 0 1173 773" role="presentation" xmlns="http://www.w3.org/2000/svg">
        <path class="icon-outline" d="M1173 407.266V773C791.7 589.486 381.3 521.402 0 573.591V16.8977C319.721 -26.5479 659.341 13.9796 985.446 136.213C1099.03 178.364 1173 286.979 1173 406.947V407.266Z" />
      </svg>
    </div>
    <nav class="page-header">
      <div class="page-menu">
        <a class="menu-item" href="draw.php">&#x0642;&#x0631;&#x0639;&#x0647; &#x06A9;&#x0634;&#x06CC;</a>
        <a class="menu-item active" href="prizes.php">&#x062C;&#x0648;&#x0627;&#x06CC;&#x0632; &#x0645;&#x0633;&#x0627;&#x0628;&#x0642;&#x0627;&#x062A;</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">قرعه‌کشی جوایز برندگان مسابقات</p>
      <div id="prize-display" class="prize-display" aria-live="polite" aria-label="Prize reveal">
        <span id="prize-value" class="prize-value">---</span>
      </div>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">start draw</button>
      </div>
    </div>
    <div class="prize-grid" aria-hidden="true">
      <?php for ($cardIndex = 0; $cardIndex < PRIZE_GRID_CARD_COUNT; $cardIndex++): ?>
        <?php
          $assignment = $cardAssignments[$cardIndex] ?? null;
          $isFlipped = $assignment !== null;
        ?>
        <div class="prize-card<?= $isFlipped ? ' card-flip' : '' ?>" data-card-index="<?= $cardIndex ?>">
          <div class="card-inner">
            <div class="card-face card-front">جایزه</div>
            <div class="card-face card-back">
              <?= htmlspecialchars((string)($assignment['prize_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
          </div>
        </div>
      <?php endfor; ?>
    </div>
    <script>
      (function () {
        const API_PATH = 'prizes.php';
        window.__PRIZE_LIST = window.__PRIZE_LIST || <?= json_encode($prizeList, JSON_UNESCAPED_UNICODE); ?>;
        const prizeList = Array.isArray(window.__PRIZE_LIST) ? window.__PRIZE_LIST : [];
        const initialCardAssignments = <?= json_encode($cardAssignments, JSON_UNESCAPED_UNICODE); ?>;
        let currentCardAssignments = initialCardAssignments.map((entry) => (entry ? { ...entry } : null));
        let drawnPrizeIds = <?= json_encode($drawnPrizeIds, JSON_UNESCAPED_UNICODE); ?>;
        let remainingPrizes = <?= $remainingPrizes; ?>;
        let canDraw = <?= $canDraw ? 'true' : 'false'; ?>;
        const prizeCards = Array.from(document.querySelectorAll('.prize-card'));
        const prizeValueEl = document.getElementById('prize-value');
        const startBtn = document.getElementById('start-draw');

        let prizeAnimationInterval = null;
        let revealTimer = null;
        let revealReady = false;
        let drawResult = null;
        let finalizeLocked = false;
        let highlightInterval = null;
        let pendingCardIndex = null;

        const stopNameAnimation = () => {
          if (prizeAnimationInterval) {
            clearInterval(prizeAnimationInterval);
            prizeAnimationInterval = null;
          }
          prizeValueEl?.classList.remove('prize-value--animating');
        };

        const startNameAnimation = () => {
          stopNameAnimation();
          prizeValueEl?.classList.add('prize-value--animating');
          prizeAnimationInterval = setInterval(() => {
            const candidate = prizeList[Math.floor(Math.random() * prizeList.length)];
            if (prizeValueEl) {
              prizeValueEl.textContent = candidate?.name || '---';
            }
          }, 120);
        };

        const setAnimatingState = () => {
          prizeValueEl?.classList.remove('prize-value--locked');
          startNameAnimation();
        };

        const setLockedState = () => {
          stopNameAnimation();
          prizeValueEl?.classList.add('prize-value--locked');
        };

        const resetValueState = () => {
          stopNameAnimation();
          prizeValueEl?.classList.remove('prize-value--locked');
          if (prizeValueEl) {
            prizeValueEl.textContent = '---';
          }
        };

        const applyCardState = (assignments) => {
          currentCardAssignments = assignments.map((entry) => (entry ? { ...entry } : null));
          prizeCards.forEach((card) => {
            const idx = Number(card.dataset.cardIndex);
            const assignment = currentCardAssignments[idx];
            const back = card.querySelector('.card-back');
            card.classList.toggle('card-flip', Boolean(assignment));
            if (back) {
              back.textContent = assignment?.prize_name || '';
            }
          });
        };
        applyCardState(currentCardAssignments);

        const getAvailableGlowCards = () => prizeCards.filter((card, idx) => !currentCardAssignments[idx] && idx !== pendingCardIndex);
        const stopCardGlow = () => {
          if (highlightInterval) {
            clearInterval(highlightInterval);
            highlightInterval = null;
          }
          prizeCards.forEach((card) => card.classList.remove('card-highlight'));
        };
        const startCardGlowCycle = () => {
          stopCardGlow();
          if (!getAvailableGlowCards().length) {
            return;
          }
          highlightInterval = setInterval(() => {
            const options = getAvailableGlowCards();
            if (!options.length) {
              stopCardGlow();
              return;
            }
            const chosen = options[Math.floor(Math.random() * options.length)];
            prizeCards.forEach((card) => card.classList.toggle('card-highlight', card === chosen));
          }, 180);
        };

        const cancelAnimation = () => {
          stopNameAnimation();
          if (revealTimer) {
            clearTimeout(revealTimer);
            revealTimer = null;
          }
          revealReady = false;
          drawResult = null;
          finalizeLocked = false;
          pendingCardIndex = null;
        };

        const revealCard = (prizeName, cardIndex, prizeId, rowNumber) => {
          stopCardGlow();
          pendingCardIndex = null;
          if (cardIndex == null) {
            return;
          }
          const card = prizeCards[cardIndex];
          if (!card) {
            return;
          }
          card.classList.add('card-flip', 'card-highlight');
          const back = card.querySelector('.card-back');
          if (back) {
            back.textContent = prizeName || '';
          }
          currentCardAssignments[cardIndex] = {
            index: cardIndex,
            prize_id: prizeId ?? 0,
            prize_name: prizeName || '',
            row: rowNumber ?? 0
          };
        };

        const tryFinalizeDraw = () => {
          if (finalizeLocked || !revealReady || !drawResult) {
            return;
          }
          finalizeLocked = true;
          setLockedState();
          prizeValueEl.textContent = drawResult.prize.name || '---';
          revealCard(drawResult.prize.name || '', drawResult.card_index, drawResult.prize.id ?? 0, drawResult.prize.row ?? 0);
          remainingPrizes = drawResult.remaining ?? remainingPrizes;
          canDraw = Boolean(drawResult.can_draw);
          drawnPrizeIds = drawResult.drawn_ids ?? drawnPrizeIds;
          startBtn.disabled = !canDraw;
        };

        const postAction = async (action) => {
          const response = await fetch(API_PATH, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action })
          });
          const payload = await response.json();
          if (!response.ok || payload.status !== 'ok') {
            throw new Error(payload.message || 'Unable to complete the request.');
          }
          return payload;
        };

        const startDraw = () => {
          if (!canDraw) {
            return;
          }
          cancelAnimation();
          stopCardGlow();
          startBtn.disabled = true;
          setAnimatingState();
          startCardGlowCycle();
          const revealDelay = 2600 + Math.random() * 1400;
          revealTimer = setTimeout(() => {
            revealReady = true;
            tryFinalizeDraw();
          }, revealDelay);
          postAction('draw_prize')
            .then((payload) => {
              drawResult = {
                prize: payload.prize ?? {},
                card_index: payload.card_index ?? null,
                remaining: payload.remaining ?? remainingPrizes,
                can_draw: payload.can_draw ?? false,
                drawn_ids: payload.drawn_ids ?? drawnPrizeIds
              };
              pendingCardIndex = payload.card_index ?? null;
              tryFinalizeDraw();
            })
            .catch((error) => {
              console.error(error);
              stopCardGlow();
              resetValueState();
              startBtn.disabled = !canDraw;
            });
        };

        if (startBtn) {
          startBtn.addEventListener('click', startDraw);
        }

        const resetDrawState = async () => {
          cancelAnimation();
          stopCardGlow();
          try {
            const payload = await postAction('reset_draw');
            const assignments = Array.isArray(payload.state) ? payload.state : currentCardAssignments;
            currentCardAssignments = assignments.map((entry) => (entry ? { ...entry } : null));
            drawnPrizeIds = payload.drawn_ids ?? [];
            remainingPrizes = payload.remaining ?? prizeList.length;
            canDraw = Boolean(payload.can_draw);
            applyCardState(currentCardAssignments);
            prizeValueEl.textContent = '---';
            resetValueState();
            startBtn.disabled = !canDraw;
          } catch (error) {
            console.error(error);
          }
        };

        const pressedKeys = new Set();
        let resetLocked = false;

        document.addEventListener('keydown', (event) => {
          pressedKeys.add(event.code);
          if (!['INPUT', 'TEXTAREA'].includes(event.target?.tagName ?? '')) {
            if (event.code === 'Enter' && !startBtn.disabled) {
              event.preventDefault();
              startDraw();
            }
          }
          if (pressedKeys.has('Numpad8') && pressedKeys.has('Numpad9') && !resetLocked) {
            resetLocked = true;
            resetDrawState();
          }
        });

        document.addEventListener('keyup', (event) => {
          pressedKeys.delete(event.code);
          if (event.code === 'Numpad8' || event.code === 'Numpad9') {
            resetLocked = false;
          }
        });

        setCode('0000');
        resetValueState();
        startBtn.disabled = !canDraw;
      })();
    </script>
  </body>
</html>
