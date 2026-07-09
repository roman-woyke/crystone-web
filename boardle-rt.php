<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/boardle-rt.php";

$myUsername = $_SESSION["username"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Unlimited Boardle</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
<?php $cssPath = __DIR__ . "/assets/css/style.css"; ?>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">
<style>
html, body { height: 100%; }
body {
    margin: 0;
    overflow-y: auto;
    overflow-x: hidden;
    display: flex;
    flex-direction: column;
    background: var(--bg-0);
}
.rt-exit {
    position: fixed; top: 16px; left: 16px; z-index: 20;
}
.rt-stage {
    flex: 1;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 30px 24px 16px;
    text-align: center;
}
.rt-phase { display: none; width: 100%; max-width: 1100px; }
#phase-playing.active { max-width: 1320px; }
.rt-phase.active { display: block; animation: rt-fade-in 300ms var(--ease-out); }
@keyframes rt-fade-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.rt-title {
    font-family: var(--font-display);
    font-size: clamp(1.8rem, 4vw, 3rem);
    font-weight: 700;
    margin-bottom: 8px;
}
.rt-subtitle { color: var(--text-2); font-size: 1.1rem; margin-bottom: 36px; }
.rt-timer {
    font-family: var(--font-display);
    font-size: clamp(2.5rem, 6vw, 4.5rem);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--violet);
    margin-bottom: 28px;
}
.rt-timer.urgent { color: var(--danger); }
/* The playing timer shares the screen with boards + keyboard, so it stays
   small enough that the keyboard is never pushed below the fold. */
#phase-playing .rt-timer { font-size: clamp(1.6rem, 2.4vw, 2.2rem); margin-bottom: 10px; }

/* Lobby */
.rt-players {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 20px;
    max-width: 700px;
    margin: 0 auto 32px;
}
.rt-player-tile {
    aspect-ratio: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border-radius: var(--radius-lg);
    border: 2px solid var(--glass-border);
    background: var(--glass);
    font-size: 1.3rem;
    font-weight: 700;
    transition: border-color var(--t-med), background var(--t-med), transform var(--t-med);
}
.rt-player-tile.ready {
    border-color: var(--success);
    background: rgba(52, 211, 153, 0.12);
}
.rt-player-tile.me { box-shadow: var(--glow-violet); }
.rt-player-avatar {
    width: 56px; height: 56px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--grad-accent); color: #fff; font-size: 1.4rem;
}
.rt-player-status { font-size: 0.85rem; font-weight: 600; color: var(--text-3); }
.rt-player-tile.ready .rt-player-status { color: var(--success); }
.rt-ready-btn { font-size: 1.15rem; padding: 16px 42px; }

/* Voting */
.rt-vote-grid { display: flex; gap: 24px; justify-content: center; flex-wrap: wrap; }
.rt-vote-btn {
    padding: 28px 40px;
    border-radius: var(--radius-lg);
    border: 2px solid var(--glass-border);
    background: var(--glass);
    color: var(--text-1);
    cursor: pointer;
    font-family: var(--font-display);
    font-size: 1.6rem;
    font-weight: 700;
    transition: all var(--t-fast);
}
.rt-vote-btn:hover { border-color: var(--violet); transform: translateY(-3px); }
.rt-vote-btn.picked { border-color: var(--violet); background: var(--grad-accent-soft); box-shadow: var(--glow-violet); }
.rt-vote-btn .n { display: block; font-size: 0.95rem; font-weight: 500; color: var(--text-3); margin-top: 6px; }

/* Selecting */
.rt-select-panel { max-width: 560px; margin: 0 auto; }
.rt-suggestions { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; margin-bottom: 18px; }
.rt-sugg-btn {
    padding: 14px 22px; border-radius: var(--radius-md); border: 2px solid var(--glass-border);
    background: var(--glass); color: var(--text-1); font-family: var(--font-display);
    font-size: 1.1rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; cursor: pointer;
}
.rt-sugg-btn.picked { border-color: var(--violet); background: var(--grad-accent-soft); }
.rt-select-panel input[type="text"], .rt-select-panel textarea {
    width: 100%; box-sizing: border-box; margin-bottom: 12px;
    background: var(--glass); border: 1px solid var(--glass-border); border-radius: var(--radius-md);
    color: var(--text-1); padding: 12px 14px; font-size: 1rem; font-family: var(--font-body);
}
.rt-select-panel textarea { resize: vertical; min-height: 60px; }

