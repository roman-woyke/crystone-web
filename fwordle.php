<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/fwordle.php";

// Server-render the full state so the grid paints complete on first byte.
$initialState = fwordleState($pdo, date("Y-m-d"), (int) $_SESSION["user_id"]);

$pageTitle = "Boardle";
require_once __DIR__ . "/includes/header.php";
?>

<style>
main.container { max-width: 1280px; }

.fw-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 28px;
    align-items: start;
}

.fw-main { min-width: 0; }

.fw-intro { margin-bottom: 18px; }

.fw-head { margin-bottom: 6px; }

.fw-sub {
    margin: 0;
    color: var(--text-2);
    max-width: 640px;
    font-size: 0.92rem;
}

/* ── Status bar ─────────────────────────────────────────────────────────── */
.fw-status {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    align-items: center;
    margin-bottom: 20px;
    font-size: 0.9rem;
    color: var(--text-2);
}

.fw-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-full);
    background: rgba(255, 255, 255, 0.03);
}

.fw-pill strong { color: var(--text-1); font-weight: 700; }

/* ── Boards ─────────────────────────────────────────────────────────────── */
.fw-boards {
    display: grid;
    grid-template-columns: repeat(var(--boards, 4), minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}

/* On phones, all-on-one-line gets too cramped — fall back to two per row. */
@media (max-width: 600px) {
    .fw-boards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

.fw-board {
    padding: 14px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    background: rgba(255, 255, 255, 0.02);
    container-type: inline-size; /* lets cells size their font to the board width */
}

.fw-board.solved {
    border-color: rgba(46, 158, 91, 0.55);
    background: rgba(46, 158, 91, 0.06);
}

.fw-board.failed {
    border-color: rgba(248, 113, 113, 0.4);
}

.fw-board-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-3);
}

.fw-board-head .fw-check { color: var(--success); }

.fw-grid {
    display: grid;
    grid-template-columns: repeat(var(--cols), 1fr);
    gap: 4px;
}

.fw-cell {
    aspect-ratio: 1;
    box-sizing: border-box;
    min-width: 0;
    min-height: 0; /* keep the square — don't let the letter push the cell taller */
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 5px;
    font-family: var(--font-display);
    font-weight: 700;
    /* font scales to the cell (board width ÷ columns), so 8–10-wide boards fit */
    font-size: clamp(0.5rem, calc(62cqw / var(--cols)), 1.25rem);
    line-height: 1;
    overflow: hidden;
    text-transform: uppercase;
    color: #fff;
    user-select: none;
}

.fw-cell.empty  { background: transparent; border: 2px solid var(--glass-border); color: var(--text-1); }
.fw-cell.active { background: rgba(255, 255, 255, 0.06); border: 2px solid var(--violet); color: var(--text-1); }
.fw-cell.blank  { background: transparent; border: 2px dashed rgba(255,255,255,0.08); }
.fw-cell.green  { background: var(--fw-green); }
.fw-cell.orange { background: var(--fw-orange); }
.fw-cell.grey   { background: var(--fw-grey); }

.fw-answer {
    margin-top: 8px;
    text-align: center;
    font-size: 0.82rem;
    color: var(--danger);
}
.fw-answer strong {
    font-family: var(--font-display);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-1);
}

:root {
    --fw-green:  #2e9e5b;
    --fw-orange: #c2992f;
    --fw-grey:   #4b4b55;
}

/* ── Banner ─────────────────────────────────────────────────────────────── */
.fw-banner {
    margin-bottom: 18px;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    font-weight: 600;
}
.fw-banner.win  { background: rgba(46, 158, 91, 0.14); border: 1px solid rgba(46,158,91,0.5); color: #7ee2a8; }
.fw-banner.lose { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248,113,113,0.45); color: #fca5a5; }

/* ── Controls / keyboard ────────────────────────────────────────────────── */
.fw-controls { margin-bottom: 20px; }

.fw-msg {
    margin: 12px 0 0;
    min-height: 1.2em;
    text-align: center;
    font-size: 0.88rem;
    color: var(--danger);
}
.fw-msg.ok { color: var(--success); }

.fw-kb-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    gap: 10px;
}

/* Vertical board switcher: which board's letter colours the keyboard shows. */
.fw-kb-selector {
    display: flex;
    flex-direction: column;
    gap: 7px;
    flex-shrink: 0;
}

