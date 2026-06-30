<?php

require_once __DIR__ . "/includes/session.php";

$users = ["Roman", "Basti", "Ben", "Lorenz"];

// Canonicalize a name against the allowed user list (case-insensitive)
function canonicalUser(?string $name, array $users): ?string {
    if ($name === null) return null;
    foreach ($users as $u) {
        if (strcasecmp($u, $name) === 0) return $u;
    }
    return null;
}

$myUser       = canonicalUser($_SESSION["username"] ?? "", $users);
$selectedUser = canonicalUser($_GET["user"] ?? "", $users) ?? $myUser ?? "Roman";

// ── Load exam data ────────────────────────────────────────────────────
$exams = $pdo->query("
    SELECT id, title, professor, exam_date, exam_time
    FROM exams
    ORDER BY exam_date, exam_time
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT exam_id FROM user_exams WHERE username = ?");
$stmt->execute([$selectedUser]);
$selectedExamIds = array_map("intval", array_column($stmt->fetchAll(PDO::FETCH_ASSOC), "exam_id"));
$selectedSet = array_flip($selectedExamIds);

// ── Countdown to first exam ───────────────────────────────────────────
$firstExamDate = $exams[0]["exam_date"] ?? "2026-07-13";
$today    = new DateTime("today");
$firstDay = new DateTime($firstExamDate);
$daysUntil = (int) ceil(($firstDay->getTimestamp() - $today->getTimestamp()) / 86400);

// ── Progress: how many of the selected exams are already written ──────
$now            = new DateTime("now");
$totalSelected  = count($selectedExamIds);
$passedSelected = 0;
foreach ($exams as $e) {
    if (!isset($selectedSet[(int) $e["id"]])) continue;
    $examDateTime = new DateTime($e["exam_date"] . " " . $e["exam_time"]);
    if ($examDateTime < $now) $passedSelected++;
}

// ── Calendar window: Mon 13.07.2026 → Sun 26.07.2026 ──────────────────
$startDate = new DateTime("2026-07-13");
$days = [];
for ($i = 0; $i < 14; $i++) {
    $d = (clone $startDate)->modify("+$i days");
    $days[] = $d;
}

// Group exams by date string (Y-m-d)
$examsByDay = [];
foreach ($exams as $e) {
    $examsByDay[$e["exam_date"]][] = $e;
}

$canEdit = ($myUser !== null && $myUser === $selectedUser);

$pageTitle = "Exam Calendar";
require_once __DIR__ . "/includes/header.php";
?>

<style>
/* Let the calendar page use much more of the viewport than other pages. */
main.container {
    max-width: none;
    padding-left: 24px;
    padding-right: 24px;
}

.calendar-page {
    width: 100%;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 24px;
}

.calendar-header-left {
    /* shrink to its content so it stays on the left */
    flex: 0 1 auto;
}

.calendar-header h1 {
    margin-bottom: 16px;
}

.header-stats {
    flex: 0 0 auto;
    display: flex;
    align-items: stretch;
    gap: 16px;
}

.exam-progress,
.exam-countdown {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 14px 28px;
}

.exam-progress .cd-value,
.exam-countdown .cd-value {
    font-family: var(--font-display);
    font-size: 2.4rem;
    font-weight: 700;
    line-height: 1;
}

.exam-progress .cd-value {
    color: var(--success);
}

.exam-countdown .cd-value {
    color: var(--danger);
    text-shadow: 0 0 18px rgba(248, 113, 113, 0.45);
    animation: cd-pulse 1.6s ease-in-out infinite;
}

@keyframes cd-pulse {
    50% { opacity: 0.45; }
}

@media (prefers-reduced-motion: reduce) {
    .exam-countdown .cd-value { animation: none; }
}

.exam-progress .cd-label,
.exam-countdown .cd-label {
    margin-top: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.09em;
}

.user-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.user-tab {
    padding: 9px 22px;
    background: var(--glass-strong);
    color: var(--text-2);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-full);
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    transition: background var(--t-fast), border-color var(--t-fast), color var(--t-fast), box-shadow var(--t-fast);
}

.user-tab:hover {
    background: rgba(255, 255, 255, 0.11);
    color: var(--text-1);
    text-decoration: none;
}

.user-tab.active {
    background: var(--grad-accent);
    border-color: transparent;
    color: #fff;
    box-shadow: var(--glow-violet);
}

.calendar-layout {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}

