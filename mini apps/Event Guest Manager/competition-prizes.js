(() => {
  const view = document.getElementById("competition-view");
  if (!view) return;
  const cards = [...view.querySelectorAll(".competition-card")];
  const progress = document.getElementById("competition-progress");
  const roll = document.getElementById("competition-roll");
  const confirm = document.getElementById("competition-confirm");
  const cancel = document.getElementById("competition-cancel");
  const reset = document.getElementById("competition-reset");
  const status = document.getElementById("competition-status");
  const history = document.getElementById("competition-history");
  let selected = null;
  let state = null;
  let busy = false;
  const connection = window.EGMDrawConnection;
  const connected = () => !connection || connection.ready;
  const showError = error => { status.textContent = connection?.isConnectionError(error) ? '' : error.message; };
  const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
  const request = async (action, extra = {}) => {
    if (connection) return connection.request(view.dataset.endpoint || 'competition_prizes.php', {action, csrf:view.dataset.csrf, period_code:view.dataset.periodCode, ...extra});
    const response = await fetch(view.dataset.endpoint || "competition_prizes.php", {
      method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/json"},
      body: JSON.stringify({action, csrf: view.dataset.csrf, period_code: view.dataset.periodCode, ...extra})
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.status !== "ok") throw new Error(result.message || "درخواست ناموفق بود.");
    return result;
  };
  const confirmedCards = () => new Map((state?.keepConfirmedCards === false ? [] : state?.confirmed || []).map(item => [Number(item.card), item]));
  const resetCards = () => cards.forEach(card => {
    card.classList.remove("flipped", "shine", "winner", "selected", "anticipating", "charging", "releasing");
    const saved = confirmedCards().get(Number(card.dataset.card));
    card.classList.toggle("confirmed", !!saved);
    card.querySelector(".competition-card-back").textContent = saved?.prizeName || "";
    if (saved) card.classList.add("flipped");
  });
  const render = () => {
    if (!state) return;
    const pending = state.pending;
    const queue = state.queue || [];
    const completed = state.confirmed || [];
    const rank = state.nextRank;
    const used = confirmedCards();
    if (used.has(selected)) selected = null;
    progress.textContent = rank ? `${completed.length + 1} از ${queue.length}` : (queue.length ? "صف تکمیل شد" : "صف تنظیم نشده است");
    resetCards();
    if (pending) {
      selected = Number(pending.card);
      const card = cards[Number(pending.card) - 1];
      card.querySelector(".competition-card-back").textContent = pending.prizeName;
      card.classList.add("flipped", "winner");
    } else if (selected) cards[selected - 1]?.classList.add("selected");
    if (selected) cards[selected - 1]?.classList.add("selected");
    roll.disabled = !connected() || busy || !selected || !rank;
    confirm.hidden = !pending;
    cancel.hidden = !pending;
    confirm.disabled = !connected() || busy || !pending;
    cancel.disabled = !connected() || busy;
    if (reset) reset.disabled = !connected() || busy;
    cards.forEach(card => { card.disabled = !connected() || busy || !rank || used.has(Number(card.dataset.card)); });
    history.replaceChildren();
    completed.forEach((item, index) => {
      const line = document.createElement("div");
      line.className = "competition-history-item";
      line.textContent = `${index + 1}. کارت ${item.card} · ${item.prizeName}`;
      history.append(line);
    });
  };
  let audioContext = null;
  const unlockAudio = () => {
    try {
      const Audio = window.AudioContext || window.webkitAudioContext;
      if (Audio && !audioContext) audioContext = new Audio();
      if (audioContext?.state === 'suspended') audioContext.resume().catch(() => {});
    } catch (_) { /* The visual reveal also works when sound is unavailable. */ }
  };
  const revealSound = () => {
    if (!audioContext || audioContext.state !== 'running') return () => {};
    const ctx = audioContext, now = ctx.currentTime, nodes = [];
    const master = ctx.createGain();
    master.gain.value = .16;
    master.connect(ctx.destination);
    // A warm rising chord resolves into a soft, bell-like shimmer.
    [130.81, 164.81, 196].forEach(frequency => {
      const tone = ctx.createOscillator(), gain = ctx.createGain();
      tone.type = 'sine'; tone.frequency.setValueAtTime(frequency, now);
      tone.frequency.exponentialRampToValueAtTime(frequency * 2, now + 5.4);
      gain.gain.setValueAtTime(.001, now);
      gain.gain.exponentialRampToValueAtTime(.34, now + 5.4);
      gain.gain.exponentialRampToValueAtTime(.001, now + 7.6);
      tone.connect(gain); gain.connect(master); tone.start(now); tone.stop(now + 7.7); nodes.push(tone, gain);
    });
    [523.25, 659.25, 783.99, 1046.5].forEach((frequency, index) => {
      const bell = ctx.createOscillator(), gain = ctx.createGain();
      bell.type = 'triangle'; bell.frequency.value = frequency;
      const start = now + 5.4 + index * .12;
      gain.gain.setValueAtTime(0, now); gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(.3, start + .035);
      gain.gain.exponentialRampToValueAtTime(.001, start + 2);
      bell.connect(gain); gain.connect(master); bell.start(start); bell.stop(start + 2.1); nodes.push(bell, gain);
    });
    return () => { nodes.forEach(node => { try { node.stop?.(); node.disconnect(); } catch (_) {} }); master.disconnect(); };
  };
  const celebrate = layer => {
    for (let i = 0; i < 72; i++) {
      const piece = document.createElement('i');
      piece.className = 'competition-confetti';
      const left = i % 2 === 0;
      piece.style.setProperty('--start', left ? '-2vw' : '102vw');
      piece.style.setProperty('--travel', `${(left ? 1 : -1) * (25 + Math.random() * 60)}vw`);
      piece.style.setProperty('--lift', `${-15 - Math.random() * 55}vh`);
      piece.style.setProperty('--turn', `${(Math.random() - .5) * 1800}deg`);
      piece.style.setProperty('--delay', `${Math.random() * .35}s`);
      piece.style.setProperty('--color', left ? '#27aeff' : '#ff982e');
      layer.append(piece);
    }
  };
  const animate = async pending => {
    document.querySelectorAll('.competition-reveal-effects').forEach(layer => layer.remove());
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const winner = cards[Number(pending.card) - 1];
    resetCards();
    winner.classList.add("selected");
    const layer = document.createElement('div');
    layer.className = 'competition-reveal-effects'; layer.setAttribute('aria-hidden', 'true');
    const light = document.createElement('div'); light.className = 'competition-holy-light'; layer.append(light);
    const bounds = winner.getBoundingClientRect();
    layer.style.setProperty('--origin-x', `${bounds.x + bounds.width / 2}px`);
    layer.style.setProperty('--origin-y', `${bounds.y + bounds.height / 2}px`);
    document.body.append(layer);
    const stopSound = reducedMotion ? () => {} : revealSound();
    try {
      status.textContent = `کارت ${pending.card}… آماده‌اید؟`;
      winner.classList.add('charging');
      await pause(reducedMotion ? 400 : 5400);
      winner.classList.remove('charging'); winner.classList.add('releasing');
      layer.classList.add('burst');
      await pause(reducedMotion ? 0 : 600);
      winner.querySelector(".competition-card-back").textContent = pending.prizeName;
      winner.classList.add("shine", "flipped", "winner");
      status.textContent = `جایزه: ${pending.prizeName}`;
      if (!reducedMotion) celebrate(layer);
      await pause(reducedMotion ? 200 : 1600);
    } finally {
      stopSound(); winner.classList.remove('charging', 'releasing');
      // Confetti finishes its flight without keeping the controls locked.
      if (reducedMotion) layer.remove(); else setTimeout(() => layer.remove(), 2400);
    }
  };
  cards.forEach(card => card.addEventListener("click", () => {
    if (!connected() || busy || card.disabled || confirmedCards().has(Number(card.dataset.card))) return;
    selected = Number(card.dataset.card);
    status.textContent = `کارت ${selected} انتخاب شد.`;
    render();
  }));
  roll.addEventListener("click", async () => {
    if (!connected() || !selected || busy || !state?.nextRank) return;
    unlockAudio();
    busy = true;
    status.textContent = "در حال چرخش...";
    render();
    try {
      state = await request("roll", {card: selected, token: state.pending?.token || ''});
      await animate(state.pending);
      status.textContent = `جایزه: ${state.pending.prizeName}`;
    } catch (error) { showError(error); }
    finally { busy = false; render(); }
  });
  confirm.addEventListener("click", async () => {
    if (!connected() || !state?.pending || busy) return;
    busy = true;
    const name = state.pending.prizeName;
    render();
    try {
      state = await request("confirm", {token: state.pending.token});
      selected = null;
      status.textContent = `${name} ثبت شد.`;
    } catch (error) { showError(error); }
    finally { busy = false; render(); }
  });
  cancel.addEventListener("click", async () => {
    if (!connected() || !state?.pending || busy) return;
    busy = true;
    render();
    try {
      state = await request("cancel");
      selected = null;
      status.textContent = "انتخاب لغو شد.";
    } catch (error) { showError(error); }
    finally { busy = false; render(); }
  });
  const refresh = async () => {
    if (busy) return;
    try {
      state = await request("state");
      render();
    } catch (error) { showError(error); }
  };
  window.addEventListener("egmcompetitionopen", refresh);
  const resetCompetition = async () => {
    if (view.hidden || busy) return;
    if (!connected()) { status.textContent = 'ابتدا اتصال به سرور باید برقرار شود.'; return; }
    busy = true;
    status.textContent = 'در حال بازنشانی…';
    render();
    try {
      if (!state) state = await request('state');
      state = await request('reset', {version: state.resetVersion});
      selected = null;
      status.textContent = 'صف و جوایز ثبت‌شدهٔ این بازه بازنشانی شد.';
    } catch (error) { showError(error); }
    finally { busy = false; render(); }
  };
  window.addEventListener("egmcompetitionreset", resetCompetition);
  reset?.addEventListener('click', resetCompetition);
  document.addEventListener("keydown", event => {
    if (view.hidden || event.isComposing || event.ctrlKey || event.altKey || event.metaKey) return;
    if (event.target?.closest?.('input, textarea, select, [contenteditable="true"]')) return;
    if (event.shiftKey && (event.code === 'Delete' || event.key === 'Delete')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!event.repeat) void resetCompetition();
      return;
    }
    const cardMatch = /^(?:Digit|Numpad)([1-9])$/.exec(event.code);
    if (event.shiftKey && cardMatch) {
      event.preventDefault();
      if (!event.repeat && !busy) cards[Number(cardMatch[1]) - 1]?.click();
    } else if (!event.shiftKey && (event.code === 'Enter' || event.code === 'NumpadEnter' || event.code === 'Space')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (event.repeat || busy) return;
      const button = event.code === 'Space' ? confirm : roll;
      if (!button.disabled && !button.hidden) button.click();
    }
  }, true);
  window.addEventListener('egmdrawconnectionchange', render);
  if (connection) connection.register(async () => {
    if (busy) return false;
    state = await request('state');
    render();
    return true;
  });
  refresh();
})();
