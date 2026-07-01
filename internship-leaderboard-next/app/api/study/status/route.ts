import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { studyStatusPayload } from "@/lib/study-status";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const payload = await studyStatusPayload(Number(session.user.id));
  return NextResponse.json(payload);
}
