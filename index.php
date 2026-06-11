<?php

require_once __DIR__ . "/includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/includes/scoring.php";

$loggedIn = isset($_SESSION["user_id"]);

// Aggregate stats only — no per-user data is exposed to logged-out visitors.
$stats = [
    "applications" => null,
    "interviews"   => null,
    "offers"       => null,
    "points"       => null,
    "competitors"  => null,
];

try {
    $stats["applications"] = (int) $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
    $stats["competitors"]  = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // Peak status per application (same derivation as get-leaderboard.php) so
    // interviews/offers persist even after a later rejection.
    $peakRow = $pdo->query("
        SELECT
            COALESCE(SUM(peak.peak_status = 'INTERVIEW'), 0) AS interviews,
            COALESCE(SUM(peak.peak_status = 'OFFER'),     0) AS offers
        FROM (
            SELECT h.application_id,
                CASE MAX(
                    CASE h.status
                        WHEN 'OFFER'     THEN 5
                        WHEN 'INTERVIEW' THEN 4
                        WHEN 'PENDING'   THEN 3
                        WHEN 'GHOSTED'   THEN 2
                        WHEN 'REJECTED'  THEN 1
                        ELSE 0
                    END)
                    WHEN 5 THEN 'OFFER'
                    WHEN 4 THEN 'INTERVIEW'
                    WHEN 3 THEN 'PENDING'
                    WHEN 2 THEN 'GHOSTED'
                    WHEN 1 THEN 'REJECTED'
                    ELSE NULL
                END AS peak_status
            FROM application_status_history h
            GROUP BY h.application_id
        ) AS peak
    ")->fetch(PDO::FETCH_ASSOC);

    $stats["interviews"] = (int) ($peakRow["interviews"] ?? 0);
    $stats["offers"]     = (int) ($peakRow["offers"] ?? 0);

    // Total points scored across all users: replay every history row through
    // the shared scorePoints() function.
    $historyStmt = $pdo->query("
        SELECT h.status, a.tag
        FROM application_status_history h
        JOIN applications a ON a.id = h.application_id
    ");

    $totalPoints = 0;
    foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $totalPoints += scorePoints($event["status"], $event["tag"]);
    }
    $stats["points"] = $totalPoints;
} catch (Throwable $e) {
    // Landing page must never white-screen on DB hiccups; stats render as "—".
}

// Render a stat as a count-up target, or a dash when unavailable.
function statValue(?int $n): string {
    if ($n === null) return "—";
    return '<span data-countup="' . $n . '">0</span>';
}

$cssPath = __DIR__ . "/assets/css/style.css";
$jsPath  = __DIR__ . "/assets/js/app.js";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>InternTrack — Track every application. Beat your friends.</title>
    <meta name="description" content="A competitive internship application tracker. Every application scores points — climb the leaderboard.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">

    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">
    <script src="<?= BASE_PATH ?>/assets/js/app.js?v=<?= file_exists($jsPath) ? filemtime($jsPath) : time() ?>" defer></script>

    <style>
    .landing main {
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 24px;
    }

    .landing .bg-orbs .orb {
        opacity: 0.45;
    }

    .hero {
        padding: 12vh 0 9vh;
        text-align: center;
    }

    .hero h1 {
        margin: 0 auto 18px;
        max-width: 800px;

        font-size: clamp(2.3rem, 6vw, 4.2rem);
        font-weight: 700;
        line-height: 1.08;
        letter-spacing: -0.02em;
    }

    .hero .hero-sub {
        margin: 0 auto 34px;
        max-width: 560px;

        font-size: clamp(1rem, 2vw, 1.18rem);
        color: var(--text-2);
    }

    .hero-ctas {
        display: flex;
        gap: 14px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .hero-ctas .btn {
        padding: 13px 30px;
        font-size: 1rem;
        border-radius: var(--radius-md);
    }

    .hero-badge {
        display: inline-block;
        margin-bottom: 22px;
        padding: 6px 16px;

        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        color: var(--text-2);

        background: var(--glass-strong);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius-full);
    }

    .hero-badge .dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        margin-right: 8px;

        background: var(--success);
        border-radius: 50%;
        box-shadow: 0 0 8px var(--success);
    }

    .landing-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
        margin-bottom: 90px;
    }

    .landing-stats .stat-card {
        padding: 24px 20px;
    }

    .landing-stats .stat-value {
        font-size: 2.4rem;
    }

    .features {
        margin-bottom: 90px;
    }

    .features h2,
    .cta-band h2 {
        text-align: center;
        font-size: clamp(1.6rem, 3.5vw, 2.3rem);
        margin-bottom: 8px;
    }

    .features .section-sub {
        margin: 0 auto 40px;
        max-width: 520px;
        text-align: center;
        color: var(--text-2);
    }

    .feature-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 18px;
    }

    .feature-card {
        padding: 26px 24px;
    }

    .feature-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        width: 44px;
        height: 44px;
        margin-bottom: 16px;

        color: #c4b5fd;

        background: var(--grad-accent-soft);
        border: 1px solid rgba(139, 92, 246, 0.3);
        border-radius: var(--radius-md);
    }

    .feature-card h3 {
        margin-bottom: 8px;
        font-size: 1.08rem;
    }

    .feature-card p {
        margin: 0;
        font-size: 0.92rem;
        color: var(--text-2);
    }

    .cta-band {
        margin-bottom: 80px;
        padding: 56px 32px;
        text-align: center;
    }

    .cta-band p {
        margin: 0 auto 28px;
        max-width: 460px;
        color: var(--text-2);
    }

    .landing-footer {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 12px;

        padding: 26px 24px;

        border-top: 1px solid var(--glass-border);

        font-size: 0.88rem;
        color: var(--text-3);
    }

    @media (max-width: 640px) {
        .hero {
            padding: 8vh 0 7vh;
        }

        .landing-stats {
            grid-template-columns: repeat(2, 1fr);
            margin-bottom: 60px;
        }

        .features,
        .cta-band {
            margin-bottom: 60px;
        }
    }
    </style>
