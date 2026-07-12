# Internship Leaderboard

A shared leaderboard for tracking internship applications with friends, plus a study counter, project time tracker, exam calendar, and a daily multiplayer Wordle (Boardle).

The app is a TypeScript/Next.js app — see [`CLAUDE.md`](CLAUDE.md) for architecture and deployment. For a full step-by-step local setup walkthrough (Docker, `.env`, schema, troubleshooting), see [`SETUP.md`](SETUP.md). This file is the **authoritative MySQL schema reference**: `prisma/schema.prisma` is introspected from it and must be kept in sync manually when either changes.

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

-- Boardle (app/(app)/boardle): a daily multi-board, multiplayer Wordle. One puzzle a
-- day; N boards (1–4) all of the same length L. The word lists live as bundled
-- text files in includes/boardle/, NOT in the DB.

-- One row per calendar day: the day's word length + whether its answer words
-- have been finalized. Length is rolled when the row is first created.
CREATE TABLE boardle_days (
    game_date       DATE NOT NULL PRIMARY KEY,
    word_length     TINYINT UNSIGNED NOT NULL,
    words_finalized TINYINT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- The answer words for each day (1–4), one row per board position.
-- source = 'chosen' (a prior-day solver picked it) or 'random' (backfilled).
CREATE TABLE boardle_words (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    game_date  DATE NOT NULL,
    position   TINYINT UNSIGNED NOT NULL,
    word       VARCHAR(10) NOT NULL,
    source     ENUM('chosen','random') NOT NULL DEFAULT 'random',
    chooser_id INT NULL REFERENCES users(id),
    hint       VARCHAR(500) NULL,
    UNIQUE KEY uniq_day_pos (game_date, position)
);

-- A solver's chosen word for the NEXT day. game_date is the date the word is
-- FOR; user_id is the chooser. Folded into boardle_words when that day arrives.
CREATE TABLE boardle_choices (
    game_date  DATE NOT NULL,
    user_id    INT NOT NULL REFERENCES users(id),
    word       VARCHAR(10) NOT NULL,
    hint       VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id)
);

