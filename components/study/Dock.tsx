"use client";

import { useEffect, useState } from "react";

import { colorFor } from "@/lib/study-colors";
import { fmtClock } from "@/lib/study-format";
import type { StudyingEntry, RecapBlock, MyStudyStatus, StudyPart } from "@/lib/study-status";
import { Button } from "@/components/ui/button";
import { Timeline } from "@/components/study/Timeline";

export function useNowTick(): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);
  return now;
}

function delta(syncTs: number, now: number): number {
  return Math.floor((now - syncTs) / 1000);
}

// The full chronological history of a session: each module run and each
// break, in the order they happened. The server (studyMergeRuns() in
// lib/study-status.ts) already folds interruptions shorter than 5 minutes
// into whichever run they interrupted — so a brief break during continuous
// studying (or a brief module check during a break) shows up as more time
// on the run it interrupted rather than as its own tiny line, and `s.parts`
// here is never fragmented or missing that time. `includeLiveBreak: false`
// drops the in-progress break entry for callers that already show an "on
// break" indicator of their own and would otherwise show the same number
// twice.
export function breakdownParts(s: { parts: StudyPart[] }, includeLiveBreak = true): StudyPart[] {
  return (s.parts ?? []).filter((p) => (p.type === "break" ? includeLiveBreak || !p.live : !!p.module));
}

// Every chip is rendered as a breakdown: one line per history entry (module
// run or break). Completed history lines stay the neutral grey of the rest
// of the chip meta; the currently-running module matches the green "session
// active" colour (blue at the library so BIB sessions read distinctly at a
// glance), and a break in progress is always yellow. The "Session · total"
// header (green while running, red while on break) only appears once
// there's actually more than one entry to sum — a plain single-module,
// no-break session is just its one line, no separate total needed.
function ChipMeta({ s, running, d }: { s: StudyingEntry; running: boolean; d: number }) {
  const parts = breakdownParts(s);
  const total = s.elapsed + (running ? d : 0);

  if (parts.length === 0) {
    return (
      <span className="ml-auto tabular-nums text-muted-foreground">
        {fmtClock(total)}
        {s.module ? ` · ${s.module}` : ""}
      </span>
    );
  }

  return (
    <span className="ml-auto min-w-0 text-right tabular-nums text-muted-foreground">
      {parts.length > 1 && (
        <span className={`block font-semibold ${running ? "text-emerald-500" : "text-destructive"}`}>
          Session · {fmtClock(total)}
        </span>
      )}
      <span className="mt-0.5 block leading-snug">
        {parts.map((p, i) => {
          if (p.type === "break") {
            return (
              <span
                key={i}
                className={`block whitespace-nowrap ${p.live ? "font-semibold text-yellow-500" : ""}`}
              >
                Break · {fmtClock(p.seconds + (p.live ? d : 0))}
              </span>
            );
          }
          const live = running && p.live;
          return (
            <span
              key={i}
              className={`block truncate whitespace-nowrap ${
                live ? (s.at_library ? "font-semibold text-sky-400" : "font-semibold text-emerald-500") : ""
              }`}
              title={p.module ?? undefined}
            >
              {p.module} · {fmtClock(p.seconds + (live ? d : 0))}
            </span>
          );
        })}
      </span>
    </span>
  );
}

