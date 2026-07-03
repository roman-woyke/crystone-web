import { dbNow, dbToday } from "@/lib/dates";

export type TimeEntryLike = { seconds: number; loggedAt: Date | null };

export function aggregateProjectTime(entries: TimeEntryLike[]) {
  const today = dbToday();
  const weekAgo = new Date(dbNow().getTime() - 7 * 86_400_000);

  let totalSeconds = 0;
  let todaySeconds = 0;
  let weekSeconds = 0;

  for (const e of entries) {
    totalSeconds += e.seconds;
    if (e.loggedAt && e.loggedAt >= today) todaySeconds += e.seconds;
    if (e.loggedAt && e.loggedAt >= weekAgo) weekSeconds += e.seconds;
  }

  return { totalSeconds, todaySeconds, weekSeconds, entryCount: entries.length };
}

export function relativeTime(date: Date | null): string {
  if (!date) return "—";
  const diff = Math.floor((dbNow().getTime() - date.getTime()) / 1000);
  if (diff < 60) return "just now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  const dd = String(date.getUTCDate()).padStart(2, "0");
  const mm = String(date.getUTCMonth() + 1).padStart(2, "0");
  const yyyy = date.getUTCFullYear();
  return `${dd}.${mm}.${yyyy}`;
}
