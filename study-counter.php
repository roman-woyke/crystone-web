<?php

require_once __DIR__ . "/includes/session.php";

// ── Module list: exam-calendar titles (defaults) + custom study modules ──
$examTitles = $pdo->query("SELECT DISTINCT title FROM exams ORDER BY title")
    ->fetchAll(PDO::FETCH_COLUMN);
$customMods = $pdo->query("SELECT name FROM study_modules ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

$modules = [];
$seen = [];
foreach ($examTitles as $t) {
    $key = mb_strtolower($t);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $modules[] = ["name" => $t, "custom" => false];
}
foreach ($customMods as $c) {
    $key = mb_strtolower($c);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $modules[] = ["name" => $c, "custom" => true];
}

// ── Initial state, rendered server-side so the page paints complete on the
//    first byte (no fetch round-trip, no "Loading…" flash on every visit). ──
require_once __DIR__ . "/includes/study-status.php";
require_once __DIR__ . "/includes/study-sessions.php";
$initialStatus = studyStatusPayload($pdo, $_SESSION["user_id"]);

// Sessions spanning midnight are split across days (see studySessionsByDay).
$initialSessions = studySessionsByDay($pdo);

$pageTitle = "Study Counter";
require_once __DIR__ . "/includes/header.php";
?>

<style>
main.container {
    max-width: 1600px;
}

.study-head {
    margin-bottom: 8px;
}

.study-sub {
    margin: 0;
    color: var(--text-2);
    max-width: 680px;
}

/* ── Layout: content + sticky "currently studying" sidebar ──────────────── */

.study-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 28px;
    align-items: stretch;
}

.study-main {
    min-width: 0; /* let wide children (chart) shrink instead of overflowing */
}

/* Intro row: compact title + filters column on the left, centered podium on
   the right (the sticky dock lives in its own .study-layout column). */
.study-intro-layout {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 32px;
    align-items: start;
    margin-bottom: 36px;
}

.study-intro {
    margin-bottom: 0;
}

/* The dock column reserves space; the card inside sticks while scrolling and
   flips between "currently studying" (front) and today's timeline (back). */
.studying-dock {
    display: flex;
    flex-direction: column;
}

.dock-card {
    position: sticky;
    top: 78px;
    perspective: 1800px;
    /* Clip the hidden, full-height back face so it doesn't add phantom page
       scroll while the (short) front is showing. */
    overflow: hidden;
    /* Height tracks the visible face's content (set in JS); animating it makes
       the bottom edge retract/extend smoothly across a flip. */
    transition: height 0.5s var(--ease-out);
}

/* Front = sticky sidebar that follows the scroll. The recap (back) instead
   takes the full height it needs and drops stickiness, so a tall day's
   timeline extends down the page and scrolls normally rather than being
   pinned at the top and clipped. */
.dock-card.recap {
    position: relative;
    top: auto;
    overflow: visible;
}

.flip-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transition: transform 0.6s var(--ease-out);
    transform-style: preserve-3d;
}

.flip-inner.flipped {
    transform: rotateY(180deg);
}

.flip-face {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    /* No bottom anchor → each face is only as tall as its own content. */
    display: flex;
    flex-direction: column;
    padding: 16px 18px;
    overflow: hidden;
    /* Solid (not backdrop-filter) so the 3D flip stays crisp. */
    background: var(--solid-glass);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    box-shadow: inset 0 1px 0 var(--glass-highlight), var(--shadow-lift);
    -webkit-backface-visibility: hidden;
    backface-visibility: hidden;
}

.flip-back {
    transform: rotateY(180deg);
}

.flip-btn {
    flex-shrink: 0;
    font-size: 1.05rem;
    line-height: 1;
}

/* Front: scrollable body so a long studying/break/library list never clips. */
.face-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}

/* Back: the timeline gets the whole card height to itself (no squashing). */
.flip-back .face-body {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

@media (max-width: 980px) {
    .study-layout {
        display: flex;
        flex-direction: column;
    }

    .study-intro-layout {
        grid-template-columns: 1fr;
    }

    .podium-controls {
        flex-direction: row;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .studying-dock {
        order: -1;
        display: block;
    }

    /* Drop sticky/viewport sizing on narrow screens; stay a positioned
       ancestor so the absolute faces anchor here, and let JS drive height. */
    .dock-card {
        position: relative;
        top: auto;
    }

    #dock-timeline {
        flex: none;
    }

    .tl-body {
        height: 240px;
        flex: none;
    }
}

/* ── Day timeline ─────────────────────────────────────────────────────────── */

/* Heute / Gestern segmented toggle in the recap header */
.recap-day-nav {
    display: inline-flex;
    gap: 2px;
    padding: 2px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: var(--radius-full);
}

.recap-day-btn {
    width: auto;
    margin: 0;
    padding: 4px 12px;
    border: none;
    background: transparent;
    color: var(--text-2);
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1.1;
    border-radius: var(--radius-full);
    cursor: pointer;
    white-space: nowrap;
    transition: all var(--t-fast);
}

.recap-day-btn:hover:not(.active) {
    color: var(--text-1);
}

.recap-day-btn.active {
    background: var(--grad-accent);
    color: #fff;
    box-shadow: var(--glow-violet);
}

.tl-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 8px;
}

.tl-user-heads {
    display: flex;
    margin-left: 38px;
    gap: 4px;
    margin-bottom: 4px;
}

.tl-col-head {
    flex: 1;
    font-size: 0.72rem;
    font-weight: 700;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

#dock-timeline {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.tl-body {
    position: relative;
    height: 300px;
    display: flex;
    gap: 4px;
}

.tl-axis {
    position: relative;
    width: 34px;
    flex-shrink: 0;
}

.tl-tick {
    position: absolute;
    right: 0;
    transform: translateY(-50%);
}

.tl-tick-label {
    font-size: 0.61rem;
    color: var(--text-3);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.tl-cols-wrap {
    flex: 1;
    position: relative;
}

.tl-grid-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: rgba(255, 255, 255, 0.05);
    pointer-events: none;
}

.tl-now-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--danger);
    opacity: 0.75;
    pointer-events: none;
    z-index: 2;
}

.tl-now-dot {
    position: absolute;
    left: -3px;
    top: -3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--danger);
}

/* Collapsed-time marker: a thin strip where empty hours were squeezed out. */
.tl-gap {
    position: absolute;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.tl-gap::before {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    border-top: 1px dashed rgba(255, 255, 255, 0.18);
}

.tl-gap-mark {
    position: relative;
    padding: 0 6px;
    font-size: 0.62rem;
    line-height: 1;
    letter-spacing: 0.1em;
    color: var(--text-3);
    background: var(--solid-glass);
}

.tl-cols {
    position: absolute;
    inset: 0;
    display: flex;
    gap: 4px;
}

.tl-col {
    flex: 1;
    position: relative;
}

.tl-block {
    position: absolute;
    left: 1px;
    right: 1px;
    border-radius: 4px;
    min-height: 4px;
    overflow: hidden;
    opacity: 0.82;
    display: flex;
    align-items: center;
    justify-content: center;
}

.tl-block.live {
    opacity: 1;
    border-top: 2px solid rgba(255, 255, 255, 0.35);
}

.tl-block-label {
    max-width: 100%;
    padding: 0 3px;
    font-size: 0.58rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.2;
}

.tl-empty {
    font-size: 0.8rem;
    color: var(--text-3);
    margin: 8px 0 0;
}

.studying-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
}

.studying-head h2 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    font-size: 1.05rem;
}

.studying-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    margin-bottom: 12px;
}

.studying-toggle {
    width: auto;
    margin: 0;
    padding: 7px 14px;
    font-size: 0.85rem;
    white-space: nowrap;
}

.studying-toggle.active {
    background: var(--grad-accent);
    border-color: transparent;
    color: #fff;
    box-shadow: var(--glow-violet);
}

.studying-toggle:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.studying-break {
    color: var(--warning);
    border-color: rgba(251, 191, 36, 0.4);
    background: rgba(251, 191, 36, 0.1);
}

.studying-break:hover {
    background: rgba(251, 191, 36, 0.18);
    border-color: var(--warning);
    color: var(--warning);
}

/* BIB ("at the library") button — small accent button, independent flag. */
.studying-bib {
    padding: 5px 10px;
    font-size: 0.78rem;
    color: var(--info, #38bdf8);
    border-color: rgba(56, 189, 248, 0.4);
    background: rgba(56, 189, 248, 0.1);
}

.studying-bib:hover {
    background: rgba(56, 189, 248, 0.18);
    border-color: var(--info, #38bdf8);
    color: var(--info, #38bdf8);
}

.studying-bib.active {
    background: var(--info, #38bdf8);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 0 18px rgba(56, 189, 248, 0.45);
}

/* On-break sub-container */
.studying-break-box {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed var(--glass-border);
}

/* At-the-library sub-container — same shape as the break box, blue accent. */
.studying-library-box {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed var(--glass-border);
}

.library-label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
}

.library-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.studying-chip.library {
    border-color: rgba(56, 189, 248, 0.4);
    background: rgba(56, 189, 248, 0.08);
}

.pulse-dot.library {
    background: var(--info, #38bdf8);
    box-shadow: none;
    animation: none;
}

.break-label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
}

.break-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.studying-chip.break {
    opacity: 0.85;
    border-style: dashed;
}

.studying-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-content: flex-start;
}

.studying-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px 7px 10px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-full);
    font-size: 0.86rem;
    font-weight: 600;
}

.studying-chip.me {
    border-color: rgba(139, 92, 246, 0.5);
    background: var(--grad-accent-soft);
}

.studying-chip .chip-meta {
    font-weight: 500;
    color: var(--text-3);
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums; /* keep chip width stable while ticking */
}

