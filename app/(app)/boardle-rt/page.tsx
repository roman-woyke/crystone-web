import { auth } from "@/lib/auth";
import { BoardleRtApp } from "@/components/boardle/BoardleRtApp";

export const metadata = { title: "Unlimited Boardle" };

// No server-rendered initial state here (unlike daily Boardle): the room's
// phase can change every second, so the client fetches fresh state on mount
// and polls every 1.5s — a snapshot from the server render would be stale
// before hydration anyway.
export default async function BoardleRtPage() {
  const session = await auth();
  const myUsername = session?.user?.name ?? "";

  return <BoardleRtApp myUsername={myUsername} />;
}
