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

// Each session is charted on the study day it started in (see studySessionsByDay).
$initialSessions = studySessionsByDay($pdo);

// username -> avatar (base64 data URL), for the podium profile pictures.
$userAvatars   = [];
$allUsernames  = [];
foreach ($pdo->query("SELECT username, avatar FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $allUsernames[] = $u["username"];
    if (!empty($u["avatar"])) $userAvatars[$u["username"]] = $u["avatar"];
}

// username -> equipped cosmetic hat (from the inventory system), for the podium.
require_once __DIR__ . "/includes/cosmetics.php";
$equippedHats = getEquippedHats($pdo);

// ── Countdown to the next exam I'm writing + exams-written (mirrors calendar.php logic) ──
$_exams = $pdo->query("SELECT id, exam_date, exam_time FROM exams ORDER BY exam_date, exam_time")
    ->fetchAll(PDO::FETCH_ASSOC);

$_myUsername    = $_SESSION["username"] ?? "";
$_stmt          = $pdo->prepare("SELECT exam_id FROM user_exams WHERE username = ?");
$_stmt->execute([$_myUsername]);
$_selectedIds   = array_flip(array_map("intval", array_column($_stmt->fetchAll(PDO::FETCH_ASSOC), "exam_id")));
$totalExams     = count($_selectedIds);
$passedExams    = 0;
$_now           = new DateTime("now");
$_nextExam      = null;
foreach ($_exams as $_e) {
    if (!isset($_selectedIds[(int) $_e["id"]])) continue;
    $_examDateTime = new DateTime($_e["exam_date"] . " " . $_e["exam_time"]);
    if ($_examDateTime < $_now) {
        $passedExams++;
    } elseif ($_nextExam === null) {
        $_nextExam = $_e;
    }
}

$_today = new DateTime("today");
if ($_nextExam !== null) {
    $_nextDay      = new DateTime($_nextExam["exam_date"]);
    $daysUntilExam = (int) ceil(($_nextDay->getTimestamp() - $_today->getTimestamp()) / 86400);
} else {
    $daysUntilExam = null;
}

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
    align-items: stretch;
    margin-bottom: 20px;
}

.study-intro {
    display: flex;
    flex-direction: column;
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
    /* overflow: hidden was here to clip the back face, but it flattens
       transform-style: preserve-3d in Firefox (spec-compliant but breaks
       backface-visibility). JS sets the explicit height to match the visible
       face, so absolute-positioned faces never add phantom scroll anyway. */
    /* Height tracks the visible face's content (set in JS); animating it makes
       the bottom edge retract/extend smoothly across a flip. */
    transition: height 0.5s var(--ease-out);
}

/* Front = sticky sidebar that follows the scroll. The recap (back) stays
   sticky too as long as it's short enough to fit within the main column's
   height (JS toggles `.recap-overflow` — see updateStickyMode()); once the
   day's timeline is tall enough that sticking would push it lower than the
   main content, it drops stickiness and scrolls normally instead. */
.dock-card.recap.recap-overflow {
    position: relative;
    top: auto;
}

.flip-inner {
    position: relative;
    width: 100%;
    height: 100%;
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
    background: var(--bg-1);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    box-shadow: inset 0 1px 0 var(--glass-highlight), var(--shadow-lift);
}

/* Back face hidden by default; JS drives visibility during the flip. */
.flip-back {
    visibility: hidden;
}

/* Two-phase flip: outgoing rotates to 90° (edge-on), incoming rotates from 90°.
   The parent .dock-card supplies the perspective. Forward = positive Y axis;
   reverse = negative Y axis so going back feels like going the other way. */
@keyframes flip-out     { to   { transform: rotateY(90deg);  } }
@keyframes flip-in      { from { transform: rotateY(90deg);  } }
@keyframes flip-out-rev { to   { transform: rotateY(-90deg); } }
@keyframes flip-in-rev  { from { transform: rotateY(-90deg); } }

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

/* Front: a clean divider between the action buttons and the user statuses. */
.flip-front .face-body {
    border-top: 1px solid var(--glass-border);
    padding-top: 16px;
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
        flex-wrap: wrap;
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
    transition: color var(--t-fast), background var(--t-fast);
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
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
    margin-bottom: 12px;
}

.studying-toggle {
    width: 100%;
    margin: 0;
    padding: 9px 14px;
    font-size: 0.85rem;
    white-space: nowrap;
}

/* Current-module display above the in-session action buttons. */
.my-session {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-bottom: 12px;
    padding: 10px 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    background: var(--grad-accent-soft);
}

.my-session-label {
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
}

.my-session-module {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-1);
}

.studying-toggle.active {
    background: var(--grad-accent);
    border-color: transparent;
    color: #fff;
    box-shadow: var(--glow-violet);
}

/* Primary dock button: "I'm studying" is green; once active it becomes the red
   "Stop session". (Id selectors so they win over the generic .active above.) */
#studying-toggle {
    background: rgba(52, 211, 153, 0.12);
    border-color: rgba(52, 211, 153, 0.35);
    color: var(--success);
    box-shadow: none;
}
#studying-toggle:hover {
    background: rgba(52, 211, 153, 0.22);
    border-color: var(--success);
}

/* "Stop session" — same red as the .btn-danger "Discard all" button. */
#studying-toggle.active {
    background: rgba(248, 113, 113, 0.12);
    border-color: rgba(248, 113, 113, 0.35);
    color: var(--danger);
    box-shadow: none;
    filter: none;
}
#studying-toggle.active:hover {
    background: rgba(248, 113, 113, 0.22);
    border-color: var(--danger);
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

