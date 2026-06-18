# Internship Leaderboard

A shared leaderboard for tracking internship applications with friends. Each person gets their own deployed instance with a separate database.

---

## How branches map to deployments

The repository has three long-lived branches. Each is independently deployed to Hostinger via a webhook, into a dedicated subfolder:

| Branch | Subfolder on server | URL path   |
|--------|---------------------|------------|
| `main` | `/roman/`           | `/roman/`  |
| `basti` | `/basti/`          | `/basti/`  |
| `ben`  | `/ben/`             | `/ben/`    |

All three branches contain the same application code. The only thing that differs per deployment is the **database credentials**, which are stored in a shared `config.php` file that lives **outside the repo, one level above the web root** (so it cannot be served over HTTP).
   
---

## Files outside the repo (managed manually on the server)

Two files are managed directly on the server (e.g. via Hostinger's File Manager or SFTP) and not tracked by Git. `config.php` lives **one level above the web root** so it is never served over HTTP; `index.php` lives at the web root.

### `config.php` (one level above web root)

Detects which subfolder is being served from `$_SERVER["SCRIPT_NAME"]` and connects to the corresponding database. It also defines `BASE_PATH` (used by all pages for links and redirects) and `INVITE_CODE` (required to register an account — keep this secret since the repo is public).

```php
<?php

define("INVITE_CODE", "your-secret-code-here");

$configs = [
    "roman" => [
        "host" => "...",
        "dbname" => "...",
        "user" => "...",
        "pass" => "...",
    ],
    "basti" => [
        "host" => "...",
        "dbname" => "...",
        "user" => "...",
        "pass" => "...",
    ],
    "ben" => [
        "host" => "...",
        "dbname" => "...",
        "user" => "...",
        "pass" => "...",
    ],
];

// Detect current subfolder from the request path
$segment = explode("/", trim($_SERVER["SCRIPT_NAME"], "/"))[0];

define("BASE_PATH", "/" . $segment);

$cfg = $configs[$segment] ?? null;
if (!$cfg) die("Unknown project.");

$pdo = new PDO(
    "mysql:host={$cfg["host"]};dbname={$cfg["dbname"]};charset=utf8mb4",
    $cfg["user"],
    $cfg["pass"],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

### `/index.php` (at web root)

Redirects visitors who land at the root to Roman's instance. Edit this if you want the root to point elsewhere.

```php
<?php
require_once __DIR__ . "/roman/includes/start-session.php";
if (isset($_SESSION["user_id"])) {
    header("Location: /roman/leaderboard.php");
} else {
    header("Location: /roman/login.php");
}
exit;
```

---

## Database schema

Each instance uses its own MySQL database. Run this SQL to set it up:

```sql
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE applications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL REFERENCES users(id),
    company_name VARCHAR(255) NOT NULL,
    job_title    VARCHAR(255),
    job_link     TEXT,
    location     VARCHAR(255),
    status       ENUM('PENDING','INTERVIEW','OFFER','REJECTED','GHOSTED') NOT NULL DEFAULT 'PENDING',
    tag          VARCHAR(50),
    notes        TEXT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE application_status_history (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    status         ENUM('PENDING','INTERVIEW','OFFER','REJECTED','GHOSTED') NOT NULL,
    score_delta    INT NOT NULL DEFAULT 0,
    changed_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Exam calendar (keyed by username string, independent from applications)
CREATE TABLE exams (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    title     VARCHAR(255) NOT NULL,
    professor VARCHAR(255),
    exam_date DATE NOT NULL,
    exam_time TIME NOT NULL
);

CREATE TABLE user_exams (
    username VARCHAR(50) NOT NULL,
    exam_id  INT NOT NULL,
    PRIMARY KEY (username, exam_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Typing battle highscores (typing-game.php). WPM and accuracy are
-- computed server-side in api/submit-typing-score.php.
CREATE TABLE typing_scores (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL REFERENCES users(id),
    wpm           DECIMAL(6,2) NOT NULL,
    accuracy      DECIMAL(5,2) NOT NULL,
    correct_chars INT NOT NULL,
    typed_chars   INT NOT NULL,
    duration_ms   INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Study counter (study-counter.php). Custom modules added by users join the
-- shared list; the default modules are the distinct exam titles from `exams`.
CREATE TABLE study_modules (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL UNIQUE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- One row per logged study session (timer or manual). `module_name` is a plain
-- string so sessions are decoupled from both `exams` and `study_modules`.
CREATE TABLE study_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id),
    module_name VARCHAR(255) NOT NULL,
    seconds     INT NOT NULL,
    studied_on  DATE NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

> **Deploy note:** when merging new tables into another branch's deployment,
> run the corresponding `CREATE TABLE` on that instance's database **before**
> pushing the code, otherwise pages using the table will 500.

---

## Scoring system

Scores are computed from the full status history of each application (every status change is recorded permanently). Changing an application's status later does not erase previous points — it adds new ones.

| Event                  | Points |
|------------------------|--------|
| PENDING                | +2     |
| REJECTED               | -1     |
| GHOSTED                | -1     |
| INTERVIEW (no tag)     | +8     |
| INTERVIEW — Maybe      | +3     |
| INTERVIEW — Probably   | +5     |
| INTERVIEW — For Sure   | +8     |
| INTERVIEW — Absolute Cinema | +13 |
| OFFER (no tag)         | +18    |
| OFFER — Maybe          | +8     |
| OFFER — Probably       | +12    |
| OFFER — For Sure       | +18    |
| OFFER — Absolute Cinema | +28  |

Interview/Offer points shown above are the net gain (the +2 from the initial PENDING is already counted).

---

## File structure

```
/                          ← one level above web root (not in repo)
├── config.php             ← shared DB config + BASE_PATH (above web root, not served over HTTP)
└── public_html/           ← web root
    ├── index.php          ← root redirect (not in repo)
    ├── roman/             ← main branch deployment
    ├── basti/             ← basti branch deployment
    └── ben/               ← ben branch deployment

internship-leaderboard/    ← this repo (inside each subfolder)
├── api/                   ← JSON endpoints (called by JS fetch)
│   ├── delete-application.php
│   ├── get-leaderboard.php
│   ├── get-raw-events.php
│   ├── get-score-history.php
│   ├── get-study-data.php      ← study counter: modules + aggregated sessions
│   ├── get-typing-scores.php   ← typing battle: best WPM per user
│   ├── log-study-session.php   ← study counter: log a session (timer/manual)
│   ├── patch-application.php
│   ├── submit-typing-score.php ← typing battle: validated score submission
│   └── toggle-user-exam.php    ← exam calendar checkbox toggle
├── assets/
│   ├── css/style.css      ← design tokens + shared glass components
│   └── js/app.js          ← mobile nav toggle + countUp helper
├── includes/
│   ├── footer.php
│   ├── header.php         ← brand, conditional nav, fonts, background orbs
│   ├── scoring.php        ← shared scoring logic + SQL helpers
│   ├── session.php        ← auth guard (redirects to login if not logged in)
│   ├── start-session.php  ← session config (30-day cookie)
│   └── typing-sentences.php ← sentence corpus for the typing battle
├── calendar.php           ← shared exam calendar (July 2026)
├── dashboard.php          ← main app page (add/edit/delete applications)
├── index.php              ← marketing landing page (per-instance root)
├── leaderboard.php        ← public leaderboard page
├── login.php
├── logout.php
├── register.php
├── score-chart.php        ← chart partial (included by leaderboard.php)
├── score-table.php        ← table partial (included by leaderboard.php)
├── study-counter.php      ← study session tracker (timer + manual, weekly chart)
└── typing-game.php        ← 60-second typing battle with highscores
```