.fw-kb-tab {
    width: 40px;
    max-width: 40px;
    flex: none;
}
.fw-kb-tab.active {
    background: var(--grad-accent);
    color: #fff;
    box-shadow: var(--glow-violet);
}
.fw-kb-tab.solved { box-shadow: inset 0 0 0 2px var(--fw-green); }

.fw-keyboard {
    display: flex;
    flex-direction: column;
    gap: 7px;
    max-width: 560px;
}

.fw-krow {
    display: flex;
    justify-content: center;
    gap: 6px;
}

.fw-key {
    flex: 1;
    max-width: 44px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 7px;
    background: var(--glass-card);
    color: var(--text-1);
    font-family: var(--font-display);
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: uppercase;
    cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
}

.fw-key:hover { background: rgba(255, 255, 255, 0.12); }
.fw-key:active { transform: translateY(1px); }

/* Press feedback — fires on click and on physical-keyboard typing. */
.fw-key.pressed { animation: fw-key-press 0.16s ease-out; }
@keyframes fw-key-press {
    0%   { transform: scale(1); }
    40%  { transform: scale(0.86); box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.5); }
    100% { transform: scale(1); }
}
@media (prefers-reduced-motion: reduce) {
    .fw-key.pressed { animation: none; }
}
.fw-key.wide { max-width: 76px; font-size: 0.74rem; letter-spacing: 0.04em; }
.fw-key.space { max-width: 260px; font-size: 0.74rem; letter-spacing: 0.1em; }
.fw-key[disabled] { cursor: not-allowed; }

