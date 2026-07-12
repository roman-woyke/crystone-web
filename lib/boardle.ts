// Day lifecycle + full state aggregation for Boardle, ported 1:1 from
// includes/boardle.php (boardleEnsureDay / boardleFinalizeWords /
// boardleSyncLateChoices / boardleState). See lib/boardle-words.ts (word
// lists), lib/boardle-score.ts (scoring/hints) and lib/boardle-streak.ts
// (streaks/stats) for the pieces this orchestrates.

import { prisma } from "@/lib/prisma";
import { dbNow, toDbDateStr } from "@/lib/dates";
import { boardleAddDays, boardleDateOnly, boardleToday } from "@/lib/boardle-dates";
import {
  BOARDLE_MAX_WORDS,
  boardleIsValidWord,
  boardleMaxGuesses,
  boardleRandomAnswer,
  boardleRollLength,
  boardleSuggestions,
} from "@/lib/boardle-words";
import {
  boardleParseHint,
  boardlePlayerRank,
  boardleUserBoards,
  type BoardGuessRow,
  type ParsedHint,
} from "@/lib/boardle-score";
import { boardleStats, boardleStreakInfo, type PlayerStats } from "@/lib/boardle-streak";
import type { BoardleDay } from "@/app/generated/prisma/client";

export { BOARDLE_MAX_WORDS, BOARDLE_MIN_LEN, BOARDLE_MAX_LEN, boardleIsValidWord, boardleMaxGuesses } from "@/lib/boardle-words";
export { boardleScore } from "@/lib/boardle-score";
export { boardleToday } from "@/lib/boardle-dates";

// ── History / archive navigation ─────────────────────────────────────────────
// The archive goes back to the first day a puzzle ever existed (the earliest
// `boardle_days` row) and never further forward than the server's real today —
// a day beyond today has no rolled length/finalized words yet.

export async function boardleEarliestDate(): Promise<string> {
  const min = await prisma.boardleDay.aggregate({ _min: { gameDate: true } });
  return min._min.gameDate ? toDbDateStr(min._min.gameDate) : boardleToday();
}

// Validate + clamp a requested `date` param into [earliest, today]. Anything
// missing, malformed, or out of range silently falls back to today rather than
// erroring — the archive nav never needs to reject a request outright.
export async function boardleResolveDate(raw: unknown): Promise<string> {
  const today = boardleToday();
  if (typeof raw !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(raw)) return today;

  const d = boardleDateOnly(raw);
  if (Number.isNaN(d.getTime()) || toDbDateStr(d) !== raw) return today;
  if (raw > today) return today;

  const earliest = await boardleEarliestDate();
  return raw < earliest ? earliest : raw;
}

// Fetch the day row, creating it (with a freshly rolled length) if absent.
export async function boardleEnsureDay(date: string): Promise<BoardleDay> {
  const gameDate = boardleDateOnly(date);
  const existing = await prisma.boardleDay.findUnique({ where: { gameDate } });
  if (existing) return existing;
  try {
    return await prisma.boardleDay.create({
      data: { gameDate, wordLength: boardleRollLength(), wordsFinalized: 0 },
    });
  } catch {
    // Lost the create race — the row exists now.
    return await prisma.boardleDay.findUniqueOrThrow({ where: { gameDate } });
  }
}

// Build (once) the answer words for a date that has arrived. The board count
// is always fixed at BOARDLE_MAX_WORDS. Each prior-day solver fills + owns a
// slot with their chosen word (else random backfill); remaining slots are
// random. Idempotent + race-safe (re-checks words_finalized under a row lock).
export async function boardleFinalizeWords(date: string): Promise<void> {
  const day = await boardleEnsureDay(date);
  if (day.wordsFinalized === 1) return;
  if (date > boardleToday()) return; // future days keep collecting choices

  const len = day.wordLength;
  const prevDate = boardleAddDays(date, -1);
  const numBoards = BOARDLE_MAX_WORDS;

  const solverRows = await prisma.boardleResult.findMany({
    where: { gameDate: boardleDateOnly(prevDate), solved: 1 },
    orderBy: { solvedAt: "asc" },
    take: BOARDLE_MAX_WORDS,
    include: { user: { select: { username: true } } },
  });
  solverRows.sort((a, b) => boardlePlayerRank(a.user.username) - boardlePlayerRank(b.user.username));
  const slotUsers = solverRows.map((r) => r.userId);

  type WordEntry = { word: string; source: "chosen" | "random"; chooserId: number | null; hint: string | null };
  const words: WordEntry[] = [];
  const used: string[] = [];

  for (let pos = 0; pos < numBoards; pos++) {
    const uid = slotUsers[pos];
    if (uid !== undefined) {
      const choice = await prisma.boardleChoice.findUnique({
        where: { gameDate_userId: { gameDate: boardleDateOnly(date), userId: uid } },
      });
      const chosen = choice ? choice.word.toLowerCase() : null;
      // Keep a valid chosen word as-is — duplicates between players are
      // allowed; only random backfill dedupes.
      if (chosen !== null && boardleIsValidWord(chosen, len)) {
        used.push(chosen);
        words.push({ word: chosen, source: "chosen", chooserId: uid, hint: choice?.hint ?? null });
        continue;
      }
    }
    const w = boardleRandomAnswer(len, used);
    if (w === null) continue;
    used.push(w);
    words.push({ word: w, source: "random", chooserId: null, hint: null });
  }

  await prisma.$transaction(async (tx) => {
    const rows = await tx.$queryRaw<
      { words_finalized: number }[]
    >`SELECT words_finalized FROM boardle_days WHERE game_date = ${date} FOR UPDATE`;
    if (rows[0] && Number(rows[0].words_finalized) === 1) return;

    for (let pos = 0; pos < words.length; pos++) {
      const w = words[pos];
      await tx.boardleWord.create({
        data: {
          gameDate: boardleDateOnly(date),
          position: pos,
          word: w.word,
          source: w.source,
          chooserId: w.chooserId,
          hint: w.hint,
        },
      });
    }
    await tx.boardleDay.update({ where: { gameDate: boardleDateOnly(date) }, data: { wordsFinalized: 1 } });
  });
}

