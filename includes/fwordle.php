<?php

// Shared helpers for fWordle — a daily, multi-board, multiplayer Wordle.
//
// Each calendar day has ONE puzzle: N boards (1–4), all the SAME length L
// (rolled per day, weighted toward shorter words). One guess is scored against
// every board at once (Quordle-style); the catch is that a guess may be any
// valid word of length 5..L, positioned within the L-wide row — the blank cells
// are neutral. A board is only *solved* by guessing the full L-length answer.
//
// N (the number of boards) = how many users solved the PREVIOUS day, capped at
// 4; if nobody solved, 4 random words are used. The first ≤4 solvers each earn
// the right to pick one of the next day's words (suggested or custom); unfilled
// slots are backfilled at random.
//
// Word data lives in includes/fwordle/ as plain text (bundled, not in the DB):
//   dict-<len>.txt    — SOWPODS Scrabble words, the *guess validation* list
//   answers-<len>.txt — common words ∩ SOWPODS, the *answer / suggestion* pool

const FWORDLE_MAX_WORDS = 4;
const FWORDLE_MIN_LEN   = 5;
const FWORDLE_MAX_LEN   = 10;

// Streak freezes: everyone starts with 3; a missed day is auto-bridged by a
// freeze (so the streak survives) when one is available; +2 are earned for every
// 7 days of streak. A freeze can also be exchanged for a joker once per day.
const FWORDLE_FREEZE_START    = 3;
const FWORDLE_FREEZE_PER_WEEK = 2;

// Shared guesses for a day: 6 for 1 board, +1 per extra board → 6/7/8/9 for
// 1–4 boards. The 4-board default is 9, like a cat's nine lives: each globally
// lost board (a failed player) costs a life the next day. Length-independent.
function fwordleMaxGuesses(int $numBoards, int $length): int
{
    return 5 + $numBoards;
}

// ── Word lists ─────────────────────────────────────────────────────────────

function fwordleDictPath(int $len): string    { return __DIR__ . "/fwordle/dict-$len.txt"; }
function fwordleAnswersPath(int $len): string { return __DIR__ . "/fwordle/answers-$len.txt"; }

// Validate a guess word against the Scrabble dictionary for its length.
function fwordleIsValidWord(string $word, int $len): bool
{
    $word = strtolower($word);
    if ($len < FWORDLE_MIN_LEN || $len > FWORDLE_MAX_LEN) return false;
    if (strlen($word) !== $len || !preg_match('/^[a-z]+$/', $word)) return false;

    static $cache = [];
    if (!isset($cache[$len])) {
        $path = fwordleDictPath($len);
        $cache[$len] = is_file($path)
            ? array_flip(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))
            : [];
    }
    return isset($cache[$len][$word]);
}

function fwordleAnswerPool(int $len): array
{
    static $cache = [];
    if (!isset($cache[$len])) {
        $path = fwordleAnswersPath($len);
        $cache[$len] = is_file($path)
            ? array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))))
            : [];
    }
    return $cache[$len];
}

// A random answer of the given length, avoiding the supplied words.
function fwordleRandomAnswer(int $len, array $exclude = []): ?string
{
    $pool = fwordleAnswerPool($len);
    if (!$pool) return null;
    $exclude = array_map('strtolower', $exclude);
    $shuffled = $pool;
    shuffle($shuffled);
    foreach ($shuffled as $w) {
        if (!in_array($w, $exclude, true)) return $w;
    }
    return $shuffled[0];
}

// Three stable per-user suggestions of the given length (don't reshuffle on
// every poll — ordered deterministically by a per-user/day hash).
function fwordleSuggestions(int $len, int $userId, string $forDate, int $count = 3): array
{
    $pool = array_values(array_unique(fwordleAnswerPool($len)));
    if (!$pool) return [];
    $seed = $userId . '|' . $forDate;
    usort($pool, fn($a, $b) => strcmp(md5($a . $seed), md5($b . $seed)));
    return array_slice($pool, 0, $count);
}

// ── Day length + lifecycle ───────────────────────────────────────────────────

