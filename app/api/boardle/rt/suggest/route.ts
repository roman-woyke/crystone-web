import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { boardleRtAdvance, boardleRtRow } from "@/lib/boardle-rt";
import { boardleRandomSuggestions } from "@/lib/boardle-words";

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  await boardleRtAdvance();
  const row = await boardleRtRow();
  if (row.phase !== "selecting") {
    return new NextResponse("Not in the word-selection phase.", { status: 409 });
  }

  return NextResponse.json({
    suggestions: boardleRandomSuggestions(row.wordLength ?? 0, 3),
  });
}