// If the day is finalized, sync any boardle_choices that differ from
// boardle_words (covers late picks made before this fix, and any future race
// between the choose API write and the finalization). Any player who chose a
// word for today gets patched in — reusing a slot they already own, otherwise
// claiming the lowest-numbered still-random (unclaimed) slot — regardless of
// whether they solved yesterday.
export async function boardleSyncLateChoices(date: string): Promise<void> {
  const day = await prisma.boardleDay.findUnique({ where: { gameDate: boardleDateOnly(date) } });
  if (!day || day.wordsFinalized !== 1) return;

  const slots = await prisma.boardleWord.findMany({
    where: { gameDate: boardleDateOnly(date) },
    orderBy: { position: "asc" },
  });
  const choices = await prisma.boardleChoice.findMany({
    where: { gameDate: boardleDateOnly(date) },
  });

  for (const choice of choices) {
    const uid = choice.userId;
    const word = choice.word.toLowerCase();
    const hint = choice.hint ?? null;

    const ownSlot = slots.find((s) => s.chooserId === uid);
    if (ownSlot) {
      if (ownSlot.word.toLowerCase() !== word) {
        await prisma.boardleWord.updateMany({
          where: { gameDate: boardleDateOnly(date), position: ownSlot.position },
          data: { word, hint, source: "chosen", chooserId: uid },
        });
        ownSlot.word = word;
      }
      continue;
    }

    const freeSlot = slots.find((s) => s.source === "random");
    if (freeSlot) {
      await prisma.boardleWord.updateMany({
        where: { gameDate: boardleDateOnly(date), position: freeSlot.position },
        data: { word, hint, source: "chosen", chooserId: uid },
      });
      freeSlot.source = "chosen";
      freeSlot.chooserId = uid;
      freeSlot.word = word;
    }
  }
}

// Did this user buy the armor joker today? (it grants +1 guess overall.)
export async function boardleHasArmor(date: string, userId: number): Promise<boolean> {
  const hint = await prisma.boardleHint.findUnique({
    where: { gameDate_userId_type: { gameDate: boardleDateOnly(date), userId, type: "armor" } },
  });
  return !!hint;
}

// Board positions whose word this user chose (auto-solved for them).
export async function boardleOwnedPositions(date: string, userId: number): Promise<number[]> {
  const rows = await prisma.boardleWord.findMany({
    where: { gameDate: boardleDateOnly(date), chooserId: userId },
    select: { position: true },
  });
  return rows.map((r) => r.position);
}

// Is this user among the first MAX_WORDS solvers of the day (-> may pick a word)?
export async function boardleChoiceEligible(date: string, userId: number): Promise<boolean> {
  const rows = await prisma.boardleResult.findMany({
    where: { gameDate: boardleDateOnly(date), solved: 1 },
    orderBy: { solvedAt: "asc" },
    take: BOARDLE_MAX_WORDS,
    select: { userId: true },
  });
  return rows.some((r) => r.userId === userId);
}

export type MeState = {
  username: string;
  finished: boolean;
  solved: boolean;
  guesses_used: number;
  max_guesses: number;
  base_max_guesses: number;
  armor: boolean;
  streak: number;
  freezes: number;
  can_freeze_joker: boolean;
  free_joker: boolean;
  solved_boards: boolean[];
  owned: number[];
  guesses: BoardGuessRow[];
  answers: (string | null)[];
  hints: ParsedHint[];
  hint_types_used: string[];
  jokers_used: number;
  jokers_total: number;
};

