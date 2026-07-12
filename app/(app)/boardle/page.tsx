import { auth } from "@/lib/auth";
import { boardleEarliestDate, boardleState, boardleToday } from "@/lib/boardle";
import { BoardleApp } from "@/components/boardle/BoardleApp";

export default async function BoardlePage() {
  const session = await auth();
  const userId = Number(session!.user.id);

  // Server-render the full state so the grid paints complete on first byte.
  const today = boardleToday();
  const initialState = {
    ...(await boardleState(today, userId)),
    today,
    earliest_date: await boardleEarliestDate(),
  };

  return <BoardleApp initialState={initialState} />;
}
