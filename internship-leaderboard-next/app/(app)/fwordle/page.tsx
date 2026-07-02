import { auth } from "@/lib/auth";
import { fwordleState, fwordleToday } from "@/lib/fwordle";
import { FwordleApp } from "@/components/fwordle/FwordleApp";

export default async function FwordlePage() {
  const session = await auth();
  const userId = Number(session!.user.id);

  // Server-render the full state so the grid paints complete on first byte.
  const initialState = await fwordleState(fwordleToday(), userId);

  return <FwordleApp initialState={initialState} />;
}
