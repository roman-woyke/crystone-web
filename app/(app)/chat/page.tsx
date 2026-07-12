import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { ChatApp, type ChatMessage } from "@/components/chat/ChatApp";

export const metadata = { title: "Chat" };

const CHAT_HISTORY_LIMIT = 200;

// Server-render the backlog so the room paints full on first byte; from
// there ChatApp receives new messages pushed over the socket ("chat" room)
// instead of polling.
export default async function ChatPage() {
  const session = await auth();
  const myUsername = session?.user?.name ?? "";

  const rows = await prisma.chatMessage.findMany({
    orderBy: { id: "desc" },
    take: CHAT_HISTORY_LIMIT,
  });
  rows.reverse();

  const users = await prisma.user.findMany({ select: { id: true, username: true } });
  const nameOf = new Map(users.map((u) => [u.id, u.username]));

  const initialMessages: ChatMessage[] = rows.map((r) => ({
    id: r.id,
    username: nameOf.get(r.userId) ?? "",
    body: r.body,
    created_at: r.createdAt.toISOString(),
  }));

  return <ChatApp myUsername={myUsername} initialMessages={initialMessages} />;
}
