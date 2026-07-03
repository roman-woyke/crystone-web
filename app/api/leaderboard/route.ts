import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { getLeaderboard } from "@/lib/leaderboard";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const leaderboard = await getLeaderboard();
  return NextResponse.json(leaderboard);
}
