"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";

import type { BoardleState, BoardleWireState } from "@/lib/boardle";
import type { ParsedHint } from "@/lib/boardle-score";
import { Button } from "@/components/ui/button";
import { PageHeader } from "@/components/layout/PageHeader";
import { Board } from "@/components/boardle/Board";
import { Keyboard } from "@/components/boardle/Keyboard";
import { JokerBar } from "@/components/boardle/JokerBar";
import { ChooseWord } from "@/components/boardle/ChooseWord";
import { StatsPanel } from "@/components/boardle/StatsPanel";
import { OpponentsPanel } from "@/components/boardle/OpponentsPanel";
import { HistoryCalendar } from "@/components/boardle/HistoryCalendar";

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

export function BoardleApp({ initialState }: { initialState: BoardleWireState }) {
  const [state, setState] = useState<BoardleState>(initialState);
  const [buffer, setBuffer] = useState("");
  const [busy, setBusy] = useState(false);
  const [kbBoard, setKbBoard] = useState(0);
  const [pendingHint, setPendingHint] = useState<string | null>(null);
  const [payMode, setPayMode] = useState<"streak" | "freeze">("streak");
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [pressedKey, setPressedKey] = useState<string | null>(null);

  // The day currently shown. Defaults to today; the day-nav arrows/calendar
  // move it within [earliest, today] to replay a missed day or look at an old
  // result. Every state-changing request (guess/joker/hint) is scoped to this
  // date, not necessarily the server's real "today". The bounds live outside
  // `state` because guess/joker responses don't carry them.
  const [viewedDate, setViewedDate] = useState(initialState.date);
  const [bounds, setBounds] = useState({ today: initialState.today, earliest: initialState.earliest_date });
  const [calendarOpen, setCalendarOpen] = useState(false);
  const viewedDateRef = useRef(viewedDate);
  viewedDateRef.current = viewedDate;
  const onToday = viewedDate === bounds.today;

  // "You solved it!" / today's-late-pick popup (ChooseWord in an overlay).
  const [chooseModalOpen, setChooseModalOpen] = useState(false);
  const choosePromptedRef = useRef(false);
  const todayPickPromptedRef = useRef(false);

  // Community-hint modal for a solved/owned board with no clue yet.
  const [addHintBoard, setAddHintBoard] = useState<number | null>(null);
  const [addHintText, setAddHintText] = useState("");
  const [addHintErr, setAddHintErr] = useState<string | null>(null);

  // Full-hint modal — opened from a clamped hint card that overflows 3 lines.
  const [fullHint, setFullHint] = useState<{ board: number; hint: string } | null>(null);

  const busyRef = useRef(busy);
  busyRef.current = busy;

  const myMax = state.me.max_guesses;
  const L = state.length;
  const bonusRow = state.me.armor ? myMax - 1 : -1;
  const activeRowIndex = state.me.finished ? -1 : state.me.guesses_used;

  function eligibleBoards(s: BoardleState): number[] {
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

  async function loadState(date?: string) {
    if (busyRef.current) return;
    const wanted = date ?? viewedDateRef.current;
    try {
      const res = await fetch(`/api/boardle/state?date=${encodeURIComponent(wanted)}`);
      if (!res.ok) return;
      const next: BoardleWireState = await res.json();
      // Drop a stale response if the user has already navigated elsewhere.
      if (next.date !== viewedDateRef.current) return;
      setState(next);
      setBounds({ today: next.today, earliest: next.earliest_date });
    } catch {
      // ignore transient poll failures
    }
  }

  // ── Day nav: prev/next arrows + "back to today" + calendar popup ────────
  function addDays(dateStr: string, delta: number): string {
    // Build the result from local date parts, not toISOString() (which
    // converts to UTC and silently drops/duplicates a day in any timezone
    // ahead of UTC).
    const d = new Date(dateStr + "T00:00:00");
    d.setDate(d.getDate() + delta);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  }

  function fmtDayLabel(dateStr: string): string {
    const d = new Date(dateStr + "T00:00:00");
    return d.toLocaleDateString(undefined, { weekday: "short", year: "numeric", month: "short", day: "numeric" });
  }

  function goToDate(dateStr: string) {
    if (busyRef.current) return;
    if (dateStr < bounds.earliest) dateStr = bounds.earliest;
    if (dateStr > bounds.today) dateStr = bounds.today;
    if (dateStr === viewedDate) return;
    setViewedDate(dateStr);
    viewedDateRef.current = dateStr;
    setBuffer("");
    setKbBoard(0);
    setPendingHint(null);
    setMsg(null);
    loadState(dateStr);
  }

  useEffect(() => {
    const poll = setInterval(() => {
      if (!document.hidden) loadState();
    }, 6000);
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

  // Pop the word picker up the moment it becomes available after a win, so
  // the player can choose without scrolling. Fires once per solve (persisted
  // per-day in localStorage, so a refresh doesn't re-fire it), after a short
  // delay to let the win sink in. The for-today late pick gets its own
  // immediate popup instead — once per pageview, tied to live state, so a
  // later visit can fire it again while the slot is still up for grabs.
  useEffect(() => {
    // Word-picking only ever concerns the real current day — replaying a
    // missed past day shouldn't offer to set a word for its (long since
    // finalized) "tomorrow".
    if (!onToday) return;
    const c = state.choose;
    const seenKey = `boardle_choose_seen_${state.date}`;
    if (
      !choosePromptedRef.current &&
      state.me.finished &&
      state.me.solved &&
      c.eligible &&
      !c.for_today &&
      !c.already &&
      !localStorage.getItem(seenKey)
    ) {
      choosePromptedRef.current = true;
      localStorage.setItem(seenKey, "1");
      const t = setTimeout(() => setChooseModalOpen(true), 1800);
      return () => clearTimeout(t);
    }
    if (!todayPickPromptedRef.current && !choosePromptedRef.current && c.eligible && c.for_today && !c.already) {
      todayPickPromptedRef.current = true;
      setChooseModalOpen(true);
    }
  }, [state, onToday]);

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
      const res = await fetch("/api/boardle/guess", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ word, offset: first, date: viewedDate }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Guess failed.");
      const next: BoardleState = await res.json();
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
      const res = await fetch("/api/boardle/hint", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ type, board: board ?? undefined, pay: payFreeze ? "freeze" : undefined, date: viewedDate }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Joker failed.");
      const next: BoardleState = await res.json();
      setState(next);
    } catch (err) {
      setMsg({ text: err instanceof Error ? err.message : "Joker failed.", ok: false });
    } finally {
      setBusy(false);
    }
  }

  async function submitChoice(word: string, hint: string) {
    const res = await fetch("/api/boardle/choose", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ word, hint }),
    });
    if (!res.ok) throw new Error((await res.text()) || "Could not save.");
    const result: { word: string; hint: string | null } = await res.json();
    setState((s) => ({ ...s, choose: { ...s.choose, already: result.word, already_hint: result.hint ?? null } }));
    setChooseModalOpen(false); // picked — back to the resting inline card
  }

  // Write a shared clue for a board you've solved that has none yet — it then
  // shows to every player under that board.
  async function saveBoardHint() {
    const hint = addHintText.trim();
    if (!hint) {
      setAddHintErr("Write something first.");
      return;
    }
    if (busyRef.current || addHintBoard === null) return;
    setBusy(true);
    setAddHintErr(null);
    try {
      const res = await fetch("/api/boardle/add-hint", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ board: addHintBoard, hint, date: viewedDate }),
      });
      if (!res.ok) throw new Error((await res.text()) || "Could not save hint.");
      const next: BoardleState = await res.json();
      setState(next);
      setAddHintBoard(null);
      setAddHintText("");
    } catch (err) {
      setAddHintErr(err instanceof Error ? err.message : "Could not save hint.");
    } finally {
      setBusy(false);
    }
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
  const showChoose = onToday && state.choose.eligible && (state.me.solved || state.choose.for_today);
  // A hintless board still earns a card in the clue row if I can add a
  // community hint to it (solved it, or own it) — the "+ Add a hint" CTA
  // takes the clue's place. The two states never coexist for a given board.
  const canAddHint = (b: number) =>
    !state.board_hints[b]?.trim() && (state.me.solved_boards[b] || state.me.owned.includes(b));
  const anyBoardHint = state.board_hints.some((h, b) => h?.trim() || canAddHint(b));
  const boardHints = (state.me.hints || []).filter(
    (h): h is ParsedHint & { type: "orange" | "green"; board: number } => h.type !== "armor",
  );

  return (
    <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="min-w-0 space-y-6">
        <PageHeader
          eyebrow="Daily puzzle"
          title={
            <>
              B<span className="gradient-text">oardle</span>
            </>
          }
          description="4 board puzzle a day. Solve every board before the guesses run out. A guess can be any word from 5 letters up to the day's length (use the space bar to place it). Beat the day and you get to set one of tomorrow's words."
        />

        <div className="rise rise-1 flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="h-[34px] w-[34px] rounded-full"
            title="Previous day"
            aria-label="Previous day"
            disabled={viewedDate <= bounds.earliest}
            onClick={() => goToDate(addDays(viewedDate, -1))}
          >
            ‹
          </Button>
          <Button
            type="button"
            variant="outline"
            className="rounded-full font-bold"
            title="Pick a day"
            onClick={() => setCalendarOpen(true)}
          >
            <span suppressHydrationWarning>{(onToday ? "Today · " : "") + fmtDayLabel(viewedDate)}</span>
          </Button>
          <Button
            type="button"
            variant="outline"
            size="icon"
            className="h-[34px] w-[34px] rounded-full"
            title="Next day"
            aria-label="Next day"
            disabled={viewedDate >= bounds.today}
            onClick={() => goToDate(addDays(viewedDate, 1))}
          >
            ›
          </Button>
          {!onToday && (
            <Button type="button" variant="outline" size="sm" onClick={() => goToDate(bounds.today)}>
              Back to today
            </Button>
          )}
          <Button render={<Link href="/boardle-rt" />}>Unlimited real-time mode</Button>
        </div>

        <div className="rise rise-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
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
                  className="glow-card overflow-hidden rounded-md border bg-card p-3 text-sm leading-relaxed text-muted-foreground"
                >
                  <span className="mb-1.5 block text-[0.68rem] font-bold tracking-wide text-muted-foreground/70 uppercase">
                    Board {b + 1} clue
                  </span>
                  <ClampedHint hint={hint} onExpand={() => setFullHint({ board: b, hint })} />
                </div>
              ) : canAddHint(b) ? (
                <button
                  key={b}
                  type="button"
                  onClick={() => {
                    setAddHintText("");
                    setAddHintErr(null);
                    setAddHintBoard(b);
                  }}
                  className="glow-card cursor-pointer overflow-hidden rounded-md border bg-card p-3 text-left text-sm transition-colors hover:border-primary/40 hover:bg-primary/5"
                >
                  <span className="mb-1.5 block text-[0.68rem] font-bold tracking-wide text-muted-foreground/70 uppercase">
                    Board {b + 1} clue
                  </span>
                  <span className="font-semibold text-muted-foreground">+ Add a hint for others</span>
                </button>
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

        {showChoose && !chooseModalOpen && <ChooseWord choose={state.choose} onSubmit={submitChoice} />}
      </div>

      <aside className="space-y-8">
        <div>
          <p className="eyebrow mb-3">Standings</p>
          <StatsPanel stats={state.stats} />
        </div>
        <div>
          <p className="eyebrow mb-3">Competition</p>
          <OpponentsPanel opponents={state.opponents} numBoards={state.num_boards} length={L} />
        </div>
      </aside>

      {/* "You solved it!" / today's-late-pick popup — the same ChooseWord
          card, centered, so the player can pick without scrolling. */}
      {chooseModalOpen && showChoose && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={(e) => {
            if (e.target === e.currentTarget) setChooseModalOpen(false);
          }}
        >
          <div className="max-h-[calc(100vh-2rem)] w-full max-w-lg overflow-y-auto">
            <ChooseWord choose={state.choose} onSubmit={submitChoice} />
          </div>
          <button
            type="button"
            className="fixed top-5 right-5 flex h-9 w-9 items-center justify-center rounded-full border bg-card text-muted-foreground hover:text-foreground"
            title="Choose later"
            onClick={() => setChooseModalOpen(false)}
          >
            ✕
          </button>
        </div>
      )}

      {/* Full-hint modal: the untruncated hint, monospace + unwrapped so
          ASCII art someone pastes in stays intact. */}
      {fullHint && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={(e) => {
            if (e.target === e.currentTarget) setFullHint(null);
          }}
        >
          <div className="glow-card flex max-h-[85vh] w-[min(94vw,900px)] flex-col overflow-hidden rounded-xl border bg-card p-5">
            <h2 className="mb-3 text-lg font-semibold">Board {fullHint.board + 1} clue</h2>
            <div className="flex-1 overflow-auto rounded-md border bg-black/25 px-4 py-3.5 font-mono text-sm leading-snug whitespace-pre">
              {fullHint.hint}
            </div>
            <div className="mt-3.5 flex justify-end">
              <Button type="button" variant="outline" onClick={() => setFullHint(null)}>
                Close
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Calendar popup: jump to any past puzzle day. */}
      {calendarOpen && (
        <HistoryCalendar
          viewedDate={viewedDate}
          onPick={(date) => {
            goToDate(date);
            setCalendarOpen(false);
          }}
          onClose={() => setCalendarOpen(false)}
        />
      )}

      {/* Add-a-hint modal (styled like the word picker). */}
      {addHintBoard !== null && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={(e) => {
            if (e.target === e.currentTarget) setAddHintBoard(null);
          }}
        >
          <div className="glow-card w-full max-w-lg overflow-hidden rounded-xl border bg-card p-5">
            <h2 className="mb-1 text-lg font-semibold">Add a hint for others</h2>
            <p className="mb-3.5 text-sm text-muted-foreground">Give the next players a clue without spoiling the word.</p>
            <textarea
              autoFocus
              value={addHintText}
              onChange={(e) => setAddHintText(e.target.value)}
              placeholder="e.g. Think kitchen appliances..."
              className="min-h-24 w-full resize-y rounded-md border bg-background px-3 py-2 text-sm"
            />
            {addHintErr && <p className="mt-2 text-sm text-destructive">{addHintErr}</p>}
            <div className="mt-3.5 flex justify-end gap-2.5">
              <Button type="button" variant="outline" onClick={() => setAddHintBoard(null)}>
                Cancel
              </Button>
              <Button type="button" onClick={saveBoardHint}>
                Save hint
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// A community/word-picker clue clamped to 3 lines; when the full text
// overflows (e.g. ASCII art), a "Click for full hint" button opens the big
// unwrapped modal instead of letting the card grow unbounded.
function ClampedHint({ hint, onExpand }: { hint: string; onExpand: () => void }) {
  const ref = useRef<HTMLDivElement>(null);
  const [overflowing, setOverflowing] = useState(false);

  useEffect(() => {
    const el = ref.current;
    // Measuring rendered size can only happen after layout, so this has to
    // be an effect rather than derived state.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (el) setOverflowing(el.scrollHeight > el.clientHeight + 1);
  }, [hint]);

  return (
    <>
      <div ref={ref} className="line-clamp-3 break-words whitespace-pre-wrap">
        {hint}
      </div>
      {overflowing && (
        <button
          type="button"
          onClick={onExpand}
          className="mt-1.5 block cursor-pointer text-xs font-bold text-primary underline hover:opacity-85"
        >
          Click for full hint
        </button>
      )}
    </>
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
