"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import { buildModuleColors } from "@/lib/study-colors";
import { fmtClock, fmtTime } from "@/lib/study-format";
import type { StudyModuleOption } from "@/lib/study-modules";
import type { AggregatedSession } from "@/lib/study-sessions";
import type { MyStudyStatus, StudyingEntry, RecapBlock } from "@/lib/study-status";
import type { ExamCountdown } from "@/lib/exam-countdown";
import { useRealtimeRoom } from "@/lib/realtime-client";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/layout/PageHeader";
import { Dock } from "@/components/study/Dock";
import { Chart } from "@/components/study/Chart";
import { Podium } from "@/components/study/Podium";
import { ModuleModal, type ModuleModalState } from "@/components/study/ModuleModal";
import { StopModal } from "@/components/study/StopModal";
import { ManualLogModal } from "@/components/study/ManualLogModal";
import { ManageSessionsModal } from "@/components/study/ManageSessionsModal";
import { FocusMode } from "@/components/study/FocusMode";

type StatusPayload = {
  me: MyStudyStatus;
  studying: StudyingEntry[];
  recap: RecapBlock[];
  recap_prev: RecapBlock[];
  custom_added?: boolean;
  logged?: boolean;
  count?: number;
  seconds?: number;
  modules?: string[];
};

export function StudyCounterApp({
  myUsername,
  modules: initialModules,
  initialStatus,
  initialSessions,
  userAvatars,
  allUsers,
  examCountdown,
}: {
  myUsername: string;
  modules: StudyModuleOption[];
  initialStatus: {
    me: MyStudyStatus;
    studying: StudyingEntry[];
    recap: RecapBlock[];
    recap_prev: RecapBlock[];
  };
  initialSessions: AggregatedSession[];
  userAvatars: Record<string, string>;
  allUsers: string[];
  examCountdown: ExamCountdown;
}) {
  const [modules, setModules] = useState(initialModules);
  const [sessions, setSessions] = useState(initialSessions);
  const [myState, setMyState] = useState(initialStatus.me);
  const [studying, setStudying] = useState(initialStatus.studying);
  const [recapByDay, setRecapByDay] = useState<{ 0: RecapBlock[]; 1: RecapBlock[] }>({
    0: initialStatus.recap,
    1: initialStatus.recap_prev,
  });
  const [syncTs, setSyncTs] = useState(() => Date.now());

  const [moduleModalState, setModuleModalState] = useState<ModuleModalState | null>(null);
  const [stopModalOpen, setStopModalOpen] = useState(false);
  const [manualModalOpen, setManualModalOpen] = useState(false);
  const [manageModalOpen, setManageModalOpen] = useState(false);
  const [logFeedback, setLogFeedback] = useState<string | null>(null);
  const [focusMode, setFocusMode] = useState(false);

  useEffect(() => {
    // localStorage isn't available during the server render pass, so this
    // one-time mount sync (not a derived-from-props case) has to live in an
    // effect rather than render.
    const stored = window.localStorage.getItem("studyFocusMode");
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (stored === "1") setFocusMode(true);
  }, []);

  const moduleColors = useMemo(() => buildModuleColors(modules.map((m) => m.name)), [modules]);

  const applyPayload = useCallback((res: StatusPayload) => {
    setMyState(res.me);
    setStudying(res.studying);
    setRecapByDay({ 0: res.recap, 1: res.recap_prev });
    setSyncTs(Date.now());
  }, []);

  const timerAction = useCallback(
    async (action: string, extra?: Record<string, unknown>) => {
      const response = await fetch("/api/study/timer", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, ...extra }),
      });
      if (!response.ok) {
        throw new Error((await response.text()) || "Timer error.");
      }
      const res: StatusPayload = await response.json();
      if (res.custom_added) {
        setTimeout(() => window.location.reload(), 400);
      }
      applyPayload(res);
      return res;
    },
    [applyPayload],
  );

  const loadStatus = useCallback(() => {
    fetch("/api/study/status")
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((res: StatusPayload) => applyPayload(res))
      .catch(() => {});
  }, [applyPayload]);

  const loadData = useCallback(() => {
    fetch("/api/study/data")
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((res: { modules: StudyModuleOption[]; sessions: AggregatedSession[] }) => {
        setModules(res.modules);
        setSessions(res.sessions);
      })
      .catch(() => {});
  }, []);

  // Real-time push instead of the old 15s poll: any study timer action
  // anywhere (start/pause/module switch/log) notifies the "study" room and
  // this client refetches once. The elapsed clocks keep ticking locally off
  // syncTs between pushes; visibility/pageshow refetches remain the
  // catch-up path for a hidden tab or a realtime server that's down.
  useRealtimeRoom("study", "update", () => {
    if (!document.hidden) loadStatus();
  });

  useEffect(() => {
    const onVisible = () => {
      if (!document.hidden) loadStatus();
    };
    const onPageShow = (e: PageTransitionEvent) => {
      if (e.persisted) loadStatus();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("pageshow", onPageShow);
    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("pageshow", onPageShow);
    };
  }, [loadStatus]);

  // Tab title clock: the running session's timer (or the break timer while
  // paused) in front of the page title. This is the one thing users actually
  // rely on while the tab is backgrounded, so it ticks unconditionally —
  // extrapolated from the last sync just like the visible chips.
  useEffect(() => {
    const base = document.title;
    const tick = () => {
      const d = Math.floor((Date.now() - syncTs) / 1000);
      if (!myState.active) {
        document.title = base;
      } else if (myState.running) {
        document.title = `${fmtClock(myState.elapsed + d)} · ${base}`;
      } else {
        document.title = `Break ${fmtClock(myState.break_elapsed + d)} · ${base}`;
      }
    };
    tick();
    const id = setInterval(tick, 1000);
    return () => {
      clearInterval(id);
      document.title = base;
    };
  }, [myState, syncTs]);

  function toggleFocusMode() {
    setFocusMode((prev) => {
      const next = !prev;
      window.localStorage.setItem("studyFocusMode", next ? "1" : "0");
      return next;
    });
  }

  function openStartModal() {
    setModuleModalState({
      title: "Start studying",
      hint: "Pick a module to track this session.",
      confirmLabel: "Start studying",
      currentModule: null,
      onConfirm: (payload) => {
        setModuleModalState(null);
        timerAction("presence", payload).catch((err) => alert(err.message));
      },
    });
  }

  function openSwitchModal() {
    if (!myState.active) return;
    setModuleModalState({
      title: "Change module",
      hint: "Switching keeps the time so far under the old module and starts a fresh session — both show up separately.",
      confirmLabel: "Change module",
      currentModule: myState.module,
      onConfirm: (payload) => {
        setModuleModalState(null);
        timerAction("set_module", payload).catch((err) => alert(err.message));
      },
    });
  }

  function handleStudyingToggle() {
    if (!myState.active) {
      openStartModal();
    } else {
      setStopModalOpen(true);
    }
  }

  function handleBreakToggle() {
    if (!myState.active) return;
    timerAction(myState.running ? "pause" : "resume").catch((err) => alert(err.message));
  }

  function handleLibraryToggle() {
    if (!myState.active) return;
    timerAction("library").catch((err) => alert(err.message));
  }

  function handleDiscard() {
    timerAction("stop").catch((err) => alert(err.message));
  }

  async function handleSaveLog(rows: { module: string; seconds: number }[]) {
    setLogFeedback("Saving…");
    try {
      const res = await timerAction("log", { sessions: rows });
      if (res.logged) {
        const n = res.count ?? (res.modules?.length ?? 0);
        setLogFeedback(`Logged ${n} session${n === 1 ? "" : "s"} · ${fmtTime(res.seconds ?? 0)}.`);
        loadData();
      } else {
        setLogFeedback("Session stopped — nothing logged.");
      }
    } catch (err) {
      setLogFeedback(err instanceof Error ? err.message : "Save failed.");
    }
  }

  function handleManualLogged(customAdded: boolean) {
    if (customAdded) {
      setTimeout(() => window.location.reload(), 600);
    } else {
      loadData();
    }
  }

  async function handleDeleteModule(name: string) {
    if (!confirm(`Remove "${name}" from the module list?\nPast sessions using it are kept.`)) return;
    const response = await fetch("/api/study/delete-module", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name }),
    });
    if (!response.ok) {
      alert((await response.text()) || "Could not delete module.");
      return;
    }
    window.location.reload();
  }

  function handleSessionsChanged() {
    loadData();
    loadStatus();
  }

  const examLine =
    examCountdown.daysUntilExam === null
      ? { value: "done", label: "exams finished" }
      : examCountdown.daysUntilExam > 0
        ? { value: `D-${examCountdown.daysUntilExam}`, label: "until next exam" }
        : { value: "D-Day", label: "next exam today" };

  return (
    <div className="space-y-8">
      <PageHeader
        eyebrow="Grind together"
        title={
          <>
            Study <span className="gradient-text">Counter</span>
          </>
        }
        description="Log your hours, see who is studying right now, and race for the podium."
        actions={
          <Button variant="outline" onClick={toggleFocusMode}>
            Focus mode
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-7 lg:grid-cols-[minmax(0,1fr)_320px]">
      <div className="space-y-8 lg:order-1">
        <div className="rise rise-1 grid grid-cols-1 gap-8 md:grid-cols-[220px_minmax(0,1fr)]">
          <div className="space-y-4">
            <div className="glow-card overflow-hidden rounded-xl border bg-card p-4 text-center backdrop-blur-md">
              <div className="tabular font-heading text-4xl font-bold text-destructive">{examLine.value}</div>
              <div className="mt-1 text-[0.7rem] font-semibold tracking-wider text-muted-foreground uppercase">
                {examLine.label}
              </div>
              <div className="mt-3 border-t pt-3">
                <div className="tabular font-heading text-xl font-semibold text-emerald-400">
                  {examCountdown.passedExams}/{examCountdown.totalExams}
                </div>
                <div className="text-[0.7rem] font-semibold tracking-wider text-muted-foreground uppercase">
                  exams written
                </div>
              </div>
            </div>
          </div>

          <Podium
            sessions={sessions}
            allUsers={allUsers}
            userAvatars={userAvatars}
            modules={modules}
            moduleColors={moduleColors}
          />
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-[auto_minmax(0,1fr)]">
          <div className="glow-card w-full space-y-3 overflow-hidden rounded-xl border bg-card p-4 md:w-64">
            <h2 className="font-semibold">Session Management</h2>
            <Button className="w-full" variant="outline" onClick={() => setManualModalOpen(true)}>
              Manual logging
            </Button>
            <Button className="w-full" variant="outline" onClick={() => setManageModalOpen(true)}>
              Manage previous sessions
            </Button>
            {logFeedback && <p className="text-sm text-muted-foreground">{logFeedback}</p>}
          </div>

          <Chart sessions={sessions} />
        </div>
      </div>

      <div className="lg:order-2">
        <Dock
          myState={myState}
          studying={studying}
          syncTs={syncTs}
          recapByDay={recapByDay}
          onStudyingToggle={handleStudyingToggle}
          onChangeModule={openSwitchModal}
          onBreakToggle={handleBreakToggle}
          onLibraryToggle={handleLibraryToggle}
        />
      </div>

      <ModuleModal
        state={moduleModalState}
        onClose={() => setModuleModalState(null)}
        modules={modules}
        moduleColors={moduleColors}
      />

      <StopModal
        open={stopModalOpen}
        parts={myState.active ? myState.parts.filter((p) => p.type === "module" && p.module) : []}
        moduleColors={moduleColors}
        onClose={() => setStopModalOpen(false)}
        onDiscard={handleDiscard}
        onSave={handleSaveLog}
      />

      <ManualLogModal
        open={manualModalOpen}
        onClose={() => setManualModalOpen(false)}
        modules={modules}
        moduleColors={moduleColors}
        onLogged={handleManualLogged}
        onDeleteModule={handleDeleteModule}
      />

      <ManageSessionsModal
        open={manageModalOpen}
        onClose={() => setManageModalOpen(false)}
        moduleColors={moduleColors}
        onChanged={handleSessionsChanged}
      />

      <FocusMode
        active={focusMode}
        onExit={toggleFocusMode}
        myUsername={myUsername}
        myState={myState}
        sessions={sessions}
        onStart={openStartModal}
        onBreakToggle={handleBreakToggle}
        onStop={() => setStopModalOpen(true)}
      />
      </div>
    </div>
  );
}
