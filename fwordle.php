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

/* Win/lose result rides in the status row as a coloured pill. */
.fw-pill.result { font-weight: 700; }
.fw-pill.result.win  { background: rgba(46, 158, 91, 0.16); border-color: rgba(46,158,91,0.5); color: #7ee2a8; }
.fw-pill.result.lose { background: rgba(248, 113, 113, 0.14); border-color: rgba(248,113,113,0.45); color: #fca5a5; }

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

/* ── Jokers: one shared bar of 3 buttons (doubles as the legend) ─────────── */
.fw-jokers {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

.fw-joker-btn {
    width: auto;
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 13px;
    border-radius: var(--radius-md);
    font-size: 0.82rem;
    text-align: left;
}
.fw-joker-btn .sw {
    width: 15px;
    height: 15px;
    border-radius: 4px;
    flex-shrink: 0;
}
.fw-joker-btn .jk-sub { color: var(--text-3); font-size: 0.72rem; }
.fw-joker-btn.grey   .sw { background: var(--fw-grey); }
.fw-joker-btn.orange .sw { background: var(--fw-orange); }
.fw-joker-btn.green  .sw { background: var(--fw-green); }
.fw-joker-btn.active {
    border-color: var(--violet);
    box-shadow: var(--glow-violet);
}
.fw-joker-btn.used {
    opacity: 0.45;
    cursor: default;
}
.fw-joker-btn[disabled] { cursor: not-allowed; }

.fw-joker-hint { margin: 2px 0 0; min-height: 1em; font-size: 0.82rem; color: var(--violet); }

/* While picking a board for a joker, boards that can take it light up. */
.fw-board.pickable {
    cursor: pointer;
    border-color: rgba(139, 92, 246, 0.7);
    box-shadow: var(--glow-violet);
}
.fw-board.pickable:hover { background: var(--grad-accent-soft); }

/* ── Per-board hint shown as an extra board row beneath each board ───────── */
.fw-hint-rows {
    display: grid;
    grid-template-columns: repeat(var(--boards, 4), minmax(0, 1fr));
    gap: 14px;
    margin: -10px 0 22px;
}
@media (max-width: 600px) {
    .fw-hint-rows { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.fw-hint-rows .fw-board {
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.02);
}
.fw-hint-rows .fw-empty-slot { visibility: hidden; }
.fw-hint-rows .fw-board-head {
    margin-bottom: 6px;
    font-size: 0.66rem;
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
            4 board puzzle a day. Solve every board before the guesses run out. 
            A guess can be any word from 5 letters up to the day's length (use the space bar to place it).
            Beat the day and you get to set one of tomorrow's words.
        </p>
    </div>

    <div class="fw-status" id="fw-status"></div>

    <!-- Shared joker bar (also the legend); click one, then pick a board. -->
    <div class="fw-jokers" id="fw-jokers"></div>
    <p class="fw-joker-hint" id="fw-joker-hint"></p>

    <div class="fw-boards" id="fw-boards"></div>

    <!-- Per-board hint, shown as an extra board row under each board. -->
    <div class="fw-hint-rows" id="fw-hint-rows"></div>

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
    let kbBoard = 0;       // which board's letter colours the keyboard shows
    let pendingHint = null; // a joker type awaiting a board pick

    const L = () => STATE.length;

    // ── DOM refs ───────────────────────────────────────────────────────────
    const statusEl  = document.getElementById("fw-status");
    const boardsEl   = document.getElementById("fw-boards");
    const jokersEl   = document.getElementById("fw-jokers");
    const jokerHintEl = document.getElementById("fw-joker-hint");
    const hintRowsEl = document.getElementById("fw-hint-rows");
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
        // The win/lose result rides in the same row (a coloured pill) so it
        // doesn't take its own line.
        let result = "";
        if (me.finished) {
            if (me.solved) {
                result = `<span class="fw-pill result win">🎉 Solved all ${STATE.num_boards} in ${me.guesses_used}!</span>`;
            } else {
                const got = me.solved_boards.filter(Boolean).length;
                result = `<span class="fw-pill result lose">Out of guesses — ${got}/${STATE.num_boards} solved</span>`;
            }
        }
        statusEl.innerHTML = `
            <span class="fw-pill">📅 <strong>${escapeHtml(STATE.date)}</strong></span>
            <span class="fw-pill"><strong>${L()}</strong>-letter words</span>
            <span class="fw-pill"><strong>${STATE.num_boards}</strong> board${STATE.num_boards === 1 ? "" : "s"}</span>
            <span class="fw-pill">Guess <strong>${Math.min(me.guesses_used + (me.finished ? 0 : 1), MAX)}</strong>/${MAX}</span>
            ${result}
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
        const pickSet = pendingHint ? new Set(eligibleBoards()) : null;

        for (let b = 0; b < n; b++) {
            const isOwned = owned.includes(b);
            const solved = me.solved_boards[b];
            const failed = me.finished && !solved;
            const pickable = pickSet && pickSet.has(b);
            html += `<div class="fw-board ${solved ? "solved" : ""} ${failed ? "failed" : ""} ${pickable ? "pickable" : ""}" data-board="${b}">`;
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

        // When a joker is pending, clicking an eligible board applies it.
        if (pendingHint) {
            boardsEl.querySelectorAll(".fw-board.pickable").forEach(el =>
                el.addEventListener("click", () => applyHint(pendingHint, parseInt(el.dataset.board, 10))));
        }
    }

    // ── Jokers (hints): one shared bar of 3 buttons, doubling as the legend.
    //    Click a joker, then pick a board to apply it to. ────────────────────
    const HINT_TYPES = [
        { type: "grey",   text: "Grey joker (5x letters)" },
        { type: "orange", text: "Orange joker (1x letter)" },
        { type: "green",  text: "Green joker (1x letter)" },
    ];

    // Boards a joker can still target: unsolved, not yours, no joker yet.
    function eligibleBoards() {
        const me = STATE.me;
        if (me.finished) return [];
        const usedBoards = me.hint_boards_used || [];
        const owned = me.owned || [];
        const out = [];
        for (let b = 0; b < STATE.num_boards; b++) {
            if (!me.solved_boards[b] && !owned.includes(b) && !usedBoards.includes(b)) out.push(b);
        }
        return out;
    }

    function renderJokers() {
        const me = STATE.me;
        const typesUsed = me.hint_types_used || [];
        const canPick = eligibleBoards().length > 0;

        // Drop a stale pending pick if it's no longer usable.
        if (pendingHint && (me.finished || !canPick || typesUsed.includes(pendingHint))) pendingHint = null;

        jokersEl.innerHTML = HINT_TYPES.map(t => {
            const used = typesUsed.includes(t.type);
            const active = pendingHint === t.type;
            const dis = (used || me.finished || !canPick) ? " disabled" : "";
            return `<button type="button" class="fw-joker-btn ${t.type}${used ? " used" : ""}${active ? " active" : ""}" data-type="${t.type}"${dis}>
                <span class="sw"></span><span>${used ? "✓ " : ""}${t.text}</span>
            </button>`;
        }).join("");

        jokersEl.querySelectorAll(".fw-joker-btn").forEach(btn => {
            if (!btn.disabled) btn.addEventListener("click", () => selectHint(btn.dataset.type));
        });

        jokerHintEl.textContent = pendingHint
            ? `Pick a board for the ${pendingHint} joker (click the joker again to cancel).`
            : "";
    }

    function selectHint(type) {
        if (busy || STATE.me.finished) return;
        pendingHint = (pendingHint === type) ? null : type; // toggle
        renderJokers();
        renderBoards(); // refresh the pickable highlight
    }

    // The used hint shown as an extra board row beneath the board.
    function hintRowHtml(h, len) {
        const cells = new Array(len).fill(null);
        if (h.type === "green")       cells[h.pos] = { ch: h.letter, cls: "green" };
        else if (h.type === "orange") cells[h.pos != null ? h.pos : 0] = { ch: h.letter, cls: "orange" };
        else if (h.type === "grey")   h.letters.forEach((c, i) => { if (i < len) cells[i] = { ch: c, cls: "grey" }; });
        let html = `<div class="fw-grid" style="--cols:${len}">`;
        for (let i = 0; i < len; i++) {
            const c = cells[i];
            html += c ? cell(c.ch.toUpperCase(), c.cls) : cell("", "empty");
        }
        return html + `</div>`;
    }

    function renderHintRows() {
        const me = STATE.me;
        const n = STATE.num_boards;
        if (n === 0 || !(me.hints || []).length) { hintRowsEl.innerHTML = ""; return; }
        hintRowsEl.style.setProperty("--boards", n);

        const byBoard = {};
        (me.hints || []).forEach(h => { byBoard[h.board] = h; });
        const len = L();

        let html = "";
        for (let b = 0; b < n; b++) {
            const h = byBoard[b];
            if (!h) { html += `<div class="fw-board fw-empty-slot"></div>`; continue; }
            const label = h.type === "grey" ? "not in word" : h.type === "orange" ? "in word" : "spot " + (h.pos + 1);
            html += `<div class="fw-board">
                <div class="fw-board-head"><span>Board ${b + 1} hint</span><span>${label}</span></div>
                ${hintRowHtml(h, len)}
            </div>`;
        }
        hintRowsEl.innerHTML = html;
    }

    function applyHint(type, board) {
        if (busy || STATE.me.finished) return;
        pendingHint = null;
        busy = true;
        setMsg("");
        const body = new FormData();
        body.append("type", type);
        body.append("board", board);
        fetch(BASE_PATH + "/api/fwordle-hint.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Hint failed."); }))
            .then(state => { STATE = state; renderAll(); })
            .catch(err => setMsg(err.message))
            .finally(() => { busy = false; });
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

    // Best colour seen for each letter on a given board (green > orange > grey),
    // merging both the player's guesses and any hint used on that board.
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
        (STATE.me.hints || []).filter(h => h.board === b).forEach(h => {
            if (h.type === "grey")        h.letters.forEach(c => bump(c, "grey"));
            else if (h.type === "orange") bump(h.letter, "orange");
            else if (h.type === "green")  bump(h.letter, "green");
        });
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
            const jokerTxt = o.jokers_used > 0 ? ` · 🃏${o.jokers_used}` : "";
            const statusTxt = (o.finished
                ? (o.solved ? `solved in ${o.guesses_used}` : `done · ${solvedCount}/${STATE.num_boards}`)
                : `${o.guesses_used}/${MAX} guesses`) + jokerTxt;

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
                renderStatus(); renderJokers(); renderBoards(); renderHintRows();
                renderControls(); renderKeyboard(); renderStats(); renderOpponents(); renderChoose();
            })
            .catch(() => {});
    }

    function renderAll() {
        renderStatus();
        renderJokers();
        renderBoards();
        renderHintRows();
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
