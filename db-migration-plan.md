# Database migration plan: Boardle streak/freeze persistence + schema cleanup

Planning document only — no code or SQL in this repo implements any of this
yet. Written against the schema in `README.md` and the logic in
`includes/boardle.php` as of this patch.

## Why

Two separate problems prompted this:

1. **Streaks and freezes are not stored anywhere.** `boardleStreakInfo()`
   (`includes/boardle.php:484`) recomputes a player's current streak and
   freeze balance from scratch on every call — it pulls that player's entire
   `boardle_results` + `boardle_hints` history and replays it day by day from
   the first day they ever played up to today. This runs on every
   `boardle-state.php` poll (every 6s per open tab). It gets more expensive
   every day the app is alive, and because the replay re-derives history
   instead of reading a stored value, there is no way to change the streak/
   freeze *rules* (starting balance, weekly bonus amount, bridging behavior)
   without silently changing everyone's past streaks too — there's no
   snapshot of "what the balance was under the old rules."
2. **A handful of constants that are really game-balance knobs live as PHP
   `const`s** (`BOARDLE_FREEZE_START`, `BOARDLE_FREEZE_PER_WEEK`,
   `BOARDLE_MAX_WORDS`, `BOARDLE_MIN_LEN`/`MAX_LEN`, the RT phase durations in
   `includes/boardle-rt.php`). Tuning any of them today means a code change
   + deploy, even though they're pure data.
3. **`boardle_choices` exists only because board position can't be known at
   pick time** — see Part 0. It's a whole extra table whose sole job is
   holding a pick until the day's solver set is final.
4. **A genuinely dead column and a write-only one**, found while auditing
   for redundancy — see Part 3.

## Explicitly out of scope

- Renaming/restructuring the daily-Boardle vs. Unlimited-mode (`boardle_rt_*`)
  table pairs. They look repetitive (`boardle_words`/`boardle_rt_words`,
  `boardle_guesses`/`boardle_rt_guesses`, etc.) but are keyed differently
  (`game_date` vs `round_id`) and serve genuinely different lifecycles.
  Merging them would add a discriminator column and conditional logic
  everywhere for no real benefit — not worth the risk.
- Normalizing `study_sessions.module_name` / `study_segments.module_name`
  into a foreign key against `study_modules`/`exams`. The README already
  documents this as an intentional decoupling (a session must survive its
  module being renamed/deleted later) — that's a design choice, not
  redundancy.

## Part 0 — Fixed board slots, drop `boardle_choices`

**The problem today:** `boardleFinalizeWords()` (`includes/boardle.php:173`)
assigns board positions by taking yesterday's solvers, sorting them by a
fixed rank (`boardlePlayerRank`: roman → ben → basti → lorenz), and handing
out positions `0, 1, 2...` **compacted** over just that subset — if only ben
and lorenz solved, ben gets 0 and lorenz gets 1 (not 1 and 3). That means a
player's board slot depends on *who else* solves that same day, which isn't
final until the day ends. A mid-day pick has nowhere to land yet — its
row doesn't exist and its position isn't decided — so `boardle_choices`
exists purely as a staging area keyed by `(game_date, user_id)` (no
position) until `boardleFinalizeWords()` resolves everyone's slot.
Confirmation this is the actual cause, not just incidental: Unlimited mode
has no equivalent staging table at all — `api/boardle-rt-choose.php` writes
straight into `boardle_rt_words`, because RT assigns positions upfront via
`boardle_rt_players` when the round starts. Give daily Boardle the same
precondition (positions fixed in advance) and the staging table stops being
necessary.

**The change:** each of the (currently four) named players permanently owns
one board index — `position = boardlePlayerRank(username)` — whether or not
they solved yesterday. A slot with no real pick just gets a random word at
finalize time, same as today, it just no longer shifts other players' slots
around. This is a **gameplay rule change**, not a pure schema tidy-up: board
numbering becomes stable per-person ("my board is always #2") instead of
compacted based on that day's solver set.

