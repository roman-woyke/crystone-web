import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { toDbDateStr } from "@/lib/dates";
import { boardleEarliestDate, boardleToday } from "@/lib/boardle";

// One row per day this user ever finished (solved or not) — backs the calendar
// popup's per-day markers. Small dataset for a small friend group; no range
// filtering needed.
export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const rows = await prisma.boardleResult.findMany({
    where: { userId },
    select: { gameDate: true, finished: true, solved: true },
  });
  const results: Record<string, { finished: boolean; solved: boolean }> = {};
  for (const r of rows) {
    results[toDbDateStr(r.gameDate)] = {
      finished: !!r.finished,
      solved: !!r.solved,
    };
  }

  return NextResponse.json({
    today: boardleToday(),
    earliest_date: await boardleEarliestDate(),
    results,
  });
}