/* Playing — same grid/keyboard model as daily Boardle, just bigger. */
:root {
    --rt-green:  #2e9e5b;
    --rt-orange: #c2992f;
    --rt-grey:   #4b4b55;
}
/* A grid (not flex) gives each board a definite track width — required for
   container-type: inline-size below to size the cells via cqw instead of
   collapsing (a content-sized flex item has no definite size to contain). */
.rt-boards {
    display: grid;
    grid-template-columns: repeat(var(--boards, 4), minmax(0, 300px));
    justify-content: center;
    gap: 16px;
    margin: 0 auto 8px;
}
@media (max-width: 700px) {
    .rt-boards { grid-template-columns: repeat(2, minmax(0, 300px)); }
}
.rt-board {
    padding: 10px; border: 1px solid var(--glass-border); border-radius: var(--radius-lg);
    background: rgba(255, 255, 255, 0.02); container-type: inline-size;
}
.rt-board.solved { border-color: rgba(46, 158, 91, 0.55); background: rgba(46, 158, 91, 0.06); }
.rt-board.failed { border-color: rgba(248, 113, 113, 0.4); }
.rt-board-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    margin-bottom: 10px; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.05em;
    text-transform: uppercase; color: var(--text-3);
}
.rt-board-head .rt-check { color: var(--success); }
/* Fixed-height slot (present even when there's no hint yet) so a long clue
   never stretches one board taller than its neighbours. */
.rt-board-hint {
    height: 2.6em;
    margin-bottom: 8px;
    font-size: 0.74rem;
    line-height: 1.3;
    color: var(--text-3);
    text-align: left;
    text-transform: none;
    overflow-y: auto;
}
.rt-grid { display: grid; grid-template-columns: repeat(var(--cols), 1fr); gap: 4px; }
.rt-cell {
    aspect-ratio: 1; box-sizing: border-box; min-width: 0; min-height: 0;
    display: flex; align-items: center; justify-content: center; border-radius: 5px;
    font-family: var(--font-display); font-weight: 700;
    font-size: clamp(0.5rem, calc(62cqw / var(--cols)), 1.4rem);
    line-height: 1; overflow: hidden; text-transform: uppercase; color: #fff; user-select: none;
}
.rt-cell.empty  { background: transparent; border: 2px solid var(--glass-border); color: var(--text-1); }
.rt-cell.active { background: rgba(255, 255, 255, 0.06); border: 2px solid var(--violet); color: var(--text-1); }
.rt-cell.blank  { background: transparent; border: 2px dashed rgba(255,255,255,0.08); }
.rt-cell.green  { background: var(--rt-green); }
.rt-cell.orange { background: var(--rt-orange); }
.rt-cell.grey   { background: var(--rt-grey); }
.rt-answer { margin-top: 8px; font-size: 0.82rem; color: var(--danger); }
.rt-answer strong { font-family: var(--font-display); letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-1); }

/* Fixed-to-viewport positioning left a huge dead gap whenever the boards
   didn't fill the screen height (the keyboard sat pinned to the bottom of
   the *window*, not to the boards). Back in normal flow, sized close to
   full daily-Boardle size, boards + keyboard together naturally fill the
   vertical space with only a small gap between them. */
