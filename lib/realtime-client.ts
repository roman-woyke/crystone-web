"use client";

// Browser half of the push architecture: one shared socket.io connection per
// tab, multiplexing every live feature over rooms ("chat", "study",
// "wordle:<gameId>"). Components subscribe with useRealtimeRoom(); the
// server emits an event to a room whenever its state changes, replacing the
// old fixed-interval polling. If the realtime server is down the socket
// simply never connects and components fall back to their
// visibilitychange/pageshow refetches.

import { useEffect, useRef } from "react";
import { io, type Socket } from "socket.io-client";

let socket: Socket | null = null;

export function getSocket(): Socket {
  if (!socket) {
    const url =
      process.env.NEXT_PUBLIC_REALTIME_URL ?? `${window.location.protocol}//${window.location.hostname}:3001`;
    socket = io(url, {
      withCredentials: true, // send the Auth.js session cookie
      reconnectionDelayMax: 10_000,
    });
  }
  return socket;
}

// Join `room` while mounted and run `handler` whenever `event` fires there.
// The handler lives in a ref so a fresh closure each render doesn't churn
// the subscription; the room is re-joined after every reconnect (socket.io
// forgets rooms across connections).
export function useRealtimeRoom(room: string | null, event: string, handler: (payload: unknown) => void) {
  const handlerRef = useRef(handler);
  useEffect(() => {
    handlerRef.current = handler;
  });

  useEffect(() => {
    if (!room) return;
    const s = getSocket();
    const fire = (payload: unknown) => handlerRef.current(payload);
    const join = () => s.emit("join", room);

    join();
    s.on(event, fire);
    s.on("connect", join);

    return () => {
      s.emit("leave", room);
      s.off(event, fire);
      s.off("connect", join);
    };
  }, [room, event]);
}
