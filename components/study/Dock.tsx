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

// A single-module session just shows " · Module" inline; one that switched
// modules shows each module's own time on its own line instead, so a chip
// can't misread as "whole elapsed time on the current module" (e.g.
// "2 hours · Module Y" after 2 hours on X and a few seconds on Y). `p.live`
// (not array position) marks the part with the still-open segment: a module
// switched away from and back to (X -> Y -> X) collapses into one summed
// part per name, so the currently-running one isn't necessarily last.
function namedParts(s: StudyingEntry): StudyPart[] {
  return (s.parts ?? []).filter((p) => p.module);
}

function ModuleBreakdown({ s, ticking, d }: { s: StudyingEntry; ticking: boolean; d: number }) {
  const parts = namedParts(s);
  if (parts.length <= 1) return null;
  return (
    <span className="mt-0.5 block leading-snug">
      {parts.map((p, i) => (
        <span key={i} className="block truncate whitespace-nowrap" title={p.module ?? undefined}>
          {p.module} · {fmtClock(p.seconds + (ticking && p.live ? d : 0))}
        </span>
      ))}
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

  const myElapsed = myState.active && myState.running ? myState.elapsed : null;
  const myBreakElapsed = myState.active && !myState.running ? myState.break_elapsed : null;

  function chipElapsed(s: StudyingEntry, isMe: boolean): number {
    return isMe && myElapsed !== null ? myElapsed : s.elapsed + d;
  }
  function chipBreakElapsed(s: StudyingEntry, isMe: boolean): number {
    return isMe && myBreakElapsed !== null ? myBreakElapsed : s.break_elapsed + d;
  }

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
                    <span className="ml-auto min-w-0 text-right tabular-nums text-muted-foreground">
                      {namedParts(s).length > 1 ? "Session · " : ""}
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {namedParts(s).length <= 1 && s.module ? ` · ${s.module}` : ""}
                      <ModuleBreakdown s={s} ticking d={d} />
                    </span>
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
                    <span className="ml-auto min-w-0 text-right tabular-nums text-muted-foreground">
                      {namedParts(s).length > 1 ? "Session · " : ""}
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {namedParts(s).length <= 1 && s.module ? ` · ${s.module}` : ""} · ☕{" "}
                      {fmtClock(chipBreakElapsed(s, s.username === myUsernameOf(myState)))}
                      <ModuleBreakdown s={s} ticking={false} d={d} />
                    </span>
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
                    <span className="ml-auto min-w-0 text-right tabular-nums text-muted-foreground">
                      {namedParts(s).length > 1 ? "Session · " : ""}
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {namedParts(s).length <= 1 && s.module ? ` · ${s.module}` : ""}
                      {!s.running ? ` · ☕ ${fmtClock(chipBreakElapsed(s, s.username === myUsernameOf(myState)))}` : ""}
                      <ModuleBreakdown s={s} ticking={s.running} d={d} />
                    </span>
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

function myUsernameOf(myState: MyStudyStatus): string | null {
  return myState.active ? myState.username : null;
}
