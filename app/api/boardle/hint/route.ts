import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleDateOnly } from "@/lib/boardle-dates";
import { boardleEnsureDay, boardleFinalizeWords, boardleResolveDate, boardleState } from "@/lib/boardle";
import { boardleStreakInfo } from "@/lib/boardle-streak";
import { boardleComputeHint } from "@/lib/boardle-score";

const HINT_TYPES = ["armor", "orange", "green"] as const;
type HintType = (typeof HINT_TYPES)[number];

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json().catch(() => ({}));
  const date = await boardleResolveDate(body.date);
  const gameDate = boardleDateOnly(date);

  const type = String(body.type ?? "");
  const boardRaw = body.board;
  const board = boardRaw === undefined || boardRaw === null || boardRaw === "" ? null : Number(boardRaw);

  if (!HINT_TYPES.includes(type as HintType)) {
    return new NextResponse("Bad joker type.", { status: 400 });
  }

  await boardleEnsureDay(date);
  await boardleFinalizeWords(date);

  // Done with that day? No more jokers.
  const result = await prisma.boardleResult.findUnique({ where: { gameDate_userId: { gameDate, userId } } });
  if (result?.finished === 1) {
    return new NextResponse("You're already done with that day.", { status: 409 });
  }

  // Each joker is once per day.
  const existing = await prisma.boardleHint.findUnique({
    where: { gameDate_userId_type: { gameDate, userId, type: type as HintType } },
  });
  if (existing) {
    return new NextResponse(`You've already used your ${type} joker.`, { status: 409 });
  }

  // Payment: a joker costs 1 streak, but a player with no streak gets one
  // free per day (their first), and a freeze can be exchanged for a joker.
  // The client may request pay=freeze; we also fall back to a freeze when
  // there's no streak left to spend.
  const info = await boardleStreakInfo(date, userId);
  const usedToday = await prisma.boardleHint.count({ where: { gameDate, userId } });

  const wantFreeze = body.pay === "freeze";
  const freeJoker = info.effective < 1 && usedToday === 0;
  const streakOk = info.effective >= 1 || freeJoker;
  const freezeOk = info.freezes >= 1;

  let viaFreeze = 0;
  if (wantFreeze) {
    if (!freezeOk) {
      return new NextResponse("No freeze to exchange today.", { status: 409 });
    }
    viaFreeze = 1;
  } else if (streakOk) {
    viaFreeze = 0;
  } else if (freezeOk) {
    viaFreeze = 1; // out of streak -> auto-pay with a freeze
  } else {
    return new NextResponse("Not enough streak or freezes to spend a joker.", { status: 409 });
  }

  // ── Armor: +1 guess overall, no board, no reveal. ──
  if (type === "armor") {
    try {
      await prisma.boardleHint.create({
        data: { gameDate, userId, boardPos: null, type: "armor", payload: "1", viaFreeze },
      });
    } catch {
      return new NextResponse("Joker conflict — reload and try again.", { status: 409 });
    }
    return NextResponse.json(await boardleState(date, userId));
  }

  // ── Board jokers (orange / green) ──
  const wordRows = await prisma.boardleWord.findMany({ where: { gameDate }, orderBy: { position: "asc" } });
  const answers: string[] = [];
  const chooser: (number | null)[] = [];
  for (const r of wordRows) {
    answers[r.position] = r.word;
    chooser[r.position] = r.chooserId;
  }

  if (board === null || !Number.isInteger(board) || answers[board] === undefined) {
    return new NextResponse("Invalid board.", { status: 400 });
  }

  // Can't hint a board you already solved (your own pick is auto-solved too).
  if (chooser[board] === userId) {
    return new NextResponse("That's your own board.", { status: 409 });
  }

  // My guesses so far (to avoid revealing anything I already know).
  const myGuessRows = await prisma.boardleGuess.findMany({
    where: { gameDate, userId },
    orderBy: { guessIndex: "asc" },
    select: { guess: true },
  });
  const myGuesses = myGuessRows.map((r) => r.guess);

  const payload = boardleComputeHint(answers[board], myGuesses, type as "orange" | "green");
  if (payload === null) {
    return new NextResponse("Nothing new to reveal there.", { status: 422 });
  }

  try {
    await prisma.boardleHint.create({
      data: { gameDate, userId, boardPos: board, type: type as "orange" | "green", payload, viaFreeze },
    });
  } catch {
    return new NextResponse("Joker conflict — reload and try again.", { status: 409 });
  }

  return NextResponse.json(await boardleState(date, userId));
}
