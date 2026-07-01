"use client";

import { useEffect, useState } from "react";

import { colorFor } from "@/lib/study-colors";
import { fmtClock } from "@/lib/study-format";
import type { StudyingEntry, RecapBlock, MyStudyStatus } from "@/lib/study-status";
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
    <div className="sticky top-[78px] overflow-hidden rounded-xl border">
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
                  <div key={s.username} className="flex items-center gap-2 text-sm">
                    <span className="relative flex size-2">
                      <span className="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75" />
                      <span className="relative inline-flex size-2 rounded-full bg-green-500" />
                    </span>
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <span className="ml-auto tabular-nums text-muted-foreground">
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {s.module ? ` · ${s.module}` : ""}
                    </span>
                  </div>
                ))
              )}
            </div>

            {paused.length > 0 && (
              <div className="space-y-1.5 rounded-md bg-muted/50 p-2">
                <div className="text-xs text-muted-foreground">☕ On break</div>
                {paused.map((s) => (
                  <div key={s.username} className="flex items-center gap-2 text-sm">
                    <span className="size-2 rounded-full bg-yellow-500" />
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <span className="ml-auto tabular-nums text-muted-foreground">
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {s.module ? ` · ${s.module}` : ""} · ☕ {fmtClock(chipBreakElapsed(s, s.username === myUsernameOf(myState)))}
                    </span>
                  </div>
                ))}
              </div>
            )}

            {library.length > 0 && (
              <div className="space-y-1.5 rounded-md bg-blue-500/10 p-2">
                <div className="text-xs text-muted-foreground">📚 At the library</div>
                {library.map((s) => (
                  <div key={s.username} className="flex items-center gap-2 text-sm">
                    <span className={`size-2 rounded-full ${s.running ? "bg-green-500" : "bg-yellow-500"}`} />
                    <span style={{ color: colorFor(s.username) }} className="font-medium">
                      {s.username}
                    </span>
                    <span className="ml-auto tabular-nums text-muted-foreground">
                      {fmtClock(chipElapsed(s, s.username === myUsernameOf(myState)))}
                      {s.module ? ` · ${s.module}` : ""}
                      {!s.running ? ` · ☕ ${fmtClock(chipBreakElapsed(s, s.username === myUsernameOf(myState)))}` : ""}
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
