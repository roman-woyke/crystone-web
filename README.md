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

All three branches contain the same application code. The only thing that differs per deployment is the **database credentials**, which are stored in a shared `config.php` file that lives **outside the repo** at the server root.

---

## Files outside the repo (managed manually on the server)

Two files live at the server root, one level above the subfolders. They are not tracked by Git and must be managed directly on the server (e.g. via Hostinger's File Manager or SFTP).

### `/config.php`

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

### `/index.php`

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
```

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
/                          ← server root (not in repo)
├── config.php             ← shared DB config + BASE_PATH (not in repo)
├── index.php              ← root redirect (not in repo)
├── roman/                 ← main branch deployment
├── basti/                 ← basti branch deployment
└── ben/                   ← ben branch deployment

internship-leaderboard/    ← this repo (inside each subfolder)
├── api/                   ← JSON endpoints (called by JS fetch)
│   ├── delete-application.php
│   ├── get-leaderboard.php
│   ├── get-raw-events.php
│   ├── get-score-history.php
│   └── patch-application.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── includes/
│   ├── footer.php
│   ├── header.php
│   ├── scoring.php        ← shared scoring logic + SQL helpers
│   ├── session.php        ← auth guard (redirects to login if not logged in)
│   └── start-session.php  ← session config (30-day cookie)
├── dashboard.php          ← main app page (add/edit/delete applications)
├── leaderboard.php        ← public leaderboard page
├── login.php
├── logout.php
├── register.php
├── score-chart.php        ← chart partial (included by leaderboard.php)
└── score-table.php        ← table partial (included by leaderboard.php)
```
