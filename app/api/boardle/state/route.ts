import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { boardleEarliestDate, boardleResolveDate, boardleState, boardleToday } from "@/lib/boardle";

export async function GET(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const date = await boardleResolveDate(request.nextUrl.searchParams.get("date"));

  const payload = {
    ...(await boardleState(date, Number(session.user.id))),
    today: boardleToday(),
    earliest_date: await boardleEarliestDate(),
  };
  return NextResponse.json(payload);
}
