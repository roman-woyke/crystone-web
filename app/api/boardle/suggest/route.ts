import { NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { boardleAddDays, boardleDateOnly } from "@/lib/boardle-dates";
import { boardleChoiceEligible, boardleEnsureDay, boardleToday } from "@/lib/boardle";
import { boardleRandomSuggestions } from "@/lib/boardle-words";

// Fresh random suggestions for the word picker's "randomize" button. Mirrors
// the choose route's eligibility so the length matches the day the picker is
// actually setting a word for.
export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const date = boardleToday();
  const tomorrow = boardleAddDays(date, 1);

  let pickingForToday = false;
  if (!(await boardleChoiceEligible(date, userId))) {
    const guessCountToday = await prisma.boardleGuess.count({ where: { gameDate: boardleDateOnly(date) } });
    if (guessCountToday === 0) pickingForToday = true;
  }

  if (!pickingForToday && !(await boardleChoiceEligible(date, userId))) {
    return new NextResponse("Solve today's Boardle first to set a word.", { status: 403 });
  }

  const targetDate = pickingForToday ? date : tomorrow;
  const tday = await boardleEnsureDay(targetDate);
  const tlen = tday.wordLength;

  return NextResponse.json({
    suggestions: boardleRandomSuggestions(tlen, 3),
    tomorrow_length: tlen,
  });
}