// Weighted random length: 5→30%, 6→30%, 7→20%, 8→10%, 9→5%, 10→5%.
function fwordleRollLength(): int
{
    $weights = [5 => 30, 6 => 30, 7 => 20, 8 => 10, 9 => 5, 10 => 5];
    $r = mt_rand(1, 100);
    $cum = 0;
    foreach ($weights as $len => $w) {
        $cum += $w;
        if ($r <= $cum) return $len;
    }
    return FWORDLE_MIN_LEN;
}

// Fetch the day row, creating it (with a freshly rolled length) if absent.
function fwordleEnsureDay(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare("SELECT game_date, word_length, words_finalized FROM fwordle_days WHERE game_date = ?");
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $pdo->prepare("INSERT IGNORE INTO fwordle_days (game_date, word_length, words_finalized) VALUES (?, ?, 0)")
        ->execute([$date, fwordleRollLength()]);

    $stmt->execute([$date]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Build (once) the answer words for a date that has arrived. The board count
// starts at 4 and drops by one for every player who PLAYED AND FAILED the
// previous day (floored at 1); players who didn't play don't count. Each
// previous-day solver fills a slot with their chosen word (else random
// backfill) and owns it; any remaining slots are random. Idempotent + race-safe.
function fwordleFinalizeWords(PDO $pdo, string $date): void
{
    $day = fwordleEnsureDay($pdo, $date);
    if ((int) $day['words_finalized'] === 1) return;
    if ($date > date('Y-m-d')) return; // future days keep collecting choices

    $len  = (int) $day['word_length'];
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));

    // Always a fixed FWORDLE_MAX_WORDS boards — failing to solve carries enough
    // intrinsic penalty (no word pick, lost streak) without also shrinking the grid.
    $numBoards = FWORDLE_MAX_WORDS;

    // Solvers each get a slot to fill + own. Boards are ordered by a FIXED player
    // rank (roman/ben/basti/lorenz), not solve time, so the numbering is stable.
    $solverStmt = $pdo->prepare("
        SELECT r.user_id, u.username FROM fwordle_results r
        JOIN users u ON u.id = r.user_id
        WHERE r.game_date = ? AND r.solved = 1
        ORDER BY r.solved_at ASC
        LIMIT " . FWORDLE_MAX_WORDS
    );
    $solverStmt->execute([$prev]);
    $solverRows = $solverStmt->fetchAll(PDO::FETCH_ASSOC);
    usort($solverRows, fn($a, $b) => fwordlePlayerRank($a['username']) <=> fwordlePlayerRank($b['username']));
    $slotUsers = array_map(fn($r) => (int) $r['user_id'], $solverRows);

    $words = []; // [word, source, chooser_id]
    $used  = [];
    $choiceStmt = $pdo->prepare("SELECT word FROM fwordle_choices WHERE game_date = ? AND user_id = ?");

    for ($pos = 0; $pos < $numBoards; $pos++) {
        $uid = $slotUsers[$pos] ?? null;
        if ($uid !== null) {
            $choiceStmt->execute([$date, $uid]);
            $chosen = $choiceStmt->fetchColumn();
            $chosen = $chosen !== false ? strtolower($chosen) : null;
            // Keep a valid chosen word as-is — duplicates between players are
            // allowed (two boards may share a word); only random backfill dedupes.
            if ($chosen !== null && fwordleIsValidWord($chosen, $len)) {
                $used[] = $chosen;
                $words[] = [$chosen, 'chosen', $uid];
                continue;
            }
        }
        $w = fwordleRandomAnswer($len, $used);
        if ($w === null) continue;
        $used[] = $w;
        $words[] = [$w, 'random', null];
    }

    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare("SELECT words_finalized FROM fwordle_days WHERE game_date = ? FOR UPDATE");
        $chk->execute([$date]);
        if ((int) $chk->fetchColumn() === 1) { $pdo->commit(); return; }

        $ins = $pdo->prepare("
            INSERT INTO fwordle_words (game_date, position, word, source, chooser_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($words as $pos => [$w, $src, $cid]) {
            $ins->execute([$date, $pos, $w, $src, $cid]);
        }
        $pdo->prepare("UPDATE fwordle_days SET words_finalized = 1 WHERE game_date = ?")->execute([$date]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

// ── Scoring ──────────────────────────────────────────────────────────────────

// Score a guess string (L chars, '_' = blank/unused cell) against an answer.
// Returns L entries of 'green' | 'orange' | 'grey' | 'empty', with standard
// Wordle letter-multiplicity handling (greens claimed first).
function fwordleScore(string $answer, string $guess): array
{
    $answer = strtolower($answer);
    $guess  = strtolower($guess);
    $L = strlen($answer);
    $res = array_fill(0, $L, 'empty');

    $remaining = [];
    for ($i = 0; $i < $L; $i++) {
        $g = $guess[$i] ?? '_';
        if ($g !== '_' && $g === $answer[$i]) {
            $res[$i] = 'green';
        } else {
            $remaining[$answer[$i]] = ($remaining[$answer[$i]] ?? 0) + 1;
        }
    }
    for ($i = 0; $i < $L; $i++) {
        $g = $guess[$i] ?? '_';
        if ($g === '_' || $res[$i] === 'green') continue;
        if (!empty($remaining[$g])) {
            $res[$i] = 'orange';
            $remaining[$g]--;
        } else {
            $res[$i] = 'grey';
        }
    }
    return $res;
}

// Replay a user's guesses across all boards. `$owned` are board positions the
// user picked the word for — those count as solved without guessing (they
// already know the word), so they don't block the win.
function fwordleUserBoards(array $guesses, array $answers, array $owned = []): array
{
    $num = count($answers);
    $solvedBoards = array_fill(0, $num, false);
    foreach ($owned as $p) {
        if ($p >= 0 && $p < $num) $solvedBoards[$p] = true;
    }
    $rows = [];
    foreach ($guesses as $g) {
        $boards = [];
        foreach ($answers as $pos => $ans) {
            $boards[$pos] = fwordleScore($ans, $g);
            if ($g === strtolower($ans)) $solvedBoards[$pos] = true;
        }
        $rows[] = ['text' => $g, 'boards' => $boards];
    }
    $solved = $num > 0 && !in_array(false, $solvedBoards, true);
    return ['guesses' => $rows, 'solved_boards' => $solvedBoards, 'solved' => $solved];
}

// Fixed board order so the numbering is stable per player (independent of who
// solved first): roman → ben → basti → lorenz, others last.
function fwordlePlayerRank(string $username): int
{
    static $order = ['roman' => 0, 'ben' => 1, 'basti' => 2, 'lorenz' => 3];
    return $order[strtolower($username)] ?? 99;
}

// ── Hints (jokers) ───────────────────────────────────────────────────────────
// 3 jokers per day, one each: ARMOR (🛡️ +1 guess overall, no board), ORANGE
// (+2 present letters on a board) and GREEN (+1 correctly-placed letter on a
// board). Each costs 1 streak, so a player can shed at most 3 streak/day, and
// can only buy one while they still have streak to spend. The board jokers only
// ever reveal NEW info beyond what the player's own guesses already show.

// What a player already knows about one board, derived from their guesses.
function fwordleBoardKnowledge(array $guesses, string $answer): array
{
    $answer = strtolower($answer);
    $len = strlen($answer);
    $present = []; $absent = []; $greenPos = [];
    foreach ($guesses as $g) {
        $colors = fwordleScore($answer, $g);
        for ($i = 0; $i < $len; $i++) {
            $ch = $g[$i] ?? '_';
            if ($ch === '_') continue;
            if ($colors[$i] === 'green')      { $present[$ch] = true; $greenPos[$i] = true; }
            elseif ($colors[$i] === 'orange') { $present[$ch] = true; }
            elseif ($colors[$i] === 'grey' && strpos($answer, $ch) === false) { $absent[$ch] = true; }
        }
    }
    return ['present' => $present, 'absent' => $absent, 'greenPos' => $greenPos];
}

// Compute a board-joker reveal, or null if there's nothing new to reveal.
// ARMOR carries no board reveal (handled by the endpoint). payload encodes one
// or more "letter:position" cells joined by commas:
//   orange → up to 2 cells, each a present letter shown on a spot it ISN'T (so
//            it reads as a true orange cell), e.g. "r:2,t:5"
//   green  → 1 cell, the correct letter at its spot (0-indexed), e.g. "r:3"
function fwordleComputeHint(string $answer, array $guesses, string $type): ?string
{
    $answer = strtolower($answer);
    $len = strlen($answer);
    $k = fwordleBoardKnowledge($guesses, $answer);

    if ($type === 'orange') {
        // Present letters the player doesn't yet know are present.
        $cands = [];
        foreach (array_unique(str_split($answer)) as $ch) {
            if (!isset($k['present'][$ch])) $cands[] = $ch;
        }
        if (!$cands) return null;
        shuffle($cands);
        $cands = array_slice($cands, 0, 2); // reveal up to 2
        $usedPos = [];
        $cells = [];
        foreach ($cands as $ch) {
            // Show each on a (still free) cell where this letter ISN'T, so the
            // orange is truthful and the two reveals don't collide.
            $spots = [];
            for ($i = 0; $i < $len; $i++) {
                if ($answer[$i] !== $ch && !isset($usedPos[$i])) $spots[] = $i;
            }
            if (!$spots) continue;
            $p = $spots[array_rand($spots)];
            $usedPos[$p] = true;
            $cells[] = $ch . ':' . $p;
        }
        return $cells ? implode(',', $cells) : null;
    }

    if ($type === 'green') {
        $cands = [];
        for ($i = 0; $i < $len; $i++) {
            if (!isset($k['greenPos'][$i])) $cands[] = $i;
        }
        if (!$cands) return null;
        $pos = $cands[array_rand($cands)];
        return $answer[$pos] . ':' . $pos;
    }

    return null;
}

// Parse a stored hint row into the client shape. Armor is board-less; orange and
// green carry a `cells` array of {letter, pos} (orange up to 2, green 1).
function fwordleParseHint(array $h): array
{
    $type = $h['type'];
    if ($type === 'armor') {
        return ['type' => 'armor'];
    }
    $cells = [];
    foreach (explode(',', $h['payload']) as $part) {
        if ($part === '') continue;
        if (strpos($part, ':') !== false) {
            [$l, $p] = explode(':', $part);
            $cells[] = ['letter' => $l, 'pos' => (int) $p];
        } else {
            $cells[] = ['letter' => $part, 'pos' => 0];
        }
    }
    return ['board' => (int) $h['board_pos'], 'type' => $type, 'cells' => $cells];
}

// Did this user buy the armor joker today? (it grants +1 guess overall.)
function fwordleHasArmor(PDO $pdo, string $date, int $userId): bool
{
    $s = $pdo->prepare("SELECT 1 FROM fwordle_hints WHERE game_date = ? AND user_id = ? AND type = 'armor'");
    $s->execute([$date, $userId]);
    return (bool) $s->fetchColumn();
}

// Current solve streak AND freeze balance for a user, computed by replaying every
// day in order from the first relevant day up to today:
//   - each solved day: streak + 1, and +2 freezes whenever the streak hits a
//     multiple of 7.
//   - each PAST unsolved day (today excluded — it isn't over): a freeze bridges
//     it (streak preserved) when the balance allows, otherwise the streak resets.
//   - jokers settle on their day: a freeze-paid joker (`via_freeze`) spends a
//     freeze; every other joker costs 1 streak.
// `effective` is the freeze-aware streak, net of the joker streak-cost.
function fwordleStreakInfo(PDO $pdo, string $date, int $userId): array
{
    $s = $pdo->prepare("SELECT game_date FROM fwordle_results WHERE user_id = ? AND solved = 1 ORDER BY game_date");
    $s->execute([$userId]);
    $solvedList = $s->fetchAll(PDO::FETCH_COLUMN);
    $solved = array_fill_keys($solvedList, true);

    // Jokers per day, split by payment method.
    $h = $pdo->prepare("
        SELECT game_date,
               SUM(via_freeze = 0) AS streak_jokers,
               SUM(via_freeze = 1) AS freeze_jokers
        FROM fwordle_hints WHERE user_id = ? GROUP BY game_date
    ");
    $h->execute([$userId]);
    $streakJokers = []; $freezeJokers = []; $jokerDates = [];
    foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $streakJokers[$r['game_date']] = (int) $r['streak_jokers'];
        $freezeJokers[$r['game_date']] = (int) $r['freeze_jokers'];
        $jokerDates[] = $r['game_date'];
    }

    // Replay from the earliest day that matters up to today.
    $candidates = array_filter([$solvedList[0] ?? null, $jokerDates ? min($jokerDates) : null]);
    $cursor = $candidates ? min($candidates) : $date;

    $balance = FWORDLE_FREEZE_START;
    $streak  = 0;
    while ($cursor <= $date) {
        if (isset($solved[$cursor])) {
            $streak++;
            if ($streak % 7 === 0) $balance += FWORDLE_FREEZE_PER_WEEK;
        } elseif ($cursor !== $date) {
            // A past day that ended unsolved → bridge with a freeze, else break.
            if ($streak > 0) {
                if ($balance >= 1) $balance--;
                else $streak = 0;
            }
        }
        // Settle the day's jokers.
        if (!empty($freezeJokers[$cursor])) $balance -= $freezeJokers[$cursor];
        if (!empty($streakJokers[$cursor])) $streak = max(0, $streak - $streakJokers[$cursor]);
        if ($balance < 0) $balance = 0;

        if ($cursor === $date) break;
        $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
    }

    return [
        'effective'         => max(0, $streak),
        'freezes'           => max(0, $balance),
        'freeze_used_today' => !empty($freezeJokers[$date]),
    ];
}

// Board positions whose word this user chose (auto-solved for them).
function fwordleOwnedPositions(PDO $pdo, string $date, int $userId): array
{
    $stmt = $pdo->prepare("SELECT position FROM fwordle_words WHERE game_date = ? AND chooser_id = ?");
    $stmt->execute([$date, $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── Eligibility + full state payload ─────────────────────────────────────────

// Is this user among the first MAX_WORDS solvers of the day (→ may pick a word)?
function fwordleChoiceEligible(PDO $pdo, string $date, int $userId): bool
{
    $stmt = $pdo->prepare("
        SELECT user_id FROM fwordle_results
        WHERE game_date = ? AND solved = 1
        ORDER BY solved_at ASC
        LIMIT " . FWORDLE_MAX_WORDS
    );
    $stmt->execute([$date]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return in_array($userId, $ids, true);
}

// Everything the page/poll needs. My board carries letters; opponents' boards
// carry colors only (letters are never exposed). Answers are revealed only once
// *I* have finished (won or out of guesses).
function fwordleState(PDO $pdo, string $date, int $userId): array
{
    fwordleEnsureDay($pdo, $date);
    fwordleFinalizeWords($pdo, $date);

    $lenStmt = $pdo->prepare("SELECT word_length FROM fwordle_days WHERE game_date = ?");
    $lenStmt->execute([$date]);
    $len = (int) $lenStmt->fetchColumn();

    $wstmt = $pdo->prepare("SELECT position, word, chooser_id FROM fwordle_words WHERE game_date = ? ORDER BY position");
    $wstmt->execute([$date]);
    $answers = [];
    $chooser = []; // position => chooser user_id (or null)
    foreach ($wstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos = (int) $r['position'];
        $answers[$pos] = $r['word'];
        $chooser[$pos] = $r['chooser_id'] !== null ? (int) $r['chooser_id'] : null;
    }
    $numBoards = count($answers);
    $maxGuesses = fwordleMaxGuesses($numBoards, $len);

    // Owned (auto-solved) board positions per user.
    $ownedOf = function (int $uid) use ($chooser): array {
        $own = [];
        foreach ($chooser as $pos => $cid) if ($cid === $uid) $own[] = $pos;
        return $own;
    };

    $gstmt = $pdo->prepare("
        SELECT g.user_id, u.username, g.guess
        FROM fwordle_guesses g JOIN users u ON u.id = g.user_id
        WHERE g.game_date = ?
        ORDER BY g.user_id, g.guess_index
    ");
    $gstmt->execute([$date]);
    $byUser = [];
    foreach ($gstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int) $r['user_id'];
        if (!isset($byUser[$uid])) $byUser[$uid] = ['username' => $r['username'], 'guesses' => []];
        $byUser[$uid]['guesses'][] = $r['guess'];
    }

    $rstmt = $pdo->prepare("SELECT user_id, finished, solved, solved_at FROM fwordle_results WHERE game_date = ?");
    $rstmt->execute([$date]);
    $results = [];
    foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $r) $results[(int) $r['user_id']] = $r;

    $unStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $unStmt->execute([$userId]);
    $myName = $unStmt->fetchColumn() ?: '';

    // ── Hints (jokers) used today, grouped by user (needed for per-user max
    //    guesses, since the armor joker grants +1). ──
    $hintStmt = $pdo->prepare("SELECT user_id, board_pos, type, payload FROM fwordle_hints WHERE game_date = ?");
    $hintStmt->execute([$date]);
    $hintsByUser = [];
    foreach ($hintStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $hintsByUser[(int) $h['user_id']][] = $h;
    }
    $armorOf = function (int $uid) use ($hintsByUser): bool {
        foreach ($hintsByUser[$uid] ?? [] as $h) if ($h['type'] === 'armor') return true;
        return false;
    };
    $maxGuessesOf = fn(int $uid): int => $maxGuesses + ($armorOf($uid) ? 1 : 0);

    // ── Me (computed even with zero guesses, so an owned board still shows) ──
    $myGuesses = $byUser[$userId]['guesses'] ?? [];
    $myOwned   = $ownedOf($userId);
    $mb        = fwordleUserBoards($myGuesses, $answers, $myOwned);
    $myUsed    = count($myGuesses);
    $myRes     = $results[$userId] ?? null;
    $myMax     = $maxGuessesOf($userId);
    $myFinished = $myRes ? (bool) $myRes['finished'] : ($mb['solved'] || $myUsed >= $myMax);

    // Persist a result when solved/finished isn't recorded yet — covers an
    // auto-win on a board you chose (no guess ever submitted).
    if ($myFinished && (!$myRes || (int) $myRes['finished'] !== 1)) {
        $solvedAt = ($myRes && $myRes['solved_at']) ? $myRes['solved_at'] : ($mb['solved'] ? date('Y-m-d H:i:s') : null);
        $pdo->prepare("
            INSERT INTO fwordle_results (game_date, user_id, finished, solved, guesses_used, solved_at)
            VALUES (?, ?, 1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                finished = VALUES(finished), solved = VALUES(solved),
                guesses_used = VALUES(guesses_used), solved_at = VALUES(solved_at)
        ")->execute([$date, $userId, $mb['solved'] ? 1 : 0, $myUsed, $solvedAt]);
    }

    // Reveal answers for boards I own, plus every board once I'm finished.
    $reveal = [];
    for ($p = 0; $p < $numBoards; $p++) {
        $reveal[$p] = ($myFinished || in_array($p, $myOwned, true)) ? $answers[$p] : null;
    }

    $myHints   = $hintsByUser[$userId] ?? [];
    $myStreak  = fwordleStreakInfo($pdo, $date, $userId)['effective'];
    // Players with no streak to spend still get one joker free per day — it's
    // available only while they have 0 streak AND haven't used a joker yet.
    $freeJoker = ($myStreak < 1 && count($myHints) === 0);

    $me = [
        'username'         => $myName,
        'finished'         => $myFinished,
        'solved'           => $mb['solved'],
        'guesses_used'     => $myUsed,
        'max_guesses'      => $myMax,
        'base_max_guesses' => $maxGuesses,
        'armor'            => $armorOf($userId),
        'streak'           => $myStreak,
        'free_joker'       => $freeJoker,
        'solved_boards'    => $mb['solved_boards'],
        'owned'            => $myOwned,
        'guesses'          => $mb['guesses'],
        'answers'          => $reveal,
        'hints'            => array_map('fwordleParseHint', $myHints),
        'hint_types_used'  => array_values(array_unique(array_map(fn($h) => $h['type'], $myHints))),
        'jokers_used'      => count($myHints),
        'jokers_total'     => 3,
    ];

    // ── Opponents (colors only, never letters) ──
    $opponents = [];
    foreach ($byUser as $uid => $info) {
        if ($uid === $userId) continue;
        $b = fwordleUserBoards($info['guesses'], $answers, $ownedOf($uid));
        $used = count($info['guesses']);
        $res = $results[$uid] ?? null;
        $oppMax = $maxGuessesOf($uid);
        $finished = $res ? (bool) $res['finished'] : ($b['solved'] || $used >= $oppMax);
        $opponents[] = [
            'username'      => $info['username'],
            'finished'      => $finished,
            'solved'        => $b['solved'],
            'guesses_used'  => $used,
            'max_guesses'   => $oppMax,
            'solved_boards' => $b['solved_boards'],
            'owned'         => $ownedOf($uid), // auto-solved boards (their own pick)
            'jokers_used'   => count($hintsByUser[$uid] ?? []),
            'guesses'       => array_map(fn($g) => ['boards' => $g['boards']], $b['guesses']),
        ];
    }

    // Tomorrow's word pick (only once I've solved and earned a slot).
    $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
    $choose = ['eligible' => false, 'already' => null, 'tomorrow_length' => null, 'suggestions' => []];
    if (fwordleChoiceEligible($pdo, $date, $userId)) {
        $tday = fwordleEnsureDay($pdo, $tomorrow);
        $tlen = (int) $tday['word_length'];
        $choose['eligible']        = true;
        $choose['tomorrow_length'] = $tlen;
        $choose['suggestions']     = fwordleSuggestions($tlen, $userId, $tomorrow);

        $c = $pdo->prepare("SELECT word FROM fwordle_choices WHERE game_date = ? AND user_id = ?");
        $c->execute([$tomorrow, $userId]);
        $already = $c->fetchColumn();
        $choose['already'] = $already !== false ? $already : null;
    }

    return [
        'date'        => $date,
        'length'      => $len,
        'num_boards'  => $numBoards,
        'max_guesses' => $maxGuesses,
        'me'          => $me,
        'opponents'   => $opponents,
        'choose'      => $choose,
        'stats'       => fwordleStats($pdo, $date),
    ];
}

// Per-user season stats: current solve streak (consecutive days solved, ending
// today or — if today isn't solved yet — yesterday, so it stays alive) NET of the
// joker streak-cost, and the average guesses used on solved days. One entry per
// user (empty if never won).
function fwordleStats(PDO $pdo, string $date): array
{
    $users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

    $aggStmt = $pdo->query("
        SELECT user_id, COUNT(*) AS solves, AVG(guesses_used) AS avg_g
        FROM fwordle_results WHERE solved = 1 GROUP BY user_id
    ");
    $agg = [];
    foreach ($aggStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $agg[(int) $r['user_id']] = $r;

    $stats = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];

        // Effective streak = consecutive solved days minus jokers spent within
        // that window (each joker costs 1 streak).
        $streak = fwordleStreakInfo($pdo, $date, $uid)['effective'];

        $a = $agg[$uid] ?? null;
        $stats[] = [
            'username'     => $u['username'],
            'streak'       => $streak,
            'solves'       => $a ? (int) $a['solves'] : 0,
            'avg_guesses'  => ($a && $a['avg_g'] !== null) ? round((float) $a['avg_g'], 1) : null,
        ];
    }

    // Biggest streak first; then more solves, then the better (lower) average.
    usort($stats, function ($x, $y) {
        if ($x['streak'] !== $y['streak']) return $y['streak'] <=> $x['streak'];
        if ($x['solves'] !== $y['solves']) return $y['solves'] <=> $x['solves'];
        $ax = $x['avg_guesses'] ?? INF;
        $ay = $y['avg_guesses'] ?? INF;
        return $ax <=> $ay;
    });

    return $stats;
}
