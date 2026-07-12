"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { colorFor } from "@/lib/study-colors";
import { useRealtimeRoom } from "@/lib/realtime-client";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/layout/PageHeader";

export type ChatMessage = {
  id: number;
  username: string;
  body: string;
  created_at: string; // ISO of the Berlin-wall-clock-as-UTC DATETIME
};

// created_at carries Berlin wall-clock digits tagged as UTC (see
// lib/dates.ts), so the display just reads the digits back out of the ISO
// string — no timezone conversion.
function timeOf(iso: string): string {
  return iso.slice(11, 16);
}

function dayOf(iso: string): string {
  return iso.slice(0, 10);
}

function dayLabel(dateStr: string): string {
  return new Date(dateStr + "T00:00:00").toLocaleDateString(undefined, {
    weekday: "long",
    month: "short",
    day: "numeric",
  });
}

// One shared live-chat room. Messages are sent with a normal POST; incoming
// ones arrive as "chat:new" events on the socket's "chat" room — there is
// no polling. The initial backlog comes server-rendered from the page.
export function ChatApp({ myUsername, initialMessages }: { myUsername: string; initialMessages: ChatMessage[] }) {
  const [messages, setMessages] = useState(initialMessages);
  const [draft, setDraft] = useState("");
  const [sendErr, setSendErr] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  const listRef = useRef<HTMLDivElement>(null);
  const stickToBottomRef = useRef(true);

  // Append with id-dedupe: my own POST response and the socket echo of the
  // same message can both arrive; whichever is second is dropped.
  const append = useCallback((msg: ChatMessage) => {
    setMessages((prev) => (prev.some((m) => m.id === msg.id) ? prev : [...prev, msg]));
  }, []);

  useRealtimeRoom(
    "chat",
    "chat:new",
    useCallback(
      (payload: unknown) => {
        const msg = payload as ChatMessage | null;
        if (msg && typeof msg.id === "number") append(msg);
      },
      [append],
    ),
  );

  // Keep the view pinned to the newest message unless the reader has
  // deliberately scrolled up into the backlog.
  function onScroll() {
    const el = listRef.current;
    if (!el) return;
    stickToBottomRef.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
  }

  useEffect(() => {
    const el = listRef.current;
    if (el && stickToBottomRef.current) el.scrollTop = el.scrollHeight;
  }, [messages]);

  async function send() {
    const body = draft.trim();
    if (body === "" || sending) return;
    setSending(true);
    setSendErr(null);
    try {
      const res = await fetch("/api/chat/messages", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ body }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Could not send.");
      const { message } = (await res.json()) as { message: ChatMessage };
      append(message);
      setDraft("");
    } catch (err) {
      setSendErr(err instanceof Error ? err.message : "Could not send.");
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="mx-auto flex h-[calc(100vh-180px)] max-w-3xl flex-col space-y-4">
      <PageHeader
        eyebrow="Everyone, live"
        title={
          <>
            Live <span className="gradient-text">Chat</span>
          </>
        }
        description="One room for the whole group. Messages arrive instantly — no refresh needed."
      />

      <div
        ref={listRef}
        onScroll={onScroll}
        className="glow-card min-h-0 flex-1 space-y-2 overflow-y-auto rounded-xl border bg-card p-4"
      >
        {messages.length === 0 && (
          <p className="py-10 text-center text-sm text-muted-foreground">Nothing here yet — say hi.</p>
        )}
        {messages.map((m, i) => {
          const day = dayOf(m.created_at);
          const showDay = i === 0 || dayOf(messages[i - 1].created_at) !== day;
          const mine = m.username === myUsername;
          return (
            <div key={m.id}>
              {showDay && (
                <div className="my-3 flex items-center gap-3 text-[0.68rem] font-bold tracking-wide text-muted-foreground/70 uppercase">
                  <span className="h-px flex-1 bg-border" />
                  {dayLabel(day)}
                  <span className="h-px flex-1 bg-border" />
                </div>
              )}
              <div className={`flex ${mine ? "justify-end" : "justify-start"}`}>
                <div
                  className={`max-w-[85%] rounded-xl border px-3 py-2 text-sm ${
                    mine ? "border-primary/30 bg-primary/10" : "bg-muted/40"
                  }`}
                >
                  <div className="mb-0.5 flex items-baseline gap-2">
                    <span className="text-xs font-bold" style={{ color: colorFor(m.username) }}>
                      {m.username}
                    </span>
                    <span className="text-[0.68rem] text-muted-foreground tabular-nums">{timeOf(m.created_at)}</span>
                  </div>
                  <div className="break-words whitespace-pre-wrap">{m.body}</div>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="space-y-1.5">
        <div className="flex gap-2">
          <textarea
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                send();
              }
            }}
            rows={1}
            placeholder="Write a message… (Enter to send, Shift+Enter for a new line)"
            className="min-h-11 flex-1 resize-none rounded-md border bg-background px-3 py-2.5 text-sm"
          />
          <Button type="button" size="lg" disabled={sending || draft.trim() === ""} onClick={send}>
            Send
          </Button>
        </div>
        {sendErr && <p className="text-sm text-destructive">{sendErr}</p>}
      </div>
    </div>
  );
}
