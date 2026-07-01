"use client";

import { useMemo, useState } from "react";

import { fmtTime, mondayOf, studyDayStart, toDateStr } from "@/lib/study-format";
import { abbrevModule } from "@/lib/study-colors";
import type { AggregatedSession } from "@/lib/study-sessions";
import type { StudyModuleOption } from "@/lib/study-modules";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type Period = "overall" | "weekly" | "daily";
type View = "all" | "module" | "library";

const PERIOD_LABELS: Record<Period, string> = {
  overall: "total studied",
  weekly: "this week",
  daily: "today",
};

const RANK_CARD_CLASS = [
  "border-amber-400/60 bg-amber-400/5",
  "border-slate-300/60 bg-slate-300/5",
  "border-orange-600/50 bg-orange-600/5",
  "border-amber-900/40 bg-amber-900/5",
];

const MEDAL_CLASS = [
  "bg-gradient-to-br from-amber-300 to-amber-500",
  "bg-gradient-to-br from-slate-200 to-slate-400",
  "bg-gradient-to-br from-orange-400 to-orange-700",
  "bg-gradient-to-br from-amber-800 to-amber-950",
];

export function Podium({
  sessions,
  allUsers,
  userAvatars,
  modules,
  moduleColors,
}: {
  sessions: AggregatedSession[];
  allUsers: string[];
  userAvatars: Record<string, string>;
  modules: StudyModuleOption[];
  moduleColors: Record<string, string>;
}) {
  const [period, setPeriod] = useState<Period>("overall");
  const [view, setView] = useState<View>("all");
  const [selectedModule, setSelectedModule] = useState<string | null>(null);
  const [moduleFilterOpen, setModuleFilterOpen] = useState(false);
  const [pickerValue, setPickerValue] = useState<string | null>(null);
  const [detail, setDetail] = useState<{ username: string; rank: number; secs: number } | null>(null);

  // Resolved lazily (not on mount) since it's only needed once the user
  // switches into module view — event handlers run client-only, so reading
  // localStorage here (unlike in an effect) needs no SSR guard.
  function defaultModule(): string | null {
    const stored = window.localStorage.getItem("podiumSelectedModule");
    if (stored && modules.some((m) => m.name === stored)) return stored;
    if (modules.some((m) => m.name === "Compiler")) return "Compiler";
    return modules[0]?.name ?? null;
  }

  const libraryOnly = view === "library";

  const filtered = useMemo(() => {
    let out = sessions;
    if (period !== "overall") {
      const today = studyDayStart(new Date());
      const todayStr = toDateStr(today);
      if (period === "daily") {
        out = out.filter((s) => s.date === todayStr);
      } else {
        const weekStartStr = toDateStr(mondayOf(today));
        out = out.filter((s) => s.date >= weekStartStr && s.date <= todayStr);
      }
    }
    if (libraryOnly) out = out.filter((s) => s.at_library);
    if (view === "module" && selectedModule) out = out.filter((s) => s.module === selectedModule);
    return out;
  }, [sessions, period, libraryOnly, view, selectedModule]);

  const ranked = useMemo(() => {
    const totals = new Map<string, number>();
    for (const u of allUsers) totals.set(u, 0);
    for (const s of filtered) totals.set(s.username, (totals.get(s.username) ?? 0) + s.seconds);
    return [...totals.entries()].sort((a, b) => b[1] - a[1]);
  }, [filtered, allUsers]);

  const sublabel =
    PERIOD_LABELS[period] +
    (view === "module" && selectedModule ? ` · ${abbrevModule(selectedModule)}` : libraryOnly ? " · at the library" : "");

  function selectView(v: View) {
    setView(v);
    if (v === "module") {
      setSelectedModule((prev) => prev ?? defaultModule());
    } else {
      setSelectedModule(null);
    }
  }

  function openModuleFilter() {
    setPickerValue(selectedModule ?? defaultModule());
    setModuleFilterOpen(true);
  }

  function applyModuleFilter() {
    if (pickerValue) {
      setSelectedModule(pickerValue);
      window.localStorage.setItem("podiumSelectedModule", pickerValue);
      setView("module");
    }
    setModuleFilterOpen(false);
  }

  const detailSessions = detail ? filtered.filter((s) => s.username === detail.username) : [];
  const detailModuleTotals = new Map<string, number>();
  for (const s of detailSessions) {
    if (!s.module) continue;
    detailModuleTotals.set(s.module, (detailModuleTotals.get(s.module) ?? 0) + s.seconds);
  }
  const detailByModule = [...detailModuleTotals.entries()].sort((a, b) => b[1] - a[1]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="text-lg font-semibold">🏆 Most hours studied</h2>
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex overflow-hidden rounded-md border text-sm">
            {(["overall", "weekly", "daily"] as Period[]).map((p) => (
              <button
                key={p}
                type="button"
                onClick={() => setPeriod(p)}
                className={`px-3 py-1.5 ${period === p ? "bg-accent font-medium" : "text-muted-foreground hover:bg-accent/50"}`}
              >
                {p === "overall" ? "Overall" : p === "weekly" ? "This week" : "Today"}
              </button>
            ))}
          </div>
          <div className="flex overflow-hidden rounded-md border text-sm">
            <button
              type="button"
              onClick={() => selectView("all")}
              className={`px-3 py-1.5 ${view === "all" ? "bg-accent font-medium" : "text-muted-foreground hover:bg-accent/50"}`}
            >
              All modules
            </button>
            <button
              type="button"
              onClick={() => selectView("module")}
              onDoubleClick={openModuleFilter}
              className={`px-3 py-1.5 ${view === "module" ? "bg-accent font-medium" : "text-muted-foreground hover:bg-accent/50"}`}
              title="Double click to change module"
            >
              {view === "module" && selectedModule ? abbrevModule(selectedModule) : "Per module"}
            </button>
            <button
              type="button"
              onClick={() => selectView("library")}
              className={`px-3 py-1.5 ${view === "library" ? "bg-accent font-medium" : "text-muted-foreground hover:bg-accent/50"}`}
            >
              Library only
            </button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {ranked.slice(0, 4).map(([username, secs], i) => {
          const initial = (username[0] ?? "?").toUpperCase();
          return (
            <div
              key={username}
              role="button"
              tabIndex={0}
              aria-label={`View ${username}'s study breakdown`}
              onClick={() => setDetail({ username, rank: i + 1, secs })}
              onKeyDown={(e) => {
                if (e.key === "Enter" || e.key === " ") setDetail({ username, rank: i + 1, secs });
              }}
              className={`cursor-pointer space-y-2 rounded-xl border-2 p-4 text-center ${RANK_CARD_CLASS[i]}`}
            >
              <div className="mx-auto size-14 overflow-hidden rounded-full bg-muted">
                {userAvatars[username] ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={userAvatars[username]} alt="" className="size-full object-cover" />
                ) : (
                  <span className="flex size-full items-center justify-center text-lg font-semibold">
                    {initial}
                  </span>
                )}
              </div>
              <div className="font-semibold">{username}</div>
              <div className="text-xl font-bold">{fmtTime(secs)}</div>
              <div className="text-xs text-muted-foreground">{sublabel}</div>
              <div className={`mx-auto flex size-6 items-center justify-center rounded-full text-xs font-bold text-white ${MEDAL_CLASS[i]}`}>
                {i + 1}
              </div>
            </div>
          );
        })}
      </div>

      <Dialog open={moduleFilterOpen} onOpenChange={setModuleFilterOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Filter podium by module</DialogTitle>
          </DialogHeader>
          <div className="space-y-1 rounded-md border p-1">
            {modules.map((m) => (
              <div
                key={m.name}
                role="button"
                tabIndex={0}
                onClick={() => setPickerValue(m.name)}
                className={`flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent ${
                  pickerValue === m.name ? "bg-accent" : ""
                }`}
              >
                <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: moduleColors[m.name] ?? "#888" }} />
                {m.name}
              </div>
            ))}
          </div>
          <DialogFooter>
            <Button variant="ghost" onClick={() => setModuleFilterOpen(false)}>
              Cancel
            </Button>
            <Button onClick={applyModuleFilter}>Apply</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={detail !== null} onOpenChange={(v) => !v && setDetail(null)}>
        <DialogContent>
          {detail && (
            <>
              <DialogHeader>
                <DialogTitle>{detail.username}</DialogTitle>
              </DialogHeader>
              <div className="text-center">
                <div className="mx-auto mb-2 size-24 overflow-hidden rounded-full bg-muted">
                  {userAvatars[detail.username] ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={userAvatars[detail.username]} alt="" className="size-full object-cover" />
                  ) : (
                    <span className="flex size-full items-center justify-center text-3xl font-semibold">
                      {(detail.username[0] ?? "?").toUpperCase()}
                    </span>
                  )}
                </div>
                <div className="text-2xl font-bold">{fmtTime(detail.secs)}</div>
                <div className="text-sm text-muted-foreground">{sublabel}</div>
              </div>

              {detailByModule.length > 0 ? (
                <div className="space-y-2">
                  <p className="text-sm font-medium">Module breakdown</p>
                  {detailByModule.map(([m, secs]) => (
                    <div key={m} className="flex items-center gap-2 text-sm">
                      <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: moduleColors[m] ?? "#888" }} />
                      <span className="w-28 shrink-0 truncate">{m}</span>
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                          className="h-full rounded-full"
                          style={{
                            width: `${Math.round((secs / detail.secs) * 100)}%`,
                            backgroundColor: moduleColors[m] ?? "#888",
                          }}
                        />
                      </div>
                      <span className="shrink-0 tabular-nums">{fmtTime(secs)}</span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No sessions logged for this period.</p>
              )}
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
