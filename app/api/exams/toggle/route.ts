import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { canonicalUser } from "@/lib/calendar";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const body = await request.json();
  const targetUser = canonicalUser(String(body.username ?? ""));
  const sessionUser = canonicalUser(session.user.name);

  if (targetUser === null) {
    return new NextResponse("Invalid user.", { status: 400 });
  }
  if (sessionUser === null || sessionUser !== targetUser) {
    return new NextResponse("You can only modify your own exams.", { status: 403 });
  }

  const examId = Number(body.exam_id);
  if (!Number.isInteger(examId) || examId <= 0) {
    return new NextResponse("Missing exam_id.", { status: 400 });
  }
  const checked = body.checked === "1" || body.checked === true;

  if (checked) {
    await prisma.userExam.upsert({
      where: { username_examId: { username: targetUser, examId } },
      create: { username: targetUser, examId },
      update: {},
    });
  } else {
    await prisma.userExam.deleteMany({ where: { username: targetUser, examId } });
  }

  return new NextResponse("OK");
}
