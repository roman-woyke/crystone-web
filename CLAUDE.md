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
| `api/log-study-session.php` | POST | Log a study session (timer or manual); validates duration/date/module, creates the module if `new_module` is new; returns JSON |
| `api/get-study-data.php` | GET | Study-counter data: module list (exam titles + custom, with `custom` flag) and sessions aggregated per user/module/day |
| `api/study-timer.php` | POST | Single state machine for `study_status` (`action` = start/presence/pause/resume/reset/stop/log/library); `start` needs a module, `presence` is a module-less quick stopwatch, `pause`/`resume` work on either; `library` toggles the BIB ("at the library") flag without bumping `updated_at` so a paused break timer keeps ticking; `log` writes a `study_sessions` row server-side (or several, when given an `allocations` split) and stamps `at_library` from the status row; returns `{me, studying}` |
| `api/get-study-status.php` | GET | Current study presence: my timer state (`me`), everyone currently studying (`studying`, each with `running`/`break_elapsed`), and today's logged sessions (`recap`, each with start/end time) |
| `api/log-time.php` | POST | Log time (`seconds`, optional `note`) against an owned project; validates duration (1–86400s) and ownership; returns `OK` |
| `api/patch-project.php` | POST | Update `name`, `description`, or `color` on an owned project; returns `OK` |
| `api/delete-project.php` | POST | Delete an owned project plus its time entries; returns `OK` |
| `api/delete-time-entry.php` | POST | Delete one owned `project_time_entries` row; returns `OK` |
| `api/fwordle-state.php` | GET | Full fWordle state for today: my boards (with letters), opponents' boards (colors only), the tomorrow-word pick panel, and per-player `stats` (solve streak + average guesses, via `fwordleStats`) |
| `api/fwordle-guess.php` | POST | Submit a guess (`word` + `offset`); validates length/position/dictionary server-side, scores every board, updates `fwordle_results`; returns the new state |
| `api/fwordle-choose.php` | POST | Set one of tomorrow's words (`word`); solver-only, validated against the dictionary + tomorrow's length (duplicates between players allowed — rejecting would leak a taken word); returns `{ok, word, tomorrow_length}` |
| `api/fwordle-hint.php` | POST | Use a joker (`type` = armor/orange/green); each costs 1 streak, one of each per day. A player with 0 streak gets one **free** joker/day (their first); otherwise rejected when effective streak < 1. `armor` is board-less (+1 guess overall); `orange`/`green` need a `board` and only reveal info not already known from your guesses (else 422). Stores it in `fwordle_hints` and returns the new state |

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

### Study counter

`study-counter.php` (auth-guarded) lets users log study time two ways: a live JS timer or a manual hours/minutes + date entry. Both share one module picker. The **module list** is the distinct exam titles from `exams` (defaults) plus rows in `study_modules` (custom, flagged with a small "custom" badge). Sessions go to `study_sessions` with `module_name` as a plain string (decoupled from both tables) and a `studied_on` DATE.

