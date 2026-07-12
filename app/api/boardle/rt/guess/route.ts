import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { boardleRtAdvance, boardleRtPayload, boardleRtRow } from "@/lib/boardle-rt";
import { notify } from "@/lib/realtime";
import { BOARDLE_MIN_LEN, boardleIsValidWord, boardleMaxGuesses } from "@/lib/boardle-words";
import { boardleUserBoards } from "@/lib/boardle-score";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json().catch(() => ({}));
  const word = String(body.word ?? "").trim().toLowerCase();
  const offset = Number(body.offset);

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase !== "playing") {
    return new NextResponse("Not in the playing phase.", { status: 409 });
  }

  const roundId = row.roundId;
  const length = row.wordLength ?? 0;

  const player = await prisma.boardleRtPlayer.findUnique({
    where: { roundId_userId: { roundId, userId } },
  });
  if (!player) {
    return new NextResponse("You're not in this round.", { status: 403 });
  }
  const position = player.position;

  const wordRows = await prisma.boardleRtWord.findMany({ where: { roundId }, orderBy: { position: "asc" } });
  const answers: string[] = [];
  for (const r of wordRows) answers[r.position] = r.word;
  const numBoards = answers.length;
  const maxGuesses = boardleMaxGuesses(numBoards);

  const result = await prisma.boardleRtResult.findUnique({ where: { roundId_userId: { roundId, userId } } });
  if (result?.finished === 1) {
    return new NextResponse("You're already done with this round.", { status: 409 });
  }

  const used = await prisma.boardleRtGuess.count({ where: { roundId, userId } });
  if (used >= maxGuesses) {
    return new NextResponse("No guesses left.", { status: 409 });
  }

  // Same rules as daily Boardle: any valid word from 5 letters up to the
  // round's length, positioned within the row (space bar shifts it), encoded
  // as an L-wide string with '_' for blank cells.
  const wl = word.length;
  if (!/^[a-z]+$/.test(word) || wl < BOARDLE_MIN_LEN || wl > length) {
    return new NextResponse(`A guess must be a word of ${BOARDLE_MIN_LEN}-${length} letters.`, { status: 400 });
  }
  if (!Number.isInteger(offset) || offset < 0 || offset + wl > length) {
    return new NextResponse("Invalid word position.", { status: 400 });
  }
  if (!boardleIsValidWord(word, wl)) {
    return new NextResponse("Not in word list.", { status: 422 });
  }

  const guess = "_".repeat(offset) + word + "_".repeat(length - offset - wl);

  try {
    await prisma.boardleRtGuess.create({
      data: { roundId, userId, guessIndex: used, guess, createdAt: dbNow() },
    });
  } catch {
    return new NextResponse("Guess conflict — reload and try again.", { status: 409 });
  }

  const myGuessRows = await prisma.boardleRtGuess.findMany({
    where: { roundId, userId },
    orderBy: { guessIndex: "asc" },
    select: { guess: true },
  });
  const myGuesses = myGuessRows.map((g) => g.guess);

  const boards = boardleUserBoards(myGuesses, answers, [position]);
  const usedNow = myGuesses.length;
  const solved = boards.solved;
  const finished = solved || usedNow >= maxGuesses;

  const solvedAt = result?.solvedAt ?? (solved ? dbNow() : null);

  await prisma.boardleRtResult.upsert({
    where: { roundId_userId: { roundId, userId } },
    create: { roundId, userId, finished: finished ? 1 : 0, solved: solved ? 1 : 0, guessesUsed: usedNow, solvedAt },
    update: { finished: finished ? 1 : 0, solved: solved ? 1 : 0, guessesUsed: usedNow, solvedAt },
  });

  // Wake the room: the 1.5s poll stays (presence heartbeat + lazy
  // deadline advance) but this makes opponents see the change instantly.
  await notify("wordle:rt", "update");

  return NextResponse.json(await boardleRtPayload(userId));
}
