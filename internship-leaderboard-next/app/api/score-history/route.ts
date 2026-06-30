import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { scorePoints } from "@/lib/scoring";
import { toDbDateStr } from "@/lib/dates";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const users = await prisma.user.findMany({
    orderBy: { id: "asc" },
    select: { id: true, username: true },
  });

  const applications = await prisma.application.findMany({
    select: { id: true, tag: true },
  });
  const tagByApp = new Map(applications.map((a) => [a.id, a.tag]));

  const history = await prisma.applicationStatusHistory.findMany({
    orderBy: { changedAt: "asc" },
    select: { userId: true, applicationId: true, status: true, changedAt: true },
  });

  // dailyDeltas[userId][date] = net score change on that date
  const dailyDeltas = new Map<number, Map<string, number>>();

  for (const row of history) {
    if (!row.changedAt) continue;
    const date = toDbDateStr(row.changedAt);
    const tag = tagByApp.get(row.applicationId) ?? null;
    const delta = scorePoints(row.status, tag);

    const userDeltas = dailyDeltas.get(row.userId) ?? new Map<string, number>();
    userDeltas.set(date, (userDeltas.get(date) ?? 0) + delta);
    dailyDeltas.set(row.userId, userDeltas);
  }

  const result = [];

  for (const user of users) {
    const deltas = dailyDeltas.get(user.id);
    if (!deltas || deltas.size === 0) continue;

    const dates = [...deltas.keys()].sort();
    const points: { date: string; score: number }[] = [];

    // Prepend a zero point one day before the first event so every user's
    // line visibly starts from 0.
    const firstDate = new Date(`${dates[0]}T00:00:00Z`);
    firstDate.setUTCDate(firstDate.getUTCDate() - 1);
    points.push({ date: toDbDateStr(firstDate), score: 0 });

    let cumulative = 0;
    for (const date of dates) {
      cumulative += deltas.get(date)!;
      points.push({ date, score: cumulative });
    }

    result.push({ username: user.username, points });
  }

  return NextResponse.json(result);
}
