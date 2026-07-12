import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { boardleRtAdvance, boardleRtPayload, boardleRtRow } from "@/lib/boardle-rt";
import { notify } from "@/lib/realtime";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json().catch(() => ({}));
  const ready = body.ready === false || body.ready === "0" ? 0 : 1;

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase !== "lobby") {
    return new NextResponse("A round is already in progress.", { status: 409 });
  }

  await prisma.boardleRtReady.upsert({
    where: { userId },
    create: { userId, ready, updatedAt: dbNow() },
    update: { ready, updatedAt: dbNow() },
  });

  // Wake the room: the 1.5s poll stays (presence heartbeat + lazy
  // deadline advance) but this makes opponents see the change instantly.
  await notify("wordle:rt", "update");

  return NextResponse.json(await boardleRtPayload(userId));
}
