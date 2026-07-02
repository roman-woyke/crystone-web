// Day lifecycle + full state aggregation for fWordle, ported 1:1 from
// includes/fwordle.php (fwordleEnsureDay / fwordleFinalizeWords /
// fwordleSyncLateChoices / fwordleState). See lib/fwordle-words.ts (word
// lists), lib/fwordle-score.ts (scoring/hints) and lib/fwordle-streak.ts
// (streaks/stats) for the pieces this orchestrates.

import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { fwordleAddDays, fwordleDateOnly, fwordleToday } from "@/lib/fwordle-dates";
import {
  FWORDLE_MAX_WORDS,
  fwordleIsValidWord,
  fwordleMaxGuesses,
  fwordleRandomAnswer,
  fwordleRollLength,
  fwordleSuggestions,
} from "@/lib/fwordle-words";
import {
  fwordleParseHint,
  fwordlePlayerRank,
  fwordleUserBoards,
  type BoardGuessRow,
  type ParsedHint,
} from "@/lib/fwordle-score";
import { fwordleStats, fwordleStreakInfo, type PlayerStats } from "@/lib/fwordle-streak";
import type { FwordleDay } from "@/app/generated/prisma/client";

export { FWORDLE_MAX_WORDS, FWORDLE_MIN_LEN, FWORDLE_MAX_LEN, fwordleIsValidWord, fwordleMaxGuesses } from "@/lib/fwordle-words";
export { fwordleScore } from "@/lib/fwordle-score";
export { fwordleToday } from "@/lib/fwordle-dates";

// Fetch the day row, creating it (with a freshly rolled length) if absent.
export async function fwordleEnsureDay(date: string): Promise<FwordleDay> {
  const gameDate = fwordleDateOnly(date);
  const existing = await prisma.fwordleDay.findUnique({ where: { gameDate } });
  if (existing) return existing;
  try {
    return await prisma.fwordleDay.create({
      data: { gameDate, wordLength: fwordleRollLength(), wordsFinalized: 0 },
    });
  } catch {
    // Lost the create race — the row exists now.
    return await prisma.fwordleDay.findUniqueOrThrow({ where: { gameDate } });
  }
}

// Build (once) the answer words for a date that has arrived. The board count
// is always fixed at FWORDLE_MAX_WORDS. Each prior-day solver fills + owns a
// slot with their chosen word (else random backfill); remaining slots are
// random. Idempotent + race-safe (re-checks words_finalized under a row lock).
export async function fwordleFinalizeWords(date: string): Promise<void> {
  const day = await fwordleEnsureDay(date);
  if (day.wordsFinalized === 1) return;
  if (date > fwordleToday()) return; // future days keep collecting choices

  const len = day.wordLength;
  const prevDate = fwordleAddDays(date, -1);
  const numBoards = FWORDLE_MAX_WORDS;

  const solverRows = await prisma.fwordleResult.findMany({
    where: { gameDate: fwordleDateOnly(prevDate), solved: 1 },
    orderBy: { solvedAt: "asc" },
    take: FWORDLE_MAX_WORDS,
    include: { user: { select: { username: true } } },
  });
  solverRows.sort((a, b) => fwordlePlayerRank(a.user.username) - fwordlePlayerRank(b.user.username));
  const slotUsers = solverRows.map((r) => r.userId);

  type WordEntry = { word: string; source: "chosen" | "random"; chooserId: number | null; hint: string | null };
  const words: WordEntry[] = [];
  const used: string[] = [];

  for (let pos = 0; pos < numBoards; pos++) {
    const uid = slotUsers[pos];
    if (uid !== undefined) {
      const choice = await prisma.fwordleChoice.findUnique({
        where: { gameDate_userId: { gameDate: fwordleDateOnly(date), userId: uid } },
      });
      const chosen = choice ? choice.word.toLowerCase() : null;
      // Keep a valid chosen word as-is — duplicates between players are
      // allowed; only random backfill dedupes.
      if (chosen !== null && fwordleIsValidWord(chosen, len)) {
        used.push(chosen);
        words.push({ word: chosen, source: "chosen", chooserId: uid, hint: choice?.hint ?? null });
        continue;
      }
    }
    const w = fwordleRandomAnswer(len, used);
    if (w === null) continue;
    used.push(w);
    words.push({ word: w, source: "random", chooserId: null, hint: null });
  }

  await prisma.$transaction(async (tx) => {
    const rows = await tx.$queryRaw<
      { words_finalized: number }[]
    >`SELECT words_finalized FROM fwordle_days WHERE game_date = ${date} FOR UPDATE`;
    if (rows[0] && Number(rows[0].words_finalized) === 1) return;

    for (let pos = 0; pos < words.length; pos++) {
      const w = words[pos];
      await tx.fwordleWord.create({
        data: {
          gameDate: fwordleDateOnly(date),
          position: pos,
          word: w.word,
          source: w.source,
          chooserId: w.chooserId,
          hint: w.hint,
        },
      });
    }
    await tx.fwordleDay.update({ where: { gameDate: fwordleDateOnly(date) }, data: { wordsFinalized: 1 } });
  });
}

