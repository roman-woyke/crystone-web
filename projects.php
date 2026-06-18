<?php

require_once __DIR__ . "/includes/session.php";

$userId = $_SESSION["user_id"];

// Color palette projects can be tagged with (value stored in DB)
$palette = [
    "#8b5cf6" => "Violet",
    "#3b82f6" => "Blue",
    "#34d399" => "Green",
    "#fbbf24" => "Amber",
    "#f472b6" => "Pink",
    "#22d3ee" => "Cyan",
    "#f87171" => "Red",
    "#a3e635" => "Lime",
];

// ── Handle project creation ───────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name        = trim($_POST["name"]        ?? "");
    $description = trim($_POST["description"] ?? "");
    $color       = trim($_POST["color"]       ?? "");

    if ($name === "") {
        die("Project name is required.");
    }

    $description = $description === "" ? null : $description;
    $color       = isset($palette[$color]) ? $color : "#8b5cf6";

    $stmt = $pdo->prepare("
        INSERT INTO projects (user_id, name, description, color)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $name, mb_substr($description ?? "", 0, 1000) ?: null, $color]);

    header("Location: " . BASE_PATH . "/projects.php");
    exit;
}

// ── Fetch projects with aggregated time ───────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.description,
        p.color,
        p.created_at,
        COALESCE(SUM(t.seconds), 0)                                                          AS total_seconds,
        COALESCE(SUM(CASE WHEN DATE(t.logged_at) = CURDATE() THEN t.seconds ELSE 0 END), 0)  AS today_seconds,
        COALESCE(SUM(CASE WHEN t.logged_at >= NOW() - INTERVAL 7 DAY THEN t.seconds ELSE 0 END), 0) AS week_seconds,
        COUNT(t.id)                                                                          AS entry_count
    FROM projects p
    LEFT JOIN project_time_entries t
        ON t.project_id = p.id
    WHERE p.user_id = ?
    GROUP BY p.id, p.name, p.description, p.color, p.created_at
    ORDER BY total_seconds DESC, p.created_at DESC
