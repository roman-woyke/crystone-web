import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { boardleState, boardleToday } from "@/lib/boardle";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const state = await boardleState(boardleToday(), Number(session.user.id));
  return NextResponse.json(state);
}
