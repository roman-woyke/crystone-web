# Internship Leaderboard

A shared leaderboard for tracking internship applications with friends.

---

## Deployment

The repo is deployed to Hostinger at the **web root** (`public_html/`) via a Git webhook — pushing the deployed branch updates the live site. All application code is in the repo; the only file that isn't is `config.php`, which holds the database credentials and is kept **one level above the web root** so it can never be served over HTTP.

### `config.php` (one level above the web root, managed manually on the server)

Defines `INVITE_CODE` (required to register an account — keep this secret since the repo is public), sets `BASE_PATH` (the URL prefix every page uses for links and redirects — empty when served from the web root), and opens the PDO connection into `$pdo`.

```php
<?php

define("INVITE_CODE", "your-secret-code-here");

// Served from the web root — no subfolder prefix.
define("BASE_PATH", "");

$pdo = new PDO(
    "mysql:host=localhost;dbname=YOUR_DB;charset=utf8mb4",
    "YOUR_USER",
    "YOUR_PASS"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
```

Every page reaches it with `require_once __DIR__ . "/../config.php"` (repo-root files) or `"/../../config.php"` (files in `api/` and `includes/`). The repo's own `index.php` redirects visitors landing at the root to the dashboard or login.

---

## Database schema

The app uses a single MySQL database. Run this SQL to set it up:

```sql
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar        MEDIUMTEXT NULL,  -- profile picture as a base64 data URL (resized client-side)
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
-- `seconds` is studied time only; `started_at` is the real wall-clock start
-- (study + breaks) so the day recap can draw the true window (NULL = manual /
-- pre-migration → recap falls back to created_at - seconds).
-- `at_library` is copied from study_status.at_library at log time so the
-- "library" podium filter can replay history.
CREATE TABLE study_sessions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id),
    module_name VARCHAR(255) NOT NULL,
    seconds     INT NOT NULL,
    started_at  DATETIME NULL,
    studied_on  DATE NOT NULL,
    at_library  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Live "currently studying" state — at most one row per user, persistent
-- across page reloads. elapsed = accumulated + (started_at ? NOW() - started_at
-- : 0); a NULL started_at means paused ("on break"). mode='timer' is a loggable
-- module stopwatch (log-a-session window); mode='presence' is a module-less
-- "I'm studying" stopwatch. A row existing at all means currently studying.
-- `at_library` is an independent flag (toggled by the BIB button) — it can be
-- on regardless of running/break state and is copied onto study_sessions at log.
CREATE TABLE study_status (
    user_id       INT NOT NULL PRIMARY KEY REFERENCES users(id),
    mode          ENUM('timer','presence') NOT NULL DEFAULT 'presence',
    module_name   VARCHAR(255) NULL,
    started_at    DATETIME NULL,
    accumulated   INT NOT NULL DEFAULT 0,
    session_start DATETIME NULL,  -- real start of the session, kept across pause/resume
    at_library    TINYINT(1) NOT NULL DEFAULT 0,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- One row per contiguous study INTERVAL. start/resume opens a row (ended_at
-- NULL = the interval running now); pause/log closes it. While a session is
-- in progress the rows have session_id NULL; on log they're attached to the
-- study_sessions row. The day recap draws one block per interval, so breaks
-- show as the gaps between them. `module_name` is the module active when the
-- interval was opened (NULL = before any module was picked), so an "I'm
-- studying" session split across modules keeps each interval's own module and
-- can be logged as one study_sessions row per module.
CREATE TABLE study_segments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id),
    session_id  INT NULL REFERENCES study_sessions(id) ON DELETE CASCADE,
    module_name VARCHAR(255) NULL,
    started_at  DATETIME NOT NULL,
    ended_at    DATETIME NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Project time tracker (projects.php). Per-user projects with a colour and
-- description; each logged session is one row in project_time_entries.
CREATE TABLE projects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL REFERENCES users(id),
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    color       VARCHAR(20) NOT NULL DEFAULT '#8b5cf6',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE project_time_entries (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id    INT NOT NULL REFERENCES users(id),
    seconds    INT NOT NULL,
    note       VARCHAR(255),
    logged_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- fWordle (fwordle.php): a daily multi-board, multiplayer Wordle. One puzzle a
-- day; N boards (1–4) all of the same length L. The word lists live as bundled
-- text files in includes/fwordle/, NOT in the DB.

-- One row per calendar day: the day's word length + whether its answer words
-- have been finalized. Length is rolled when the row is first created.
CREATE TABLE fwordle_days (
    game_date       DATE NOT NULL PRIMARY KEY,
    word_length     TINYINT UNSIGNED NOT NULL,
    words_finalized TINYINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- The answer words for each day (1–4), one row per board position.
-- source = 'chosen' (a prior-day solver picked it) or 'random' (backfilled).
CREATE TABLE fwordle_words (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    game_date  DATE NOT NULL,
    position   TINYINT UNSIGNED NOT NULL,
    word       VARCHAR(10) NOT NULL,
    source     ENUM('chosen','random') NOT NULL DEFAULT 'random',
    chooser_id INT NULL REFERENCES users(id),
    UNIQUE KEY uniq_day_pos (game_date, position)
);

-- A solver's chosen word for the NEXT day. game_date is the date the word is
-- FOR; user_id is the chooser. Folded into fwordle_words when that day arrives.
CREATE TABLE fwordle_choices (
    game_date  DATE NOT NULL,
    user_id    INT NOT NULL REFERENCES users(id),
    word       VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id)
);

-- Per user, per day outcome. solved_at orders who earns choice rights
-- (first 4 solvers). finished locks the board (win or out of guesses).
CREATE TABLE fwordle_results (
    game_date    DATE NOT NULL,
    user_id      INT NOT NULL REFERENCES users(id),
    finished     TINYINT NOT NULL DEFAULT 0,
    solved       TINYINT NOT NULL DEFAULT 0,
    guesses_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
    solved_at    DATETIME NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id)
);

-- Each guess a user has made today (shared across all boards), in order. The
-- guess is stored as an L-wide string with '_' for unused cells (e.g.
-- '__HELLO___'), encoding both the word and where it was positioned.
CREATE TABLE fwordle_guesses (
    game_date   DATE NOT NULL,
    user_id     INT NOT NULL REFERENCES users(id),
    guess_index TINYINT UNSIGNED NOT NULL,
    guess       VARCHAR(10) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id, guess_index)
);

-- Jokers: 3 per player per day, one of each type, each costing 1 streak.
--   armor  → +1 guess overall; board-less (board_pos NULL), payload = '1'.
--   orange → +2 present letters on a board; payload = up to 2 "letter:position"
--            cells (letter is in the word but NOT at that shown spot), comma-joined.
--   green  → +1 correctly-placed letter on a board; payload = one "letter:position".
-- PK enforces one-of-each-type per day; armor/orange/green may share a board.
CREATE TABLE fwordle_hints (
    game_date  DATE NOT NULL,
    user_id    INT NOT NULL REFERENCES users(id),
    type       ENUM('armor','orange','green') NOT NULL,
    board_pos  TINYINT UNSIGNED NULL,
    payload    VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id, type)
);
```

