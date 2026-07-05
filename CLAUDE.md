@AGENTS.md

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Style rules

- Never use emojis in code, comments, or any output — this project is emoji-free.

## What this is

A shared leaderboard for tracking internship applications, plus a study counter, project time tracker, exam calendar, and a daily multiplayer Wordle (Boardle). Originally a PHP/MySQL app; fully rewritten as this TypeScript/Next.js app. See [`plan.md`](plan.md) for the phase-by-phase migration plan, stack choice, and per-phase deviation notes — useful if you need to understand *why* something works the way it does, or what the original PHP behavior was for a feature.

**Deployment status:** not yet deployed. Vercel + Hostinger-remote-MySQL wiring is still pending (see `plan.md` Phase 1.5) — there is currently no live production instance.

[`README.md`](README.md) holds the **authoritative MySQL schema** (`CREATE TABLE` statements + scoring table) — `prisma/schema.prisma` is introspected from it and the two must be kept in sync manually.

## Running locally

1. `npm install`
2. Local MySQL: `docker compose up -d` (starts `db` on `localhost:3306` + phpMyAdmin on `localhost:8081`; see `docker-compose.yml`) — or point at any MySQL 8 instance.
3. Copy `.env.example` to `.env` and fill in `DATABASE_URL`, `AUTH_SECRET` (`npx auth secret`), `INVITE_CODE`.
4. Apply the schema from `README.md`'s "Database schema" section to that database (it's the authoritative source; `prisma/schema.prisma` is introspected from it — keep both in sync when either changes).
5. `npx prisma generate`
6. `npm run dev`

## Architecture

### Request flow

- `app/(app)/layout.tsx` is the auth guard for every page under it: calls `auth()` (Auth.js), redirects to `/login` if there's no session, then renders `Header`/`Footer` around `children`. Pages under `app/(app)/` are Server Components that fetch their own data via Prisma.
- `app/(auth)/login` and `app/(auth)/register` are unguarded.
- `app/api/*/route.ts` handlers check `auth()`/`session.user.id` themselves and return HTTP error statuses on failure.
- `app/page.tsx` is a plain redirect (logged in → `/study`, logged out → `/login`) — not a marketing page (see `plan.md` Phase 7 notes on why).
- `app/(app)/leaderboard/page.tsx` renders `LeaderboardTable` + `ScoreChart`.

### Scoring model

Scores are **additive and permanent**. Every status change is appended to `application_status_history`; nothing is ever updated or deleted there. `scorePoints(status, tag)` in `lib/scoring.ts` maps a single history row to its point value. Scores are recomputed at query time by summing all history rows. The `tag` column on `applications` modifies point values for INTERVIEW and OFFER statuses — the tag at the time the history row is inserted is what matters.

Point values per status change:
- PENDING: +2, REJECTED: −1, GHOSTED: −1
- INTERVIEW: +8 base (−5 Maybe, −3 Probably, +0 For Sure, +5 Absolute Cinema relative to base)
- OFFER: +18 base (same tag modifiers scaled up)

`lib/leaderboard.ts` computes the "peak status" per application (highest-priority status ever reached: OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED) over the fetched history array in TypeScript. A ↓ indicator means the current status is lower than the peak.

### API routes

All return JSON. Auth failures return 401; ownership failures return 404 (Prisma `findFirst({ where: { id, userId } })` returning null).

