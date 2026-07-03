import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

export async function DELETE(
  _request: NextRequest,
  { params }: RouteContext<"/api/study/sessions/[id]">,
) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const sessionId = Number(id);
  if (!Number.isInteger(sessionId)) {
    return new NextResponse("Invalid session id.", { status: 400 });
  }

  // Ownership check — you can only delete your own sessions. Attached
  // study_segments cascade via the FK's ON DELETE CASCADE.
  const result = await prisma.studySession.deleteMany({ where: { id: sessionId, userId } });

  if (result.count === 0) {
    return new NextResponse("Session not found.", { status: 404 });
  }

  return new NextResponse("OK");
}
