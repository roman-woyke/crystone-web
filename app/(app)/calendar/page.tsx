import { BookOpenCheck, CalendarClock } from "lucide-react";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbToday, toDbDateStr } from "@/lib/dates";
import { examCountdown } from "@/lib/exam-countdown";
import { CALENDAR_USERS, canonicalUser, calendarDays, examTimeHM, formatExamMeta, toDateStr } from "@/lib/calendar";
import { Card, CardContent } from "@/components/ui/card";
import { ExamChecklist } from "@/components/calendar/ExamChecklist";
import { PageHeader } from "@/components/layout/PageHeader";
import { PillTabs } from "@/components/layout/PillTabs";
import { StatTile } from "@/components/layout/StatTile";
import { InfoBanner } from "@/components/layout/InfoBanner";

const WEEKDAY_LABELS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

export default async function CalendarPage({
  searchParams,
}: {
  searchParams: Promise<{ user?: string }>;
}) {
  const session = await auth();
  const myUser = canonicalUser(session!.user.name);
  const { user: userParam } = await searchParams;
  const selectedUser = canonicalUser(userParam) ?? myUser ?? "Roman";
  const canEdit = myUser !== null && myUser === selectedUser;

  const [exams, selected, countdown] = await Promise.all([
    prisma.exam.findMany({ orderBy: [{ examDate: "asc" }, { examTime: "asc" }] }),
    prisma.userExam.findMany({ where: { username: selectedUser }, select: { examId: true } }),
    examCountdown(selectedUser),
  ]);

  const selectedIds = new Set(selected.map((s) => s.examId));

  const examsByDay = new Map<string, typeof exams>();
  for (const e of exams) {
    const key = toDateStr(e.examDate);
    const list = examsByDay.get(key) ?? [];
    list.push(e);
    examsByDay.set(key, list);
  }

  const days = calendarDays();
  const todayStr = toDbDateStr(dbToday());

  const dDay =
    countdown.daysUntilExam === null ? "done" : countdown.daysUntilExam > 0 ? `D-${countdown.daysUntilExam}` : "D-Day";
  const dDayLabel =
    countdown.daysUntilExam === null
      ? "exams finished"
      : countdown.daysUntilExam > 0
        ? "until next exam"
        : "next exam today";

  return (
    // Breaks out of the layout's max-width container (like calendar.php's
    // `main.container { max-width: none }` override) — a 7-column week grid
    // needs more room than the rest of the app.
    <div className="relative left-1/2 w-screen -translate-x-1/2 px-4 sm:px-6 lg:px-10">
      <div className="mx-auto max-w-[1400px] space-y-6">
        <PageHeader
          eyebrow="Exam season"
          title={
            <>
              Exam <span className="gradient-text">Calendar</span>
            </>
          }
          description="July 2026 — everyone's exams in one shared view."
          actions={
            <div className="grid w-full grid-cols-2 gap-3 sm:w-auto">
              <StatTile
                label="exams written"
                value={`${countdown.passedExams}/${countdown.totalExams}`}
                icon={<BookOpenCheck />}
                accent="#34d399"
              />
              <StatTile label={dDayLabel} value={dDay} icon={<CalendarClock />} accent="#f87171" />
            </div>
          }
        />

        <div className="rise rise-1 flex flex-wrap items-center gap-4">
          <PillTabs
            tabs={CALENDAR_USERS.map((u) => ({
              href: `/calendar?user=${encodeURIComponent(u)}`,
              label: u,
              active: u === selectedUser,
            }))}
          />
        </div>

        <div className="rise rise-2">
          <InfoBanner>
            Viewing exams for <strong className="text-foreground">{selectedUser}</strong>.{" "}
            {canEdit ? (
              "Toggle the checkboxes to add or remove your highlights."
            ) : (
              <em>Read only — switch to your own tab to edit.</em>
            )}
          </InfoBanner>
        </div>

        <div className="rise rise-3 flex flex-col gap-6 lg:flex-row lg:items-start">
          <aside className="w-full shrink-0 lg:w-72">
            <Card>
              <CardContent className="space-y-3 py-4">
                <div>
                  <h3 className="font-heading font-semibold">{selectedUser}&apos;s exams</h3>
                  <p className="text-xs text-muted-foreground">
                    {canEdit ? "Check the exams you're writing." : `Selections set by ${selectedUser}.`}
                  </p>
                </div>
                <ExamChecklist
                  exams={exams.map((e) => ({
                    id: e.id,
                    title: e.title,
                    professor: e.professor,
                    meta: formatExamMeta(e.examDate, e.examTime),
                  }))}
                  selectedIds={[...selectedIds]}
                  canEdit={canEdit}
                  targetUsername={selectedUser}
                />
              </CardContent>
            </Card>
          </aside>

          <div className="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-7">
            {WEEKDAY_LABELS.map((w) => (
              <div
                key={w}
                className="hidden text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground lg:block"
              >
                {w}
              </div>
            ))}
            {days.map((d) => {
              const key = toDateStr(d);
              const dow = d.getUTCDay();
              const weekend = dow === 0 || dow === 6;
              const isToday = key === todayStr;
              const todays = examsByDay.get(key) ?? [];
              return (
                <Card
                  key={key}
                  className={`${weekend ? "bg-muted/20" : ""} ${
                    isToday ? "ring-2 ring-primary/60" : ""
                  }`}
                >
                  <CardContent className="flex min-h-40 flex-col gap-2 py-4">
                    <div className="flex items-baseline gap-1.5">
                      <span
                        className={`font-heading text-lg font-bold ${
                          isToday
                            ? "flex size-7 items-center justify-center rounded-full bg-primary text-sm text-primary-foreground"
                            : ""
                        }`}
                      >
                        {d.getUTCDate()}
                      </span>
                      <span className="text-xs font-normal text-muted-foreground">
                        {d.toLocaleDateString("en-US", { month: "short", timeZone: "UTC" })}
                      </span>
                    </div>
                    {todays.map((e) => {
                      const isSel = selectedIds.has(e.id);
                      return (
                        <div
                          key={e.id}
                          title={`${e.title} — ${e.professor ?? ""}`}
                          className={`rounded-md border px-2.5 py-1.5 text-sm leading-snug transition-colors ${
                            isSel
                              ? "border-primary/40 bg-primary/15 text-foreground shadow-[0_0_16px_-6px_rgba(139,92,246,0.5)]"
                              : "border-transparent bg-muted/40 text-muted-foreground opacity-60"
                          }`}
                        >
                          <span className={`block font-semibold ${isSel ? "text-primary" : ""}`}>
                            {examTimeHM(e.examTime)}
                          </span>
                          <span className="block break-words">{e.title}</span>
                        </div>
                      );
                    })}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}
