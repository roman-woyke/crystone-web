import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { studySessionDate } from "@/lib/study-sessions";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const rows = await prisma.studySession.findMany({
    where: { userId },
  });

  rows.sort((a, b) => {
    const at = (a.startedAt ?? a.createdAt ?? new Date(0)).getTime();
    const bt = (b.startedAt ?? b.createdAt ?? new Date(0)).getTime();
    return bt - at;
  });

  const sessions = rows.map((r) => ({
    id: r.id,
    module: r.moduleName,
    seconds: r.seconds,
    date: studySessionDate(r.startedAt, r.studiedOn),
    started_at: r.startedAt,
    created_at: r.createdAt,
    at_library: r.atLibrary,
  }));

  return NextResponse.json({ sessions });
}