> **Deploy note:** run the corresponding `CREATE TABLE` on the database
> **before** pushing code that uses a new table, otherwise pages hitting it
> will 500.
>
> **fwordle_hints migration:** the joker rework changed this table (new `type`
> enum, nullable `board_pos`, new PK). Jokers are throwaway daily state, so the
> simplest migration is to recreate it:
> ```sql
> DROP TABLE IF EXISTS fwordle_hints;
> -- then run the CREATE TABLE above
> ```
>
> **study_segments migration:** the "I'm studying" session can now be split
> across modules, so each interval stores the module that was active when it
> opened:
> ```sql
> ALTER TABLE study_segments ADD COLUMN module_name VARCHAR(255) NULL AFTER session_id;
> ```
>
> **users.avatar migration:** profile pictures are stored as a base64 data URL
> on the user row:
> ```sql
> ALTER TABLE users ADD COLUMN avatar MEDIUMTEXT NULL AFTER password_hash;
> ```

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
└── config.php             ← DB config + BASE_PATH (above web root, not served over HTTP)

public_html/               ← web root = this repo
├── api/                   ← JSON endpoints (called by JS fetch)
│   ├── delete-application.php
│   ├── delete-project.php      ← project tracker: delete a project (cascades entries)
│   ├── delete-study-session.php ← study counter: delete one of my logged sessions
│   ├── delete-time-entry.php   ← project tracker: remove a single logged session
│   ├── fwordle-choose.php      ← fWordle: set one of tomorrow's words (solver-only)
│   ├── fwordle-guess.php       ← fWordle: submit a guess (validated + scored server-side)
│   ├── fwordle-state.php       ← fWordle: full game state (mine + opponents' colors)
│   ├── get-leaderboard.php
│   ├── get-my-study-sessions.php ← study counter: my logged sessions (manage window)
│   ├── get-raw-events.php
│   ├── get-score-history.php
│   ├── get-study-data.php      ← study counter: modules + aggregated sessions
│   ├── get-study-status.php    ← study counter: my timer + who's studying now
│   ├── log-study-session.php   ← study counter: log a session (timer/manual)
│   ├── log-time.php            ← project tracker: log time against a project
│   ├── study-timer.php         ← study counter: persistent timer/presence state machine
│   ├── patch-application.php
│   ├── patch-project.php       ← project tracker: edit name/description/colour
│   ├── toggle-user-exam.php    ← exam calendar checkbox toggle
│   └── upload-avatar.php       ← set/clear the logged-in user's profile picture
├── assets/
│   ├── css/style.css      ← design tokens + shared glass components
│   └── js/app.js          ← mobile nav toggle + countUp helper
├── includes/
│   ├── fwordle/           ← fWordle word data: dict-<5..10>.txt (validation) + answers-<5..10>.txt (answer pool)
│   ├── fwordle.php        ← fWordle: length roll, day lifecycle, scoring, state payload
│   ├── footer.php
│   ├── header.php         ← brand, conditional nav, fonts, background orbs
│   ├── scoring.php        ← shared scoring logic + SQL helpers
│   ├── session.php        ← auth guard (redirects to login if not logged in)
│   ├── start-session.php  ← session config (30-day cookie)
│   └── study-status.php   ← shared "currently studying" payload helper
├── calendar.php           ← shared exam calendar (July 2026)
├── dashboard.php          ← main app page (add/edit/delete applications)
├── fwordle.php            ← daily multi-board multiplayer Wordle
├── index.php              ← redirects root visitors to the dashboard / login
├── leaderboard.php        ← public leaderboard page
├── login.php
├── logout.php
├── projects.php           ← project time tracker (timer + manual, per-project totals)
├── register.php
├── score-chart.php        ← chart partial (included by leaderboard.php)
├── score-table.php        ← table partial (included by leaderboard.php)
└── study-counter.php      ← study session tracker (timer + manual, weekly chart)
```
