// Client-side date/time helpers. These run in the browser (not the server),
// so unlike lib/dates.ts they use the browser's own local time directly —
// exactly like the original PHP app's inline JS did (both assume the visitor
// is in Europe/Berlin, same as the server).

export function startOfDay(d: Date): Date {
  const r = new Date(d);
  r.setHours(0, 0, 0, 0);
  return r;
}

export function addDays(d: Date, n: number): Date {
  const r = new Date(d);
  r.setDate(r.getDate() + n);
  return r;
}

export function toDateStr(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export function mondayOf(d: Date): Date {
  const s = startOfDay(d);
  const back = (s.getDay() + 6) % 7;
  return addDays(s, -back);
}

// A "study day" runs 07:00 -> 07:00 the next calendar day: before 07:00 we're
// still on yesterday's study day.
export function studyDayStart(d: Date): Date {
  const s = startOfDay(d);
  return d.getHours() < 7 ? addDays(s, -1) : s;
}

export function fmtTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0 && m > 0) return `${h}h ${m}m`;
  if (h > 0) return `${h}h`;
  if (m > 0) return `${m}m`;
  return `${Math.max(0, Math.floor(seconds))}s`;
}

export function fmtClock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  const mm = String(m).padStart(2, "0");
  const ss = String(sec).padStart(2, "0");
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

// Distinct rounding from fmtTime — used by the focus-mode mini charts.
export function fmtMini(seconds: number): string {
  if (!seconds) return "";
  if (seconds >= 3600) {
    const hours = Math.round(seconds / 360) / 10;
    return `${Number.isInteger(hours) ? hours : hours}h`;
  }
  return `${Math.round(seconds / 60)}m`;
}

export function escapeHtml(value: string): string {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