.exam-sidebar {
    width: 280px;
    flex-shrink: 0;
    padding: 18px;
}

.exam-sidebar h3 {
    margin: 0 0 12px 0;
    font-size: 1.05rem;
}

.exam-sidebar .hint {
    font-size: 0.8rem;
    color: var(--text-3);
    margin-bottom: 14px;
}

.exam-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.exam-list li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 9px 10px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    transition: border-color var(--t-fast);
}

.exam-list li:hover {
    border-color: rgba(139, 92, 246, 0.3);
}

.exam-list input[type="checkbox"] {
    width: auto;
    margin: 3px 0 0 0;
    padding: 0;
    flex-shrink: 0;
    accent-color: var(--violet);
}

.exam-list label {
    flex: 1;
    cursor: pointer;
}

.exam-list .exam-title {
    font-weight: 600;
    color: var(--text-1);
}

.exam-list .exam-meta {
    color: var(--text-3);
    font-size: 0.78rem;
    margin-top: 2px;
}

.calendar-grid {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
}

.weekday-header {
    text-align: center;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.09em;
    padding: 8px 0;
}

.cal-day {
    padding: 14px;
    min-height: 220px;
    display: flex;
    flex-direction: column;
    border-radius: var(--radius-md);
}

.cal-day.weekend {
    background: rgba(10, 10, 22, 0.6);
}

.cal-day-num {
    font-family: var(--font-display);
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--text-1);
    margin-bottom: 10px;
}

.cal-day-num .month {
    font-size: 0.8rem;
    color: var(--text-3);
    font-weight: 400;
    margin-left: 6px;
}

.exam-block {
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    margin-top: 8px;
    font-size: 0.88rem;
    line-height: 1.35;
}

.exam-block .eb-time {
    font-weight: 700;
    display: block;
    margin-bottom: 3px;
    font-size: 0.92rem;
}

.exam-block .eb-title {
    display: block;
    overflow-wrap: anywhere; /* break long German exam titles instead of overflowing */
}

.exam-block.highlighted {
    background: var(--grad-accent-soft);
    color: var(--text-1);
    border: 1px solid rgba(139, 92, 246, 0.5);
    box-shadow: 0 0 14px rgba(139, 92, 246, 0.25);
}

.exam-block.dimmed {
    background: rgba(255, 255, 255, 0.03);
    color: var(--text-3);
    border: 1px solid var(--glass-border);
    opacity: 0.45;
}

.viewing-banner {
    padding: 10px 16px;
    margin-top: 24px;
    margin-bottom: 16px;
    font-size: 0.9rem;
    color: var(--text-2);
    border-radius: var(--radius-md);
}

.viewing-banner strong {
    color: var(--text-1);
}

/* ── Responsive ──────────────────────────────────────────────────────── */

@media (max-width: 1100px) {
    .calendar-layout {
        flex-direction: column;
    }

    .exam-sidebar {
        width: 100%;
    }

    .exam-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    }

    .cal-day {
        min-height: 140px;
        padding: 10px;
    }

    .cal-day-num {
        font-size: 1.2rem;
    }
}