| Route | Method | Purpose |
|---|---|---|
| `api/auth/[...nextauth]` | — | Auth.js Credentials provider (login/session) |
| `api/auth/register` | POST | Create account, validates `INVITE_CODE`, hashes with bcryptjs |
| `api/applications` | GET, POST | List / create applications for the logged-in user |
| `api/applications/[id]` | PATCH, DELETE | Update (`status`/`tag`/`notes`/`job_link`, writes history on status change) / delete an owned application |
| `api/leaderboard` | GET | Full leaderboard incl. per-user applications |
| `api/score-history` | GET | Daily cumulative scores per user (chart line) |
| `api/raw-events` | GET | Raw history rows with score deltas (chart tooltips) |
| `api/avatar` | POST, DELETE | Set / clear the logged-in user's `users.avatar` |
| `api/exams/toggle` | POST | Toggle exam selection for the calendar (by username, not user_id) |
| `api/study/status` | GET | Current study presence: my timer state, everyone studying, today's recap |
| `api/study/timer` | POST | State machine for `study_status` (`action` = presence/pause/resume/set_module/reset/stop/log/library) |
| `api/study/data` | GET | Module list + aggregated sessions |
| `api/study/sessions` | GET, POST | My logged sessions / manual session logging |
| `api/study/sessions/[id]` | DELETE | Delete one of my `study_sessions` rows |
| `api/study/my-sessions` | GET | My sessions with ids for the "manage previous sessions" window |
| `api/study/delete-module` | POST | Delete a custom study module (undocumented in the PHP original, needed by the UI) |
| `api/projects` | GET, POST | List / create projects |
| `api/projects/[id]` | PATCH, DELETE | Edit (name/description/color) / delete an owned project |
| `api/time-entries` | POST | Log time against an owned project |
| `api/time-entries/[id]` | DELETE | Delete one owned time entry |
| `api/boardle/state` | GET | Full Boardle state for today (my boards, opponents' colors-only boards, stats) |
| `api/boardle/guess` | POST | Submit a guess (word + offset), scores all boards |
| `api/boardle/choose` | POST | Set one of tomorrow's words (solvers), or today's while nobody has guessed yet (anyone) |
| `api/boardle/hint` | POST | Use a joker (armor/orange/green), optionally paid with a streak freeze |
| `api/boardle/suggest` | GET | Fresh random word suggestions (the picker's randomize button) |
| `api/boardle/add-hint` | POST | Write a community clue onto a solved, still-hintless board |

### Ownership checks

`api/applications/[id]`, `api/projects/[id]`, `api/study/sessions/[id]`, `api/time-entries/[id]` all scope their Prisma query by `userId: session.user.id` — users cannot modify each other's rows even if they know the id.

### Timestamps

`lib/dates.ts` (`dbNow()`, `toDbDateStr()`) encodes Europe/Berlin wall-clock time as if it were UTC, matching how `@prisma/adapter-mariadb` round-trips raw DB timestamp digits (no real timezone conversion happens). Every write to `study_status`, `study_sessions`, `study_segments`, `applications`, `application_status_history`, and `users.created_at` must go through `dbNow()` explicitly — relying on MySQL's own `NOW()`/`ON UPDATE CURRENT_TIMESTAMP` runs in the DB session timezone (UTC), not Europe/Berlin, and previously caused real 2h offsets (see `plan.md` Phase 4 notes).

### Chart rendering

`components/leaderboard/ScoreChart.tsx` uses Chart.js directly. It combines `api/score-history` (daily cumulative per user, with a synthetic zero-point one day before the first event) and `api/raw-events` (tooltip detail), spreading same-day events as fractional x-axis sub-points so they don't overlap.

### Exam feature

`app/(app)/calendar/page.tsx` + `api/exams/toggle` use a separate data model (`exams`, `user_exams`) keyed by `username` string rather than `user_id` — independent from application tracking. `lib/calendar.ts` uses a fixed `["Roman", "Basti", "Ben", "Lorenz"]` list rather than the `users` table (Projects, by contrast, uses real user records).

### Profile pictures

`components/layout/AvatarButton.tsx` + `Header.tsx` render the circular avatar / upload modal; the chosen image is center-cropped and resized to 256px client-side via canvas, then POSTed as a base64 data URL to `api/avatar`. Stored in `users.avatar` (DB, not filesystem) so it's portable across environments. `study`'s podium reads every user's avatar the same way.

### Study counter

`app/(app)/study/page.tsx` (Server Component) embeds initial status + aggregated sessions (`INITIAL_STATUS`/`INITIAL_SESSIONS`) so `StudyCounterApp.tsx` paints synchronously on load — no fetch round-trip, no loading flash. `api/study/data` and `api/study/status` are only used for background refresh after that (`setInterval(15s)` + `visibilitychange` + `pageshow`, no SWR — the state is one tightly-coupled blob, see `plan.md` Phase 4 notes).

Key components: `StudyCounterApp.tsx` (orchestrator), `Dock.tsx` + `Timeline.tsx` (the sticky flip-card presence panel — front: running/on-break chips; back: today's per-module timeline), `Chart.tsx` (hand-built grouped bar chart, per-user solid bars, per-module split shown only in tooltip + `Podium.tsx`), `ModulePicker.tsx`/`ModuleModal.tsx`, `StopModal.tsx` (log modal built from the live per-module breakdown, trim/drop per module), `ManualLogModal.tsx`, `ManageSessionsModal.tsx`, `FocusMode.tsx` (fullscreen timer, not in the original PHP plan — added on user request).

The timer is server-backed (`study_status` table, one row per user) via the single state machine in `api/study/timer`. `lib/study-status.ts`, `lib/study-segments.ts`, `lib/study-modules.ts`, `lib/study-sessions.ts` split the logic across separate files rather than one large module.

### Project tracker

`app/(app)/projects/page.tsx` — projects owned per-user, viewable across users via a calendar-style tab switcher (`?user=<username>`). `$canEdit` gating happens server-side (controls aren't rendered at all when viewing someone else, not just hidden). The timer is client-only (`localStorage`, key includes project id), unlike the study counter's server-backed one. `Standings.tsx` shows a top-3 podium when 2+ users have projects. `lib/project-colors.ts` holds the single accent-color palette used by both creation and validation.

### Boardle

`app/(app)/boardle/page.tsx` server-renders initial state, then `BoardleApp.tsx` polls `api/boardle/state` every 6s + `visibilitychange` + `pageshow`. Logic lives in `lib/boardle.ts` (orchestrator) split across `lib/boardle-words.ts`, `lib/boardle-score.ts`, `lib/boardle-streak.ts`, `lib/boardle-dates.ts`. Word lists are bundled text files in `includes/boardle/` (`dict-<5..10>.txt` for validation, `answers-<5..10>.txt` for the answer pool), read via `fs.readFileSync` relative to `process.cwd()`.

One puzzle per day, N boards (fixed at 4) all of the same length L, solved Quordle-style. `Board.tsx` renders the grid, `Keyboard.tsx` the on-screen keyboard with a per-board color switcher (`kbStatesFor` in `BoardleApp.tsx`), `JokerBar.tsx` the joker buttons + streak/freeze wallets, `OpponentsPanel.tsx`/`StatsPanel.tsx` the sidebar (Standings: one row per player, podium tint for the top 3). `ChooseWord.tsx` is the tomorrow's-word picker for solvers — it also pops up as an overlay right after a full solve (once per solve, tracked in `localStorage`) and immediately on load for the anyone-may-pick-today case (no guesses made yet). Solved-but-hintless boards show a "+ Add a hint for others" card that posts to `api/boardle/add-hint`. Behavioral details (guess encoding as an L-char string with `_` blanks, joker types/costs, streak-freeze bridging, board ownership/auto-solve for your own pick, opponents only ever seeing colors) are unchanged from the PHP original — see `plan.md` Phase 6 notes for anything intentionally simplified (word-picker hint cards and joker-reveal rows render in natural order rather than pixel-pinned under their board column).

## Database schema

See [`README.md`](README.md) for the full `CREATE TABLE` statements — that file is the authoritative schema source; `prisma/schema.prisma` is introspected from it and must be kept in sync manually when either changes. Key points:
- `application_status_history` has `ON DELETE CASCADE` from `applications`.
- The `tag` column is on `applications`, not on history rows — score computation joins through `applications` to get the tag when replaying history.
- Status ENUM values: `PENDING`, `INTERVIEW`, `OFFER`, `REJECTED`, `GHOSTED`.
- Tag values: `MAYBE`, `PROBABLY`, `FOR SURE`, `ABSOLUTE CINEMA`.
