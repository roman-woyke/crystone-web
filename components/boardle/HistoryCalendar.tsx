"use client";

import { useEffect, useState } from "react";

type DayResult = { finished: boolean; solved: boolean };
type History = { today: string; earliest_date: string; results: Record<string, DayResult> };

const DOWS = ["S", "M", "T", "W", "T", "F", "S"];

function monthStart(dateStr: string): string {
  return dateStr.slice(0, 8) + "01";
}

function shiftMonth(cursor: string, delta: number): string {
  const [y, m] = cursor.split("-").map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

// Jump-to-a-past-puzzle popup: a month grid with solved/missed markers per
// day, bounded to [earliest_date, today]. Ported from boardle.php's
// fw-cal-modal (Patch 1.2).
export function HistoryCalendar({
  viewedDate,
  onPick,
  onClose,
}: {
  viewedDate: string;
  onPick: (date: string) => void;
  onClose: () => void;
}) {
  const [history, setHistory] = useState<History | null>(null);
  const [failed, setFailed] = useState(false);
  const [cursor, setCursor] = useState(monthStart(viewedDate));

  useEffect(() => {
    let cancelled = false;
    fetch("/api/boardle/history")
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((res: History) => {
        if (!cancelled) setHistory(res);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const [cy, cm] = cursor.split("-").map(Number);
  const firstDow = new Date(cy, cm - 1, 1).getDay(); // 0=Sun
  const daysInMonth = new Date(cy, cm, 0).getDate();
  const monthLabel = new Date(cy, cm - 1, 1).toLocaleDateString(undefined, { month: "long", year: "numeric" });

  const cells: React.ReactNode[] = [];
  if (history) {
    for (let i = 0; i < firstDow; i++) cells.push(<div key={`e${i}`} />);
    for (let day = 1; day <= daysInMonth; day++) {
      const ds = `${cy}-${String(cm).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
      const outOfRange = ds < history.earliest_date || ds > history.today;
      const r = history.results[ds];
      let tone = "";
      if (!outOfRange) {
        if (r && r.solved) tone = "bg-emerald-600/20 shadow-[inset_0_0_0_1px_rgba(46,158,91,0.5)]";
        // A past day counts as missed whether it was played and not solved,
        // or never opened at all — today is still in progress so it's left
        // unmarked either way.
        else if (ds !== history.today) tone = "bg-red-400/10 shadow-[inset_0_0_0_1px_rgba(248,113,113,0.35)]";
      }
      cells.push(
        <button
          key={ds}
          type="button"
          disabled={outOfRange}
          onClick={() => onPick(ds)}
          className={`relative flex aspect-square items-center justify-center rounded-md text-[0.82rem] font-semibold ${tone} ${
            outOfRange ? "cursor-default text-muted-foreground opacity-35" : "cursor-pointer hover:bg-foreground/10"
          } ${ds === viewedDate ? "shadow-[inset_0_0_0_2px_var(--primary)]" : ""}`}
        >
          {day}
          {ds === history.today && (
            <span className="absolute bottom-1 h-1 w-1 rounded-full bg-primary" aria-hidden="true" />
          )}
        </button>,
      );
    }
  }

  const prevDisabled = !history || cursor <= monthStart(history.earliest_date);
  const nextDisabled = !history || cursor >= monthStart(history.today);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="glow-card w-full max-w-[340px] overflow-hidden rounded-xl border bg-card p-5">
        <div className="mb-3 flex items-center justify-between">
          <button
            type="button"
            disabled={prevDisabled}
            onClick={() => setCursor((c) => shiftMonth(c, -1))}
            aria-label="Previous month"
            className="flex h-8 w-8 items-center justify-center rounded-full border text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-35"
          >
            ‹
          </button>
          <h2 className="text-base font-semibold">{monthLabel}</h2>
          <button
            type="button"
            disabled={nextDisabled}
            onClick={() => setCursor((c) => shiftMonth(c, 1))}
            aria-label="Next month"
            className="flex h-8 w-8 items-center justify-center rounded-full border text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-35"
          >
            ›
          </button>
        </div>

        {failed ? (
          <p className="text-sm text-muted-foreground">Couldn&apos;t load history.</p>
        ) : !history ? (
          <p className="text-sm text-muted-foreground">Loading…</p>
        ) : (
          <>
            <div className="grid grid-cols-7 gap-1">
              {DOWS.map((d, i) => (
                <div
                  key={i}
                  className="pb-1 text-center text-[0.68rem] font-bold tracking-wide text-muted-foreground uppercase"
                >
                  {d}
                </div>
              ))}
              {cells}
            </div>
            <div className="mt-3 flex flex-wrap gap-2.5 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-[3px] bg-emerald-600/60" /> Solved
              </span>
              <span className="inline-flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-[3px] bg-red-400/50" /> Missed
              </span>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