/* BIB ("at the library") button — accent button, independent flag. */
.studying-bib {
    color: var(--info, #38bdf8);
    border-color: rgba(56, 189, 248, 0.4);
    background: rgba(56, 189, 248, 0.1);
}

.studying-bib:hover {
    background: rgba(56, 189, 248, 0.18);
    border-color: var(--info, #38bdf8);
    color: var(--info, #38bdf8);
}

/* "Leave library" (active) — same red as the .btn-danger "Discard all" button. */
.studying-bib.active {
    background: rgba(248, 113, 113, 0.12);
    border-color: rgba(248, 113, 113, 0.35);
    color: var(--danger);
    box-shadow: none;
}
.studying-bib.active:hover {
    background: rgba(248, 113, 113, 0.22);
    border-color: var(--danger);
    color: var(--danger);
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
    flex-direction: column;
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
    flex-direction: column;
    gap: 8px;
}

.studying-chip.break {
    opacity: 0.85;
    border-style: dashed;
}

.studying-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.studying-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 7px 12px 7px 10px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-md);
    font-size: 0.86rem;
    font-weight: 600;
    overflow: hidden;
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

.chip-module-breakdown {
    display: block;
    margin-top: 2px;
    line-height: 1.4;
    min-width: 0;
}

.chip-breakdown-line {
    display: block;
    white-space: nowrap;
}

/* Completed history lines (modules no longer running, breaks already over)
   stay the same neutral grey as the rest of the chip meta text. The
   currently-running module matches the green "session active" colour;
   at the library it switches to blue instead so BIB sessions read
   distinctly at a glance. A break in progress is always yellow. */
.chip-breakdown-line.live {
    color: var(--success);
    font-weight: 600;
}

.chip-breakdown-line.live-lib {
    color: var(--info, #38bdf8);
    font-weight: 600;
}

.chip-breakdown-line.break-line.live {
    color: var(--warning);
    font-weight: 600;
}

.chip-time-label.session-active,
.chip-time.session-active {
    color: var(--success);
}

.chip-time-label.session-break,
.chip-time.session-break {
    color: var(--danger);
}

/* Pulsing presence dots */
.studying-live-dot,
.pulse-dot {
    display: inline-block;
    flex-shrink: 0;
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

.podium-header-row {
    display: flex;
    align-items: center;
    gap: 34px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.podium-heading {
    margin: 0;
    font-size: 1.25rem;
    white-space: nowrap;
}

.podium-controls {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 28px;
    flex-wrap: wrap;
}

/* Time period → a horizontal segmented control (pill row). */
.seg-control {
    display: inline-flex;
    flex-direction: row;
    gap: 2px;
    padding: 4px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
}

.seg-btn {
    margin: 0;
    padding: 8px 18px;
    border: none;
    background: transparent;
    color: var(--text-2);
    font-size: 0.88rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
    text-align: center;
    border-radius: var(--radius-full);
    cursor: pointer;
    transition: background var(--t-fast), color var(--t-fast), transform var(--t-fast);
}
.seg-btn:hover:not(.active) { color: var(--text-1); background: rgba(255, 255, 255, 0.07); }
.seg-btn:active { transform: scale(0.97); }
.seg-btn.active {
    background: var(--grad-accent);
    color: #fff;
    box-shadow: var(--glow-violet);
}



/* ── Exam countdown in the intro column ─────────────────────────────────── */
.sc-exam-countdown {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    margin: auto 0;
    padding: 28px 20px;
    background: var(--bg-1);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lift);
}

.sc-exam-countdown .cd-value {
    font-family: var(--font-display);
    font-size: 2.8rem;
    font-weight: 700;
    line-height: 1;
    color: var(--danger);
}

.sc-exam-countdown .cd-value.done {
    color: var(--success);
}

.sc-exam-countdown .cd-label {
    margin-top: 4px;
    font-size: 0.66rem;
    font-weight: 700;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.09em;
}

.sc-exams-written {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid var(--glass-border);
    width: 100%;
}

.sc-ew-value {
    font-family: var(--font-display);
    font-size: 2.8rem;
    font-weight: 700;
    line-height: 1;
    color: var(--success);
}

.sc-ew-label {
    margin-top: 4px;
    font-size: 0.66rem;
    font-weight: 700;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.09em;
}

.podium-views {
    position: relative;
    margin-bottom: 0;
}

.podium-module-fullname {
    min-height: 1.5em;
    margin: 18px 0 0;
    text-align: center;
    font-size: 0.92rem;
    font-weight: 600;
    color: var(--text-3);
    visibility: hidden;
}

.podium-module-fullname.visible {
    visibility: visible;
}

.podium-view {
    transition: opacity var(--t-med) var(--ease-out);
}

.podium-view.fade-out {
    opacity: 0;
}

/* Floating hint above the view seg-control, visible only in module view */
.podium-header-row {
    position: relative;
}

.podium-module-hint {
    position: absolute;
    /* Sits just above the view seg-control; JS sets --hint-left to centre it */
    bottom: calc(100% + 6px);
    left: var(--hint-left, 50%);
    transform: translateX(-50%);
    font-size: 0.6rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-3);
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity var(--t-fast);
}

.podium-module-hint.visible {
    opacity: 1;
}

.podium-sub-time {
    font-size: 0.78rem;
    color: var(--text-3);
}

/* ── Study podium: profile pictures + gold/silver/bronze/chocolate medals ──
   Scoped to #overall-podium so the shared .podium (projects) is untouched. */
#overall-podium { flex-wrap: wrap; }

.sc-avatar {
    width: 64px;
    height: 64px;
    margin: 0 auto 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 2px solid var(--glass-border);
    border-radius: 50%;
    background: var(--glass-strong);
}
.sc-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.sc-avatar-fallback {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 1.5rem;
    color: var(--text-1);
    line-height: 1;
}

/* The medal disc lives under the "total studied" sub-label. */
.sc-medal {
    width: 30px;
    height: 30px;
    margin: 12px auto 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 0.92rem;
    border-radius: 50%;
    box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.4), 0 2px 6px rgba(0, 0, 0, 0.3);
}
.sc-medal-1 { /* gold   */ background: linear-gradient(145deg, #fff3b0, #fbbf24 45%, #d4960a); color: #5a3d00; box-shadow: inset 0 1px 1px rgba(255,255,255,0.55), 0 0 14px rgba(251,191,36,0.5); }
.sc-medal-2 { /* silver */ background: linear-gradient(145deg, #ffffff, #cbd5e1 45%, #94a3b8); color: #39414e; }
.sc-medal-3 { /* bronze — warm copper, kept clearly orange so it doesn't read as brown */ background: linear-gradient(145deg, #f7b878, #e07f37 45%, #b85f1e); color: #4a2400; }
.sc-medal-4 { /* chocolate — dark brown */ background: linear-gradient(145deg, #8a5a36, #5e3a1e 45%, #3a2210); color: #f3e2d0; }

/* ── Cosmetic hats: worn on top of the avatar, from each player's inventory ──
   A transparent PNG (assets/images/*.png) worn on top of the avatar. The
   avatar circle clips its children (overflow: hidden + border-radius), so
   the hat is an absolutely-positioned sibling inside this wrapper.
   Percentage sizing scales it with any avatar size (64px podium, 48px
   rank-4, 108px detail modal). --hx/--hy/--hr/--hs/--hflip are per-user
   placement set inline from user_cosmetics (see includes/cosmetics.php) and
   are also what the header's Hats-tab preview drives live, so the two stay
   in visual sync. */
.sc-avatar-wrap {
    position: relative;
    width: fit-content;
    margin: 0 auto 10px;
}
.sc-avatar-wrap .sc-avatar,
.sc-avatar-wrap .pd-avatar { margin: 0; }
.pd-avatar-wrap { margin-bottom: 16px; }

.sc-hat {
    position: absolute;
    width: 130%;
    max-width: none;
    left: 50%;
    top: -90%;
    transform: translateX(-50%) translate(var(--hx, 0%), var(--hy, 0%)) rotate(var(--hr, 0deg)) scale(var(--hs, 1)) scaleX(var(--hflip, 1));
    pointer-events: none;
    z-index: 1;
    filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.3));
}

/* Room for the hat to rise above the avatar without crossing the card edge. */
#overall-podium .podium-card { padding-top: 34px; }

/* Desktop: 1→4 left-to-right staircase, with a smaller 4th place. */
@media (min-width: 769px) {
    #overall-podium { align-items: flex-start; }
    #overall-podium .podium-card { order: 0; margin-top: 0; max-width: 210px; }
    #overall-podium .podium-card.rank-1 { order: 1; max-width: 230px; }
    #overall-podium .podium-card.rank-2 { order: 2; margin-top: 14px; }
    #overall-podium .podium-card.rank-3 { order: 3; margin-top: 26px; }
    #overall-podium .podium-card.rank-4 { order: 4; margin-top: 38px; max-width: 168px; padding: 26px 14px 18px; }
    #overall-podium .podium-card.rank-4 .sc-avatar { width: 48px; height: 48px; }
    #overall-podium .podium-card.rank-4 .podium-score { font-size: 1.6rem; }
}

/* ── Logging panel ──────────────────────────────────────────────────────── */

/* ── Logging panel ──────────────────────────────────────────────────────── */

.log-layout {
    display: grid;
    /* Left card sizes to its content (just two buttons) instead of reserving a
       fixed-width column; the chart takes the rest. */
    grid-template-columns: auto minmax(0, 1fr);
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
    margin-top: 8px;
}

.new-module-wrap.show { display: block; }

.module-hint {
    margin: -8px 0 14px;
    font-size: 0.78rem;
    color: var(--text-3);
}

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

/* Session-management card (live tracking lives in the dock; this just opens the
   manual-logging and history pop-ups). Sized to its content. */
.log-collapsed-actions { display: flex; flex-direction: column; gap: 8px; }
.log-collapsed-actions .btn { width: 100%; margin: 0; white-space: nowrap; }

/* Opaque pop-up dialogs (no glass translucency bleeding the page through). */
.modal-dialog.modal-solid {
    background: var(--bg-1);
    backdrop-filter: none;
}

/* ── Custom module picker list ───────────────────────────────────────────── */

/* Wrapper: just provides the stacking context for the scroll-hint overlay. */
.mod-list-wrap {
    position: relative;
    margin: 6px 0 12px;
}

/* Fading arrow that appears when more items are below the fold. */
.mod-list-wrap::after {
    content: "\25BE"; /* ▾ */
    position: absolute;
    bottom: 1px;
    left: 1px;
    right: 1px;
    height: 30px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding-bottom: 5px;
    background: linear-gradient(to bottom, transparent, rgba(16, 16, 30, 0.78));
    pointer-events: none;
    font-size: 0.85rem;
    color: var(--text-3);
    border-radius: 0 0 calc(var(--radius-sm) - 1px) calc(var(--radius-sm) - 1px);
    transition: opacity var(--t-fast);
}
.mod-list-wrap.no-more::after { opacity: 0; }

/* 207px = 1px top-border + 5 × 41px items − 1px (last item has no border) + 1px bottom-border */
.mod-list {
    max-height: 207px;
    overflow-y: auto;
    margin: 0;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    background: rgba(255, 255, 255, 0.02);
    scrollbar-width: none;
}
.mod-list::-webkit-scrollbar { display: none; }

.mod-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    transition: background var(--t-fast);
    user-select: none;
}
.mod-item:last-child { border-bottom: none; }
.mod-item:hover:not(.active) { background: rgba(255, 255, 255, 0.05); }
.mod-item.active {
    background: var(--grad-accent-soft);
    border-left: 3px solid var(--violet);
    padding-left: 9px;
}
.mod-item.active .mod-name { color: var(--text-1); font-weight: 700; }

.mod-dot {
    width: 11px;
    height: 11px;
    border-radius: 3px;
    flex-shrink: 0;
}

.mod-name {
    flex: 1;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mod-custom {
    font-size: 0.58rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--violet);
    border: 1px solid rgba(139, 92, 246, 0.45);
    background: var(--grad-accent-soft);
    border-radius: var(--radius-full);
    padding: 1px 6px;
    flex-shrink: 0;
}

.mod-delete {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    margin: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    line-height: 1;
    border-radius: var(--radius-sm);
    color: var(--danger);
    border-color: rgba(248, 113, 113, 0.35);
    opacity: 0;
    transition: opacity var(--t-fast), background var(--t-fast);
}
.mod-item:hover .mod-delete { opacity: 1; }
.mod-delete:hover { background: rgba(248, 113, 113, 0.14); }

.mod-item-new .mod-name {
    color: var(--text-3);
    font-weight: 500;
    font-style: italic;
}

.mod-new-plus {
    width: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
    font-size: 1rem;
    line-height: 1;
    flex-shrink: 0;
}

/* Module button in the studying dock (carries the current module name). */
.studying-module {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.studying-module.active {
    background: var(--grad-accent);
    border-color: transparent;
    color: #fff;
    box-shadow: var(--glow-violet);
}

/* Stop modal: per-module checklist */
.stop-checklist {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 16px 0 8px;
}

.stop-part {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    background: rgba(255, 255, 255, 0.03);
}

.stop-part .module-dot { width: 12px; height: 12px; border-radius: 4px; flex-shrink: 0; }
.stop-part-name {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    font-size: 0.9rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.stop-part-time {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
    min-width: 56px;
    text-align: right;
}

/* − (trim time) and × (drop session) controls on a stop row. */
.stop-part-btn {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    margin: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    line-height: 1;
    border-radius: var(--radius-sm);
}
.stop-part-btn.minus,
.stop-part-btn.plus { font-size: 1.2rem; }
.stop-part-btn.remove { color: var(--danger); border-color: rgba(248, 113, 113, 0.4); }
.stop-part-btn.remove:hover { background: rgba(248, 113, 113, 0.14); }

.stop-part.removed {
    opacity: 0.45;
    border-style: dashed;
}
.stop-part.removed .stop-part-name { text-decoration: line-through; }

.stop-part.disabled {
    opacity: 0.6;
    border-style: dashed;
}

/* ── Manage previous sessions modal ─────────────────────────────────────── */
.manage-dialog { max-width: 560px; width: 100%; }
.manage-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.manage-head h3 { margin: 0; }
.manage-week-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 16px;
}
.manage-week-nav .btn { width: auto; margin: 0; padding: 6px 12px; line-height: 1; }
.manage-week-nav .btn:disabled { opacity: 0.35; cursor: not-allowed; }

.manage-list {
    max-height: 52vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 14px;
    /* Seamless scroll — no visible scrollbar. */
    scrollbar-width: none;          /* Firefox */
    -ms-overflow-style: none;       /* old Edge/IE */
}
.manage-list::-webkit-scrollbar { width: 0; height: 0; display: none; } /* WebKit */
.manage-day-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-3);
    margin: 4px 0 6px;
}
.manage-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    background: rgba(255, 255, 255, 0.03);
}
.manage-row .module-dot { width: 11px; height: 11px; border-radius: 4px; flex-shrink: 0; }
.manage-row-main { flex: 1; min-width: 0; }
.manage-row-mod {
    font-weight: 600;
    font-size: 0.9rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.manage-row-meta { font-size: 0.74rem; color: var(--text-3); }
.manage-row-lib { color: var(--info, #38bdf8); }
.manage-row-time {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 0.9rem;
    font-variant-numeric: tabular-nums;
}
.manage-row-del {
    width: 28px; height: 28px; flex-shrink: 0; margin: 0; padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1rem; line-height: 1; border-radius: var(--radius-sm);
    color: var(--danger); border-color: rgba(248, 113, 113, 0.4);
}
.manage-row-del:hover { background: rgba(248, 113, 113, 0.14); }
.manage-empty { color: var(--text-3); text-align: center; padding: 24px 0; }

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

/* ── Podium detail modal ─────────────────────────────────────────────────── */

/* Cards become clickable in the overall podium */
#overall-podium .podium-card {
    cursor: pointer;
    /* Promote to its own compositor layer ahead of the first hover — without
       this the browser's first transform on a freshly-painted element does a
       synchronous layout/paint instead of an animated one, which reads as a
       jump/glitch on the very first hover before it settles down. */
    will-change: transform;
    /* Per-user tinted resting shadow, set inline as --card-shadow (see
       buildOverallPodium) — read through a var() here
       instead of inlining box-shadow directly so the :hover rule below
       (a class selector) can actually out-specificity it and take over;
       an inline box-shadow declaration would always win over :hover,
       making the hover glow a dead rule. */
    box-shadow: var(--card-shadow, none);
    transition:
        transform var(--t-fast),
        box-shadow var(--t-fast),
        border-color var(--t-fast);
}
#overall-podium .podium-card:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 20px 48px rgba(0, 0, 0, 0.5);
}
#overall-podium .podium-card:active {
    transform: translateY(-1px) scale(1.00);
}

.podium-detail-dialog {
    width: min(540px, calc(100vw - 32px));
    text-align: center;
    will-change: transform, opacity;
    animation: podium-detail-in 300ms var(--ease-out);
}

@keyframes podium-detail-in {
    from { opacity: 0; transform: scale(0.88); }
    to   { opacity: 1; transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
    .podium-detail-dialog { animation: none; }
}

.pd-head {
    display: flex;
    align-items: flex-start;
    justify-content: flex-end;
    margin-bottom: 4px;
}

.pd-avatar {
    width: 108px;
    height: 108px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 3px solid var(--glass-border);
    border-radius: 50%;
    background: var(--glass-strong);
}
.pd-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pd-avatar .sc-avatar-fallback { font-size: 2.6rem; }

.pd-username {
    font-family: var(--font-display);
    font-size: 1.6rem;
    font-weight: 700;
    margin-bottom: 12px;
}

.pd-rank-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 24px;
}

.pd-rank-row .sc-medal { margin: 0; flex-shrink: 0; }

.pd-total-time {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 1.4rem;
}

.pd-total-label {
    font-size: 0.8rem;
    color: var(--text-3);
}

.pd-modules {
    text-align: left;
    border-top: 1px solid var(--glass-border);
    padding-top: 18px;
    margin-top: 4px;
}

.pd-modules-label {
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 12px;
}

.pd-mod-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}
.pd-mod-row:last-child { border-bottom: none; }

.pd-mod-name {
    flex: 0 0 120px;
    font-weight: 600;
    font-size: 0.88rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: left;
}

.pd-mod-bar-wrap {
    flex: 1;
    height: 6px;
    background: rgba(255, 255, 255, 0.07);
    border-radius: 3px;
    overflow: hidden;
}

.pd-mod-bar {
    height: 100%;
    border-radius: 3px;
}

.pd-mod-time {
    flex: 0 0 62px;
    text-align: right;
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 0.88rem;
    font-variant-numeric: tabular-nums;
}

/* ── Focus mode ──────────────────────────────────────────────────────────── */

.focus-mode-btn {
    width: 100%;
    margin-top: 10px;
}

#focus-section { display: none; }

/* Fake fullscreen: main covers the whole viewport over the navbar */
body.focus-mode main.container {
    position: fixed;
    inset: 0;
    max-width: none;
    padding: 20px 24px;
    background: var(--bg-0);
    z-index: 101;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

/* study-layout fills the fixed container */
body.focus-mode .study-layout {
    flex: 1;
    min-height: 0;
}

/* study-main becomes a side-by-side row: narrow exam sidebar + big timer */
body.focus-mode .study-main {
    display: flex;
    flex-direction: row;
    align-items: stretch;
    gap: 24px;
    min-height: 0;
}

body.focus-mode .study-intro-layout {
    display: flex;
    flex-direction: column;
    width: 220px;
    flex-shrink: 0;
    gap: 0;
    margin-bottom: 0;
}

body.focus-mode .study-intro-layout > div:not(.study-intro) { display: none; }

/* Hide the page heading in focus mode to keep the sidebar compact */
body.focus-mode .study-head { display: none; }

/* Remove the vertical centering so the card sits at the top */
body.focus-mode .sc-exam-countdown { margin: 0; }

body.focus-mode .log-layout { display: none; }

/* Timer section fills the remaining middle space */
body.focus-mode #focus-section {
    display: flex;
    flex: 1;
    min-width: 0;
}

body.focus-mode .focus-timer-card { flex: 1; }

body.focus-sidebar-collapsed .study-intro-layout { display: none; }

.focus-sidebar-toggle {
    position: absolute;
    top: 12px;
    left: 12px;
    width: 52px;
    height: 52px;
    margin: 0;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    line-height: 1;
    border-radius: var(--radius-sm);
    color: var(--text-3);
    border-color: var(--glass-border);
    background: transparent;
    transition: color var(--t-fast), background var(--t-fast);
}
.focus-sidebar-toggle:hover {
    color: var(--text-1);
    background: rgba(255, 255, 255, 0.07);
}

/* Hide only the start/stop/break buttons from the dock in focus mode — they live in the timer */
body.focus-mode #studying-toggle { display: none; }
body.focus-mode #studying-break  { display: none; }

body.focus-mode.focus-dock-collapsed .studying-dock { display: none; }
body.focus-mode.focus-dock-collapsed .study-layout { grid-template-columns: minmax(0, 1fr); }

.focus-dock-toggle {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 52px;
    height: 52px;
    margin: 0;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    line-height: 1;
    border-radius: var(--radius-sm);
    color: var(--text-3);
    border-color: var(--glass-border);
    background: transparent;
    transition: color var(--t-fast), background var(--t-fast);
}
.focus-dock-toggle:hover {
    color: var(--text-1);
    background: rgba(255, 255, 255, 0.07);
}

/* Dock sticky top adjusted now that the navbar is hidden */
body.focus-mode .dock-card { top: 20px; }

.focus-timer-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 52px 32px;
    text-align: center;
}

.focus-clock {
    font-family: var(--font-display);
    font-size: clamp(5rem, 10vw, 9rem);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    letter-spacing: -3px;
    color: var(--text-1);
    transition: color var(--t-fast);
}

.focus-clock.on-break { color: var(--warning); }

.focus-clock-module {
    margin-top: 14px;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-3);
    /* Reserve a line even when empty so the clock doesn't jump */
    min-height: 1.5em;
    line-height: 1.5;
}

.focus-actions {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
    margin-top: 28px;
}

.focus-actions .btn {
    width: auto;
    margin: 0;
    padding: 20px 56px;
    font-size: 1.1rem;
}

/* Preserve the original dock-button colours */
#focus-start-btn {
    background: rgba(52, 211, 153, 0.12);
    border-color: rgba(52, 211, 153, 0.35);
    color: var(--success);
}
#focus-start-btn:hover {
    background: rgba(52, 211, 153, 0.22);
    border-color: var(--success);
}

