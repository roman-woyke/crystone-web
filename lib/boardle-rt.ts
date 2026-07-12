// Boardle: Unlimited real-time mode — a single shared "room" (row id is
// always 1, same one-instance-app-wide model as the study counter's presence
// row) that cycles lobby -> voting -> selecting -> playing -> results -> lobby.
// Ported 1:1 from includes/boardle-rt.php.
//
// Phase transitions are resolved lazily, the same way boardleFinalizeWords()
// lazily finalizes a day: every read/write endpoint calls boardleRtAdvance()
// first, which locks the singleton row, checks whether the current phase's
// deadline has passed (or its completion condition is already met), and
// steps the state machine forward exactly once per stale poll.
//
// Reuses the daily Boardle word lists, scoring, and player-rank helpers —
// the only real difference from daily Boardle is that everything is scoped
// by round_id instead of game_date.

import { prisma } from "@/lib/prisma";
import { dbNow, diffSeconds } from "@/lib/dates";
import {
  BOARDLE_MAX_LEN,
  BOARDLE_MAX_WORDS,
  BOARDLE_MIN_LEN,
  boardleMaxGuesses,
  boardleRandomAnswer,
} from "@/lib/boardle-words";
import { boardlePlayerRank, boardleUserBoards, type BoardGuessRow, type CellColor } from "@/lib/boardle-score";
import type { BoardleRtState, Prisma } from "@/app/generated/prisma/client";

export const BOARDLE_RT_MIN_PLAYERS = 2;
export const BOARDLE_RT_MAX_PLAYERS = BOARDLE_MAX_WORDS; // 4
export const BOARDLE_RT_VOTE_SECONDS = 15;
export const BOARDLE_RT_SELECT_SECONDS = 120;
export const BOARDLE_RT_PLAY_SECONDS = 900; // 15 minutes

// A lobby visitor counts as "active" as long as their last poll landed within
// this window — a few times the 1.5s poll interval so one slow/dropped poll
// doesn't flicker them out. The client only polls while its tab is visible,
// so closing/backgrounding the tab naturally lets someone age out.
export const BOARDLE_RT_PRESENCE_SECONDS = 8;

type Db = Prisma.TransactionClient;

export async function boardleRtRow(db: Db = prisma): Promise<BoardleRtState> {
  const row = await db.boardleRtState.findUnique({ where: { id: 1 } });
  if (row) return row;
  try {
    return await db.boardleRtState.create({ data: { id: 1, phaseStartedAt: dbNow() } });
  } catch {
    return await db.boardleRtState.findUniqueOrThrow({ where: { id: 1 } });
  }
}

function boardleRtElapsed(row: BoardleRtState): number {
  return diffSeconds(row.phaseStartedAt, dbNow());
}

// Three distinct lengths out of the same 5-10 range daily Boardle uses.
function boardleRtRollLengthChoices(): number[] {
  const all: number[] = [];
  for (let l = BOARDLE_MIN_LEN; l <= BOARDLE_MAX_LEN; l++) all.push(l);
  for (let i = all.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [all[i], all[j]] = [all[j], all[i]];
  }
  return all.slice(0, 3);
}

async function boardleRtAllVoted(db: Db, roundId: number, numPlayers: number): Promise<boolean> {
  return (await db.boardleRtVote.count({ where: { roundId } })) >= numPlayers;
}

async function boardleRtAllChosen(db: Db, roundId: number, numPlayers: number): Promise<boolean> {
  return (await db.boardleRtWord.count({ where: { roundId } })) >= numPlayers;
}

async function boardleRtAllFinished(db: Db, roundId: number, numPlayers: number): Promise<boolean> {
  return (await db.boardleRtResult.count({ where: { roundId, finished: 1 } })) >= numPlayers;
}

type ActivePlayer = { id: number; username: string; ready: boolean };

// Everyone whose lobby poll (or ready toggle) landed within the presence
// window — i.e. who actually has the lobby open right now, not just anyone
// who once clicked ready. Each row also carries whether that visitor is
// currently marked ready.
async function boardleRtActivePlayers(db: Db): Promise<ActivePlayer[]> {
  const cutoff = new Date(dbNow().getTime() - BOARDLE_RT_PRESENCE_SECONDS * 1000);
  const rows = await db.boardleRtReady.findMany({ where: { updatedAt: { gte: cutoff } } });
  if (rows.length === 0) return [];
  const users = await db.user.findMany({
    where: { id: { in: rows.map((r) => r.userId) } },
    select: { id: true, username: true },
  });
  const nameOf = new Map(users.map((u) => [u.id, u.username]));
  return rows
    .map((r) => ({ id: r.userId, username: nameOf.get(r.userId) ?? "", ready: r.ready === 1 }))
    .filter((r) => r.username !== "")
    .sort((a, b) => a.username.localeCompare(b.username));
}

