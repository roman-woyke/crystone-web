import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleAddDays, boardleDateOnly } from "@/lib/boardle-dates";
import { boardleChoiceEligible, boardleEnsureDay, boardleToday } from "@/lib/boardle";
import { boardleIsValidWord } from "@/lib/boardle-words";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const date = boardleToday();
  const tomorrow = boardleAddDays(date, 1);

  // Late-pick: today has no guesses yet -> allow setting today's word,
  // whether or not this player solved yesterday.
  let pickingForToday = false;
  if (!(await boardleChoiceEligible(date, userId))) {
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
  // the live game sees the updated word before anyone guesses. Reuse a slot
  // this user already owns (changing an earlier late pick), otherwise claim
  // the lowest-numbered still-random (unclaimed) slot — this works whether or
  // not the player solved yesterday, since a random slot exists precisely
  // when nobody has claimed it yet.
  if (pickingForToday && tday.wordsFinalized === 1) {
    const slots = await prisma.boardleWord.findMany({
      where: { gameDate: boardleDateOnly(date) },
      orderBy: { position: "asc" },
    });
    const targetPos =
      slots.find((s) => s.chooserId === userId)?.position ?? slots.find((s) => s.source === "random")?.position;
    if (targetPos !== undefined) {
      await prisma.boardleWord.updateMany({
        where: { gameDate: boardleDateOnly(date), position: targetPos },
        data: { word, hint, source: "chosen", chooserId: userId },
      });
    }
  }

  return NextResponse.json({ ok: true, word, hint, tomorrow_length: tlen });
}
