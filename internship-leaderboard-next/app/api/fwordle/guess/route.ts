import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { fwordleDateOnly } from "@/lib/fwordle-dates";
import { fwordleHasArmor, fwordleMaxGuesses, fwordleOwnedPositions, fwordleState, fwordleToday } from "@/lib/fwordle";
import { FWORDLE_MIN_LEN, fwordleIsValidWord } from "@/lib/fwordle-words";
import { fwordleUserBoards } from "@/lib/fwordle-score";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);
  const date = fwordleToday();
  const gameDate = fwordleDateOnly(date);

  const day = await prisma.fwordleDay.findUnique({ where: { gameDate } });
  const len = day?.wordLength ?? 0;

  const numBoards = await prisma.fwordleWord.count({ where: { gameDate } });
  const maxGuesses = fwordleMaxGuesses(numBoards) + ((await fwordleHasArmor(date, userId)) ? 1 : 0);

  // Already finished today? No more guesses.
  const result = await prisma.fwordleResult.findUnique({ where: { gameDate_userId: { gameDate, userId } } });
  if (result && result.finished === 1) {
    return new NextResponse("You're already done for today.", { status: 409 });
  }

  const used = await prisma.fwordleGuess.count({ where: { gameDate, userId } });
  if (used >= maxGuesses) {
    return new NextResponse("No guesses left.", { status: 409 });
  }

  // ── Validate the submitted word + position ──
  const body = await request.json().catch(() => ({}));
  const word = String(body.word ?? "").trim().toLowerCase();
  const offset = Number(body.offset);
  const wl = word.length;

  if (!/^[a-z]+$/.test(word) || wl < FWORDLE_MIN_LEN || wl > len) {
    return new NextResponse(`A guess must be a word of ${FWORDLE_MIN_LEN}–${len} letters.`, { status: 400 });
  }
  if (!Number.isInteger(offset) || offset < 0 || offset + wl > len) {
    return new NextResponse("Invalid word position.", { status: 400 });
  }
  if (!fwordleIsValidWord(word, wl)) {
    return new NextResponse("Not in word list.", { status: 422 });
  }

  // Encode the placement as an L-wide string ('_' = blank cell).
  const guess = "_".repeat(offset) + word + "_".repeat(len - offset - wl);

  // Append the guess (race-safe via the unique (date,user,index) key).
  try {
    await prisma.fwordleGuess.create({
      data: { gameDate, userId, guessIndex: used, guess, createdAt: dbNow() },
    });
  } catch {
    return new NextResponse("Guess conflict — reload and try again.", { status: 409 });
  }

  // Recompute my standing across every board.
  const answerRows = await prisma.fwordleWord.findMany({
    where: { gameDate },
    orderBy: { position: "asc" },
    select: { word: true },
  });
  const answers = answerRows.map((r) => r.word);

  const myGuessRows = await prisma.fwordleGuess.findMany({
    where: { gameDate, userId },
    orderBy: { guessIndex: "asc" },
    select: { guess: true },
  });
  const myGuesses = myGuessRows.map((r) => r.guess);

  const owned = await fwordleOwnedPositions(date, userId);
  const boards = fwordleUserBoards(myGuesses, answers, owned);
  const usedNow = myGuesses.length;
  const solved = boards.solved;
  const finished = solved || usedNow >= maxGuesses;

  // Preserve an existing solve time; stamp it the moment all boards fall.
  const solvedAt = result?.solvedAt ?? (solved ? dbNow() : null);

  await prisma.fwordleResult.upsert({
    where: { gameDate_userId: { gameDate, userId } },
    create: { gameDate, userId, finished: finished ? 1 : 0, solved: solved ? 1 : 0, guessesUsed: usedNow, solvedAt },
    update: { finished: finished ? 1 : 0, solved: solved ? 1 : 0, guessesUsed: usedNow, solvedAt },
  });

  return NextResponse.json(await fwordleState(date, userId));
}
