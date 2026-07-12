import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { boardleRtPayload } from "@/lib/boardle-rt";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  return NextResponse.json(await boardleRtPayload(Number(session.user.id)));
}
