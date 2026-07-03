"use client";

import { useEffect, useState } from "react";

import { addDays, fmtTime, mondayOf, studyDayStart, toDateStr } from "@/lib/study-format";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type MySession = {
  id: number;
  module: string | null;
  seconds: number;
  date: string;
  started_at: string | null;
  created_at: string | null;
  at_library: boolean;
};

function sessionClock(s: MySession): string {
  const raw = s.started_at ?? s.created_at;
  if (!raw) return "";
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) return "";
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

export function ManageSessionsModal({
  open,
  onClose,
  moduleColors,
  onChanged,
}: {
  open: boolean;
  onClose: () => void;
  moduleColors: Record<string, string>;
  onChanged: () => void;
}) {
  const [sessions, setSessions] = useState<MySession[] | null>(null);
  const [offset, setOffset] = useState(0);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setOffset(0);
      setSessions(null);
      setLoadError(null);
    }
  }

  useEffect(() => {
    if (!open) return;
    fetch("/api/study/my-sessions")
      .then((r) => {
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then((res) => setSessions(res.sessions))
      .catch(() => setLoadError("Couldn't load your sessions."));
  }, [open]);

  async function remove(id: number) {
    if (!confirm("Delete this study session? This can't be undone.")) return;
    const response = await fetch(`/api/study/sessions/${id}`, { method: "DELETE" });
    if (!response.ok) {
      alert("Couldn't delete that session.");
      return;
    }
    setSessions((prev) => (prev ? prev.filter((s) => s.id !== id) : prev));
    onChanged();
  }

  const today = studyDayStart(new Date());
  const weekStart = addDays(mondayOf(today), offset * 7);
  const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

  const weekLabel =
    offset === 0
      ? "This week"
      : `${weekStart.toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ${addDays(
          weekStart,
          6,
        ).toLocaleDateString(undefined, { month: "short", day: "numeric" })}`;

  const byDay = new Map<string, MySession[]>();
  if (sessions) {
    const weekDayStrs = new Set(weekDays.map(toDateStr));
    for (const s of sessions) {
      if (!weekDayStrs.has(s.date)) continue;
      const list = byDay.get(s.date) ?? [];
      list.push(s);
      byDay.set(s.date, list);
    }
    for (const list of byDay.values()) {
      list.sort((a, b) => (a.started_at ?? a.created_at ?? "").localeCompare(b.started_at ?? b.created_at ?? ""));
    }
  }

  const hasAny = [...byDay.values()].some((l) => l.length > 0);

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Manage previous sessions</DialogTitle>
        </DialogHeader>

        <div className="flex items-center justify-between">
          <Button variant="outline" size="sm" title="Previous week" onClick={() => setOffset((o) => o - 1)}>
            ←
          </Button>
          <span className="text-sm font-medium">{weekLabel}</span>
          <Button
            variant="outline"
            size="sm"
            title="Next week"
            disabled={offset >= 0}
            onClick={() => setOffset((o) => o + 1)}
          >
            →
          </Button>
        </div>

        <div className="max-h-[52vh] space-y-4 overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          {loadError && <p className="text-sm text-destructive">{loadError}</p>}
          {!loadError && sessions === null && <p className="text-sm text-muted-foreground">Loading…</p>}
          {sessions !== null && !hasAny && <p className="text-sm text-muted-foreground">No sessions this week.</p>}
          {sessions !== null &&
            weekDays.map((d) => {
              const dateStr = toDateStr(d);
              const daySessions = byDay.get(dateStr);
              if (!daySessions || daySessions.length === 0) return null;
              const isToday = dateStr === toDateStr(today);
              return (
                <div key={dateStr}>
                  <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                    {d.toLocaleDateString(undefined, { weekday: "long", month: "short", day: "numeric" })}
                    {isToday ? " · today" : ""}
                  </p>
                  <div className="space-y-1.5">
                    {daySessions.map((s) => (
                      <div key={s.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                        <span
                          className="size-2.5 shrink-0 rounded-full"
                          style={{ backgroundColor: (s.module && moduleColors[s.module]) || "#888" }}
                        />
                        <div className="min-w-0 flex-1">
                          <div className="truncate">{s.module ?? "—"}</div>
                          <div className="text-xs text-muted-foreground">
                            {[sessionClock(s), s.at_library ? "📚 library" : null].filter(Boolean).join(" · ")}
                          </div>
                        </div>
                        <span className="tabular-nums">{fmtTime(s.seconds)}</span>
                        <button
                          type="button"
                          title="Delete session"
                          className="text-muted-foreground hover:text-destructive"
                          onClick={() => remove(s.id)}
                        >
                          ✕
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}
        </div>
      </DialogContent>
    </Dialog>
  );
}
