// The PHP app's DATETIME/TIMESTAMP columns store Europe/Berlin wall-clock
// values with no timezone info (same assumption the original app's PHP and
// JS make — see score-table.php/score-chart.php). `@prisma/adapter-mariadb`
// reads them by tagging the raw stored digits as UTC, so a DB value of
// "2026-06-30 21:45:36" becomes a Date whose *UTC* components are exactly
// those digits. `dbNow()` encodes the real current time the same way, so
// diffing it against DB-derived Dates yields the correct Berlin wall-clock
// elapsed time regardless of the server process's own TZ (e.g. Vercel/UTC).

export function dbNow(): Date {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: "Europe/Berlin",
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  }).formatToParts(new Date());

  const get = (type: string) => Number(parts.find((p) => p.type === type)!.value);

  return new Date(
    Date.UTC(get("year"), get("month") - 1, get("day"), get("hour"), get("minute"), get("second")),
  );
}

// Equivalent to MySQL's `DATE(col)` on these wall-clock-as-UTC values.
export function toDbDateStr(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function daysSince(date: Date | null): number | null {
  if (!date) return null;
  return Math.floor((dbNow().getTime() - date.getTime()) / 86_400_000);
}

export function relativeDaysLabel(days: number | null): string {
  if (days === null) return "—";
  if (days <= 0) return "today";
  return `${days}d ago`;
}