@media (max-width: 768px) {
    .weekday-header {
        display: none;
    }

    /* Agenda view: each day becomes a full-width card */
    .calendar-grid {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .cal-day {
        min-height: 0;
        flex-direction: row;
        align-items: flex-start;
        gap: 14px;
    }

    .cal-day-num {
        flex-shrink: 0;
        width: 64px;
        margin-bottom: 0;
    }

    .cal-day-num .month {
        display: block;
        margin-left: 0;
    }

    .cal-day .day-exams {
        flex: 1;
    }

    .exam-block:first-child {
        margin-top: 0;
    }

    .header-stats {
        width: 100%;
    }

    .exam-progress,
    .exam-countdown {
        flex: 1;
    }
}
</style>

<div class="calendar-page">

    <div class="calendar-header">
        <div class="calendar-header-left">
            <h1>Exam Calendar — July 2026</h1>

            <div class="user-tabs">
                <?php foreach ($users as $u): ?>
                    <a
                        class="user-tab <?= $u === $selectedUser ? 'active' : '' ?>"
                        href="<?= BASE_PATH ?>/calendar.php?user=<?= urlencode($u) ?>"
                    ><?= htmlspecialchars($u) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="header-stats">
            <div class="exam-progress glass-card">
                <span class="cd-value"><?= $passedSelected ?>/<?= $totalSelected ?></span>
                <span class="cd-label">exams written</span>
            </div>

            <div class="exam-countdown glass-card">
                <?php if ($daysUntil > 0): ?>
                    <span class="cd-value">D-<?= $daysUntil ?></span>
                    <span class="cd-label">until first exam</span>
                <?php elseif ($daysUntil === 0): ?>
                    <span class="cd-value">D-Day</span>
                    <span class="cd-label">first exam today</span>
                <?php else: ?>
                    <span class="cd-value">🎉</span>
                    <span class="cd-label">exams done</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="viewing-banner glass-card">
        Viewing exams for <strong><?= htmlspecialchars($selectedUser) ?></strong>.
        <?php if ($canEdit): ?>
            Toggle the checkboxes to add or remove your highlights.
        <?php else: ?>
            <em>Read only — switch to your own tab to edit.</em>
        <?php endif; ?>
    </div>

    <div class="calendar-layout">

        <aside class="exam-sidebar glass-card">
            <h3><?= htmlspecialchars($selectedUser) ?>'s exams</h3>
            <p class="hint">
                <?php if ($canEdit): ?>
                    Check the exams you're writing.
                <?php else: ?>
                    Selections set by <?= htmlspecialchars($selectedUser) ?>.
                <?php endif; ?>
            </p>
            <ul class="exam-list">
                <?php foreach ($exams as $e):
                    $id = (int) $e["id"];
                    $isSel = isset($selectedSet[$id]);
                    $dt = new DateTime($e["exam_date"] . " " . $e["exam_time"]);
                ?>
                    <li>
                        <input
                            type="checkbox"
                            id="exam-<?= $id ?>"
                            class="exam-checkbox"
                            data-exam-id="<?= $id ?>"
                            <?= $isSel ? "checked" : "" ?>
                            <?= $canEdit ? "" : "disabled" ?>
                        >
                        <label for="exam-<?= $id ?>">
                            <span class="exam-title"><?= htmlspecialchars($e["title"]) ?></span>
                            <span class="exam-meta">
                                <?= htmlspecialchars($e["professor"] ?? "") ?><br>
                                <?= $dt->format("D d.m.Y · H:i") ?>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <div class="calendar-grid">
            <?php foreach (["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as $w): ?>
                <div class="weekday-header"><?= $w ?></div>
            <?php endforeach; ?>

            <?php foreach ($days as $d):
                $dayKey  = $d->format("Y-m-d");
                $dow     = (int) $d->format("N"); // 1 = Mon
                $weekend = $dow >= 6;
                $todays  = $examsByDay[$dayKey] ?? [];
            ?>
                <div class="cal-day glass-card <?= $weekend ? 'weekend' : '' ?>">
                    <div class="cal-day-num">
                        <?= $d->format("j") ?><span class="month"><?= $d->format("M") ?></span>
                    </div>
                    <div class="day-exams">
                    <?php foreach ($todays as $e):
                        $eid   = (int) $e["id"];
                        $isSel = isset($selectedSet[$eid]);
                        $cls   = $isSel ? "highlighted" : "dimmed";
                        $time  = substr($e["exam_time"], 0, 5);
                    ?>
                        <div class="exam-block <?= $cls ?>" title="<?= htmlspecialchars($e["title"] . " — " . ($e["professor"] ?? "")) ?>">
                            <span class="eb-time"><?= htmlspecialchars($time) ?></span>
                            <span class="eb-title"><?= htmlspecialchars($e["title"]) ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</div>

<script>
const BASE_PATH = "<?= BASE_PATH ?>";
const SELECTED_USER = <?= json_encode($selectedUser) ?>;

document.querySelectorAll(".exam-checkbox").forEach(cb => {
    cb.addEventListener("change", () => {
        const examId  = cb.dataset.examId;
        const checked = cb.checked ? "1" : "0";

        const body = new FormData();
        body.append("username", SELECTED_USER);
        body.append("exam_id", examId);
        body.append("checked", checked);

        cb.disabled = true;

        fetch(BASE_PATH + "/api/toggle-user-exam.php", { method: "POST", body })
            .then(r => {
                if (!r.ok) throw new Error("Save failed.");
                // Reload so calendar highlights update
                window.location.reload();
            })
            .catch(err => {
                cb.checked = !cb.checked;
                cb.disabled = false;
                alert(err.message);
            });
    });
});
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