.studying-chip .chip-break {
    margin-left: 6px;
    color: var(--warning);
    font-weight: 600;
}

/* Pulsing presence dots */
.studying-live-dot,
.pulse-dot {
    display: inline-block;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.6);
    animation: pulse-ring 1.8s ease-out infinite;
}

.pulse-dot.paused {
    background: var(--warning);
    box-shadow: none;
    animation: none;
}

.pulse-dot.presence {
    background: var(--info);
    box-shadow: none;
    animation: none;
}

@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.55); }
    70%  { box-shadow: 0 0 0 7px rgba(52, 211, 153, 0); }
    100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
}

@media (prefers-reduced-motion: reduce) {
    .studying-live-dot,
    .pulse-dot { animation: none; }
}

/* ── Podium + per-module toggle ─────────────────────────────────────────── */

.podium-heading {
    margin: 0 0 16px;
    font-size: 1.25rem;
}

.podium-controls {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    margin-top: 20px;
}


.period-tab {
    display: inline-flex;
    align-items: center;
    width: auto;
    margin: 0;
    padding: 8px 20px;
    border-radius: var(--radius-full);
    font-size: 0.92rem;
    font-weight: 600;
    border: none;
    background: transparent;
    color: var(--text-2);
    cursor: pointer;
    transition: all var(--t-fast);
    white-space: nowrap;
    line-height: 1;
}

.tab-sep {
    display: block;
    width: 100%;
    height: 2px;
    background: rgba(255,255,255,0.12);
    border-radius: 2px;
    margin: 4px 0;
}

.period-tab.active {
    background: var(--grad-accent);
    color: #fff;
    box-shadow: var(--glow-violet);
}

.period-tab:hover:not(.active) {
    background: rgba(255, 255, 255, 0.08);
    color: var(--text-1);
}


.podium-views {
    position: relative;
    margin-bottom: 0;
}

.podium-view {
    transition: opacity var(--t-med) var(--ease-out);
}

.podium-view.fade-out {
    opacity: 0;
}

.podium-sub-time {
    font-size: 0.78rem;
    color: var(--text-3);
}

/* Per-module mini podiums */
.module-podium-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
    margin-top: 8px;
}

.module-podium {
    padding: 16px 18px;
}

.module-podium h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 12px;
    font-size: 1rem;
}

.module-dot {
    flex-shrink: 0;
    width: 13px;
    height: 13px;
    border-radius: 4px;
}

.module-podium .mp-custom {
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--violet);
    border: 1px solid rgba(139, 92, 246, 0.45);
    background: var(--grad-accent-soft);
    border-radius: var(--radius-full);
    padding: 1px 7px;
}

.mp-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.mp-row:last-child { border-bottom: none; }

.mp-rank {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.74rem;
    font-weight: 700;
    color: #fff;
    border-radius: 50%;
}

.mp-rank.r1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); }
.mp-rank.r2 { background: linear-gradient(135deg, #d1d5db, #9ca3af); }
.mp-rank.r3 { background: linear-gradient(135deg, #d97706, #92400e); }

.mp-name { flex: 1; font-weight: 600; font-size: 0.9rem; }
.mp-time { font-family: var(--font-display); font-weight: 700; font-size: 0.9rem; }

/* ── Logging panel ──────────────────────────────────────────────────────── */

.log-layout {
    display: grid;
    grid-template-columns: 380px minmax(0, 1fr);
    gap: 24px;
    align-items: start;
    margin-bottom: 36px;
}

@media (max-width: 980px) {
    .log-layout {
        grid-template-columns: 1fr;
    }
}

.log-panel {
    padding: 22px;
}

.log-panel h2 {
    margin: 0 0 16px;
    font-size: 1.15rem;
}

.module-picker label {
    display: block;
    margin-bottom: 2px;
}

.new-module-wrap {
    display: none;
    margin-top: -8px;
}

.new-module-wrap.show { display: block; }

.module-hint {
    margin: -8px 0 14px;
    font-size: 0.78rem;
    color: var(--text-3);
}

/* Stop modal */
#stop-modal select {
    margin: 16px 0 8px;
}

.mode-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
}

.mode-tab {
    flex: 1;
    width: auto;
    margin: 0;
}

.mode-tab.active {
    background: var(--grad-accent);
    border-color: transparent;
    color: #fff;
    box-shadow: var(--glow-violet);
}

.mode-pane { display: none; }
.mode-pane.active { display: block; }

.timer-display {
    font-family: var(--font-display);
    font-size: 3.4rem;
    font-weight: 700;
    line-height: 1.1;
    text-align: center;
    letter-spacing: 0.01em;
    margin: 6px 0 4px;
    font-variant-numeric: tabular-nums; /* fixed-width digits so ticking doesn't reflow */
}

.timer-note {
    margin: 0 0 16px;
    min-height: 1.1em;
    text-align: center;
    font-size: 0.82rem;
    color: var(--text-3);
}

.timer-note .tn-mod {
    color: var(--text-2);
    font-weight: 600;
}

.timer-note .tn-live {
    color: var(--success);
    font-weight: 600;
}

.timer-break {
    margin: -8px 0 12px;
    text-align: center;
    font-size: 1rem;
    font-weight: 600;
    color: var(--warning);
    font-variant-numeric: tabular-nums;
}

.timer-break .tb-time {
    font-family: var(--font-display);
    font-weight: 700;
}

.timer-controls {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}

.timer-controls button { margin: 0; flex: 1; }

.manual-today {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 14px;
    font-size: 0.86rem;
    color: var(--text-2);
    cursor: pointer;
}
.manual-today input { width: auto; margin: 0; cursor: pointer; }

.manual-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.manual-grid .full { grid-column: 1 / -1; }

/* Toggle between exact-time inputs (Today) and a duration (past day). */
#pane-manual.today-mode .manual-dur   { display: none; }
#pane-manual:not(.today-mode) .manual-exact { display: none; }
.manual-grid input:disabled { opacity: 0.5; cursor: not-allowed; }

.log-feedback {
    margin: 12px 0 0;
    font-size: 0.85rem;
    color: var(--success);
    min-height: 1.1em;
}

.log-feedback.error { color: var(--danger); }

/* ── Chart ──────────────────────────────────────────────────────────────── */

.chart-panel {
    padding: 22px;
}

.chart-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 6px;
}

.chart-head h2 {
    margin: 0;
    font-size: 1.15rem;
}

.week-nav {
    display: flex;
    align-items: center;
    gap: 8px;
}

.week-nav button {
    width: auto;
    margin: 0;
    padding: 6px 12px;
    line-height: 1;
}

.week-nav button:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.week-label {
    min-width: 168px;
    text-align: center;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-2);
}

.chart-area {
    position: relative;
    display: flex;
    align-items: stretch;
    gap: 10px;
    margin-top: 18px;
    height: 320px;
}

.chart-yaxis {
    position: relative;
    width: 42px;
    flex-shrink: 0;
}

.y-tick {
    position: absolute;
    right: 0;
    transform: translateY(50%);
    font-size: 0.68rem;
    color: var(--text-3);
    white-space: nowrap;
}

.chart-plot {
    position: relative;
    flex: 1;
    display: flex;
    align-items: flex-end;
    gap: 16px; /* clear separation between days (bars within a day stay tight) */
    border-left: 1px solid var(--glass-border);
    border-bottom: 1px solid var(--glass-border);
    padding: 0 6px;
}

.grid-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: rgba(255, 255, 255, 0.04);
}

.day-col {
    position: relative;
    z-index: 1;
    flex: 1;
    height: 100%;
    min-width: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 5px;
    padding: 0 6px;
    /* subtle band so each day reads as one group */
    background: rgba(255, 255, 255, 0.025);
    border-radius: 8px 8px 0 0;
}

.day-col.is-today { background: rgba(139, 92, 246, 0.10); }

.user-bar {
    position: relative;
    width: 100%;
    max-width: 30px;
    min-height: 3px;
    border-radius: 6px 6px 4px 4px;
    /* Solid user colour — "whose bar" is instant; modules live in the tooltip. */
    background: var(--user-color, var(--violet));
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
    cursor: default;
    transition: filter var(--t-fast);
}

.user-bar:hover { filter: brightness(1.12); }

.bar-total {
    position: absolute;
    top: -17px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 0.6rem;
    font-weight: 600;
    color: var(--text-3);
    white-space: nowrap;
    pointer-events: none;
}

.chart-xaxis {
    display: flex;
    gap: 10px;
    margin-top: 8px;
}

.chart-xaxis .xaxis-spacer {
    width: 42px;
    flex-shrink: 0;
}

.chart-xaxis .xaxis-cols {
    flex: 1;
    display: flex;
    gap: 16px;
    padding: 0 6px;
}

.day-label {
    flex: 1;
    min-width: 0;
    font-size: 0.74rem;
    line-height: 1.25;
    color: var(--text-3);
    text-align: center;
    white-space: nowrap;
}

.day-label.is-today {
    color: var(--text-1);
    font-weight: 700;
}

.chart-empty {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
    font-size: 0.92rem;
}

/* Legends */
.legends {
    display: flex;
    flex-wrap: wrap;
    gap: 28px;
    margin-top: 22px;
}

.legend-group h4 {
    margin: 0 0 8px;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-3);
}

.legend-items {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 0.83rem;
    color: var(--text-2);
}

.legend-swatch {
    width: 13px;
    height: 13px;
    border-radius: 4px;
    flex-shrink: 0;
}

.legend-swatch.outline {
    background: transparent;
    border: 2px solid var(--sw);
}

.legend-item.custom::after {
    content: "custom";
    font-size: 0.58rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--violet);
    border: 1px solid rgba(139, 92, 246, 0.45);
    border-radius: var(--radius-full);
    padding: 0 6px;
}

