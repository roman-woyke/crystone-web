# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Style rules

- Never use emojis in code, comments, or any output — this project is emoji-free.

## What this is

**crystone-web**: a shared PHP/MySQL site for a small group of friends (roman, basti, ben, lorenz) to manage study-related activity. Despite the repo folder name ("internship-leaderboard") and the still-present application tracker, the site's actual center of gravity today is the **study counter** (time tracking + presence) and **Boardle** (a daily multiplayer word game) — both are the most actively developed features. The original internship-application leaderboard and the project time tracker still exist and work, but are secondary. All users push to a single shared branch; every push deploys immediately to `public_html` on the server. There is one shared database and one shared deployment — all users share the same instance.

## Running locally

There is no build step, package manager, or test suite. To run locally, you need a PHP + MySQL environment (e.g. XAMPP, Laragon, or Docker — see `docker/` and `docker-compose.yml`). The app requires a `config.php` one directory above the repo root — see the README for its exact format. Without it, every page dies immediately at `require_once __DIR__ . "/../../config.php"`.

Claude Code's own shell has no `php` binary and no MySQL connection — do not run `php -l`, `php -S`, or similar to lint/verify changes; they will simply fail with "command not found". Verify PHP changes by reading the code (matching braces, checking function signatures against call sites) rather than executing them.

## Architecture

### Config and BASE_PATH

`config.php` lives **outside the repo** at the server root. It detects the active deployment subfolder from `$_SERVER["SCRIPT_NAME"]`, sets `BASE_PATH` to that subfolder (e.g. `/basti`), opens a PDO connection into `$pdo`, and defines `INVITE_CODE`. Every PHP file that needs DB access does `require_once __DIR__ . "/../../config.php"`. Every internal link and redirect uses `BASE_PATH` as a prefix — never hardcode paths.

### Request flow

- Pages (`dashboard.php`, `study-counter.php`, `boardle.php`, etc.) include `includes/session.php` which requires `config.php` and guards against unauthenticated access.
- `includes/start-session.php` just starts a 30-day session; it does **not** guard.
- API endpoints under `api/` require `start-session.php` (not `session.php`) and check `$_SESSION["user_id"]` themselves, returning HTTP error codes on failure.
- `leaderboard.php` is a shell — it includes `score-table.php` and `score-chart.php` as partials.

### API endpoints

All return plain text on error (non-200 status) or their payload on success.

**Study counter:**

