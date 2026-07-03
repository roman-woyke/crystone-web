import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { getStudyModulesList } from "@/lib/study-modules";
import { studySessionsByDay } from "@/lib/study-sessions";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const [modules, sessions] = await Promise.all([getStudyModulesList(), studySessionsByDay()]);

  return NextResponse.json({ modules, sessions });
}
