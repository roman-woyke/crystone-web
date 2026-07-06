<?php

// Boardle: Unlimited real-time mode — a single shared "room" (row id is
// always 1, same one-instance-app-wide model as the study counter's presence
// row) that cycles lobby -> voting -> selecting -> playing -> results -> lobby.
//
// Phase transitions are resolved lazily, the same way boardleFinalizeWords()
// lazily finalizes a day: every read/write endpoint calls boardleRtAdvance()
// first, which locks the singleton row, checks whether the current phase's
// deadline has passed (or its completion condition is already met), and
// steps the state machine forward exactly once per stale poll.
//
// Reuses includes/boardle.php's word lists, scoring, and player-rank helpers
// — the only real difference from daily Boardle is that guesses are plain
// fixed-length words (no leading-space offset) and everything is scoped by
// round_id instead of game_date.

require_once __DIR__ . "/boardle.php";

const BOARDLE_RT_MIN_PLAYERS   = 2;
const BOARDLE_RT_MAX_PLAYERS   = BOARDLE_MAX_WORDS; // 4
const BOARDLE_RT_VOTE_SECONDS   = 15;
const BOARDLE_RT_SELECT_SECONDS = 120;
const BOARDLE_RT_PLAY_SECONDS   = 900; // 15 minutes

// A lobby visitor counts as "active" as long as their last poll landed within
// this window — a few times the 1.5s poll interval so one slow/dropped poll
// doesn't flicker them out. The client only polls while its tab is visible,
// so closing/backgrounding the tab naturally lets someone age out.
const BOARDLE_RT_PRESENCE_SECONDS = 8;

function boardleRtRow(PDO $pdo): array
{
    $row = $pdo->query("SELECT * FROM boardle_rt_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->exec("INSERT IGNORE INTO boardle_rt_state (id) VALUES (1)");
        $row = $pdo->query("SELECT * FROM boardle_rt_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

function boardleRtElapsed(array $row): int
{
    return time() - strtotime($row["phase_started_at"]);
}

// Three distinct lengths out of the same 5-10 range daily Boardle uses.
function boardleRtRollLengthChoices(): array
{
    $all = range(BOARDLE_MIN_LEN, BOARDLE_MAX_LEN);
    shuffle($all);
    return array_slice($all, 0, 3);
}

function boardleRtAllVoted(PDO $pdo, int $roundId, int $numPlayers): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM boardle_rt_votes WHERE round_id = ?");
    $st->execute([$roundId]);
    return (int) $st->fetchColumn() >= $numPlayers;
}

function boardleRtAllChosen(PDO $pdo, int $roundId, int $numPlayers): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM boardle_rt_words WHERE round_id = ?");
    $st->execute([$roundId]);
    return (int) $st->fetchColumn() >= $numPlayers;
}

function boardleRtAllFinished(PDO $pdo, int $roundId, int $numPlayers): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM boardle_rt_results WHERE round_id = ? AND finished = 1");
    $st->execute([$roundId]);
    return (int) $st->fetchColumn() >= $numPlayers;
}

