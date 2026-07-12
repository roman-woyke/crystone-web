import { prisma } from "@/lib/prisma";
import { dbNow, dbToday } from "@/lib/dates";

export type ExamCountdown = {
  // Days until the next exam this user is actually writing; null once all
  // of their selected exams are behind them (or they selected none).
  daysUntilExam: number | null;
  passedExams: number;
  totalExams: number;
};

// Days until the next of *this user's* selected exams (not the earliest
// exam overall — someone who skips the first exams shouldn't see a
// countdown to a day they're not writing anything), plus how many of their
// selected exams have already happened (exam_date + exam_time combined,
// compared against now).
export async function examCountdown(username: string): Promise<ExamCountdown> {
  const exams = await prisma.exam.findMany({
    orderBy: [{ examDate: "asc" }, { examTime: "asc" }],
  });

  const selected = await prisma.userExam.findMany({
    where: { username },
    select: { examId: true },
  });
  const selectedSet = new Set(selected.map((s) => s.examId));

  const now = dbNow();
  let passedExams = 0;
  let nextExamDate: Date | null = null;
  for (const e of exams) {
    if (!selectedSet.has(e.id)) continue;
    const examDateTime = new Date(
      Date.UTC(
        e.examDate.getUTCFullYear(),
        e.examDate.getUTCMonth(),
        e.examDate.getUTCDate(),
        e.examTime.getUTCHours(),
        e.examTime.getUTCMinutes(),
        e.examTime.getUTCSeconds(),
      ),
    );
    if (examDateTime < now) {
      passedExams++;
    } else if (nextExamDate === null) {
      nextExamDate = e.examDate;
    }
  }

  const today = dbToday();
  const daysUntilExam =
    nextExamDate !== null ? Math.ceil((nextExamDate.getTime() - today.getTime()) / 86_400_000) : null;

  return { daysUntilExam, passedExams, totalExams: selectedSet.size };
}