- `api/log-study-session.php` validates duration (1–86400s) and date (valid `Y-m-d`, not future), resolves the module against the allowed set, and — when `new_module` is a genuinely new name — inserts it into `study_modules` first. The page reloads after adding a new custom module so the shared list/colors update.
- `api/get-study-data.php` returns the module list and sessions aggregated per user/module/day. All chart/podium aggregation is client-side.
- The chart is a hand-built HTML/CSS grouped bar chart (no Chart.js): one group of bars per weekday, one **solid user-coloured** bar per user (height = that day's total), so "whose bar" reads instantly. The per-module split is **not** stacked into the bar — it's shown in the hover tooltip (module-coloured swatches via `moduleColor`) and the Per-module podium. The chart legend is users-only. Week navigation is pure client-side (`weekOffset`); the "next" button is disabled at the current week.
- Above the chart, a podium of total hours studied; a "Per module" toggle crossfades between the overall podium and a per-module grid of mini-podiums. A "📚 Library only" toggle (also in `.podium-controls`) layers on top of the period tabs and restricts both podium views to sessions logged with `at_library=1`; it does not affect the chart.

#### Persistent timer + presence

The study timer is **server-backed** so it survives closing/reloading the page. All state lives in one row per user in `study_status` (helper `includes/study-status.php` builds the JSON payload). A row is a running stopwatch when `started_at IS NOT NULL` and paused ("on break") when it is `NULL`; `elapsed = accumulated + (started_at ? NOW() - started_at : 0)`. Two modes share this machinery:
- `mode='timer'` — started from the **log-a-session window** with a module; loggable. `log` computes elapsed server-side, writes a `study_sessions` row, and deletes the status row.
- `mode='presence'` — started by the **"I'm studying"** button; a module-less quick stopwatch. Clicking **"Stop"** opens a modal (reusing the log-window module catalog) where the elapsed time can be **split across several modules** (one row per module, minutes each) — `log` accepts an `allocations` JSON array (`[{module|new_module, seconds}]`) and writes one `study_sessions` row per allocation, validating the total against the server-side elapsed; it still accepts a single `module`/`new_module` override — or the session can be **discarded** (`stop`).
- `pause`/`resume` ("I'm on break" / "Resume") are mode-agnostic and operate on the single row, so the break button in the panel pauses a log-window module timer too. `api/study-timer.php` is the only endpoint that mutates `study_status`.
- **BIB** (`📚 BIB` button, `at_library` flag on `study_status`) is an **independent** location tag — you can be at the library while running OR on break, and the flag survives pause/resume. The `library` action toggles it; the SQL sets `updated_at = updated_at` so toggling it during a break does not reset the break-elapsed counter (which is derived from `updated_at`). At-library chips render in a separate **"📚 At the library"** sub-container in the dock (same shape as the break box, blue accent) regardless of their running/break state — the break box and library box are mutually exclusive on the client side, with the library box taking precedence. On `log`, `study_sessions.at_library` is stamped from the status flag so the "📚 Library only" podium filter can replay history.
- A row existing at all means the user is "currently studying". The presence UI is a **sticky, Anki-style flip card** in its own sidebar column (`.study-layout` grid; `.studying-dock` is `position: sticky` so it reserves space at top-right then floats while scrolling; the dock height tracks its content; `.flip-inner.flipped` rotates it): the **front** splits people into **running** chips (study time live-ticking) and an **"On break"** sub-container (frozen study time + a live-ticking break timer); the **back** is a day recap — a per-user timeline of today's logged sessions (`recap` from `studyStatusPayload`), it draws **one block per study interval** (`study_segments`) so a break shows as a real gap — the **real** session span including breaks (`study_sessions.started_at`, carried from `study_status.session_start`; falls back to `created_at − seconds` for manual/pre-migration rows), positioned in minutes from midnight on a fixed **00:00–24:00** axis (auto-zoomed within the day, no midnight overflow/duplication). Timeline blocks are coloured by **module** (the column header carries the user). It polls `get-study-status.php` every 15s, on `visibilitychange`, and on bfcache `pageshow`. The client ticks locally each second and re-syncs on every poll/action, so no drift accumulates. `break_elapsed` (time since the break began) is derived server-side from `updated_at`, which is only bumped when the row is paused. The log-window big display only reflects a `mode='timer'` row (with a break-timer line while paused); a `presence` stopwatch shows only in the panel chip. Starting a timer with a brand-new custom module reloads the page (the timer just resumes from the server afterward).
- **Initial paint is server-rendered.** `study-counter.php` embeds the status payload (`studyStatusPayload`) and the aggregated sessions as `INITIAL_STATUS` / `INITIAL_SESSIONS`, and the JS paints synchronously from them on load (`renderAll()` + `applyMyState`/`renderStudying`) — no fetch round-trip, no "Loading…" flash on each visit. `get-study-data.php`/`get-study-status.php` are only used for background refresh after that.

### Project tracker

`projects.php` (auth-guarded) is a time tracker where projects are **owned per-user** (all mutations are scoped by `user_id`) but **viewable across users**. Each project (`projects` table) has a name, description, and one of eight accent colours; each logged session is a row in `project_time_entries` (`seconds` + optional `note`). Each card shows total contributed time plus today / last-7-days / session-count breakdowns (one aggregate SQL query) with the latest 4 sessions listed.

- **User switcher.** Calendar-style tabs (`?user=<username>`, resolved against the `users` table) let you view *any* user's projects in the same card layout. `$viewUserId` drives the card/entry queries; `$canEdit = ($viewUserId === $myUserId)`. When viewing someone else the page is read-only — the new-project form, the per-card timer, edit/delete actions, and the session ✕ buttons are all gated behind `$canEdit` in PHP (so the controls aren't just hidden, they're never emitted). The create-project POST always inserts for `$myUserId` regardless of the viewed user. A banner states whose projects you're viewing.
- **Standings.** Shown only when 2+ users have projects: a top-3 podium (reusing the `.podium` component) where each card is a link into that user's view. Built from a second `$pdo->query` joining `projects` to `users`, grouped per project then folded into per-user buckets in PHP and `uasort`-ed by total time.

- Time is logged two ways: a **live timer** per card and a **manual** hours/minutes modal. Unlike the study counter's server-backed timer, the project timer is **client-only** — the start epoch is kept in `localStorage` (key `project_timer_<BASE_PATH>_<id>`) so it survives reloads, and on stop the elapsed seconds POST to `api/log-time.php`. There is no shared/presence aspect.
- `api/log-time.php` validates duration (1–86400s, capped) and project ownership before inserting. `patch-project.php` (name/description/colour, validated against the colour palette), `delete-project.php` (deletes entries then the project), and `delete-time-entry.php` all include `AND user_id = ?` ownership checks. The page reloads after any mutation rather than patching the DOM.

### fWordle

`fwordle.php` (auth-guarded) is a daily, multi-board, multiplayer Wordle. All logic lives in `includes/fwordle.php`; the page server-renders the initial state and then polls. The word lists are **bundled text files** in `includes/fwordle/` (not the DB): `dict-<5..10>.txt` (full SOWPODS, for guess validation) and `answers-<5..10>.txt` (common words ∩ SOWPODS, for random answers + suggestions). `fwordleIsValidWord()` lazily loads + caches the one length it needs.

- **One puzzle per day** (server date). The day's **length L** is rolled once with weights 5→50% / 6→25% / 7→15% / 8→6% / 9→3% / 10→1% (`fwordleRollLength`) and stored on `fwordle_days`. **N boards** (1–4), all length L, are solved Quordle-style: one guess scored against every board.
- **Variable-length guesses.** A guess is any valid word of length 5..L, **positioned** within the L-wide row by typing leading spaces (an on-screen **space bar**; the position is the offset of the contiguous letter block). It's stored in `fwordle_guesses.guess` as an L-char string with `_` for blank cells (e.g. `__HELLO___`), encoding word + offset together — so no schema column is needed for position. Blank cells score as `empty` (neutral); `fwordleScore()` does standard greens-first multiplicity over the filled cells. A board is only **solved** by the full L-length answer at offset 0 (`guess === answer`). Base guess limit `fwordleMaxGuesses($numBoards, $length)` = `5 + numBoards` → 9 for the fixed 4 boards (length-independent; 9 = a cat's nine lives). The armor joker adds +1 **per player**, so `max_guesses` is personal (`me.max_guesses`, each opponent's `o.max_guesses`); the JS reads it live via `myMax()` since it can change mid-game.
- **Keyboard feedback is per-board.** Because one guess hits every board, the on-screen keyboard tints letters for a *single* board at a time; a vertical `1·2·3·4` switcher (left of the keyboard, `kbStatesFor`/`renderKeyboard`) chooses which board's green/orange/grey colours are shown — `kbStatesFor` merges both guesses and that board's hint.
- **Board numbering is fixed per player** (`fwordlePlayerRank`: roman→ben→basti→lorenz): `fwordleFinalizeWords` orders the chosen-word slots by that rank, not solve time, so the boards are stable. (Fewer-board days number 1..N in that rank order.)
- **Jokers** (`fwordle_hints`, `api/fwordle-hint.php`): 3 per day, one of each type, and **each costs 1 streak** — `fwordleStreakInfo` subtracts jokers used within the current streak window, and a joker is refused if the player's effective streak is < 1 (so max 3 streak lost/day). **Free joker:** a player with 0 streak gets one free joker/day — allowed as their first joker that day (`me.free_joker` = `effective < 1 && jokers used today == 0`); the API gate only refuses when `streak < 1 && usedToday > 0`. The types: **armor** (🛡️ +1 guess overall — board-less, grants a personal `max_guesses + 1`; the extra row is tinted `.fw-cell.bonus`), **orange** (🔍 +2 present letters on a board), **green** (🧩 +1 correctly-placed letter on a board) — type names in the DB are still `armor`/`orange`/`green`; the emoji/labels are display-only. Board jokers never apply to an owned/solved board and only reveal info not derivable from the player's own guesses (`fwordleComputeHint` / `fwordleBoardKnowledge`); the two board types may **stack on the same board** (only one-per-type is enforced, via the PK). The UI is a **shared joker bar** above the boards (`renderJokers`): each button shows its 1-streak cost (or "free"), with the player's spendable streak as a `.fw-streak-wallet` chip on the same row; armor applies instantly; orange/green are click-then-pick-a-board (`pendingHint` → `.fw-board.pickable`). A used board joker renders as an **extra board row beneath its board** (`.fw-hint-rows`, `hintRowHtml`, payload parsed to a `cells` array) and tints the keyboard. Opponents show a `🃏` joker count. The sidebar 🔥 streak shows the effective (post-joker) value.
- **Board count is fixed at 4**: `fwordleFinalizeWords()` runs when a day arrives and always sets `numBoards = FWORDLE_MAX_WORDS` (4). Each prior-day solver (ordered by `fwordle_results.solved_at`) fills + **owns** one slot with their `fwordle_choices` word; remaining slots are random backfill. Failing to solve carries enough intrinsic penalty (no word pick, lost streak) without also shrinking the grid. Finalize is idempotent and race-safe (re-checks `words_finalized` under `SELECT … FOR UPDATE`).
- **Choosing tomorrow's word**: the first ≤4 solvers (`fwordleChoiceEligible`) get a pick. Tomorrow's row (and its length) is created lazily via `fwordleEnsureDay` so the picker can constrain to the right length. Three stable suggestions come from the answer pool (`fwordleSuggestions`, deterministic per user/day); a custom word is validated against the dictionary + length; duplicates between players are allowed (two boards may share a word) since rejecting a taken word would spoil it. `api/fwordle-choose.php` `REPLACE`s into `fwordle_choices`, so a pick can be changed until that day finalizes.
- **You don't solve your own pick.** A board whose word you chose (`fwordle_words.chooser_id` = you) is **auto-solved and revealed** for you — `fwordleUserBoards($guesses, $answers, $owned)` pre-marks those positions, so they don't count toward your win and render as a green "★ your word" row. `fwordleState` stamps a result row for the no-guess auto-win case. (Edge: on a 1-board day where you picked the word, you win instantly — rare, since N tracks the prior day's solver count.)
- **Live competition**: opponents' boards are sent as **colors only, never letters** (`fwordleState` strips `text` from everyone but the requester). Own answers are revealed only once *I* finish (win or out of guesses). The page polls `fwordle-state.php` every 6s, on `visibilitychange`, and on bfcache `pageshow`; a poll never clobbers the in-progress local guess buffer/offset (those live only client-side until submitted).

## Database schema

See README for the full `CREATE TABLE` statements. Key points:
- `application_status_history` has `ON DELETE CASCADE` from `applications`.
- The `tag` column is on `applications`, not on history rows — but score computation joins through `applications` to get the tag when replaying history.
- Score computation: `get-leaderboard.php` fetches all history rows joined to `applications` (for the tag), then calls `scorePoints()` in a PHP loop.
- Status ENUM values: `PENDING`, `INTERVIEW`, `OFFER`, `REJECTED`, `GHOSTED`.
- Tag values: `MAYBE`, `PROBABLY`, `FOR SURE`, `ABSOLUTE CINEMA`.