// Everyone whose lobby poll (or ready toggle) landed within the presence
// window — i.e. who actually has the lobby open right now, not just anyone
// who once clicked ready. Each row also carries whether that visitor is
// currently marked ready.
function boardleRtActivePlayers(PDO $pdo): array
{
    $st = $pdo->prepare("
        SELECT u.id, u.username, r.ready FROM boardle_rt_ready r
        JOIN users u ON u.id = r.user_id
        WHERE r.updated_at >= NOW() - INTERVAL " . BOARDLE_RT_PRESENCE_SECONDS . " SECOND
        ORDER BY u.username
    ");
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// A round only starts once everyone currently active in the lobby (not just
// "at least 2 people, ever, who clicked ready") has marked themselves ready —
// so a round never kicks off while someone who's actually watching the lobby
// hasn't opted in yet. Called under the row lock; no-ops otherwise.
function boardleRtMaybeStartRound(PDO $pdo, array $row): void
{
    $active = boardleRtActivePlayers($pdo);
    if (count($active) < BOARDLE_RT_MIN_PLAYERS) return;
    foreach ($active as $a) {
        if (!$a["ready"]) return;
    }
    $ready = $active;

    usort($ready, fn($a, $b) => boardlePlayerRank($a["username"]) <=> boardlePlayerRank($b["username"]));
    $ready = array_slice($ready, 0, BOARDLE_RT_MAX_PLAYERS);

    $roundId = (int) $row["round_id"] + 1;
    $insPlayer = $pdo->prepare("INSERT INTO boardle_rt_players (round_id, user_id, position) VALUES (?, ?, ?)");
    foreach ($ready as $pos => $u) {
        $insPlayer->execute([$roundId, $u["id"], $pos]);
    }

    $choices = boardleRtRollLengthChoices();
    $pdo->prepare("
        UPDATE boardle_rt_state
        SET phase = 'voting', round_id = ?, phase_started_at = NOW(),
            length_choices = ?, word_length = NULL, num_boards = ?
        WHERE id = 1
    ")->execute([$roundId, implode(",", $choices), count($ready)]);

    $pdo->exec("DELETE FROM boardle_rt_ready");
}

function boardleRtResolveVoting(PDO $pdo, array $row): void
{
    $roundId = (int) $row["round_id"];
    $choices = array_map("intval", explode(",", (string) $row["length_choices"]));

    $tally = array_fill_keys($choices, 0);
    $st = $pdo->prepare("SELECT length, COUNT(*) AS n FROM boardle_rt_votes WHERE round_id = ? GROUP BY length");
    $st->execute([$roundId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($tally[(int) $r["length"]])) $tally[(int) $r["length"]] = (int) $r["n"];
    }

    $max = max($tally);
    $winners = array_keys(array_filter($tally, fn($n) => $n === $max));
    $length = $winners[array_rand($winners)];

    $pdo->prepare("
        UPDATE boardle_rt_state SET phase = 'selecting', phase_started_at = NOW(), word_length = ? WHERE id = 1
    ")->execute([$length]);
}

// Anyone who hasn't chosen a word+hint in time gets a random unused word and
// a placeholder hint, so a slow/AFK player never blocks the whole room.
function boardleRtResolveSelecting(PDO $pdo, array $row): void
{
    $roundId = (int) $row["round_id"];
    $length  = (int) $row["word_length"];

    $players = $pdo->prepare("SELECT user_id, position FROM boardle_rt_players WHERE round_id = ?");
    $players->execute([$roundId]);
    $players = $players->fetchAll(PDO::FETCH_ASSOC);

    $chosen = $pdo->prepare("SELECT position, word FROM boardle_rt_words WHERE round_id = ?");
    $chosen->execute([$roundId]);
    $chosenRows = $chosen->fetchAll(PDO::FETCH_ASSOC);
    $usedWords  = array_column($chosenRows, "word");
    $chosenPos  = array_flip(array_column($chosenRows, "position"));

    $ins = $pdo->prepare("
        INSERT IGNORE INTO boardle_rt_words (round_id, position, chooser_id, word, hint) VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($players as $p) {
        $pos = (int) $p["position"];
        if (isset($chosenPos[$pos])) continue;
        $word = boardleRandomAnswer($length, $usedWords) ?? "";
        if ($word === "") continue;
        $usedWords[] = $word;
        $ins->execute([$roundId, $pos, (int) $p["user_id"], $word, "No hint provided."]);
    }

    $pdo->prepare("
        UPDATE boardle_rt_state SET phase = 'playing', phase_started_at = NOW() WHERE id = 1
    ")->execute();
    $pdo->prepare("
        INSERT INTO boardle_rt_rounds (round_id, playing_started_at) VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE playing_started_at = playing_started_at
    ")->execute([$roundId]);
}

// Anyone still mid-game when time runs out is locked in with whatever they'd
// solved so far, then the room moves to the results screen.
function boardleRtResolvePlaying(PDO $pdo, array $row): void
{
    $roundId = (int) $row["round_id"];

    $wStmt = $pdo->prepare("SELECT position, word FROM boardle_rt_words WHERE round_id = ? ORDER BY position");
    $wStmt->execute([$roundId]);
    $answers = [];
    foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $answers[(int) $r["position"]] = $r["word"];

    $players = $pdo->prepare("SELECT user_id, position FROM boardle_rt_players WHERE round_id = ?");
    $players->execute([$roundId]);
    $players = $players->fetchAll(PDO::FETCH_ASSOC);

    $doneStmt = $pdo->prepare("SELECT user_id FROM boardle_rt_results WHERE round_id = ? AND finished = 1");
    $doneStmt->execute([$roundId]);
    $done = array_flip($doneStmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($players as $p) {
        $uid = (int) $p["user_id"];
        if (isset($done[$uid])) continue;

        $gStmt = $pdo->prepare("SELECT guess FROM boardle_rt_guesses WHERE round_id = ? AND user_id = ? ORDER BY guess_index");
        $gStmt->execute([$roundId, $uid]);
        $guesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);

        $boards = boardleUserBoards($guesses, $answers, [(int) $p["position"]]);
        $pdo->prepare("
            INSERT INTO boardle_rt_results (round_id, user_id, finished, solved, guesses_used, solved_at)
            VALUES (?, ?, 1, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE finished = 1, solved = VALUES(solved), guesses_used = VALUES(guesses_used)
        ")->execute([$roundId, $uid, $boards["solved"] ? 1 : 0, count($guesses)]);
    }

    // Decide this round's two winning categories among everyone who actually
    // solved: fastest (lowest solved_at - playing_started_at) and fewest
    // guesses. Ties in either category all count as co-winners.
    $startStmt = $pdo->prepare("SELECT playing_started_at FROM boardle_rt_rounds WHERE round_id = ?");
    $startStmt->execute([$roundId]);
    $startedAt = $startStmt->fetchColumn();

    $solvedStmt = $pdo->prepare("
        SELECT user_id, guesses_used, solved_at FROM boardle_rt_results WHERE round_id = ? AND solved = 1
    ");
    $solvedStmt->execute([$roundId]);
    $solvedRows = $solvedStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($solvedRows && $startedAt) {
        $startTs = strtotime($startedAt);
        $bestGuesses = min(array_map(fn($r) => (int) $r["guesses_used"], $solvedRows));
        $bestTime    = min(array_map(fn($r) => strtotime($r["solved_at"]) - $startTs, $solvedRows));

        $winStmt = $pdo->prepare("UPDATE boardle_rt_results SET speed_win = ?, guess_win = ? WHERE round_id = ? AND user_id = ?");
        foreach ($solvedRows as $r) {
            $isSpeedWin = (strtotime($r["solved_at"]) - $startTs) === $bestTime;
            $isGuessWin = (int) $r["guesses_used"] === $bestGuesses;
            $winStmt->execute([$isSpeedWin ? 1 : 0, $isGuessWin ? 1 : 0, $roundId, (int) $r["user_id"]]);
        }
    }

    $pdo->prepare("UPDATE boardle_rt_state SET phase = 'results', phase_started_at = NOW() WHERE id = 1")->execute();
}

// The lazy state-machine tick — call at the top of every RT endpoint.
function boardleRtAdvance(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $row = $pdo->query("SELECT * FROM boardle_rt_state WHERE id = 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->exec("INSERT IGNORE INTO boardle_rt_state (id) VALUES (1)");
            $pdo->commit();
            return;
        }

        $elapsed = boardleRtElapsed($row);
        $roundId = (int) $row["round_id"];
        $numBoards = (int) ($row["num_boards"] ?? 0);

        switch ($row["phase"]) {
            case "lobby":
                boardleRtMaybeStartRound($pdo, $row);
                break;

            case "voting":
                if ($elapsed >= BOARDLE_RT_VOTE_SECONDS || boardleRtAllVoted($pdo, $roundId, $numBoards)) {
                    boardleRtResolveVoting($pdo, $row);
                }
                break;

            case "selecting":
                if ($elapsed >= BOARDLE_RT_SELECT_SECONDS || boardleRtAllChosen($pdo, $roundId, $numBoards)) {
                    boardleRtResolveSelecting($pdo, $row);
                }
                break;

            case "playing":
                if ($elapsed >= BOARDLE_RT_PLAY_SECONDS || boardleRtAllFinished($pdo, $roundId, $numBoards)) {
                    boardleRtResolvePlaying($pdo, $row);
                }
                break;

            case "results":
                // Waits for an explicit "continue" call.
                break;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// All site users, for the lobby's player+ready list (small closed friend
// group — everyone is a potential player, unlike per-user application data).
function boardleRtAllUsers(PDO $pdo): array
{
    return $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
}

// Two separate podiums across every round ever played in unlimited mode —
// freezes/streaks don't apply here, it's just win counts in each category:
// speed (fastest solve) and guesses (fewest guesses), per next-patch.txt.
function boardleRtStandings(PDO $pdo): array
{
    $users = boardleRtAllUsers($pdo);
    $agg = $pdo->query("
        SELECT user_id, COUNT(*) AS played, SUM(solved) AS solves,
               SUM(speed_win) AS speed_wins, SUM(guess_win) AS guess_wins
        FROM boardle_rt_results WHERE finished = 1 GROUP BY user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $byUser = [];
    foreach ($agg as $r) $byUser[(int) $r["user_id"]] = $r;

    $base = [];
    foreach ($users as $u) {
        $a = $byUser[(int) $u["id"]] ?? null;
        $base[] = [
            "username"   => $u["username"],
            "played"     => $a ? (int) $a["played"] : 0,
            "solves"     => $a ? (int) $a["solves"] : 0,
            "speed_wins" => $a ? (int) $a["speed_wins"] : 0,
            "guess_wins" => $a ? (int) $a["guess_wins"] : 0,
        ];
    }

    $bySpeed = $base;
    usort($bySpeed, function ($x, $y) {
        if ($x["speed_wins"] !== $y["speed_wins"]) return $y["speed_wins"] <=> $x["speed_wins"];
        return $y["solves"] <=> $x["solves"];
    });
    $byGuesses = $base;
    usort($byGuesses, function ($x, $y) {
        if ($x["guess_wins"] !== $y["guess_wins"]) return $y["guess_wins"] <=> $x["guess_wins"];
        return $y["solves"] <=> $x["solves"];
    });

    return ["speed" => $bySpeed, "guesses" => $byGuesses];
}

// Full payload for the polling endpoint, scoped to the requesting user.
function boardleRtPayload(PDO $pdo, int $userId): array
{
    // Presence heartbeat: touch (or create, defaulting to not-ready) this
    // user's lobby row *before* advancing, so this very poll can itself
    // contribute to "everyone active is ready" if that's already the case.
    // Only meaningful in the lobby — once a round starts, boardle_rt_ready
    // is empty until the room returns to the lobby.
    $preRow = boardleRtRow($pdo);
    if ($preRow["phase"] === "lobby") {
        $pdo->prepare("
            INSERT INTO boardle_rt_ready (user_id, ready) VALUES (?, 0)
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ")->execute([$userId]);
    }

    boardleRtAdvance($pdo);
    $row = boardleRtRow($pdo);
    $phase   = $row["phase"];
    $roundId = (int) $row["round_id"];
    $elapsed = boardleRtElapsed($row);

    $payload = [
        "phase"     => $phase,
        "round_id"  => $roundId,
        "elapsed"   => $elapsed,
        "users"     => array_map(fn($u) => $u["username"], boardleRtAllUsers($pdo)),
    ];

    if ($phase === "lobby") {
        // Only whoever's lobby is actually open right now (see the heartbeat
        // above) — not every site user, and not everyone who's ever readied.
        $payload["active"] = array_map(fn($a) => [
            "username" => $a["username"],
            "ready"    => (bool) $a["ready"],
        ], boardleRtActivePlayers($pdo));
        $payload["min_players"] = BOARDLE_RT_MIN_PLAYERS;
        return $payload;
    }

    $playersStmt = $pdo->prepare("
        SELECT p.user_id, p.position, u.username FROM boardle_rt_players p
        JOIN users u ON u.id = p.user_id WHERE p.round_id = ? ORDER BY p.position
    ");
    $playersStmt->execute([$roundId]);
    $players = $playersStmt->fetchAll(PDO::FETCH_ASSOC);
    $myPosition = null;
    foreach ($players as $p) if ((int) $p["user_id"] === $userId) $myPosition = (int) $p["position"];

    $payload["players"]     = array_map(fn($p) => $p["username"], $players);
    $payload["num_boards"]  = (int) $row["num_boards"];
    $payload["my_position"] = $myPosition;
    $payload["in_round"]    = ($myPosition !== null);

    if ($phase === "voting") {
        $payload["deadline"]       = BOARDLE_RT_VOTE_SECONDS;
        $payload["length_choices"] = array_map("intval", explode(",", (string) $row["length_choices"]));
        $voteStmt = $pdo->prepare("SELECT user_id, length FROM boardle_rt_votes WHERE round_id = ?");
        $voteStmt->execute([$roundId]);
        $votes = $voteStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $payload["my_vote"]     = $votes[$userId] ?? null;
        $payload["voted_count"] = count($votes);
        $tally = array_fill_keys($payload["length_choices"], 0);
        foreach ($votes as $len) if (isset($tally[$len])) $tally[$len]++;
        $payload["tally"] = $tally;
        return $payload;
    }

    if ($phase === "selecting") {
        $payload["deadline"]    = BOARDLE_RT_SELECT_SECONDS;
        $payload["word_length"] = (int) $row["word_length"];
        $chosenStmt = $pdo->prepare("SELECT position FROM boardle_rt_words WHERE round_id = ?");
        $chosenStmt->execute([$roundId]);
        $chosenPositions = array_map("intval", $chosenStmt->fetchAll(PDO::FETCH_COLUMN));
        $payload["chosen_count"] = count($chosenPositions);
        $payload["i_chose"]      = $myPosition !== null && in_array($myPosition, $chosenPositions, true);
        return $payload;
    }

    // playing / results: build the board answers + my guesses, same shape as
    // daily boardleState() so the client can reuse its scoring/render logic.
    $wStmt = $pdo->prepare("SELECT position, word, hint, chooser_id FROM boardle_rt_words WHERE round_id = ? ORDER BY position");
    $wStmt->execute([$roundId]);
    $answers = [];
    $hints   = [];
    $chooser = [];
    foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos = (int) $r["position"];
        $answers[$pos] = $r["word"];
        $hints[$pos]   = $r["hint"];
        $chooser[$pos] = (int) $r["chooser_id"];
    }
    $length     = (int) $row["word_length"];
    $numBoards  = count($answers);
    $maxGuesses = boardleMaxGuesses($numBoards, $length);
    $payload["word_length"] = $length;
    $payload["max_guesses"] = $maxGuesses;

    $gStmt = $pdo->prepare("SELECT guess FROM boardle_rt_guesses WHERE round_id = ? AND user_id = ? ORDER BY guess_index");
    $gStmt->execute([$roundId, $userId]);
    $myGuesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);
    $owned = $myPosition !== null ? [$myPosition] : [];
    $me = boardleUserBoards($myGuesses, $answers, $owned);
    $me["guesses_used"] = count($myGuesses);
    $me["finished"] = $me["solved"] || $me["guesses_used"] >= $maxGuesses;
    $me["hints"] = array_values($hints);
    // My own board is auto-solved from the moment the round starts (I picked
    // the word), so its answer is mine to see right away — not just once the
    // whole round is finished.
    $me["own_word"] = $myPosition !== null ? ($answers[$myPosition] ?? null) : null;
    $payload["me"] = $me;
    if ($me["finished"] && $myPosition !== null) $payload["me"]["answers"] = array_values($answers);

    // Opponents: colors only, letters stripped, unless the round is over.
    $opponents = [];
    foreach ($players as $p) {
        $uid = (int) $p["user_id"];
        if ($uid === $userId) continue;
        $og = $pdo->prepare("SELECT guess FROM boardle_rt_guesses WHERE round_id = ? AND user_id = ? ORDER BY guess_index");
        $og->execute([$roundId, $uid]);
        $oGuesses = $og->fetchAll(PDO::FETCH_COLUMN);
        $oBoards = boardleUserBoards($oGuesses, $answers, [(int) $p["position"]]);
        foreach ($oBoards["guesses"] as &$row2) unset($row2["text"]);
        unset($row2);
        $opponents[] = [
            "username"     => $p["username"],
            "guesses_used" => count($oGuesses),
            "solved"       => $oBoards["solved"],
            "boards"       => $oBoards["guesses"],
            "solved_boards" => $oBoards["solved_boards"],
        ];
    }
    $payload["opponents"] = $opponents;

    if ($phase === "playing") {
        $payload["deadline"] = BOARDLE_RT_PLAY_SECONDS;
    }

    if ($phase === "results") {
        $rStmt = $pdo->prepare("
            SELECT r.user_id, u.username, r.solved, r.guesses_used, r.speed_win, r.guess_win
            FROM boardle_rt_results r JOIN users u ON u.id = r.user_id WHERE r.round_id = ?
        ");
        $rStmt->execute([$roundId]);
        $results = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        usort($results, function ($a, $b) {
            if ((int) $a["solved"] !== (int) $b["solved"]) return $b["solved"] <=> $a["solved"];
            return (int) $a["guesses_used"] <=> (int) $b["guesses_used"];
        });
        $payload["results"] = array_map(fn($r) => [
            "username"     => $r["username"],
            "solved"       => (bool) $r["solved"],
            "guesses_used" => (int) $r["guesses_used"],
            "speed_win"    => (bool) $r["speed_win"],
            "guess_win"    => (bool) $r["guess_win"],
        ], $results);
        $payload["standings"] = boardleRtStandings($pdo);
        $payload["answers"]   = array_values($answers);
    }

    return $payload;
}