");
$stmt->execute([$userId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch recent time entries (grouped per project in PHP) ────────────
$entryStmt = $pdo->prepare("
    SELECT id, project_id, seconds, note, logged_at
    FROM project_time_entries
    WHERE user_id = ?
    ORDER BY logged_at DESC
");
$entryStmt->execute([$userId]);

$entriesByProject = [];
foreach ($entryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $entriesByProject[(int) $row["project_id"]][] = $row;
}

// ── Page-level totals ─────────────────────────────────────────────────
$grandTotal   = 0;
$grandWeek    = 0;
$grandToday   = 0;
$grandEntries = 0;
foreach ($projects as $p) {
    $grandTotal   += (int) $p["total_seconds"];
    $grandWeek    += (int) $p["week_seconds"];
    $grandToday   += (int) $p["today_seconds"];
    $grandEntries += (int) $p["entry_count"];
}

// ── Cross-user standings: everyone's projects on this instance ────────
// Projects stay owned per-user, but totals are public so people can see
// who's putting in the hours and what others are working on (read-only).
$allStmt = $pdo->query("
    SELECT
        p.id,
        p.user_id,
        u.username,
        p.name,
        p.color,
        COALESCE(SUM(t.seconds), 0) AS total_seconds
    FROM projects p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN project_time_entries t ON t.project_id = p.id
    GROUP BY p.id, p.user_id, u.username, p.name, p.color
    ORDER BY total_seconds DESC, p.created_at DESC
");

$userStats = [];
foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $uid = (int) $r["user_id"];
    if (!isset($userStats[$uid])) {
        $userStats[$uid] = [
            "username" => $r["username"],
            "total"    => 0,
            "projects" => [],
        ];
    }
    $userStats[$uid]["total"]     += (int) $r["total_seconds"];
    $userStats[$uid]["projects"][] = [
        "name"  => $r["name"],
        "color" => $r["color"],
        "total" => (int) $r["total_seconds"],
    ];
}

// Rank users by total time tracked (descending), keeping user_id keys.
uasort($userStats, fn($a, $b) => $b["total"] <=> $a["total"]);

function formatDuration(int $seconds): string {
    if ($seconds <= 0) return "0m";
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0 && $m > 0) return "{$h}h {$m}m";
    if ($h > 0)           return "{$h}h";
    return "{$m}m";
}

function relativeTime(string $ts): string {
    $then = strtotime($ts);
    $diff = time() - $then;
    if ($diff < 60)     return "just now";
    if ($diff < 3600)   return floor($diff / 60) . "m ago";
    if ($diff < 86400)  return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    return date("d.m.Y", $then);
}

$pageTitle = "Projects";
require_once __DIR__ . "/includes/header.php";
?>

<style>
main.container {
    max-width: 1400px;
}

.projects-intro {
    margin-bottom: 24px;
}

.projects-intro .page-heading {
    margin-bottom: 6px;
}

.projects-sub {
    margin: 0;
    color: var(--text-2);
    max-width: 680px;
}

/* New-project panel */
.new-project {
    padding: 22px;
    margin-bottom: 28px;
}

.new-project h2 {
    margin: 0 0 4px;
    font-size: 1.15rem;
}

.new-project .np-hint {
    margin: 0 0 10px;
    font-size: 0.85rem;
    color: var(--text-3);
}

.np-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
    gap: 18px;
    align-items: start;
}

@media (max-width: 760px) {
    .np-grid { grid-template-columns: 1fr; }
}

.color-swatches {
    display: flex;
    gap: 10px;
    margin: 8px 0 18px;
    flex-wrap: wrap;
}
.color-swatches input { display: none; }
.color-swatches label {
    width: 28px;
    height: 28px;
    margin: 0;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid transparent;
    box-shadow: 0 0 0 1px var(--glass-border);
    transition: transform var(--t-fast), box-shadow var(--t-fast);
}
.color-swatches input:checked + label {
    transform: scale(1.18);
    box-shadow: 0 0 0 2px var(--text-1);
}

.new-project .np-submit {
    width: auto;
    margin: 0;
}

/* Projects grid */
.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 18px;
}

.project-card {
    --accent: var(--violet);
    position: relative;
    padding: 20px 20px 16px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Accent glow strip along the top edge */
.project-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--accent);
    opacity: 0.9;
}

.pc-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 6px;
}

.pc-title {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}

.pc-dot {
    flex-shrink: 0;
    width: 12px;
    height: 12px;
    border-radius: 4px;
    background: var(--accent);
    box-shadow: 0 0 10px var(--accent);
}

.pc-name {
    margin: 0;
    font-size: 1.2rem;
    overflow-wrap: anywhere;
}

.pc-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}
.pc-actions .icon-btn { font-size: 0.85rem; }

.pc-desc {
    font-size: 0.9rem;
    line-height: 1.45;
    margin: 0 0 16px;
    overflow-wrap: anywhere;
}
.pc-desc.empty { font-style: italic; }

.pc-total {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 12px;
}
.pc-time {
    font-family: var(--font-display);
    font-size: 2.3rem;
    font-weight: 700;
    line-height: 1;
    color: var(--accent);
    font-variant-numeric: tabular-nums;
}
.pc-total-label {
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.pc-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    font-size: 0.84rem;
    color: var(--text-2);
    margin-bottom: 16px;
}
.pc-breakdown strong { color: var(--text-1); font-weight: 600; }

/* Live timer row */
.pc-timer {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}
.pc-timer-display {
    font-family: var(--font-display);
    font-size: 1.35rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--text-1);
    min-width: 92px;
}
.pc-timer .start-btn,
.pc-timer .log-btn {
    width: auto;
    margin: 0;
    padding: 8px 16px;
}
.pc-timer .start-btn.running {
    background: rgba(248, 113, 113, 0.14);
    border: 1px solid rgba(248, 113, 113, 0.4);
    color: var(--danger);
    box-shadow: none;
}

