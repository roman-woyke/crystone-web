"use client";

import { useMemo } from "react";

import { addDays, fmtClock, fmtMini, fmtTime, mondayOf, studyDayStart, toDateStr } from "@/lib/study-format";
import { abbrevModule, colorFor } from "@/lib/study-colors";
import type { AggregatedSession } from "@/lib/study-sessions";
import type { MyStudyStatus } from "@/lib/study-status";
import { Button } from "@/components/ui/button";
import { useTooltip, TooltipEl } from "@/components/study/Tooltip";
import { breakdownParts } from "@/components/study/Dock";

const BAR_MAX_PX = 88;

function MiniChart({
  label,
  offset,
  username,
  sessions,
}: {
  label: string;
  offset: number;
  username: string;
  sessions: AggregatedSession[];
}) {
  const { tooltip, elRef, show, hide } = useTooltip();

  const today = studyDayStart(new Date());
  const weekStart = addDays(mondayOf(today), offset * 7);
  const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

  const byDay = useMemo(() => {
    const map = new Map<string, { total: number; mods: Map<string, number> }>();
    const dayStrs = new Set(days.map(toDateStr));
    for (const s of sessions) {
      if (s.username !== username || !dayStrs.has(s.date)) continue;
      const e = map.get(s.date) ?? { total: 0, mods: new Map<string, number>() };
      e.total += s.seconds;
      if (s.module) e.mods.set(s.module, (e.mods.get(s.module) ?? 0) + s.seconds);
      map.set(s.date, e);
    }
    return map;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessions, username, weekStart]);

  const maxSecs = Math.max(1, ...days.map((d) => byDay.get(toDateStr(d))?.total ?? 0));
  const weekTotal = days.reduce((sum, d) => sum + (byDay.get(toDateStr(d))?.total ?? 0), 0);
  const activeDays = days.filter((d) => (byDay.get(toDateStr(d))?.total ?? 0) > 0).length;
  const avgPerDay = activeDays > 0 ? weekTotal / activeDays : 0;
  const myColor = colorFor(username);

  return (
    <div>
      <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
        <span>{label}</span>
        {weekTotal > 0 && (
          <span>
            {fmtTime(weekTotal)} <span className="text-muted-foreground/70">avg {fmtMini(avgPerDay)}/d</span>
          </span>
        )}
      </div>
      <div className="flex h-[100px] items-end gap-1.5">
        {days.map((d) => {
          const dateStr = toDateStr(d);
          const e = byDay.get(dateStr);
          const secs = e?.total ?? 0;
          const hasData = secs > 0;
          const isToday = dateStr === toDateStr(today);
          const barH = hasData ? Math.max(Math.round((secs / maxSecs) * BAR_MAX_PX), 3) : 2;
          const color = hasData ? (isToday ? myColor : `${myColor}99`) : "rgba(255,255,255,0.07)";

          return (
            <div key={dateStr} className="flex flex-1 flex-col items-center justify-end gap-1">
              {hasData && <span className="text-[10px] text-muted-foreground">{fmtMini(secs)}</span>}
              <div
                className="w-full max-w-4 rounded-t-sm"
                style={{ height: barH, backgroundColor: color }}
                onMouseMove={(e2) => {
                  if (!hasData) return;
                  const mods = [...(e?.mods.entries() ?? [])].sort((a, b) => b[1] - a[1]);
                  const header = `<strong>${d.toLocaleDateString(undefined, { weekday: "long", month: "short", day: "numeric" })} — ${fmtTime(secs)}</strong>`;
                  const modLines = mods
                    .map(([m, s2]) => `<div class="mt-1">${m} · <strong>${fmtTime(s2)}</strong></div>`)
                    .join("");
                  show(header + modLines, e2.clientX, e2.clientY);
                }}
                onMouseLeave={hide}
              />
              <span className="text-[10px] text-muted-foreground">
                {d.toLocaleDateString(undefined, { weekday: "narrow" })}
              </span>
            </div>
          );
        })}
      </div>
      <TooltipEl tooltip={tooltip} elRef={elRef} />
    </div>
  );
}

export function FocusMode({
  active,
  onExit,
  myUsername,
  myState,
  sessions,
  onStart,
  onBreakToggle,
  onStop,
}: {
  active: boolean;
  onExit: () => void;
  myUsername: string;
  myState: MyStudyStatus;
  sessions: AggregatedSession[];
  onStart: () => void;
  onBreakToggle: () => void;
  onStop: () => void;
}) {
  const personalTotal = useMemo(
    () => sessions.filter((s) => s.username === myUsername).reduce((sum, s) => sum + s.seconds, 0),
    [sessions, myUsername],
  );

  if (!active) return null;

  const onBreak = myState.active && !myState.running;
  const parts = myState.active ? breakdownParts(myState) : [];

  return (
    <div className="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-8 bg-background p-8">
      <Button variant="outline" className="absolute right-6 top-6" onClick={onExit}>
        Exit focus
      </Button>

      <div className="text-center">
        <div className={`font-mono text-7xl font-bold tabular-nums ${onBreak ? "text-yellow-500" : ""}`}>
          {myState.active ? fmtClock(myState.elapsed) : "00:00"}
        </div>
        {/* A session that switched modules, or has taken a break, shows a
            total line plus each history entry on its own line — including a
            break still in progress — so the big (whole-session) clock above
            isn't misread as time spent in the current module alone, and
            there's no need for a separate "on break" readout. */}
        {myState.active && parts.length > 1 ? (
          <div className="mt-2 text-muted-foreground">
            <div>Session · {fmtClock(myState.elapsed)}</div>
            {parts.map((p, i) => (
              <div key={i} className={p.type === "break" && p.live ? "text-yellow-500" : ""}>
                {p.type === "break" ? "Break" : abbrevModule(p.module)} · {fmtClock(p.seconds)}
              </div>
            ))}
          </div>
        ) : (
          // The lone entry can be a break rather than a module — e.g. a
          // session that opened with a too-short-to-stand-alone module tap
          // gets folded entirely into the break that followed it (see
          // studyMergeRuns()) — so this can't just assume myState.module.
          <div className="mt-2 text-muted-foreground">
            {myState.active ? (parts[0]?.type === "break" ? "Break" : myState.module || "") : ""}
          </div>
        )}

        <div className="mt-6 flex justify-center gap-3">
          {!myState.active ? (
            <Button size="lg" onClick={onStart}>
              I&apos;m studying
            </Button>
          ) : (
            <>
              <Button size="lg" variant="outline" onClick={onBreakToggle}>
                {myState.running ? "Take a break" : "Resume"}
              </Button>
              <Button size="lg" variant="destructive" onClick={onStop}>
                Stop session
              </Button>
            </>
          )}
        </div>
      </div>

      <div className="grid w-full max-w-xl grid-cols-1 gap-6 sm:grid-cols-3">
        <MiniChart label="This week" offset={0} username={myUsername} sessions={sessions} />
        <MiniChart label="Last week" offset={-1} username={myUsername} sessions={sessions} />
        <MiniChart label="Two weeks ago" offset={-2} username={myUsername} sessions={sessions} />
      </div>

      <div className="text-center">
        <div className="text-xs text-muted-foreground">Total studied</div>
        <div className="text-lg font-semibold">{personalTotal > 0 ? fmtTime(personalTotal) : "—"}</div>
      </div>
    </div>
  );
}
