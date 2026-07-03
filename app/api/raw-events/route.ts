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

  const eventsByUser = new Map<
    number,
    { date: string; status: string; score_delta: number }[]
  >();

  for (const row of history) {
    if (!row.changedAt) continue;
    const tag = tagByApp.get(row.applicationId) ?? null;
    const delta = scorePoints(row.status, tag);

    const events = eventsByUser.get(row.userId) ?? [];
    events.push({ date: toDbDateStr(row.changedAt), status: row.status, score_delta: delta });
    eventsByUser.set(row.userId, events);
  }

  const result = [];
  for (const user of users) {
    const events = eventsByUser.get(user.id);
    if (!events || events.length === 0) continue;
    result.push({ username: user.username, events });
  }

  return NextResponse.json(result);
}
