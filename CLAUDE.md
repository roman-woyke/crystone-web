# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A shared PHP/MySQL leaderboard for tracking internship applications. Three people each get their own deployed instance (roman, basti, ben) on the same server, each backed by a separate database. The repo is identical across all three branches — only the database credentials differ.

## Running locally

There is no build step, package manager, or test suite. To run locally, you need a PHP + MySQL environment (e.g. XAMPP, Laragon, or Docker). The app requires a `config.php` one directory above the repo root — see the README for its exact format. Without it, every page dies immediately at `require_once __DIR__ . "/../../config.php"`.

## Architecture

### Config and BASE_PATH

`config.php` lives **outside the repo** at the server root. It detects the active deployment subfolder from `$_SERVER["SCRIPT_NAME"]`, sets `BASE_PATH` to that subfolder (e.g. `/basti`), opens a PDO connection into `$pdo`, and defines `INVITE_CODE`. Every PHP file that needs DB access does `require_once __DIR__ . "/../../config.php"`. Every internal link and redirect uses `BASE_PATH` as a prefix — never hardcode paths.

### Request flow

- Pages (`dashboard.php`, `leaderboard.php`, etc.) include `includes/session.php` which requires `config.php` and guards against unauthenticated access.
- `includes/start-session.php` just starts a 30-day session; it does **not** guard.
- API endpoints under `api/` require `start-session.php` (not `session.php`) and check `$_SESSION["user_id"]` themselves, returning HTTP error codes on failure.
- `leaderboard.php` is a shell — it includes `score-table.php` and `score-chart.php` as partials.

### Scoring model

Scores are **additive and permanent**. Every status change is appended to `application_status_history`; nothing is ever updated or deleted there. The PHP function `scorePoints(string $status, ?string $tag): int` in `includes/scoring.php` maps a single history row to its point value. Scores are recomputed at query time by summing all history rows. The `tag` column on `applications` modifies point values for INTERVIEW and OFFER statuses — the tag at the time the history row is inserted is what matters.

Point values per status change:
- PENDING: +2, REJECTED: −1, GHOSTED: −1
- INTERVIEW: +8 base (−5 Maybe, −3 Probably, +0 For Sure, +5 Absolute Cinema relative to base)
- OFFER: +18 base (same tag modifiers scaled up)

`peakStatusSql()` (also in `scoring.php`) returns a SQL `CASE` expression that computes the highest-priority status ever reached by an application (OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED); it requires a `LEFT JOIN application_status_history AS h`. This is used in the dashboard and leaderboard to show downgraded applications — a ↓ indicator means the current status is lower than the peak.

### API endpoints

All return plain text on error (non-200 status) or their payload on success:

| File | Method | Purpose |
|------|--------|---------|
| `api/get-leaderboard.php` | GET | Full leaderboard JSON including per-user applications |
| `api/patch-application.php` | POST | Update `status`, `tag`, `notes`, or `job_link` on an owned application; status changes also write to history |
| `api/delete-application.php` | POST | Delete an owned application (cascades history) |
| `api/get-score-history.php` | GET | Daily cumulative scores per user (for the chart line) |
| `api/get-raw-events.php` | GET | Raw history rows with score deltas (for chart tooltips) |
| `api/toggle-user-exam.php` | POST | Toggle exam selection for the calendar (uses username, not user_id) |
| `api/submit-typing-score.php` | POST | Save a typing-battle run; WPM/accuracy computed server-side from raw counters with anti-cheat bounds; returns JSON |
| `api/get-typing-scores.php` | GET | Best WPM per user plus run counts (typing-battle highscores) |

### Ownership checks

`patch-application.php` and `delete-application.php` always include `AND user_id = ?` in their WHERE clauses — users cannot modify each other's applications even if they know the ID. The `?next=` redirect parameter in `login.php` is validated to start with `BASE_PATH . "/"` to prevent open redirects.

### Chart rendering (score-chart.php)

The chart does not use pre-aggregated scores. Instead:
1. `get-score-history.php` returns daily cumulative scores per user; a synthetic zero-point entry is prepended one day before the first event so lines start at 0.
2. `get-raw-events.php` returns raw events for tooltip details.
3. The JS spreads same-day events as sub-points (fractional x-axis indices) so they don't overlap, landing the last sub-point exactly on the whole-day grid line.

All chart JS is inline in `score-chart.php`; `assets/js/app.js` carries only site-wide helpers (mobile nav toggle, `countUp` animation) and is loaded by `header.php` with the same `filemtime` cache-busting as the CSS.

### Exam feature

`calendar.php` and `api/toggle-user-exam.php` use a separate data model (`exams`, `user_exams` tables) keyed by `username` string rather than `user_id`. This is independent from the application-tracking feature.

### Landing page

`index.php` at the repo root is a standalone marketing page (it does not use `header.php`/`footer.php`). It is reachable logged-out and renders aggregate stats server-side via direct PDO queries wrapped in `try/catch` (dashes on DB failure). Logged-in visitors see dashboard CTAs instead of being redirected. It does not conflict with the server-root `index.php` outside the repo.

### Typing battle

`typing-game.php` (auth-guarded) runs a 60-second typing round over the sentence corpus in `includes/typing-sentences.php`. The client submits only raw counters (`correct_chars`, `typed_chars`, `duration_ms`); `api/submit-typing-score.php` validates bounds (duration 55–65s, WPM ≤ 220, `correct <= typed`, `typed <= 2500`) and computes WPM/accuracy server-side into the `typing_scores` table.

## Database schema

See README for the full `CREATE TABLE` statements. Key points:
- `application_status_history` has `ON DELETE CASCADE` from `applications`.
- The `tag` column is on `applications`, not on history rows — but score computation joins through `applications` to get the tag when replaying history.
- Score computation: `get-leaderboard.php` fetches all history rows joined to `applications` (for the tag), then calls `scorePoints()` in a PHP loop.
- Status ENUM values: `PENDING`, `INTERVIEW`, `OFFER`, `REJECTED`, `GHOSTED`.
- Tag values: `MAYBE`, `PROBABLY`, `FOR SURE`, `ABSOLUTE CINEMA`.
