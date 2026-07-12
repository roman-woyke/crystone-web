import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { notify } from "@/lib/realtime";

export type ChatWireMessage = {
  id: number;
  username: string;
  body: string;
  created_at: string; // ISO of the Berlin-wall-clock-as-UTC DATETIME
};

const CHAT_HISTORY_LIMIT = 200;
const CHAT_MAX_BODY = 2000;

async function usernamesById(ids: number[]): Promise<Map<number, string>> {
  const users = await prisma.user.findMany({
    where: { id: { in: ids } },
    select: { id: true, username: true },
  });
  return new Map(users.map((u) => [u.id, u.username]));
}

// The latest slice of chat history, oldest first — enough backlog for a
// small friend group; anything older just scrolls out of memory.
export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const rows = await prisma.chatMessage.findMany({
    orderBy: { id: "desc" },
    take: CHAT_HISTORY_LIMIT,
  });
  rows.reverse();

  const nameOf = await usernamesById([...new Set(rows.map((r) => r.userId))]);
  const messages: ChatWireMessage[] = rows.map((r) => ({
    id: r.id,
    username: nameOf.get(r.userId) ?? "",
    body: r.body,
    created_at: r.createdAt.toISOString(),
  }));

  return NextResponse.json({ messages });
}

// Post a message. The write goes through this normal POST as usual; only
// the broadcast side is new — notify() pushes the stored message to every
// client in the "chat" room so nobody has to poll for it.
export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const reqBody = await request.json().catch(() => ({}));
  const body = String(reqBody.body ?? "").trim();
  if (body === "") {
    return new NextResponse("Message can't be empty.", { status: 422 });
  }
  if (body.length > CHAT_MAX_BODY) {
    return new NextResponse(`Message too long (max ${CHAT_MAX_BODY} characters).`, { status: 422 });
  }

  const created = await prisma.chatMessage.create({
    data: { userId, body, createdAt: dbNow() },
  });

  const message: ChatWireMessage = {
    id: created.id,
    username: session.user.name ?? "",
    body: created.body,
    created_at: created.createdAt.toISOString(),
  };

  await notify("chat", "chat:new", message);

  return NextResponse.json({ message });
}
