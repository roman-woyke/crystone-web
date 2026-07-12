// Standalone socket.io push server, run alongside Next.js (whose serverless
// API routes can't hold persistent connections):
//
//   node --env-file=.env server/realtime.mjs
//
// This process is the Observer pattern's Subject and nothing else: it holds
// no application state and never touches the database. Connected clients
// (Observers) join/leave rooms on mount/unmount — "chat" for the live chat,
// "wordle:<gameId>" for live Boardle progress (gameId = the puzzle date),
// "study" for the study counter. Whenever a Next.js API route commits a
// state change (chat message, guess, study timer action) it calls notify()
// (lib/realtime.ts), which POSTs to this server's internal /notify endpoint;
// the event is then emitted only to the clients in the relevant room —
// replacing the old fixed-interval polling that hit the API whether or not
// anything had changed. Regular actions still go through normal POST
// requests; only the broadcast/notification side lives here.

import { createServer } from "node:http";
import { Server } from "socket.io";
import { decode } from "next-auth/jwt";

const PORT = Number(process.env.REALTIME_PORT ?? 3001);
const AUTH_SECRET = process.env.AUTH_SECRET;
const INTERNAL_SECRET = process.env.REALTIME_INTERNAL_SECRET ?? "";
// The browser origin the Next.js app is served from (socket.io CORS).
const APP_ORIGIN = process.env.REALTIME_APP_ORIGIN ?? "http://localhost:3000";

if (!AUTH_SECRET) {
  console.error("AUTH_SECRET is not set — cannot verify session cookies.");
  process.exit(1);
}

// Rooms a client may subscribe to. wordle:<gameId> covers both the daily
// game (gameId = YYYY-MM-DD) and any future per-game channels.
const ROOM_RE = /^(chat|study|wordle:[A-Za-z0-9:_-]{1,32})$/;

function parseCookies(header) {
  const out = {};
  for (const part of String(header ?? "").split(";")) {
    const eq = part.indexOf("=");
    if (eq === -1) continue;
    out[part.slice(0, eq).trim()] = decodeURIComponent(part.slice(eq + 1).trim());
  }
  return out;
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let body = "";
    req.on("data", (chunk) => {
      body += chunk;
      if (body.length > 65536) reject(new Error("body too large"));
    });
    req.on("end", () => resolve(body));
    req.on("error", reject);
  });
}

// Internal notify endpoint — only the Next.js server calls this (shared
// secret), never browsers.
const httpServer = createServer(async (req, res) => {
  if (req.method === "POST" && req.url === "/notify") {
    try {
      const { secret, room, event, payload } = JSON.parse(await readBody(req));
      if (secret !== INTERNAL_SECRET) {
        res.writeHead(403);
        res.end();
        return;
      }
      if (typeof room !== "string" || !ROOM_RE.test(room) || typeof event !== "string") {
        res.writeHead(400);
        res.end();
        return;
      }
      io.to(room).emit(event, payload ?? null);
      res.writeHead(204);
      res.end();
    } catch {
      res.writeHead(400);
      res.end();
    }
    return;
  }
  res.writeHead(404);
  res.end();
});

const io = new Server(httpServer, {
  cors: { origin: APP_ORIGIN, credentials: true },
});

// Every socket must present a valid Auth.js session cookie — the same JWT
// the Next.js app issues. Nothing here is per-user beyond "is logged in":
// the events themselves carry no secrets (mostly just "something changed,
// refetch your own view"), but an invite-only app shouldn't leak its chat
// stream to anonymous connections either.
io.use(async (socket, next) => {
  try {
    const cookies = parseCookies(socket.handshake.headers.cookie);
    const cookieName = cookies["__Secure-authjs.session-token"]
      ? "__Secure-authjs.session-token"
      : "authjs.session-token";
    const token = cookies[cookieName];
    if (!token) return next(new Error("unauthorized"));
    const session = await decode({ token, secret: AUTH_SECRET, salt: cookieName });
    if (!session?.sub && !session?.id) return next(new Error("unauthorized"));
    socket.data.userId = session.id ?? session.sub;
    next();
  } catch {
    next(new Error("unauthorized"));
  }
});

io.on("connection", (socket) => {
  socket.on("join", (room) => {
    if (typeof room === "string" && ROOM_RE.test(room)) socket.join(room);
  });
  socket.on("leave", (room) => {
    if (typeof room === "string") socket.leave(room);
  });
});

httpServer.listen(PORT, () => {
  console.log(`realtime server listening on :${PORT}`);
});