#focus-stop-btn {
    background: rgba(248, 113, 113, 0.12);
    border-color: rgba(248, 113, 113, 0.35);
    color: var(--danger);
}
#focus-stop-btn:hover {
    background: rgba(248, 113, 113, 0.22);
    border-color: var(--danger);
}

/* Fixed equal widths so the row never shifts when labels toggle */
#focus-break-btn,
#focus-stop-btn { width: 230px; }

/* ── Focus mode mini charts (personal weekly bars) ───────────────────────── */

#focus-mini-charts {
    display: none;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
}

body.focus-mode #focus-mini-charts { display: flex; }

.focus-mini-week {
    /* top padding absorbs value labels that float above the tallest bar */
    padding: 16px 10px 6px;
    background: var(--bg-1);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
}

.focus-mini-week-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 42px;
}

.focus-mini-total {
    display: flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0;
}

.focus-mini-total-sum {
    font-variant-numeric: tabular-nums;
    color: var(--text-2);
}

.focus-mini-total-avg {
    font-variant-numeric: tabular-nums;
    color: var(--text-3);
    font-weight: 600;
}

.focus-personal-total {
    margin-top: 10px;
    padding: 12px 14px;
    background: var(--bg-1);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.focus-personal-total-label {
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
}

.focus-personal-total-value {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--text-1);
}

.focus-mini-bars-wrap {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 88px;
}

.focus-mini-day {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
}

.focus-mini-bar {
    width: 100%;
    border-radius: 2px 2px 0 0;
    min-height: 2px;
    position: relative;
}

/* Value label floats just above the top of its bar */
.focus-mini-val {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    margin-bottom: 2px;
    font-size: 0.52rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--text-2);
    white-space: nowrap;
    line-height: 1;
}

.focus-mini-day-lbl {
    font-size: 0.54rem;
    color: var(--text-3);
    line-height: 1;
}

.focus-mini-day.is-today .focus-mini-day-lbl {
    color: var(--text-1);
    font-weight: 700;
}

.focus-mini-day.is-today .focus-mini-val {
    color: var(--text-1);
}
</style>

<div class="study-layout">
<div class="study-main">

<!-- ── Intro + Podium ─────────────────────────────────────────────────────── -->
<div class="study-intro-layout">

    <div class="study-intro">
        <h1 class="page-heading study-head">Study <span class="gradient-text">Counter</span></h1>
        <div class="sc-exam-countdown">
            <?php if ($daysUntilExam === null): ?>
                <span class="cd-value done">done</span>
                <span class="cd-label">exams finished</span>
            <?php elseif ($daysUntilExam > 0): ?>
                <span class="cd-value">D-<?= $daysUntilExam ?></span>
                <span class="cd-label">until next exam</span>
            <?php else: ?>
                <span class="cd-value">D-Day</span>
                <span class="cd-label">next exam today</span>
            <?php endif; ?>
            <div class="sc-exams-written">
                <span class="sc-ew-value"><?= $passedExams ?>/<?= $totalExams ?></span>
                <span class="sc-ew-label">exams written</span>
            </div>
        </div>
        <button type="button" class="btn focus-mode-btn" id="focus-toggle">Focus mode</button>
        <div id="focus-mini-charts">
            <div class="focus-mini-week" data-week-offset="0" data-week-name="This week">
                <div class="focus-mini-week-label"><span>This week</span><span class="focus-mini-total"></span></div>
                <div class="focus-mini-bars-wrap"></div>
            </div>
            <div class="focus-mini-week" data-week-offset="-1" data-week-name="Last week">
                <div class="focus-mini-week-label"><span>Last week</span><span class="focus-mini-total"></span></div>
                <div class="focus-mini-bars-wrap"></div>
            </div>
            <div class="focus-mini-week" data-week-offset="-2" data-week-name="Two weeks ago">
                <div class="focus-mini-week-label"><span>Two weeks ago</span><span class="focus-mini-total"></span></div>
                <div class="focus-mini-bars-wrap"></div>
            </div>
            <div id="focus-personal-total" class="focus-personal-total">
                <span class="focus-personal-total-label">Total studied</span>
                <span class="focus-personal-total-value">—</span>
            </div>
        </div>
    </div>

    <div>
        <div class="podium-header-row">
            <h2 class="podium-heading">🏆 Most hours studied</h2>
            <div class="podium-controls">
                <div class="seg-control">
                    <button type="button" class="seg-btn active" data-period="overall">Overall</button>
                    <button type="button" class="seg-btn" data-period="weekly">This week</button>
                    <button type="button" class="seg-btn" data-period="daily">Today</button>
                </div>
                <div class="seg-control" id="view-seg-control">
                    <button type="button" class="seg-btn active" data-view="all">All modules</button>
                    <button type="button" class="seg-btn" data-view="module" id="podium-module-btn">Per module</button>
                    <button type="button" class="seg-btn" data-view="library">Library only</button>
                </div>
            </div>
            <span class="podium-module-hint" id="podium-module-hint" aria-hidden="true">Double click to change module</span>
        </div>
        <div class="podium-views">
            <div class="podium-view" id="podium-overall">
                <div class="podium" id="overall-podium" style="display:none;"></div>
                <p class="muted" id="overall-empty" style="display:none;">No study sessions logged yet.</p>
            </div>
            <p class="podium-module-fullname" id="podium-module-fullname"></p>
        </div>
    </div>

</div>

<!-- ── Focus mode timer ──────────────────────────────────────────────────── -->
<div id="focus-section">
    <section class="focus-timer-card glass-card">
        <button type="button" class="btn focus-sidebar-toggle" id="focus-sidebar-toggle" title="Hide sidebar">&#x2039;</button>
        <button type="button" class="btn focus-dock-toggle" id="focus-dock-toggle" title="Hide sidebar">&#x203A;</button>
        <div class="focus-clock" id="focus-clock">00:00</div>
        <div class="focus-clock-module" id="focus-clock-module"></div>
        <div class="focus-actions" id="focus-actions">
            <button type="button" class="btn studying-toggle" id="focus-start-btn">I'm studying</button>
            <button type="button" class="btn studying-break" id="focus-break-btn" style="display:none;">Take a break</button>
            <button type="button" class="btn" id="focus-stop-btn" style="display:none;">Stop session</button>
        </div>
    </section>
</div>

<!-- ── Logging + chart ────────────────────────────────────────────────────── -->
<div class="log-layout">

    <section class="log-panel glass-card">
        <!-- Live tracking lives in the "Currently studying" dock; this small card
             just opens the manual-logging and history pop-ups. -->
        <h2>Session Management</h2>
        <div class="log-collapsed-actions">
            <button type="button" class="btn" id="manual-toggle">Manual logging</button>
            <button type="button" class="btn" id="manage-toggle">Manage previous sessions</button>
        </div>
        <p class="log-feedback" id="log-feedback"></p>
    </section>

    <section class="chart-panel glass-card">
        <div class="chart-head">
            <h2>Hours per day - All modules</h2>
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
                <div class="my-session" id="my-session" style="display:none;">
                    <span class="my-session-label">Current module</span>
                    <span class="my-session-module" id="my-module-name">—</span>
                </div>
                <div class="studying-actions">
                    <button type="button" class="btn studying-toggle" id="studying-toggle">I'm studying</button>
                    <button type="button" class="btn studying-toggle studying-module" id="studying-module" style="display:none;">Change module</button>
                    <button type="button" class="btn studying-toggle studying-break" id="studying-break" style="display:none;">Take a break</button>
                    <button type="button" class="btn studying-toggle studying-bib" id="studying-bib" style="display:none;">At the library</button>
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

<!-- ── Podium-card detail modal ──────────────────────────────────────────── -->
<div id="podium-detail-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid podium-detail-dialog">
        <div class="pd-head">
            <button type="button" class="icon-btn" id="podium-detail-close" aria-label="Close">&#x2715;</button>
        </div>
        <div id="podium-detail-body"></div>
    </div>
</div>