// A round only starts once everyone currently active in the lobby (not just
// "at least 2 people, ever, who clicked ready") has marked themselves ready —
// so a round never kicks off while someone who's actually watching the lobby
// hasn't opted in yet. Called under the row lock; no-ops otherwise.
async function boardleRtMaybeStartRound(db: Db, row: BoardleRtState): Promise<void> {
  const active = await boardleRtActivePlayers(db);
  if (active.length < BOARDLE_RT_MIN_PLAYERS) return;
  if (active.some((a) => !a.ready)) return;

  const ready = [...active]
    .sort((a, b) => boardlePlayerRank(a.username) - boardlePlayerRank(b.username))
    .slice(0, BOARDLE_RT_MAX_PLAYERS);

  const roundId = row.roundId + 1;
  await db.boardleRtPlayer.createMany({
    data: ready.map((u, pos) => ({ roundId, userId: u.id, position: pos })),
  });

  const choices = boardleRtRollLengthChoices();
  await db.boardleRtState.update({
    where: { id: 1 },
    data: {
      phase: "voting",
      roundId,
      phaseStartedAt: dbNow(),
      lengthChoices: choices.join(","),
      wordLength: null,
      numBoards: ready.length,
    },
  });

  await db.boardleRtReady.deleteMany({});
}

async function boardleRtResolveVoting(db: Db, row: BoardleRtState): Promise<void> {
  const roundId = row.roundId;
  const choices = String(row.lengthChoices ?? "")
    .split(",")
    .map(Number);

  const tally = new Map<number, number>(choices.map((c) => [c, 0]));
  const votes = await db.boardleRtVote.findMany({ where: { roundId } });
  for (const v of votes) {
    if (tally.has(v.length)) tally.set(v.length, (tally.get(v.length) ?? 0) + 1);
  }

  const max = Math.max(...tally.values());
  const winners = [...tally.entries()].filter(([, n]) => n === max).map(([len]) => len);
  const length = winners[Math.floor(Math.random() * winners.length)];

  await db.boardleRtState.update({
    where: { id: 1 },
    data: { phase: "selecting", phaseStartedAt: dbNow(), wordLength: length },
  });
}

// Anyone who hasn't chosen a word+hint in time gets a random unused word and
// a placeholder hint, so a slow/AFK player never blocks the whole room.
async function boardleRtResolveSelecting(db: Db, row: BoardleRtState): Promise<void> {
  const roundId = row.roundId;
  const length = row.wordLength ?? 0;

  const players = await db.boardleRtPlayer.findMany({ where: { roundId } });
  const chosenRows = await db.boardleRtWord.findMany({ where: { roundId } });
  const usedWords = chosenRows.map((r) => r.word);
  const chosenPos = new Set(chosenRows.map((r) => r.position));

  const fills: { roundId: number; position: number; chooserId: number; word: string; hint: string }[] = [];
  for (const p of players) {
    if (chosenPos.has(p.position)) continue;
    const word = boardleRandomAnswer(length, usedWords);
    if (word === null) continue;
    usedWords.push(word);
    fills.push({ roundId, position: p.position, chooserId: p.userId, word, hint: "No hint provided." });
  }
  if (fills.length) {
    await db.boardleRtWord.createMany({ data: fills, skipDuplicates: true });
  }

  await db.boardleRtState.update({ where: { id: 1 }, data: { phase: "playing", phaseStartedAt: dbNow() } });
  await db.boardleRtRound.upsert({
    where: { roundId },
    create: { roundId, playingStartedAt: dbNow() },
    update: {}, // keep the original start on a replayed resolve
  });
}

