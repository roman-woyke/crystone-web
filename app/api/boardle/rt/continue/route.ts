import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { boardleRtAdvance, boardleRtPayload, boardleRtRow } from "@/lib/boardle-rt";
import { notify } from "@/lib/realtime";

export async function POST() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase === "results") {
    await prisma.boardleRtState.update({
      where: { id: 1 },
      data: { phase: "lobby", phaseStartedAt: dbNow() },
    });
  }

  // Wake the room: the 1.5s poll stays (presence heartbeat + lazy
  // deadline advance) but this makes opponents see the change instantly.
  await notify("wordle:rt", "update");

  return NextResponse.json(await boardleRtPayload(Number(session.user.id)));
}
