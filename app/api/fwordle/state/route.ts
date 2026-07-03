import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { fwordleState, fwordleToday } from "@/lib/fwordle";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const state = await fwordleState(fwordleToday(), Number(session.user.id));
  return NextResponse.json(state);
}
