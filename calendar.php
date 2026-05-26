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
.calendar-page {
    max-width: 1500px;
    margin: 0 auto;
}

.user-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
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
    width: 340px;
    flex-shrink: 0;
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 10px;
    padding: 18px;
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
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
}

.weekday-header {
    text-align: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 6px 0;
}

.cal-day {
    background: #1f2937;
    border: 1px solid #374151;
    border-radius: 8px;
    padding: 10px;
    min-height: 160px;
    display: flex;
    flex-direction: column;
}

.cal-day.weekend {
    background: #161e2c;
}

.cal-day-num {
    font-size: 1.4rem;
    font-weight: 700;
    color: #f3f4f6;
    margin-bottom: 6px;
}

.cal-day-num .month {
    font-size: 0.7rem;
    color: #9ca3af;
    font-weight: 400;
    margin-left: 4px;
}

.exam-block {
    padding: 6px 8px;
    border-radius: 5px;
    margin-top: 6px;
    font-size: 0.78rem;
    line-height: 1.3;
}

.exam-block .eb-time {
    font-weight: 700;
    display: block;
    margin-bottom: 2px;
}

.exam-block .eb-title {
    display: block;
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
    margin-bottom: 16px;
    font-size: 0.9rem;
    color: #9ca3af;
}

.viewing-banner strong {
    color: #f3f4f6;
}
</style>

<div class="calendar-page">

    <h1>Exam Calendar — July 2026</h1>

    <div class="user-tabs">
        <?php foreach ($users as $u): ?>
            <a
                class="user-tab <?= $u === $selectedUser ? 'active' : '' ?>"
                href="<?= BASE_PATH ?>/calendar.php?user=<?= urlencode($u) ?>"
            ><?= htmlspecialchars($u) ?></a>
        <?php endforeach; ?>
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
