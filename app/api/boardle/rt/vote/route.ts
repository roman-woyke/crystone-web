import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleRtAdvance, boardleRtPayload, boardleRtRow } from "@/lib/boardle-rt";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json().catch(() => ({}));
  const length = Number(body.length);

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase !== "voting") {
    return new NextResponse("Not in the voting phase.", { status: 409 });
  }

  const choices = String(row.lengthChoices ?? "")
    .split(",")
    .map(Number);
  if (!Number.isInteger(length) || !choices.includes(length)) {
    return new NextResponse("Invalid length choice.", { status: 400 });
  }

  const inRound = await prisma.boardleRtPlayer.findUnique({
    where: { roundId_userId: { roundId: row.roundId, userId } },
  });
  if (!inRound) {
    return new NextResponse("You're not in this round.", { status: 403 });
  }

  await prisma.boardleRtVote.upsert({
    where: { roundId_userId: { roundId: row.roundId, userId } },
    create: { roundId: row.roundId, userId, length },
    update: { length },
  });

  return NextResponse.json(await boardleRtPayload(userId));
}