</head>
<body class="landing">

<div class="bg-orbs" aria-hidden="true">
    <div class="orb orb-violet"></div>
    <div class="orb orb-blue"></div>
    <div class="orb orb-teal"></div>
</div>

<nav class="navbar">
    <div class="nav-left">
        <a class="nav-brand" href="<?= BASE_PATH ?>/">
            <span class="brand-mark">IT</span>
            <span class="gradient-text">InternTrack</span>
        </a>
    </div>

    <div class="nav-right">
        <?php if ($loggedIn): ?>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/dashboard.php">Go to dashboard</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= BASE_PATH ?>/login.php">Login</a>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/register.php">Get started</a>
        <?php endif; ?>
    </div>
</nav>

<main>

    <section class="hero">
        <span class="hero-badge"><span class="dot"></span>Internship season is live</span>

        <h1>Track every application.<br><span class="gradient-text">Beat your friends.</span></h1>

        <p class="hero-sub">
            InternTrack turns the internship grind into a competition.
            Every application, interview and offer scores points — climb the
            leaderboard before your friends do.
        </p>

        <div class="hero-ctas">
            <?php if ($loggedIn): ?>
                <a class="btn btn-primary" href="<?= BASE_PATH ?>/dashboard.php">Go to dashboard</a>
                <a class="btn btn-ghost" href="<?= BASE_PATH ?>/leaderboard.php">View leaderboard</a>
            <?php else: ?>
                <a class="btn btn-primary" href="<?= BASE_PATH ?>/register.php">Start competing</a>
                <a class="btn btn-ghost" href="<?= BASE_PATH ?>/login.php">I have an account</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="landing-stats">
        <div class="stat-card glass-card glass-card--hover">
            <span class="stat-value gradient-text"><?= statValue($stats["applications"]) ?></span>
            <span class="stat-label">Applications sent</span>
        </div>
        <div class="stat-card glass-card glass-card--hover">
            <span class="stat-value gradient-text"><?= statValue($stats["interviews"]) ?></span>
            <span class="stat-label">Interviews landed</span>
        </div>
        <div class="stat-card glass-card glass-card--hover">
            <span class="stat-value gradient-text"><?= statValue($stats["offers"]) ?></span>
            <span class="stat-label">Offers received</span>
        </div>
        <div class="stat-card glass-card glass-card--hover">
            <span class="stat-value gradient-text"><?= statValue($stats["points"]) ?></span>
            <span class="stat-label">Points scored</span>
        </div>
        <div class="stat-card glass-card glass-card--hover">
            <span class="stat-value gradient-text"><?= statValue($stats["competitors"]) ?></span>
            <span class="stat-label">Competitors</span>
        </div>
    </section>

    <section class="features">
        <h2>Built for the <span class="gradient-text">grind</span></h2>
        <p class="section-sub">Everything you need to stay on top of internship season — and on top of the leaderboard.</p>

        <div class="feature-grid">
            <div class="feature-card glass-card glass-card--hover">
                <span class="feature-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>
                </span>
                <h3>Competitive leaderboard</h3>
                <p>Pending +2, interview +8, offer +18 — with tag multipliers up to "Absolute Cinema". Every status change counts forever.</p>
            </div>

            <div class="feature-card glass-card glass-card--hover">
                <span class="feature-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                </span>
                <h3>Score evolution chart</h3>
                <p>Watch every race develop day by day — every application event is plotted on a live multiplayer score chart.</p>
            </div>

            <div class="feature-card glass-card glass-card--hover">
                <span class="feature-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                </span>
                <h3>Exam calendar</h3>
                <p>Shared exam schedule with countdowns and per-person highlights — because internship season overlaps exam season.</p>
            </div>

            <div class="feature-card glass-card glass-card--hover">
                <span class="feature-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 8 4 4-4 4"/><path d="M12 16h6"/><rect width="20" height="16" x="2" y="4" rx="2"/></svg>
                </span>
                <h3>Typing battle</h3>
                <p>Type cover-letter classics against the clock. WPM highscores, accuracy tracking and bragging rights included.</p>
            </div>
        </div>
    </section>

    <section class="cta-band glass-card">
        <h2>Ready to <span class="gradient-text">compete</span>?</h2>
        <p>Applications are points. Points are pride. Get on the board.</p>
        <?php if ($loggedIn): ?>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/dashboard.php">Go to dashboard</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/register.php">Create your account</a>
        <?php endif; ?>
    </section>

</main>

<footer class="landing-footer">
    <span>InternTrack</span>
    <span>·</span>
    <span class="footer-label">Switch to:</span>
    <a href="/basti/">Basti</a>
    <a href="/ben/">Ben</a>
</footer>

</body>
</html>
