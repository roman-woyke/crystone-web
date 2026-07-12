import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleRtAdvance, boardleRtPayload, boardleRtRow } from "@/lib/boardle-rt";
import { boardleIsValidWord } from "@/lib/boardle-words";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json().catch(() => ({}));
  const word = String(body.word ?? "").trim().toLowerCase();
  const hint = String(body.hint ?? "").trim().slice(0, 500);

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase !== "selecting") {
    return new NextResponse("Not in the word-selection phase.", { status: 409 });
  }

  const roundId = row.roundId;
  const length = row.wordLength ?? 0;

  const player = await prisma.boardleRtPlayer.findUnique({
    where: { roundId_userId: { roundId, userId } },
  });
  if (!player) {
    return new NextResponse("You're not in this round.", { status: 403 });
  }

  if (hint === "") {
    return new NextResponse("A hint is mandatory.", { status: 422 });
  }
  if (word.length !== length || !boardleIsValidWord(word, length)) {
    return new NextResponse(`Not a valid ${length}-letter word.`, { status: 422 });
  }

  const existing = await prisma.boardleRtWord.findUnique({
    where: { roundId_position: { roundId, position: player.position } },
  });
  if (existing) {
    return new NextResponse("You've already chosen your word.", { status: 409 });
  }

  try {
    await prisma.boardleRtWord.create({
      data: { roundId, position: player.position, chooserId: userId, word, hint },
    });
  } catch {
    return new NextResponse("Word conflict — reload and try again.", { status: 409 });
  }

  return NextResponse.json(await boardleRtPayload(userId));
}