/* Floating tooltip */
.chart-tooltip {
    position: fixed;
    z-index: 300;
    pointer-events: none;
    padding: 8px 11px;
    font-size: 0.8rem;
    line-height: 1.35;
    color: var(--text-1);
    background: rgba(16, 16, 30, 0.92);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-card);
    opacity: 0;
    transition: opacity var(--t-fast);
}

.chart-tooltip.show { opacity: 1; }

.chart-tooltip .tt-user { font-weight: 700; }
.chart-tooltip .tt-mod  { color: var(--text-2); }
.chart-tooltip .tt-time { color: var(--text-1); font-weight: 600; }

@media (max-width: 560px) {
    .chart-area { height: 240px; }
    .manual-grid { grid-template-columns: 1fr; }
}
</style>

<div class="study-layout">
<div class="study-main">

<!-- ── Intro + Podium ─────────────────────────────────────────────────────── -->
<div class="study-intro-layout">

    <div class="study-intro">
        <h1 class="page-heading study-head">Study <span class="gradient-text">Counter</span></h1>
        <div class="podium-controls">
            <button class="period-tab active" data-period="overall">Overall</button>
            <button class="period-tab" data-period="weekly">This week</button>
            <button class="period-tab" data-period="daily">Today</button>
            <span class="tab-sep"></span>
            <button type="button" class="period-tab" id="podium-toggle">Per module</button>
            <button type="button" class="period-tab" id="library-toggle">Library only</button>
        </div>
    </div>

    <div>
        <h2 class="podium-heading">🏆 Most hours studied</h2>
        <div class="podium-views">
            <div class="podium-view" id="podium-overall">
                <div class="podium" id="overall-podium" style="display:none;"></div>
                <p class="muted" id="overall-empty" style="display:none;">No study sessions logged yet.</p>
            </div>
            <div class="podium-view fade-out" id="podium-modules" style="display:none;">
                <div class="module-podium-grid" id="module-podium-grid"></div>
                <p class="muted" id="modules-empty" style="display:none;">No study sessions logged yet.</p>
            </div>
        </div>
    </div>

</div>

<!-- ── Logging + chart ────────────────────────────────────────────────────── -->
<div class="log-layout">

    <section class="log-panel glass-card">
        <h2>Log a session</h2>

        <div class="module-picker">
            <label for="module-select">Module</label>
            <select id="module-select">
                <?php foreach ($modules as $i => $m): ?>
                    <option value="<?= htmlspecialchars($m["name"]) ?>">
                        <?= htmlspecialchars($m["name"]) ?><?= $m["custom"] ? "  (custom)" : "" ?>
                    </option>
                <?php endforeach; ?>
                <option value="__new__">+ Add new module…</option>
            </select>

            <div class="new-module-wrap" id="new-module-wrap">
                <input type="text" id="new-module-input" maxlength="255" placeholder="New module name">
                <p class="module-hint">This module will join the shared list for everyone.</p>
            </div>
        </div>

        <div class="mode-tabs">
            <button type="button" class="btn mode-tab active" data-mode="timer">Timer</button>
            <button type="button" class="btn mode-tab" data-mode="manual">Manual</button>
        </div>

        <!-- Timer -->
        <div class="mode-pane active" id="pane-timer">
            <div class="timer-display" id="timer-display">00:00</div>
            <p class="timer-break" id="timer-break" style="display:none;"></p>
            <p class="timer-note" id="timer-note">Keeps running even if you close the page.</p>
            <div class="timer-controls">
                <button type="button" class="btn-primary" id="timer-start">Start</button>
                <button type="button" class="btn" id="timer-reset">Reset</button>
            </div>
            <button type="button" class="btn-primary" id="timer-log">Log this session</button>
        </div>

        <!-- Manual -->
        <div class="mode-pane" id="pane-manual">
            <label class="manual-today">
                <input type="checkbox" id="manual-today" checked>
                <span>Today — log exact start &amp; end time</span>
            </label>

            <div class="manual-grid">
                <!-- Exact start/end (Today mode) -->
                <div class="manual-exact">
                    <label for="manual-start">Start</label>
                    <input type="time" id="manual-start">
                </div>
                <div class="manual-exact">
                    <label for="manual-end">End</label>
                    <input type="time" id="manual-end">
                </div>
                <!-- Duration (past-day mode) -->
                <div class="manual-dur">
                    <label for="manual-hours">Hours</label>
                    <input type="number" id="manual-hours" min="0" max="24" step="1" value="0">
                </div>
                <div class="manual-dur">
                    <label for="manual-minutes">Minutes</label>
                    <input type="number" id="manual-minutes" min="0" max="59" step="1" value="30">
                </div>
                <div class="full">
                    <label for="manual-date">Date</label>
                    <input type="date" id="manual-date">
                </div>
            </div>
            <button type="button" class="btn-primary" id="manual-log">Log session</button>
        </div>

        <p class="log-feedback" id="log-feedback"></p>
    </section>

    <section class="chart-panel glass-card">
        <div class="chart-head">
            <h2>Hours per day</h2>
            <div class="week-nav">
                <button type="button" class="btn" id="week-prev" aria-label="Previous week">‹</button>
                <span class="week-label" id="week-label">This week</span>
                <button type="button" class="btn" id="week-next" aria-label="Next week">›</button>
            </div>
        </div>

        <div class="chart-area">
            <div class="chart-yaxis" id="chart-yaxis"></div>
            <div class="chart-plot" id="chart-plot"></div>
            <div class="chart-empty" id="chart-empty" style="display:none;">No study sessions this week.</div>
        </div>
        <div class="chart-xaxis">
            <div class="xaxis-spacer"></div>
            <div class="xaxis-cols" id="chart-xaxis"></div>
        </div>

        <div class="legends">
            <div class="legend-group">
                <h4>Users <span class="muted" style="text-transform:none;letter-spacing:0;font-weight:400;">(bar colour · hover a bar for the module split)</span></h4>
                <div class="legend-items" id="legend-users"></div>
            </div>
        </div>
    </section>

</div>

</div><!-- /study-main -->

<aside class="studying-dock" id="studying-dock">
    <div class="dock-card">
        <div class="flip-inner" id="studying-flip">

            <!-- Front: who's currently studying / on break / at the library -->
            <div class="flip-face flip-front">
                <div class="studying-head">
                    <h2><span class="studying-live-dot"></span> Currently studying</h2>
                    <button type="button" class="icon-btn flip-btn" id="flip-to-recap" title="Today's timeline" aria-label="Show today's timeline">⇄</button>
                </div>
                <div class="studying-actions">
                    <button type="button" class="btn studying-toggle" id="studying-toggle">I'm studying</button>
                    <button type="button" class="btn studying-toggle studying-break" id="studying-break" style="display:none;">I'm on break</button>
                    <button type="button" class="btn studying-toggle studying-bib" id="studying-bib" style="display:none;">📚 BIB</button>
                </div>
                <div class="face-body">
                    <div class="studying-list" id="studying-list">
                        <span class="muted">Loading…</span>
                    </div>
                    <div class="studying-break-box" id="studying-break-box" style="display:none;">
                        <span class="break-label">☕ On break</span>
                        <div class="break-list" id="break-list"></div>
                    </div>
                    <div class="studying-library-box" id="studying-library-box" style="display:none;">
                        <span class="library-label">📚 At the library</span>
                        <div class="library-list" id="library-list"></div>
                    </div>
                </div>
            </div>

            <!-- Back: a day's timeline, one column per person (Heute / Gestern) -->
            <div class="flip-face flip-back">
                <div class="studying-head">
                    <div class="recap-day-nav">
                        <button type="button" class="recap-day-btn active" data-recap="0">📅 Heute</button>
                        <button type="button" class="recap-day-btn" data-recap="1">Gestern</button>
                    </div>
                    <button type="button" class="icon-btn flip-btn" id="flip-to-front" title="Back" aria-label="Back to currently studying">⇄</button>
                </div>
                <div class="face-body">
                    <div id="dock-timeline"></div>
                </div>
            </div>

        </div>
    </div>
</aside>

</div><!-- /study-layout -->

<div class="chart-tooltip" id="chart-tooltip"></div>

<!-- ── Stop / log-on-stop modal ────────────────────────────────────────────── -->
<div id="stop-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3>Log this study session?</h3>
        <p class="muted" id="stop-modal-sub">You studied <strong id="stop-modal-time">0m</strong>. Pick a module to save it, or discard it.</p>

        <label for="stop-module-select">Module</label>
        <select id="stop-module-select">
            <?php foreach ($modules as $m): ?>
                <option value="<?= htmlspecialchars($m["name"]) ?>">
                    <?= htmlspecialchars($m["name"]) ?><?= $m["custom"] ? "  (custom)" : "" ?>
                </option>
            <?php endforeach; ?>
            <option value="__new__">+ Add new module…</option>
        </select>
        <div class="new-module-wrap" id="stop-new-module-wrap">
            <input type="text" id="stop-new-module-input" maxlength="255" placeholder="New module name">
            <p class="module-hint">This module will join the shared list for everyone.</p>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="stop-cancel">Cancel</button>
            <button type="button" class="btn-danger" id="stop-discard">Discard</button>
            <button type="button" class="btn-primary" id="stop-log">Log session</button>
        </div>
    </div>
</div>

