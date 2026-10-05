(() => {
  const view = document.getElementById("competition-view");
  if (!view) return;
  const cards = [...view.querySelectorAll(".competition-card")];
  const progress = document.getElementById("competition-progress");
  const roll = document.getElementById("competition-roll");
  const confirm = document.getElementById("competition-confirm");
  const cancel = document.getElementById("competition-cancel");
  const status = document.getElementById("competition-status");
  const history = document.getElementById("competition-history");
  const rankNames = {1: "اول", 2: "دوم", 3: "سوم", 4: "چهارم"};
  let selected = null;
  let state = null;
  let busy = false;
  const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
  const request = async (action, extra = {}) => {
    const response = await fetch("competition_prizes.php", {
      method: "POST", credentials: "same-origin", headers: {"Content-Type": "application/json"},
      body: JSON.stringify({action, csrf: view.dataset.csrf, ...extra})
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.status !== "ok") throw new Error(result.message || "درخواست ناموفق بود.");
    return result;
  };
  const resetCards = () => cards.forEach(card => {
    card.classList.remove("flipped", "shine", "winner", "selected");
    card.querySelector(".competition-card-back").textContent = "";
  });
  const render = () => {
    if (!state) return;
    const pending = state.pending;
    const queue = state.queue || [];
    const completed = state.confirmed || [];
    const rank = state.nextRank;
    progress.textContent = rank ? `${completed.length + 1} از ${queue.length} · رتبه ${rankNames[rank]}` : (queue.length ? "صف تکمیل شد" : "صف تنظیم نشده است");
    resetCards();
    if (pending) {
      selected = Number(pending.card);
      const card = cards[selected - 1];
      card.querySelector(".competition-card-back").textContent = pending.prizeName;
      card.classList.add("flipped", "winner");
    } else if (selected) cards[selected - 1]?.classList.add("selected");
    roll.disabled = busy || !!pending || !selected || !rank;
    confirm.hidden = !pending;
    cancel.hidden = !pending;
    confirm.disabled = busy;
    cancel.disabled = busy;
    cards.forEach(card => { card.disabled = busy || !!pending || !rank; });
    history.replaceChildren();
    completed.forEach((item, index) => {
      const line = document.createElement("div");
      line.className = "competition-history-item";
      line.textContent = `${index + 1}. رتبه ${rankNames[item.rank]} · کارت ${item.card} · ${item.prizeName}`;
      history.append(line);
    });
  };
  const animate = async pending => {
    const preview = state.previewPrizes?.length ? state.previewPrizes : [pending.prizeName];
    resetCards();
    for (let step = 0; step < 14; step++) {
      const card = cards[Math.floor(Math.random() * cards.length)];
      card.querySelector(".competition-card-back").textContent = preview[Math.floor(Math.random() * preview.length)];
      card.classList.add("shine", "flipped");
      await pause(160 + step * 6);
      card.classList.remove("flipped", "shine");
      await pause(60);
    }
    const winner = cards[Number(pending.card) - 1];
    winner.querySelector(".competition-card-back").textContent = pending.prizeName;
    winner.classList.add("shine", "flipped", "winner");
    await pause(650);
  };
  cards.forEach(card => card.addEventListener("click", () => {
    if (busy || state?.pending) return;
    selected = Number(card.dataset.card);
    status.textContent = `کارت ${selected} انتخاب شد.`;
    render();
  }));
  roll.addEventListener("click", async () => {
    if (!selected || busy) return;
    busy = true;
    status.textContent = "در حال چرخش...";
    render();
    try {
      state = await request("roll", {card: selected});
      await animate(state.pending);
      status.textContent = `جایزه رتبه ${rankNames[state.pending.rank]}: ${state.pending.prizeName}`;
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; render(); }
  });
  confirm.addEventListener("click", async () => {
    if (!state?.pending || busy) return;
    busy = true;
    const name = state.pending.prizeName;
    render();
    try {
      state = await request("confirm", {token: state.pending.token});
      selected = null;
      status.textContent = `${name} ثبت شد.`;
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; render(); }
  });
  cancel.addEventListener("click", async () => {
    if (!state?.pending || busy) return;
    busy = true;
    render();
    try {
      state = await request("cancel");
      selected = null;
      status.textContent = "انتخاب لغو شد.";
    } catch (error) { status.textContent = error.message; }
    finally { busy = false; render(); }
  });
  const refresh = async () => {
    if (busy) return;
    try {
      state = await request("state");
      render();
    } catch (error) { status.textContent = error.message; }
  };
  window.addEventListener("egmcompetitionopen", refresh);
  refresh();
})();