// Anyone still mid-game when time runs out is locked in with whatever they'd
// solved so far, then the room moves to the results screen.
async function boardleRtResolvePlaying(db: Db, row: BoardleRtState): Promise<void> {
  const roundId = row.roundId;

  const wordRows = await db.boardleRtWord.findMany({ where: { roundId }, orderBy: { position: "asc" } });
  const answers: string[] = [];
  for (const r of wordRows) answers[r.position] = r.word;

  const players = await db.boardleRtPlayer.findMany({ where: { roundId } });
  const doneRows = await db.boardleRtResult.findMany({ where: { roundId, finished: 1 }, select: { userId: true } });
  const done = new Set(doneRows.map((r) => r.userId));

  for (const p of players) {
    if (done.has(p.userId)) continue;
    const guessRows = await db.boardleRtGuess.findMany({
      where: { roundId, userId: p.userId },
      orderBy: { guessIndex: "asc" },
      select: { guess: true },
    });
    const guesses = guessRows.map((g) => g.guess);
    const boards = boardleUserBoards(guesses, answers, [p.position]);
    await db.boardleRtResult.upsert({
      where: { roundId_userId: { roundId, userId: p.userId } },
      create: {
        roundId,
        userId: p.userId,
        finished: 1,
        solved: boards.solved ? 1 : 0,
        guessesUsed: guesses.length,
        solvedAt: null,
      },
      update: { finished: 1, solved: boards.solved ? 1 : 0, guessesUsed: guesses.length },
    });
  }

  // Decide this round's two winning categories among everyone who actually
  // solved: fastest (lowest solved_at - playing_started_at) and fewest
  // guesses. Ties in either category all count as co-winners.
  const round = await db.boardleRtRound.findUnique({ where: { roundId } });
  const solvedRows = await db.boardleRtResult.findMany({ where: { roundId, solved: 1 } });

  if (solvedRows.length && round) {
    const timeOf = (solvedAt: Date | null) => (solvedAt ? diffSeconds(round.playingStartedAt, solvedAt) : Infinity);
    const bestGuesses = Math.min(...solvedRows.map((r) => r.guessesUsed));
    const bestTime = Math.min(...solvedRows.map((r) => timeOf(r.solvedAt)));

    for (const r of solvedRows) {
      await db.boardleRtResult.update({
        where: { roundId_userId: { roundId, userId: r.userId } },
        data: {
          speedWin: timeOf(r.solvedAt) === bestTime ? 1 : 0,
          guessWin: r.guessesUsed === bestGuesses ? 1 : 0,
        },
      });
    }
  }

  await db.boardleRtState.update({ where: { id: 1 }, data: { phase: "results", phaseStartedAt: dbNow() } });
}

// The lazy state-machine tick — call at the top of every RT endpoint.
export async function boardleRtAdvance(): Promise<void> {
  await prisma.$transaction(async (tx) => {
    // Lock the singleton row so two overlapping polls can't both step the
    // machine (same pattern as boardleFinalizeWords' words_finalized lock).
    const locked = await tx.$queryRaw<{ id: number }[]>`SELECT id FROM boardle_rt_state WHERE id = 1 FOR UPDATE`;
    if (!locked.length) {
      await tx.$executeRaw`INSERT IGNORE INTO boardle_rt_state (id) VALUES (1)`;
      return;
    }
    const row = await tx.boardleRtState.findUniqueOrThrow({ where: { id: 1 } });

    const elapsed = boardleRtElapsed(row);
    const roundId = row.roundId;
    const numBoards = row.numBoards ?? 0;

    switch (row.phase) {
      case "lobby":
        await boardleRtMaybeStartRound(tx, row);
        break;

      case "voting":
        if (elapsed >= BOARDLE_RT_VOTE_SECONDS || (await boardleRtAllVoted(tx, roundId, numBoards))) {
          await boardleRtResolveVoting(tx, row);
        }
        break;

      case "selecting":
        if (elapsed >= BOARDLE_RT_SELECT_SECONDS || (await boardleRtAllChosen(tx, roundId, numBoards))) {
          await boardleRtResolveSelecting(tx, row);
        }
        break;

      case "playing":
        if (elapsed >= BOARDLE_RT_PLAY_SECONDS || (await boardleRtAllFinished(tx, roundId, numBoards))) {
          await boardleRtResolvePlaying(tx, row);
        }
        break;

      case "results":
        // Waits for an explicit "continue" call.
        break;
    }
  });
}

// All site users, for the lobby's player+ready list (small closed friend
// group — everyone is a potential player, unlike per-user application data).
async function boardleRtAllUsers(db: Db): Promise<{ id: number; username: string }[]> {
  return db.user.findMany({ select: { id: true, username: true }, orderBy: { username: "asc" } });
}

export type RtStandingRow = {
  username: string;
  played: number;
  solves: number;
  speed_wins: number;
  guess_wins: number;
};

