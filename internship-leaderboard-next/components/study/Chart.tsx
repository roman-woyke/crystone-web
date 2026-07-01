"use client";

import { useState } from "react";

import { addDays, fmtTime, mondayOf, studyDayStart, toDateStr } from "@/lib/study-format";
import { colorFor } from "@/lib/study-colors";
import { Button } from "@/components/ui/button";
import { useTooltip, TooltipEl } from "@/components/study/Tooltip";
import type { AggregatedSession } from "@/lib/study-sessions";

export function Chart({ sessions }: { sessions: AggregatedSession[] }) {
  const [weekOffset, setWeekOffset] = useState(0);
  const { tooltip, elRef, show, hide } = useTooltip();

  const today = studyDayStart(new Date());
  const weekStart = addDays(mondayOf(today), weekOffset * 7);
  const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
  const weekDayStrs = weekDays.map(toDateStr);

  const weekLabel =
    weekOffset === 0
      ? "This week"
      : `${weekStart.toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ${addDays(
          weekStart,
          6,
        ).toLocaleDateString(undefined, { month: "short", day: "numeric" })}`;

  const byDay = new Map<string, Map<string, { total: number; mods: Map<string, number> }>>();
  for (const s of sessions) {
    if (!weekDayStrs.includes(s.date)) continue;
    const dayMap = byDay.get(s.date) ?? new Map();
    const entry = dayMap.get(s.username) ?? { total: 0, mods: new Map<string, number>() };
    entry.total += s.seconds;
    if (s.module) entry.mods.set(s.module, (entry.mods.get(s.module) ?? 0) + s.seconds);
    dayMap.set(s.username, entry);
    byDay.set(s.date, dayMap);
  }

  let maxTotal = 0;
  for (const dayMap of byDay.values()) {
    for (const u of dayMap.values()) {
      if (u.total > maxTotal) maxTotal = u.total;
    }
  }

  const hasData = maxTotal > 0;
  const axisTop = Math.max(3600, Math.ceil(maxTotal / 3600) * 3600);
  const hours = axisTop / 3600;
  const tickStep = hours <= 6 ? 1 : Math.ceil(hours / 6);
  const ticks: number[] = [];
  for (let h = 0; h <= hours; h += tickStep) ticks.push(h);

  const legendTotals = new Map<string, number>();
  for (const s of sessions) {
    legendTotals.set(s.username, (legendTotals.get(s.username) ?? 0) + s.seconds);
  }
  const legendUsers = [...legendTotals.keys()].sort();

  return (
    <div className="rounded-xl border p-6">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Hours per day - All modules</h2>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" title="Previous week" onClick={() => setWeekOffset((o) => o - 1)}>
            ←
          </Button>
          <span className="min-w-[130px] text-center text-sm text-muted-foreground">{weekLabel}</span>
          <Button
            variant="outline"
            size="sm"
            title="Next week"
            disabled={weekOffset >= 0}
            onClick={() => setWeekOffset((o) => o + 1)}
          >
            →
          </Button>
        </div>
      </div>

      <div className="relative flex h-64">
        <div className="relative w-10 shrink-0">
          {ticks.map((h) => (
            <span
              key={h}
              className="absolute right-2 -translate-y-1/2 text-xs text-muted-foreground"
              style={{ bottom: `${(h / hours) * 100}%` }}
            >
              {h}h
            </span>
          ))}
        </div>

        <div className="relative flex flex-1 items-end gap-2">
          {ticks.map((h) => (
            <div
              key={h}
              className="pointer-events-none absolute inset-x-0 border-t border-border/60"
              style={{ bottom: `${(h / hours) * 100}%` }}
            />
          ))}

          {!hasData && (
            <div className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
              No study sessions this week.
            </div>
          )}

          {weekDays.map((d) => {
            const dateStr = toDateStr(d);
            const dayMap = byDay.get(dateStr);
            const users = dayMap ? [...dayMap.keys()].sort() : [];
            const isToday = dateStr === toDateStr(today);

            return (
              <div key={dateStr} className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                <div className="flex h-full w-full items-end justify-center gap-1">
                  {users.map((username) => {
                    const u = dayMap!.get(username)!;
                    const heightPct = (u.total / axisTop) * 100;
                    const mods = [...u.mods.entries()].sort((a, b) => b[1] - a[1]);
                    return (
                      <div
                        key={username}
                        className="relative w-3 min-w-[8px] flex-1 max-w-6 rounded-t-sm"
                        style={{ height: `${heightPct}%`, backgroundColor: colorFor(username) }}
                        onMouseMove={(e) => {
                          const header = `<strong>${username} — ${fmtTime(u.total)}</strong>`;
                          const modLines = mods
                            .map(
                              ([m, secs]) =>
                                `<div class="flex items-center gap-1.5 mt-1"><span class="inline-block size-2 rounded-full" style="background:${colorFor(
                                  username,
                                )}"></span>${m} · <strong>${fmtTime(secs)}</strong></div>`,
                            )
                            .join("");
                          show(header + modLines, e.clientX, e.clientY);
                        }}
                        onMouseLeave={hide}
                      />
                    );
                  })}
                </div>
                <span className={`text-xs ${isToday ? "font-semibold text-foreground" : "text-muted-foreground"}`}>
                  {d.toLocaleDateString(undefined, { weekday: "short" })}
                  <br />
                  {d.getDate()}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <span className="text-xs text-muted-foreground">
          Users (bar colour · hover a bar for the module split)
        </span>
        {legendUsers.length === 0 ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          legendUsers.map((u) => (
            <span key={u} className="flex items-center gap-1.5">
              <span className="size-2.5 rounded-full" style={{ backgroundColor: colorFor(u) }} />
              {u}
            </span>
          ))
        )}
      </div>

      <TooltipEl tooltip={tooltip} elRef={elRef} />
    </div>
  );
}