-- Per user, per day outcome. solved_at orders who earns choice rights
-- (first 4 solvers). finished locks the board (win or out of guesses).
CREATE TABLE boardle_results (
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
CREATE TABLE boardle_guesses (
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
-- via_freeze = 1 when the joker was bought by exchanging a streak freeze
-- (once/day) instead of paying a streak.
CREATE TABLE boardle_hints (
    game_date  DATE NOT NULL,
    user_id    INT NOT NULL REFERENCES users(id),
    type       ENUM('armor','orange','green') NOT NULL,
    board_pos  TINYINT UNSIGNED NULL,
    payload    VARCHAR(64) NOT NULL,
    via_freeze TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (game_date, user_id, type)
);

-- ── Boardle: Unlimited real-time mode ───────────────────────────────────────
-- A single shared "room" (id is always 1 — one instance app-wide, like the
-- study counter's presence row). A round starts once >=2 players are ready,
-- then moves lobby -> voting -> selecting -> playing -> results -> lobby.
-- Phase transitions are resolved lazily (like boardleFinalizeWords): every
-- state read/write calls boardleRtAdvance() first, which checks the phase's
-- deadline and/or completion condition and steps the state machine forward.
CREATE TABLE boardle_rt_state (
    id               TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    phase            ENUM('lobby','voting','selecting','playing','results') NOT NULL DEFAULT 'lobby',
    round_id         INT NOT NULL DEFAULT 0,
    phase_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    length_choices   VARCHAR(20) NULL,
    word_length      TINYINT UNSIGNED NULL,
    num_boards       TINYINT UNSIGNED NULL
);
INSERT INTO boardle_rt_state (id) VALUES (1);

-- Who has marked themselves ready in the lobby. Cleared whenever a round
-- starts (snapshotted into boardle_rt_players) and whenever "Continue" on
-- the results screen returns everyone to the lobby.
CREATE TABLE boardle_rt_ready (
    user_id    INT NOT NULL PRIMARY KEY REFERENCES users(id),
    ready      TINYINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- The players locked into a round (snapshotted from ready players when
-- voting starts) and their fixed board position — each player picks the
-- word for their own position (auto-solved for them, like normal Boardle).
CREATE TABLE boardle_rt_players (
    round_id INT NOT NULL,
    user_id  INT NOT NULL REFERENCES users(id),
    position TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (round_id, user_id)
);

-- One length vote per player per round, from that round's 3 rolled choices.
CREATE TABLE boardle_rt_votes (
    round_id INT NOT NULL,
    user_id  INT NOT NULL REFERENCES users(id),
    length   TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (round_id, user_id)
);

-- Each round's boards: the word + mandatory hint the owning player chose
-- (or an auto-pick if they ran out of time).
CREATE TABLE boardle_rt_words (
    round_id   INT NOT NULL,
    position   TINYINT UNSIGNED NOT NULL,
    chooser_id INT NOT NULL REFERENCES users(id),
    word       VARCHAR(10) NOT NULL,
    hint       VARCHAR(500) NOT NULL,
    PRIMARY KEY (round_id, position)
);

-- Guesses during the playing phase — one shared guess stream per player,
-- scored against every board in the round at once (Quordle-style).
CREATE TABLE boardle_rt_guesses (
    round_id    INT NOT NULL,
    user_id     INT NOT NULL REFERENCES users(id),
    guess_index TINYINT UNSIGNED NOT NULL,
    guess       VARCHAR(10) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (round_id, user_id, guess_index)
);

-- When the playing phase actually started, needed once the round has moved
-- on and boardle_rt_state.phase_started_at has been overwritten by a later
-- phase — solved_at - playing_started_at is a result row's solve time.
CREATE TABLE boardle_rt_rounds (
    round_id           INT NOT NULL PRIMARY KEY,
    playing_started_at DATETIME NOT NULL
);

-- Per-round outcome, replayed into the unlimited-mode standings panel.
-- Freezes/streaks don't apply here — this mode has its own two win
-- categories instead: speed_win (fastest solve_at - playing_started_at
-- among that round's solvers) and guess_win (fewest guesses_used among
-- that round's solvers). Both flags are decided once, when the round ends.
CREATE TABLE boardle_rt_results (
    round_id     INT NOT NULL,
    user_id      INT NOT NULL REFERENCES users(id),
    finished     TINYINT NOT NULL DEFAULT 0,
    solved       TINYINT NOT NULL DEFAULT 0,
    guesses_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
    solved_at    DATETIME NULL,
    speed_win    TINYINT NOT NULL DEFAULT 0,
    guess_win    TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (round_id, user_id)
);
```

> **Streak freezes** are not a table — everyone starts with 3, earns +3 per 7
> days of streak, and a freeze auto-bridges a missed day (or is exchanged for a
> joker once/day). The balance is **derived** by replaying solved days +
> `boardle_hints.via_freeze`, so the only persisted state is that column.

> **Deploy note:** run the corresponding `CREATE TABLE` on the database
> **before** pushing code that uses a new table, otherwise pages hitting it
> will 500.
>
> **fwordle → boardle rename migration:** the game was renamed from fWordle to
> Boardle; the code now queries `boardle_*` tables, so the existing
> `fwordle_*` tables must be renamed in place (data is kept):
> ```sql
> RENAME TABLE fwordle_days     TO boardle_days,
>              fwordle_words    TO boardle_words,
>              fwordle_choices  TO boardle_choices,
>              fwordle_results  TO boardle_results,
>              fwordle_guesses  TO boardle_guesses,
>              fwordle_hints    TO boardle_hints;
> ```
>
> **boardle_hints migration:** the joker rework changed this table (new `type`
> enum, nullable `board_pos`, new PK). Jokers are throwaway daily state, so the
> simplest migration is to recreate it:
> ```sql
> DROP TABLE IF EXISTS boardle_hints;
> -- then run the CREATE TABLE above
> ```
>
> **boardle_hints.via_freeze migration:** streak freezes can be exchanged for
> jokers, recorded on the hint row:
> ```sql
> ALTER TABLE boardle_hints ADD COLUMN via_freeze TINYINT NOT NULL DEFAULT 0;
> ```
>
> **boardle_words.hint / boardle_choices.hint migration:** the word picker can
> attach an optional clue shown under a board from the start:
> ```sql
> ALTER TABLE boardle_words ADD COLUMN hint VARCHAR(500) NULL;
> ALTER TABLE boardle_choices ADD COLUMN hint VARCHAR(500) NULL;
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
>
> **Boardle unlimited real-time mode migration:** new feature, new tables —
> run the `boardle_rt_*` `CREATE TABLE` statements above (including the
> `boardle_rt_state` seed row) before deploying `app/(app)/boardle-rt/` /
> `lib/boardle-rt.ts` / the `api/boardle/rt/*` endpoints, otherwise every
> request to them 500s.

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

## App structure

See [`CLAUDE.md`](CLAUDE.md) for the current file structure, API route map, and per-feature architecture (Next.js App Router + Prisma). This app previously existed as a PHP/MySQL implementation living at the repo root, and the Next.js rewrite previously lived nested under `internship-leaderboard-next/` during the migration; both have since been folded into the repo root — see [`plan.md`](plan.md) for the migration history.
