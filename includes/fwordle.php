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

const FWORDLE_MAX_GUESSES = 7;
const FWORDLE_MAX_WORDS   = 4;
const FWORDLE_MIN_LEN     = 5;
const FWORDLE_MAX_LEN     = 10;

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

// Weighted random length: 5→50%, 6→25%, 7→15%, 8→6%, 9→3%, 10→1%.
function fwordleRollLength(): int
{
    $weights = [5 => 50, 6 => 25, 7 => 15, 8 => 6, 9 => 3, 10 => 1];
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

// Build (once) the answer words for a date that has arrived: one slot per
// previous-day solver (their chosen word, else random backfill), or 4 random
// words when nobody solved. Idempotent and race-safe.
function fwordleFinalizeWords(PDO $pdo, string $date): void
{
    $day = fwordleEnsureDay($pdo, $date);
    if ((int) $day['words_finalized'] === 1) return;
    if ($date > date('Y-m-d')) return; // future days keep collecting choices

    $len  = (int) $day['word_length'];
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));

    $solverStmt = $pdo->prepare("
        SELECT user_id FROM fwordle_results
        WHERE game_date = ? AND solved = 1
        ORDER BY solved_at ASC
        LIMIT " . FWORDLE_MAX_WORDS
    );
    $solverStmt->execute([$prev]);
    $slotUsers = array_map('intval', $solverStmt->fetchAll(PDO::FETCH_COLUMN));

    $words = []; // [word, source, chooser_id]
    $used  = [];

    if (empty($slotUsers)) {
        for ($i = 0; $i < FWORDLE_MAX_WORDS; $i++) {
            $w = fwordleRandomAnswer($len, $used);
            if ($w === null) break;
            $used[] = $w;
            $words[] = [$w, 'random', null];
        }
    } else {
        $choiceStmt = $pdo->prepare("SELECT word FROM fwordle_choices WHERE game_date = ? AND user_id = ?");
        foreach ($slotUsers as $uid) {
            $choiceStmt->execute([$date, $uid]);
            $chosen = $choiceStmt->fetchColumn();
            $chosen = $chosen !== false ? strtolower($chosen) : null;

            if ($chosen !== null && fwordleIsValidWord($chosen, $len) && !in_array($chosen, $used, true)) {
                $used[] = $chosen;
                $words[] = [$chosen, 'chosen', $uid];
            } else {
                $w = fwordleRandomAnswer($len, $used);
                if ($w === null) continue;
                $used[] = $w;
                $words[] = [$w, 'random', null];
            }
        }
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

// Replay a user's guesses across all boards.
function fwordleUserBoards(array $guesses, array $answers): array
{
    $num = count($answers);
    $solvedBoards = array_fill(0, $num, false);
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

    $wstmt = $pdo->prepare("SELECT word FROM fwordle_words WHERE game_date = ? ORDER BY position");
    $wstmt->execute([$date]);
    $answers = $wstmt->fetchAll(PDO::FETCH_COLUMN);
    $numBoards = count($answers);

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

    $rstmt = $pdo->prepare("SELECT user_id, finished, solved FROM fwordle_results WHERE game_date = ?");
    $rstmt->execute([$date]);
    $results = [];
    foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $r) $results[(int) $r['user_id']] = $r;

    $unStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $unStmt->execute([$userId]);
    $myName = $unStmt->fetchColumn() ?: '';

    $me = [
        'username' => $myName, 'finished' => false, 'solved' => false,
        'guesses_used' => 0, 'solved_boards' => array_fill(0, $numBoards, false),
        'guesses' => [],
    ];
    $opponents = [];

    foreach ($byUser as $uid => $info) {
        $b = fwordleUserBoards($info['guesses'], $answers);
        $used = count($info['guesses']);
        $res = $results[$uid] ?? null;
        $finished = $res ? (bool) $res['finished'] : ($b['solved'] || $used >= FWORDLE_MAX_GUESSES);

        $entry = [
            'username'      => $info['username'],
            'finished'      => $finished,
            'solved'        => $b['solved'],
            'guesses_used'  => $used,
            'solved_boards' => $b['solved_boards'],
        ];

        if ($uid === $userId) {
            $entry['guesses'] = $b['guesses']; // full letters
            if ($finished) $entry['answers'] = $answers;
            $me = $entry;
        } else {
            $entry['guesses'] = array_map(fn($g) => ['boards' => $g['boards']], $b['guesses']);
            $opponents[] = $entry;
        }
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
        'max_guesses' => FWORDLE_MAX_GUESSES,
        'me'          => $me,
        'opponents'   => $opponents,
        'choose'      => $choose,
    ];
}
