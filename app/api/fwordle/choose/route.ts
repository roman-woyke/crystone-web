import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { fwordleAddDays, fwordleDateOnly } from "@/lib/fwordle-dates";
import { fwordleChoiceEligible, fwordleEnsureDay, fwordleToday } from "@/lib/fwordle";
import { FWORDLE_MAX_WORDS, fwordleIsValidWord } from "@/lib/fwordle-words";
import { fwordlePlayerRank } from "@/lib/fwordle-score";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const date = fwordleToday();
  const tomorrow = fwordleAddDays(date, 1);
  const yesterday = fwordleAddDays(date, -1);

  // Late-pick: solved yesterday, today has no guesses yet -> allow setting today's word.
  let pickingForToday = false;
  if (!(await fwordleChoiceEligible(date, userId)) && (await fwordleChoiceEligible(yesterday, userId))) {
    const guessCountToday = await prisma.fwordleGuess.count({ where: { gameDate: fwordleDateOnly(date) } });
    if (guessCountToday === 0) pickingForToday = true;
  }

  if (!pickingForToday && !(await fwordleChoiceEligible(date, userId))) {
    return new NextResponse("Solve today's fWordle first to set a word.", { status: 403 });
  }

  const targetDate = pickingForToday ? date : tomorrow;
  const tday = await fwordleEnsureDay(targetDate);
  const tlen = tday.wordLength;

  // For tomorrow's normal pick, respect the finalized lock. For today's late
  // pick we patch fwordle_words directly after saving.
  if (!pickingForToday && tday.wordsFinalized === 1) {
    return new NextResponse("Tomorrow's words are already locked in.", { status: 409 });
  }

  const body = await request.json().catch(() => ({}));
  const word = String(body.word ?? "").trim().toLowerCase();
  if (!fwordleIsValidWord(word, tlen)) {
    return new NextResponse(`Pick a valid ${tlen}-letter word.`, { status: 422 });
  }

  const hintRaw = String(body.hint ?? "").trim().slice(0, 500);
  const hint = hintRaw === "" ? null : hintRaw;

  // Duplicates between players are allowed — rejecting a taken word would leak
  // that someone else already picked it (spoiling their word).
  await prisma.fwordleChoice.upsert({
    where: { gameDate_userId: { gameDate: fwordleDateOnly(targetDate), userId } },
    create: { gameDate: fwordleDateOnly(targetDate), userId, word, hint },
    update: { word, hint },
  });

  // If today's words are already finalized, patch fwordle_words directly so
  // the live game sees the updated word before anyone guesses. The board
  // position is looked up by player rank (not chooser_id) because the slot
  // may have been filled with a random backfill if the user hadn't picked a
  // word before the day started.
  if (pickingForToday && tday.wordsFinalized === 1) {
    const solverRows = await prisma.fwordleResult.findMany({
      where: { gameDate: fwordleDateOnly(yesterday), solved: 1 },
      orderBy: { solvedAt: "asc" },
      take: FWORDLE_MAX_WORDS,
      include: { user: { select: { username: true } } },
    });
    solverRows.sort((a, b) => fwordlePlayerRank(a.user.username) - fwordlePlayerRank(b.user.username));
    const userPos = solverRows.findIndex((r) => r.userId === userId);
    if (userPos !== -1) {
      await prisma.fwordleWord.updateMany({
        where: { gameDate: fwordleDateOnly(date), position: userPos },
        data: { word, hint, source: "chosen", chooserId: userId },
      });
    }
  }

  return NextResponse.json({ ok: true, word, hint, tomorrow_length: tlen });
}
