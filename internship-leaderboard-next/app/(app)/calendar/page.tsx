import Link from "next/link";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { examCountdown } from "@/lib/exam-countdown";
import { CALENDAR_USERS, canonicalUser, calendarDays, examTimeHM, formatExamMeta, toDateStr } from "@/lib/calendar";
import { Card, CardContent } from "@/components/ui/card";
import { ExamChecklist } from "@/components/calendar/ExamChecklist";

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

  return (
    // Breaks out of the layout's max-w-5xl container (like calendar.php's
    // `main.container { max-width: none }` override) — a 7-column week grid
    // needs more room than the rest of the app.
    <div className="relative left-1/2 w-screen -translate-x-1/2 px-4 sm:px-6 lg:px-10">
      <div className="mx-auto max-w-[1400px] space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="space-y-3">
            <h1 className="text-2xl font-semibold tracking-tight">Exam Calendar — July 2026</h1>
            <div className="flex flex-wrap gap-2">
              {CALENDAR_USERS.map((u) => (
                <Link
                  key={u}
                  href={`/calendar?user=${encodeURIComponent(u)}`}
                  className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${
                    u === selectedUser
                      ? "border-transparent bg-primary text-primary-foreground"
                      : "text-muted-foreground hover:border-foreground/30 hover:text-foreground"
                  }`}
                >
                  {u}
                </Link>
              ))}
            </div>
          </div>

          <div className="flex gap-3">
            <Card>
              <CardContent className="flex flex-col items-center gap-1 px-6 py-4">
                <span className="text-2xl font-bold text-emerald-500">
                  {countdown.passedExams}/{countdown.totalExams}
                </span>
                <span className="text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  exams written
                </span>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="flex flex-col items-center gap-1 px-6 py-4">
                <span className="text-2xl font-bold text-destructive">
                  {countdown.daysUntilExam > 0
                    ? `D-${countdown.daysUntilExam}`
                    : countdown.daysUntilExam === 0
                      ? "D-Day"
                      : "🎉"}
                </span>
                <span className="text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {countdown.daysUntilExam > 0
                    ? "until first exam"
                    : countdown.daysUntilExam === 0
                      ? "first exam today"
                      : "exams done"}
                </span>
              </CardContent>
            </Card>
          </div>
        </div>

        <p className="rounded-md border bg-muted/40 px-4 py-2.5 text-sm text-muted-foreground">
          Viewing exams for <strong className="text-foreground">{selectedUser}</strong>.{" "}
          {canEdit ? (
            "Toggle the checkboxes to add or remove your highlights."
          ) : (
            <em>Read only — switch to your own tab to edit.</em>
          )}
        </p>

        <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
          <aside className="w-full shrink-0 lg:w-72">
            <Card>
              <CardContent className="space-y-3 py-4">
                <div>
                  <h3 className="font-semibold">{selectedUser}&apos;s exams</h3>
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
              const todays = examsByDay.get(key) ?? [];
              return (
                <Card key={key} className={weekend ? "bg-muted/30" : ""}>
                  <CardContent className="flex min-h-40 flex-col gap-2 py-4">
                    <div className="text-lg font-bold">
                      {d.getUTCDate()}
                      <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                        {d.toLocaleDateString("en-US", { month: "short", timeZone: "UTC" })}
                      </span>
                    </div>
                    {todays.map((e) => {
                      const isSel = selectedIds.has(e.id);
                      return (
                        <div
                          key={e.id}
                          title={`${e.title} — ${e.professor ?? ""}`}
                          className={`rounded-md border px-2.5 py-1.5 text-sm leading-snug ${
                            isSel
                              ? "border-primary/40 bg-primary/10 text-foreground"
                              : "border-transparent bg-muted/40 text-muted-foreground opacity-60"
                          }`}
                        >
                          <span className="block font-semibold">{examTimeHM(e.examTime)}</span>
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
