import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";

// Study segments: one row per contiguous study interval. A "live" segment
// (sessionId null) belongs to the in-progress session; the open one (endedAt
// null) is the interval currently running. Pausing closes it; resuming opens
// a new one. On log they're attached to the session so the day recap can
// draw the real intervals with breaks shown as gaps.

export async function segOpen(userId: number, module: string | null): Promise<void> {
  const hasOpen = await prisma.studySegment.findFirst({
    where: { userId, sessionId: null, endedAt: null },
    select: { id: true },
  });
  if (!hasOpen) {
    const now = dbNow();
    await prisma.studySegment.create({
      data: { userId, moduleName: module, startedAt: now, createdAt: now },
    });
  }
}

export async function segClose(userId: number): Promise<void> {
  await prisma.studySegment.updateMany({
    where: { userId, sessionId: null, endedAt: null },
    data: { endedAt: dbNow() },
  });
}

export async function segClearLive(userId: number): Promise<void> {
  await prisma.studySegment.deleteMany({ where: { userId, sessionId: null } });
}
