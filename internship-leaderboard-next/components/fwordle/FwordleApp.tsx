"use client";

import { useEffect, useRef, useState } from "react";

import type { FwordleState } from "@/lib/fwordle";
import type { ParsedHint } from "@/lib/fwordle-score";
import { Board } from "@/components/fwordle/Board";
import { Keyboard } from "@/components/fwordle/Keyboard";
import { JokerBar } from "@/components/fwordle/JokerBar";
import { ChooseWord } from "@/components/fwordle/ChooseWord";
import { StatsPanel } from "@/components/fwordle/StatsPanel";
import { OpponentsPanel } from "@/components/fwordle/OpponentsPanel";

const HINT_TYPES: { type: "armor" | "orange" | "green"; board: boolean }[] = [
  { type: "armor", board: false },
  { type: "orange", board: true },
  { type: "green", board: true },
];

type LetterColor = "green" | "orange" | "grey";

function Pill({ children }: { children: React.ReactNode }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border bg-muted/30 px-3 py-1">{children}</span>
  );
}

export function FwordleApp({ initialState }: { initialState: FwordleState }) {
  const [state, setState] = useState(initialState);
  const [buffer, setBuffer] = useState("");
  const [busy, setBusy] = useState(false);
  const [kbBoard, setKbBoard] = useState(0);
  const [pendingHint, setPendingHint] = useState<string | null>(null);
  const [payMode, setPayMode] = useState<"streak" | "freeze">("streak");
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [pressedKey, setPressedKey] = useState<string | null>(null);

  const busyRef = useRef(busy);
  busyRef.current = busy;

  const myMax = state.me.max_guesses;
  const L = state.length;
  const bonusRow = state.me.armor ? myMax - 1 : -1;
  const activeRowIndex = state.me.finished ? -1 : state.me.guesses_used;

  function eligibleBoards(s: FwordleState): number[] {
    if (s.me.finished) return [];
    const owned = s.me.owned;
    const out: number[] = [];
    for (let b = 0; b < s.num_boards; b++) {
      if (!s.me.solved_boards[b] && !owned.includes(b)) out.push(b);
    }
    return out;
  }

  // A pending pick can go stale (e.g. the board it could target got solved
  // by a fresh poll) — derive its validity each render instead of syncing it
  // back into state, so a stale pick just stops rendering as active.
  function isPendingHintValid(): boolean {
    if (!pendingHint) return false;
    const canSpendStreak = state.me.streak >= 1 || state.me.free_joker;
    const broke = !canSpendStreak && !state.me.can_freeze_joker;
    const t = HINT_TYPES.find((x) => x.type === pendingHint);
    const canPick = eligibleBoards(state).length > 0;
    if (state.me.finished || broke || state.me.hint_types_used.includes(pendingHint) || (t?.board && !canPick)) {
      return false;
    }
    return true;
  }
  const effectivePendingHint = isPendingHintValid() ? pendingHint : null;

  async function loadState() {
    if (busyRef.current) return;
    try {
      const res = await fetch("/api/fwordle/state");
      if (!res.ok) return;
      const next: FwordleState = await res.json();
      setState(next);
    } catch {
      // ignore transient poll failures
    }
  }

  useEffect(() => {
    const poll = setInterval(loadState, 6000);
    const onVisible = () => {
      if (!document.hidden) loadState();
    };
    const onPageShow = (e: PageTransitionEvent) => {
      if (e.persisted) loadState();
    };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("pageshow", onPageShow);
    return () => {
      clearInterval(poll);
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("pageshow", onPageShow);
    };
  }, []);

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      const tag = (document.activeElement && document.activeElement.tagName) || "";
      if (tag === "INPUT" || tag === "TEXTAREA") return; // don't hijack the custom-word field
      if (e.key === "Enter") {
        e.preventDefault();
        handleKey("enter");
      } else if (e.key === "Backspace") {
        e.preventDefault();
        handleKey("back");
      } else if (e.key === " ") {
        e.preventDefault();
        handleKey("space");
      } else if (/^[a-zA-Z]$/.test(e.key)) {
        handleKey(e.key.toLowerCase());
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  });

  function flashKey(key: string) {
    setPressedKey(key);
    setTimeout(() => setPressedKey((k) => (k === key ? null : k)), 160);
  }

  function handleKey(key: string) {
    if (state.me.finished || busyRef.current) return;
    flashKey(key);
    if (key === "enter") {
      submitGuess();
      return;
    }
    if (key === "back") {
      setBuffer((b) => b.slice(0, -1));
      setMsg(null);
      return;
    }
    if (key === "space") {
      setBuffer((b) => (b.length < L ? b + " " : b));
      setMsg(null);
      return;
    }
    if (/^[a-z]$/.test(key)) {
      setBuffer((b) => (b.length < L ? b + key : b));
      setMsg(null);
    }
  }

  async function submitGuess() {
    if (busyRef.current || state.me.finished) return;

    const first = buffer.search(/[a-z]/);
    if (first === -1) {
      setMsg({ text: "Type a word.", ok: false });
      return;
    }
    let last = -1;
    for (let i = buffer.length - 1; i >= 0; i--) {
      if (/[a-z]/.test(buffer[i])) {
        last = i;
        break;
      }
    }
    const word = buffer.slice(first, last + 1);
    if (/\s/.test(word)) {
      setMsg({ text: "No gaps inside the word — spaces only before or after it.", ok: false });
      return;
    }
    if (word.length < 5) {
      setMsg({ text: "Use at least 5 letters.", ok: false });
      return;
    }

    setBusy(true);
    try {
      const res = await fetch("/api/fwordle/guess", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ word, offset: first }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Guess failed.");
      const next: FwordleState = await res.json();
      setState(next);
      setBuffer("");
      setMsg(null);
    } catch (err) {
      setMsg({ text: err instanceof Error ? err.message : "Guess failed.", ok: false });
    } finally {
      setBusy(false);
    }
  }

  function selectHint(type: string) {
    if (busyRef.current || state.me.finished) return;
    const t = HINT_TYPES.find((x) => x.type === type);
    if (t && !t.board) {
      applyHint(type, null);
      return;
    }
    setPendingHint((p) => (p === type ? null : type));
  }

  async function applyHint(type: string, board: number | null) {
    if (busyRef.current || state.me.finished) return;
    const canSpendStreak = state.me.streak >= 1 || state.me.free_joker;
    const payFreeze = state.me.can_freeze_joker && (payMode === "freeze" || !canSpendStreak);

    const names: Record<string, string> = { armor: "Armor (+1 guess)", orange: "Reveal (+2 🟨)", green: "Place (+1 🟩)" };
    const cost = payFreeze
      ? "This uses 1 🧊 freeze — your streak stays safe."
      : state.me.free_joker
        ? "This uses your one free joker (you have no streak)."
        : "This costs you 1 🔥 streak.";
    if (!confirm(`Use ${names[type] ?? "this joker"}?\n\n${cost}`)) {
      setPendingHint(null);
      return;
    }

    setPendingHint(null);
    setBusy(true);
    setPayMode("streak");
    setMsg(null);
    try {
      const res = await fetch("/api/fwordle/hint", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ type, board: board ?? undefined, pay: payFreeze ? "freeze" : undefined }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Joker failed.");
      const next: FwordleState = await res.json();
      setState(next);
    } catch (err) {
      setMsg({ text: err instanceof Error ? err.message : "Joker failed.", ok: false });
    } finally {
      setBusy(false);
    }
  }

  async function submitChoice(word: string, hint: string) {
    const res = await fetch("/api/fwordle/choose", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ word, hint }),
    });
    if (!res.ok) throw new Error((await res.text()) || "Could not save.");
    const result: { word: string; hint: string | null } = await res.json();
    setState((s) => ({ ...s, choose: { ...s.choose, already: result.word, already_hint: result.hint ?? null } }));
  }

  // Best colour seen for each letter on a given board (green > orange >
  // grey), merging both the player's guesses and any hint used on that board.
  function kbStatesFor(b: number): Record<string, LetterColor> {
    const rank: Record<LetterColor, number> = { grey: 1, orange: 2, green: 3 };
    const st: Record<string, LetterColor> = {};
    const bump = (ch: string, c: LetterColor) => {
      if (!st[ch] || rank[c] > rank[st[ch]]) st[ch] = c;
    };
    for (const g of state.me.guesses) {
      const colors = g.boards[b];
      for (let i = 0; i < g.text.length; i++) {
        const ch = g.text[i];
        const c = colors[i];
        if (ch === "_" || c === "empty") continue;
        bump(ch, c as LetterColor);
      }
    }
    for (const h of state.me.hints) {
      if (h.board !== b) continue;
      const c: LetterColor = h.type === "green" ? "green" : "orange";
      for (const cell of h.cells) bump(cell.letter, c);
    }
    return st;
  }

  const kbBoardClamped = kbBoard >= state.num_boards ? 0 : kbBoard;
  const pickSet = effectivePendingHint ? new Set(eligibleBoards(state)) : null;
  const showChoose = state.choose.eligible && (state.me.solved || state.choose.for_today);
  const anyBoardHint = state.board_hints.some((h) => h?.trim());
  const boardHints = (state.me.hints || []).filter(
    (h): h is ParsedHint & { type: "orange" | "green"; board: number } => h.type !== "armor",
  );

  return (
    <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="min-w-0 space-y-6">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">
            B<span className="text-primary">oardle</span>
          </h1>
          <p className="mt-1 max-w-xl text-sm text-muted-foreground">
            4 board puzzle a day. Solve every board before the guesses run out. A guess can be any word from 5
            letters up to the day&apos;s length (use the space bar to place it). Beat the day and you get to set
            one of tomorrow&apos;s words.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
          <Pill>
            📅 <strong className="text-foreground">{state.date}</strong>
          </Pill>
          <Pill>
            <strong className="text-foreground">{state.length}</strong>-letter words
          </Pill>
          <Pill>
            <strong className="text-foreground">{state.num_boards}</strong> board{state.num_boards === 1 ? "" : "s"}
          </Pill>
          <Pill>
            Guess{" "}
            <strong className="text-foreground">
              {Math.min(state.me.guesses_used + (state.me.finished ? 0 : 1), myMax)}
            </strong>
            /{myMax}
            {state.me.armor ? " 🛡️" : ""}
          </Pill>
          {state.me.finished &&
            (state.me.solved ? (
              <span className="rounded-full border border-emerald-500/50 bg-emerald-500/15 px-3 py-1 font-bold text-emerald-500">
                🎉 Solved all {state.num_boards} in {state.me.guesses_used}!
              </span>
            ) : (
              <span className="rounded-full border border-destructive/45 bg-destructive/15 px-3 py-1 font-bold text-destructive">
                Out of guesses — {state.me.solved_boards.filter(Boolean).length}/{state.num_boards} solved
              </span>
            ))}
        </div>

        <JokerBar
          me={state.me}
          pendingHint={effectivePendingHint}
          canPickBoard={eligibleBoards(state).length > 0}
          payMode={payMode}
          onSelect={selectHint}
          onSetPayMode={setPayMode}
        />

        {state.num_boards === 0 ? (
          <p className="text-sm text-muted-foreground">No puzzle today — check back later.</p>
        ) : (
          <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
            {Array.from({ length: state.num_boards }, (_, b) => (
              <Board
                key={b}
                index={b}
                length={L}
                maxGuesses={myMax}
                bonusRow={bonusRow}
                isOwned={state.me.owned.includes(b)}
                answer={state.me.answers[b]}
                solved={state.me.solved_boards[b]}
                finished={state.me.finished}
                guesses={state.me.guesses}
                activeRowIndex={activeRowIndex}
                buffer={buffer}
                pickable={!!pickSet?.has(b)}
                onPick={() => effectivePendingHint && applyHint(effectivePendingHint, b)}
              />
            ))}
          </div>
        )}

        {anyBoardHint && (
          <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
            {state.board_hints.map((hint, b) =>
              hint?.trim() ? (
                <div
                  key={b}
                  className="glow-card overflow-hidden rounded-md border bg-card p-3 text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground"
                >
                  <span className="mb-1.5 block text-[0.68rem] font-bold tracking-wide text-muted-foreground/70 uppercase">
                    Board {b + 1} clue
                  </span>
                  {hint}
                </div>
              ) : null,
            )}
          </div>
        )}

        {boardHints.length > 0 && (
          <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-4">
            {boardHints.map((h) => (
              <HintRow key={h.board} board={h.board!} type={h.type} cells={h.cells} length={L} />
            ))}
          </div>
        )}

        <div className="space-y-3">
          <Keyboard
            numBoards={state.num_boards}
            kbBoard={kbBoardClamped}
            onSelectBoard={setKbBoard}
            solvedBoards={state.me.solved_boards}
            letterStates={state.num_boards > 0 ? kbStatesFor(kbBoardClamped) : {}}
            disabled={state.me.finished}
            onKey={handleKey}
            pressedKey={pressedKey}
          />
          {msg && (
            <p className={`min-h-[1.2em] text-center text-sm ${msg.ok ? "text-emerald-500" : "text-destructive"}`}>{msg.text}</p>
          )}
        </div>

        {showChoose && <ChooseWord choose={state.choose} onSubmit={submitChoice} />}
      </div>

      <aside className="space-y-6">
        <div>
          <h2 className="mb-3 flex items-center gap-2 text-base font-semibold">🔥 Streaks</h2>
          <StatsPanel stats={state.stats} />
        </div>
        <div>
          <h2 className="mb-3 flex items-center gap-2 text-base font-semibold">⚔️ Competition</h2>
          <OpponentsPanel opponents={state.opponents} numBoards={state.num_boards} length={L} />
        </div>
      </aside>
    </div>
  );
}

