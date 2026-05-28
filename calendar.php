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
$today    = new DateTime("today", new DateTimeZone("UTC"));
$firstDay = new DateTime($firstExamDate, new DateTimeZone("UTC"));
$daysUntil = (int) ceil(($firstDay->getTimestamp() - $today->getTimestamp()) / 86400);

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

require_once __DIR__ . "/includes/header.php";
?>

<style>
/* Let the calendar page use much more of the viewport than other pages */
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
    gap: 24px;
}

.calendar-header-left {
    /* shrink to its content so it stays on the left */
    flex: 0 1 auto;
}

.calendar-header h1 {
    margin-bottom: 16px;
}

.exam-countdown {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 12px;
    padding: 12px 28px;
}

.exam-countdown .cd-value {
    font-size: 2.6rem;
    font-weight: 800;
    line-height: 1;
    color: #f87171;
    animation: cd-blink 1s steps(1, end) infinite;
}

@keyframes cd-blink {
    50% { opacity: 0.15; }
}

@media (prefers-reduced-motion: reduce) {
    .exam-countdown .cd-value { animation: none; }
}

.exam-countdown .cd-label {
    margin-top: 4px;
    font-size: 0.75rem;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.user-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.user-tab {
    padding: 10px 22px;
    background: #374151;
    color: #f3f4f6;
    border: 1px solid #4b5563;
    border-radius: 8px;
    font-weight: 600;
    font-size: 1rem;
    text-decoration: none;
    transition: background 0.15s;
}

.user-tab:hover {
    background: #4b5563;
    text-decoration: none;
}

.user-tab.active {
    background: #2563eb;
    border-color: #2563eb;
    color: white;
}

.calendar-layout {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}

.exam-sidebar {
    width: 280px;
    flex-shrink: 0;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 10px;
    padding: 16px;
}

.exam-sidebar h3 {
    margin: 0 0 12px 0;
    font-size: 1.05rem;
    color: #f3f4f6;
}

.exam-sidebar .hint {
    font-size: 0.8rem;
    color: #9ca3af;
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
    padding: 8px;
    background: #111827;
    border: 1px solid #374151;
    border-radius: 6px;
    font-size: 0.85rem;
}

.exam-list input[type="checkbox"] {
    width: auto;
    margin: 3px 0 0 0;
    padding: 0;
    flex-shrink: 0;
}

.exam-list label {
    flex: 1;
    cursor: pointer;
}

.exam-list .exam-title {
    font-weight: 600;
    color: #f3f4f6;
}

.exam-list .exam-meta {
    color: #9ca3af;
    font-size: 0.78rem;
    margin-top: 2px;
}

.calendar-grid {
    flex: 1;
    min-width: 0; /* allow the grid to shrink inside the flex row instead of overflowing */
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 8px;
}

.weekday-header {
    text-align: center;
    font-size: 0.9rem;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 8px 0;
}

.cal-day {
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 10px;
    padding: 14px;
    min-height: 220px;
    display: flex;
    flex-direction: column;
}

.cal-day.weekend {
    background: #161e2c;
}

.cal-day-num {
    font-size: 1.75rem;
    font-weight: 700;
    color: #f3f4f6;
    margin-bottom: 10px;
}

.cal-day-num .month {
    font-size: 0.8rem;
    color: #9ca3af;
    font-weight: 400;
    margin-left: 6px;
}

.exam-block {
    padding: 8px 10px;
    border-radius: 6px;
    margin-top: 8px;
    font-size: 0.9rem;
    line-height: 1.35;
}

.exam-block .eb-time {
    font-weight: 700;
    display: block;
    margin-bottom: 3px;
    font-size: 0.95rem;
}

.exam-block .eb-title {
    display: block;
    overflow-wrap: anywhere; /* break long German exam titles instead of overflowing */
}

.exam-block.highlighted {
    background: #2563eb;
    color: #ffffff;
    border: 1px solid #3b82f6;
    box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.3);
}

.exam-block.dimmed {
    background: #2a3441;
    color: #6b7280;
    border: 1px solid #374151;
    opacity: 0.45;
}

.viewing-banner {
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 8px;
    padding: 10px 14px;
    margin-top: 24px;
    margin-bottom: 16px;
    font-size: 0.9rem;
    color: #9ca3af;
}

.viewing-banner strong {
    color: #f3f4f6;
}

/* On narrow screens, stack the sidebar above the calendar so neither overflows */
@media (max-width: 900px) {
    .calendar-layout {
        flex-direction: column;
    }

    .exam-sidebar {
        width: 100%;
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

        <div class="exam-countdown">
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

    <div class="viewing-banner">
        Viewing exams for <strong><?= htmlspecialchars($selectedUser) ?></strong>.
        <?php if ($canEdit): ?>
            Toggle the checkboxes to add or remove your highlights.
        <?php else: ?>
            <em>Read only — switch to your own tab to edit.</em>
        <?php endif; ?>
    </div>

    <div class="calendar-layout">

        <aside class="exam-sidebar">
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
                <div class="cal-day <?= $weekend ? 'weekend' : '' ?>">
                    <div class="cal-day-num">
                        <?= $d->format("j") ?><span class="month"><?= $d->format("M") ?></span>
                    </div>
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