<!-- ── Module picker modal (assign/switch the running session's module) ─────── -->
<div id="module-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3 id="sess-module-title">Pick a module</h3>
        <p class="muted" id="sess-module-hint">Switching modules keeps the time so far under the old one and starts a fresh session — both show up separately.</p>

        <label>Module</label>
        <div class="mod-list-wrap">
            <div class="mod-list" id="sess-mod-list"></div>
        </div>
        <div class="new-module-wrap" id="sess-new-module-wrap">
            <input type="text" id="sess-new-module-input" maxlength="255" placeholder="New module name">
            <p class="module-hint">This module will join the shared list for everyone.</p>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="sess-module-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="sess-module-set">Set module</button>
        </div>
    </div>
</div>

<!-- ── Podium module filter picker ─────────────────────────────────────────── -->
<div id="podium-module-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3>Filter podium by module</h3>
        <div class="mod-list-wrap">
            <div class="mod-list" id="podium-mod-list"></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="podium-module-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="podium-module-confirm">Apply</button>
        </div>
    </div>
</div>

<!-- ── Stop / log-on-stop modal (per-module list, trim with − / remove with ×) ── -->
<div id="stop-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3>Log this study session</h3>
        <p class="muted">Each module is saved as its own session. Use − to trim time (forgot to stop?), or × to drop a session.</p>

        <div class="stop-checklist" id="stop-checklist"></div>

        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="stop-cancel">Cancel</button>
            <button type="button" class="btn-danger" id="stop-discard">Discard all</button>
            <button type="button" class="btn-primary" id="stop-log">Save</button>
        </div>
    </div>
</div>

<!-- ── Manual logging modal (back-fill a past session by hand) ──────────────── -->
<div id="manual-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card modal-solid">
        <div class="manage-head">
            <h3>Manual logging</h3>
            <button type="button" class="icon-btn" id="manual-close" title="Close" aria-label="Close">✕</button>
        </div>

        <div class="module-picker">
            <label>Module</label>
            <div class="mod-list-wrap">
                <div class="mod-list" id="manual-mod-list"></div>
            </div>
            <div class="new-module-wrap" id="new-module-wrap">
                <input type="text" id="new-module-input" maxlength="255" placeholder="New module name">
                <p class="module-hint">This module will join the shared list for everyone.</p>
            </div>
        </div>

        <div id="pane-manual">
            <label class="manual-today">
                <input type="checkbox" id="manual-today" checked>
                <span>Today — log exact start &amp; end time</span>
            </label>

            <div class="manual-grid">
                <!-- Exact start/end (Today mode) -->
                <div class="manual-exact">
                    <label for="manual-start">Start <span class="muted">(24h)</span></label>
                    <input type="text" id="manual-start" inputmode="numeric" maxlength="5"
                           pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="HH:MM" autocomplete="off">
                </div>
                <div class="manual-exact">
                    <label for="manual-end">End <span class="muted">(24h)</span></label>
                    <input type="text" id="manual-end" inputmode="numeric" maxlength="5"
                           pattern="([01]\d|2[0-3]):[0-5]\d" placeholder="HH:MM" autocomplete="off">
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

        <p class="log-feedback" id="manual-feedback"></p>
    </div>
</div>

<!-- ── Manage previous sessions modal (browse a week, delete sessions) ──────── -->
<div id="manage-modal" class="modal-overlay" style="display:none;">
    <div class="modal-dialog glass-card manage-dialog modal-solid">
        <div class="manage-head">
            <h3>Manage previous sessions</h3>
            <button type="button" class="icon-btn" id="manage-close" title="Close" aria-label="Close">✕</button>
        </div>
        <div class="manage-week-nav">
            <button type="button" class="btn" id="manage-prev" aria-label="Previous week">‹</button>
            <span class="week-label" id="manage-week-label">This week</span>
            <button type="button" class="btn" id="manage-next" aria-label="Next week">›</button>
        </div>
        <div class="manage-list" id="manage-list"></div>
    </div>
</div>

<script>
(function () {
    const BASE_PATH   = "<?= BASE_PATH ?>";
    const MY_USERNAME = <?= json_encode($_SESSION["username"] ?? "") ?>;
    const MODULES          = <?= json_encode($modules) ?>;
    const INITIAL_STATUS   = <?= json_encode($initialStatus) ?>;
    const INITIAL_SESSIONS = <?= json_encode($initialSessions) ?>;
    const USER_AVATARS     = <?= json_encode($userAvatars, JSON_UNESCAPED_SLASHES) ?>;
    const EQUIPPED_HATS    = <?= json_encode($equippedHats, JSON_UNESCAPED_SLASHES) ?>;
    const ALL_USERS        = <?= json_encode($allUsernames) ?>;

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
    // Start (midnight) of the study day a moment belongs to. A study day runs
    // 04:00 → 04:00 the next morning, so before 04:00 we're still on the
    // previous day — matching the recap and the server's session bucketing, so
    // a night session counts toward the evening it started.
    function studyDayStart(d) {
        const x = startOfDay(d);
        return d.getHours() < 4 ? addDays(x, -1) : x;
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

    // Always abbreviates: first letter of each word (split on spaces and hyphens),
    // skipping "and"/"und"/"-"; roman numerals kept as-is with a preceding space.
    // Exception map takes priority (case-insensitive exact match).
    const ROMAN_RE = /^(I{1,3}|IV|VI{0,3}|IX|X{1,3}|XI{1,3}|XIV|XV|XVI{0,3})$/i;
    const ABBREV_EXCEPTIONS = {
        "softwareentwicklung ii":   "SE II",
        "datenbanksysteme i":    "DBS I",
        "datenschutz i":         "DS I",
        "betriebssysteme i":     "BS I",
        "it-sicherheit i":       "ITS I",
        "rechnerarchitektur":    "RA",
    };
    function abbrevModule(name) {
        if (!name) return name;
        const ex = ABBREV_EXCEPTIONS[name.toLowerCase()];
        if (ex) return ex;
        const SKIP = new Set(["and", "und", "-"]);
        const parts = name.split(/[\s-]+/).filter(w => w && !SKIP.has(w.toLowerCase()));
        return parts.map((w, i) => {
            const isRoman = ROMAN_RE.test(w);
            const token   = isRoman ? w.toUpperCase() : w[0].toUpperCase();
            return (isRoman && i > 0) ? " " + token : token;
        }).join("");
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
    let podiumPeriod  = "overall"; // "overall" | "weekly" | "daily"
    let libraryOnly   = false;     // when true, podium counts only at-library sessions
    let podiumView    = "all";     // "all" | "module" | "library"
    let selectedModule = null;     // active module name when podiumView === "module"

    function filteredSessions() {
        let out = SESSIONS;
        if (podiumPeriod !== "overall") {
            const today = studyDayStart(new Date());
            const todayStr = toDateStr(today);
            if (podiumPeriod === "daily") {
                out = out.filter(s => s.date === todayStr);
            } else {
                const weekStartStr = toDateStr(mondayOf(today));
                out = out.filter(s => s.date >= weekStartStr && s.date <= todayStr);
            }
        }
        if (libraryOnly) out = out.filter(s => s.at_library);
        if (podiumView === "module" && selectedModule) out = out.filter(s => s.module === selectedModule);
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
        // Anchor the week on the current study day (04:00 boundary) so a session
        // logged after midnight stays grouped with the evening it started.
        const today = studyDayStart(new Date());
        const weekStart = addDays(mondayOf(today), weekOffset * 7);
        const weekDays = [];
        for (let i = 0; i < 7; i++) weekDays.push(addDays(weekStart, i));
        const todayStr = toDateStr(today);

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
        ALL_USERS.forEach(u => { totals[u] = 0; });
        SESSIONS.forEach(s => { totals[s.username] = (totals[s.username] || 0) + s.seconds; });
        return totals;
    }

    const PERIOD_LABELS = { overall: "total studied", weekly: "this week", daily: "today" };

    const podiumFullname = document.getElementById("podium-module-fullname");

    // ── Cosmetic hats ────────────────────────────────────────────────────
    // Whichever hat a user has equipped from their inventory (see
    // includes/cosmetics.php / api/patch-cosmetic.php), only rendered on the
    // unfiltered podium (Period = Overall and View = all) — weekly/daily,
    // per-module and library podiums stay hat-free.
    function hatImg(username) {
        const hat = EQUIPPED_HATS[username];
        if (!hat) return "";
        const style = `--hx:${hat.offset_x}%;--hy:${hat.offset_y}%;--hr:${hat.rotation}deg;--hs:${hat.scale};--hflip:${hat.flip_x ? -1 : 1}`;
        return `<img class="sc-hat" style="${style}" src="${BASE_PATH}/assets/images/${hat.file}" alt="">`;
    }

    // The header's Hats tab lives on every page (including this one) and
    // fires this event on equip/unequip/save so the podium updates instantly
    // instead of waiting for the next background poll.
    window.addEventListener("cosmetic-updated", (e) => {
        const { username, equipped, cosmetic } = e.detail;
        if (equipped && cosmetic) {
            EQUIPPED_HATS[username] = {
                item_key: cosmetic.item_key,
                file:     cosmetic.file,
                offset_x: cosmetic.offset_x,
                offset_y: cosmetic.offset_y,
                rotation: cosmetic.rotation,
                scale:    cosmetic.scale,
                flip_x:   cosmetic.flip_x,
            };
        } else {
            delete EQUIPPED_HATS[username];
        }
        buildOverallPodium();
    });

    function buildOverallPodium() {
        const sessions = filteredSessions();
        const totals = {};
        // Seed every known user with 0 so they always appear.
        ALL_USERS.forEach(u => { totals[u] = 0; });
        sessions.forEach(s => { totals[s.username] = (totals[s.username] || 0) + s.seconds; });
        const ranked = Object.entries(totals).sort((a, b) => b[1] - a[1]);
        const podium = document.getElementById("overall-podium");
        const empty  = document.getElementById("overall-empty");

        empty.style.display = "none";

        // Full module name strip — always reserves its height, visible only in module view.
        if (podiumView === "module" && selectedModule) {
            podiumFullname.textContent = selectedModule;
            podiumFullname.classList.add("visible");
        } else {
            podiumFullname.textContent = " "; // non-breaking space keeps height
            podiumFullname.classList.remove("visible");
        }

        let sublabel = PERIOD_LABELS[podiumPeriod] || "total studied";
        if (podiumView === "module" && selectedModule) sublabel += " · " + abbrevModule(selectedModule);
        else if (libraryOnly) sublabel += " · at the library";
        const top = ranked.slice(0, 4);
        podium.innerHTML = top.map(([username, secs], i) => {
            const rank = i + 1;
            const color = userColor[username] || "#8b5cf6";
            const style = `border-color:${color}; background:linear-gradient(160deg, ${color}24, ${color}0a); --card-shadow:0 10px 28px ${color}33;`;
            const av = USER_AVATARS[username];
            const avatarInner = av
                ? `<img src="${av}" alt="">`
                : `<span class="sc-avatar-fallback">${escapeHtml((username[0] || "?").toUpperCase())}</span>`;
            const hat = podiumPeriod === "overall" && podiumView === "all" ? hatImg(username) : "";
            return `
                <div class="podium-card glass-card rank-${rank}" style="${style}"
                     data-username="${escapeHtml(username)}" data-rank="${rank}" data-secs="${secs}"
                     role="button" tabindex="0" aria-label="View ${escapeHtml(username)}'s study breakdown">
                    <div class="sc-avatar-wrap"><div class="sc-avatar" style="border-color:${color}">${avatarInner}</div>${hat}</div>
                    <div class="podium-name">${escapeHtml(username)}</div>
                    <div class="podium-score" style="color:${color}">${fmtTime(secs)}</div>
                    <div class="podium-sub podium-sub-time">${sublabel}</div>
                    <div class="sc-medal sc-medal-${rank}">${rank}</div>
                </div>
            `;
        }).join("");
        podium.style.display = "flex";
    }

    // ── Podium detail modal ───────────────────────────────────────────────────
    const detailModal = document.getElementById("podium-detail-modal");
    const detailBody  = document.getElementById("podium-detail-body");

    function openPodiumDetail(username, rank, totalSecs) {
        const sessions = filteredSessions().filter(s => s.username === username);

        const byModule = {};
        sessions.forEach(s => {
            byModule[s.module] = (byModule[s.module] || 0) + s.seconds;
        });
        const mods = Object.entries(byModule).sort((a, b) => b[1] - a[1]);

        const av    = USER_AVATARS[username];
        const color = userColor[username] || "#8b5cf6";
        const avatarHtml = av
            ? `<img src="${av}" alt="">`
            : `<span class="sc-avatar-fallback">${escapeHtml((username[0] || "?").toUpperCase())}</span>`;

        let sublabel = PERIOD_LABELS[podiumPeriod] || "total studied";
        if (podiumView === "module" && selectedModule) sublabel += " · " + abbrevModule(selectedModule);
        else if (libraryOnly) sublabel += " · at the library";

        const modRows = mods.map(([mod, secs]) => {
            const pct = totalSecs > 0 ? Math.round(secs / totalSecs * 100) : 0;
            const col = moduleColor[mod] || "#64748b";
            return `
                <div class="pd-mod-row">
                    <span class="module-dot" style="background:${col};width:12px;height:12px;border-radius:4px;flex-shrink:0;"></span>
                    <span class="pd-mod-name">${escapeHtml(mod)}</span>
                    <div class="pd-mod-bar-wrap">
                        <div class="pd-mod-bar" style="width:${pct}%;background:${col}"></div>
                    </div>
                    <span class="pd-mod-time">${fmtTime(secs)}</span>
                </div>
            `;
        }).join("");

        const detailHat = podiumPeriod === "overall" && podiumView === "all" ? hatImg(username) : "";
        detailBody.innerHTML = `
            <div class="sc-avatar-wrap pd-avatar-wrap"><div class="pd-avatar" style="border-color:${color}">${avatarHtml}</div>${detailHat}</div>
            <div class="pd-username" style="color:${color}">${escapeHtml(username)}</div>
            <div class="pd-rank-row">
                <span class="sc-medal sc-medal-${rank}">${rank}</span>
                <span class="pd-total-time">${fmtTime(totalSecs)}</span>
                <span class="pd-total-label">${escapeHtml(sublabel)}</span>
            </div>
            ${mods.length > 0 ? `
                <div class="pd-modules">
                    <div class="pd-modules-label">Module breakdown</div>
                    ${modRows}
                </div>
            ` : `<p class="muted" style="margin:0;">No sessions logged for this period.</p>`}
        `;

        detailModal.style.display = "flex";
    }

    function closePodiumDetail() { detailModal.style.display = "none"; }

    document.getElementById("podium-detail-close").addEventListener("click", closePodiumDetail);
    detailModal.addEventListener("click", (e) => { if (e.target === detailModal) closePodiumDetail(); });
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && detailModal.style.display !== "none") closePodiumDetail();
    });

    // Event delegation on the overall podium — works even after innerHTML rerenders
    document.getElementById("overall-podium").addEventListener("click", (e) => {
        const card = e.target.closest("[data-username]");
        if (!card) return;
        openPodiumDetail(
            card.dataset.username,
            parseInt(card.dataset.rank, 10),
            parseInt(card.dataset.secs, 10)
        );
    });
    document.getElementById("overall-podium").addEventListener("keydown", (e) => {
        if (e.key !== "Enter" && e.key !== " ") return;
        const card = e.target.closest("[data-username]");
        if (!card) return;
        e.preventDefault();
        openPodiumDetail(
            card.dataset.username,
            parseInt(card.dataset.rank, 10),
            parseInt(card.dataset.secs, 10)
        );
    });

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

    // ── Podium module filter picker modal ────────────────────────────────
    const podiumModuleModal = document.getElementById("podium-module-modal");
    const podiumModuleBtn   = document.getElementById("podium-module-btn");

    // Lock the button width to its natural "Per module" size before text ever changes.
    podiumModuleBtn.style.minWidth = podiumModuleBtn.offsetWidth + "px";

    const PODIUM_MODULE_KEY  = "podiumSelectedModule";
    const PODIUM_MODULE_DEFAULT = "Compiler";

    // Simple picker for the podium modal (no delete/add-new — read-only list).
    let podiumPickerValue = null;
    function renderPodiumModList() {
        const listEl = document.getElementById("podium-mod-list");
        const wrapEl = listEl.parentElement;
        listEl.innerHTML = MODULES.map(m => {
            const color = moduleColor[m.name] || "#64748b";
            const isActive = podiumPickerValue === m.name;
            return `<div class="mod-item${isActive ? " active" : ""}" data-val="${escapeHtml(m.name)}">
                <span class="mod-dot" style="background:${color}"></span>
                <span class="mod-name">${escapeHtml(m.name)}</span>
                ${m.custom ? `<span class="mod-custom">custom</span>` : ""}
            </div>`;
        }).join("");
        requestAnimationFrame(() => {
            const overflows = listEl.scrollHeight > listEl.clientHeight;
            const atBottom  = listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 2;
            wrapEl.classList.toggle("no-more", !overflows || atBottom);
        });
        listEl.addEventListener("scroll", () => {
            const atBottom = listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 2;
            wrapEl.classList.toggle("no-more", atBottom);
        }, { once: true });
    }

    document.getElementById("podium-mod-list").addEventListener("click", e => {
        const item = e.target.closest(".mod-item");
        if (!item) return;
        podiumPickerValue = item.dataset.val;
        renderPodiumModList();
    });

    function openPodiumModuleModal() {
        // Default: last saved → Compiler → first module
        if (!podiumPickerValue) {
            const saved = localStorage.getItem(PODIUM_MODULE_KEY);
            const hasModule = name => MODULES.some(m => m.name === name);
            podiumPickerValue = (saved && hasModule(saved)) ? saved
                : hasModule(PODIUM_MODULE_DEFAULT) ? PODIUM_MODULE_DEFAULT
                : (MODULES[0] ? MODULES[0].name : null);
        }
        renderPodiumModList();
        podiumModuleModal.style.display = "flex";
    }

    function closePodiumModuleModal() { podiumModuleModal.style.display = "none"; }

    document.getElementById("podium-module-cancel").addEventListener("click", closePodiumModuleModal);
    podiumModuleModal.addEventListener("click", e => { if (e.target === podiumModuleModal) closePodiumModuleModal(); });

    document.getElementById("podium-module-confirm").addEventListener("click", () => {
        if (!podiumPickerValue) return;
        selectedModule = podiumPickerValue;
        localStorage.setItem(PODIUM_MODULE_KEY, selectedModule);
        podiumModuleBtn.textContent = abbrevModule(selectedModule);

        // Activate module view if not already active.
        document.querySelectorAll(".seg-btn[data-view]").forEach(b => b.classList.remove("active"));
        podiumModuleBtn.classList.add("active");
        podiumView  = "module";
        libraryOnly = false;

        closePodiumModuleModal();
        updateModuleHint(true);
        buildOverallPodium();
    });

    // ── Module hint positioning ───────────────────────────────────────────
    const podiumModuleHint = document.getElementById("podium-module-hint");

    function updateModuleHint(visible) {
        if (visible) {
            // Centre the hint over the module button within the header row.
            const headerRow = podiumModuleHint.parentElement;
            const btnRect   = podiumModuleBtn.getBoundingClientRect();
            const rowRect   = headerRow.getBoundingClientRect();
            const left = (btnRect.left - rowRect.left) + btnRect.width / 2;
            podiumModuleHint.style.setProperty("--hint-left", left + "px");
            podiumModuleHint.classList.add("visible");
        } else {
            podiumModuleHint.classList.remove("visible");
        }
    }

    // ── View segmented control ────────────────────────────────────────────
    //   all     → overall combined podium, every session
    //   module  → overall podium filtered to selected module; dblclick to repick
    //   library → overall combined podium, library sessions only
    document.querySelectorAll(".seg-btn[data-view]").forEach(btn => {
        btn.addEventListener("click", () => {
            if (btn.classList.contains("active")) return;
            document.querySelectorAll(".seg-btn[data-view]").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");

            const view = btn.dataset.view;
            podiumView  = view;
            libraryOnly = (view === "library");

            if (view === "module") {
                // Resolve module: last saved → Compiler → first module.
                if (!selectedModule) {
                    const saved = localStorage.getItem(PODIUM_MODULE_KEY);
                    const has = name => MODULES.some(m => m.name === name);
                    selectedModule = (saved && has(saved)) ? saved
                        : has(PODIUM_MODULE_DEFAULT) ? PODIUM_MODULE_DEFAULT
                        : (MODULES[0] ? MODULES[0].name : null);
                }
                podiumModuleBtn.textContent = abbrevModule(selectedModule) || "Per module";
                updateModuleHint(true);
            } else {
                selectedModule = null;
                podiumModuleBtn.textContent = "Per module";
                updateModuleHint(false);
            }
            buildOverallPodium();
        });

        // Double click on the module button opens the picker.
        if (btn.dataset.view === "module") {
            btn.addEventListener("dblclick", e => {
                e.preventDefault();
                openPodiumModuleModal();
            });
        }
    });

    // ── Period segmented control ──────────────────────────────────────────
    document.querySelectorAll(".seg-btn[data-period]").forEach(tab => {
        tab.addEventListener("click", () => {
            if (tab.dataset.period === podiumPeriod) return;
            document.querySelectorAll(".seg-btn[data-period]").forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            podiumPeriod = tab.dataset.period;
            buildOverallPodium();
        });
    });

    // ── Data load + full render ───────────────────────────────────────────
    function renderAll() {
        assignUserColors(Object.keys(userTotals()));
        buildOverallPodium();
        buildLegends();
        renderChart();
        renderFocusMiniCharts();
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

    // ── Custom module picker ──────────────────────────────────────────────
    function deleteModule(name) {
        if (!confirm(`Remove "${name}" from the module list?\nPast sessions using it are kept.`)) return;
        const body = new FormData();
        body.append("name", name);
        fetch(BASE_PATH + "/api/delete-study-module.php", { method: "POST", body })
            .then(r => r.ok ? r.text() : r.text().then(t => { throw new Error(t || "Delete failed."); }))
            .then(() => window.location.reload())
            .catch(err => alert(err.message));
    }

    function createModulePicker(listEl, newWrapEl, newInputEl) {
        const wrapEl = listEl.parentElement; // .mod-list-wrap
        let value = MODULES.length > 0 ? MODULES[0].name : "__new__";

        function updateScrollHint() {
            const overflows = listEl.scrollHeight > listEl.clientHeight;
            const atBottom  = listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 2;
            wrapEl.classList.toggle("no-more", !overflows || atBottom);
        }

        function render() {
            const items = MODULES.map(m => {
                const color    = moduleColor[m.name] || "#64748b";
                const isActive = value === m.name;
                return `<div class="mod-item${isActive ? " active" : ""}" data-val="${escapeHtml(m.name)}">
                    <span class="mod-dot" style="background:${color}"></span>
                    <span class="mod-name">${escapeHtml(m.name)}</span>
                    ${m.custom ? `<span class="mod-custom">custom</span>` : ""}
                    ${m.custom ? `<button type="button" class="btn mod-delete" data-name="${escapeHtml(m.name)}" title="Remove module">&#x2715;</button>` : ""}
                </div>`;
            });
            items.push(`<div class="mod-item mod-item-new${value === "__new__" ? " active" : ""}" data-val="__new__">
                <span class="mod-new-plus">+</span>
                <span class="mod-name">Add new module…</span>
            </div>`);
            listEl.innerHTML = items.join("");
            requestAnimationFrame(updateScrollHint);
        }

        function select(val) {
            value = val;
            render();
            const isNew = val === "__new__";
            newWrapEl.classList.toggle("show", isNew);
            if (isNew) setTimeout(() => newInputEl.focus(), 0);
        }

        listEl.addEventListener("scroll", updateScrollHint);
        listEl.addEventListener("click", e => {
            const del = e.target.closest(".mod-delete");
            if (del) { e.stopPropagation(); deleteModule(del.dataset.name); return; }
            const item = e.target.closest(".mod-item");
            if (item) select(item.dataset.val);
        });

        render();
        if (value === "__new__") newWrapEl.classList.add("show");

        return {
            getValue:   () => value,
            getNewName: () => newInputEl.value.trim(),
            setValue:   (v) => { if (value !== v) select(v); },
        };
    }

    // Manual logging picker
    const newWrap  = document.getElementById("new-module-wrap");
    const newInput = document.getElementById("new-module-input");
    const manualPicker = createModulePicker(
        document.getElementById("manual-mod-list"),
        newWrap,
        newInput
    );

    function selectedModulePayload() {
        if (manualPicker.getValue() === "__new__") {
            const name = manualPicker.getNewName();
            if (name === "") return { error: "Enter a name for the new module." };
            return { new_module: name };
        }
        return { module: manualPicker.getValue() };
    }

    // ── Feedback ──────────────────────────────────────────────────────────
    // `setFeedback` → the card (dock/session errors); `setManualFeedback` → the
    // manual-logging pop-up, so its messages stay inside that window.
    const feedback = document.getElementById("log-feedback");
    function setFeedback(msg, isError) {
        feedback.textContent = msg;
        feedback.classList.toggle("error", !!isError);
    }
    const manualFeedback = document.getElementById("manual-feedback");
    function setManualFeedback(msg, isError) {
        manualFeedback.textContent = msg;
        manualFeedback.classList.toggle("error", !!isError);
    }

    // opts: { startTime, endTime } for exact mode, or { seconds, studiedOn }.
    function submitSession(opts) {
        const mod = selectedModulePayload();
        if (mod.error) { setManualFeedback(mod.error, true); return; }

        const exact = opts.startTime && opts.endTime;
        const body = new FormData();
        if (exact) {
            body.append("start_time", opts.startTime);
            body.append("end_time", opts.endTime);
        } else {
            if (!opts.seconds || opts.seconds <= 0) { setManualFeedback("Nothing to log yet.", true); return; }
            body.append("seconds", opts.seconds);
            if (opts.studiedOn) body.append("studied_on", opts.studiedOn);
        }
        if (mod.module)     body.append("module", mod.module);
        if (mod.new_module) body.append("new_module", mod.new_module);

        setManualFeedback("Saving…", false);
        fetch(BASE_PATH + "/api/log-study-session.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Save failed."); }))
            .then(res => {
                setManualFeedback(`Logged ${fmtTime(res.seconds)} of ${res.module}.`, false);
                if (opts.onDone) opts.onDone();
                // A new custom module changes the shared list — reload to pick up
                // colors, legend, and the dropdown entry.
                if (res.custom && manualPicker.getValue() === "__new__") {
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    loadData();
                }
            })
            .catch(err => setManualFeedback(err.message, true));
    }

    // ── Manual logging pop-up ─────────────────────────────────────────────
    const manualModal = document.getElementById("manual-modal");
    function openManualModal()  { setManualFeedback("", false); manualModal.style.display = "flex"; }
    function closeManualModal() { manualModal.style.display = "none"; }
    document.getElementById("manual-toggle").addEventListener("click", openManualModal);
    document.getElementById("manual-close").addEventListener("click", closeManualModal);
    manualModal.addEventListener("click", (e) => { if (e.target === manualModal) closeManualModal(); });

    // ── Manage previous sessions (browse a week of my sessions, delete) ────
    const manageModal    = document.getElementById("manage-modal");
    const manageListEl   = document.getElementById("manage-list");
    const manageWeekLbl  = document.getElementById("manage-week-label");
    let   manageSessions = [];   // [{id, module, seconds, date, started_at, created_at, at_library}]
    let   manageOffset   = 0;    // 0 = current week, -1 = previous, …

    function openManageModal() {
        manageOffset = 0;
        manageListEl.innerHTML = `<p class="manage-empty">Loading…</p>`;
        manageModal.style.display = "flex";
        fetch(BASE_PATH + "/api/get-my-study-sessions.php")
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(res => { manageSessions = res.sessions || []; renderManageWeek(); })
            .catch(() => { manageListEl.innerHTML = `<p class="manage-empty">Couldn't load your sessions.</p>`; });
    }
    function closeManageModal() { manageModal.style.display = "none"; }

    function sessionClock(s) {
        const raw = s.started_at || s.created_at;
        if (!raw) return "";
        const d = new Date(raw.replace(" ", "T"));
        if (isNaN(d)) return "";
        return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
    }

    function renderManageWeek() {
        const today     = studyDayStart(new Date());
        const weekStart = addDays(mondayOf(today), manageOffset * 7);
        const weekDays  = [];
        for (let i = 0; i < 7; i++) weekDays.push(addDays(weekStart, i));
        const todayStr  = toDateStr(today);

        manageWeekLbl.textContent = manageOffset === 0
            ? "This week"
            : `${weekStart.toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ${weekDays[6].toLocaleDateString(undefined, { month: "short", day: "numeric" })}`;
        document.getElementById("manage-next").disabled = manageOffset >= 0;

        // Group this week's sessions by day.
        const byDay = {};
        weekDays.forEach(d => { byDay[toDateStr(d)] = []; });
        manageSessions.forEach(s => { if (s.date in byDay) byDay[s.date].push(s); });

        const blocks = weekDays.map(d => {
            const ds   = toDateStr(d);
            const list = byDay[ds].slice().sort((a, b) =>
                String(a.started_at || a.created_at).localeCompare(String(b.started_at || b.created_at)));
            if (list.length === 0) return "";
            const label = d.toLocaleDateString(undefined, { weekday: "long", month: "short", day: "numeric" })
                + (ds === todayStr ? " · today" : "");
            const rows = list.map(s => {
                const color = moduleColor[s.module] || "#64748b";
                const clock = sessionClock(s);
                const meta  = [clock, s.at_library ? `<span class="manage-row-lib">📚 library</span>` : ""]
                    .filter(Boolean).join(" · ");
                return `<div class="manage-row" data-id="${s.id}">
                    <span class="module-dot" style="background:${color}"></span>
                    <div class="manage-row-main">
                        <div class="manage-row-mod">${escapeHtml(s.module || "—")}</div>
                        <div class="manage-row-meta">${meta}</div>
                    </div>
                    <span class="manage-row-time">${fmtTime(s.seconds)}</span>
                    <button type="button" class="btn manage-row-del" data-id="${s.id}" title="Delete session">✕</button>
                </div>`;
            }).join("");
            return `<div><div class="manage-day-label">${label}</div>${rows}</div>`;
        }).join("");

        manageListEl.innerHTML = blocks || `<p class="manage-empty">No sessions this week.</p>`;
    }

    manageListEl.addEventListener("click", (e) => {
        const btn = e.target.closest(".manage-row-del");
        if (!btn) return;
        const id = +btn.dataset.id;
        if (!confirm("Delete this study session? This can't be undone.")) return;
        const body = new FormData();
        body.append("id", id);
        fetch(BASE_PATH + "/api/delete-study-session.php", { method: "POST", body })
            .then(r => { if (!r.ok) throw new Error(); return r.text(); })
            .then(() => {
                manageSessions = manageSessions.filter(s => s.id !== id);
                renderManageWeek();
                loadData();      // refresh chart + podiums
                loadStatus();    // refresh the dock recap (segments may have gone)
            })
            .catch(() => alert("Couldn't delete that session."));
    });

    document.getElementById("manage-toggle").addEventListener("click", openManageModal);
    document.getElementById("manage-close").addEventListener("click", closeManageModal);
    manageModal.addEventListener("click", (e) => { if (e.target === manageModal) closeManageModal(); });
    document.getElementById("manage-prev").addEventListener("click", () => { manageOffset -= 1; renderManageWeek(); });
    document.getElementById("manage-next").addEventListener("click", () => { if (manageOffset < 0) { manageOffset += 1; renderManageWeek(); } });

    // ── My study state (server-backed presence session) ───────────────────
    // The live stopwatch lives in the `study_status` table (so it survives
    // reloads); the page drives it through the "Currently studying" dock.
    // Mirrors the server's study_status row for me.
    let myState = { active: false, mode: null, running: false, elapsed: 0, breakElapsed: 0, module: null, atLibrary: false, parts: [] };
    let ticker  = null;

    function manageTicker() {
        clearInterval(ticker);
        ticker = null;
        if (!myState.active) return;
        ticker = setInterval(() => {
            if (myState.running) {
                myState.elapsed += 1; // keeps my chip ticking live
            } else {
                // On break — the study time is frozen, the break time ticks up.
                myState.breakElapsed += 1;
            }
            // Whichever part is still open (a running module, or an
            // in-progress break) ticks too, so the breakdown line isn't stuck
            // between polls — this is the same figure the server folded any
            // short interruptions into, so it just keeps counting up as one
            // continuous number rather than resetting when it started.
            const live = myState.parts.find(p => p.live);
            if (live) live.seconds += 1;
        }, 1000);
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
            parts:        me.parts || [],
        };
        manageTicker();
        renderPresenceButtons();
    }

    function timerAction(action, extra) {
        const body = new FormData();
        body.append("action", action);
        if (extra) {
            if (extra.module)     body.append("module", extra.module);
            if (extra.new_module) body.append("new_module", extra.new_module);
            if (extra.sessions)   body.append("sessions", JSON.stringify(extra.sessions));
        }
        return fetch(BASE_PATH + "/api/study-timer.php", { method: "POST", body })
            .then(r => r.ok ? r.json() : r.text().then(t => { throw new Error(t || "Timer error."); }))
            .then(res => {
                // A brand-new custom module changes the shared list — reload so the
                // dropdown, colors and legend pick it up. The session is persistent,
                // so it simply resumes after the reload.
                if (res.custom_added) setTimeout(() => window.location.reload(), 400);
                applyMyState(res.me);
                renderStudying(res.studying);
                setRecaps(res);
                return res;
            });
    }

    // ── Currently studying ────────────────────────────────────────────────
    const studyingList   = document.getElementById("studying-list");
    const toggleStudyBtn = document.getElementById("studying-toggle");
    const moduleBtn      = document.getElementById("studying-module");
    const mySession      = document.getElementById("my-session");
    const myModuleName   = document.getElementById("my-module-name");
    const breakBtn       = document.getElementById("studying-break");
    const breakBox       = document.getElementById("studying-break-box");
    const breakList      = document.getElementById("break-list");
    const bibBtn         = document.getElementById("studying-bib");
    const libraryBox     = document.getElementById("studying-library-box");
    const libraryList    = document.getElementById("library-list");

    // ── Day timeline ──────────────────────────────────────────────────────────
    // Recap blocks are one-per-study-interval, positioned in minutes from the
    // study day's midnight (server-anchored). The study day runs 04:00 → 03:00
    // the next morning, so post-midnight work lands in the 24:00–28:00 tail.
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

    // The study day runs 04:00 → 03:00 next morning. Minutes after midnight but
    // before the start belong to the previous evening's night shift, so they map
    // to the tail of the window (e.g. 02:00 → 26:00).
    const DAY_START_MIN = 4 * 60;              // 04:00 (top of the axis)
    const DAY_END_MIN   = 27 * 60;             // 03:00 next morning (bottom of the axis)
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

        // Clamp every interval into the study day's window (04:00–03:00 next day);
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
        // The now-line always reflects the real current time, so today's current
        // hour is always shown — even when the last activity was hours ago (the
        // gap in between collapses like any other empty run, same as elsewhere).
        if (isToday) activeSet.add(Math.floor(nowMin / 60));

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
        // The current hour (or a live block) grew into a not-yet-shown hour →
        // re-layout to add it (and collapse the newly-stale one behind it).
        if (!(nowHour in tlLayout.hourTop)) { renderTimeline(); return; }

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

        // Primary button: start the session, or stop & log it.
        if (!active) {
            toggleStudyBtn.classList.remove("active");
            toggleStudyBtn.textContent = "I'm studying";
        } else {
            toggleStudyBtn.classList.add("active");
            toggleStudyBtn.textContent = "Stop session";
        }

        // Current-module display (only while active).
        mySession.style.display = active ? "" : "none";
        if (active) myModuleName.textContent = myState.module || "—";

        // Module / break / library actions only make sense during a session.
        moduleBtn.style.display = active ? "" : "none";

        if (running) {
            breakBtn.style.display = "";
            breakBtn.textContent = "Take a break";
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
            bibBtn.textContent = myState.atLibrary ? "Leave library" : "At the library";
        } else {
            bibBtn.style.display = "none";
            bibBtn.classList.remove("active");
        }

        if (typeof renderFocusClock === "function") renderFocusClock();
    }

    // The full chronological history of a session: each module run and each
    // break, in the order they happened. The server (studyMergeRuns() in
    // includes/study-status.php) already folds interruptions shorter than 5
    // minutes into whichever run they interrupted — so a brief break during
    // continuous studying (or a brief module check during a break) shows up
    // as more time on the run it interrupted rather than as its own tiny
    // line, and `s.parts` here is never fragmented or missing that time.
    // `includeLiveBreak: false` drops the in-progress break entry for
    // callers (the focus clock) that already show an "On break" indicator of
    // their own and would otherwise show the same number twice.
    function breakdownParts(s, includeLiveBreak = true) {
        return (s.parts || []).filter(p => {
            if (p.type === "break") return includeLiveBreak || !p.live;
            return !!p.module;
        });
    }

    // Every chip is rendered as a breakdown: one line per history entry
    // (module run or break), using the full module name — fitBreakdownLines()
    // shrinks any line that overflows the chip down to its podium
    // abbreviation afterward. The still-open module or break (`p.live`, not
    // array position) is picked out in blue/green/yellow so it reads as
    // "what's happening right now"; finished entries stay the neutral grey
    // of the rest of the chip. The "Session · total" header line (green
    // while running, red while on break) only appears once there's actually
    // more than one entry to sum — a plain single-module, no-break session
    // is just its one line, no separate total needed.
    function sessionMeta(s, isRunning, i, timeAttr) {
        const parts = breakdownParts(s);
        const attr  = timeAttr ? ` ${timeAttr}="${i}"` : "";

        const lines = parts.map((p) => {
            if (p.type === "break") {
                const liveAttr = p.live ? ` data-brk-idx="${i}"` : "";
                return `<span class="chip-breakdown-line break-line${p.live ? " live" : ""}">` +
                    `<span class="bd-name">Break</span> · ` +
                    `<span class="bd-time"${liveAttr}>${fmtClock(p.seconds)}</span>` +
                    `</span>`;
            }
            const isLive   = isRunning && p.live;
            const liveCls  = isLive ? (s.at_library ? " live-lib" : " live") : "";
            const liveAttr = isLive ? ` data-mod-idx="${i}"` : "";
            return `<span class="chip-breakdown-line${liveCls}" data-module="${escapeHtml(p.module)}">` +
                `<span class="bd-name">${escapeHtml(p.module)}</span> · ` +
                `<span class="bd-time"${liveAttr}>${fmtClock(p.seconds)}</span>` +
                `</span>`;
        });

        const sessionCls = isRunning ? "session-active" : "session-break";
        const header = parts.length > 1
            ? `<span class="chip-time-label ${sessionCls}">Session · </span>` +
              `<span class="chip-time ${sessionCls}"${attr}>${fmtClock(s.elapsed)}</span>`
            : "";

        return `${header}<span class="chip-module-breakdown">${lines.join("")}</span>`;
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
                        <span class="chip-meta">${sessionMeta(s, true, i, "data-idx")}</span>
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
                // Study time is frozen; the live "Break" breakdown line ticks up.
                return `
                    <span class="studying-chip break ${isMe ? "me" : ""}">
                        <span class="pulse-dot paused"></span>
                        <span class="chip-name" style="color:${colorFor(s.username)}">${escapeHtml(s.username)}</span>
                        <span class="chip-meta">${sessionMeta(s, false, i, null)}</span>
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
                return `
                    <span class="studying-chip library ${isMe ? "me" : ""}">
                        <span class="${dotCls}"></span>
                        <span class="chip-name" style="color:${colorFor(s.username)}">${escapeHtml(s.username)}</span>
                        <span class="chip-meta">${sessionMeta(s, s.running, i, "data-libidx")}</span>
                    </span>
                `;
            }).join("");
        }

        fitBreakdownLines();
        syncDockHeight();
    }

    // A module-breakdown line renders with the full module name first; if that
    // makes the chip wider than its column (the row wraps mid-word or the
    // chip overflows), swap that chip's lines down to the podium abbreviation
    // instead. Checked per-chip since chip width can vary with the username.
    function fitBreakdownLines() {
        document.querySelectorAll(".studying-chip").forEach(chip => {
            if (chip.scrollWidth <= chip.clientWidth) return;
            chip.querySelectorAll(".chip-breakdown-line[data-module]").forEach(line => {
                const nameEl = line.querySelector(".bd-name");
                if (nameEl) nameEl.textContent = abbrevModule(line.dataset.module);
            });
        });
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
            if (el) {
                const secs = (s.username === MY_USERNAME && myState.active && myState.running)
                    ? myState.elapsed
                    : s.elapsed + delta;
                el.textContent = fmtClock(secs);
            }
            const modEl = studyingList.querySelector(`.bd-time[data-mod-idx="${i}"]`);
            if (modEl) modEl.textContent = fmtClock(liveModuleSeconds(s, delta));
        });

        breakEntries.forEach((s, i) => {
            const el = breakList.querySelector(`.bd-time[data-brk-idx="${i}"]`);
            if (!el) return;
            const secs = (s.username === MY_USERNAME && myState.active && !myState.running)
                ? myState.breakElapsed
                : (s.break_elapsed || 0) + delta;
            el.textContent = fmtClock(secs);
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
            if (s.running) {
                const modEl = libraryList.querySelector(`.bd-time[data-mod-idx="${i}"]`);
                if (modEl) modEl.textContent = fmtClock(liveModuleSeconds(s, delta));
            } else {
                const brEl = libraryList.querySelector(`.bd-time[data-brk-idx="${i}"]`);
                if (brEl) {
                    const secs = (s.username === MY_USERNAME && myState.active && !myState.running)
                        ? myState.breakElapsed
                        : (s.break_elapsed || 0) + delta;
                    brEl.textContent = fmtClock(secs);
                }
            }
        });
    }

    // The live (currently open) module part for a chip: my own row mirrors
    // myState.parts (already ticking every second via manageTicker); other
    // users' rows extrapolate from the last poll with the shared delta.
    function liveModuleSeconds(s, delta) {
        const isMe = s.username === MY_USERNAME && myState.active && myState.running;
        const parts = ((isMe ? myState.parts : s.parts) || []).filter(p => p.module);
        if (!parts.length) return 0;
        const live = parts.find(p => p.live) || parts[parts.length - 1];
        return isMe ? live.seconds : live.seconds + delta;
    }

    // "I'm studying" prompts for a module first (the session starts with it);
    // "Stop session" opens the log modal to save/trim each module sub-session.
    toggleStudyBtn.addEventListener("click", () => {
        if (!myState.active) {
            openModuleModal();
        } else {
            openStopModal();
        }
    });

    // "Take a break" pauses whatever's running; "Resume" starts it again.
    breakBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction(myState.running ? "pause" : "resume")
            .catch(err => setFeedback(err.message, true));
    });

    // "At the library" — toggle the at-library flag. Independent from
    // running/break: you can be at the library while studying, or on break.
    bibBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction("library").catch(err => setFeedback(err.message, true));
    });

    // ── Module picker modal (start a session, or switch its module) ─────────
    const moduleModal  = document.getElementById("module-modal");
    const sessNewWrap  = document.getElementById("sess-new-module-wrap");
    const sessNewInput = document.getElementById("sess-new-module-input");
    const sessTitle    = document.getElementById("sess-module-title");
    const sessHint     = document.getElementById("sess-module-hint");
    const sessSetBtn   = document.getElementById("sess-module-set");

    const sessPicker = createModulePicker(
        document.getElementById("sess-mod-list"),
        sessNewWrap,
        sessNewInput
    );

    function openModuleModal() {
        const starting = !myState.active;
        sessTitle.textContent  = starting ? "Start studying" : "Change module";
        sessHint.textContent   = starting
            ? "Pick a module to track this session."
            : "Switching keeps the time so far under the old module and starts a fresh session — both show up separately.";
        sessSetBtn.textContent = starting ? "Start studying" : "Change module";

        // Default to the current module if it's still in the list.
        if (myState.module && MODULES.some(m => m.name === myState.module)) {
            sessPicker.setValue(myState.module);
        } else {
            sessPicker.setValue(MODULES.length > 0 ? MODULES[0].name : "__new__");
        }
        sessNewInput.value = "";
        sessNewWrap.classList.toggle("show", sessPicker.getValue() === "__new__");
        moduleModal.style.display = "flex";
    }
    function closeModuleModal() { moduleModal.style.display = "none"; }

    moduleBtn.addEventListener("click", () => {
        if (!myState.active) return;
        openModuleModal();
    });
    document.getElementById("sess-module-cancel").addEventListener("click", closeModuleModal);
    moduleModal.addEventListener("click", (e) => { if (e.target === moduleModal) closeModuleModal(); });

    sessSetBtn.addEventListener("click", () => {
        const extra = {};
        if (sessPicker.getValue() === "__new__") {
            const name = sessNewInput.value.trim();
            if (name === "") { sessNewInput.focus(); return; }
            extra.new_module = name;
        } else if (sessPicker.getValue()) {
            extra.module = sessPicker.getValue();
        } else {
            return;
        }
        // Not studying yet → start the session with this module; otherwise switch.
        const action = myState.active ? "set_module" : "presence";
        closeModuleModal();
        timerAction(action, extra).catch(err => setFeedback(err.message, true));
    });

    // ── Stop → per-module log modal (− trims time, × drops a session) ───────
    const stopModal     = document.getElementById("stop-modal");
    const stopChecklist = document.getElementById("stop-checklist");
    const TRIM_STEP     = 300; // seconds removed per − click (5 min)
    let stopRows        = [];  // [{module, full, secs, removed}]

    function renderStopRows() {
        stopChecklist.innerHTML = stopRows.map((r, i) => {
            if (!r.module) {
                return `<div class="stop-part disabled">
                    <span class="muted">No module · ${fmtTime(r.full)} — won't be saved</span>
                </div>`;
            }
            const color = moduleColor[r.module] || "#64748b";
            return `<div class="stop-part ${r.removed ? "removed" : ""}" data-i="${i}">
                <span class="module-dot" style="background:${color}"></span>
                <span class="stop-part-name">${escapeHtml(r.module)}</span>
                <span class="stop-part-time">${fmtTime(r.secs)}</span>
                <button type="button" class="btn stop-part-btn minus" data-act="minus" data-i="${i}" title="Remove 5 min" ${r.removed ? "disabled" : ""}>−</button>
                <button type="button" class="btn stop-part-btn plus" data-act="plus" data-i="${i}" title="Add 5 min" ${r.removed ? "disabled" : ""}>+</button>
                <button type="button" class="btn stop-part-btn remove" data-act="remove" data-i="${i}" title="${r.removed ? "Restore" : "Drop this session"}">${r.removed ? "↺" : "✕"}</button>
            </div>`;
        }).join("") || `<p class="muted">No study time recorded yet.</p>`;
    }

    function openStopModal() {
        stopRows = (myState.parts || [])
            .filter(p => p.type !== "break" && p.module)
            .map(p => ({
                module:  p.module,
                full:    p.seconds,
                secs:    p.seconds,
                removed: false,
            }));
        renderStopRows();
        stopModal.style.display = "flex";
    }

    function closeStopModal() { stopModal.style.display = "none"; }

    stopChecklist.addEventListener("click", (e) => {
        const btn = e.target.closest(".stop-part-btn");
        if (!btn) return;
        const r = stopRows[+btn.dataset.i];
        if (!r) return;
        if (btn.dataset.act === "minus") {
            r.secs = Math.max(60, r.secs - TRIM_STEP);     // floor at 1 min
        } else if (btn.dataset.act === "plus") {
            r.secs = Math.min(86400, r.secs + TRIM_STEP);  // cap at 24h
        } else {
            r.removed = !r.removed;
        }
        renderStopRows();
    });

    document.getElementById("stop-cancel").addEventListener("click", closeStopModal);
    stopModal.addEventListener("click", (e) => { if (e.target === stopModal) closeStopModal(); });

    // Discard all: stop studying without recording anything.
    document.getElementById("stop-discard").addEventListener("click", () => {
        closeStopModal();
        timerAction("stop").catch(err => setFeedback(err.message, true));
    });

    // Save: keep the non-removed modules (one session each, trimmed seconds).
    document.getElementById("stop-log").addEventListener("click", () => {
        const sessions = stopRows
            .filter(r => r.module && !r.removed)
            .map(r => ({ module: r.module, seconds: r.secs }));

        closeStopModal();
        setFeedback("Saving…", false);
        timerAction("log", { sessions })
            .then(res => {
                if (res.logged) {
                    const n = res.count || res.modules.length;
                    setFeedback(`Logged ${n} session${n === 1 ? "" : "s"} · ${fmtTime(res.seconds)}.`, false);
                    loadData();
                } else {
                    setFeedback("Session stopped — nothing logged.", false);
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
        updateStickyMode();
    }

    // Recap (back face) stays sticky only while it's short enough that sticking
    // wouldn't push it lower than the main column's content — otherwise it
    // would hang past where the rest of the page already ended.
    const studyMain = document.querySelector(".study-main");
    function updateStickyMode() {
        if (!dockCard.classList.contains("recap") || !studyMain) {
            dockCard.classList.remove("recap-overflow");
            return;
        }
        const topOffset = parseFloat(getComputedStyle(dockCard).top) || 0;
        const fits = backFace.offsetHeight + topOffset <= studyMain.offsetHeight;
        dockCard.classList.toggle("recap-overflow", !fits);
    }

    let flipPhase = [null, null];
    function flipDock(showBack) {
        clearTimeout(flipPhase[0]);
        clearTimeout(flipPhase[1]);

        // Clear any mid-flight animation so restart is clean.
        frontFace.style.animation = "";
        backFace.style.animation  = "";
        void dockCard.offsetHeight; // flush

        const outFace = showBack ? frontFace : backFace;
        const inFace  = showBack ? backFace  : frontFace;
        const outAnim = showBack ? "flip-out"     : "flip-out-rev";
        const inAnim  = showBack ? "flip-in"      : "flip-in-rev";

        // Update layout state NOW (before any animation) so no mid-animation
        // reflow disturbs the running transform and flashes the back face.
        flipInner.classList.toggle("flipped", showBack);
        dockCard.classList.toggle("recap", showBack);
        syncDockHeight();

        // Phase 1: outgoing face rotates to 90° (geometrically edge-on).
        outFace.style.visibility = "visible";
        outFace.style.animation  = `${outAnim} 200ms ease-in forwards`;

        flipPhase[0] = setTimeout(() => {
            // Face is edge-on and invisible — safe to swap.
            outFace.style.visibility = "hidden";
            outFace.style.animation  = "";

            // Phase 2: incoming face rotates from 90° to face the viewer.
            inFace.style.visibility = "visible";
            inFace.style.animation  = `${inAnim} 200ms ease-out forwards`;

            flipPhase[1] = setTimeout(() => {
                inFace.style.animation = "";
                flipPhase = [null, null];
            }, 230);
        }, 200);
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

    const PAGE_TITLE = document.title;
    function tickTitle() {
        if (!myState.active) {
            document.title = PAGE_TITLE;
        } else if (myState.running) {
            document.title = fmtClock(myState.elapsed) + " · " + PAGE_TITLE;
        } else {
            document.title = "Break " + fmtClock(myState.breakElapsed) + " · " + PAGE_TITLE;
        }
    }

    // Tick the studying chips locally every second; re-sync from the server
    // every 15s (covers other tabs and other users). The tab title clock is
    // the one thing users actually rely on while the tab is backgrounded, so
    // it keeps ticking even when hidden — only the (invisible) DOM updates
    // are skipped for performance.
    setInterval(() => {
        tickTitle();
        if (document.hidden) return;
        tickStudying(); tickTimeline(); renderFocusClock();
    }, 1000);
    setInterval(() => { if (!document.hidden) loadStatus(); }, 15000);
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
    const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/; // strict 24h HH:MM

    // Keep the text time fields as 24h HH:MM: digits only, auto-insert the colon.
    function bindTimeField(el) {
        el.addEventListener("input", () => {
            let d = el.value.replace(/\D/g, "").slice(0, 4);
            el.value = d.length > 2 ? d.slice(0, 2) + ":" + d.slice(2) : d;
        });
    }
    bindTimeField(manualStart);
    bindTimeField(manualEnd);

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
            const start = manualStart.value.trim(), end = manualEnd.value.trim();
            if (!TIME_RE.test(start) || !TIME_RE.test(end)) {
                setManualFeedback("Enter start and end as 24h HH:MM.", true);
                return;
            }
            submitSession({ startTime: start, endTime: end });
        } else {
            const h = parseInt(document.getElementById("manual-hours").value, 10) || 0;
            const m = parseInt(document.getElementById("manual-minutes").value, 10) || 0;
            submitSession({ seconds: h * 3600 + m * 60, studiedOn: manualDate.value || null });
        }
    });

    // ── Focus mode ────────────────────────────────────────────────────────
    const FOCUS_KEY          = "studyFocusMode";
    const FOCUS_SIDEBAR_KEY  = "studyFocusSidebarCollapsed";
    const focusToggleBtn    = document.getElementById("focus-toggle");
    const focusSidebarBtn   = document.getElementById("focus-sidebar-toggle");
    const focusDockBtn      = document.getElementById("focus-dock-toggle");
    let sidebarCollapsed    = localStorage.getItem(FOCUS_SIDEBAR_KEY) === "1";
    let dockCollapsed       = localStorage.getItem("studyFocusDockCollapsed") === "1";

    function applySidebarCollapse() {
        document.body.classList.toggle("focus-sidebar-collapsed", sidebarCollapsed);
        focusSidebarBtn.innerHTML = sidebarCollapsed ? "&#x203A;" : "&#x2039;";
        focusSidebarBtn.title = sidebarCollapsed ? "Show sidebar" : "Hide sidebar";
    }

    function applyDockCollapse() {
        document.body.classList.toggle("focus-dock-collapsed", dockCollapsed);
        focusDockBtn.innerHTML = dockCollapsed ? "&#x2039;" : "&#x203A;";
        focusDockBtn.title = dockCollapsed ? "Show sidebar" : "Hide sidebar";
    }

    focusSidebarBtn.addEventListener("click", () => {
        sidebarCollapsed = !sidebarCollapsed;
        localStorage.setItem(FOCUS_SIDEBAR_KEY, sidebarCollapsed ? "1" : "0");
        applySidebarCollapse();
    });

    focusDockBtn.addEventListener("click", () => {
        dockCollapsed = !dockCollapsed;
        localStorage.setItem("studyFocusDockCollapsed", dockCollapsed ? "1" : "0");
        applyDockCollapse();
    });
    const focusClockEl    = document.getElementById("focus-clock");
    const focusModuleEl   = document.getElementById("focus-clock-module");
    const focusActionsEl  = document.getElementById("focus-actions");
    const focusStartBtn   = document.getElementById("focus-start-btn");
    const focusBreakBtn   = document.getElementById("focus-break-btn");
    const focusStopBtn    = document.getElementById("focus-stop-btn");

    let focusMode = localStorage.getItem(FOCUS_KEY) === "1";

    function applyFocusMode() {
        document.body.classList.toggle("focus-mode", focusMode);
        focusToggleBtn.textContent = focusMode ? "Exit focus" : "Focus mode";
        applySidebarCollapse();
        applyDockCollapse();
        renderFocusClock();
        renderFocusMiniCharts();
    }

    function renderFocusClock() {
        if (!focusMode) return;
        const active  = myState.active;
        const running = active && myState.running;

        if (!active) {
            focusClockEl.textContent = "00:00";
            focusClockEl.classList.remove("on-break");
            focusModuleEl.textContent = "";
            focusStartBtn.style.display = "";
            focusBreakBtn.style.display = "none";
            focusStopBtn.style.display  = "none";
            return;
        }

        focusStartBtn.style.display = "none";
        focusBreakBtn.style.display = "";
        focusStopBtn.style.display  = "";

        // A session that switched modules, or has taken a break, shows a total
        // line plus each history entry on its own line — including a break
        // still in progress — so the big (whole-session) clock above isn't
        // misread as time spent in the current module alone, and there's no
        // need for a separate "on break" readout.
        const parts = breakdownParts(myState);
        if (parts.length > 1) {
            const lines = [`Session · ${fmtClock(myState.elapsed)}`, ...parts.map(p =>
                p.type === "break" ? `Break · ${fmtClock(p.seconds)}` : `${abbrevModule(p.module)} · ${fmtClock(p.seconds)}`
            )];
            focusModuleEl.innerHTML = lines.map(escapeHtml).join("<br>");
        } else {
            // The lone entry can be a break rather than a module — e.g. a
            // session that opened with a too-short-to-stand-alone module tap
            // gets folded entirely into the break that followed it (see
            // studyMergeRuns()) — so this can't just assume myState.module.
            const only = parts[0];
            focusModuleEl.textContent = only && only.type === "break" ? "Break" : (myState.module || "");
        }

        if (running) {
            focusClockEl.textContent = fmtClock(myState.elapsed);
            focusClockEl.classList.remove("on-break");
            focusBreakBtn.textContent = "Take a break";
        } else {
            focusClockEl.textContent = fmtClock(myState.elapsed);
            focusClockEl.classList.add("on-break");
            focusBreakBtn.textContent = "Resume";
        }
    }

    function fmtMini(secs) {
        if (!secs) return "";
        if (secs >= 3600) {
            const h = Math.round(secs / 360) / 10;
            return (h % 1 === 0 ? Math.round(h) : h) + "h";
        }
        return Math.round(secs / 60) + "m";
    }

    function renderFocusMiniCharts() {
        const today = studyDayStart(new Date());
        const todayStr = toDateStr(today);
        const myColor = colorFor(MY_USERNAME);
        const BAR_MAX_PX = 88;

        document.querySelectorAll(".focus-mini-week").forEach(weekEl => {
            const offset = parseInt(weekEl.dataset.weekOffset, 10);
            const weekStart = addDays(mondayOf(today), offset * 7);
            const weekDays = [];
            for (let i = 0; i < 7; i++) weekDays.push(addDays(weekStart, i));

            const byDay = {};
            weekDays.forEach(d => { byDay[toDateStr(d)] = 0; });
            SESSIONS.forEach(s => {
                if (s.username !== MY_USERNAME) return;
                if (s.date in byDay) byDay[s.date] += s.seconds;
            });

            const values = weekDays.map(d => byDay[toDateStr(d)]);
            const maxSecs = Math.max(...values, 1);

            const weekTotal = values.reduce((a, b) => a + b, 0);
            const activeDays = values.filter(v => v > 0).length;
            const avgPerDay = activeDays > 0 ? weekTotal / activeDays : 0;
            const totalEl = weekEl.querySelector(".focus-mini-total");
            if (totalEl) totalEl.innerHTML = weekTotal > 0
                ? `<span class="focus-mini-total-sum">${fmtTime(weekTotal)}</span><span class="focus-mini-total-avg">avg ${fmtMini(avgPerDay)}/d</span>`
                : "";

            const barsWrap = weekEl.querySelector(".focus-mini-bars-wrap");
            barsWrap.innerHTML = weekDays.map((d, i) => {
                const secs = values[i];
                const isToday = toDateStr(d) === todayStr;
                const hasData = secs > 0;
                const barH = hasData ? Math.max(Math.round((secs / maxSecs) * BAR_MAX_PX), 3) : 2;
                const barColor = hasData
                    ? myColor + (isToday ? "" : "99")
                    : "rgba(255,255,255,0.07)";
                const lbl = d.toLocaleDateString(undefined, { weekday: "narrow" });
                const val = fmtMini(secs);
                return `<div class="focus-mini-day${isToday ? " is-today" : ""}${hasData ? " has-data" : ""}" data-date="${toDateStr(d)}">
                    <div class="focus-mini-bar" style="height:${barH}px;background:${barColor};">
                        ${val ? `<span class="focus-mini-val">${escapeHtml(val)}</span>` : ""}
                    </div>
                    <span class="focus-mini-day-lbl">${escapeHtml(lbl)}</span>
                </div>`;
            }).join("");

            barsWrap.querySelectorAll(".focus-mini-day.has-data").forEach(dayEl => {
                const date = dayEl.dataset.date;
                const d = weekDays.find(x => toDateStr(x) === date);
                const daySecs = byDay[date] || 0;
                const barEl = dayEl.querySelector(".focus-mini-bar");
                barEl.style.cursor = "pointer";

                barEl.addEventListener("mousemove", (e) => {
                    const mods = {};
                    SESSIONS.forEach(s => {
                        if (s.username !== MY_USERNAME || s.date !== date) return;
                        mods[s.module] = (mods[s.module] || 0) + s.seconds;
                    });
                    const modRows = Object.entries(mods).sort((a, b) => b[1] - a[1]).map(([mod, secs]) =>
                        `<div class="tt-mod"><span style="display:inline-block;width:9px;height:9px;border-radius:3px;background:${moduleColor[mod] || "#64748b"};margin-right:6px;"></span>${escapeHtml(mod)} · <span class="tt-time">${fmtTime(secs)}</span></div>`
                    ).join("");
                    const dayName = d.toLocaleDateString(undefined, { weekday: "long", month: "short", day: "numeric" });
                    showTooltip(
                        `<div class="tt-user">${escapeHtml(dayName)} — ${fmtTime(daySecs)}</div>${modRows}`,
                        e.clientX, e.clientY
                    );
                });
                barEl.addEventListener("mouseleave", hideTooltip);
            });
        });

        const personalTotal = SESSIONS.filter(s => s.username === MY_USERNAME)
            .reduce((a, s) => a + s.seconds, 0);
        const totalValEl = document.getElementById("focus-personal-total");
        if (totalValEl) {
            totalValEl.querySelector(".focus-personal-total-value").textContent =
                personalTotal > 0 ? fmtTime(personalTotal) : "—";
        }
    }

    focusToggleBtn.addEventListener("click", () => {
        focusMode = !focusMode;
        localStorage.setItem(FOCUS_KEY, focusMode ? "1" : "0");
        applyFocusMode();
    });

    focusStartBtn.addEventListener("click", () => {
        if (myState.active) return;
        openModuleModal();
    });

    focusBreakBtn.addEventListener("click", () => {
        if (!myState.active) return;
        timerAction(myState.running ? "pause" : "resume")
            .catch(err => setFeedback(err.message, true));
    });

    focusStopBtn.addEventListener("click", () => {
        if (!myState.active) return;
        openStopModal();
    });

    // ── Go ────────────────────────────────────────────────────────────────
    // Paint synchronously from the server-embedded state — no fetch, no flash.
    // The 15s poll / visibilitychange / pageshow handlers keep it fresh after.
    renderAll();
    applyMyState(INITIAL_STATUS.me);
    renderStudying(INITIAL_STATUS.studying);
    setRecaps(INITIAL_STATUS);
    applyFocusMode();
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
