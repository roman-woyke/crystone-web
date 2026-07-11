<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/boardle.php";

// Server-render the full state so the grid paints complete on first byte.
$today = date("Y-m-d");
$initialState = boardleState($pdo, $today, (int) $_SESSION["user_id"]);
$initialState["today"]         = $today;
$initialState["earliest_date"] = boardleEarliestDate($pdo);

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

/* ── Day nav: prev/next arrows + calendar popup to jump to an old puzzle ──── */
.fw-day-nav {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}
.fw-day-arrow {
    width: 34px;
    height: 34px;
    padding: 0;
    margin: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-full);
    font-size: 1rem;
    flex-shrink: 0;
}
.fw-day-label {
    width: auto;
    margin: 0;
    padding: 6px 14px;
    border-radius: var(--radius-full);
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
}
.fw-day-today-btn {
    width: auto;
    margin: 0;
    padding: 6px 12px;
    font-size: 0.8rem;
}
.fw-day-arrow[disabled] { opacity: 0.35; cursor: not-allowed; }

/* Calendar popup */
.fw-cal-dialog { width: min(340px, calc(100vw - 32px)); }
.fw-cal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.fw-cal-head h2 { margin: 0; font-size: 1rem; }
.fw-cal-nav-btn { width: 32px; height: 32px; padding: 0; margin: 0; border-radius: var(--radius-full); }
.fw-cal-nav-btn[disabled] { opacity: 0.35; cursor: not-allowed; }
.fw-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}
.fw-cal-dow {
    text-align: center;
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--text-3);
    text-transform: uppercase;
    padding-bottom: 4px;
}
.fw-cal-day {
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-md);
    font-size: 0.82rem;
    font-weight: 600;
    background: transparent;
    color: var(--text-1);
    cursor: pointer;
    position: relative;
}
.fw-cal-day:hover:not(.disabled) { background: rgba(255, 255, 255, 0.08); }
.fw-cal-day.disabled { color: var(--text-3); opacity: 0.35; cursor: default; }
.fw-cal-day.empty { visibility: hidden; cursor: default; }
.fw-cal-day.solved { background: rgba(46, 158, 91, 0.18); box-shadow: inset 0 0 0 1px rgba(46,158,91,0.5); }
.fw-cal-day.missed { background: rgba(248, 113, 113, 0.12); box-shadow: inset 0 0 0 1px rgba(248,113,113,0.35); }
.fw-cal-day.viewing { box-shadow: inset 0 0 0 2px var(--violet); }
.fw-cal-day.is-today::after {
    content: "";
    position: absolute;
    bottom: 4px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--violet);
}
.fw-cal-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
    font-size: 0.72rem;
    color: var(--text-3);
}
.fw-cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
.fw-cal-legend .sw { width: 10px; height: 10px; border-radius: 3px; }

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
.fw-joker-btn .sw.emoji { background: none; font-size: 15px; line-height: 1; }
/* Per-joker streak cost badge inside each button. */
.fw-joker-btn .jk-cost {
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
    color: var(--danger);
    background: rgba(255, 255, 255, 0.06);
}
.fw-joker-btn .jk-cost.free {
    color: #fff;
    font-weight: 700;
    background: var(--fw-green);
}
/* Freeze-paid joker cost badge (blue, like the freeze wallet). */
.fw-joker-btn .jk-cost.freeze {
    color: var(--info, #38bdf8);
    background: rgba(56, 189, 248, 0.14);
}
/* Spendable-streak "wallet", sat right next to the jokers and marked red. */
.fw-streak-wallet {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--danger);
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid var(--danger);
}
/* Freeze wallet — same shape as the streak wallet, blue. */
.fw-freeze-wallet {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    border-radius: var(--radius-md);
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--info, #38bdf8);
    background: rgba(56, 189, 248, 0.1);
    border: 1px solid var(--info, #38bdf8);
}
/* The wallet chips double as the payment-mode switch: clickable when both
   streak and freeze are options, with the active mode filled in. */
.fw-streak-wallet.selectable,
.fw-freeze-wallet.selectable { cursor: pointer; transition: background var(--t-fast); }
.fw-streak-wallet.selectable:hover { background: rgba(239, 68, 68, 0.2); }
.fw-freeze-wallet.selectable:hover { background: rgba(56, 189, 248, 0.2); }
.fw-streak-wallet.selectable.active { color: #2a0606; background: var(--danger); border-color: transparent; }
.fw-freeze-wallet.selectable.active { color: #04222f; background: var(--info, #38bdf8); border-color: transparent; }
.fw-joker-btn.active {
    border-color: var(--violet);
    box-shadow: var(--glow-violet);
}
.fw-joker-btn.used {
    opacity: 0.45;
    cursor: default;
}
.fw-joker-btn[disabled] { cursor: not-allowed; }

.fw-joker-hint { margin: 2px 0 7px; min-height: 1em; font-size: 0.82rem; color: var(--violet); }

/* While picking a board for a joker, boards that can take it light up. */
.fw-board.pickable {
    cursor: pointer;
    border-color: rgba(139, 92, 246, 0.7);
    box-shadow: var(--glow-violet);
}
.fw-board.pickable:hover { background: var(--grad-accent-soft); }

/* ── Per-board hint shown as an extra board row beneath each board ───────── */
.fw-hint-rows:empty { margin: 0; }
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

/* The bonus guess row unlocked by the 🛡️ armor joker — tinted so it's clearly
   an added row. The tint rides on top of any colour the cell already has. */
.fw-cell.bonus {
    border: 2px solid var(--sky, #38bdf8);
    box-shadow: inset 0 0 0 2px rgba(56, 189, 248, 0.25);
}
.fw-cell.bonus.empty { border-style: dashed; }

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

/* "Add a hint" CTA that fills a .fw-board-hint card in place of clue text —
   same card, same footprint, for a board you've solved that has no community
   clue yet. The two states never coexist for a given board. The whole card is
   the click/hover target, not just the CTA text. */
.fw-board-hint-cta {
    cursor: pointer;
    transition: background var(--t-fast), border-color var(--t-fast);
}
.fw-board-hint-cta:hover {
    background: var(--grad-accent-soft);
    border-color: rgba(139, 92, 246, 0.4);
}
.fw-add-hint {
    font-weight: 600;
    color: var(--text-2);
}

:root {
    --fw-green:  #2e9e5b;
    --fw-orange: #c2992f;
    --fw-grey:   #4b4b55;
    --fw-key-grey: #222224;
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
.fw-key.fw-kb-tab {
    background: var(--glass-card);
    border-color: var(--glass-border);
    color: var(--text-1);
}
.fw-key.fw-kb-tab:hover { background: rgba(255, 255, 255, 0.12); border-color: var(--text-3); }
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
    border: 1px solid rgba(0, 0, 0, 0.15);
    border-radius: 7px;
    background: #818384;
    color: #fff;
    font-family: var(--font-display);
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: uppercase;
    cursor: pointer;
    transition: background var(--t-fast), border-color var(--t-fast), transform var(--t-fast);
}

.fw-key:hover { background: #6f7071; border-color: rgba(0, 0, 0, 0.25); }
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
.fw-key.green  { background: var(--fw-green); border-color: var(--fw-green); color: #fff; }
.fw-key.orange { background: var(--fw-orange); border-color: var(--fw-orange); color: #fff; }
.fw-key.grey   { background: var(--fw-key-grey); border-color: var(--fw-key-grey); color: #fff; opacity: 0.7; transform: scale(0.92); }

/* ── Word-picker hints shown under the boards ───────────────────────────── */
.fw-board-hints:empty { margin: 0; }
.fw-board-hints {
    display: grid;
    grid-template-columns: repeat(var(--boards, 4), minmax(0, 1fr));
    gap: 14px;
    margin: 0 0 22px;
}
@media (max-width: 600px) {
    .fw-board-hints { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.fw-board-hint {
    padding: 10px 14px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.02);
    font-size: 0.84rem;
    color: var(--text-2);
    line-height: 1.55;
}
.fw-board-hint-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 6px;
}
/* Clamp long hints (e.g. ASCII art) to 3 lines; overflow opens the full-hint modal. */
.fw-board-hint-text {
    word-break: break-word;
    white-space: pre-wrap;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.fw-board-hint-more {
    display: block;
    margin-top: 6px;
    padding: 0;
    background: none;
    border: none;
    font: inherit;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--violet);
    cursor: pointer;
    text-decoration: underline;
}
.fw-board-hint-more:hover { opacity: 0.85; }

/* ── Full-hint modal — big, monospace, unwrapped so ASCII art stays intact. ── */
.fw-full-hint-dialog {
    width: min(94vw, 900px);
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}
.fw-full-hint-dialog h2 { margin: 0 0 12px; font-size: 1.05rem; }
.fw-full-hint-body {
    flex: 1;
    overflow: auto;
    padding: 14px 16px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    background: rgba(0, 0, 0, 0.25);
    font-family: ui-monospace, "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 0.86rem;
    line-height: 1.4;
    white-space: pre;
    color: var(--text-1);
}

/* ── Tomorrow's word picker ─────────────────────────────────────────────── */
.fw-choose { padding: 20px; }
.fw-choose h2 { margin: 0 0 4px; font-size: 1.1rem; }
.fw-choose p.muted { margin: 0 0 14px; font-size: 0.86rem; }

/* Same #fw-choose card, just re-parented into the centered popup on solve —
   this class only adds the sizing/entrance animation a modal dialog needs. */
#fw-choose.fw-choose-modal {
    width: min(520px, calc(100vw - 32px));
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    animation: modal-in var(--t-med) var(--ease-out);
}
.fw-choose-modal-close {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1;
    width: 36px;
    height: 36px;
    margin: 0;
    padding: 0;
    border-radius: 50%;
    font-size: 0.95rem;
    line-height: 1;
    color: var(--text-2);
}
.fw-choose-modal-close:hover { color: var(--text-1); }

.fw-suggests {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 14px;
}

/* Fixed width (big enough for the max 10-letter word) so swapping in new
   suggestions — or hitting Randomize — never reflows the row. */
.fw-suggest {
    width: 128px;
    flex: 0 0 128px;
    margin: 0;
    padding: 9px 10px;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: var(--font-display);
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

/* Reroll the three suggestions — a small icon-only die inline with them, sized
   to match the suggestion buttons' height without stealing their width. */
.fw-randomize {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    margin: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    line-height: 1;
    white-space: nowrap;
    align-self: center;
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
    height: 46px;
    margin: 0;
    box-sizing: border-box;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-family: var(--font-display);
}
.fw-custom-row button {
    width: auto;
    height: 46px;
    margin: 0;
    padding: 0 20px;
    box-sizing: border-box;
}

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

.fw-spoiler-wrap {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}
.fw-spoiler {
    filter: blur(6px);
    user-select: none;
    transition: filter 0.2s;
}
.fw-spoiler.revealed {
    filter: none;
}
.fw-reveal-btn {
    width: auto;
    margin: 0;
    padding: 3px 10px;
    font-size: 0.78rem;
    opacity: 0.75;
}

.fw-choose-msg { margin: 10px 0 0; min-height: 1.1em; font-size: 0.85rem; color: var(--danger); }
.fw-choose-msg.ok { color: var(--success); }

.fw-hint-wrap { margin: 14px 0 0; }
.fw-hint-wrap label { display: block; margin-bottom: 6px; font-size: 0.86rem; color: var(--text-2); }
.fw-hint-wrap textarea {
    width: 100%;
    min-height: 80px;
    resize: vertical;
    box-sizing: border-box;
    font-size: 0.9rem;
    line-height: 1.5;
}
.fw-chosen-hint {
    margin: 8px 0 0;
    font-size: 0.86rem;
    color: var(--text-2);
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
}
.fw-chosen-hint-label { font-weight: 700; color: var(--text-3); }

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

/* Standings — one compact container, one line per player. */
.fw-stats {
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    background: var(--solid-glass);
    overflow: hidden;
}

.fw-stat {
    display: flex;
    align-items: baseline;
    gap: 8px;
    padding: 7px 11px;
    font-size: 0.8rem;
    color: var(--text-2);
    font-variant-numeric: tabular-nums;
}
.fw-stat + .fw-stat { border-top: 1px solid var(--glass-border); }
.fw-stat.empty { opacity: 0.5; }

/* Podium tint for the top 3 rows (list is already sorted by rank). */
.fw-stat.rank-1 { border-left: 3px solid #f59e0b; background: linear-gradient(90deg, rgba(251, 191, 36, 0.14), transparent 65%); }
.fw-stat.rank-2 { border-left: 3px solid #9ca3af; background: linear-gradient(90deg, rgba(209, 213, 219, 0.14), transparent 65%); }
.fw-stat.rank-3 { border-left: 3px solid #b45309; background: linear-gradient(90deg, rgba(217, 119, 6, 0.14), transparent 65%); }
.fw-stat.rank-1 .fw-stat-name { color: #fbbf24; }
.fw-stat.rank-2 .fw-stat-name { color: #d1d5db; }
.fw-stat.rank-3 .fw-stat-name { color: #d97706; }

.fw-stat-name {
    flex: 1;
    min-width: 0;
    font-weight: 700;
    font-size: 0.84rem;
    color: var(--text-1);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fw-stat-streak { font-weight: 700; color: var(--text-1); }
.fw-stat-freeze { font-weight: 700; color: var(--info, #38bdf8); }
.fw-stat-avg { color: var(--text-3); white-space: nowrap; }

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

    <div class="fw-day-nav" id="fw-day-nav">
        <button type="button" class="btn fw-day-arrow" id="fw-day-prev" title="Previous day" aria-label="Previous day">‹</button>
        <button type="button" class="btn fw-day-label" id="fw-day-label" title="Pick a day"></button>
        <button type="button" class="btn fw-day-arrow" id="fw-day-next" title="Next day" aria-label="Next day">›</button>
        <button type="button" class="btn fw-day-today-btn" id="fw-day-today" style="display:none;">Back to today</button>
        <a class="btn btn-primary" href="<?= BASE_PATH ?>/boardle-rt.php">Unlimited real-time mode</a>
    </div>

    <div class="fw-status" id="fw-status"></div>

    <!-- Shared joker bar (also the legend); click one, then pick a board. -->
    <div class="fw-jokers" id="fw-jokers"></div>
    <p class="fw-joker-hint" id="fw-joker-hint"></p>

    <div class="fw-boards" id="fw-boards"></div>

    <!-- Word-picker hints: shown from the start, one card per board. -->
    <div class="fw-board-hints" id="fw-board-hints"></div>

    <!-- Per-board hint, shown as an extra board row under each board. -->
    <div class="fw-hint-rows" id="fw-hint-rows"></div>

    <div class="fw-controls" id="fw-controls">
        <div class="fw-kb-wrap">
            <div class="fw-kb-selector" id="fw-kb-selector"></div>
            <div class="fw-keyboard" id="fw-keyboard"></div>
        </div>
        <p class="fw-msg" id="fw-msg"></p>
    </div>

</div><!-- /fw-main -->

<!-- ── "You solved it!" popup — #fw-choose is re-parented in here so the exact
     same picker (suggestions, custom word, hint, randomize) pops up the moment
     the last board is solved, without needing to scroll to the bottom. ────── -->
<div id="fw-choose-modal" class="modal-overlay" style="display:none;">
    <button type="button" class="fw-choose-modal-close" id="fw-choose-modal-close" title="Choose later">✕</button>
</div>

<!-- ── Add-a-hint modal (styled like the tomorrow's-word picker) ─────────────── -->
<div id="fw-hint-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card fw-choose">
        <h2>Add a hint for others</h2>
        <p class="muted">Give the next players a clue without spoiling the word.</p>
        <div class="fw-hint-wrap">
            <textarea id="fw-hint-input" placeholder="e.g. Think kitchen appliances..."></textarea>
        </div>
        <p class="fw-choose-msg" id="fw-hint-modal-msg"></p>
        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="fw-hint-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="fw-hint-save">Save hint</button>
        </div>
    </div>
</div>

<!-- ── Full-hint modal — opened from a clamped hint card that overflows 3 lines. ── -->
<div id="fw-full-hint-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid fw-full-hint-dialog">
        <h2 id="fw-full-hint-title">Board clue</h2>
        <div class="fw-full-hint-body" id="fw-full-hint-body"></div>
        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="fw-full-hint-close">Close</button>
        </div>
    </div>
</div>

<!-- ── Calendar popup: jump to any past puzzle day ───────────────────────────── -->
<div id="fw-cal-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid fw-cal-dialog">
        <div class="fw-cal-head">
            <button type="button" class="btn fw-cal-nav-btn" id="fw-cal-prev-month" aria-label="Previous month">‹</button>
            <h2 id="fw-cal-month-label"></h2>
            <button type="button" class="btn fw-cal-nav-btn" id="fw-cal-next-month" aria-label="Next month">›</button>
        </div>
        <div class="fw-cal-grid" id="fw-cal-grid"></div>
        <div class="fw-cal-legend">
            <span><span class="sw" style="background:rgba(46,158,91,0.6)"></span> Solved</span>
            <span><span class="sw" style="background:rgba(248,113,113,0.5)"></span> Missed</span>
        </div>
    </div>
</div>

<aside class="fw-sidebar">
    <h2>🔥 Standings</h2>
    <div class="fw-stats" id="fw-stats"></div>

    <h2>⚔️ Competition</h2>
    <div id="fw-opponents"></div>

    <div class="fw-choose glass-card" id="fw-choose" style="display:none;"></div>
    <!-- Anchor marking #fw-choose's resting spot — it's re-parented into the
         popup on solve and moved back here when dismissed/submitted. -->
    <div id="fw-choose-anchor"></div>
</aside>

</div><!-- /fw-layout -->

<script>
(function () {
    const BASE_PATH = "<?= BASE_PATH ?>";
    let STATE = <?= json_encode($initialState) ?>;

    // The day currently shown. Defaults to today; the day-nav arrows/calendar
    // move it within [STATE.earliest_date, STATE.today] to replay a missed day
    // or look at an old result. Every state-changing request (guess/joker/hint)
    // is scoped to this date, not necessarily the server's real "today".
    let viewedDate = STATE.date;

    // My guess limit is personal: the base day limit plus the armor joker (+1).
    // It can change mid-game (when armor is bought), so always read it live.
    const myMax = () => STATE.me.max_guesses;

    // ── Local input state (never lives on the server until submitted) ──────
    // `buffer` is the row as typed: letters plus spaces (a space = a blank cell
    // used to push a shorter word into position). Capped at the day's length.
    let buffer = "";
    let busy = false;
    let kbBoard = 0;       // which board's letter colours the keyboard shows
    let pendingHint = null; // a joker type awaiting a board pick
    let useFreeze = false;  // pay the next joker with a freeze instead of streak

    const L = () => STATE.length;

    // ── DOM refs ───────────────────────────────────────────────────────────
    const statusEl    = document.getElementById("fw-status");
    const boardsEl    = document.getElementById("fw-boards");
    const boardHintsEl = document.getElementById("fw-board-hints");
    const jokersEl    = document.getElementById("fw-jokers");
    const jokerHintEl = document.getElementById("fw-joker-hint");
    const hintRowsEl  = document.getElementById("fw-hint-rows");
    const keyboardEl  = document.getElementById("fw-keyboard");
    const selectorEl  = document.getElementById("fw-kb-selector");
    const msgEl       = document.getElementById("fw-msg");
    const chooseEl    = document.getElementById("fw-choose");
    const oppEl       = document.getElementById("fw-opponents");
    const statsEl     = document.getElementById("fw-stats");
    const hintModalEl = document.getElementById("fw-hint-modal");
    const hintInputEl = document.getElementById("fw-hint-input");
    const hintModalMsgEl = document.getElementById("fw-hint-modal-msg");
    const chooseAnchorEl = document.getElementById("fw-choose-anchor");
    const chooseModalEl  = document.getElementById("fw-choose-modal");
    const chooseModalCloseEl = document.getElementById("fw-choose-modal-close");
    const dayPrevEl   = document.getElementById("fw-day-prev");
    const dayNextEl   = document.getElementById("fw-day-next");
    const dayLabelEl  = document.getElementById("fw-day-label");
    const dayTodayEl  = document.getElementById("fw-day-today");
    const calModalEl  = document.getElementById("fw-cal-modal");
    const calGridEl   = document.getElementById("fw-cal-grid");
    const calMonthLabelEl = document.getElementById("fw-cal-month-label");
    const calPrevMonthEl  = document.getElementById("fw-cal-prev-month");
    const calNextMonthEl  = document.getElementById("fw-cal-next-month");

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
            <span class="fw-pill">Guess <strong>${Math.min(me.guesses_used + (me.finished ? 0 : 1), myMax())}</strong>/${myMax()}${me.armor ? " 🛡️" : ""}</span>
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
        const max = myMax();
        const activeRowIndex = me.finished ? -1 : me.guesses_used;
        // The armor joker adds exactly one row — the last one — which we tint.
        const bonusRow = me.armor ? max - 1 : -1;
        const bcls = (base, r) => base + (r === bonusRow ? " bonus" : "");
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
                for (let r = 0; r < max; r++) {
                    for (let i = 0; i < len; i++) {
                        html += (r === 0) ? cell(ans[i] || "", "green") : cell("", bcls("empty", r));
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
                for (let r = 0; r < max; r++) {
                    if (r < shown) {
                        const g = me.guesses[r];
                        const colors = g.boards[b];
                        for (let i = 0; i < len; i++) {
                            const ch = g.text[i] === "_" ? "" : g.text[i];
                            html += cell(ch, bcls(colors[i], r));
                        }
                    } else if (r === activeRowIndex && !solved) {
                        for (let i = 0; i < len; i++) {
                            const ch = buffer[i];
                            if (ch && ch !== " ") html += cell(ch, bcls("active", r));
                            else html += cell("", bcls(buffer.length ? "blank" : "empty", r));
                        }
                    } else {
                        for (let i = 0; i < len; i++) html += cell("", bcls("empty", r));
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

    // ── Word-picker hints: one text card per board, shown from the start. ────
    function renderBoardHints() {
        const n = STATE.num_boards;
        const hints = STATE.board_hints || [];
        const me = STATE.me;
        const owned = me.owned || [];

        // A hintless board slot still earns a place here if I can add a hint to
        // it (solved it, or own it) — offer the "+ Add a hint" CTA in its place.
        const anyContent = n > 0 && Array.from({ length: n }, (_, b) => {
            const hint = (hints[b] || "").trim();
            return hint || (me.solved_boards[b] || owned.includes(b));
        }).some(Boolean);
        if (!anyContent) { boardHintsEl.innerHTML = ""; return; }

        boardHintsEl.style.setProperty("--boards", n);
        let html = "";
        for (let b = 0; b < n; b++) {
            const hint = (hints[b] || "").trim();
            if (hint) {
                html += `<div class="fw-board-hint" style="grid-column:${b + 1}"><span class="fw-board-hint-label">Board ${b + 1} clue</span><div class="fw-board-hint-text" data-hint-board="${b}">${escapeHtml(hint)}</div></div>`;
            } else if (me.solved_boards[b] || owned.includes(b)) {
                // Same card, same footprint — the hover/click affordance lives on
                // the whole card, not just the CTA text. The two states never
                // coexist for a given board.
                html += `<div class="fw-board-hint fw-board-hint-cta" style="grid-column:${b + 1}" data-add-hint="${b}"><span class="fw-board-hint-label">Board ${b + 1} clue</span><span class="fw-add-hint">+ Add a hint for others</span></div>`;
            }
        }
        boardHintsEl.innerHTML = html;

        boardHintsEl.querySelectorAll("[data-add-hint]").forEach(el =>
            el.addEventListener("click", () => openHintModal(parseInt(el.dataset.addHint, 10))));

        // A hint clamped to 3 lines that actually overflows gets a "click for
        // full hint" button appended below it, opening the big unwrapped modal.
        boardHintsEl.querySelectorAll(".fw-board-hint-text").forEach(el => {
            if (el.scrollHeight > el.clientHeight + 1) {
                const b = parseInt(el.dataset.hintBoard, 10);
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "fw-board-hint-more";
                btn.textContent = "Click for full hint";
                btn.addEventListener("click", () => openFullHintModal(b, hints[b] || ""));
                el.insertAdjacentElement("afterend", btn);
            }
        });
    }

    // ── Full-hint modal: shows the untruncated hint, monospace + unwrapped so
    //    ASCII art someone pastes in stays intact. ────────────────────────────
    const fullHintModalEl = document.getElementById("fw-full-hint-modal");
    const fullHintTitleEl = document.getElementById("fw-full-hint-title");
    const fullHintBodyEl  = document.getElementById("fw-full-hint-body");
    function openFullHintModal(board, hint) {
        fullHintTitleEl.textContent = `Board ${board + 1} clue`;
        fullHintBodyEl.textContent = hint;
        fullHintModalEl.style.display = "flex";
    }
    function closeFullHintModal() { fullHintModalEl.style.display = "none"; }
    document.getElementById("fw-full-hint-close").addEventListener("click", closeFullHintModal);
    fullHintModalEl.addEventListener("click", e => { if (e.target === fullHintModalEl) closeFullHintModal(); });

    // ── Jokers: one shared bar of 3 buttons, doubling as the legend. Each costs
    //    1 streak. Armor applies instantly (no board); orange/green need a board
    //    pick. ─────────────────────────────────────────────────────────────────
    const HINT_TYPES = [
        { type: "armor",  board: false, swatch: '<span class="sw emoji">🛡️</span>', text: "Armor (+1 guess)" },
        { type: "orange", board: true,  swatch: '<span class="sw emoji">🔍</span>', text: "Reveal (+2 🟨)" },
        { type: "green",  board: true,  swatch: '<span class="sw emoji">🧩</span>', text: "Place (+1 🟩)" },
    ];

    // Boards a board-joker can target: unsolved and not yours. (Different joker
    // types may stack on the same board; each type is still once-per-day.)
    function eligibleBoards() {
        const me = STATE.me;
        if (me.finished) return [];
        const owned = me.owned || [];
        const out = [];
        for (let b = 0; b < STATE.num_boards; b++) {
            if (!me.solved_boards[b] && !owned.includes(b)) out.push(b);
        }
        return out;
    }

    function renderJokers() {
        const me = STATE.me;
        const typesUsed = me.hint_types_used || [];
        const canPick = eligibleBoards().length > 0;
        // You can spend if you have streak, your one free joker (0 streak), or a
        // freeze to exchange.
        const canSpendStreak = (me.streak || 0) >= 1 || me.free_joker;
        const canFreeze = !!me.can_freeze_joker;
        const canSpend = canSpendStreak || canFreeze;
        const broke = !canSpend;

        // Will the next joker be freeze-paid? (toggled on, or forced when there's
        // no streak left.)
        const payFreezeNow = canFreeze && (useFreeze || !canSpendStreak);

        // Drop a stale pending pick if it's no longer usable.
        if (pendingHint) {
            const t = HINT_TYPES.find(x => x.type === pendingHint);
            if (me.finished || broke || typesUsed.includes(pendingHint) || (t && t.board && !canPick)) pendingHint = null;
        }

        const costHtml = payFreezeNow
            ? '<span class="jk-cost freeze">🧊&nbsp;1</span>'
            : me.free_joker
                ? '<span class="jk-cost free">free</span>'
                : '<span class="jk-cost">🔥&nbsp;1</span>';

        const buttons = HINT_TYPES.map(t => {
            const used = typesUsed.includes(t.type);
            const active = pendingHint === t.type;
            // Board jokers also need an eligible board; armor doesn't.
            const dis = (used || me.finished || broke || (t.board && !canPick)) ? " disabled" : "";
            return `<button type="button" class="fw-joker-btn ${t.type}${used ? " used" : ""}${active ? " active" : ""}" data-type="${t.type}"${dis}>
                ${t.swatch}<span class="jk-label">${used ? "✓ " : ""}${t.text}</span>${used ? "" : costHtml}
            </button>`;
        }).join("");

        // Spendable streak (red) + freeze balance (blue) shown as currency — and
        // they double as the payment-mode switch: click one to pay your next
        // joker with it. The active one is highlighted.
        const streakSelectable = canSpendStreak && canFreeze; // switching only matters when both are options
        const freezeSelectable = canFreeze;
        const wallet =
            `<span class="fw-streak-wallet${!payFreezeNow ? " active" : ""}${streakSelectable ? " selectable" : ""}" data-pay="streak"
                   title="${streakSelectable ? "Click to pay jokers with streak" : "Streak you can spend on jokers"}">🔥 ${me.streak || 0}${me.free_joker ? " · 1 free" : ""}</span>`;
        const freezeWallet =
            `<span class="fw-freeze-wallet${payFreezeNow ? " active" : ""}${freezeSelectable ? " selectable" : ""}" data-pay="freeze"
                   title="${freezeSelectable ? "Click to pay your next joker with a freeze (keeps your streak)" : "Streak freezes — bridge a missed day, or exchange one for a joker"}">🧊 ${me.freezes || 0}</span>`;

        jokersEl.innerHTML = buttons + wallet + freezeWallet;

        jokersEl.querySelectorAll(".fw-joker-btn").forEach(btn => {
            if (!btn.disabled) btn.addEventListener("click", () => selectHint(btn.dataset.type));
        });
        // Wallet chips switch the payment mode.
        const streakChip = jokersEl.querySelector('.fw-streak-wallet.selectable');
        if (streakChip) streakChip.addEventListener("click", () => { useFreeze = false; renderJokers(); });
        const freezeChip = jokersEl.querySelector('.fw-freeze-wallet.selectable');
        if (freezeChip) freezeChip.addEventListener("click", () => { useFreeze = true; renderJokers(); });

        jokerHintEl.textContent = pendingHint
            ? `Pick a board for the ${pendingHint} joker (click the joker again to cancel).`
            : payFreezeNow ? "Paying with a freeze — your streak stays safe (click 🔥 to pay with streak)."
            : me.free_joker ? "No streak — your first joker today is free."
            : broke ? "No streak or freezes left for jokers."
            : canFreeze ? "Each joker costs 1 streak (click 🧊 to pay with a freeze)."
            : "Each joker costs 1 streak.";
    }

    function selectHint(type) {
        if (busy || STATE.me.finished) return;
        const t = HINT_TYPES.find(x => x.type === type);
        if (t && !t.board) { applyHint(type, null); return; } // armor: instant
        pendingHint = (pendingHint === type) ? null : type;   // board jokers: toggle pick
        renderJokers();
        renderBoards(); // refresh the pickable highlight
    }

    // A used board-joker shown as an extra board row beneath the board (armor
    // isn't board-attached, so it never shows here).
    function hintRowHtml(h, len) {
        const cells = new Array(len).fill(null);
        const cls = h.type === "green" ? "green" : "orange";
        (h.cells || []).forEach(c => { if (c.pos >= 0 && c.pos < len) cells[c.pos] = { ch: c.letter, cls }; });
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
        const boardHints = (me.hints || []).filter(h => h.type !== "armor");
        if (n === 0 || !boardHints.length) { hintRowsEl.innerHTML = ""; return; }
        hintRowsEl.style.setProperty("--boards", n);

        const byBoard = {};
        boardHints.forEach(h => { byBoard[h.board] = h; });
        const len = L();

        let html = "";
        for (let b = 0; b < n; b++) {
            const h = byBoard[b];
            if (!h) continue;
            const label = h.type === "orange" ? "in word" : "in spot";
            html += `<div class="fw-board" style="grid-column:${b + 1}">
                <div class="fw-board-head"><span>Board ${b + 1} hint</span><span>${label}</span></div>
                ${hintRowHtml(h, len)}
            </div>`;
        }
        hintRowsEl.innerHTML = html;
    }

    function applyHint(type, board) {
        if (busy || STATE.me.finished) return;
        const me = STATE.me;
        const canSpendStreak = (me.streak || 0) >= 1 || me.free_joker;
        const payFreeze = !!me.can_freeze_joker && (useFreeze || !canSpendStreak);

        // Warn before spending — armor lands here right after its click, reveal/
        // place right after a board is picked.
        const names = { armor: "Armor (+1 guess)", orange: "Reveal (+2 🟨)", green: "Place (+1 🟩)" };
        const cost = payFreeze
            ? "This uses 1 🧊 freeze — your streak stays safe."
            : me.free_joker
                ? "This uses your one free joker (you have no streak)."
                : "This costs you 1 🔥 streak.";
        if (!confirm(`Use ${names[type] || "this joker"}?\n\n${cost}`)) {
            pendingHint = null;
            renderJokers();
            renderBoards();
            return;
        }

        pendingHint = null;
        busy = true;
        useFreeze = false; // consume the toggle either way
        setMsg("");
        const body = new FormData();
        body.append("type", type);
        body.append("date", viewedDate);
        if (board !== null && board !== undefined) body.append("board", board);
        if (payFreeze) body.append("pay", "freeze");
        fetch(BASE_PATH + "/api/boardle-hint.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Joker failed."); }))
            .then(state => { STATE = state; renderAll(); })
            .catch(err => setMsg(err.message))
            .finally(() => { busy = false; });
    }

    // Write a shared clue for a board you've solved that has none yet — it then
    // shows to every player under that board. Opens a styled modal (matching the
    // tomorrow's-word picker) rather than an inline field, since renderBoards
    // re-runs on every keystroke/poll and would wipe an embedded input mid-edit.
    let hintModalBoard = null;
    function openHintModal(board) {
        if (busy) return;
        hintModalBoard = board;
        hintInputEl.value = "";
        hintModalMsgEl.textContent = "";
        hintModalMsgEl.classList.remove("ok");
        hintModalEl.style.display = "flex";
        hintInputEl.focus();
    }
    function closeHintModal() {
        hintModalEl.style.display = "none";
        hintModalBoard = null;
    }
    function saveBoardHint() {
        const hint = hintInputEl.value.trim();
        if (!hint) { hintModalMsgEl.textContent = "Write something first."; return; }
        if (busy || hintModalBoard === null) return;
        busy = true;
        hintModalMsgEl.textContent = "";
        const body = new FormData();
        body.append("board", hintModalBoard);
        body.append("hint", hint);
        body.append("date", viewedDate);
        fetch(BASE_PATH + "/api/boardle-add-hint.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Could not save hint."); }))
            .then(state => { STATE = state; closeHintModal(); renderAll(); })
            .catch(err => { hintModalMsgEl.classList.remove("ok"); hintModalMsgEl.textContent = err.message; })
            .finally(() => { busy = false; });
    }
    document.getElementById("fw-hint-cancel").addEventListener("click", closeHintModal);
    document.getElementById("fw-hint-save").addEventListener("click", saveBoardHint);
    hintModalEl.addEventListener("click", e => { if (e.target === hintModalEl) closeHintModal(); });

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
            const c = h.type === "green" ? "green" : "orange";
            (h.cells || []).forEach(cell => bump(cell.letter, c));
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
        statsEl.innerHTML = stats.map((s, i) => {
            const played = s.solves > 0 || s.streak > 0;
            const avg = s.avg_guesses != null ? s.avg_guesses + " avg" : "—";
            const rank = i < 3 ? ` rank-${i + 1}` : "";
            return `
                <div class="fw-stat${played ? "" : " empty"}${rank}">
                    <span class="fw-stat-name">${escapeHtml(s.username)}</span>
                    <span class="fw-stat-streak" title="Day streak">🔥 ${s.streak}</span>
                    <span class="fw-stat-freeze" title="Streak freezes">🧊 ${s.freezes ?? 0}</span>
                    <span class="fw-stat-avg" title="Average guesses on solved days">${avg}</span>
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
            const oMax = o.max_guesses;
            const solvedCount = o.solved_boards.filter(Boolean).length;
            const jokerTxt = o.jokers_used > 0 ? ` · 🃏${o.jokers_used}` : "";
            const statusTxt = (o.finished
                ? (o.solved ? `solved in ${o.guesses_used}` : `done · ${solvedCount}/${STATE.num_boards}`)
                : `${o.guesses_used}/${oMax} guesses`) + jokerTxt;

            const owned = o.owned || [];
            let minis = "";
            for (let b = 0; b < STATE.num_boards; b++) {
                // A board they chose is auto-solved — show a single green row
                // (they never guessed it, so there's no winning row to freeze on).
                if (owned.includes(b)) {
                    minis += `<div class="fw-mini" style="--cols:${len}">`;
                    for (let r = 0; r < oMax; r++) {
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
                for (let r = 0; r < oMax; r++) {
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
    let suggestOverride = null; // rerolled suggestions from the randomize button
    let lastChooseSig = null;
    let choosePrompted = false; // popped the "you solved it!" modal already this pageview?
    let chooseModalDismissedOnce = false; // has the win popup been shown & closed yet this pageview?
    let todayPickPrompted = false; // popped the "pick today's word" modal already this pageview?

    // Persist "already shown" across reloads (per browser, per today's date) so
    // the popup only ever fires once for a given solve — not again on every
    // refresh. If it was already shown, treat it as pre-dismissed: skip the
    // schedule and don't suppress the resting sidebar slot either.
    const CHOOSE_SEEN_KEY = `boardle_choose_seen_${BASE_PATH}_${STATE.date}`;
    if (localStorage.getItem(CHOOSE_SEEN_KEY)) {
        choosePrompted = true;
        chooseModalDismissedOnce = true;
    }

    function renderChoose() {
        const c = STATE.choose;
        const eligibleForPopup = c.eligible && !c.for_today && STATE.me.solved && !c.already;
        // Until the "you solved it" popup has been shown & dismissed once, keep
        // the resting sidebar slot empty — no flash/duplicate of the picker
        // before the popup appears. While the popup is actually open (#fw-choose
        // is currently parented inside it), it must stay visible there
        // regardless — openChooseModal forces that directly, this only governs
        // the resting sidebar spot.
        const inModal = chooseEl.parentElement === chooseModalEl;
        const suppressResting = eligibleForPopup && !chooseModalDismissedOnce && !inModal;
        // Word-picking only ever concerns the real current day — replaying a
        // missed past day shouldn't offer to set a word for its (long since
        // finalized) "tomorrow".
        const visible = viewedDate === STATE.today && c.eligible && (STATE.me.solved || c.for_today) && !suppressResting;

        // Only rebuild when something actually changed — otherwise a background
        // poll would wipe the custom-word input you're typing in. The rerolled
        // suggestions are part of the signature so they survive polls.
        const overrideSig = suggestOverride ? suggestOverride.join(",") : "";
        const sig = visible ? `${c.already || ""}|${c.already_hint || ""}|${changing}|${c.tomorrow_length}|${overrideSig}` : "hidden";
        if (sig === lastChooseSig) return;
        lastChooseSig = sig;

        if (!visible) { chooseEl.style.display = "none"; return; }
        chooseEl.style.display = "";

        const tlen = c.tomorrow_length;
        if (c.already && !changing) {
            const hintHtml = c.already_hint
                ? `<p class="fw-chosen-hint"><span class="fw-chosen-hint-label">Your hint:</span> ${escapeHtml(c.already_hint)}</p>`
                : "";
            const lockedMsg = c.for_today
                ? "Saved. You can still change it until the first guess is made."
                : "Saved. You can change it until tomorrow begins.";
            const lockedTitle = c.for_today ? "🗳️ Today's word" : "🗳️ Tomorrow's word";
            chooseEl.innerHTML = `
                <h2>${lockedTitle}</h2>
                <div class="fw-chosen">
                    Your pick: <span class="fw-spoiler-wrap"><strong class="fw-spoiler" id="fw-spoiler">${escapeHtml(c.already)}</strong><button type="button" class="btn fw-reveal-btn" id="fw-reveal">Reveal</button></span>
                    ${hintHtml}
                    <br><button type="button" class="btn" id="fw-change">Change it</button>
                </div>
                <p class="fw-choose-msg ok" id="fw-choose-msg">${lockedMsg}</p>
            `;
            document.getElementById("fw-change").addEventListener("click", () => { changing = true; renderChoose(); });
            document.getElementById("fw-reveal").addEventListener("click", () => {
                const spoiler = document.getElementById("fw-spoiler");
                const btn = document.getElementById("fw-reveal");
                const revealed = spoiler.classList.toggle("revealed");
                btn.textContent = revealed ? "Hide" : "Reveal";
            });
            return;
        }

        const suggestions = suggestOverride || c.suggestions || [];
        const sugg = suggestions.map(w =>
            `<button type="button" class="btn fw-suggest" data-word="${escapeHtml(w)}">${escapeHtml(w)}</button>`
        ).join("");

        const pickTitle = c.for_today
            ? "The day just started — still time to set your word!"
            : "🎉 Set a word for tomorrow";
        const pickDesc = c.for_today
            ? `Today is a <strong>${tlen}-letter</strong> day. Pick a suggestion or enter your own (must be a real ${tlen}-letter word). Once someone guesses, the word is final.`
            : `Tomorrow is a <strong>${tlen}-letter</strong> day. Pick a suggestion or enter your own (must be a real ${tlen}-letter word).`;

        chooseEl.innerHTML = `
            <h2>${pickTitle}</h2>
            <p class="muted">${pickDesc}</p>
            <div class="fw-suggests">${sugg}<button type="button" class="btn fw-randomize" id="fw-randomize" title="Get three new suggestions" aria-label="Randomize suggestions">🎲</button></div>
            <div class="fw-custom-row">
                <input type="text" id="fw-custom" maxlength="${tlen}" placeholder="Your own ${tlen}-letter word" value="${escapeHtml(changing && c.already ? c.already : "")}">
                <button type="button" class="btn-primary" id="fw-custom-go">Set word</button>
            </div>
            <div class="fw-hint-wrap">
                <label for="fw-hint">Hint for players (optional — shown under their board from the start):</label>
                <textarea id="fw-hint" placeholder="Give players a clue without spoiling the word...">${escapeHtml(c.already_hint || "")}</textarea>
            </div>
            <p class="fw-choose-msg" id="fw-choose-msg"></p>
        `;
        chooseEl.querySelectorAll(".fw-suggest").forEach(btn =>
            btn.addEventListener("click", () => submitChoice(btn.dataset.word)));
        const randBtn = document.getElementById("fw-randomize");
        if (randBtn) randBtn.addEventListener("click", randomizeSuggestions);
        document.getElementById("fw-custom-go").addEventListener("click", () => {
            submitChoice(document.getElementById("fw-custom").value.trim().toLowerCase());
        });
        document.getElementById("fw-custom").addEventListener("keydown", e => {
            if (e.key === "Enter") { e.preventDefault(); submitChoice(e.target.value.trim().toLowerCase()); }
        });
    }

    // ── "You solved it!" popup ──────────────────────────────────────────────
    // #fw-choose is a single node re-parented between its resting spot at the
    // bottom of the page and this popup — same element, same event listeners,
    // so opening/closing never duplicates the picker's logic.
    function openChooseModal() {
        chooseEl.classList.add("fw-choose-modal");
        chooseModalEl.appendChild(chooseEl);
        chooseEl.style.display = ""; // force visible — it may have been hidden while resting
        chooseModalEl.style.display = "flex";
    }
    function closeChooseModal() {
        if (chooseModalEl.style.display === "none") return;
        chooseModalDismissedOnce = true; // reveal the resting sidebar slot from now on
        chooseEl.classList.remove("fw-choose-modal");
        chooseAnchorEl.insertAdjacentElement("beforebegin", chooseEl);
        chooseModalEl.style.display = "none";
    }
    // Pop up once ever per solve (persisted in localStorage, not just this
    // pageview) the moment the picker becomes available after a win — lets the
    // player choose without scrolling. Doesn't reopen on a refresh, and never
    // fires for the "already picked" state or the for-today late-pick (that
    // one gets its own immediate popup — see maybeOpenTodayPickModal below).
    const CHOOSE_MODAL_DELAY_MS = 1800; // let the win sink in before popping the picker up
    function maybeOpenChooseModal() {
        if (choosePrompted || viewedDate !== STATE.today) return;
        const c = STATE.me.finished && STATE.choose;
        if (c && c.eligible && !c.for_today && STATE.me.solved && !c.already) {
            choosePrompted = true; // set immediately so a re-render during the delay can't re-schedule it
            localStorage.setItem(CHOOSE_SEEN_KEY, "1"); // never pop again for this solve, even across reloads
            setTimeout(() => {
                openChooseModal();
                // renderChoose() was a no-op while resting-suppressed (sig was
                // "hidden"), so #fw-choose never got its content built. Now that
                // it's parented inside the modal (inModal), re-run it so the
                // picker actually renders instead of sitting empty until the
                // next poll fills it in.
                renderChoose();
            }, CHOOSE_MODAL_DELAY_MS);
        }
    }
    // Pop up immediately on page load (no delay, no "you solved it" fanfare)
    // when the previous day wasn't solved by this player but a board slot is
    // still up for grabs — as long as nobody (including this player) has made
    // a guess yet today. Fires once per pageview; a fresh page load (e.g. next
    // visit) can fire it again since it's tied to live state, not a one-time
    // event like a solve.
    function maybeOpenTodayPickModal() {
        if (todayPickPrompted || choosePrompted || viewedDate !== STATE.today) return;
        const c = STATE.choose;
        if (c && c.eligible && c.for_today && !c.already) {
            todayPickPrompted = true;
            openChooseModal();
            renderChoose();
        }
    }
    chooseModalCloseEl.addEventListener("click", closeChooseModal);
    chooseModalEl.addEventListener("click", e => { if (e.target === chooseModalEl) closeChooseModal(); });

    function submitChoice(word) {
        const tlen = STATE.choose.tomorrow_length;
        const cmsg = document.getElementById("fw-choose-msg");
        if (!/^[a-z]+$/.test(word) || word.length !== tlen) {
            if (cmsg) { cmsg.classList.remove("ok"); cmsg.textContent = `Needs to be a ${tlen}-letter word.`; }
            return;
        }
        const hintInput = document.getElementById("fw-hint");
        const hint = hintInput ? hintInput.value.trim() : "";
        const body = new FormData();
        body.append("word", word);
        if (hint) body.append("hint", hint);
        fetch(BASE_PATH + "/api/boardle-choose.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Could not save."); }))
            .then(res => {
                STATE.choose.already = res.word;
                STATE.choose.already_hint = res.hint || null;
                changing = false;
                renderChoose();
                closeChooseModal(); // picked — send it back to its resting spot at the bottom
            })
            .catch(err => { if (cmsg) { cmsg.classList.remove("ok"); cmsg.textContent = err.message; } });
    }

    // Reroll the three suggestions (server picks fresh random words of the right
    // length). Kept in suggestOverride so polls don't reset them.
    function randomizeSuggestions() {
        const cmsg = document.getElementById("fw-choose-msg");
        fetch(BASE_PATH + "/api/boardle-suggest.php")
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Could not shuffle."); }))
            .then(res => { suggestOverride = res.suggestions || []; renderChoose(); })
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
        body.append("date", viewedDate);
        fetch(BASE_PATH + "/api/boardle-guess.php", { method: "POST", body })
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
        fetch(BASE_PATH + "/api/boardle-state.php?date=" + encodeURIComponent(viewedDate))
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(state => {
                // Don't clobber an in-progress guess: the local buffer is kept.
                const savedScroll = window.scrollY;
                STATE = state;
                renderDayNav();
                renderStatus(); renderJokers(); renderBoards(); renderBoardHints(); renderHintRows();
                renderControls(); renderKeyboard(); renderStats(); renderOpponents(); renderChoose();
                maybeOpenChooseModal();
                // Safari resets scroll when large innerHTML sections are replaced.
                if (window.scrollY !== savedScroll) window.scrollTo(0, savedScroll);
            })
            .catch(() => {});
    }

    // ── Day nav: prev/next arrows + "back to today" + calendar popup ───────
    function addDays(dateStr, delta) {
        // Build the result from local date parts, not toISOString() (which
        // converts to UTC and silently drops/duplicates a day in any
        // timezone ahead of UTC).
        const d = new Date(dateStr + "T00:00:00");
        d.setDate(d.getDate() + delta);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");
        return `${y}-${m}-${day}`;
    }
    function fmtDayLabel(dateStr) {
        const d = new Date(dateStr + "T00:00:00");
        return d.toLocaleDateString(undefined, { weekday: "short", year: "numeric", month: "short", day: "numeric" });
    }

    function goToDate(dateStr) {
        if (busy) return;
        const earliest = STATE.earliest_date, today = STATE.today;
        if (dateStr < earliest) dateStr = earliest;
        if (dateStr > today) dateStr = today;
        if (dateStr === viewedDate) return;
        viewedDate = dateStr;
        buffer = "";
        kbBoard = 0;
        pendingHint = null;
        setMsg("");
        loadState();
    }

    function renderDayNav() {
        const today = STATE.today, earliest = STATE.earliest_date;
        dayLabelEl.textContent = (viewedDate === today ? "Today · " : "") + fmtDayLabel(viewedDate);
        dayPrevEl.disabled = viewedDate <= earliest;
        dayNextEl.disabled = viewedDate >= today;
        dayTodayEl.style.display = viewedDate === today ? "none" : "";
    }

    dayPrevEl.addEventListener("click", () => goToDate(addDays(viewedDate, -1)));
    dayNextEl.addEventListener("click", () => goToDate(addDays(viewedDate, 1)));
    dayTodayEl.addEventListener("click", () => goToDate(STATE.today));
    dayLabelEl.addEventListener("click", openCalendar);

    // ── Calendar popup ──────────────────────────────────────────────────────
    let calHistory = null;      // { today, earliest_date, results: { "Y-m-d": {finished, solved} } }
    let calMonthCursor = null;  // "Y-m-01" of the month currently shown

    function openCalendar() {
        calMonthCursor = viewedDate.slice(0, 8) + "01";
        calModalEl.style.display = "flex";
        if (calHistory) renderCalendar(); // paint the stale cache instantly, then refresh
        fetch(BASE_PATH + "/api/boardle-history.php")
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(res => { calHistory = res; renderCalendar(); })
            .catch(() => { if (!calHistory) calGridEl.innerHTML = `<p class="fw-empty-note">Couldn't load history.</p>`; });
    }
    function closeCalendar() { calModalEl.style.display = "none"; }

    function renderCalendar() {
        if (!calHistory) return;
        const [cy, cm] = calMonthCursor.split("-").map(Number);
        calMonthLabelEl.textContent = new Date(cy, cm - 1, 1).toLocaleDateString(undefined, { month: "long", year: "numeric" });

        const firstDow = new Date(cy, cm - 1, 1).getDay(); // 0=Sun
        const daysInMonth = new Date(cy, cm, 0).getDate();
        const dows = ["S", "M", "T", "W", "T", "F", "S"];

        let html = dows.map(d => `<div class="fw-cal-dow">${d}</div>`).join("");
        for (let i = 0; i < firstDow; i++) html += `<div class="fw-cal-day empty"></div>`;

        for (let day = 1; day <= daysInMonth; day++) {
            const ds = `${cy}-${String(cm).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
            const outOfRange = ds < calHistory.earliest_date || ds > calHistory.today;
            const r = calHistory.results[ds];
            const cls = ["fw-cal-day"];
            if (outOfRange) cls.push("disabled");
            else if (r && r.solved) cls.push("solved");
            // A past day counts as missed whether it was played and not
            // solved, or never opened at all — today is still in progress
            // so it's left unmarked either way.
            else if (ds !== calHistory.today) cls.push("missed");
            if (ds === calHistory.today) cls.push("is-today");
            if (ds === viewedDate) cls.push("viewing");
            html += `<div class="${cls.join(" ")}" data-date="${ds}">${day}</div>`;
        }
        calGridEl.innerHTML = html;

        calGridEl.querySelectorAll(".fw-cal-day[data-date]:not(.disabled)").forEach(el => {
            el.addEventListener("click", () => { goToDate(el.dataset.date); closeCalendar(); });
        });

        calPrevMonthEl.disabled = calMonthCursor <= calHistory.earliest_date.slice(0, 8) + "01";
        calNextMonthEl.disabled = calMonthCursor >= calHistory.today.slice(0, 8) + "01";
    }

    calPrevMonthEl.addEventListener("click", () => {
        const [y, m] = calMonthCursor.split("-").map(Number);
        const d = new Date(y, m - 2, 1);
        calMonthCursor = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
        renderCalendar();
    });
    calNextMonthEl.addEventListener("click", () => {
        const [y, m] = calMonthCursor.split("-").map(Number);
        const d = new Date(y, m, 1);
        calMonthCursor = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
        renderCalendar();
    });
    calModalEl.addEventListener("click", e => { if (e.target === calModalEl) closeCalendar(); });

    function renderAll() {
        renderDayNav();
        renderStatus();
        renderJokers();
        renderBoards();
        renderBoardHints();
        renderHintRows();
        renderControls();
        renderKeyboard();
        renderStats();
        renderOpponents();
        renderChoose();
        maybeOpenChooseModal();
        maybeOpenTodayPickModal();
    }

    // ── Go ─────────────────────────────────────────────────────────────────
    buildKeyboard();
    renderAll();

    setInterval(() => { if (!document.hidden) loadState(); }, 6000);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) loadState(); });
    window.addEventListener("pageshow", e => { if (e.persisted) loadState(); });
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