// Two separate podiums across every round ever played in unlimited mode —
// freezes/streaks don't apply here, it's just win counts in each category:
// speed (fastest solve) and guesses (fewest guesses).
export async function boardleRtStandings(db: Db = prisma): Promise<{ speed: RtStandingRow[]; guesses: RtStandingRow[] }> {
  const users = await boardleRtAllUsers(db);
  const agg = await db.boardleRtResult.groupBy({
    by: ["userId"],
    where: { finished: 1 },
    _count: { _all: true },
    _sum: { solved: true, speedWin: true, guessWin: true },
  });
  const byUser = new Map(agg.map((a) => [a.userId, a]));

  const base: RtStandingRow[] = users.map((u) => {
    const a = byUser.get(u.id);
    return {
      username: u.username,
      played: a?._count._all ?? 0,
      solves: a?._sum.solved ?? 0,
      speed_wins: a?._sum.speedWin ?? 0,
      guess_wins: a?._sum.guessWin ?? 0,
    };
  });

  const bySpeed = [...base].sort((x, y) =>
    x.speed_wins !== y.speed_wins ? y.speed_wins - x.speed_wins : y.solves - x.solves,
  );
  const byGuesses = [...base].sort((x, y) =>
    x.guess_wins !== y.guess_wins ? y.guess_wins - x.guess_wins : y.solves - x.solves,
  );

  return { speed: bySpeed, guesses: byGuesses };
}

export type RtMe = {
  solved: boolean;
  solved_boards: boolean[];
  guesses: BoardGuessRow[];
  guesses_used: number;
  finished: boolean;
  hints: string[];
  own_word: string | null;
  answers?: string[];
};

export type RtOpponent = {
  username: string;
  position: number;
  guesses_used: number;
  solved: boolean;
  boards: { boards: CellColor[][] }[];
  solved_boards: boolean[];
};

export type RtResultRow = {
  username: string;
  solved: boolean;
  guesses_used: number;
  speed_win: boolean;
  guess_win: boolean;
};

export type BoardleRtPayload = {
  phase: "lobby" | "voting" | "selecting" | "playing" | "results";
  round_id: number;
  elapsed: number;
  users: string[];
  active?: { username: string; ready: boolean }[];
  min_players?: number;
  players?: string[];
  num_boards?: number;
  my_position?: number | null;
  in_round?: boolean;
  deadline?: number;
  length_choices?: number[];
  my_vote?: number | null;
  voted_count?: number;
  tally?: Record<number, number>;
  word_length?: number;
  chosen_count?: number;
  i_chose?: boolean;
  max_guesses?: number;
  me?: RtMe;
  opponents?: RtOpponent[];
  results?: RtResultRow[];
  standings?: { speed: RtStandingRow[]; guesses: RtStandingRow[] };
  answers?: string[];
};

