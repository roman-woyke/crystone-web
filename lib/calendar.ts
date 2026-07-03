// Mirrors calendar.php: exam highlighting is keyed by a fixed username
// string (not user_id), independent of the applications/projects features.
export const CALENDAR_USERS = ["Roman", "Basti", "Ben", "Lorenz"];

export function canonicalUser(name: string | null | undefined): string | null {
  if (!name) return null;
  return CALENDAR_USERS.find((u) => u.toLowerCase() === name.toLowerCase()) ?? null;
}

// Calendar window: Mon 13.07.2026 -> Sun 26.07.2026 (matches calendar.php).
export function calendarDays(): Date[] {
  const start = Date.UTC(2026, 6, 13);
  return Array.from({ length: 14 }, (_, i) => new Date(start + i * 86_400_000));
}

export function toDateStr(d: Date): string {
  return d.toISOString().slice(0, 10);
}

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

// examDate/examTime are @db.Date/@db.Time columns, encoded the same
// UTC-tagged-wall-clock way as every other timestamp in this app (see
// lib/dates.ts) — so their UTC getters are the real calendar/clock values.
export function examTimeHM(examTime: Date): string {
  const hh = String(examTime.getUTCHours()).padStart(2, "0");
  const mm = String(examTime.getUTCMinutes()).padStart(2, "0");
  return `${hh}:${mm}`;
}

export function formatExamMeta(examDate: Date, examTime: Date): string {
  const combined = new Date(
    Date.UTC(
      examDate.getUTCFullYear(),
      examDate.getUTCMonth(),
      examDate.getUTCDate(),
      examTime.getUTCHours(),
      examTime.getUTCMinutes(),
    ),
  );
  const dow = WEEKDAYS[combined.getUTCDay()];
  const dd = String(combined.getUTCDate()).padStart(2, "0");
  const mo = String(combined.getUTCMonth() + 1).padStart(2, "0");
  const yyyy = combined.getUTCFullYear();
  return `${dow} ${dd}.${mo}.${yyyy} · ${examTimeHM(examTime)}`;
}