/* Letter feedback (for the currently selected board). */
.fw-key.green  { background: var(--fw-green); color: #fff; }
.fw-key.orange { background: var(--fw-orange); color: #fff; }
.fw-key.grey   { background: var(--fw-grey); color: #fff; opacity: 0.85; }

/* ── Tomorrow's word picker ─────────────────────────────────────────────── */
.fw-choose { padding: 20px; }
.fw-choose h2 { margin: 0 0 4px; font-size: 1.1rem; }
.fw-choose p.muted { margin: 0 0 14px; font-size: 0.86rem; }

.fw-suggests {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

.fw-suggest {
    width: auto;
    margin: 0;
    padding: 9px 16px;
    font-family: var(--font-display);
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.fw-custom-row {
    display: flex;
    gap: 10px;
    align-items: stretch;
    flex-wrap: wrap;
}
.fw-custom-row input {
    flex: 1;
    min-width: 160px;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-family: var(--font-display);
}
.fw-custom-row button { width: auto; margin: 0; padding: 0 20px; }

.fw-chosen {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    background: var(--grad-accent-soft);
    border: 1px solid rgba(139, 92, 246, 0.4);
}
.fw-chosen strong {
    font-family: var(--font-display);
    letter-spacing: 0.14em;
    text-transform: uppercase;
}
.fw-chosen button {
    width: auto;
    margin: 8px 0 0;
    padding: 5px 14px;
    font-size: 0.8rem;
}

.fw-choose-msg { margin: 10px 0 0; min-height: 1.1em; font-size: 0.85rem; color: var(--danger); }
.fw-choose-msg.ok { color: var(--success); }

/* ── Sidebar: competition ───────────────────────────────────────────────── */
.fw-sidebar { position: sticky; top: 78px; }
.fw-sidebar > h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 14px;
    font-size: 1.05rem;
}
.fw-sidebar > h2:not(:first-child) { margin-top: 22px; }

/* Streak / average-guesses slots — one per player. */
.fw-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.fw-stat {
    padding: 9px 11px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    background: var(--solid-glass);
}
.fw-stat.empty { opacity: 0.5; }

.fw-stat-name {
    font-weight: 700;
    font-size: 0.86rem;
    margin-bottom: 5px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fw-stat-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 6px;
    font-size: 0.78rem;
    color: var(--text-2);
    font-variant-numeric: tabular-nums;
}
.fw-stat-streak { font-weight: 700; color: var(--text-1); }
.fw-stat-avg { color: var(--text-3); }

.fw-opp {
    padding: 12px 14px;
    margin-bottom: 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    background: var(--solid-glass);
}

.fw-opp-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 9px;
}
.fw-opp-name { font-weight: 700; font-size: 0.92rem; }
.fw-opp-stat { font-size: 0.74rem; color: var(--text-3); font-variant-numeric: tabular-nums; }
.fw-opp.done .fw-opp-name { color: var(--success); }

.fw-mini-wrap { display: flex; flex-wrap: wrap; gap: 8px; }

.fw-mini {
    display: grid;
    grid-template-columns: repeat(var(--cols), 1fr);
    gap: 2px;
    flex: 1;
    min-width: 0;
}

.fw-mini .fw-mcell {
    aspect-ratio: 1;
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.04);
}
.fw-mini .fw-mcell.green  { background: var(--fw-green); }
.fw-mini .fw-mcell.orange { background: var(--fw-orange); }
.fw-mini .fw-mcell.grey   { background: var(--fw-grey); }

.fw-empty-note { color: var(--text-3); font-size: 0.86rem; }

@media (max-width: 920px) {
    .fw-layout { display: flex; flex-direction: column; }
    .fw-sidebar { position: static; order: 1; }
}
</style>

<div class="fw-layout">
<div class="fw-main">

    <div class="fw-intro">
        <h1 class="page-heading fw-head">B<span class="gradient-text">oardle</span></h1>
        <p class="fw-sub">
            One puzzle a day, shared by everyone. Solve every board before the shared guesses run
            out — nine on a full four-board day, one fewer for each board the group lost. A guess
            can be any word from 5 letters up to the day's length — use the space bar to place it.
            Beat the day and you get to set one of tomorrow's words.
        </p>
    </div>

    <div class="fw-status" id="fw-status"></div>

    <div class="fw-banner" id="fw-banner" style="display:none;"></div>

    <div class="fw-boards" id="fw-boards"></div>

    <div class="fw-controls" id="fw-controls">
        <div class="fw-kb-wrap">
            <div class="fw-kb-selector" id="fw-kb-selector"></div>
            <div class="fw-keyboard" id="fw-keyboard"></div>
        </div>
        <p class="fw-msg" id="fw-msg"></p>
    </div>

    <div class="fw-choose glass-card" id="fw-choose" style="display:none;"></div>

</div><!-- /fw-main -->

<aside class="fw-sidebar">
    <h2>🔥 Streaks</h2>
    <div class="fw-stats" id="fw-stats"></div>

    <h2>⚔️ Competition</h2>
    <div id="fw-opponents"></div>
</aside>

</div><!-- /fw-layout -->

<script>
(function () {
    const BASE_PATH = "<?= BASE_PATH ?>";
    let STATE = <?= json_encode($initialState) ?>;

    const MAX = STATE.max_guesses;

    // ── Local input state (never lives on the server until submitted) ──────
    // `buffer` is the row as typed: letters plus spaces (a space = a blank cell
    // used to push a shorter word into position). Capped at the day's length.
    let buffer = "";
    let busy = false;
    let kbBoard = 0;   // which board's letter colours the keyboard shows

    const L = () => STATE.length;

    // ── DOM refs ───────────────────────────────────────────────────────────
    const statusEl  = document.getElementById("fw-status");
    const boardsEl   = document.getElementById("fw-boards");
    const bannerEl   = document.getElementById("fw-banner");
    const keyboardEl = document.getElementById("fw-keyboard");
    const selectorEl = document.getElementById("fw-kb-selector");
    const msgEl      = document.getElementById("fw-msg");
    const chooseEl   = document.getElementById("fw-choose");
    const oppEl      = document.getElementById("fw-opponents");
    const statsEl    = document.getElementById("fw-stats");

    function escapeHtml(v) {
        return String(v).replaceAll("&", "&amp;").replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");
    }
    function setMsg(text, ok) { msgEl.textContent = text || ""; msgEl.classList.toggle("ok", !!ok); }

    // ── Status bar ───────────────────────────────────────────────────────
    function renderStatus() {
        const me = STATE.me;
        statusEl.innerHTML = `
            <span class="fw-pill">📅 <strong>${escapeHtml(STATE.date)}</strong></span>
            <span class="fw-pill"><strong>${L()}</strong>-letter words</span>
            <span class="fw-pill"><strong>${STATE.num_boards}</strong> board${STATE.num_boards === 1 ? "" : "s"}</span>
            <span class="fw-pill">Guess <strong>${Math.min(me.guesses_used + (me.finished ? 0 : 1), MAX)}</strong>/${MAX}</span>
        `;
    }

    // ── My boards ──────────────────────────────────────────────────────────
    function cell(letter, cls) {
        return `<div class="fw-cell ${cls}">${letter}</div>`;
    }

    function renderBoards() {
        const me = STATE.me;
        const n = STATE.num_boards;
        const len = L();

        if (n === 0) {
            boardsEl.innerHTML = `<p class="fw-empty-note">No puzzle today — check back later.</p>`;
            return;
        }

        boardsEl.style.setProperty("--boards", n);
        const activeRowIndex = me.finished ? -1 : me.guesses_used;
        let html = "";

        const owned = me.owned || [];
        const answers = me.answers || [];

        for (let b = 0; b < n; b++) {
            const isOwned = owned.includes(b);
            const solved = me.solved_boards[b];
            const failed = me.finished && !solved;
            html += `<div class="fw-board ${solved ? "solved" : ""} ${failed ? "failed" : ""}">`;
            const badge = isOwned
                ? '<span class="fw-check">★ your word</span>'
                : (solved ? '<span class="fw-check">✓ solved</span>' : "");
            html += `<div class="fw-board-head"><span>Board ${b + 1}</span>${badge}</div>`;
            html += `<div class="fw-grid" style="--cols:${len}">`;

            if (isOwned) {
                // You chose this word — it's revealed and counts as solved.
                const ans = answers[b] || "";
                for (let r = 0; r < MAX; r++) {
                    for (let i = 0; i < len; i++) {
                        html += (r === 0) ? cell(ans[i] || "", "green") : cell("", "empty");
                    }
                }
            } else {
                // A solved board freezes at its winning row — later guesses
                // (aimed at other boards) shouldn't keep filling it in. The
                // solving guess is the only all-green row for this board.
                let shown = me.guesses.length;
                if (solved) {
                    for (let r = 0; r < me.guesses.length; r++) {
                        if (me.guesses[r].boards[b].every(c => c === "green")) { shown = r + 1; break; }
                    }
                }
                for (let r = 0; r < MAX; r++) {
                    if (r < shown) {
                        const g = me.guesses[r];
                        const colors = g.boards[b];
                        for (let i = 0; i < len; i++) {
                            const ch = g.text[i] === "_" ? "" : g.text[i];
                            html += cell(ch, colors[i]);
                        }
                    } else if (r === activeRowIndex && !solved) {
                        for (let i = 0; i < len; i++) {
                            const ch = buffer[i];
                            if (ch && ch !== " ") html += cell(ch, "active");
                            else html += cell("", buffer.length ? "blank" : "empty");
                        }
                    } else {
                        for (let i = 0; i < len; i++) html += cell("", "empty");
                    }
                }
            }

            html += `</div>`; // grid
            if (!isOwned && failed && answers[b]) {
                html += `<div class="fw-answer">Answer: <strong>${escapeHtml(answers[b])}</strong></div>`;
            }
            html += `</div>`; // board
        }
        boardsEl.innerHTML = html;
    }

    // ── Win/lose banner ──────────────────────────────────────────────────
    function renderBanner() {
        const me = STATE.me;
        if (!me.finished) { bannerEl.style.display = "none"; return; }
        bannerEl.style.display = "";
        if (me.solved) {
            bannerEl.className = "fw-banner win";
            bannerEl.textContent = `🎉 Solved all ${STATE.num_boards} in ${me.guesses_used} guess${me.guesses_used === 1 ? "" : "es"}!`;
        } else {
            bannerEl.className = "fw-banner lose";
            const got = me.solved_boards.filter(Boolean).length;
            bannerEl.textContent = `Out of guesses — you solved ${got}/${STATE.num_boards}. Answers revealed above.`;
        }
    }

    // ── Keyboard ───────────────────────────────────────────────────────────
    const KB_ROWS = ["qwertyuiop", "asdfghjkl", "zxcvbnm"];
    function buildKeyboard() {
        let html = "";
        KB_ROWS.forEach((row, idx) => {
            html += `<div class="fw-krow">`;
            if (idx === 2) html += `<button type="button" class="fw-key wide" data-key="enter">Enter</button>`;
            for (const ch of row) html += `<button type="button" class="fw-key" data-key="${ch}">${ch}</button>`;
            if (idx === 2) html += `<button type="button" class="fw-key wide" data-key="back">⌫</button>`;
            html += `</div>`;
        });
        // A space bar pads blank cells so a shorter word can be placed anywhere.
        html += `<div class="fw-krow"><button type="button" class="fw-key space" data-key="space">space</button></div>`;
        keyboardEl.innerHTML = html;
        keyboardEl.querySelectorAll(".fw-key").forEach(btn => {
            btn.addEventListener("click", () => handleKey(btn.dataset.key));
        });
    }

    function renderControls() {
        const disabled = STATE.me.finished;
        keyboardEl.querySelectorAll(".fw-key").forEach(b => { b.disabled = disabled; });
    }

    // Best colour seen for each letter on a given board (green > orange > grey).
    function kbStatesFor(b) {
        const rank = { grey: 1, orange: 2, green: 3 };
        const st = {};
        for (const g of STATE.me.guesses) {
            const colors = g.boards[b];
            for (let i = 0; i < g.text.length; i++) {
                const ch = g.text[i];
                const c = colors[i];
                if (ch === "_" || c === "empty") continue;
                if (!st[ch] || rank[c] > rank[st[ch]]) st[ch] = c;
            }
        }
        return st;
    }

    // The 1·2·3·4 board switcher + the per-board key tinting.
    function renderKeyboard() {
        const n = STATE.num_boards;
        if (kbBoard >= n) kbBoard = 0;

        // Switcher only earns its place with more than one board.
        if (n <= 1) {
            selectorEl.style.display = "none";
        } else {
            selectorEl.style.display = "flex";
            selectorEl.innerHTML = "";
            for (let b = 0; b < n; b++) {
                const solved = STATE.me.solved_boards[b];
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "fw-key fw-kb-tab" + (b === kbBoard ? " active" : "") + (solved ? " solved" : "");
                btn.textContent = String(b + 1);
                btn.title = "Board " + (b + 1) + (solved ? " (solved)" : "");
                btn.addEventListener("click", () => { kbBoard = b; renderKeyboard(); });
                selectorEl.appendChild(btn);
            }
        }

        const st = n > 0 ? kbStatesFor(kbBoard) : {};
        keyboardEl.querySelectorAll(".fw-key").forEach(key => {
            const k = key.dataset.key;
            key.classList.remove("green", "orange", "grey");
            if (/^[a-z]$/.test(k) && st[k]) key.classList.add(st[k]);
        });
    }

    // ── Streak / average-guesses stats (one slot per player) ──────────────
    function renderStats() {
        const stats = STATE.stats || [];
        statsEl.innerHTML = stats.map(s => {
            const played = s.solves > 0 || s.streak > 0;
            const avg = s.avg_guesses != null ? s.avg_guesses + " avg" : "—";
            return `
                <div class="fw-stat ${played ? "" : "empty"}">
                    <div class="fw-stat-name">${escapeHtml(s.username)}</div>
                    <div class="fw-stat-row">
                        <span class="fw-stat-streak" title="Day streak">🔥 ${s.streak}</span>
                        <span class="fw-stat-avg" title="Average guesses on solved days">${avg}</span>
                    </div>
                </div>
            `;
        }).join("");
    }

    // ── Opponents ────────────────────────────────────────────────────────
    function renderOpponents() {
        const opp = STATE.opponents || [];
        if (opp.length === 0) {
            oppEl.innerHTML = `<p class="fw-empty-note">No one else has played yet today.</p>`;
            return;
        }
        const len = L();
        oppEl.innerHTML = opp.map(o => {
            const solvedCount = o.solved_boards.filter(Boolean).length;
            const statusTxt = o.finished
                ? (o.solved ? `solved in ${o.guesses_used}` : `done · ${solvedCount}/${STATE.num_boards}`)
                : `${o.guesses_used}/${MAX} guesses`;

            const owned = o.owned || [];
            let minis = "";
            for (let b = 0; b < STATE.num_boards; b++) {
                // A board they chose is auto-solved — show a single green row
                // (they never guessed it, so there's no winning row to freeze on).
                if (owned.includes(b)) {
                    minis += `<div class="fw-mini" style="--cols:${len}">`;
                    for (let r = 0; r < MAX; r++) {
                        for (let i = 0; i < len; i++) {
                            minis += `<div class="fw-mcell ${r === 0 ? "green" : ""}"></div>`;
                        }
                    }
                    minis += `</div>`;
                    continue;
                }
                // Freeze a solved board at its winning (all-green) row.
                let shown = o.guesses.length;
                if (o.solved_boards[b]) {
                    for (let r = 0; r < o.guesses.length; r++) {
                        if (o.guesses[r].boards[b].every(c => c === "green")) { shown = r + 1; break; }
                    }
                }
                minis += `<div class="fw-mini" style="--cols:${len}">`;
                for (let r = 0; r < MAX; r++) {
                    for (let i = 0; i < len; i++) {
                        const c = (r < shown) ? o.guesses[r].boards[b][i] : "";
                        const cls = (c === "green" || c === "orange" || c === "grey") ? c : "";
                        minis += `<div class="fw-mcell ${cls}"></div>`;
                    }
                }
                minis += `</div>`;
            }

            return `
                <div class="fw-opp ${o.finished && o.solved ? "done" : ""}">
                    <div class="fw-opp-head">
                        <span class="fw-opp-name">${escapeHtml(o.username)}</span>
                        <span class="fw-opp-stat">${statusTxt}</span>
                    </div>
                    <div class="fw-mini-wrap">${minis}</div>
                </div>
            `;
        }).join("");
    }

    // ── Tomorrow's word picker ─────────────────────────────────────────────
    let changing = false;
    let lastChooseSig = null;
    function renderChoose() {
        const c = STATE.choose;
        const visible = c.eligible && STATE.me.solved;

        // Only rebuild when something actually changed — otherwise a background
        // poll would wipe the custom-word input you're typing in.
        const sig = visible ? `${c.already || ""}|${changing}|${c.tomorrow_length}` : "hidden";
        if (sig === lastChooseSig) return;
        lastChooseSig = sig;

        if (!visible) { chooseEl.style.display = "none"; return; }
        chooseEl.style.display = "";

        const tlen = c.tomorrow_length;
        if (c.already && !changing) {
            chooseEl.innerHTML = `
                <h2>🗳️ Tomorrow's word — locked in</h2>
                <div class="fw-chosen">
                    Your pick: <strong>${escapeHtml(c.already)}</strong>
                    <br><button type="button" class="btn" id="fw-change">Change it</button>
                </div>
                <p class="fw-choose-msg ok" id="fw-choose-msg">Saved. You can change it until tomorrow begins.</p>
            `;
            document.getElementById("fw-change").addEventListener("click", () => { changing = true; renderChoose(); });
            return;
        }

        const sugg = (c.suggestions || []).map(w =>
            `<button type="button" class="btn fw-suggest" data-word="${escapeHtml(w)}">${escapeHtml(w)}</button>`
        ).join("");

        chooseEl.innerHTML = `
            <h2>🎉 You solved it — set a word for tomorrow</h2>
            <p class="muted">Tomorrow is a <strong>${tlen}-letter</strong> day. Pick a suggestion or enter your own (must be a real ${tlen}-letter word).</p>
            <div class="fw-suggests">${sugg}</div>
            <div class="fw-custom-row">
                <input type="text" id="fw-custom" maxlength="${tlen}" placeholder="Your own ${tlen}-letter word">
                <button type="button" class="btn-primary" id="fw-custom-go">Set word</button>
            </div>
            <p class="fw-choose-msg" id="fw-choose-msg"></p>
        `;
        chooseEl.querySelectorAll(".fw-suggest").forEach(btn =>
            btn.addEventListener("click", () => submitChoice(btn.dataset.word)));
        document.getElementById("fw-custom-go").addEventListener("click", () => {
            submitChoice(document.getElementById("fw-custom").value.trim().toLowerCase());
        });
        document.getElementById("fw-custom").addEventListener("keydown", e => {
            if (e.key === "Enter") { e.preventDefault(); submitChoice(e.target.value.trim().toLowerCase()); }
        });
    }

    function submitChoice(word) {
        const tlen = STATE.choose.tomorrow_length;
        const cmsg = document.getElementById("fw-choose-msg");
        if (!/^[a-z]+$/.test(word) || word.length !== tlen) {
            if (cmsg) { cmsg.classList.remove("ok"); cmsg.textContent = `Needs to be a ${tlen}-letter word.`; }
            return;
        }
        const body = new FormData();
        body.append("word", word);
        fetch(BASE_PATH + "/api/fwordle-choose.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Could not save."); }))
            .then(res => {
                STATE.choose.already = res.word;
                changing = false;
                renderChoose();
            })
            .catch(err => { if (cmsg) { cmsg.classList.remove("ok"); cmsg.textContent = err.message; } });
    }

    // ── Input handling ─────────────────────────────────────────────────────
    // Replay the press animation on the matching on-screen key (so physical
    // typing lights it up too). Re-trigger by removing + reflowing the class.
    function flashKey(key) {
        const btn = keyboardEl.querySelector(`.fw-key[data-key="${key}"]`);
        if (!btn) return;
        btn.classList.remove("pressed");
        void btn.offsetWidth;
        btn.classList.add("pressed");
    }

    function handleKey(key) {
        if (STATE.me.finished || busy) return;
        flashKey(key);
        if (key === "enter") return submitGuess();
        if (key === "back")  { buffer = buffer.slice(0, -1); rerenderInput(); return; }
        if (key === "space") {
            // Leading/trailing blanks position a short word; ignore a leading run
            // longer than would leave room, and never start with nothing typed.
            if (buffer.length < L()) { buffer += " "; rerenderInput(); }
            return;
        }
        if (/^[a-z]$/.test(key) && buffer.length < L()) {
            buffer += key;
            rerenderInput();
        }
    }

    // Only the active row + controls change while typing — no need to repaint
    // committed rows or opponents.
    function rerenderInput() { setMsg(""); renderBoards(); renderControls(); }

    function submitGuess() {
        if (busy || STATE.me.finished) return;

        // Pull the contiguous word out of the row and where it sits.
        const first = buffer.search(/[a-z]/);
        if (first === -1) { setMsg("Type a word."); return; }
        const last = buffer.length - 1 - [...buffer].reverse().findIndex(c => /[a-z]/.test(c));
        const word = buffer.slice(first, last + 1);
        if (/\s/.test(word)) { setMsg("No gaps inside the word — spaces only before or after it."); return; }
        if (word.length < 5)  { setMsg("Use at least 5 letters."); return; }

        busy = true;
        const body = new FormData();
        body.append("word", word);
        body.append("offset", first);
        fetch(BASE_PATH + "/api/fwordle-guess.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Guess failed."); }))
            .then(state => {
                STATE = state;
                buffer = "";
                renderAll();
            })
            .catch(err => setMsg(err.message))
            .finally(() => { busy = false; });
    }

    document.addEventListener("keydown", e => {
        const tag = (document.activeElement && document.activeElement.tagName) || "";
        if (tag === "INPUT" || tag === "TEXTAREA") return; // don't hijack the custom-word field
        if (e.key === "Enter")          { e.preventDefault(); handleKey("enter"); }
        else if (e.key === "Backspace") { e.preventDefault(); handleKey("back"); }
        else if (e.key === " ")         { e.preventDefault(); handleKey("space"); }
        else if (/^[a-zA-Z]$/.test(e.key)) { handleKey(e.key.toLowerCase()); }
    });

    // ── Polling for opponents (and cross-tab self updates) ─────────────────
    function loadState() {
        if (busy) return;
        fetch(BASE_PATH + "/api/fwordle-state.php")
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(state => {
                // Don't clobber an in-progress guess: the local buffer is kept.
                STATE = state;
                renderBoards(); renderBanner(); renderStatus();
                renderControls(); renderKeyboard(); renderStats(); renderOpponents(); renderChoose();
            })
            .catch(() => {});
    }

    function renderAll() {
        renderStatus();
        renderBoards();
        renderBanner();
        renderControls();
        renderKeyboard();
        renderStats();
        renderOpponents();
        renderChoose();
    }

    // ── Go ─────────────────────────────────────────────────────────────────
    buildKeyboard();
    renderAll();

    setInterval(loadState, 6000);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) loadState(); });
    window.addEventListener("pageshow", e => { if (e.persisted) loadState(); });
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
