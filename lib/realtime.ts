// Server-side notify() — the write half of the push architecture. API routes
// call this after committing a state change; the standalone socket.io server
// (server/realtime.mjs) then emits the event to every client subscribed to
// that room. See the header comment in server/realtime.mjs for the room
// naming scheme.

const REALTIME_INTERNAL_URL = process.env.REALTIME_INTERNAL_URL ?? "http://localhost:3001";

// Awaited (with a short timeout) so serverless runtimes don't kill the
// request before it leaves — but any failure is swallowed: a missing or
// down realtime server must never break the action itself; clients just
// fall back to their visibility-change refetches.
export async function notify(room: string, event: string, payload?: unknown): Promise<void> {
  try {
    await fetch(`${REALTIME_INTERNAL_URL}/notify`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        secret: process.env.REALTIME_INTERNAL_SECRET ?? "",
        room,
        event,
        payload,
      }),
      signal: AbortSignal.timeout(1500),
    });
  } catch {
    // realtime server unreachable — polling fallbacks cover it
  }
}
