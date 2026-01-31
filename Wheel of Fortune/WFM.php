<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Great Panel</title>
    <link rel="icon" href="data:," />
    <style>
      :root {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        color-scheme: dark;
      }

      * {
        box-sizing: border-box;
      }

      body {
        margin: 40px 0 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        background: radial-gradient(circle at top, #7cb7ff, #1a3edb 55%, #07103b 100%);
        color: #f0f8ff;
        text-align: center;
        padding: 32px 16px 48px;
        flex-direction: column;
        gap: 24px;
        position: relative;
        overflow-x: hidden;
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
        gap: 28px;
        position: relative;
        z-index: 1;
      }

      .code-display {
        display: flex;
        justify-content: center;
        gap: clamp(0.35rem, 1vw, 0.8rem);
        margin: 0 auto;
        direction: ltr;
        unicode-bidi: isolate;
      }

      .code-digit {
        width: clamp(60px, 14vw, 90px);
        height: clamp(80px, 20vw, 120px);
        background: rgba(255, 255, 255, 0.95);
        position: relative;
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        font-size: clamp(3.5rem, 8vw, 7rem);
        letter-spacing: 0;
        color: #0042a4;
        font-weight: 700;
        line-height: 1;
        padding-top: clamp(6px, 1.2vw, 12px);
        padding-bottom: clamp(4px, 1vw, 10px);
        box-shadow: inset 0 0 0 1px rgba(4, 12, 38, 0.15);
        transition: background 0.3s ease, color 0.3s ease;
        direction: ltr;
        text-align: center;
      }

      .code-digit::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        border: 2px solid rgba(0, 66, 164, 0.3);
        pointer-events: none;
      }

      .code-digit--animating {
        background: linear-gradient(180deg, #d7ecff, #b4d8ff);
        color: #07245d;
      }

      .code-digit--locked {
        background: #173972;
        color: #e9f5ff;
      }

      .caption {
        font-size: 1.1rem;
        letter-spacing: 0.25em;
        color: rgba(255, 255, 255, 0.72);
        margin: 0;
      }

      .winner-message {
        font-size: 1.6rem;
        margin: 0;
        letter-spacing: 0.02em;
      }

      .winner-message--idle {
        color: rgba(205, 230, 255, 0.6);
      }

      .winner-message--active {
        color: #cde6ff;
      }

      .cta-group button {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
        letter-spacing: 0.04em;
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
        margin: 0;
      }

      .winners-panel {
        width: min(540px, 100%);
        border-radius: 28px;
        background: rgba(4, 12, 38, 0.72);
        border: 1px solid rgba(255, 255, 255, 0.08);
        padding: 24px;
        box-shadow: 0 16px 30px rgba(3, 20, 60, 0.4);
        position: relative;
        z-index: 1;
      }

      .winners-panel h3 {
        margin: 0 0 12px;
        font-size: 1rem;
        letter-spacing: 0.2em;
        color: rgba(255, 255, 255, 0.6);
        text-transform: uppercase;
      }

      .winner-items {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .winner-item {
        padding: 14px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 12px;
        align-items: center;
        direction: ltr;
      }

      .winner-code {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.4rem;
        color: #8be1ff;
        direction: ltr;
      }

      .winner-info {
        text-align: right;
        font-size: 1rem;
        direction: rtl;
        color: #ffffff;
        font-weight: 600;
      }

      @media (max-width: 480px) {
        .draw-shell,
        .winners-panel {
          padding: 20px;
        }
        .code-display {
          letter-spacing: 0.6rem;
          font-size: clamp(3.2rem, 20vw, 7rem);
        }
      }
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
        <a class="menu-item active" href="WFM.php">Ù‚Ø±Ø¹Ù‡ Ú©Ø´ÛŒ</a>
        <a class="menu-item" href="prizes.php">Ø¬ÙˆØ§ÛŒØ² Ù…Ø³Ø§Ø¨Ù‚Ø§Øª</a>
      </div>
    </nav>
    <div class="draw-shell" aria-live="polite">
      <p class="caption">Ù‚Ø±Ø¹Ù‡â€ŒÚ©Ø´ÛŒ Ù…Ø´Ù‡Ø¯ Ù…Ù‚Ø¯Ø³</p>
      <p id="code-display" class="code-display" aria-live="polite" aria-label="Ú©Ø¯ Ù‚Ø±Ø¹Ù‡â€ŒÚ©Ø´ÛŒ ÙØ¹Ù„ÛŒ">
        <span class="code-digit code-digit--animating" data-index="0"></span>
        <span class="code-digit code-digit--animating" data-index="1"></span>
        <span class="code-digit code-digit--animating" data-index="2"></span>
        <span class="code-digit code-digit--animating" data-index="3"></span>
      </p>
      <p id="winner-name" class="winner-message winner-message--idle">Ø¨Ø±Ù†Ø¯Ù‡ Ù‚Ø±Ø¹Ù‡ Ú©Ø´ÛŒ</p>
      <div class="cta-group">
        <button id="start-draw" class="start-btn" type="button">Ù‚Ø±Ø¹Ù‡ Ú©Ø´ÛŒ</button>
        <button id="confirm-guest" class="confirm-btn" type="button" disabled>ØªØ§ÛŒÛŒØ¯ Ù…Ù‡Ù…Ø§Ù†</button>
      </div>
    </div>
    <div class="winners-panel" aria-live="polite">
      <h3>Ø¨Ø±Ù†Ø¯Ú¯Ø§Ù†</h3>
      <div id="winner-items" class="winner-items"></div>
    </div>

    <script>
      const guestPool = [
        { code: '1201', firstname: 'Ø¹Ù„ÛŒ', lastname: 'ÙØ±Ù‡Ø§Ø¯ÛŒ', full_name: 'Ø¹Ù„ÛŒ ÙØ±Ù‡Ø§Ø¯ÛŒ' },
        { code: '8453', firstname: 'Ø³Ø§Ø±Ø§', lastname: 'Ú©Ø±ÛŒÙ…ÛŒ', full_name: 'Ø³Ø§Ø±Ø§ Ú©Ø±ÛŒÙ…ÛŒ' },
        { code: '9920', firstname: 'Ø±Ø¶Ø§', lastname: 'Ù…Ø­Ù…Ø¯ÛŒ', full_name: 'Ø±Ø¶Ø§ Ù…Ø­Ù…Ø¯ÛŒ' },
        { code: '4378', firstname: 'Ù…Ø±ÛŒÙ…', lastname: 'Ù†Ø´Ø§Ø·', full_name: 'Ù…Ø±ÛŒÙ… Ù†Ø´Ø§Ø·' }
      ];

      let winnersList = [];
      const codeDisplay = document.getElementById('code-display');
      const winnerNameEl = document.getElementById('winner-name');
      const startBtn = document.getElementById('start-draw');
      const confirmBtn = document.getElementById('confirm-guest');
      const winnersContainer = document.getElementById('winner-items');
      const digitElements = Array.from(codeDisplay.querySelectorAll('.code-digit'));

      let animationInterval = null;
      let stopTimeouts = [];
      let currentWinner = null;
      const pressedShortcutKeys = new Set();
      let resetShortcutLocked = false;

      const randomDigit = () => Math.floor(Math.random() * 10).toString();

      const normalizeCode = (value) => {
        const text = (value ?? '').toString().trim();
        const digits = text.replace(/\D+/g, '');
        if (digits.length === 0) {
          return '0000';
        }
        return digits.slice(-4).padStart(4, '0');
      };

      const defaultLocks = () => Array(4).fill(false);

      const renderDigits = (digits, locks = defaultLocks()) => {
        const normalized = normalizeCode(digits);
        digitElements.forEach((element) => {
          const index = Number(element.dataset.index);
          const char = normalized[index] ?? '0';
          element.textContent = char;
          const locked = Boolean(locks[index]);
          element.classList.toggle('code-digit--locked', locked);
          element.classList.toggle('code-digit--animating', !locked);
        });
      };

      const setCode = (value, locks = defaultLocks()) => {
        const normalized = normalizeCode(value);
        renderDigits(normalized, locks);
      };

      const createGuestSelectionKey = (guest) => {
        if (!guest || typeof guest !== 'object') {
          return '';
        }
        const code = normalizeCode(guest.code ?? '');
        const number = (guest.number ?? '').toString();
        return `${code}|${number}`;
      };

      const chosenGuestKeys = new Set();

      const getAvailableGuests = () => guestPool.filter((guest) => {
        const key = createGuestSelectionKey(guest);
        return key !== '' && !chosenGuestKeys.has(key);
      });

      const cancelAnimation = () => {
        if (animationInterval !== null) {
          clearInterval(animationInterval);
          animationInterval = null;
        }
        stopTimeouts.forEach(clearTimeout);
        stopTimeouts = [];
      };

      const showIdleWinnerText = () => {
        winnerNameEl.textContent = 'Ø¨Ø±Ù†Ø¯Ù‡ Ù‚Ø±Ø¹Ù‡ Ú©Ø´ÛŒ';
        winnerNameEl.classList.add('winner-message--idle');
        winnerNameEl.classList.remove('winner-message--active');
      };

      const setWinnerText = (name) => {
        winnerNameEl.textContent = name;
        winnerNameEl.classList.add('winner-message--active');
        winnerNameEl.classList.remove('winner-message--idle');
      };

      const renderWinner = (winner) => {
        const name = winner?.full_name || '----';
        setWinnerText(name);
      };

      const formatWinnerItem = (entry) => {
        const container = document.createElement('div');
        container.className = 'winner-item';
        const codeEl = document.createElement('div');
        codeEl.className = 'winner-code';
        codeEl.textContent = (entry.code || entry.invite_code || '0000').toString();
        const infoEl = document.createElement('div');
        infoEl.className = 'winner-info';
        const displayName = entry.full_name || `${entry.firstname || ''} ${entry.lastname || ''}`.trim() || 'Ù…Ù‡Ù…Ø§Ù†';
        infoEl.textContent = displayName;
        container.append(codeEl, infoEl);
        return container;
      };

      const renderWinnerList = (items) => {
        winnersList = Array.isArray(items) ? items.slice() : [];
        winnersContainer.innerHTML = '';
        if (!winnersList.length) {
          const placeholder = document.createElement('p');
          placeholder.className = 'status';
          placeholder.textContent = 'Ù‡Ù†ÙˆØ² Ø¨Ø±Ù†Ø¯Ù‡â€ŒØ§ÛŒ ØªØ§ÛŒÛŒØ¯ Ù†Ø´Ø¯Ù‡ Ø§Ø³Øª';
          winnersContainer.appendChild(placeholder);
          return;
        }
        winnersList.forEach((row) => winnersContainer.appendChild(formatWinnerItem(row)));
      };

      const resetWinnersList = () => {
        cancelAnimation();
        confirmBtn.disabled = true;
        chosenGuestKeys.clear();
        currentWinner = null;
        showIdleWinnerText();
        setCode('0000');
        renderWinnerList([]);
        startBtn.disabled = getAvailableGuests().length === 0;
        confirmBtn.disabled = true;
      };

      setCode('0000');
      showIdleWinnerText();

      startBtn.addEventListener('click', () => {
        const availableGuests = getAvailableGuests();
        if (!availableGuests.length) {
          startBtn.disabled = true;
          return;
        }
        cancelAnimation();
        startBtn.disabled = true;
        confirmBtn.disabled = true;
        currentWinner = availableGuests[Math.floor(Math.random() * availableGuests.length)];
        const selectionKey = createGuestSelectionKey(currentWinner);
        if (selectionKey !== '') {
          chosenGuestKeys.add(selectionKey);
        }
        showIdleWinnerText();
        const targetCode = normalizeCode(currentWinner?.code ?? currentWinner?.invite_code);
        const digits = targetCode.split('');
        const currentDigits = ['0', '0', '0', '0'];
        const locks = [false, false, false, false];
        animationInterval = setInterval(() => {
          for (let i = 0; i < 4; i += 1) {
            if (!locks[i]) {
              currentDigits[i] = randomDigit();
            }
          }
          setCode(currentDigits.join(''), locks);
        }, 90);
        const stopDelays = [1200, 3200, 5200, 7200];
        stopDelays.forEach((delay, index) => {
          const timeout = setTimeout(() => {
            locks[index] = true;
            currentDigits[index] = digits[index];
            setCode(currentDigits.join(''), locks);
            if (index === 3) {
              cancelAnimation();
              confirmBtn.disabled = false;
              const hasRemaining = getAvailableGuests().length > 0;
              startBtn.disabled = !hasRemaining;
              renderWinner(currentWinner);
            }
          }, delay);
          stopTimeouts.push(timeout);
        });
      });

      confirmBtn.addEventListener('click', () => {
        if (!currentWinner) {
          return;
        }
        confirmBtn.disabled = true;
        const updated = winnersList.concat(currentWinner);
        renderWinnerList(updated);
      });

      document.addEventListener('keydown', (event) => {
        pressedShortcutKeys.add(event.code);
        if (event.code === 'Numpad1') {
          event.preventDefault();
          window.location.href = 'prizes.php';
          return;
        }
        if (event.code === 'Numpad2') {
          event.preventDefault();
          window.location.href = 'WFM.php';
          return;
        }
        const targetTag = event.target?.tagName ?? '';
        if (['INPUT', 'TEXTAREA'].includes(targetTag)) {
          return;
        }
        if (event.code === 'Enter') {
          if (!startBtn.disabled) {
            startBtn.click();
          }
        } else if (event.code === 'Space') {
          event.preventDefault();
          if (!confirmBtn.disabled) {
            confirmBtn.click();
          }
        }
        if (!resetShortcutLocked && pressedShortcutKeys.has('Numpad8') && pressedShortcutKeys.has('Numpad9')) {
          resetShortcutLocked = true;
          event.preventDefault();
          resetWinnersList();
        }
      });

      document.addEventListener('keyup', (event) => {
        pressedShortcutKeys.delete(event.code);
        if (event.code === 'Numpad8' || event.code === 'Numpad9') {
          resetShortcutLocked = false;
        }
      });

      if (!getAvailableGuests().length) {
        startBtn.disabled = true;
      }
      renderWinnerList(winnersList);
    </script>
  </body>
</html>