// If the day is finalized, sync any fwordle_choices that differ from
// fwordle_words (covers late picks + any race between the choose API write
// and finalization). Board position is looked up by player rank, not
// chooser_id, since the slot may have been filled by a random backfill.
export async function fwordleSyncLateChoices(date: string): Promise<void> {
  const day = await prisma.fwordleDay.findUnique({ where: { gameDate: fwordleDateOnly(date) } });
  if (!day || day.wordsFinalized !== 1) return;

  const yesterday = fwordleAddDays(date, -1);
  const solverRows = await prisma.fwordleResult.findMany({
    where: { gameDate: fwordleDateOnly(yesterday), solved: 1 },
    orderBy: { solvedAt: "asc" },
    take: FWORDLE_MAX_WORDS,
    include: { user: { select: { username: true } } },
  });
  solverRows.sort((a, b) => fwordlePlayerRank(a.user.username) - fwordlePlayerRank(b.user.username));

  for (let idx = 0; idx < solverRows.length; idx++) {
    const uid = solverRows[idx].userId;
    const choice = await prisma.fwordleChoice.findUnique({
      where: { gameDate_userId: { gameDate: fwordleDateOnly(date), userId: uid } },
    });
    if (!choice) continue;
    await prisma.fwordleWord.updateMany({
      where: { gameDate: fwordleDateOnly(date), position: idx },
      data: { word: choice.word, hint: choice.hint ?? null, source: "chosen", chooserId: uid },
    });
  }
}

// Did this user buy the armor joker today? (it grants +1 guess overall.)
export async function fwordleHasArmor(date: string, userId: number): Promise<boolean> {
  const hint = await prisma.fwordleHint.findUnique({
    where: { gameDate_userId_type: { gameDate: fwordleDateOnly(date), userId, type: "armor" } },
  });
  return !!hint;
}

// Board positions whose word this user chose (auto-solved for them).
export async function fwordleOwnedPositions(date: string, userId: number): Promise<number[]> {
  const rows = await prisma.fwordleWord.findMany({
    where: { gameDate: fwordleDateOnly(date), chooserId: userId },
    select: { position: true },
  });
  return rows.map((r) => r.position);
}

