// Streak + streak-freeze accounting for fWordle, ported 1:1 from
// includes/fwordle.php (fwordleStreakInfo / fwordleStats).

import { prisma } from "@/lib/prisma";
import { toDbDateStr } from "@/lib/dates";
import { fwordleAddDays } from "@/lib/fwordle-dates";

// Everyone starts with 3 freezes, earns 3 more per 7 days of streak, and a
// freeze auto-bridges a missed day (so the streak survives) when one is
// available; a freeze can also be exchanged for a joker once per day.
export const FWORDLE_FREEZE_START = 3;
export const FWORDLE_FREEZE_PER_WEEK = 3;

export type StreakInfo = {
  effective: number;
  freezes: number;
  freezeUsedToday: boolean;
};

// Current solve streak AND freeze balance for a user, computed by replaying
// every day in order from the first relevant day up to `date`:
//   - each solved day: streak + 1, and FREEZE_PER_WEEK freezes whenever the
//     streak hits a multiple of 7.
//   - each PAST unsolved day (date excluded — it isn't over): a freeze
//     bridges it (streak preserved) when the balance allows, otherwise the
//     streak resets.
//   - jokers settle on their day: a freeze-paid joker spends a freeze; every
//     other joker costs 1 streak.
// `effective` is the freeze-aware streak, net of the joker streak-cost.
export async function fwordleStreakInfo(date: string, userId: number): Promise<StreakInfo> {
  const solvedRows = await prisma.fwordleResult.findMany({
    where: { userId, solved: 1 },
    orderBy: { gameDate: "asc" },
    select: { gameDate: true },
  });
  const solvedList = solvedRows.map((r) => toDbDateStr(r.gameDate));
  const solved = new Set(solvedList);

  const hintRows = await prisma.fwordleHint.findMany({
    where: { userId },
    select: { gameDate: true, viaFreeze: true },
  });
  const streakJokers = new Map<string, number>();
  const freezeJokers = new Map<string, number>();
  const jokerDates: string[] = [];
  for (const h of hintRows) {
    const d = toDbDateStr(h.gameDate);
    if (!streakJokers.has(d) && !freezeJokers.has(d)) jokerDates.push(d);
    if (h.viaFreeze) {
      freezeJokers.set(d, (freezeJokers.get(d) ?? 0) + 1);
    } else {
      streakJokers.set(d, (streakJokers.get(d) ?? 0) + 1);
    }
  }

  const candidates = [solvedList[0], jokerDates.length ? jokerDates.reduce((a, b) => (a < b ? a : b)) : undefined].filter(
    (d): d is string => !!d,
  );
  let cursor = candidates.length ? candidates.reduce((a, b) => (a < b ? a : b)) : date;

  let balance = FWORDLE_FREEZE_START;
  let streak = 0;

  while (cursor <= date) {
    if (solved.has(cursor)) {
      streak++;
      if (streak % 7 === 0) balance += FWORDLE_FREEZE_PER_WEEK;
    } else if (cursor !== date) {
      if (streak > 0) {
        if (balance >= 1) balance--;
        else streak = 0;
      }
    }

    const fj = freezeJokers.get(cursor);
    if (fj) balance -= fj;
    const sj = streakJokers.get(cursor);
    if (sj) streak = Math.max(0, streak - sj);
    if (balance < 0) balance = 0;

    if (cursor === date) break;
    cursor = fwordleAddDays(cursor, 1);
  }

  return {
    effective: Math.max(0, streak),
    freezes: Math.max(0, balance),
    freezeUsedToday: !!freezeJokers.get(date),
  };
}

// Field names match the wire payload 1:1 (this becomes `state.stats` as-is),
// same snake_case-for-JSON-fields convention as lib/study-status.ts.
export type PlayerStats = {
  username: string;
  streak: number;
  freezes: number;
  solves: number;
  avg_guesses: number | null;
};

// Per-user season stats: effective (freeze-aware) streak and average guesses
// on solved days. One entry per user (empty if never won).
export async function fwordleStats(date: string): Promise<PlayerStats[]> {
  const users = await prisma.user.findMany({
    select: { id: true, username: true },
    orderBy: { username: "asc" },
  });

  const agg = await prisma.fwordleResult.groupBy({
    by: ["userId"],
    where: { solved: 1 },
    _count: { _all: true },
    _avg: { guessesUsed: true },
  });
  const aggByUser = new Map(agg.map((a) => [a.userId, a]));

  const stats: PlayerStats[] = [];
  for (const u of users) {
    const info = await fwordleStreakInfo(date, u.id);
    const a = aggByUser.get(u.id);
    stats.push({
      username: u.username,
      streak: info.effective,
      freezes: info.freezes,
      solves: a?._count._all ?? 0,
      avg_guesses: a?._avg.guessesUsed != null ? Math.round(a._avg.guessesUsed * 10) / 10 : null,
    });
  }

  stats.sort((x, y) => {
    if (x.streak !== y.streak) return y.streak - x.streak;
    if (x.solves !== y.solves) return y.solves - x.solves;
    const ax = x.avg_guesses ?? Infinity;
    const ay = y.avg_guesses ?? Infinity;
    return ax - ay;
  });

  return stats;
}
