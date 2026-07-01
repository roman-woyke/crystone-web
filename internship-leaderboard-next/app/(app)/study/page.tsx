import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { studyStatusPayload } from "@/lib/study-status";
import { studySessionsByDay } from "@/lib/study-sessions";
import { getStudyModulesList } from "@/lib/study-modules";
import { examCountdown } from "@/lib/exam-countdown";
import { StudyCounterApp } from "@/components/study/StudyCounterApp";

export default async function StudyPage() {
  const session = await auth();
  const userId = Number(session!.user.id);
  const myUsername = session!.user.name ?? "";

  const [modules, initialStatus, initialSessions, countdown, users] = await Promise.all([
    getStudyModulesList(),
    studyStatusPayload(userId),
    studySessionsByDay(),
    examCountdown(myUsername),
    prisma.user.findMany({ select: { username: true, avatar: true }, orderBy: { username: "asc" } }),
  ]);

  const userAvatars: Record<string, string> = {};
  for (const u of users) {
    if (u.avatar) userAvatars[u.username] = u.avatar;
  }
  const allUsers = users.map((u) => u.username);

  return (
    <StudyCounterApp
      myUsername={myUsername}
      modules={modules}
      initialStatus={initialStatus}
      initialSessions={initialSessions}
      userAvatars={userAvatars}
      allUsers={allUsers}
      examCountdown={countdown}
    />
  );
}