Once adopted:
- `api/boardle-choose.php` upserts directly into `boardle_words` at the
  chooser's fixed position (`INSERT ... ON DUPLICATE KEY UPDATE word = ...,
  hint = ..., source = 'chosen'`), mutable until `words_finalized` flips —
  exactly the semantics `boardle_choices` provided, just in the final table.
- `boardleEnsureDay()` pre-creates all 4 rows for a date (`source='random'`
  placeholders) as soon as that date is reachable for picking, instead of
  `boardleFinalizeWords()` inserting them all at once at day-boundary time.
- `boardleFinalizeWords()` shrinks to: fill any position still `source =
  'random'` with a real random answer (skip if a pick already landed there),
  then flip `words_finalized = 1`. No more reading `boardle_choices` at all.
- `boardleSyncLateChoices()` (`includes/boardle.php:251`) is deleted outright
  — it exists only to patch late `boardle_choices` picks into an already-
  finalized `boardle_words`; with direct writes there's nothing to sync.
- `DROP TABLE boardle_choices` after a one-time backfill: any row in
  `boardle_choices` for a not-yet-finalized date gets upserted into
  `boardle_words` at `boardlePlayerRank(username)` before the table is
  dropped, so no in-flight pick is lost mid-migration.

**Caveat:** this hard-codes the assumption that the game has exactly the
four named players it does today (`boardlePlayerRank` already hard-codes
this, so it's not a new constraint — just one this change now depends on
more directly). If a 5th player is ever added, `BOARDLE_MAX_WORDS` and the
rank mapping both need to grow together, same as they would today.

## Part 1 — Persist streak + freeze as real rows

Add one row per player that holds the *current* streak/freeze balance,
updated incrementally instead of replayed:

```sql
CREATE TABLE boardle_streaks (
    user_id           INT NOT NULL PRIMARY KEY REFERENCES users(id),
    streak            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    freeze_balance    SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    last_settled_date DATE NULL,   -- last game_date this row's numbers reflect
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**How it gets updated:** the same day-by-day rule logic that
`boardleStreakInfo()` runs today moves into a `boardleSettleStreak($pdo,
$userId, $throughDate)` function, but it only replays the gap between
`last_settled_date` and `$throughDate` (almost always zero or one day),
not the player's whole history. It's called:
- once per day per player, lazily, the same way `boardleFinalizeWords()`
  lazily finalizes a day — the first `boardle-state.php`/`boardle-guess.php`
  call for a player on a new `game_date` settles yesterday and stamps
  `last_settled_date`.
- immediately after a joker purchase (`api/boardle-hint.php`), so
  `via_freeze` spends apply to the live balance instead of waiting for the
  next day's replay.

**Backfill migration (one-time, run once before deploying the new code):**
replay `boardleStreakInfo()`'s existing algorithm for every user who has ever
solved a board, over their full history exactly once, and insert the
resulting `(streak, freeze_balance, last_settled_date = today)` row. After
that, only the incremental settle path runs.

**Why this is safe to change gradually:** `boardleStreakInfo()`'s return
shape (`effective`, `freezes`, `freeze_used_today`) stays the same — callers
in `includes/boardle.php` don't need to change, only its internals swap from
"replay everything" to "read the row, settle the small gap if any."

## Part 2 — Move tunable rules into a config table

```sql
CREATE TABLE boardle_config (
    `key`        VARCHAR(64) NOT NULL PRIMARY KEY,
    value        VARCHAR(255) NOT NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO boardle_config (`key`, value) VALUES
    ('freeze_start',      '3'),
    ('freeze_per_week',   '3'),
    ('max_boards',        '4'),
    ('min_word_length',   '5'),
    ('max_word_length',   '10'),
    ('rt_vote_seconds',   '15'),
    ('rt_select_seconds', '120'),
    ('rt_play_seconds',   '900');
```

Read once per request (or cached in a static in `includes/boardle.php` for
the request's lifetime) instead of `const`s. This is the piece that actually
"allows change of rules easier" per the patch note — a rule tweak becomes an
`UPDATE boardle_config`, no deploy. Length-weighting
(`boardleRollLength`'s 50/25/15/6/3/1 split) is deliberately **not** moved
here — it's a distribution, not a scalar, and would need a different
shape (e.g. `boardle_length_weights(length, weight)`); only worth doing if a
real need to tune it shows up.

## Part 3 — Minor cleanup while touching this area

- **`application_status_history.score_delta` is a dead column.** It's
  defined in the schema (`README.md:66`), but neither place that inserts a
  history row (`dashboard.php:41`, `api/patch-application.php:82`) ever
  writes it, and every score computation (`get-leaderboard.php`,
  `get-raw-events.php`) calls `scorePoints()` live from `status`+`tag`
  instead of reading it. It's always 0 and nothing reads it — safe to drop.
- **`study_modules.created_by` is write-only.** Set on insert
  (`api/log-study-session.php:100`, `api/study-timer.php:30`) but never
  selected anywhere. Not harmful, just collected and unused — worth dropping
  or, if there's a future use in mind (e.g. "added by" attribution in the
  module list UI), keep it and actually surface it instead of silently
  dropping.
- `boardle_days.words_finalized` and the actual presence of rows in
  `boardle_words` for that date are two ways of asking "has this day been
  finalized" — `boardleFinalizeWords()` already treats the flag as the
  source of truth and uses `SELECT ... FOR UPDATE` on it for the race guard,
  so this isn't broken, just worth a comment noting the flag (not row
  existence) is authoritative, so a future reader doesn't add a second
  finalization path keyed off row presence.
- Add an index recommendation: `boardle_results(user_id, solved, game_date)`
  — once streak settling reads only a small trailing window instead of full
  history, this index turns that query from a user-scoped scan into a
  narrow range lookup.

## Rollout order

1. Drop the dead/write-only columns (Part 3's `score_delta` and
   `created_by`) — zero behavior change, immediate.
2. Ship `boardle_config` + read path (pure addition, zero behavior change if
   seeded with today's constant values) — lowest risk, validates the
   config-read plumbing.
3. Ship the fixed-slot rule change + direct `boardle_words` writes (Part 0),
   backfill any in-flight `boardle_choices` rows, then `DROP TABLE
   boardle_choices`. Do this on its own, separately from Part 1 below, since
   it's a visible gameplay change (board numbering behavior) and worth being
   able to roll back independently.
4. Ship `boardle_streaks` + backfill migration + incremental settle, behind
   the unchanged `boardleStreakInfo()` return shape.
5. Only after all of the above are live and stable, consider the
   `boardle_length_weights` split if a concrete need to tune length odds
   comes up.

## Risks / things to double-check before implementing

- The backfill migration must run **once**, after a deploy freeze, since two
  processes racing to backfill the same user would double-apply weekly
  bonuses. Wrap it in a single transaction with `INSERT ... SELECT` guarded
  by `NOT EXISTS`, or run it as a one-off script the night of deploy.
- `boardleStreakInfo()` currently treats `solved_at IS NULL` rows (pre-
  migration data) as "on time" — the backfill must replicate that exact
  leniency or old streaks will shift.
- Incremental settling must still handle a player who hasn't opened Boardle
  in N days: the lazy settle needs to walk `last_settled_date + 1` through
  `today - 1` (not just "yesterday"), same as the current replay's loop —
  just bounded to a normally-small N instead of "since they started playing."
