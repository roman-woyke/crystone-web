"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";

import { fmtClock, fmtTime } from "@/lib/study-format";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { EditProjectDialog } from "@/components/projects/EditProjectDialog";
import { LogTimeDialog } from "@/components/projects/LogTimeDialog";

export type ProjectEntry = {
  id: number;
  seconds: number;
  note: string | null;
  loggedAtLabel: string;
};

export type ProjectData = {
  id: number;
  name: string;
  description: string | null;
  color: string;
  totalSeconds: number;
  todaySeconds: number;
  weekSeconds: number;
  entryCount: number;
  entries: ProjectEntry[];
};

function timerKey(id: number) {
  return `project_timer_${id}`;
}

export function ProjectCard({ project, canEdit }: { project: ProjectData; canEdit: boolean }) {
  const router = useRouter();
  const [running, setRunning] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const [busy, setBusy] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [logOpen, setLogOpen] = useState(false);
  const startRef = useRef<number | null>(null);

  useEffect(() => {
    // localStorage isn't available during the server render pass, so this
    // one-time mount sync (not a derived-from-props case) has to live in an
    // effect rather than render.
    const stored = window.localStorage.getItem(timerKey(project.id));
    const start = stored ? parseInt(stored, 10) : NaN;
    if (!start) return;
    startRef.current = start;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setRunning(true);
    setElapsed(Math.max(0, Math.floor((Date.now() - start) / 1000)));
  }, [project.id]);

  useEffect(() => {
    if (!running) return;
    const interval = setInterval(() => {
      if (startRef.current) setElapsed(Math.max(0, Math.floor((Date.now() - startRef.current) / 1000)));
    }, 1000);
    return () => clearInterval(interval);
  }, [running]);

  function start() {
    const now = Date.now();
    startRef.current = now;
    window.localStorage.setItem(timerKey(project.id), String(now));
    setRunning(true);
    setElapsed(0);
  }

  async function stop() {
    const start = startRef.current;
    window.localStorage.removeItem(timerKey(project.id));
    setRunning(false);
    startRef.current = null;
    if (!start) return;

    const seconds = Math.floor((Date.now() - start) / 1000);
    if (seconds < 1) return;

    setBusy(true);
    await fetch("/api/time-entries", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ project_id: project.id, seconds, note: "" }),
    });
    setBusy(false);
    router.refresh();
  }

  async function deleteProject() {
    if (!confirm("Delete this project and all its logged time? This cannot be undone.")) return;
    window.localStorage.removeItem(timerKey(project.id));
    await fetch(`/api/projects/${project.id}`, { method: "DELETE" });
    router.refresh();
  }

  async function deleteEntry(entryId: number) {
    if (!confirm("Remove this session?")) return;
    await fetch(`/api/time-entries/${entryId}`, { method: "DELETE" });
    router.refresh();
  }

  const visibleEntries = project.entries.slice(0, 4);
  const moreCount = project.entries.length - visibleEntries.length;

  return (
    <Card style={{ borderTop: `3px solid ${project.color}` }} className="flex flex-col">
      <CardContent className="flex flex-1 flex-col gap-3">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2.5">
            <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
            <h3 className="truncate text-lg font-semibold">{project.name}</h3>
          </div>
          {canEdit && (
            <div className="flex shrink-0 gap-1">
              <Button variant="ghost" size="icon-sm" onClick={() => setEditOpen(true)} title="Edit project">
                ✏️
              </Button>
              <Button variant="ghost" size="icon-sm" onClick={deleteProject} title="Delete project">
                🗑️
              </Button>
            </div>
          )}
        </div>

        <p className={`text-sm ${project.description ? "text-muted-foreground" : "italic text-muted-foreground/70"}`}>
          {project.description ?? "No description yet."}
        </p>

        <div className="flex items-baseline gap-2">
          <span className="text-3xl font-bold" style={{ color: project.color }}>
            {fmtTime(project.totalSeconds)}
          </span>
          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">contributed</span>
        </div>

        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
          <span>
            Today <strong className="text-foreground">{fmtTime(project.todaySeconds)}</strong>
          </span>
          <span>
            This week <strong className="text-foreground">{fmtTime(project.weekSeconds)}</strong>
          </span>
          <span>
            <strong className="text-foreground">{project.entryCount}</strong> sessions
          </span>
        </div>

        {canEdit && (
          <div className="flex items-center gap-2.5">
            <span className="min-w-[92px] font-mono text-lg font-semibold tabular-nums">{fmtClock(elapsed)}</span>
            <Button size="sm" variant={running ? "destructive" : "default"} disabled={busy} onClick={() => (running ? stop() : start())}>
              {running ? "■ Stop" : "▶ Start"}
            </Button>
            <Button size="sm" variant="outline" onClick={() => setLogOpen(true)}>
              + Log
            </Button>
          </div>
        )}

        <div className="mt-auto border-t pt-3">
          {visibleEntries.length > 0 ? (
            <>
              <p className="mb-1.5 text-[0.7rem] font-semibold uppercase tracking-wide text-muted-foreground">
                Recent sessions
              </p>
              <div className="space-y-1">
                {visibleEntries.map((entry) => (
                  <div key={entry.id} className="flex items-center gap-2 text-sm">
                    <span className="min-w-14 font-semibold tabular-nums">{fmtTime(entry.seconds)}</span>
                    <span className="flex-1 truncate text-muted-foreground">{entry.note || "—"}</span>
                    <span className="shrink-0 text-xs text-muted-foreground">{entry.loggedAtLabel}</span>
                    {canEdit && (
                      <button
                        type="button"
                        onClick={() => deleteEntry(entry.id)}
                        className="shrink-0 rounded px-1.5 text-xs text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        title="Remove session"
                      >
                        ✕
                      </button>
                    )}
                  </div>
                ))}
              </div>
              {moreCount > 0 && (
                <p className="mt-1.5 text-xs text-muted-foreground">
                  + {moreCount} more session{moreCount === 1 ? "" : "s"}
                </p>
              )}
            </>
          ) : (
            <p className="text-[0.7rem] font-semibold uppercase tracking-wide text-muted-foreground">
              No sessions logged yet
            </p>
          )}
        </div>
      </CardContent>

      {canEdit && (
        <>
          <EditProjectDialog
            open={editOpen}
            onClose={() => setEditOpen(false)}
            projectId={project.id}
            initialName={project.name}
            initialDescription={project.description ?? ""}
          />
          <LogTimeDialog open={logOpen} onClose={() => setLogOpen(false)} projectId={project.id} />
        </>
      )}
    </Card>
  );
}
