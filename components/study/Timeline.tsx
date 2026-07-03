"use client";

import { useEffect, useState } from "react";

import { colorFor } from "@/lib/study-colors";
import { fmtTime } from "@/lib/study-format";
import type { RecapBlock } from "@/lib/study-status";

// A study day runs 07:00 -> 07:00 next morning (420 - 1800 raw minutes,
// mapped so anything before 07:00 lands in the 24:00-30:00 tail).
const DAY_START_MIN = 7 * 60;
const DAY_END_MIN = 30 * 60;
const HOUR_PX = 40;
const TOTAL_PX = ((DAY_END_MIN - DAY_START_MIN) / 60) * HOUR_PX;

function toDayMin(m: number): number {
  return m < DAY_START_MIN ? m + 1440 : m;
}

function clampDay(m: number): number {
  return Math.min(DAY_END_MIN, Math.max(DAY_START_MIN, m));
}

function yPx(m: number): number {
  return ((clampDay(m) - DAY_START_MIN) / (DAY_END_MIN - DAY_START_MIN)) * TOTAL_PX;
}

function minsToHHMM(m: number): string {
  const wrapped = ((m % 1440) + 1440) % 1440;
  const h = Math.floor(wrapped / 60);
  const mm = wrapped % 60;
  return `${String(h).padStart(2, "0")}:${String(mm).padStart(2, "0")}`;
}

export function Timeline({
  blocks,
  isToday,
  extraUsers,
}: {
  blocks: RecapBlock[];
  isToday: boolean;
  extraUsers: string[];
}) {
  const [tickMin, setTickMin] = useState<number | null>(null);

  useEffect(() => {
    if (!isToday) return;
    const id = setInterval(() => {
      const now = new Date();
      setTickMin(toDayMin(now.getHours() * 60 + now.getMinutes() + now.getSeconds() / 60));
    }, 1000);
    return () => clearInterval(id);
  }, [isToday]);

  const now0 = new Date();
  const nowMin = isToday
    ? (tickMin ?? toDayMin(now0.getHours() * 60 + now0.getMinutes() + now0.getSeconds() / 60))
    : null;

  const clamped = blocks
    .map((r) => ({
      username: r.username,
      module: r.module,
      seconds: r.seconds,
      live: r.live,
      startM: toDayMin(r.start_min),
      endM: toDayMin(r.end_min),
    }))
    .filter((b) => b.endM - b.startM >= 0.5);

  const userSet = new Set<string>(clamped.map((b) => b.username));
  if (isToday) {
    for (const u of extraUsers) userSet.add(u);
  }
  const allUsers = [...userSet].sort();

  if (allUsers.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {isToday ? "Keine Sessions heute." : "Keine Sessions gestern."}
      </p>
    );
  }

  const ticks: number[] = [];
  for (let m = DAY_START_MIN; m <= DAY_END_MIN; m += 60) ticks.push(m);

  const nowY = nowMin !== null ? yPx(nowMin) : null;

  return (
    <div className="overflow-x-auto">
      <div className="flex" style={{ minWidth: allUsers.length * 90 }}>
        {allUsers.map((u) => (
          <div key={u} className="flex-1 truncate px-1 text-center text-xs font-medium" style={{ color: colorFor(u) }}>
            {u}
          </div>
        ))}
      </div>
      <div className="relative flex" style={{ height: TOTAL_PX + 9 }}>
        <div className="relative w-10 shrink-0">
          {ticks.map((m) => (
            <span
              key={m}
              className="absolute -translate-y-1/2 text-[10px] text-muted-foreground"
              style={{ top: yPx(m) }}
            >
              {minsToHHMM(m)}
            </span>
          ))}
        </div>
        <div className="relative flex flex-1" style={{ minWidth: allUsers.length * 90 - 40 }}>
          {ticks.map((m) => (
            <div
              key={m}
              className="pointer-events-none absolute inset-x-0 border-t border-border/50"
              style={{ top: yPx(m) }}
            />
          ))}

          {nowY !== null && (
            <div className="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-red-500" style={{ top: nowY }}>
              <span className="absolute -left-1 -top-1 size-2 rounded-full bg-red-500" />
            </div>
          )}

          {allUsers.map((u) => (
            <div key={u} className="relative flex-1 border-l border-border/30 first:border-l-0">
              {clamped
                .filter((b) => b.username === u)
                .map((b, i) => {
                  const top = yPx(b.startM);
                  const bottom = b.live && nowY !== null ? nowY : yPx(b.endM);
                  const height = Math.max(bottom - top, 3);
                  const color = b.module ? colorFor(b.module) : "#888";
                  return (
                    <div
                      key={i}
                      title={`${b.module ?? "—"} · ${fmtTime(b.seconds)}`}
                      className={`absolute inset-x-0.5 rounded-sm ${b.live ? "border-t-2 border-white/60" : ""}`}
                      style={{ top, height, backgroundColor: color, opacity: b.live ? 0.85 : 0.7 }}
                    />
                  );
                })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