// Full payload for the polling endpoint, scoped to the requesting user.
export async function boardleRtPayload(userId: number): Promise<BoardleRtPayload> {
  // Presence heartbeat: touch (or create, defaulting to not-ready) this
  // user's lobby row *before* advancing, so this very poll can itself
  // contribute to "everyone active is ready" if that's already the case.
  // Only meaningful in the lobby — once a round starts, boardle_rt_ready
  // is empty until the room returns to the lobby.
  const preRow = await boardleRtRow();
  if (preRow.phase === "lobby") {
    await prisma.boardleRtReady.upsert({
      where: { userId },
      create: { userId, ready: 0, updatedAt: dbNow() },
      update: { updatedAt: dbNow() },
    });
  }

  await boardleRtAdvance();
  const row = await boardleRtRow();
  const phase = row.phase;
  const roundId = row.roundId;
  const elapsed = boardleRtElapsed(row);

  const payload: BoardleRtPayload = {
    phase,
    round_id: roundId,
    elapsed,
    users: (await boardleRtAllUsers(prisma)).map((u) => u.username),
  };

  if (phase === "lobby") {
    // Only whoever's lobby is actually open right now (see the heartbeat
    // above) — not every site user, and not everyone who's ever readied.
    payload.active = (await boardleRtActivePlayers(prisma)).map((a) => ({
      username: a.username,
      ready: a.ready,
    }));
    payload.min_players = BOARDLE_RT_MIN_PLAYERS;
    return payload;
  }

  const playerRows = await prisma.boardleRtPlayer.findMany({ where: { roundId }, orderBy: { position: "asc" } });
  const userRows = await prisma.user.findMany({
    where: { id: { in: playerRows.map((p) => p.userId) } },
    select: { id: true, username: true },
  });
  const nameOf = new Map(userRows.map((u) => [u.id, u.username]));
  const players = playerRows.map((p) => ({ ...p, username: nameOf.get(p.userId) ?? "" }));
  const myPosition = players.find((p) => p.userId === userId)?.position ?? null;

  payload.players = players.map((p) => p.username);
  payload.num_boards = row.numBoards ?? 0;
  payload.my_position = myPosition;
  payload.in_round = myPosition !== null;

  if (phase === "voting") {
    payload.deadline = BOARDLE_RT_VOTE_SECONDS;
    payload.length_choices = String(row.lengthChoices ?? "")
      .split(",")
      .map(Number);
    const votes = await prisma.boardleRtVote.findMany({ where: { roundId } });
    payload.my_vote = votes.find((v) => v.userId === userId)?.length ?? null;
    payload.voted_count = votes.length;
    const tally: Record<number, number> = {};
    for (const len of payload.length_choices) tally[len] = 0;
    for (const v of votes) if (v.length in tally) tally[v.length]++;
    payload.tally = tally;
    return payload;
  }

  if (phase === "selecting") {
    payload.deadline = BOARDLE_RT_SELECT_SECONDS;
    payload.word_length = row.wordLength ?? 0;
    const chosen = await prisma.boardleRtWord.findMany({ where: { roundId }, select: { position: true } });
    const chosenPositions = chosen.map((c) => c.position);
    payload.chosen_count = chosenPositions.length;
    payload.i_chose = myPosition !== null && chosenPositions.includes(myPosition);
    return payload;
  }

  // playing / results: build the board answers + my guesses, same shape as
  // daily boardleState() so the client can reuse its scoring/render logic.
  const wordRows = await prisma.boardleRtWord.findMany({ where: { roundId }, orderBy: { position: "asc" } });
  const answers: string[] = [];
  const hints: string[] = [];
  for (const r of wordRows) {
    answers[r.position] = r.word;
    hints[r.position] = r.hint;
  }
  const length = row.wordLength ?? 0;
  const numBoards = answers.length;
  const maxGuesses = boardleMaxGuesses(numBoards);
  payload.word_length = length;
  payload.max_guesses = maxGuesses;

  const myGuessRows = await prisma.boardleRtGuess.findMany({
    where: { roundId, userId },
    orderBy: { guessIndex: "asc" },
    select: { guess: true },
  });
  const myGuesses = myGuessRows.map((g) => g.guess);
  const owned = myPosition !== null ? [myPosition] : [];
  const mb = boardleUserBoards(myGuesses, answers, owned);
  const me: RtMe = {
    solved: mb.solved,
    solved_boards: mb.solvedBoards,
    guesses: mb.guesses,
    guesses_used: myGuesses.length,
    finished: mb.solved || myGuesses.length >= maxGuesses,
    hints,
    // My own board is auto-solved from the moment the round starts (I picked
    // the word), so its answer is mine to see right away — not just once the
    // whole round is finished.
    own_word: myPosition !== null ? (answers[myPosition] ?? null) : null,
  };
  if (me.finished && myPosition !== null) me.answers = [...answers];
  payload.me = me;

  // Opponents: colors only, letters stripped, unless the round is over.
  const opponents: RtOpponent[] = [];
  for (const p of players) {
    if (p.userId === userId) continue;
    const oGuessRows = await prisma.boardleRtGuess.findMany({
      where: { roundId, userId: p.userId },
      orderBy: { guessIndex: "asc" },
      select: { guess: true },
    });
    const oGuesses = oGuessRows.map((g) => g.guess);
    const oBoards = boardleUserBoards(oGuesses, answers, [p.position]);
    opponents.push({
      username: p.username,
      position: p.position,
      guesses_used: oGuesses.length,
      solved: oBoards.solved,
      boards: oBoards.guesses.map((g) => ({ boards: g.boards })),
      solved_boards: oBoards.solvedBoards,
    });
  }
  payload.opponents = opponents;

  if (phase === "playing") {
    payload.deadline = BOARDLE_RT_PLAY_SECONDS;
  }

  if (phase === "results") {
    const resultRows = await prisma.boardleRtResult.findMany({ where: { roundId } });
    const resUserRows = await prisma.user.findMany({
      where: { id: { in: resultRows.map((r) => r.userId) } },
      select: { id: true, username: true },
    });
    const resNameOf = new Map(resUserRows.map((u) => [u.id, u.username]));
    const sorted = [...resultRows].sort((a, b) =>
      a.solved !== b.solved ? b.solved - a.solved : a.guessesUsed - b.guessesUsed,
    );
    payload.results = sorted.map((r) => ({
      username: resNameOf.get(r.userId) ?? "",
      solved: r.solved === 1,
      guesses_used: r.guessesUsed,
      speed_win: r.speedWin === 1,
      guess_win: r.guessWin === 1,
    }));
    payload.standings = await boardleRtStandings();
    payload.answers = [...answers];
  }

  return payload;
}
