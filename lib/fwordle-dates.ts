// Calendar-date-string helpers for fWordle. game_date columns are plain
// dates (Berlin wall-clock calendar day, same convention as lib/dates.ts),
// so these operate on "YYYY-MM-DD" strings / midnight-UTC-tagged Dates
// rather than on real timestamps.

import { dbNow, toDbDateStr } from "@/lib/dates";

export function fwordleToday(): string {
  return toDbDateStr(dbNow());
}

export function fwordleAddDays(dateStr: string, delta: number): string {
  const d = new Date(`${dateStr}T00:00:00.000Z`);
  d.setUTCDate(d.getUTCDate() + delta);
  return toDbDateStr(d);
}

// Midnight-UTC-tagged Date for a "YYYY-MM-DD" string, matching how every
// other `@db.Date` column is written/read in this app.
export function fwordleDateOnly(dateStr: string): Date {
  return new Date(`${dateStr}T00:00:00.000Z`);
}