<script>
(function () {
    const BASE_PATH   = "<?= BASE_PATH ?>";
    const MY_USERNAME = <?= json_encode($_SESSION["username"] ?? "") ?>;
    const MODULES          = <?= json_encode($modules) ?>;
    const INITIAL_STATUS   = <?= json_encode($initialStatus) ?>;
    const INITIAL_SESSIONS = <?= json_encode($initialSessions) ?>;

    // Two distinct palettes: outlines for users, fills for modules.
    // Fixed colours for the regulars; everyone else draws from the palette.
    const FIXED_USER_COLORS = { basti: "#22c55e", ben: "#ef4444", roman: "#8b5cf6", lorenz: "#eab308" };
    const USER_COLORS = [
        "#f472b6", "#f59e0b", "#22d3ee", "#a3e635",
        "#fb7185", "#c084fc", "#facc15", "#2dd4bf",
    ];
    const MODULE_COLORS = [
        "#8b5cf6", "#3b82f6", "#34d399", "#fbbf24",
        "#ef4444", "#ec4899", "#14b8a6", "#f97316",
        "#a855f7", "#06b6d4", "#84cc16", "#eab308",
        "#6366f1", "#10b981", "#d946ef", "#f43f5e",
    ];

    // ── Date helpers (all local time) ─────────────────────────────────────
    function startOfDay(d) { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; }
    function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
    function toDateStr(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");
        return `${y}-${m}-${day}`;
    }
    function mondayOf(d) {
        const x = startOfDay(d);
        const dow = (x.getDay() + 6) % 7; // 0 = Monday
        return addDays(x, -dow);
    }

    function fmtTime(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        if (h > 0) return m > 0 ? `${h}h ${m}m` : `${h}h`;
        if (m > 0) return `${m}m`;
        return `${seconds}s`;
    }
    function fmtClock(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        const mm = String(m).padStart(2, "0");
        const ss = String(s).padStart(2, "0");
        return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
    }

    // ── Color maps ────────────────────────────────────────────────────────
    const moduleColor = {};
    MODULES.forEach((m, i) => { moduleColor[m.name] = MODULE_COLORS[i % MODULE_COLORS.length]; });
    const moduleCustom = {};
    MODULES.forEach(m => { moduleCustom[m.name] = !!m.custom; });

    // User colors assigned once from all usernames seen (stable across weeks).
    const userColor = {};
    // Colour for any username — fixed regulars first, otherwise a deterministic
    // palette pick from the name (so it's stable across renders and refreshes,
    // and works for people studying who have no logged sessions yet). Cached.
    function colorFor(username) {
        if (userColor[username]) return userColor[username];
        const fixed = FIXED_USER_COLORS[(username || "").toLowerCase()];
        if (fixed) return (userColor[username] = fixed);
        let h = 0;
        for (let i = 0; i < username.length; i++) h = (h * 31 + username.charCodeAt(i)) >>> 0;
        return (userColor[username] = USER_COLORS[h % USER_COLORS.length]);
    }
    function assignUserColors(usernames) {
        usernames.forEach(colorFor);
    }

    // ── State ─────────────────────────────────────────────────────────────
    let SESSIONS = INITIAL_SESSIONS || [];  // [{username, module, date, seconds, at_library}]
    let weekOffset = 0;    // 0 = current week, -1 = previous, ...
    let podiumPeriod = "overall"; // "overall" | "weekly" | "daily"
    let libraryOnly  = false;     // when true, podium counts only at-library sessions

    function filteredSessions() {
        let out = SESSIONS;
        if (podiumPeriod !== "overall") {
            const todayStr = toDateStr(startOfDay(new Date()));
            if (podiumPeriod === "daily") {
                out = out.filter(s => s.date === todayStr);
            } else {
                // weekly: Monday of this week → today
                const weekStartStr = toDateStr(mondayOf(new Date()));
                out = out.filter(s => s.date >= weekStartStr && s.date <= todayStr);
            }
        }
        if (libraryOnly) out = out.filter(s => s.at_library);
        return out;
    }

    const plotEl    = document.getElementById("chart-plot");
    const yAxisEl   = document.getElementById("chart-yaxis");
    const emptyEl   = document.getElementById("chart-empty");
    const weekLabel = document.getElementById("week-label");
    const tooltip   = document.getElementById("chart-tooltip");

    // ── Tooltip ───────────────────────────────────────────────────────────
    function showTooltip(html, x, y) {
        tooltip.innerHTML = html;
        tooltip.classList.add("show");
        const pad = 14;
        let left = x + pad, top = y + pad;
        const r = tooltip.getBoundingClientRect();
        if (left + r.width > window.innerWidth) left = x - r.width - pad;
        if (top + r.height > window.innerHeight) top = y - r.height - pad;
        tooltip.style.left = left + "px";
        tooltip.style.top  = top + "px";
    }
    function hideTooltip() { tooltip.classList.remove("show"); }

    // ── Chart render ──────────────────────────────────────────────────────
    function renderChart() {
        const weekStart = addDays(mondayOf(new Date()), weekOffset * 7);
        const weekDays = [];
        for (let i = 0; i < 7; i++) weekDays.push(addDays(weekStart, i));
        const todayStr = toDateStr(startOfDay(new Date()));

        // Week label
        const wkEnd = weekDays[6];
        const opts = { month: "short", day: "numeric" };
        if (weekOffset === 0) {
            weekLabel.textContent = "This week";
        } else {
            weekLabel.textContent =
                `${weekStart.toLocaleDateString(undefined, opts)} – ${wkEnd.toLocaleDateString(undefined, opts)}`;
        }
        document.getElementById("week-next").disabled = weekOffset >= 0;

        // Aggregate: date -> user -> { module -> seconds, total }
        const byDay = {};
        weekDays.forEach(d => { byDay[toDateStr(d)] = {}; });

        SESSIONS.forEach(s => {
            if (!(s.date in byDay)) return;
            const day = byDay[s.date];
            if (!day[s.username]) day[s.username] = { total: 0, mods: {} };
            day[s.username].mods[s.module] = (day[s.username].mods[s.module] || 0) + s.seconds;
            day[s.username].total += s.seconds;
        });

        // Scale: round max up to a sensible hour boundary
        let maxTotal = 0;
        Object.values(byDay).forEach(day =>
            Object.values(day).forEach(u => { if (u.total > maxTotal) maxTotal = u.total; }));

        const hasData = maxTotal > 0;
        emptyEl.style.display = hasData ? "none" : "flex";

        // Axis top: at least 1h, rounded up to the next hour.
        const axisTop = Math.max(3600, Math.ceil(maxTotal / 3600) * 3600);

        // Y axis ticks
        yAxisEl.innerHTML = "";
        plotEl.querySelectorAll(".grid-line").forEach(el => el.remove());
        const hours = axisTop / 3600;
        const tickStep = hours <= 6 ? 1 : Math.ceil(hours / 6);
        for (let h = 0; h <= hours; h += tickStep) {
            const frac = h / hours; // 0 bottom .. 1 top
            const tick = document.createElement("div");
            tick.className = "y-tick";
            tick.style.bottom = (frac * 100) + "%";
            tick.textContent = h + "h";
            yAxisEl.appendChild(tick);

            const line = document.createElement("div");
            line.className = "grid-line";
            line.style.bottom = (frac * 100) + "%";
            plotEl.appendChild(line);
        }

        // Day columns
        // Remove previous day columns (keep grid lines)
        plotEl.querySelectorAll(".day-col").forEach(el => el.remove());
        const xAxis = document.getElementById("chart-xaxis");
        xAxis.innerHTML = "";

        weekDays.forEach(d => {
            const dateStr = toDateStr(d);
            const col = document.createElement("div");
            col.className = "day-col" + (dateStr === todayStr ? " is-today" : "");

            const bars = col; // bars stack directly inside the day column

            const dayUsers = byDay[dateStr];
            // Stable user order
            Object.keys(dayUsers).sort().forEach(username => {
                const u = dayUsers[username];
                const bar = document.createElement("div");
                bar.className = "user-bar";
                bar.style.setProperty("--user-color", userColor[username] || "#8b5cf6");
                bar.style.height = ((u.total / axisTop) * 100) + "%";

                // Total label above the bar
                const totalLabel = document.createElement("span");
                totalLabel.className = "bar-total";
                totalLabel.textContent = fmtTime(u.total);
                bar.appendChild(totalLabel);

                // The bar is a solid user colour; the module split is in the
                // tooltip (and the Per-module podium), not stacked in the bar.
                const segs = Object.entries(u.mods).sort((a, b) => b[1] - a[1]);

                // Tooltip listeners on the bar (lists all modules)
                bar.addEventListener("mousemove", (e) => {
                    const rows = segs.map(([mod, secs]) =>
                        `<div class="tt-mod"><span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${moduleColor[mod] || "#64748b"};margin-right:6px;"></span>${escapeHtml(mod)} · <span class="tt-time">${fmtTime(secs)}</span></div>`
                    ).join("");
                    showTooltip(
                        `<div class="tt-user">${escapeHtml(username)} — ${fmtTime(u.total)}</div>${rows}`,
                        e.clientX, e.clientY
                    );
                });
                bar.addEventListener("mouseleave", hideTooltip);

                bars.appendChild(bar);
            });

            plotEl.appendChild(col);

            const label = document.createElement("div");
            label.className = "day-label" + (dateStr === todayStr ? " is-today" : "");
            label.innerHTML = d.toLocaleDateString(undefined, { weekday: "short" }) +
                "<br>" + d.getDate();
            xAxis.appendChild(label);
        });
    }

    // ── Podiums ───────────────────────────────────────────────────────────
    function userTotals() {
        const totals = {};
        SESSIONS.forEach(s => { totals[s.username] = (totals[s.username] || 0) + s.seconds; });
        return totals;
    }

    const PERIOD_LABELS = { overall: "total studied", weekly: "this week", daily: "today" };

    function buildOverallPodium() {
        const sessions = filteredSessions();
        const totals = {};
        sessions.forEach(s => { totals[s.username] = (totals[s.username] || 0) + s.seconds; });
        const ranked = Object.entries(totals).sort((a, b) => b[1] - a[1]);
        const podium = document.getElementById("overall-podium");
        const empty  = document.getElementById("overall-empty");

        if (ranked.length === 0) {
            podium.style.display = "none";
            empty.style.display = "block";
            return;
        }
        empty.style.display = "none";

        const sublabel = (PERIOD_LABELS[podiumPeriod] || "total studied") + (libraryOnly ? " · at the library" : "");
        const top = ranked.slice(0, 3);
        podium.innerHTML = top.map(([username, secs], i) => {
            const rank = i + 1;
            const color = userColor[username] || "#8b5cf6";
            const style = `border-color:${color}; background:linear-gradient(160deg, ${color}24, ${color}0a); box-shadow:0 10px 28px ${color}33;`;
            return `
                <div class="podium-card glass-card rank-${rank}" style="${style}">
                    <span class="podium-medal">${rank}</span>
                    <div class="podium-name">${escapeHtml(username)}</div>
                    <div class="podium-score" style="color:${color}">${fmtTime(secs)}</div>
                    <div class="podium-sub podium-sub-time">${sublabel}</div>
                </div>
            `;
        }).join("");
        podium.style.display = "flex";
    }

    function buildModulePodiums() {
        // module -> user -> seconds
        const sessions = filteredSessions();
        const byModule = {};
        sessions.forEach(s => {
            if (!byModule[s.module]) byModule[s.module] = {};
            byModule[s.module][s.username] = (byModule[s.module][s.username] || 0) + s.seconds;
        });

        const grid  = document.getElementById("module-podium-grid");
        const empty = document.getElementById("modules-empty");

        const moduleNames = Object.keys(byModule);
        if (moduleNames.length === 0) {
            grid.innerHTML = "";
            empty.style.display = "block";
            return;
        }
        empty.style.display = "none";

        // Order modules by total time studied (desc)
        moduleNames.sort((a, b) => {
            const ta = Object.values(byModule[a]).reduce((x, y) => x + y, 0);
            const tb = Object.values(byModule[b]).reduce((x, y) => x + y, 0);
            return tb - ta;
        });

        grid.innerHTML = moduleNames.map(mod => {
            const ranked = Object.entries(byModule[mod]).sort((a, b) => b[1] - a[1]).slice(0, 3);
            const color = moduleColor[mod] || "#64748b";
            const customBadge = moduleCustom[mod] ? `<span class="mp-custom">custom</span>` : "";
            const rows = ranked.map(([username, secs], i) => `
                <div class="mp-row">
                    <span class="mp-rank r${i + 1}">${i + 1}</span>
                    <span class="mp-name">${escapeHtml(username)}</span>
                    <span class="mp-time">${fmtTime(secs)}</span>
                </div>
            `).join("");
            return `
                <div class="module-podium glass-card">
                    <h3><span class="module-dot" style="background:${color}"></span>${escapeHtml(mod)}${customBadge}</h3>
                    ${rows}
                </div>
            `;
        }).join("");
    }

    // ── Legend (users only — modules now live in the bar tooltips) ─────────
    function buildLegends() {
        const usersSeen = Object.keys(userTotals()).sort();
        const lu = document.getElementById("legend-users");
        lu.innerHTML = usersSeen.length === 0
            ? `<span class="muted">—</span>`
            : usersSeen.map(u => `
                <span class="legend-item">
                    <span class="legend-swatch" style="background:${userColor[u] || "#8b5cf6"}"></span>
                    ${escapeHtml(u)}
                </span>
            `).join("");
    }

    // ── Podium toggle (fade) ──────────────────────────────────────────────
    let showingModules = false;
    const toggleBtn   = document.getElementById("podium-toggle");
    const viewOverall = document.getElementById("podium-overall");
    const viewModules = document.getElementById("podium-modules");

    function switchPodiumView(toModules) {
        const current = showingModules ? viewModules : viewOverall;
        const next    = toModules      ? viewModules : viewOverall;
        if (current === next) return;
        current.classList.add("fade-out");
        setTimeout(() => {
            current.style.display = "none";
            next.style.display = "block";
            requestAnimationFrame(() => next.classList.remove("fade-out"));
        }, 280);
        showingModules = toModules;
        toggleBtn.classList.toggle("active", showingModules);
    }

    toggleBtn.addEventListener("click", () => {
        switchPodiumView(!showingModules);
        if (showingModules) buildModulePodiums(); else buildOverallPodium();
    });

    // Library-only filter — applies on top of the period selection. Re-render
    // whichever podium view (overall/per-module) is currently visible.
    const libraryToggle = document.getElementById("library-toggle");
    libraryToggle.addEventListener("click", () => {
        libraryOnly = !libraryOnly;
        libraryToggle.classList.toggle("active", libraryOnly);
        if (showingModules) buildModulePodiums(); else buildOverallPodium();
    });

    // ── Period tabs ───────────────────────────────────────────────────────
    document.querySelectorAll(".period-tab[data-period]").forEach(tab => {
        tab.addEventListener("click", () => {
            if (tab.dataset.period === podiumPeriod) return;
            document.querySelectorAll(".period-tab[data-period]").forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            podiumPeriod = tab.dataset.period;
            if (showingModules) buildModulePodiums(); else buildOverallPodium();
        });
    });

    // ── Data load + full render ───────────────────────────────────────────
    function renderAll() {
        assignUserColors(Object.keys(userTotals()));
        buildOverallPodium();
        buildModulePodiums();
        buildLegends();
        renderChart();
    }

    function loadData() {
        return fetch(BASE_PATH + "/api/get-study-data.php")
            .then(r => { if (!r.ok) throw new Error("Failed to load study data."); return r.json(); })
            .then(data => {
                SESSIONS = data.sessions || [];
                renderAll();
            })
            .catch(() => {
                emptyEl.textContent = "Could not load study data.";
                emptyEl.style.display = "flex";
            });
    }

    // ── Week navigation ───────────────────────────────────────────────────
    document.getElementById("week-prev").addEventListener("click", () => {
        weekOffset -= 1;
        renderChart();
    });
    document.getElementById("week-next").addEventListener("click", () => {
        if (weekOffset >= 0) return;
        weekOffset += 1;
        renderChart();
    });

    // ── Module picker (reveal new-module input) ───────────────────────────
    const moduleSelect = document.getElementById("module-select");
    const newWrap      = document.getElementById("new-module-wrap");
    const newInput     = document.getElementById("new-module-input");

    moduleSelect.addEventListener("change", () => {
        const isNew = moduleSelect.value === "__new__";
        newWrap.classList.toggle("show", isNew);
        if (isNew) newInput.focus();
    });

    // No default modules yet → the only option is "+ Add new module…".
    if (moduleSelect.value === "__new__") newWrap.classList.add("show");

    function selectedModulePayload() {
        if (moduleSelect.value === "__new__") {
            const name = newInput.value.trim();
            if (name === "") return { error: "Enter a name for the new module." };
            return { new_module: name };
        }
        return { module: moduleSelect.value };
    }

    // ── Feedback ──────────────────────────────────────────────────────────
    const feedback = document.getElementById("log-feedback");
    function setFeedback(msg, isError) {
        feedback.textContent = msg;
        feedback.classList.toggle("error", !!isError);
    }

    // opts: { startTime, endTime } for exact mode, or { seconds, studiedOn }.
    function submitSession(opts) {
        const mod = selectedModulePayload();
        if (mod.error) { setFeedback(mod.error, true); return; }

        const exact = opts.startTime && opts.endTime;
        const body = new FormData();
        if (exact) {
            body.append("start_time", opts.startTime);
            body.append("end_time", opts.endTime);
        } else {
            if (!opts.seconds || opts.seconds <= 0) { setFeedback("Nothing to log yet.", true); return; }
            body.append("seconds", opts.seconds);
            if (opts.studiedOn) body.append("studied_on", opts.studiedOn);
        }
        if (mod.module)     body.append("module", mod.module);
        if (mod.new_module) body.append("new_module", mod.new_module);

        setFeedback("Saving…", false);
        fetch(BASE_PATH + "/api/log-study-session.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Save failed."); }))
            .then(res => {
                setFeedback(`Logged ${fmtTime(res.seconds)} of ${res.module}.`, false);
                if (opts.onDone) opts.onDone();
                // A new custom module changes the shared list — reload to pick up
                // colors, legend, and the dropdown entry.
                if (res.custom && moduleSelect.value === "__new__") {
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    loadData();
                }
            })
            .catch(err => setFeedback(err.message, true));
    }

    // ── Mode tabs ─────────────────────────────────────────────────────────
    document.querySelectorAll(".mode-tab").forEach(tab => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".mode-tab").forEach(t => t.classList.remove("active"));
            document.querySelectorAll(".mode-pane").forEach(p => p.classList.remove("active"));
            tab.classList.add("active");
            document.getElementById("pane-" + tab.dataset.mode).classList.add("active");
        });
    });

    // ── Timer (server-backed & persistent) ────────────────────────────────
    // The stopwatch lives in the `study_status` table, so closing or reloading
    // the page never stops it — we just re-sync from the server.
    const timerDisplay = document.getElementById("timer-display");
    const timerNote    = document.getElementById("timer-note");
    const timerBreak   = document.getElementById("timer-break");
    const startBtn     = document.getElementById("timer-start");
    const resetBtn     = document.getElementById("timer-reset");
    const logBtn       = document.getElementById("timer-log");

    // Mirrors the server's study_status row for me.
    let myState = { active: false, mode: null, running: false, elapsed: 0, breakElapsed: 0, module: null, atLibrary: false };
    let ticker  = null;

    function manageTicker() {
        clearInterval(ticker);
        ticker = null;
        if (!myState.active) return;
        ticker = setInterval(() => {
            if (myState.running) {
                myState.elapsed += 1; // advances for any mode (keeps my chip live)
                // The big display only reflects the loggable module timer.
                if (myState.mode === "timer") timerDisplay.textContent = fmtClock(myState.elapsed);
            } else {
                // On break — the study time is frozen, the break time ticks up.
                myState.breakElapsed += 1;
                renderBreakLine();
            }
        }, 1000);
    }

    // The break line under the big display (module timer only — a presence
    // break is shown in the panel chip instead).
    function renderBreakLine() {
        const onBreak = myState.active && !myState.running && myState.mode === "timer";
        if (onBreak) {
            timerBreak.style.display = "";
            timerBreak.innerHTML = `☕ On break · <span class="tb-time">${fmtClock(myState.breakElapsed)}</span>`;
        } else {
            timerBreak.style.display = "none";
        }
    }

    function renderTimerUI() {
        const timing = myState.active && myState.mode === "timer";
        timerDisplay.textContent = fmtClock(timing ? myState.elapsed : 0);

        if (timing && myState.running) {
            startBtn.textContent = "Pause";
            timerNote.innerHTML = `<span class="tn-live">● Running</span> · <span class="tn-mod">${escapeHtml(myState.module || "")}</span>`;
        } else if (timing) {
            startBtn.textContent = "Resume";
            timerNote.innerHTML = `Paused · <span class="tn-mod">${escapeHtml(myState.module || "")}</span>`;
        } else {
            startBtn.textContent = "Start";
            timerNote.textContent = "Keeps running even if you close the page.";
        }

        renderBreakLine();
    }

    // Apply the `me` object returned by any status endpoint.
    function applyMyState(me) {
        myState = {
            active:       !!me.active,
            mode:         me.mode || null,
            running:      !!me.running,
            elapsed:      me.elapsed || 0,
            breakElapsed: me.break_elapsed || 0,
            module:       me.module || null,
            atLibrary:    !!me.at_library,
        };
        renderTimerUI();
        manageTicker();
        renderPresenceButtons();
    }

    function timerAction(action, extra) {
        const body = new FormData();
        body.append("action", action);
        if (extra) {
            if (extra.module)     body.append("module", extra.module);
            if (extra.new_module) body.append("new_module", extra.new_module);
        }
        return fetch(BASE_PATH + "/api/study-timer.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Timer error."); }))
            .then(res => {
                // A brand-new custom module changes the shared list — reload so the
                // dropdown, colors and legend pick it up. The timer is persistent,
                // so it simply resumes after the reload.
                if (res.custom_added) setTimeout(() => window.location.reload(), 400);
                applyMyState(res.me);
                renderStudying(res.studying);
                setRecaps(res);
                return res;
            });
    }

    startBtn.addEventListener("click", () => {
        if (myState.active && myState.mode === "timer" && myState.running) {
            timerAction("pause").catch(err => setFeedback(err.message, true));
            return;
        }
        // Resume a paused module timer, or start a fresh one (needs a module).
        const extra = {};
        if (!(myState.active && myState.mode === "timer")) {
            const mod = selectedModulePayload();
            if (mod.error) { setFeedback(mod.error, true); return; }
            Object.assign(extra, mod);
        }
        timerAction("start", extra).catch(err => setFeedback(err.message, true));
    });

    resetBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction("reset").catch(err => setFeedback(err.message, true));
    });

    logBtn.addEventListener("click", () => {
        if (!myState.active || myState.mode !== "timer") {
            setFeedback("Start the timer first.", true);
            return;
        }
        setFeedback("Saving…", false);
        timerAction("log")
            .then(res => {
                if (res.logged) {
                    setFeedback(`Logged ${fmtTime(res.seconds)} of ${res.module}.`, false);
                    loadData();
                } else {
                    setFeedback("Nothing to log yet.", true);
                }
            })
            .catch(err => setFeedback(err.message, true));
    });

    // ── Currently studying ────────────────────────────────────────────────
    const studyingList   = document.getElementById("studying-list");
    const toggleStudyBtn = document.getElementById("studying-toggle");
    const breakBtn       = document.getElementById("studying-break");
    const breakBox       = document.getElementById("studying-break-box");
    const breakList      = document.getElementById("break-list");
    const bibBtn         = document.getElementById("studying-bib");
    const libraryBox     = document.getElementById("studying-library-box");
    const libraryList    = document.getElementById("library-list");

    // ── Day timeline ──────────────────────────────────────────────────────────
    // Recap blocks are one-per-study-interval, positioned in minutes from the
    // study day's midnight (server-anchored). The study day runs 07:00 → 06:00
    // the next morning, so post-midnight work lands in the 24:00–30:00 tail.
    // Breaks show as the gaps between a session's blocks.
    let lastRecap = [];
    // recapByDay[0] = today's study day, recapByDay[1] = yesterday's. recapOffset
    // selects which the timeline shows (toggled by the Heute / Gestern buttons).
    let recapByDay  = { 0: [], 1: [] };
    let recapOffset = 0;
    // Piecewise axis built each render: only active hours occupy space, runs of
    // empty hours collapse. Shared with the per-second ticker via tlLayout.
    let tlLayout  = { hourTop: {}, hourPx: 32, totalPx: 0, lastActiveHour: -1 };

    // Store both days from a status payload, then repaint the selected one.
    function setRecaps(payload) {
        recapByDay[0] = payload.recap      || [];
        recapByDay[1] = payload.recap_prev || [];
        renderTimeline();
    }

    // The study day runs 07:00 → 06:00 next morning. Minutes after midnight but
    // before the start belong to the previous evening's night shift, so they map
    // to the tail of the window (e.g. 02:00 → 26:00).
    const DAY_START_MIN = 7 * 60;              // 07:00 (top of the axis)
    const DAY_END_MIN   = 30 * 60;             // 06:00 next morning (bottom of the axis)
    const toDayMin  = (m) => (m >= 0 && m < DAY_START_MIN ? m + 1440 : m);
    const clampDay  = (m) => Math.max(DAY_START_MIN, Math.min(DAY_END_MIN, m));
    const minsToHHMM = (m) => {
        m = Math.round(m);
        const h = Math.floor(m / 60) % 24, mm = m % 60;
        return String(h).padStart(2, "0") + ":" + String(mm).padStart(2, "0");
    };

    function renderTimeline() {
        const isToday = recapOffset === 0;
        lastRecap = recapByDay[recapOffset] || [];
        const tl = document.getElementById("dock-timeline");
        if (!tl) return;

        const now    = new Date();
        // The now-line / live ticking only make sense for the current day.
        const nowMin = isToday
            ? toDayMin(now.getHours() * 60 + now.getMinutes() + now.getSeconds() / 60)
            : -1;

        // Clamp every interval into the study day's window (07:00–06:00 next day);
        // a live block runs up to now.
        const blocks = [];
        lastRecap.forEach(r => {
            const startM = clampDay(toDayMin(r.start_min));
            const endM   = clampDay(r.live ? nowMin : toDayMin(r.end_min));
            if (endM - startM < 0.5) return; // nothing inside today to draw
            blocks.push({
                username: r.username,
                module:   r.module || null,
                seconds:  r.seconds,
                live:     !!r.live,
                startM, endM,
            });
        });

        // Current live presence only belongs on today's column set; yesterday's
        // view shows purely its logged blocks.
        const userSet = new Set([
            ...blocks.map(b => b.username),
            ...(isToday ? studyingEntries.map(s => s.username) : []),
            ...(isToday ? breakEntries.map(s => s.username) : []),
        ]);
        const allUsers = [...userSet].sort();

        if (allUsers.length === 0) {
            tl.innerHTML = `<p class="tl-empty">${isToday ? "Keine Sessions heute." : "Keine Sessions gestern."}</p>`;
            syncDockHeight();
            return;
        }

        assignUserColors(allUsers);

        // ── Collapsed time axis ────────────────────────────────────────────
        // Only hours that actually contain activity get drawn; runs of empty
        // hours collapse to a thin marker. The axis is piecewise: each shown
        // hour is a fixed band (HOUR_PX), each collapsed run a small strip.
        const HOUR_PX = 64;   // height of one shown hour
        const GAP_PX  = 18;   // height of a collapsed-time marker

        const activeSet = new Set();
        blocks.forEach(b => {
            const h0 = Math.floor(b.startM / 60);
            const h1 = Math.floor((b.endM - 0.0001) / 60); // last hour the block touches
            for (let h = h0; h <= h1; h++) activeSet.add(h);
        });
        // While someone is live, keep the current hour shown so the now-line and
        // the growing block have a home (even before the minute count fills it).
        if (blocks.some(b => b.live))   activeSet.add(Math.floor(nowMin / 60));
        if (activeSet.size === 0 && isToday) activeSet.add(Math.floor(nowMin / 60));

        const activeHours = [...activeSet].sort((a, b) => a - b);

        const hourTop = {};   // hour index -> y (px) of its :00 line
        const gaps    = [];   // collapsed runs: {y, fromH, toH}
        let y = 0;
        activeHours.forEach((h, i) => {
            if (i > 0 && h !== activeHours[i - 1] + 1) {
                gaps.push({ y, fromH: activeHours[i - 1] + 1, toH: h });
                y += GAP_PX;
            }
            hourTop[h] = y;
            y += HOUR_PX;
        });
        const totalPx        = y;
        const lastActiveHour = activeHours[activeHours.length - 1];

        // Minute -> y (px); null for minutes inside a collapsed gap.
        const yPx = (m) => {
            const h = Math.floor(m / 60);
            if (h in hourTop) return hourTop[h] + (m - h * 60) / 60 * HOUR_PX;
            // Exact bottom boundary of an active hour (e.g. a block ending at :00).
            if ((h - 1) in hourTop && Math.abs(m - h * 60) < 0.001) return hourTop[h - 1] + HOUR_PX;
            return null;
        };
        tlLayout = { hourTop, hourPx: HOUR_PX, totalPx, lastActiveHour }; // for the ticker

        const byUser = {};
        allUsers.forEach(u => { byUser[u] = []; });
        blocks.forEach(b => { if (byUser[b.username]) byUser[b.username].push(b); });

        const dayEnd = (lastActiveHour + 1) * 60;
        const nowY   = yPx(Math.min(nowMin, dayEnd));

        // A label/line lives at every shown :00, at each run's end boundary
        // (the top of a collapse marker), and at the final closing boundary.
        const tick = (yp, m) => `<div class="tl-tick" style="top:${yp}px"><span class="tl-tick-label">${minsToHHMM(m)}</span></div>`;
        const tickRows =
            activeHours.map(h => tick(hourTop[h], h * 60)).join("") +
            gaps.map(g => tick(g.y, g.fromH * 60)).join("") +
            tick(totalPx, dayEnd);

        const grid = (yp) => `<div class="tl-grid-line" style="top:${yp}px"></div>`;
        const gridRows =
            activeHours.map(h => grid(hourTop[h])).join("") +
            gaps.map(g => grid(g.y)).join("") +
            grid(totalPx);

        const gapRows = gaps.map(g =>
            `<div class="tl-gap" style="top:${g.y}px;height:${GAP_PX}px" title="${minsToHHMM(g.fromH * 60)}–${minsToHHMM(g.toH * 60)} ausgeblendet"><span class="tl-gap-mark">⋯</span></div>`
        ).join("");

        const nowLine = (!isToday || nowY === null) ? "" :
            `<div class="tl-now-line" id="tl-now-line" style="top:${nowY}px"><span class="tl-now-dot"></span></div>`;

        tl.innerHTML = `
            <div class="tl-user-heads">
                ${allUsers.map(u =>
                    `<div class="tl-col-head" style="color:${userColor[u] || "#8b5cf6"}">${escapeHtml(u)}</div>`
                ).join("")}
            </div>
            <div class="tl-body" style="height:${totalPx + 9}px">
                <div class="tl-axis">${tickRows}</div>
                <div class="tl-cols-wrap">
                    ${gridRows}
                    ${gapRows}
                    ${nowLine}
                    <div class="tl-cols">
                        ${allUsers.map(u => {
                            return `<div class="tl-col">${(byUser[u] || []).map(s => {
                                // Colour by module (the column header already identifies the user).
                                const color    = moduleColor[s.module] || "#64748b";
                                const top      = yPx(s.startM);
                                const bottom   = yPx(Math.min(s.endM, dayEnd));
                                const blockH   = Math.max((bottom ?? top) - top, 3);
                                const liveAttr = s.live ? ` data-live-start="${s.startM}"` : "";
                                const tip = (s.module ? escapeHtml(s.module) + " · " : "") + fmtTime(s.seconds);
                                return `<div class="tl-block ${s.live ? "live" : ""}"${liveAttr}
                                     style="top:${top}px;height:${blockH}px;background:${color};"
                                     title="${tip}">${s.module ? `<span class="tl-block-label">${escapeHtml(s.module)}</span>` : ""}</div>`;
                            }).join("")}</div>`;
                        }).join("")}
                    </div>
                </div>
            </div>
        `;
        syncDockHeight();
    }

    function tickTimeline() {
        if (recapOffset !== 0) return; // yesterday's timeline is static
        const live = document.querySelectorAll(".tl-block[data-live-start]");
        const nowLine = document.getElementById("tl-now-line");
        if (live.length === 0 && !nowLine) return;

        const now = new Date();
        const nowMin = toDayMin(now.getHours() * 60 + now.getMinutes() + now.getSeconds() / 60);
        const nowHour = Math.floor(nowMin / 60);
        // A live block grew into a not-yet-shown hour → re-layout to add it.
        if (live.length && !(nowHour in tlLayout.hourTop)) { renderTimeline(); return; }

        const yPx = (m) => {
            const h = Math.floor(m / 60);
            if (h in tlLayout.hourTop) return tlLayout.hourTop[h] + (m - h * 60) / 60 * tlLayout.hourPx;
            if ((h - 1) in tlLayout.hourTop && Math.abs(m - h * 60) < 0.001) return tlLayout.hourTop[h - 1] + tlLayout.hourPx;
            return null;
        };

        const cap  = Math.min(nowMin, (tlLayout.lastActiveHour + 1) * 60);
        const capY = yPx(cap);
        if (nowLine && capY !== null) nowLine.style.top = capY.toFixed(1) + "px";

        live.forEach(el => {
            const top = yPx(parseFloat(el.dataset.liveStart));
            if (top !== null && capY !== null) el.style.height = Math.max(capY - top, 1).toFixed(1) + "px";
        });
    }

    // Last lists from the server + when we synced them, so chips can tick
    // locally between polls instead of sitting frozen for 15s.
    let studyingEntries = [];  // running, not at library — study time ticks
    let breakEntries    = [];  // paused, not at library — break time ticks
    let libraryEntries  = [];  // at library (any state)    — both can tick
    let studyingSyncTs  = Date.now();

    function renderPresenceButtons() {
        const active  = myState.active;
        const running = active && myState.running;

        // Primary button: start a quick session / stop a presence session.
        // (A module timer is started & stopped from the log-a-session window.)
        if (!active) {
            toggleStudyBtn.style.display = "";
            toggleStudyBtn.classList.remove("active");
            toggleStudyBtn.textContent = "I'm studying";
        } else if (myState.mode === "presence") {
            toggleStudyBtn.style.display = "";
            toggleStudyBtn.classList.add("active");
            toggleStudyBtn.textContent = "Stop";
        } else {
            toggleStudyBtn.style.display = "none";
        }

        // Break / resume button — works for both presence and module timers.
        if (running) {
            breakBtn.style.display = "";
            breakBtn.textContent = "I'm on break";
        } else if (active) {
            breakBtn.style.display = "";
            breakBtn.textContent = "Resume";
        } else {
            breakBtn.style.display = "none";
        }

        // BIB — independent flag, only shown while actively studying.
        if (active) {
            bibBtn.style.display = "";
            bibBtn.classList.toggle("active", !!myState.atLibrary);
            bibBtn.textContent = myState.atLibrary ? "📚 At library" : "📚 BIB";
        } else {
            bibBtn.style.display = "none";
            bibBtn.classList.remove("active");
        }
    }

    function moduleSuffix(s) {
        return (s.mode === "timer" && s.module) ? " · " + escapeHtml(s.module) : "";
    }

    function renderStudying(list) {
        list = list || [];
        // BIB takes precedence as a section — at-library people land in the
        // library box regardless of running/break, so they don't appear twice.
        const library = list.filter(s => s.at_library);
        const running = list.filter(s => !s.at_library && s.running);
        const paused  = list.filter(s => !s.at_library && !s.running);

        // Indices match the data-* markup used by tickStudying().
        studyingEntries = running;
        breakEntries    = paused;
        libraryEntries  = library;
        studyingSyncTs  = Date.now();

        if (running.length === 0) {
            // Avoid the misleading "nobody studying" line when people are
            // actually present in the break or library sections below.
            studyingList.innerHTML = (paused.length + library.length) > 0
                ? `<span class="muted">—</span>`
                : `<span class="muted">Nobody's studying right now.</span>`;
        } else {
            studyingList.innerHTML = running.map((s, i) => {
                const isMe = s.username === MY_USERNAME;
                return `
                    <span class="studying-chip ${isMe ? "me" : ""}">
                        <span class="pulse-dot"></span>
                        <span class="chip-name" style="color:${colorFor(s.username)}">${escapeHtml(s.username)}</span>
                        <span class="chip-meta"><span class="chip-time" data-idx="${i}">${fmtClock(s.elapsed)}</span>${moduleSuffix(s)}</span>
                    </span>
                `;
            }).join("");
        }

        if (paused.length === 0) {
            breakBox.style.display = "none";
            breakList.innerHTML = "";
        } else {
            breakBox.style.display = "";
            breakList.innerHTML = paused.map((s, i) => {
                const isMe = s.username === MY_USERNAME;
                // Study time is frozen; the break time (.chip-break) ticks up.
                return `
                    <span class="studying-chip break ${isMe ? "me" : ""}">
                        <span class="pulse-dot paused"></span>
                        <span class="chip-name" style="color:${colorFor(s.username)}">${escapeHtml(s.username)}</span>
                        <span class="chip-meta">${fmtClock(s.elapsed)}${moduleSuffix(s)}<span class="chip-break" data-bidx="${i}">☕ ${fmtClock(s.break_elapsed || 0)}</span></span>
                    </span>
                `;
            }).join("");
        }

        if (library.length === 0) {
            libraryBox.style.display = "none";
            libraryList.innerHTML = "";
        } else {
            libraryBox.style.display = "";
            libraryList.innerHTML = library.map((s, i) => {
                const isMe   = s.username === MY_USERNAME;
                const dotCls = s.running ? "pulse-dot" : "pulse-dot paused";
                // While paused at the library, also show a ☕ break timer.
                const breakSpan = !s.running
                    ? `<span class="chip-break" data-libbidx="${i}">☕ ${fmtClock(s.break_elapsed || 0)}</span>`
                    : "";
                return `
                    <span class="studying-chip library ${isMe ? "me" : ""}">
                        <span class="${dotCls}"></span>
                        <span class="chip-name" style="color:${colorFor(s.username)}">${escapeHtml(s.username)}</span>
                        <span class="chip-meta"><span class="chip-time" data-libidx="${i}">${fmtClock(s.elapsed)}</span>${moduleSuffix(s)}${breakSpan}</span>
                    </span>
                `;
            }).join("");
        }

        syncDockHeight();
    }

    // Tick the chips every second (re-synced to the server on each poll): study
    // time on running chips, break time on paused chips. My own chip mirrors the
    // main display exactly. Library chips can tick either side depending on
    // whether the person is running or on break.
    function tickStudying() {
        if (studyingEntries.length === 0 && breakEntries.length === 0 && libraryEntries.length === 0) return;
        const delta = Math.floor((Date.now() - studyingSyncTs) / 1000);

        studyingEntries.forEach((s, i) => {
            const el = studyingList.querySelector(`.chip-time[data-idx="${i}"]`);
            if (!el) return;
            const secs = (s.username === MY_USERNAME && myState.active && myState.running)
                ? myState.elapsed
                : s.elapsed + delta;
            el.textContent = fmtClock(secs);
        });

        breakEntries.forEach((s, i) => {
            const el = breakList.querySelector(`.chip-break[data-bidx="${i}"]`);
            if (!el) return;
            const secs = (s.username === MY_USERNAME && myState.active && !myState.running)
                ? myState.breakElapsed
                : (s.break_elapsed || 0) + delta;
            el.textContent = "☕ " + fmtClock(secs);
        });

        libraryEntries.forEach((s, i) => {
            const timeEl = libraryList.querySelector(`.chip-time[data-libidx="${i}"]`);
            if (timeEl) {
                // Study time ticks only while running; it's frozen on break.
                const secs = s.running
                    ? ((s.username === MY_USERNAME && myState.active && myState.running) ? myState.elapsed : s.elapsed + delta)
                    : s.elapsed;
                timeEl.textContent = fmtClock(secs);
            }
            if (!s.running) {
                const brEl = libraryList.querySelector(`.chip-break[data-libbidx="${i}"]`);
                if (brEl) {
                    const secs = (s.username === MY_USERNAME && myState.active && !myState.running)
                        ? myState.breakElapsed
                        : (s.break_elapsed || 0) + delta;
                    brEl.textContent = "☕ " + fmtClock(secs);
                }
            }
        });
    }

    // "I'm studying" starts a quick (module-less) timer; "Stop" asks whether to
    // log the session before clearing it.
    toggleStudyBtn.addEventListener("click", () => {
        if (!myState.active) {
            timerAction("presence").catch(err => setFeedback(err.message, true));
        } else if (myState.mode === "presence") {
            openStopModal();
        }
    });

    // "I'm on break" pauses whatever's running (presence OR the log-window
    // module timer); "Resume" starts it again.
    breakBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction(myState.running ? "pause" : "resume")
            .catch(err => setFeedback(err.message, true));
    });

    // BIB — toggle the at-library flag. Independent from running/break: you
    // can be at the library while studying, or paused at the library.
    bibBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction("library").catch(err => setFeedback(err.message, true));
    });

    // ── Stop → "log this session?" modal (split across modules) ───────────
    // ── Stop → "log this session?" modal ──────────────────────────────────
    const stopModal        = document.getElementById("stop-modal");
    const stopModuleSelect = document.getElementById("stop-module-select");
    const stopNewWrap      = document.getElementById("stop-new-module-wrap");
    const stopNewInput     = document.getElementById("stop-new-module-input");
    const stopTimeEl       = document.getElementById("stop-modal-time");

    function openStopModal() {
        stopTimeEl.textContent = fmtTime(myState.elapsed);
        // Default to the module picked in the log window, when it's a real one.
        if (moduleSelect.value && moduleSelect.value !== "__new__") {
            stopModuleSelect.value = moduleSelect.value;
        }
        stopNewInput.value = "";
        stopNewWrap.classList.toggle("show", stopModuleSelect.value === "__new__");
        stopModal.style.display = "flex";
    }

    function closeStopModal() {
        stopModal.style.display = "none";
    }

    stopModuleSelect.addEventListener("change", () => {
        const isNew = stopModuleSelect.value === "__new__";
        stopNewWrap.classList.toggle("show", isNew);
        if (isNew) stopNewInput.focus();
    });

    document.getElementById("stop-cancel").addEventListener("click", closeStopModal);
    stopModal.addEventListener("click", (e) => { if (e.target === stopModal) closeStopModal(); });

    // Discard: stop studying without recording a session.
    document.getElementById("stop-discard").addEventListener("click", () => {
        closeStopModal();
        timerAction("stop").catch(err => setFeedback(err.message, true));
    });

    // Log: record the elapsed study time against the chosen module, then clear.
    document.getElementById("stop-log").addEventListener("click", () => {
        const extra = {};
        if (stopModuleSelect.value === "__new__") {
            const name = stopNewInput.value.trim();
            if (name === "") { stopNewInput.focus(); return; }
            extra.new_module = name;
        } else if (stopModuleSelect.value) {
            extra.module = stopModuleSelect.value;
        } else {
            stopNewInput.focus();
            return;
        }

        closeStopModal();
        setFeedback("Saving…", false);
        timerAction("log", extra)
            .then(res => {
                if (res.logged) {
                    setFeedback(`Logged ${fmtTime(res.seconds)} of ${res.module}.`, false);
                    loadData();
                } else {
                    setFeedback("Session stopped — nothing to log.", false);
                }
            })
            .catch(err => setFeedback(err.message, true));
    });

    function loadStatus() {
        return fetch(BASE_PATH + "/api/get-study-status.php")
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(res => { applyMyState(res.me); renderStudying(res.studying); setRecaps(res); })
            .catch(() => { /* keep previous state on a transient error */ });
    }

    // ── Dock flip: "currently studying" (front) ⇄ today's timeline (back) ──
    // The card height tracks whichever face is showing, so the bottom edge
    // retracts/extends smoothly (CSS height transition) when the faces differ.
    const flipInner = document.getElementById("studying-flip");
    const dockCard  = document.querySelector(".dock-card");
    const frontFace = dockCard.querySelector(".flip-front");
    const backFace  = dockCard.querySelector(".flip-back");

    let dockHeightInit = false;
    function syncDockHeight() {
        const face = flipInner.classList.contains("flipped") ? backFace : frontFace;
        const h = face.offsetHeight + "px";
        if (!dockHeightInit) {
            // First measure: apply instantly so the card doesn't animate up from
            // zero on page load. Subsequent changes use the CSS height transition.
            dockCard.style.transition = "none";
            dockCard.style.height = h;
            void dockCard.offsetHeight; // force reflow
            dockCard.style.transition = "";
            dockHeightInit = true;
        } else {
            dockCard.style.height = h;
        }
    }

    function flipDock(showBack) {
        flipInner.classList.toggle("flipped", showBack);
        // Front = sticky; back = in-flow so a tall recap can scroll down the page.
        dockCard.classList.toggle("recap", showBack);
        syncDockHeight();
    }

    document.getElementById("flip-to-recap").addEventListener("click", () => flipDock(true));
    document.getElementById("flip-to-front").addEventListener("click", () => flipDock(false));
    window.addEventListener("resize", syncDockHeight);

    // Heute / Gestern toggle: switch which study day the timeline shows.
    document.querySelectorAll(".recap-day-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            recapOffset = parseInt(btn.dataset.recap, 10) || 0;
            document.querySelectorAll(".recap-day-btn")
                .forEach(b => b.classList.toggle("active", b === btn));
            renderTimeline();
        });
    });

    // Tick the studying chips locally every second; re-sync from the server
    // every 15s (covers other tabs and other users).
    setInterval(() => { tickStudying(); tickTimeline(); }, 1000);
    setInterval(loadStatus, 15000);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) loadStatus();
    });
    // Restored from the back/forward cache: timers were frozen, so re-sync.
    window.addEventListener("pageshow", (e) => {
        if (e.persisted) loadStatus();
    });

    // ── Manual ────────────────────────────────────────────────────────────
    const manualDate  = document.getElementById("manual-date");
    const manualPane  = document.getElementById("pane-manual");
    const manualToday = document.getElementById("manual-today");
    const manualStart = document.getElementById("manual-start");
    const manualEnd   = document.getElementById("manual-end");
    const todayStr    = toDateStr(startOfDay(new Date()));
    manualDate.value  = todayStr;
    manualDate.max    = todayStr;

    const fmtHM = (d) => String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");

    // "Today" → exact start/end + date locked to today; otherwise duration + date.
    function applyManualMode() {
        const today = manualToday.checked;
        manualPane.classList.toggle("today-mode", today);
        manualDate.disabled = today;
        if (today) {
            manualDate.value = todayStr;
            if (!manualEnd.value)   manualEnd.value   = fmtHM(new Date());
            if (!manualStart.value) manualStart.value = fmtHM(new Date(Date.now() - 30 * 60000));
        }
    }
    manualToday.addEventListener("change", applyManualMode);
    applyManualMode();

    document.getElementById("manual-log").addEventListener("click", () => {
        if (manualToday.checked) {
            if (!manualStart.value || !manualEnd.value) { setFeedback("Enter a start and end time.", true); return; }
            submitSession({ startTime: manualStart.value, endTime: manualEnd.value });
        } else {
            const h = parseInt(document.getElementById("manual-hours").value, 10) || 0;
            const m = parseInt(document.getElementById("manual-minutes").value, 10) || 0;
            submitSession({ seconds: h * 3600 + m * 60, studiedOn: manualDate.value || null });
        }
    });

    // ── Go ────────────────────────────────────────────────────────────────
    // Paint synchronously from the server-embedded state — no fetch, no flash.
    // The 15s poll / visibilitychange / pageshow handlers keep it fresh after.
    renderAll();
    applyMyState(INITIAL_STATUS.me);
    renderStudying(INITIAL_STATUS.studying);
    setRecaps(INITIAL_STATUS);
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