export function Dock({
  myState,
  studying,
  syncTs,
  recapByDay,
  onStudyingToggle,
  onChangeModule,
  onBreakToggle,
  onLibraryToggle,
}: {
  myState: MyStudyStatus;
  studying: StudyingEntry[];
  syncTs: number;
  recapByDay: { 0: RecapBlock[]; 1: RecapBlock[] };
  onStudyingToggle: () => void;
  onChangeModule: () => void;
  onBreakToggle: () => void;
  onLibraryToggle: () => void;
}) {
  const [showBack, setShowBack] = useState(false);
  const [recapOffset, setRecapOffset] = useState<0 | 1>(0);
  const now = useNowTick();
  const d = delta(syncTs, now);

  const library = studying.filter((s) => s.at_library);
  const running = studying.filter((s) => !s.at_library && s.running);
  const paused = studying.filter((s) => !s.at_library && !s.running);

  const extraUsersToday = new Set<string>([...running, ...paused].map((s) => s.username));

  return (
    <div data-no-tilt className="glow-card sticky top-[78px] overflow-hidden rounded-xl border bg-card">
      {!showBack ? (
        <div className="space-y-4 p-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2 text-sm font-medium">
              <span className="relative flex size-2">
                <span className="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75" />
                <span className="relative inline-flex size-2 rounded-full bg-green-500" />
              </span>
              Currently studying
            </div>
            <button
              type="button"
              title="Show today's timeline"
              className="text-muted-foreground hover:text-foreground"
              onClick={() => setShowBack(true)}
            >
              ⇄
            </button>
          </div>

          {myState.active && (
            <div className="rounded-md bg-muted px-3 py-2 text-sm">
              <div className="text-xs text-muted-foreground">Current module</div>
              <div className="font-medium">{myState.module || "—"}</div>
            </div>
          )}

          <div className="flex flex-col gap-2">
            <Button variant={myState.active ? "destructive" : "default"} onClick={onStudyingToggle}>
              {myState.active ? "Stop session" : "I'm studying"}
            </Button>
            {myState.active && (
              <Button variant="outline" onClick={onChangeModule}>
                Change module
              </Button>
            )}
            {myState.active && (
              <Button variant="outline" onClick={onBreakToggle}>
                {myState.running ? "Take a break" : "Resume"}
              </Button>
            )}
            {myState.active && (
              <Button variant={myState.at_library ? "default" : "outline"} onClick={onLibraryToggle}>
                {myState.at_library ? "Leave library" : "At the library"}
              </Button>
            )}
          </div>

          <div className="max-h-80 space-y-3 overflow-y-auto">
            <div className="space-y-1.5">
              {running.length === 0 ? (
                <span className="text-sm text-muted-foreground">
                  {paused.length > 0 || library.length > 0 ? "—" : "Nobody's studying right now."}
                </span>
              ) : (
                running.map((s) => (
                  <div key={s.username} className="flex items-start gap-2 text-sm">
                    <span className="relative mt-1.5 flex size-2">
                      <span className="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75" />
                      <span className="relative inline-flex size-2 rounded-full bg-green-500" />
                    </span>
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <ChipMeta s={s} running d={d} />
                  </div>
                ))
              )}
            </div>

            {paused.length > 0 && (
              <div className="space-y-1.5 rounded-md bg-muted/50 p-2">
                <div className="text-xs text-muted-foreground">☕ On break</div>
                {paused.map((s) => (
                  <div key={s.username} className="flex items-start gap-2 text-sm">
                    <span className="mt-1.5 size-2 rounded-full bg-yellow-500" />
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <ChipMeta s={s} running={false} d={d} />
                  </div>
                ))}
              </div>
            )}

            {library.length > 0 && (
              <div className="space-y-1.5 rounded-md bg-blue-500/10 p-2">
                <div className="text-xs text-muted-foreground">📚 At the library</div>
                {library.map((s) => (
                  <div key={s.username} className="flex items-start gap-2 text-sm">
                    <span className={`mt-1.5 size-2 rounded-full ${s.running ? "bg-green-500" : "bg-yellow-500"}`} />
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <ChipMeta s={s} running={s.running} d={d} />
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      ) : (
        <div className="space-y-3 p-4">
          <div className="flex items-center justify-between">
            <div className="flex overflow-hidden rounded-md border text-xs">
              <button
                type="button"
                onClick={() => setRecapOffset(0)}
                className={`px-2.5 py-1 ${recapOffset === 0 ? "bg-accent font-medium" : "text-muted-foreground"}`}
              >
                📅 Heute
              </button>
              <button
                type="button"
                onClick={() => setRecapOffset(1)}
                className={`px-2.5 py-1 ${recapOffset === 1 ? "bg-accent font-medium" : "text-muted-foreground"}`}
              >
                Gestern
              </button>
            </div>
            <button
              type="button"
              title="Back to currently studying"
              className="text-muted-foreground hover:text-foreground"
              onClick={() => setShowBack(false)}
            >
              ⇄
            </button>
          </div>

          <div className="max-h-96 overflow-y-auto">
            <Timeline
              blocks={recapByDay[recapOffset]}
              isToday={recapOffset === 0}
              extraUsers={recapOffset === 0 ? [...extraUsersToday] : []}
            />
          </div>
        </div>
      )}
    </div>
  );
}