/* Entries list */
.pc-entries {
    border-top: 1px solid var(--glass-border);
    padding-top: 12px;
    margin-top: auto;
}
.pe-title {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 8px;
}
.pc-entry {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    padding: 5px 0;
}
.pe-secs {
    font-weight: 700;
    color: var(--text-1);
    min-width: 56px;
    font-variant-numeric: tabular-nums;
}
.pe-note { flex: 1; color: var(--text-2); overflow-wrap: anywhere; }
.pe-when { color: var(--text-3); font-size: 0.78rem; white-space: nowrap; }
.pe-del {
    width: auto;
    margin: 0;
    padding: 2px 7px;
    font-size: 0.8rem;
    line-height: 1;
    background: transparent;
    border: 1px solid transparent;
    color: var(--text-3);
}
.pe-del:hover {
    background: rgba(248, 113, 113, 0.14);
    border-color: rgba(248, 113, 113, 0.35);
    color: var(--danger);
}
.pe-more { font-size: 0.78rem; color: var(--text-3); margin-top: 6px; }

.empty-state {
    padding: 56px 24px;
    text-align: center;
    color: var(--text-2);
}
.empty-state .es-emoji { font-size: 2.4rem; display: block; margin-bottom: 10px; }

/* ── Standings / cross-user comparison ──────────────────────────────── */
.standings { margin-top: 44px; }

.standings-head {
    display: flex;
    align-items: baseline;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 2px;
}
.standings-head h2 { margin: 0; font-size: 1.25rem; }
.standings-sub { margin: 0; color: var(--text-3); font-size: 0.85rem; }

.user-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    margin-top: 22px;
}

.user-panel { padding: 18px 18px 14px; }
.user-panel.me {
    border-color: rgba(139, 92, 246, 0.45);
    box-shadow: inset 0 1px 0 var(--glass-highlight), var(--shadow-card), var(--glow-violet);
}