.rt-controls { margin-top: 10px; }
.rt-kb-wrap {
    display: flex; justify-content: center; align-items: flex-start; gap: 10px;
    transform: scale(0.95);
    transform-origin: top center;
    margin-bottom: -12px;
}
.rt-kb-selector { display: flex; flex-direction: column; gap: 7px; flex-shrink: 0; }
.rt-kb-tab { width: 40px; max-width: 40px; flex: none; }
.rt-kb-tab.active { background: var(--grad-accent); color: #fff; box-shadow: var(--glow-violet); }
.rt-kb-tab.solved { box-shadow: inset 0 0 0 2px var(--rt-green); }
.rt-keyboard { display: flex; flex-direction: column; gap: 7px; max-width: 560px; }
.rt-krow { display: flex; justify-content: center; gap: 6px; }
.rt-key {
    flex: 1; max-width: 44px; height: 52px; display: flex; align-items: center; justify-content: center;
    border: none; border-radius: 7px; background: var(--glass-card, var(--glass)); color: var(--text-1);
    font-family: var(--font-display); font-weight: 600; font-size: 0.95rem; text-transform: uppercase; cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
}
.rt-key:hover { background: rgba(255, 255, 255, 0.12); }
.rt-key:active { transform: translateY(1px); }
.rt-key.wide { max-width: 76px; font-size: 0.74rem; letter-spacing: 0.04em; }
.rt-key.space { max-width: 260px; font-size: 0.74rem; letter-spacing: 0.1em; }
.rt-key[disabled] { cursor: not-allowed; opacity: 0.6; }
.rt-key.green  { background: var(--rt-green); color: #fff; }
.rt-key.orange { background: var(--rt-orange); color: #fff; }
.rt-key.grey   { background: var(--rt-grey); color: #fff; opacity: 0.85; }

/* Playing layout: boards + keyboard on the left, opponents' live progress
   pinned to the right so it reads as a sidebar, not a trailing footer. */
.rt-play-layout { display: flex; align-items: flex-start; justify-content: center; gap: 28px; }
.rt-play-main { flex: 1; min-width: 0; max-width: 900px; }
.rt-play-side {
    width: 220px; flex-shrink: 0; display: flex; flex-direction: column; gap: 12px;
    position: sticky; top: 16px;
}
.rt-opp {
    padding: 10px; border-radius: var(--radius-md); border: 1px solid var(--glass-border);
    background: var(--glass); text-align: left;
}
.rt-opp-name {
    font-size: 0.82rem; font-weight: 700; color: var(--text-2); margin-bottom: 8px;
    display: flex; align-items: center; justify-content: space-between;
}
.rt-opp-name .rt-check { color: var(--success); }
.rt-opp-boards { display: flex; gap: 6px; flex-wrap: wrap; }
.rt-opp-grid { display: grid; grid-template-columns: repeat(var(--cols), 1fr); gap: 2px; width: 48px; }
.rt-opp-cell { aspect-ratio: 1; border-radius: 2px; background: rgba(255,255,255,0.08); }
.rt-opp-cell.green { background: var(--rt-green); }
.rt-opp-cell.orange { background: var(--rt-orange); }
.rt-opp-cell.grey { background: var(--rt-grey); }
@media (max-width: 900px) {
    .rt-play-layout { flex-direction: column; align-items: center; }
    .rt-play-side { position: static; width: 100%; max-width: 500px; flex-direction: row; flex-wrap: wrap; }
    .rt-opp { flex: 1; min-width: 140px; }
}

/* Results */
.rt-results-table { margin: 0 auto 28px; border-collapse: collapse; }
.rt-results-table td, .rt-results-table th { padding: 8px 18px; text-align: left; }
.rt-results-table th { color: var(--text-3); font-size: 0.8rem; text-transform: uppercase; }
.rt-podiums { display: flex; gap: 40px; justify-content: center; flex-wrap: wrap; margin-bottom: 28px; }
.rt-podium { min-width: 220px; }
.rt-podium h3 { font-family: var(--font-display); margin-bottom: 10px; }
.rt-podium ol { list-style: none; margin: 0; padding: 0; text-align: left; }
.rt-podium li { display: flex; justify-content: space-between; padding: 6px 14px; border-radius: var(--radius-sm); }
.rt-podium li:first-child { background: rgba(251, 191, 36, 0.12); font-weight: 700; }
.rt-badge { font-size: 0.75rem; padding: 2px 8px; border-radius: var(--radius-full); margin-left: 6px; }
.rt-badge.speed { background: rgba(59,130,246,0.2); color: var(--info); }
.rt-badge.guess { background: rgba(52,211,153,0.2); color: var(--success); }

.rt-msg { min-height: 1.4em; color: var(--danger); margin-top: 10px; }
</style>
</head>
<body>

<a class="btn btn-ghost rt-exit" href="<?= BASE_PATH ?>/boardle.php">← Exit</a>

<div class="rt-stage">

    <div class="rt-phase" id="phase-lobby">
        <div class="rt-title">Unlimited Boardle</div>
        <div class="rt-subtitle" id="lobby-sub"></div>
        <div class="rt-players" id="lobby-players"></div>
        <button type="button" class="btn-primary rt-ready-btn" id="ready-btn">I'm ready</button>
    </div>

    <div class="rt-phase" id="phase-voting">
        <div class="rt-title">Vote a word length</div>
        <div class="rt-timer" id="vote-timer">15</div>
        <div class="rt-vote-grid" id="vote-grid"></div>
        <p class="rt-msg" id="vote-msg"></p>
    </div>

    <div class="rt-phase" id="phase-selecting">
        <div class="rt-title">Pick your word <span id="select-length"></span></div>
        <div class="rt-timer" id="select-timer">2:00</div>
        <div class="rt-select-panel" id="select-panel">
            <div class="rt-suggestions" id="select-suggestions"></div>
            <button type="button" class="btn" id="select-regen">Regenerate</button>
            <input type="text" id="select-custom" placeholder="or type your own word" autocomplete="off">
            <textarea id="select-hint" placeholder="Mandatory hint for whoever plays your board…"></textarea>
            <button type="button" class="btn-primary" id="select-submit">Confirm word + hint</button>
            <p class="rt-msg" id="select-msg"></p>
            <p class="rt-subtitle" id="select-waiting" style="display:none;">Word locked in. Waiting for the others…</p>
        </div>
    </div>

    <div class="rt-phase" id="phase-playing">
        <div class="rt-play-layout">
            <div class="rt-play-main">
                <div class="rt-timer" id="play-timer">15:00</div>
                <div class="rt-boards" id="play-boards"></div>
                <p class="rt-msg" id="play-msg"></p>
                <div class="rt-controls">
                    <div class="rt-kb-wrap">
                        <div class="rt-kb-selector" id="play-kb-selector"></div>
                        <div class="rt-keyboard" id="play-keyboard"></div>
                    </div>
                </div>
            </div>
            <div class="rt-play-side" id="play-opponents"></div>
        </div>
    </div>

    <div class="rt-phase" id="phase-results">
        <div class="rt-title">Round over</div>
        <table class="rt-results-table" id="results-table"></table>
        <div class="rt-podiums" id="results-podiums"></div>
        <button type="button" class="btn-primary" id="results-continue">Continue</button>
    </div>

</div>

<script>
(function () {
    const BASE_PATH = "<?= BASE_PATH ?>";
    const ME = <?= json_encode($myUsername) ?>;
    let STATE = null;
    let busy = false;

    const phases = ["lobby", "voting", "selecting", "playing", "results"];
    const els = {};
    phases.forEach(p => els[p] = document.getElementById("phase-" + p));

    function showPhase(p) {
        phases.forEach(name => els[name].classList.toggle("active", name === p));
    }

    function fmtMMSS(secs) {
        secs = Math.max(0, Math.round(secs));
        const m = Math.floor(secs / 60), s = secs % 60;
        return `${m}:${String(s).padStart(2, "0")}`;
    }

    function post(url, params) {
        const body = new FormData();
        Object.entries(params || {}).forEach(([k, v]) => body.append(k, v));
        return fetch(BASE_PATH + url, { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Request failed."); }));
    }

    function loadState() {
        return fetch(BASE_PATH + "/api/boardle-rt-state.php")
            .then(r => r.json())
            .then(state => { STATE = state; render(); })
            .catch(() => {});
    }

    function render() {
        if (!STATE) return;
        showPhase(STATE.phase);
        if (STATE.phase === "lobby") renderLobby();
        else if (STATE.phase === "voting") renderVoting();
        else if (STATE.phase === "selecting") renderSelecting();
        else if (STATE.phase === "playing") renderPlaying();
        else if (STATE.phase === "results") renderResults();
    }

    // ── Lobby ────────────────────────────────────────────────────────────────
    // Only whoever currently has this window open shows up here (tracked via a
    // presence heartbeat sent with every poll) — not every site user, and not
    // everyone who's ever clicked ready. A round only starts once everyone
    // shown here is ready.
    function renderLobby() {
        const active = STATE.active || [];
        document.getElementById("lobby-players").innerHTML = active.map(p => {
            const isMe = p.username === ME;
            return `
                <div class="rt-player-tile ${p.ready ? "ready" : ""} ${isMe ? "me" : ""}">
                    <div class="rt-player-avatar">${p.username.slice(0, 1).toUpperCase()}</div>
                    <div>${p.username}</div>
                    <div class="rt-player-status">${p.ready ? "Ready" : "Waiting"}</div>
                </div>
            `;
        }).join("");

        const mine = active.find(p => p.username === ME);
        const iAmReady = mine ? mine.ready : false;
        document.getElementById("ready-btn").textContent = iAmReady ? "Cancel ready" : "I'm ready";

        const subEl = document.getElementById("lobby-sub");
        if (active.length < STATE.min_players) {
            subEl.textContent = `Waiting for at least ${STATE.min_players} players to open this page…`;
        } else if (active.every(p => p.ready)) {
            subEl.textContent = "Starting…";
        } else {
            subEl.textContent = "Waiting for everyone here to be ready…";
        }
    }

    document.getElementById("ready-btn").addEventListener("click", () => {
        if (busy) return;
        busy = true;
        const mine = (STATE.active || []).find(p => p.username === ME);
        const nowReady = !(mine && mine.ready);
        post("/api/boardle-rt-ready.php", { ready: nowReady ? "1" : "0" })
            .then(state => { STATE = state; render(); })
            .catch(() => {})
            .finally(() => { busy = false; });
    });

    // ── Voting ───────────────────────────────────────────────────────────────
    function renderVoting() {
        const remaining = STATE.deadline - STATE.elapsed;
        const timerEl = document.getElementById("vote-timer");
        timerEl.textContent = Math.max(0, Math.ceil(remaining));
        timerEl.classList.toggle("urgent", remaining <= 5);

        document.getElementById("vote-grid").innerHTML = STATE.length_choices.map(len => {
            const picked = STATE.my_vote === len;
            const n = (STATE.tally && STATE.tally[len]) || 0;
            return `
                <button type="button" class="rt-vote-btn ${picked ? "picked" : ""}" data-len="${len}">
                    ${len} letters
                    <span class="n">${n} vote${n === 1 ? "" : "s"}</span>
                </button>
            `;
        }).join("");
        document.getElementById("vote-grid").querySelectorAll(".rt-vote-btn").forEach(btn => {
            btn.addEventListener("click", () => {
                if (busy || !STATE.in_round) return;
                busy = true;
                post("/api/boardle-rt-vote.php", { length: btn.dataset.len })
                    .then(state => { STATE = state; render(); })
                    .catch(err => { document.getElementById("vote-msg").textContent = err.message; })
                    .finally(() => { busy = false; });
            });
        });
    }

    // ── Selecting ────────────────────────────────────────────────────────────
    let lastSuggestLength = null;
    function renderSelecting() {
        const remaining = STATE.deadline - STATE.elapsed;
        const timerEl = document.getElementById("select-timer");
        timerEl.textContent = fmtMMSS(remaining);
        timerEl.classList.toggle("urgent", remaining <= 20);

        document.getElementById("select-length").textContent = `(${STATE.word_length} letters)`;

        const panel = document.getElementById("select-panel");
        const waiting = document.getElementById("select-waiting");
        const inputEls = panel.querySelectorAll("input, textarea, button");
        if (STATE.i_chose || !STATE.in_round) {
            inputEls.forEach(el => { el.style.display = "none"; });
            waiting.style.display = "";
            waiting.textContent = STATE.in_round
                ? "Word locked in. Waiting for the others…"
                : "Spectating this round.";
            return;
        }
        inputEls.forEach(el => { el.style.display = ""; });
        waiting.style.display = "none";

        if (lastSuggestLength !== STATE.word_length) {
            lastSuggestLength = STATE.word_length;
            loadSuggestions();
        }
    }

    function loadSuggestions() {
        fetch(BASE_PATH + "/api/boardle-rt-suggest.php")
            .then(r => r.json())
            .then(res => {
                document.getElementById("select-suggestions").innerHTML = (res.suggestions || []).map(w =>
                    `<button type="button" class="rt-sugg-btn" data-word="${w}">${w}</button>`
                ).join("");
                document.getElementById("select-suggestions").querySelectorAll(".rt-sugg-btn").forEach(btn => {
                    btn.addEventListener("click", () => {
                        document.getElementById("select-custom").value = btn.dataset.word;
                        document.querySelectorAll(".rt-sugg-btn").forEach(b => b.classList.toggle("picked", b === btn));
                    });
                });
            })
            .catch(() => {});
    }
    document.getElementById("select-regen").addEventListener("click", loadSuggestions);
    document.getElementById("select-submit").addEventListener("click", () => {
        if (busy) return;
        const word = document.getElementById("select-custom").value.trim();
        const hint = document.getElementById("select-hint").value.trim();
        const msgEl = document.getElementById("select-msg");
        if (!word) { msgEl.textContent = "Pick or type a word."; return; }
        if (!hint) { msgEl.textContent = "A hint is mandatory."; return; }
        busy = true;
        post("/api/boardle-rt-choose.php", { word, hint })
            .then(state => { STATE = state; msgEl.textContent = ""; render(); })
            .catch(err => { msgEl.textContent = err.message; })
            .finally(() => { busy = false; });
    });

    // ── Playing — same typing/keyboard model as daily Boardle: any word from
    // 5 letters up to the round's length, positioned in the row via the space
    // bar, one shared guess scored against every board at once. ──────────────
    const KB_ROWS = ["qwertyuiop", "asdfghjkl", "zxcvbnm"];
    let buffer = "";
    let kbBoard = 0;
    let lastPlayingRound = null;
    const playBoardsEl      = document.getElementById("play-boards");
    const playKeyboardEl    = document.getElementById("play-keyboard");
    const playKbSelectorEl  = document.getElementById("play-kb-selector");
    const playMsgEl         = document.getElementById("play-msg");

    function cell(letter, cls) {
        return `<div class="rt-cell ${cls}">${letter}</div>`;
    }

    function buildKeyboard() {
        let html = "";
        KB_ROWS.forEach((row, idx) => {
            html += `<div class="rt-krow">`;
            if (idx === 2) html += `<button type="button" class="rt-key wide" data-key="enter">Enter</button>`;
            for (const ch of row) html += `<button type="button" class="rt-key" data-key="${ch}">${ch}</button>`;
            if (idx === 2) html += `<button type="button" class="rt-key wide" data-key="back">⌫</button>`;
            html += `</div>`;
        });
        html += `<div class="rt-krow"><button type="button" class="rt-key space" data-key="space">space</button></div>`;
        playKeyboardEl.innerHTML = html;
        playKeyboardEl.querySelectorAll(".rt-key").forEach(btn => {
            btn.addEventListener("click", () => handleKey(btn.dataset.key));
        });
    }

    function handleKey(key) {
        if (!STATE || STATE.phase !== "playing" || STATE.me.finished || busy) return;
        if (key === "enter") return submitGuess();
        const len = STATE.word_length;
        if (key === "back")  { buffer = buffer.slice(0, -1); renderPlaying(); return; }
        if (key === "space") {
            if (buffer.length < len) { buffer += " "; renderPlaying(); }
            return;
        }
        if (/^[a-z]$/.test(key) && buffer.length < len) {
            buffer += key;
            renderPlaying();
        }
    }

    // Best colour seen for each letter on a given board (green > orange > grey).
    function kbStatesFor(b) {
        const rank = { grey: 1, orange: 2, green: 3 };
        const st = {};
        const bump = (ch, c) => { if (!st[ch] || rank[c] > rank[st[ch]]) st[ch] = c; };
        for (const g of STATE.me.guesses) {
            const colors = g.boards[b];
            for (let i = 0; i < g.text.length; i++) {
                const ch = g.text[i];
                const c = colors[i];
                if (ch === "_" || c === "empty") continue;
                bump(ch, c);
            }
        }
        return st;
    }

    // The 1·2·3·4 board switcher + per-board key tinting.
    function renderKeyboard() {
        const n = STATE.num_boards;
        if (kbBoard >= n) kbBoard = 0;

        if (n <= 1) {
            playKbSelectorEl.style.display = "none";
        } else {
            playKbSelectorEl.style.display = "flex";
            playKbSelectorEl.innerHTML = "";
            for (let b = 0; b < n; b++) {
                const solved = STATE.me.solved_boards[b];
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "rt-key rt-kb-tab" + (b === kbBoard ? " active" : "") + (solved ? " solved" : "");
                btn.textContent = String(b + 1);
                btn.title = "Board " + (b + 1) + (solved ? " (solved)" : "");
                btn.addEventListener("click", () => { kbBoard = b; renderKeyboard(); });
                playKbSelectorEl.appendChild(btn);
            }
        }

        const st = n > 0 ? kbStatesFor(kbBoard) : {};
        playKeyboardEl.querySelectorAll(".rt-key").forEach(key => {
            const k = key.dataset.key;
            key.classList.remove("green", "orange", "grey");
            if (/^[a-z]$/.test(k) && st[k]) key.classList.add(st[k]);
            key.disabled = STATE.me.finished;
        });
    }

    function renderPlaying() {
        if (lastPlayingRound !== STATE.round_id) {
            lastPlayingRound = STATE.round_id;
            buffer = "";
            kbBoard = 0;
        }

        const remaining = STATE.deadline - STATE.elapsed;
        const timerEl = document.getElementById("play-timer");
        timerEl.textContent = fmtMMSS(remaining);
        timerEl.classList.toggle("urgent", remaining <= 60);

        const me  = STATE.me;
        const n   = STATE.num_boards;
        const len = STATE.word_length;
        const max = STATE.max_guesses;
        const activeRowIndex = me.finished ? -1 : me.guesses_used;
        const answers = me.answers || [];

        playBoardsEl.style.setProperty("--boards", n);

        let html = "";
        for (let b = 0; b < n; b++) {
            const isMine  = b === STATE.my_position;
            const solved  = me.solved_boards[b];
            const failed  = me.finished && !solved;
            html += `<div class="rt-board ${solved ? "solved" : ""} ${failed ? "failed" : ""}">`;
            const badge = isMine
                ? '<span class="rt-check">★ your word</span>'
                : (solved ? '<span class="rt-check">✓ solved</span>' : "");
            const owner = (STATE.players && STATE.players[b]) || `Board ${b + 1}`;
            html += `<div class="rt-board-head"><span>${owner}</span>${badge}</div>`;

            const hint = (me.hints && me.hints[b]) ? me.hints[b] : "";
            html += `<div class="rt-board-hint">${hint ? `<strong>Hint:</strong> ${hint}` : ""}</div>`;

            html += `<div class="rt-grid" style="--cols:${len}">`;
            if (isMine) {
                // Your own board is auto-solved from the start — revealed, no typing.
                const ans = (me.own_word || "").toUpperCase();
                for (let r = 0; r < max; r++) {
                    for (let i = 0; i < len; i++) {
                        html += (r === 0) ? cell(ans[i] || "", "green") : cell("", "empty");
                    }
                }
            } else {
                // A solved board freezes at its winning row.
                let shown = me.guesses.length;
                if (solved) {
                    for (let r = 0; r < me.guesses.length; r++) {
                        if (me.guesses[r].boards[b].every(c => c === "green")) { shown = r + 1; break; }
                    }
                }
                for (let r = 0; r < max; r++) {
                    if (r < shown) {
                        const g = me.guesses[r];
                        const colors = g.boards[b];
                        for (let i = 0; i < len; i++) {
                            const ch = g.text[i] === "_" ? "" : g.text[i].toUpperCase();
                            html += cell(ch, colors[i]);
                        }
                    } else if (r === activeRowIndex && !solved) {
                        for (let i = 0; i < len; i++) {
                            const ch = buffer[i];
                            if (ch && ch !== " ") html += cell(ch.toUpperCase(), "active");
                            else html += cell("", buffer.length ? "blank" : "empty");
                        }
                    } else {
                        for (let i = 0; i < len; i++) html += cell("", "empty");
                    }
                }
            }
            html += `</div>`;
            if (!isMine && failed && answers[b]) {
                html += `<div class="rt-answer">Answer: <strong>${answers[b]}</strong></div>`;
            }
            html += `</div>`;
        }
        playBoardsEl.innerHTML = html;

        document.getElementById("play-opponents").innerHTML = (STATE.opponents || []).map(o => {
            // o.boards is a list of guess ROWS (each with colors per board
            // position) — build one mini-grid per board, freezing at the
            // winning row like the main boards do. The board that is this
            // opponent's own pick is skipped: it's auto-solved for them from
            // the start, so it's not real progress and showing it would leak
            // color hints about their own word to everyone else.
            let minis = "";
            for (let b = 0; b < n; b++) {
                if (b === o.position) continue;
                let shown = o.boards.length;
                if (o.solved_boards[b]) {
                    for (let r = 0; r < o.boards.length; r++) {
                        if (o.boards[r].boards[b].every(c => c === "green")) { shown = r + 1; break; }
                    }
                }
                let cellsHtml = "";
                for (let r = 0; r < shown; r++) {
                    cellsHtml += o.boards[r].boards[b].map(c => `<div class="rt-opp-cell ${c}"></div>`).join("");
                }
                minis += `<div class="rt-opp-grid" style="--cols:${len}">${cellsHtml}</div>`;
            }
            return `
                <div class="rt-opp">
                    <div class="rt-opp-name"><span>${o.username}</span>${o.solved ? '<span class="rt-check">✓</span>' : ""}</div>
                    <div class="rt-opp-boards">${minis}</div>
                </div>
            `;
        }).join("");

        playMsgEl.textContent = me.finished
            ? (me.solved ? "You solved every board!" : "No guesses left — waiting for the round to end.")
            : "";

        renderKeyboard();
    }

    function submitGuess() {
        if (busy || STATE.me.finished) return;
        // Pull the contiguous word out of the row and where it sits.
        const first = buffer.search(/[a-z]/);
        if (first === -1) { playMsgEl.textContent = "Type a word."; return; }
        const last = buffer.length - 1 - [...buffer].reverse().findIndex(c => /[a-z]/.test(c));
        const word = buffer.slice(first, last + 1);
        if (/\s/.test(word)) { playMsgEl.textContent = "No gaps inside the word — spaces only before or after it."; return; }
        if (word.length < 5)  { playMsgEl.textContent = "Use at least 5 letters."; return; }

        busy = true;
        post("/api/boardle-rt-guess.php", { word, offset: first })
            .then(state => { STATE = state; buffer = ""; playMsgEl.textContent = ""; render(); })
            .catch(err => { playMsgEl.textContent = err.message; })
            .finally(() => { busy = false; });
    }

    document.addEventListener("keydown", e => {
        if (!STATE || STATE.phase !== "playing") return;
        const tag = (document.activeElement && document.activeElement.tagName) || "";
        if (tag === "INPUT" || tag === "TEXTAREA") return; // don't hijack the hint/custom-word fields
        if (e.key === "Enter")          { e.preventDefault(); handleKey("enter"); }
        else if (e.key === "Backspace") { e.preventDefault(); handleKey("back"); }
        else if (e.key === " ")         { e.preventDefault(); handleKey("space"); }
        else if (/^[a-zA-Z]$/.test(e.key)) { handleKey(e.key.toLowerCase()); }
    });

    // ── Results ──────────────────────────────────────────────────────────────
    function podiumHtml(title, rows, key, badgeClass) {
        const top = rows.slice(0, 3);
        return `
            <div class="rt-podium">
                <h3>${title}</h3>
                <ol>
                    ${top.map(r => `<li><span>${r.username}</span><span>${r[key]} win${r[key] === 1 ? "" : "s"}</span></li>`).join("")}
                </ol>
            </div>
        `;
    }
    function renderResults() {
        const rows = STATE.results || [];
        document.getElementById("results-table").innerHTML = `
            <tr><th>Player</th><th>Solved</th><th>Guesses</th><th></th></tr>
            ${rows.map(r => `
                <tr>
                    <td>${r.username}</td>
                    <td>${r.solved ? "✓" : "✗"}</td>
                    <td>${r.guesses_used}</td>
                    <td>
                        ${r.speed_win ? '<span class="rt-badge speed">Fastest</span>' : ""}
                        ${r.guess_win ? '<span class="rt-badge guess">Fewest guesses</span>' : ""}
                    </td>
                </tr>
            `).join("")}
        `;
        const st = STATE.standings || { speed: [], guesses: [] };
        document.getElementById("results-podiums").innerHTML =
            podiumHtml("Fastest solvers", st.speed, "speed_wins") +
            podiumHtml("Fewest guesses", st.guesses, "guess_wins");
    }
    document.getElementById("results-continue").addEventListener("click", () => {
        if (busy) return;
        busy = true;
        post("/api/boardle-rt-continue.php", {})
            .then(state => { STATE = state; render(); })
            .catch(() => {})
            .finally(() => { busy = false; });
    });

    buildKeyboard();
    loadState();
    setInterval(() => { if (!document.hidden) loadState(); }, 1500);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) loadState(); });
}());
</script>
</body>
</html>
