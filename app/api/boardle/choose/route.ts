import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleAddDays, boardleDateOnly } from "@/lib/boardle-dates";
import { boardleChoiceEligible, boardleEnsureDay, boardleToday } from "@/lib/boardle";
import { BOARDLE_MAX_WORDS, boardleIsValidWord } from "@/lib/boardle-words";
import { boardlePlayerRank } from "@/lib/boardle-score";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const date = boardleToday();
  const tomorrow = boardleAddDays(date, 1);
  const yesterday = boardleAddDays(date, -1);

  // Late-pick: solved yesterday, today has no guesses yet -> allow setting today's word.
  let pickingForToday = false;
  if (!(await boardleChoiceEligible(date, userId)) && (await boardleChoiceEligible(yesterday, userId))) {
    const guessCountToday = await prisma.boardleGuess.count({ where: { gameDate: boardleDateOnly(date) } });
    if (guessCountToday === 0) pickingForToday = true;
  }

  if (!pickingForToday && !(await boardleChoiceEligible(date, userId))) {
    return new NextResponse("Solve today's Boardle first to set a word.", { status: 403 });
  }

  const targetDate = pickingForToday ? date : tomorrow;
  const tday = await boardleEnsureDay(targetDate);
  const tlen = tday.wordLength;

  // For tomorrow's normal pick, respect the finalized lock. For today's late
  // pick we patch boardle_words directly after saving.
  if (!pickingForToday && tday.wordsFinalized === 1) {
    return new NextResponse("Tomorrow's words are already locked in.", { status: 409 });
  }

  const body = await request.json().catch(() => ({}));
  const word = String(body.word ?? "").trim().toLowerCase();
  if (!boardleIsValidWord(word, tlen)) {
    return new NextResponse(`Pick a valid ${tlen}-letter word.`, { status: 422 });
  }

  const hintRaw = String(body.hint ?? "").trim().slice(0, 500);
  const hint = hintRaw === "" ? null : hintRaw;

  // Duplicates between players are allowed — rejecting a taken word would leak
  // that someone else already picked it (spoiling their word).
  await prisma.boardleChoice.upsert({
    where: { gameDate_userId: { gameDate: boardleDateOnly(targetDate), userId } },
    create: { gameDate: boardleDateOnly(targetDate), userId, word, hint },
    update: { word, hint },
  });

  // If today's words are already finalized, patch boardle_words directly so
  // the live game sees the updated word before anyone guesses. The board
  // position is looked up by player rank (not chooser_id) because the slot
  // may have been filled with a random backfill if the user hadn't picked a
  // word before the day started.
  if (pickingForToday && tday.wordsFinalized === 1) {
    const solverRows = await prisma.boardleResult.findMany({
      where: { gameDate: boardleDateOnly(yesterday), solved: 1 },
      orderBy: { solvedAt: "asc" },
      take: BOARDLE_MAX_WORDS,
      include: { user: { select: { username: true } } },
    });
    solverRows.sort((a, b) => boardlePlayerRank(a.user.username) - boardlePlayerRank(b.user.username));
    const userPos = solverRows.findIndex((r) => r.userId === userId);
    if (userPos !== -1) {
      await prisma.boardleWord.updateMany({
        where: { gameDate: boardleDateOnly(date), position: userPos },
        data: { word, hint, source: "chosen", chooserId: userId },
      });
    }
  }

  return NextResponse.json({ ok: true, word, hint, tomorrow_length: tlen });
}