.up-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}
.up-rank {
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 50%;
    background: var(--glass-strong);
    color: var(--text-2);
}
.up-rank.r1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
.up-rank.r2 { background: linear-gradient(135deg, #d1d5db, #9ca3af); color: #fff; }
.up-rank.r3 { background: linear-gradient(135deg, #d97706, #92400e); color: #fff; }

.up-name {
    flex: 1;
    min-width: 0;
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 1.05rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.up-you {
    flex-shrink: 0;
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
.up-total {
    flex-shrink: 0;
    font-family: var(--font-display);
    font-weight: 700;
    color: var(--text-1);
    font-variant-numeric: tabular-nums;
}

.up-projects { display: flex; flex-direction: column; gap: 11px; }
.up-empty { color: var(--text-3); font-size: 0.85rem; }

.up-proj-top {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    margin-bottom: 5px;
}
.up-dot { flex-shrink: 0; width: 9px; height: 9px; border-radius: 3px; }
.up-pname {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text-2);
}
.up-ptime { flex-shrink: 0; font-weight: 600; color: var(--text-1); font-variant-numeric: tabular-nums; }

.up-bar {
    height: 6px;
    border-radius: var(--radius-full);
    background: rgba(255, 255, 255, 0.05);
    overflow: hidden;
}
.up-bar > span {
    display: block;
    height: 100%;
    border-radius: var(--radius-full);
    min-width: 2px;
}

/* Modal tweaks */
.modal-dialog h3 { margin-top: 0; }
.hms-row { display: flex; gap: 14px; }
.hms-row > div { flex: 1; }
</style>

<div class="projects-intro">
    <h1 class="page-heading">Project <span class="gradient-text">Time</span></h1>
    <p class="projects-sub">
        Track the hours you pour into each project. Run a live timer or log time you spent
        offline, jot down what it's about, and watch your contribution add up.
    </p>
</div>

<!-- ── Page stats ─────────────────────────────────────────────────────── -->
<div class="stat-grid">
    <div class="stat-card glass-card">
        <span class="stat-value gradient-text"><?= htmlspecialchars(formatDuration($grandTotal)) ?></span>
        <span class="stat-label">Total tracked</span>
    </div>
    <div class="stat-card glass-card">
        <span class="stat-value"><?= htmlspecialchars(formatDuration($grandToday)) ?></span>
        <span class="stat-label">Today</span>
    </div>
    <div class="stat-card glass-card">
        <span class="stat-value"><?= htmlspecialchars(formatDuration($grandWeek)) ?></span>
        <span class="stat-label">Last 7 days</span>
    </div>
    <div class="stat-card glass-card">
        <span class="stat-value"><?= count($projects) ?></span>
        <span class="stat-label">Projects</span>
    </div>
    <div class="stat-card glass-card">
        <span class="stat-value"><?= $grandEntries ?></span>
        <span class="stat-label">Sessions</span>
    </div>
</div>

<!-- ── New project ────────────────────────────────────────────────────── -->
<section class="new-project glass-card">
    <h2>New project</h2>
    <p class="np-hint">Give it a name, a colour, and a short description.</p>

    <form action="<?= BASE_PATH ?>/projects.php" method="POST">
        <div class="np-grid">
            <div>
                <label for="np-name">Name</label>
                <input type="text" id="np-name" name="name" maxlength="255" placeholder="e.g. Portfolio website" required>

                <label>Colour</label>
                <div class="color-swatches">
                    <?php $first = true; foreach ($palette as $hex => $cname): ?>
                        <input type="radio" name="color" id="sw-<?= ltrim($hex, '#') ?>" value="<?= $hex ?>" <?= $first ? "checked" : "" ?>>
                        <label for="sw-<?= ltrim($hex, '#') ?>" style="background:<?= $hex ?>;" title="<?= $cname ?>"></label>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>
            <div>
                <label for="np-desc">Description</label>
                <textarea id="np-desc" name="description" maxlength="1000" style="min-height:104px;" placeholder="What is this project about?"></textarea>
            </div>
        </div>
        <button type="submit" class="btn-primary np-submit">+ Add project</button>
    </form>
</section>

<?php if (count($projects) === 0): ?>
    <div class="glass-card empty-state">
        <span class="es-emoji">🗂️</span>
        No projects yet — create one above and start logging your hours.
    </div>
<?php else: ?>
    <div class="projects-grid">
        <?php foreach ($projects as $p):
            $pid     = (int) $p["id"];
            $accent  = htmlspecialchars($p["color"] ?: "#8b5cf6");
            $entries = $entriesByProject[$pid] ?? [];
        ?>
            <article class="project-card glass-card glass-card--hover" id="project-<?= $pid ?>" style="--accent: <?= $accent ?>;">
                <div class="pc-head">
                    <div class="pc-title">
                        <span class="pc-dot"></span>
                        <h3 class="pc-name"><?= htmlspecialchars($p["name"]) ?></h3>
                    </div>
                    <div class="pc-actions">
                        <button
                            class="icon-btn edit-project-btn"
                            data-id="<?= $pid ?>"
                            data-name="<?= htmlspecialchars($p["name"]) ?>"
                            data-desc="<?= htmlspecialchars($p["description"] ?? "") ?>"
                            title="Edit project">✏️</button>
                        <button class="icon-btn delete-project-btn" data-id="<?= $pid ?>" title="Delete project">🗑️</button>
                    </div>
                </div>

                <p class="pc-desc <?= empty($p["description"]) ? "empty muted" : "muted" ?>">
                    <?= empty($p["description"]) ? "No description yet." : htmlspecialchars($p["description"]) ?>
                </p>

                <div class="pc-total">
                    <span class="pc-time" id="total-<?= $pid ?>"><?= htmlspecialchars(formatDuration((int) $p["total_seconds"])) ?></span>
                    <span class="pc-total-label muted">contributed</span>
                </div>

                <div class="pc-breakdown">
                    <span>Today <strong><?= htmlspecialchars(formatDuration((int) $p["today_seconds"])) ?></strong></span>
                    <span>This week <strong><?= htmlspecialchars(formatDuration((int) $p["week_seconds"])) ?></strong></span>
                    <span><strong><?= (int) $p["entry_count"] ?></strong> sessions</span>
                </div>

                <div class="pc-timer" data-id="<?= $pid ?>">
                    <span class="pc-timer-display" id="timer-<?= $pid ?>">00:00:00</span>
                    <button class="btn-primary start-btn" data-id="<?= $pid ?>">▶ Start</button>
                    <button class="btn log-btn" data-id="<?= $pid ?>">+ Log</button>
                </div>

                <div class="pc-entries">
                    <?php if (count($entries) > 0): ?>
                        <div class="pe-title">Recent sessions</div>
                        <?php foreach (array_slice($entries, 0, 4) as $e): ?>
                            <div class="pc-entry" id="entry-<?= (int) $e["id"] ?>">
                                <span class="pe-secs"><?= htmlspecialchars(formatDuration((int) $e["seconds"])) ?></span>
                                <span class="pe-note"><?= $e["note"] !== null && $e["note"] !== "" ? htmlspecialchars($e["note"]) : "—" ?></span>
                                <span class="pe-when"><?= htmlspecialchars(relativeTime($e["logged_at"])) ?></span>
                                <button class="pe-del delete-entry-btn" data-id="<?= (int) $e["id"] ?>" title="Remove session">✕</button>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($entries) > 4): ?>
                            <div class="pe-more">+ <?= count($entries) - 4 ?> more session<?= count($entries) - 4 === 1 ? "" : "s" ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="pe-title">No sessions logged yet</div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($userStats) > 0): ?>
    <section class="standings">
        <div class="standings-head">
            <h2>🏆 Standings</h2>
        </div>
        <p class="standings-sub">Total time tracked by everyone on this instance — and what they're working on.</p>

        <?php $podium = array_slice($userStats, 0, 3, true); ?>
        <div class="podium">
            <?php $rank = 0; foreach ($podium as $u): $rank++; ?>
                <div class="podium-card glass-card rank-<?= $rank ?>">
                    <span class="podium-medal"><?= $rank ?></span>
                    <div class="podium-name"><?= htmlspecialchars($u["username"]) ?></div>
                    <div class="podium-score gradient-text"><?= htmlspecialchars(formatDuration($u["total"])) ?></div>
                    <div class="podium-sub">total tracked</div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="user-grid">
            <?php $rank = 0; foreach ($userStats as $uid => $u): $rank++; $isMe = ($uid === (int) $userId); ?>
                <div class="user-panel glass-card <?= $isMe ? "me" : "" ?>">
                    <div class="up-head">
                        <span class="up-rank <?= $rank <= 3 ? "r{$rank}" : "" ?>"><?= $rank ?></span>
                        <span class="up-name"><?= htmlspecialchars($u["username"]) ?></span>
                        <?php if ($isMe): ?><span class="up-you">you</span><?php endif; ?>
                        <span class="up-total"><?= htmlspecialchars(formatDuration($u["total"])) ?></span>
                    </div>
                    <div class="up-projects">
                        <?php
                        $maxP = 0;
                        foreach ($u["projects"] as $pr) { $maxP = max($maxP, $pr["total"]); }
                        ?>
                        <?php if (count($u["projects"]) === 0): ?>
                            <span class="up-empty">No projects yet.</span>
                        <?php else: foreach ($u["projects"] as $pr):
                            $c = htmlspecialchars($pr["color"] ?: "#8b5cf6");
                            $w = $maxP > 0 ? round($pr["total"] / $maxP * 100) : 0;
                        ?>
                            <div class="up-proj">
                                <div class="up-proj-top">
                                    <span class="up-dot" style="background:<?= $c ?>"></span>
                                    <span class="up-pname"><?= htmlspecialchars($pr["name"]) ?></span>
                                    <span class="up-ptime"><?= htmlspecialchars(formatDuration($pr["total"])) ?></span>
                                </div>
                                <div class="up-bar"><span style="width:<?= $w ?>%;background:<?= $c ?>"></span></div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- ── Log time modal ──────────────────────────────────────────────── -->
<div class="modal-overlay" id="log-modal" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3>Log time</h3>
        <p class="muted" id="log-modal-sub" style="margin-top:0;">Add time you spent on this project offline.</p>
        <div class="hms-row">
            <div>
                <label for="log-hours">Hours</label>
                <input type="number" id="log-hours" min="0" max="24" value="0">
            </div>
            <div>
                <label for="log-minutes">Minutes</label>
                <input type="number" id="log-minutes" min="0" max="59" value="30">
            </div>
        </div>
        <label for="log-note">Note (optional)</label>
        <input type="text" id="log-note" maxlength="255" placeholder="What did you work on?">
        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="log-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="log-save">Save</button>
        </div>
    </div>
</div>

<!-- ── Edit project modal ──────────────────────────────────────────── -->
<div class="modal-overlay" id="edit-modal" style="display:none;">
    <div class="modal-dialog glass-card">
        <h3>Edit project</h3>
        <label for="edit-name">Name</label>
        <input type="text" id="edit-name" maxlength="255">
        <label for="edit-desc">Description</label>
        <textarea id="edit-desc" maxlength="1000" style="min-height:96px;"></textarea>
        <div class="modal-actions">
            <button type="button" class="btn-ghost" id="edit-cancel">Cancel</button>
            <button type="button" class="btn-primary" id="edit-save">Save</button>
        </div>
    </div>
</div>

<script>
(function () {
    const BASE_PATH = "<?= BASE_PATH ?>";

    // ── Live timers (persisted in localStorage so they survive reloads) ───
    function timerKey(id) { return "project_timer_" + BASE_PATH + "_" + id; }

    function fmtClock(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        const pad = n => String(n).padStart(2, "0");
        return pad(h) + ":" + pad(m) + ":" + pad(s);
    }

    const tickHandles = {};

    function renderRunning(id) {
        const start = parseInt(localStorage.getItem(timerKey(id)), 10);
        if (!start) return;

        const timer = document.querySelector(`.pc-timer[data-id="${id}"]`);
        const btn   = timer.querySelector(".start-btn");
        const disp  = document.getElementById("timer-" + id);

        btn.textContent = "■ Stop";
        btn.classList.add("running");

        const update = () => {
            const elapsed = Math.max(0, Math.floor((Date.now() - start) / 1000));
            disp.textContent = fmtClock(elapsed);
        };
        update();
        clearInterval(tickHandles[id]);
        tickHandles[id] = setInterval(update, 1000);
    }

    function startTimer(btn) {
        const id = btn.dataset.id;
        localStorage.setItem(timerKey(id), Date.now().toString());
        renderRunning(id);
    }

    function stopTimer(btn) {
        const id    = btn.dataset.id;
        const start = parseInt(localStorage.getItem(timerKey(id)), 10);
        clearInterval(tickHandles[id]);
        localStorage.removeItem(timerKey(id));

        if (!start) { window.location.reload(); return; }

        const seconds = Math.floor((Date.now() - start) / 1000);
        if (seconds < 1) { window.location.reload(); return; }

        btn.disabled = true;
        logTime(id, seconds, "");
    }

    function logTime(projectId, seconds, note) {
        const body = new FormData();
        body.append("project_id", projectId);
        body.append("seconds", seconds);
        body.append("note", note);

        fetch(BASE_PATH + "/api/log-time.php", { method: "POST", body })
            .then(r => { if (!r.ok) throw new Error("Failed to save time."); window.location.reload(); })
            .catch(err => { alert(err.message); window.location.reload(); });
    }

    // Delegated start/stop handling (the class swaps when running)
    document.querySelectorAll(".pc-timer").forEach(timer => {
        timer.addEventListener("click", e => {
            const btn = e.target.closest("button");
            if (!btn || !btn.classList.contains("start-btn")) return;
            if (btn.classList.contains("running")) stopTimer(btn);
            else startTimer(btn);
        });
    });

    // Restore any running timers on load
    document.querySelectorAll(".pc-timer").forEach(t => {
        if (localStorage.getItem(timerKey(t.dataset.id))) renderRunning(t.dataset.id);
    });

    // ── Log-time modal ────────────────────────────────────────────────────
    const logModal = document.getElementById("log-modal");
    let logProjectId = null;

    function openModal(el)  { el.style.display = "flex"; }
    function closeModal(el) { el.style.display = "none"; }

    document.querySelectorAll(".log-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            logProjectId = btn.dataset.id;
            document.getElementById("log-hours").value = 0;
            document.getElementById("log-minutes").value = 30;
            document.getElementById("log-note").value = "";
            openModal(logModal);
            document.getElementById("log-minutes").focus();
        });
    });

    document.getElementById("log-cancel").addEventListener("click", () => closeModal(logModal));
    logModal.addEventListener("click", e => { if (e.target === logModal) closeModal(logModal); });

    document.getElementById("log-save").addEventListener("click", () => {
        const h = parseInt(document.getElementById("log-hours").value, 10) || 0;
        const m = parseInt(document.getElementById("log-minutes").value, 10) || 0;
        const seconds = h * 3600 + m * 60;
        if (seconds <= 0) { alert("Enter a duration greater than zero."); return; }
        logTime(logProjectId, seconds, document.getElementById("log-note").value.trim());
    });

    // ── Edit-project modal ────────────────────────────────────────────────
    const editModal = document.getElementById("edit-modal");
    let editProjectId = null;

    document.querySelectorAll(".edit-project-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            editProjectId = btn.dataset.id;
            document.getElementById("edit-name").value = btn.dataset.name;
            document.getElementById("edit-desc").value = btn.dataset.desc;
            openModal(editModal);
            document.getElementById("edit-name").focus();
        });
    });

    document.getElementById("edit-cancel").addEventListener("click", () => closeModal(editModal));
    editModal.addEventListener("click", e => { if (e.target === editModal) closeModal(editModal); });

    document.getElementById("edit-save").addEventListener("click", () => {
        const name = document.getElementById("edit-name").value.trim();
        if (name === "") { alert("Name cannot be empty."); return; }

        const body = new FormData();
        body.append("project_id", editProjectId);
        body.append("name", name);
        body.append("description", document.getElementById("edit-desc").value.trim());

        fetch(BASE_PATH + "/api/patch-project.php", { method: "POST", body })
            .then(r => { if (!r.ok) throw new Error("Failed to save."); window.location.reload(); })
            .catch(err => alert(err.message));
    });

    // ── Delete project ────────────────────────────────────────────────────
    document.querySelectorAll(".delete-project-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            if (!confirm("Delete this project and all its logged time? This cannot be undone.")) return;
            const id = btn.dataset.id;
            const body = new FormData();
            body.append("project_id", id);
            fetch(BASE_PATH + "/api/delete-project.php", { method: "POST", body })
                .then(r => {
                    if (!r.ok) throw new Error("Delete failed.");
                    localStorage.removeItem(timerKey(id));
                    document.getElementById("project-" + id).remove();
                })
                .catch(err => alert(err.message));
        });
    });

    // ── Delete single time entry ──────────────────────────────────────────
    document.querySelectorAll(".delete-entry-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            if (!confirm("Remove this session?")) return;
            const id = btn.dataset.id;
            const body = new FormData();
            body.append("entry_id", id);
            fetch(BASE_PATH + "/api/delete-time-entry.php", { method: "POST", body })
                .then(r => { if (!r.ok) throw new Error("Delete failed."); window.location.reload(); })
                .catch(err => alert(err.message));
        });
    });
}());
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