// Is this user among the first MAX_WORDS solvers of the day (-> may pick a word)?
export async function fwordleChoiceEligible(date: string, userId: number): Promise<boolean> {
  const rows = await prisma.fwordleResult.findMany({
    where: { gameDate: fwordleDateOnly(date), solved: 1 },
    orderBy: { solvedAt: "asc" },
    take: FWORDLE_MAX_WORDS,
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

export type FwordleState = {
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
export async function fwordleState(date: string, userId: number): Promise<FwordleState> {
  const day = await fwordleEnsureDay(date);
  await fwordleFinalizeWords(date);
  await fwordleSyncLateChoices(date);

  const len = day.wordLength;

  const wordRows = await prisma.fwordleWord.findMany({
    where: { gameDate: fwordleDateOnly(date) },
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
  const maxGuesses = fwordleMaxGuesses(numBoards);

  const ownedOf = (uid: number): number[] => {
    const own: number[] = [];
    chooser.forEach((cid, pos) => {
      if (cid === uid) own.push(pos);
    });
    return own;
  };

  const guessRows = await prisma.fwordleGuess.findMany({
    where: { gameDate: fwordleDateOnly(date) },
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

  const resultRows = await prisma.fwordleResult.findMany({ where: { gameDate: fwordleDateOnly(date) } });
  const results = new Map(resultRows.map((r) => [r.userId, r]));

  const myUser = await prisma.user.findUnique({ where: { id: userId }, select: { username: true } });
  const myName = myUser?.username ?? "";

  const hintRows = await prisma.fwordleHint.findMany({ where: { gameDate: fwordleDateOnly(date) } });
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
  const mb = fwordleUserBoards(myGuesses, answers, myOwned);
  const myUsed = myGuesses.length;
  const myRes = results.get(userId);
  const myMax = maxGuessesOf(userId);
  const myFinished = myRes ? !!myRes.finished : mb.solved || myUsed >= myMax;

  // Persist a result when solved/finished isn't recorded yet — covers an
  // auto-win on a board you chose (no guess ever submitted).
  if (myFinished && (!myRes || myRes.finished !== 1)) {
    const solvedAt = myRes?.solvedAt ?? (mb.solved ? dbNow() : null);
    await prisma.fwordleResult.upsert({
      where: { gameDate_userId: { gameDate: fwordleDateOnly(date), userId } },
      create: {
        gameDate: fwordleDateOnly(date),
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
  const myInfo = await fwordleStreakInfo(date, userId);
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
    hints: myHints.map(fwordleParseHint),
    hint_types_used: [...new Set(myHints.map((h) => h.type))],
    jokers_used: myHints.length,
    jokers_total: 3,
  };

  // ── Opponents (colors only, never letters) ──
  const opponents: OpponentState[] = [];
  for (const [uid, info] of byUser) {
    if (uid === userId) continue;
    const b = fwordleUserBoards(info.guesses, answers, ownedOf(uid));
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
  const tomorrow = fwordleAddDays(date, 1);
  const yesterday = fwordleAddDays(date, -1);
  const choose: ChooseInfo = {
    eligible: false,
    already: null,
    already_hint: null,
    tomorrow_length: null,
    suggestions: [],
    for_today: false,
  };

  if (await fwordleChoiceEligible(date, userId)) {
    // Normal: solved today -> pick for tomorrow.
    const tday = await fwordleEnsureDay(tomorrow);
    const tlen = tday.wordLength;
    choose.eligible = true;
    choose.tomorrow_length = tlen;
    choose.suggestions = fwordleSuggestions(tlen, userId, tomorrow);

    const c = await prisma.fwordleChoice.findUnique({
      where: { gameDate_userId: { gameDate: fwordleDateOnly(tomorrow), userId } },
    });
    choose.already = c?.word ?? null;
    choose.already_hint = c?.hint ?? null;
  } else if (await fwordleChoiceEligible(yesterday, userId)) {
    // Late pick: solved yesterday, today has no guesses yet -> still allow
    // setting today's word.
    const guessCountToday = await prisma.fwordleGuess.count({ where: { gameDate: fwordleDateOnly(date) } });
    if (guessCountToday === 0) {
      const tday = await fwordleEnsureDay(date);
      const tlen = tday.wordLength;
      choose.eligible = true;
      choose.for_today = true;
      choose.tomorrow_length = tlen;
      choose.suggestions = fwordleSuggestions(tlen, userId, date);

      const c = await prisma.fwordleChoice.findUnique({
        where: { gameDate_userId: { gameDate: fwordleDateOnly(date), userId } },
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
    stats: await fwordleStats(date),
  };
}