| File | Method | Purpose |
|------|--------|---------|
| `api/log-study-session.php` | POST | Log a study session (timer or manual); validates duration/date/module, creates the module if `new_module` is new; returns JSON |
| `api/get-study-data.php` | GET | Module list (exam titles + custom, with `custom` flag) and sessions aggregated per user/module/day |
| `api/study-timer.php` | POST | Single state machine for `study_status` (`action` = start/presence/pause/resume/set_module/reset/stop/log/library); `presence` starts the "I'm studying" stopwatch and accepts an optional starting `module`/`new_module`; `pause`/`resume` work on either mode; `set_module` assigns/switches the running session's module, splitting `study_segments`; `library` toggles the BIB ("at the library") flag without bumping `updated_at`; `log` takes `sessions` (JSON array of `{module, seconds}`) and writes one `study_sessions` row per kept module, trimming intervals from the end; returns `{me, studying}` (`me.parts` is the per-module live breakdown) |
| `api/get-my-study-sessions.php` | GET | My own logged sessions with ids (module, seconds, study-day `date`, started_at, at_library) for the "manage previous sessions" window |
| `api/delete-study-session.php` | POST | Delete one of **my** `study_sessions` rows (`AND user_id = ?`; attached segments cascade); returns `OK` |
| `api/delete-study-module.php` | POST | Delete a custom `study_modules` row by name (default/exam-derived modules can't be deleted this way); returns `OK` |
| `api/get-study-status.php` | GET | Current study presence: my timer state (`me`, incl. `parts`), everyone currently studying (`studying`, each with `running`/`break_elapsed`), and today's logged sessions (`recap`) |

**Boardle:**

| File | Method | Purpose |
|------|--------|---------|
| `api/boardle-state.php` | GET | Full state for today: my boards (with letters), opponents' boards (colors only), the tomorrow-word pick panel, and per-player `stats` (solve streak + average guesses) |
| `api/boardle-guess.php` | POST | Submit a guess (`word` + `offset`); validates length/position/dictionary server-side, scores every board, updates `boardle_results`; returns the new state |
| `api/boardle-choose.php` | POST | Set one of tomorrow's words (`word`); solver-only, validated against the dictionary + tomorrow's length (duplicates between players allowed); returns `{ok, word, tomorrow_length}` |
| `api/boardle-suggest.php` | GET | Three deterministic word suggestions (`boardleRandomSuggestions`) for whichever day the caller is currently eligible to pick a word for (today if no one has guessed yet, else tomorrow); returns `{suggestions, tomorrow_length}` |
| `api/boardle-hint.php` | POST | Use a joker (`type` = armor/orange/green); each costs 1 streak, one of each per day. A player with 0 streak gets one free joker/day. Can instead pay with a streak freeze (`pay=freeze`, once/day, also the automatic fallback when out of streak) — recorded as `boardle_hints.via_freeze=1`. `armor` is board-less (+1 guess overall); `orange`/`green` need a `board` and only reveal info not already known from the player's own guesses (else 422). Returns the new state |
| `api/boardle-add-hint.php` | POST | Let a player who has **solved** a given board write a free-text community `hint` on it (`boardle_words.hint`), but only if that board doesn't already have one (409 if it does) — so future/other solvers get a clue without spoiling the word. Returns the new state |

**Other (application tracker, project tracker, profile, exams):**

| File | Method | Purpose |
|------|--------|---------|
| `api/get-leaderboard.php` | GET | Full leaderboard JSON including per-user applications |
| `api/patch-application.php` | POST | Update `status`, `tag`, `notes`, or `job_link` on an owned application; status changes also write to history |
| `api/delete-application.php` | POST | Delete an owned application (cascades history) |
| `api/get-score-history.php` | GET | Daily cumulative scores per user (for the chart line) |
| `api/get-raw-events.php` | GET | Raw history rows with score deltas (for chart tooltips) |
| `api/toggle-user-exam.php` | POST | Toggle exam selection for the calendar (uses username, not user_id) |
| `api/upload-avatar.php` | POST | Set (`avatar` = base64 image data URL, validated + size-capped) or clear (`remove=1`) the logged-in user's profile picture in `users.avatar`; returns JSON |
| `api/patch-profile.php` | POST | Change the logged-in user's username and/or password; requires `current_password`; validates username format/uniqueness; returns JSON |
| `api/log-time.php` | POST | Log time (`seconds`, optional `note`) against an owned project; validates duration (1-86400s) and ownership; returns `OK` |
| `api/patch-project.php` | POST | Update `name`, `description`, or `color` on an owned project; returns `OK` |
| `api/delete-project.php` | POST | Delete an owned project plus its time entries; returns `OK` |
| `api/delete-time-entry.php` | POST | Delete one owned `project_time_entries` row; returns `OK` |

### Ownership checks

`patch-application.php`, `delete-application.php`, and the project-tracker mutation endpoints always include `AND user_id = ?` in their WHERE clauses — users cannot modify each other's rows even if they know the ID. The `?next=` redirect parameter in `login.php` is validated to start with `BASE_PATH . "/"` to prevent open redirects.

### Study counter

`study-counter.php` (auth-guarded) is the flagship feature: users log study time two ways — the live, server-backed **"I'm studying"** session in the dock (see *Persistent timer + presence* below) or a **manual** hours/minutes + date entry. The bottom-left **"Session Management"** card is sized to its content and holds two buttons, each opening an opaque pop-up (`.modal-solid`): **"Manual logging"** (`#manual-toggle` -> `#manual-modal`) and **"Manage previous sessions"** (`#manage-toggle` -> `#manage-modal`). The manage modal lists **my own** sessions a week at a time (`get-my-study-sessions.php`, client groups by study-day, week nav with "next" disabled at the current week) with an **x** per row to delete (`delete-study-session.php`); deletes refresh the chart/podiums and the dock recap. The **module list** is the distinct exam titles from `exams` (defaults) plus rows in `study_modules` (custom, flagged with a "custom" badge, deletable via `delete-study-module.php`). Sessions go to `study_sessions` with `module_name` as a plain string (decoupled from both tables) and a `studied_on` DATE.

- `api/log-study-session.php` validates duration (1-86400s) and date (valid `Y-m-d`, not future), resolves the module against the allowed set, and — when `new_module` is a genuinely new name — inserts it into `study_modules` first.
- `api/get-study-data.php` returns the module list and sessions aggregated per user/module/day. All chart/podium aggregation is client-side.
- The chart is a hand-built HTML/CSS grouped bar chart (no Chart.js): one group of bars per weekday, one **solid user-coloured** bar per user (height = that day's total). The per-module split is shown in the hover tooltip (module-coloured swatches via `moduleColor`) and the Per-module podium, not stacked into the bar. Week navigation is pure client-side (`weekOffset`); "next" is disabled at the current week.
- Above the chart, a podium of total hours studied showing the **top 4** (`#overall-podium`): each card shows the user's **profile picture** (`USER_AVATARS`, falling back to their initial) instead of a number, and a gold/silver/bronze/chocolate medal disc (4th = chocolate, a smaller "honourable mention" slot).
- The `.podium-controls` are two vertical segmented controls (`.seg-control` -> `.seg-btn`): **Period** (`data-period`: Overall / This week / Today) and **View** (`data-view`: `all` = combined podium, `module` = per-module mini-podiums, `library` = combined podium restricted to `at_library=1` sessions). Neither affects the chart.

#### Persistent timer + presence

The study timer is **server-backed** so it survives closing/reloading the page. All state lives in one row per user in `study_status` (helper `includes/study-status.php` builds the JSON payload). A row is a running stopwatch when `started_at IS NOT NULL` and paused ("on break") when it is `NULL`; `elapsed = accumulated + (started_at ? NOW() - started_at : 0)`. The single live flow is driven entirely from the dock's `.studying-actions` plus a **"Current module"** display (`#my-session`). Button labels are exact: **I'm studying / Stop session**, **Change module**, **Take a break / Resume**, **At the library / Leave library**:
- **"I'm studying"** opens the module modal **first**; confirming starts a `mode='presence'` row stamped with that module. (`mode='timer'` and the old `start` action still exist for legacy rows but are no longer triggered by the UI.)
- **Change module** (`set_module`) switches the running session's module via the same modal. Each `study_segments` interval is stamped with the module active when it opened: the **first** pick stamps the open interval in place, a **switch** closes the current interval and opens a fresh one — so the session splits into **per-module sub-sessions** that all show separately in the recap.
- **Stop session** opens the **log modal** built from `me.parts` (per-module live breakdown): one row per module with its summed time, a **-** button that trims 5 min (floor 1 min), and an **x** that drops that module's session (toggle, shows restore). **Save** sends `sessions` to `log`, which writes one `study_sessions` row per kept module, trimming intervals from the end. **Discard all** = `stop`.
- `pause`/`resume` operate on the single row. `api/study-timer.php` is the only endpoint that mutates `study_status`.
- **BIB** (`at_library` flag) is an **independent** location tag — on regardless of running/break state, and survives pause/resume. The `library` action toggles it; the SQL sets `updated_at = updated_at` so toggling during a break does not reset the break-elapsed counter. At-library chips render in a separate sub-container in the dock (break box and library box are mutually exclusive client-side, library takes precedence). On `log`, `study_sessions.at_library` is stamped from the status flag so the "Library only" podium filter can replay history.
- A row existing at all means the user is "currently studying". The presence UI is a **sticky, Anki-style flip card** in its own sidebar column (`.study-layout` grid; `.studying-dock` is `position: sticky`): the **front** splits people into **running** chips and an **"On break"** sub-container; the **back** is a day recap — a per-user timeline of today's logged sessions, one block per `study_segments` interval (so a break shows as a real gap), positioned on a fixed **00:00-24:00** axis. Timeline blocks are coloured by **module**. It polls `get-study-status.php` every 15s, on `visibilitychange`, and on bfcache `pageshow`. `break_elapsed` is derived server-side from `updated_at`.
- **Initial paint is server-rendered.** `study-counter.php` embeds the status payload (`studyStatusPayload`) and aggregated sessions as `INITIAL_STATUS` / `INITIAL_SESSIONS`, and the JS paints synchronously from them on load — no fetch round-trip, no "Loading..." flash. `get-study-data.php`/`get-study-status.php` are only used for background refresh after that.

### Boardle

`boardle.php` (auth-guarded) is the other flagship feature: a daily, multi-board, multiplayer Wordle. All logic lives in `includes/boardle.php`; the page server-renders the initial state and then polls. The word lists are **bundled text files** in `includes/boardle/` (not the DB): `dict-<5..10>.txt` (full SOWPODS, for guess validation) and `answers-<5..10>.txt` (common words intersected with SOWPODS, for random answers + suggestions). `boardleIsValidWord()` lazily loads + caches the one length it needs.

- **One puzzle per day** (server date). The day's **length L** is rolled once with weights 5->50% / 6->25% / 7->15% / 8->6% / 9->3% / 10->1% (`boardleRollLength`) and stored on `boardle_days`. **N boards** (1-4), all length L, are solved Quordle-style: one guess scored against every board.
- **Variable-length guesses.** A guess is any valid word of length 5..L, **positioned** within the L-wide row by typing leading spaces (an on-screen space bar; the position is the offset of the contiguous letter block). It's stored in `boardle_guesses.guess` as an L-char string with `_` for blank cells (e.g. `__HELLO___`), encoding word + offset together. Blank cells score as `empty`; `boardleScore()` does standard greens-first multiplicity over filled cells. A board is only **solved** by the full L-length answer at offset 0. Base guess limit `boardleMaxGuesses($numBoards, $length)` = `5 + numBoards` -> 9 for the fixed 4 boards. The armor joker adds +1 **per player**, so `max_guesses` is personal.
- **Keyboard feedback is per-board.** Because one guess hits every board, the on-screen keyboard tints letters for a *single* board at a time; a vertical `1-2-3-4` switcher (`kbStatesFor`/`renderKeyboard`) chooses which board's colours are shown — merges both guesses and that board's hint.
- **Board numbering is fixed per player** (`boardlePlayerRank`: roman->ben->basti->lorenz): `boardleFinalizeWords` orders the chosen-word slots by that rank, not solve time, so boards are stable.
- **Jokers** (`boardle_hints`, `api/boardle-hint.php`): 3 per day, one of each type, each costing 1 streak by default. A player with 0 streak gets one **free** joker/day (their first). A joker can instead be bought by exchanging a **streak freeze** (`pay=freeze`, once/day) — also the automatic fallback when out of streak — recorded as `via_freeze=1`. Types: **armor** (+1 guess overall, board-less), **orange** (+2 present letters on a board), **green** (+1 correctly-placed letter on a board). Board jokers never apply to an owned/solved board and only reveal info not derivable from the player's own guesses; orange/green may stack on the same board (one-per-type via the PK). The UI is a shared joker bar above the boards; armor applies instantly, orange/green are click-then-pick-a-board. A used board joker renders as an extra board row beneath its board and tints the keyboard.
- **Community hints.** Independent of jokers: once a player has solved a board, they can write a free-text clue on it via `api/boardle-add-hint.php`, stored on `boardle_words.hint`. Only the first hint sticks (a board can't be overwritten once it has one), so it's a one-shot community assist for whoever hits that board later, not a per-player joker.
- **Streak freezes** (`boardleStreakInfo`, derived — no table): everyone starts with **3**, earns **`BOARDLE_FREEZE_PER_WEEK` every 7 days of streak**, and a freeze **auto-bridges a missed day** so the streak survives (when the balance allows; otherwise the streak resets). `boardleStreakInfo` replays every day from the first relevant one to today.
- **Board count is fixed at 4**: `boardleFinalizeWords()` runs when a day arrives and sets `numBoards = BOARDLE_MAX_WORDS` (4). Each prior-day solver (ordered by `boardle_results.solved_at`) fills + **owns** one slot with their `boardle_choices` word; remaining slots are random backfill. Finalize is idempotent and race-safe (re-checks `words_finalized` under `SELECT ... FOR UPDATE`).
- **Choosing a word**: the first <=4 solvers (`boardleChoiceEligible`) get a pick for the **next** day — except before anyone has guessed today, in which case a pick targets **today** instead (mirrored by both `boardle-choose.php` and `boardle-suggest.php`). Three stable suggestions come from the answer pool (`boardleRandomSuggestions`/`boardleSuggestions`); a custom word is validated against the dictionary + length; duplicates between players are allowed. `api/boardle-choose.php` `REPLACE`s into `boardle_choices`, so a pick can be changed until that day finalizes.
- **You don't solve your own pick.** A board whose word you chose (`boardle_words.chooser_id` = you) is **auto-solved and revealed** for you — it doesn't count toward your win and renders as a green "your word" row.
- **Live competition**: opponents' boards are sent as **colors only, never letters** (`boardleState` strips `text` from everyone but the requester). Own answers are revealed only once *I* finish. The page polls `boardle-state.php` every 6s, on `visibilitychange`, and on bfcache `pageshow`; a poll never clobbers the in-progress local guess buffer/offset.

### Project tracker

`projects.php` (auth-guarded) is a time tracker where projects are **owned per-user** but **viewable across users**. Each project (`projects` table) has a name, description, and one of eight accent colours; each logged session is a row in `project_time_entries`. Each card shows total contributed time plus today / last-7-days / session-count breakdowns.

- **User switcher.** Calendar-style tabs (`?user=<username>`) let you view any user's projects read-only (`$canEdit = ($viewUserId === $myUserId)` gates the new-project form, per-card timer, edit/delete, and session x buttons in PHP so they're never emitted for someone else's view). The create-project POST always inserts for `$myUserId`.
- **Standings.** Shown only when 2+ users have projects: a top-3 podium where each card links into that user's view.
- Time is logged via a **client-only** live timer (start epoch in `localStorage`, key `project_timer_<BASE_PATH>_<id>`, so it survives reloads) or a manual hours/minutes modal. There is no shared/presence aspect (unlike the study counter's server-backed timer).

### Application tracker (legacy, still functional)

The original feature the repo was built around. Scores are **additive and permanent**: every status change is appended to `application_status_history`; nothing is ever updated or deleted there. `scorePoints(string $status, ?string $tag): int` in `includes/scoring.php` maps a single history row to its point value; scores are recomputed at query time by summing all history rows. The `tag` column on `applications` modifies point values for INTERVIEW and OFFER — the tag at the time the history row is inserted is what matters.

Point values per status change:
- PENDING: +2, REJECTED: -1, GHOSTED: -1
- INTERVIEW: +8 base (-5 Maybe, -3 Probably, +0 For Sure, +5 Absolute Cinema relative to base)
- OFFER: +18 base (same tag modifiers scaled up)

`peakStatusSql()` (also in `scoring.php`) returns a SQL `CASE` expression computing the highest-priority status ever reached (OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED); requires a `LEFT JOIN application_status_history AS h`. Used in the dashboard/leaderboard to show a downgrade indicator (down-arrow when current status is below peak).

#### Chart rendering (score-chart.php)

The chart does not use pre-aggregated scores. `get-score-history.php` returns daily cumulative scores per user (a synthetic zero-point entry is prepended one day before the first event so lines start at 0); `get-raw-events.php` returns raw events for tooltip details; the JS spreads same-day events as fractional sub-points so they don't overlap, landing the last sub-point on the whole-day grid line. All chart JS is inline in `score-chart.php`; `assets/js/app.js` carries only site-wide helpers (mobile nav toggle, `countUp`) and is loaded by `header.php` with the same `filemtime` cache-busting as the CSS.

### Exam feature

`calendar.php` and `api/toggle-user-exam.php` use a separate data model (`exams`, `user_exams` tables) keyed by `username` string rather than `user_id`. Independent from the application-tracking feature; exam titles double as the default module list in the study counter.

### Profile pictures and account settings

`includes/header.php` renders a circular **avatar button** (`#nav-avatar`) next to the logout link, showing `users.avatar` (a base64 image data URL) or the username's initial as a fallback. Clicking it opens an opaque upload modal: the chosen image is **center-cropped to a square and resized to 256px client-side via canvas** (JPEG), then POSTed to `api/upload-avatar.php` (validates the data URL, caps decoded size, confirms it's a real image with `getimagesizefromstring`). The avatar is stored in the DB (not a filesystem upload dir) so it works across all deployments. `study-counter.php` reads every user's avatar into `USER_AVATARS` for the podium. `api/patch-profile.php` lets a user change their username and/or password (requires `current_password`).

### Landing page

`index.php` at the repo root is a standalone marketing page (it does not use `header.php`/`footer.php`). It is reachable logged-out and renders aggregate stats server-side via direct PDO queries wrapped in `try/catch` (dashes on DB failure). Logged-in visitors see dashboard CTAs instead of being redirected. It does not conflict with the server-root `index.php` outside the repo.

## Database schema

See README for the full `CREATE TABLE` statements. Key points:
- `application_status_history` has `ON DELETE CASCADE` from `applications`.
- The `tag` column is on `applications`, not on history rows — score computation joins through `applications` to get the tag when replaying history.
- Score computation: `get-leaderboard.php` fetches all history rows joined to `applications` (for the tag), then calls `scorePoints()` in a PHP loop.
- Status ENUM values: `PENDING`, `INTERVIEW`, `OFFER`, `REJECTED`, `GHOSTED`.
- Tag values: `MAYBE`, `PROBABLY`, `FOR SURE`, `ABSOLUTE CINEMA`.
- `study_segments.module_name` is nullable and stamped per interval (see *Persistent timer + presence* above) — don't assume one module per session.
- `boardle_words.hint` holds the community hint text (nullable) and is distinct from the joker system's `boardle_hints` table.
