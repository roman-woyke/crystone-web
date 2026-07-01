import { prisma } from "@/lib/prisma";
import { toDbDateStr } from "@/lib/dates";

// Study-session aggregation shared by the study-counter page (initial paint)
// and the background-refresh API route. A "study day" runs 07:00 -> 07:00 the
// next morning, and a session counts toward the day it *started* in - even if
// it ran past midnight - so an 8pm-2am night session lands wholly on the
// evening it began.

// The study day a timestamp belongs to: its own date, or the previous date
// when it's before 07:00 (still the previous evening's study day).
export function studyDayOf(dt: Date): string {
  const d = new Date(dt.getTime());
  if (d.getUTCHours() < 7) {
    d.setUTCDate(d.getUTCDate() - 1);
  }
  return toDbDateStr(d);
}

// The day a session is charted on: its study day by real start time, falling
// back to the stored studiedOn when there's no usable start (manual entry).
export function studySessionDate(startedAt: Date | null, studiedOn: Date): string {
  if (!startedAt) return toDbDateStr(studiedOn);
  return studyDayOf(startedAt);
}

export type AggregatedSession = {
  username: string;
  module: string | null;
  date: string;
  seconds: number;
  at_library: boolean;
};

// Fetch every session, bucket it onto its study day, and aggregate into the
// per-user/module/day/at_library shape the client expects.
export async function studySessionsByDay(): Promise<AggregatedSession[]> {
  const sessions = await prisma.studySession.findMany({
    include: { user: { select: { username: true } } },
    orderBy: { studiedOn: "asc" },
  });

  const agg = new Map<string, AggregatedSession>();

  for (const s of sessions) {
    const date = studySessionDate(s.startedAt, s.studiedOn);
    const key = `${s.user.username}\0${s.moduleName}\0${date}\0${s.atLibrary ? 1 : 0}`;
    const existing = agg.get(key);
    if (existing) {
      existing.seconds += s.seconds;
    } else {
      agg.set(key, {
        username: s.user.username,
        module: s.moduleName,
        date,
        seconds: s.seconds,
        at_library: s.atLibrary,
      });
    }
  }

  return [...agg.values()].sort((a, b) => a.date.localeCompare(b.date));
}