export type OpponentState = {
  username: string;
  finished: boolean;
  solved: boolean;
  guesses_used: number;
  max_guesses: number;
  solved_boards: boolean[];
  owned: number[];
  jokers_used: number;
  guesses: { boards: BoardGuessRow["boards"] }[];
};

export type ChooseInfo = {
  eligible: boolean;
  already: string | null;
  already_hint: string | null;
  tomorrow_length: number | null;
  suggestions: string[];
  for_today: boolean;
};

// The client-facing payload: boardleState() plus the archive bounds the
// day-nav needs. Built by the page/state route; guess/joker responses omit
// the extra fields and the client keeps its previous bounds.
export type BoardleWireState = BoardleState & { today: string; earliest_date: string };

export type BoardleState = {
  date: string;
  length: number;
  num_boards: number;
  max_guesses: number;
  me: MeState;
  opponents: OpponentState[];
  choose: ChooseInfo;
  board_hints: (string | null)[];
  stats: PlayerStats[];
};

// Everything the page/poll needs. My board carries letters; opponents' boards
// carry colors only (letters are never exposed). Answers are revealed only
// once *I* have finished (won or out of guesses).
export async function boardleState(date: string, userId: number): Promise<BoardleState> {
  const day = await boardleEnsureDay(date);
  await boardleFinalizeWords(date);
  await boardleSyncLateChoices(date);

  const len = day.wordLength;

  const wordRows = await prisma.boardleWord.findMany({
    where: { gameDate: boardleDateOnly(date) },
    orderBy: { position: "asc" },
  });
  const answers: string[] = [];
  const chooser: (number | null)[] = [];
  const boardHints: (string | null)[] = [];
  for (const r of wordRows) {
    answers[r.position] = r.word;
    chooser[r.position] = r.chooserId;
    boardHints[r.position] = r.hint && r.hint !== "" ? r.hint : null;
  }
  const numBoards = answers.length;
  const maxGuesses = boardleMaxGuesses(numBoards);

  const ownedOf = (uid: number): number[] => {
    const own: number[] = [];
    chooser.forEach((cid, pos) => {
      if (cid === uid) own.push(pos);
    });
    return own;
  };

  const guessRows = await prisma.boardleGuess.findMany({
    where: { gameDate: boardleDateOnly(date) },
    orderBy: [{ userId: "asc" }, { guessIndex: "asc" }],
    include: { user: { select: { username: true } } },
  });
  const byUser = new Map<number, { username: string; guesses: string[] }>();
  for (const r of guessRows) {
    let entry = byUser.get(r.userId);
    if (!entry) {
      entry = { username: r.user.username, guesses: [] };
      byUser.set(r.userId, entry);
    }
    entry.guesses.push(r.guess);
  }

  const resultRows = await prisma.boardleResult.findMany({ where: { gameDate: boardleDateOnly(date) } });
  const results = new Map(resultRows.map((r) => [r.userId, r]));

  const myUser = await prisma.user.findUnique({ where: { id: userId }, select: { username: true } });
  const myName = myUser?.username ?? "";

  const hintRows = await prisma.boardleHint.findMany({ where: { gameDate: boardleDateOnly(date) } });
  const hintsByUser = new Map<number, typeof hintRows>();
  for (const h of hintRows) {
    const arr = hintsByUser.get(h.userId) ?? [];
    arr.push(h);
    hintsByUser.set(h.userId, arr);
  }
  const armorOf = (uid: number) => (hintsByUser.get(uid) ?? []).some((h) => h.type === "armor");
  const maxGuessesOf = (uid: number) => maxGuesses + (armorOf(uid) ? 1 : 0);

  // ── Me (computed even with zero guesses, so an owned board still shows) ──
  const myGuesses = byUser.get(userId)?.guesses ?? [];
  const myOwned = ownedOf(userId);
  const mb = boardleUserBoards(myGuesses, answers, myOwned);
  const myUsed = myGuesses.length;
  const myRes = results.get(userId);
  const myMax = maxGuessesOf(userId);
  const myFinished = myRes ? !!myRes.finished : mb.solved || myUsed >= myMax;

  // Persist a result when solved/finished isn't recorded yet — covers an
  // auto-win on a board you chose (no guess ever submitted).
  if (myFinished && (!myRes || myRes.finished !== 1)) {
    const solvedAt = myRes?.solvedAt ?? (mb.solved ? dbNow() : null);
    await prisma.boardleResult.upsert({
      where: { gameDate_userId: { gameDate: boardleDateOnly(date), userId } },
      create: {
        gameDate: boardleDateOnly(date),
        userId,
        finished: 1,
        solved: mb.solved ? 1 : 0,
        guessesUsed: myUsed,
        solvedAt,
      },
      update: { finished: 1, solved: mb.solved ? 1 : 0, guessesUsed: myUsed, solvedAt },
    });
  }

  // Reveal answers for boards I own, plus every board once I'm finished.
  const reveal: (string | null)[] = [];
  for (let p = 0; p < numBoards; p++) {
    reveal[p] = myFinished || myOwned.includes(p) ? answers[p] : null;
  }

  const myHints = hintsByUser.get(userId) ?? [];
  const myInfo = await boardleStreakInfo(date, userId);
  const myStreak = myInfo.effective;
  // Players with no streak to spend still get one joker free per day.
  const freeJoker = myStreak < 1 && myHints.length === 0;
  const canFreezeJoker = myInfo.freezes >= 1 && !myFinished;

  const me: MeState = {
    username: myName,
    finished: myFinished,
    solved: mb.solved,
    guesses_used: myUsed,
    max_guesses: myMax,
    base_max_guesses: maxGuesses,
    armor: armorOf(userId),
    streak: myStreak,
    freezes: myInfo.freezes,
    can_freeze_joker: canFreezeJoker,
    free_joker: freeJoker,
    solved_boards: mb.solvedBoards,
    owned: myOwned,
    guesses: mb.guesses,
    answers: reveal,
    hints: myHints.map(boardleParseHint),
    hint_types_used: [...new Set(myHints.map((h) => h.type))],
    jokers_used: myHints.length,
    jokers_total: 3,
  };

  // ── Opponents (colors only, never letters) ──
  const opponents: OpponentState[] = [];
  for (const [uid, info] of byUser) {
    if (uid === userId) continue;
    const b = boardleUserBoards(info.guesses, answers, ownedOf(uid));
    const used = info.guesses.length;
    const res = results.get(uid);
    const oppMax = maxGuessesOf(uid);
    const finished = res ? !!res.finished : b.solved || used >= oppMax;
    opponents.push({
      username: info.username,
      finished,
      solved: b.solved,
      guesses_used: used,
      max_guesses: oppMax,
      solved_boards: b.solvedBoards,
      owned: ownedOf(uid),
      jokers_used: (hintsByUser.get(uid) ?? []).length,
      guesses: b.guesses.map((g) => ({ boards: g.boards })),
    });
  }

  // Tomorrow's word pick (only once I've solved and earned a slot).
  const tomorrow = boardleAddDays(date, 1);
  const choose: ChooseInfo = {
    eligible: false,
    already: null,
    already_hint: null,
    tomorrow_length: null,
    suggestions: [],
    for_today: false,
  };

  if (await boardleChoiceEligible(date, userId)) {
    // Normal: solved today -> pick for tomorrow.
    const tday = await boardleEnsureDay(tomorrow);
    const tlen = tday.wordLength;
    choose.eligible = true;
    choose.tomorrow_length = tlen;
    choose.suggestions = boardleSuggestions(tlen, userId, tomorrow);

    const c = await prisma.boardleChoice.findUnique({
      where: { gameDate_userId: { gameDate: boardleDateOnly(tomorrow), userId } },
    });
    choose.already = c?.word ?? null;
    choose.already_hint = c?.hint ?? null;
  } else {
    // Late pick: today has no guesses yet -> allow setting today's word,
    // whether or not this player solved yesterday. Any board slot that wasn't
    // already claimed by a real pick (a random backfill) is still up for
    // grabs — see the slot-claiming logic in the choose route.
    const guessCountToday = await prisma.boardleGuess.count({ where: { gameDate: boardleDateOnly(date) } });
    if (guessCountToday === 0) {
      const tday = await boardleEnsureDay(date);
      const tlen = tday.wordLength;
      choose.eligible = true;
      choose.for_today = true;
      choose.tomorrow_length = tlen;
      choose.suggestions = boardleSuggestions(tlen, userId, date);

      const c = await prisma.boardleChoice.findUnique({
        where: { gameDate_userId: { gameDate: boardleDateOnly(date), userId } },
      });
      choose.already = c?.word ?? null;
      choose.already_hint = c?.hint ?? null;
    }
  }

  const boardHintsArr: (string | null)[] = [];
  for (let i = 0; i < numBoards; i++) boardHintsArr.push(boardHints[i] ?? null);

  return {
    date,
    length: len,
    num_boards: numBoards,
    max_guesses: maxGuesses,
    me,
    opponents,
    choose,
    board_hints: boardHintsArr,
    // Always today's standing, never the viewed day's — browsing an old
    // day to catch up or replay a result shouldn't make the streak/avg
    // panel look like it's showing history too.
    stats: await boardleStats(boardleToday()),
  };
}
