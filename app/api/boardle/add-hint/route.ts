import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleDateOnly } from "@/lib/boardle-dates";
import {
  boardleEnsureDay,
  boardleFinalizeWords,
  boardleOwnedPositions,
  boardleState,
  boardleToday,
} from "@/lib/boardle";
import { boardleUserBoards } from "@/lib/boardle-score";

// Write a shared clue onto a board that has none yet — visible to every
// player from then on. Only allowed once the author has SOLVED that board
// (their own pick counts as solved): they know the word, so they can write a
// fair clue for the next players.
export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);
  const date = boardleToday();

  const body = await request.json().catch(() => ({}));
  const board = Number.isInteger(body.board) ? (body.board as number) : NaN;
  const hint = String(body.hint ?? "").trim().slice(0, 500);

  if (!Number.isInteger(board) || board < 0) {
    return new NextResponse("Invalid board.", { status: 400 });
  }
  if (hint === "") {
    return new NextResponse("Hint can't be empty.", { status: 422 });
  }

  await boardleEnsureDay(date);
  await boardleFinalizeWords(date);

  // Load the day's boards (answers + current hint).
  const wordRows = await prisma.boardleWord.findMany({
    where: { gameDate: boardleDateOnly(date) },
    orderBy: { position: "asc" },
  });
  const answers: string[] = [];
  const hints: (string | null)[] = [];
  for (const r of wordRows) {
    answers[r.position] = r.word;
    hints[r.position] = r.hint && r.hint !== "" ? r.hint : null;
  }

  if (answers[board] === undefined) {
    return new NextResponse("Invalid board.", { status: 400 });
  }

  // Only a hintless board can receive a community hint — never overwrite the
  // chooser's original clue (or another solver's).
  if (hints[board] !== null) {
    return new NextResponse("That board already has a hint.", { status: 409 });
  }

  const owned = await boardleOwnedPositions(date, userId);
  const guessRows = await prisma.boardleGuess.findMany({
    where: { gameDate: boardleDateOnly(date), userId },
    orderBy: { guessIndex: "asc" },
    select: { guess: true },
  });
  const mb = boardleUserBoards(guessRows.map((g) => g.guess), answers, owned);
  if (!mb.solvedBoards[board]) {
    return new NextResponse("Solve this board before adding a hint.", { status: 403 });
  }

  // Write it onto the shared board. The hint-is-still-empty WHERE guard keeps
  // a concurrent add from clobbering an already-written hint.
  await prisma.boardleWord.updateMany({
    where: {
      gameDate: boardleDateOnly(date),
      position: board,
      OR: [{ hint: null }, { hint: "" }],
    },
    data: { hint },
  });

  return NextResponse.json(await boardleState(date, userId));
}