// A used board-joker shown as an extra board row beneath the board.
function HintRow({
  board,
  type,
  cells,
  length,
}: {
  board: number;
  type: "orange" | "green";
  cells: { letter: string; pos: number }[];
  length: number;
}) {
  const slots: (null | string)[] = new Array(length).fill(null);
  for (const c of cells) {
    if (c.pos >= 0 && c.pos < length) slots[c.pos] = c.letter;
  }
  const label = type === "orange" ? "in word" : "in spot";
  const cls = type === "green" ? "bg-emerald-600 text-white" : "bg-amber-500 text-white";

  return (
    <div className="glow-card overflow-hidden rounded-xl border bg-card p-3">
      <div className="mb-1.5 flex items-center justify-between text-[0.68rem] font-bold tracking-wide text-muted-foreground uppercase">
        <span>Board {board + 1} hint</span>
        <span>{label}</span>
      </div>
      <div className="grid gap-1" style={{ gridTemplateColumns: `repeat(${length}, minmax(0, 1fr))` }}>
        {slots.map((ch, i) => (
          <div
            key={i}
            className={`flex aspect-square items-center justify-center rounded-md text-sm font-bold uppercase ${ch ? cls : "border-2 border-border"}`}
          >
            {ch ?? ""}
          </div>
        ))}
      </div>
    </div>
  );
}
