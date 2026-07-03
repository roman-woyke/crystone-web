import { prisma } from "@/lib/prisma";
import { dbNow, dbToday } from "@/lib/dates";

export type ExamCountdown = {
  daysUntilExam: number;
  passedExams: number;
  totalExams: number;
};

// Mirrors calendar.php's countdown block: days until the earliest exam
// overall, and how many of *this user's* selected exams have already
// happened (exam_date + exam_time combined, compared against now).
export async function examCountdown(username: string): Promise<ExamCountdown> {
  const exams = await prisma.exam.findMany({
    orderBy: [{ examDate: "asc" }, { examTime: "asc" }],
  });

  const firstExamDate = exams[0]?.examDate ?? new Date("2026-07-13T00:00:00Z");
  const today = dbToday();
  const daysUntilExam = Math.ceil((firstExamDate.getTime() - today.getTime()) / 86_400_000);

  const selected = await prisma.userExam.findMany({
    where: { username },
    select: { examId: true },
  });
  const selectedSet = new Set(selected.map((s) => s.examId));

  const now = dbNow();
  let passedExams = 0;
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
    if (examDateTime < now) passedExams++;
  }

  return { daysUntilExam, passedExams, totalExams: selectedSet.size };
}
