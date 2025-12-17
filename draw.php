<?php
session_start();

if (empty($_SESSION['authenticated'])) {
  header('Location: login.php');
  exit;
}

$guestStorePath = __DIR__ . '/data/guests.json';
$guestPool = [];
if (is_file($guestStorePath)) {
  $content = file_get_contents($guestStorePath);
  if ($content !== false) {
    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
      $events = $decoded['events'] ?? [];
      foreach ($events as $event) {
        if (!is_array($event)) {
          continue;
        }
        $guests = $event['guests'] ?? [];
        foreach ($guests as $guest) {
          if (!is_array($guest)) {
            continue;
          }
          $code = preg_replace('/\D+/', '', (string)($guest['invite_code'] ?? ''));
          if ($code === '') {
            continue;
          }
          $code = substr($code, -4);
          $code = str_pad($code, 4, '0', STR_PAD_LEFT);
          $firstName = trim((string)($guest['firstname'] ?? ''));
          $lastName = trim((string)($guest['lastname'] ?? ''));
          $fullName = trim(implode(' ', array_filter([$firstName, $lastName], static fn ($value) => $value !== '')));
          if ($fullName === '') {
            $fullName = 'Guest';
          }
          $guestPool[] = [
            'code' => $code,
            'full_name' => $fullName
          ];
        }
      }
    }
  }
}

?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Draw Stage</title>
    <link rel="icon" href="data:," />
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
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        text-align: center;
        padding: 32px 16px;
      }

      .draw-shell {
        width: min(540px, 100%);
        padding: 32px;
        border-radius: 32px;
        background: linear-gradient(180deg, rgba(20, 35, 67, 0.8), rgba(6, 21, 57, 0.95));
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 16px 40px rgba(3, 20, 60, 0.55);
        display: flex;
        flex-direction: column;
        gap: 28px;
      }

      .code-display {
        font-size: clamp(4rem, 16vw, 9rem);
        letter-spacing: 1.2rem;
        font-weight: 700;
        color: #89c6ff;
        margin: 0;
        line-height: 1;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
      }

      .winner-message {
        font-size: 1.2rem;
        margin: 0;
        color: #cde6ff;
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

      .confirm-btn {
        background: transparent;
        color: #a8e0ff;
        border: 1px solid rgba(168, 224, 255, 0.6);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
      }

      .confirm-btn:hover:not(:disabled) {
        transform: translateY(-2px);
      }

      .status {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.8);
        letter-spacing: 0.2em;
      }

      @media (max-width: 480px) {
        .draw-shell {
          padding: 24px 20px;
        }
        .code-display {
          letter-spacing: 0.6rem;
        }
      }
    </style>
  </head>
  <body>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">میز آغاز قرعه‌کشی</p>
      <p id="code-display" class="code-display">0000</p>
      <p id="winner-name" class="winner-message">-- <span>----</span> is the winner --</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">start draw</button>
        <button id="confirm-guest" class="confirm-btn" type="button" disabled>confirm guest</button>
      </div>
      <p id="status-text" class="status">ready for the next pick</p>
    </div>

    <script>
      window.__GUEST_POOL = window.__GUEST_POOL || <?= json_encode($guestPool, JSON_UNESCAPED_UNICODE); ?>;
      const guestPool = Array.isArray(window.__GUEST_POOL) ? window.__GUEST_POOL : [];
      const codeDisplay = document.getElementById('code-display');
      const winnerNameEl = document.getElementById('winner-name');
      const statusText = document.getElementById('status-text');
      const startBtn = document.getElementById('start-draw');
      const confirmBtn = document.getElementById('confirm-guest');

      let animationInterval = null;
      let settleTimeout = null;
      let currentWinner = null;

      const randomCode = () => {
        return Array.from({ length: 4 }, () => Math.floor(Math.random() * 10)).join('');
      };

      const setCode = (value) => {
        codeDisplay.textContent = value.padStart(4, '0');
      };

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        if (settleTimeout !== null) {
          clearTimeout(settleTimeout);
          settleTimeout = null;
        }
      };

      const setWinnerText = (name) => {
        // Update the winner line while keeping text nodes safe.
        winnerNameEl.textContent = '';
        winnerNameEl.appendChild(document.createTextNode('-- '));
        const highlight = document.createElement('span');
        highlight.textContent = name;
        winnerNameEl.appendChild(highlight);
        winnerNameEl.appendChild(document.createTextNode(' is the winner --'));
      };

      const renderWinner = (winner) => {
        const name = winner?.full_name || '----';
        setWinnerText(name);
        statusText.textContent = winner ? `winner locked: ${name}` : 'ready for the next pick';
      };

      startBtn.addEventListener('click', () => {
        if (!guestPool.length) {
          statusText.textContent = 'guest list is empty';
          return;
        }
        cancelAnimation();
        startBtn.disabled = true;
        confirmBtn.disabled = true;
        currentWinner = guestPool[Math.floor(Math.random() * guestPool.length)];
        statusText.textContent = 'drawing...';

        animationInterval = setInterval(() => {
          setCode(randomCode());
        }, 70);

        settleTimeout = setTimeout(() => {
          cancelAnimation();
          const winnerCode = currentWinner?.code ?? '0000';
          setCode(winnerCode);
          confirmBtn.disabled = false;
          startBtn.disabled = false;
          renderWinner(currentWinner);
        }, 2400);
      });

      confirmBtn.addEventListener('click', () => {
        if (!currentWinner) {
          statusText.textContent = 'choose a winner first';
          return;
        }
        statusText.textContent = `guest confirmed: ${currentWinner.full_name}`;
        // Keep the winner displayed until another draw.
      });

      if (!guestPool.length) {
        statusText.textContent = 'guest list is empty';
        startBtn.disabled = true;
      }
    </script>
  </body>
</html>
