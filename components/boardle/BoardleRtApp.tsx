"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";

import type { BoardleRtPayload, RtStandingRow } from "@/lib/boardle-rt";
import type { CellColor } from "@/lib/boardle-score";
import { useRealtimeRoom } from "@/lib/realtime-client";
import { Button } from "@/components/ui/button";
import { Board } from "@/components/boardle/Board";
import { Keyboard } from "@/components/boardle/Keyboard";

type LetterColor = "green" | "orange" | "grey";

function fmtMMSS(secs: number): string {
  secs = Math.max(0, Math.round(secs));
  const m = Math.floor(secs / 60);
  const s = secs % 60;
  return `${m}:${String(s).padStart(2, "0")}`;
}

// Unlimited real-time mode: one shared room cycling lobby -> voting ->
// selecting -> playing -> results. The server resolves phase transitions
// lazily on every poll (see lib/boardle-rt.ts); this client just renders
// whatever phase comes back and posts actions. Ported from boardle-rt.php.
export function BoardleRtApp({ myUsername }: { myUsername: string }) {
  const [state, setState] = useState<BoardleRtPayload | null>(null);
  // Nothing renders differently while a request is in flight — busy only
  // guards against double-submits and poll clobbering, so a ref suffices.
  const busyRef = useRef(false);

  // Countdown smoothing: the server's `elapsed` is only as fresh as the last
  // poll, so remaining time is extrapolated with a local 1s tick.
  const [syncTs, setSyncTs] = useState(() => Date.now());
  const [nowTs, setNowTs] = useState(() => Date.now());
  useEffect(() => {
    const id = setInterval(() => setNowTs(Date.now()), 1000);
    return () => clearInterval(id);
  }, []);
  const localElapsed = (state?.elapsed ?? 0) + Math.floor((nowTs - syncTs) / 1000);
  const remaining = state?.deadline !== undefined ? state.deadline - localElapsed : 0;

  const apply = useCallback((next: BoardleRtPayload) => {
    setState(next);
    setSyncTs(Date.now());
  }, []);

  const loadState = useCallback(async () => {
    if (busyRef.current) return;
    try {
      const res = await fetch("/api/boardle/rt/state");
      if (!res.ok) return;
      apply(await res.json());
    } catch {
      // ignore transient poll failures
    }
  }, [apply]);

  // Push on top of the poll: the 1.5s poll must stay (it doubles as the
  // lobby presence heartbeat and drives the lazy deadline-based phase
  // transitions), but a room notification lands opponents' actions
  // instantly instead of up to one poll interval later.
  useRealtimeRoom("wordle:rt", "update", () => {
    if (!document.hidden) loadState();
  });

  useEffect(() => {
    // Initial fetch on mount (there's no server-rendered state to hydrate
    // from — the room can change phase every second).
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadState();
    const poll = setInterval(() => {
      if (!document.hidden) loadState();
    }, 1500);
    const onVisible = () => {
      if (!document.hidden) loadState();
    };
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      clearInterval(poll);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [loadState]);

  async function post(url: string, params: Record<string, unknown>): Promise<BoardleRtPayload> {
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(params),
    });
    if (!res.ok) throw new Error((await res.text()) || "Request failed.");
    return res.json();
  }

  async function action(url: string, params: Record<string, unknown>, onError?: (msg: string) => void) {
    if (busyRef.current) return;
    busyRef.current = true;
    try {
      apply(await post(url, params));
    } catch (err) {
      onError?.(err instanceof Error ? err.message : "Request failed.");
    } finally {
      busyRef.current = false;
    }
  }

  // ── Playing-phase input state (reset whenever a new round starts) ────────
  const [buffer, setBuffer] = useState("");
  const [kbBoard, setKbBoard] = useState(0);
  const [playMsg, setPlayMsg] = useState("");
  const [pressedKey, setPressedKey] = useState<string | null>(null);
  const lastPlayingRound = useRef<number | null>(null);
  useEffect(() => {
    if (state?.phase === "playing" && lastPlayingRound.current !== state.round_id) {
      lastPlayingRound.current = state.round_id;
      setBuffer("");
      setKbBoard(0);
      setPlayMsg("");
    }
  }, [state]);

  function submitGuess() {
    if (!state || !state.me || busyRef.current || state.me.finished) return;
    const first = buffer.search(/[a-z]/);
    if (first === -1) {
      setPlayMsg("Type a word.");
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
      setPlayMsg("No gaps inside the word — spaces only before or after it.");
      return;
    }
    if (word.length < 5) {
      setPlayMsg("Use at least 5 letters.");
      return;
    }
    busyRef.current = true;
    post("/api/boardle/rt/guess", { word, offset: first })
      .then((next) => {
        apply(next);
        setBuffer("");
        setPlayMsg("");
      })
      .catch((err) => setPlayMsg(err instanceof Error ? err.message : "Guess failed."))
      .finally(() => {
        busyRef.current = false;
      });
  }

  function handleKey(key: string) {
    if (!state || state.phase !== "playing" || !state.me || state.me.finished || busyRef.current) return;
    setPressedKey(key);
    setTimeout(() => setPressedKey((k) => (k === key ? null : k)), 160);
    if (key === "enter") {
      submitGuess();
      return;
    }
    const len = state.word_length ?? 0;
    if (key === "back") {
      setBuffer((b) => b.slice(0, -1));
      setPlayMsg("");
      return;
    }
    if (key === "space") {
      setBuffer((b) => (b.length < len ? b + " " : b));
      setPlayMsg("");
      return;
    }
    if (/^[a-z]$/.test(key)) {
      setBuffer((b) => (b.length < len ? b + key : b));
      setPlayMsg("");
    }
  }

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (!state || state.phase !== "playing") return;
      const tag = (document.activeElement && document.activeElement.tagName) || "";
      if (tag === "INPUT" || tag === "TEXTAREA") return; // don't hijack the hint/custom-word fields
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

  // Best colour seen for each letter on a given board (green > orange > grey).
  function kbStatesFor(b: number): Record<string, LetterColor> {
    const rank: Record<LetterColor, number> = { grey: 1, orange: 2, green: 3 };
    const st: Record<string, LetterColor> = {};
    if (!state?.me) return st;
    for (const g of state.me.guesses) {
      const colors = g.boards[b];
      for (let i = 0; i < g.text.length; i++) {
        const ch = g.text[i];
        const c = colors[i];
        if (ch === "_" || c === "empty") continue;
        if (!st[ch] || rank[c as LetterColor] > rank[st[ch]]) st[ch] = c as LetterColor;
      }
    }
    return st;
  }

  if (!state) {
    return <p className="py-20 text-center text-sm text-muted-foreground">Connecting…</p>;
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div className="flex items-center justify-between">
        <Link href="/boardle" className="text-sm text-muted-foreground hover:text-foreground">
          ← Daily Boardle
        </Link>
      </div>

      {state.phase === "lobby" && <LobbyPhase state={state} myUsername={myUsername} onToggleReady={onToggleReady} />}
      {state.phase === "voting" && <VotingPhase state={state} remaining={remaining} onVote={onVote} />}
      {state.phase === "selecting" && <SelectingPhase state={state} remaining={remaining} onChoose={onChoose} />}
      {state.phase === "playing" && renderPlaying()}
      {state.phase === "results" && <ResultsPhase state={state} onContinue={onContinue} />}
    </div>
  );

  function onToggleReady() {
    const mine = (state?.active ?? []).find((p) => p.username === myUsername);
    const nowReady = !(mine && mine.ready);
    action("/api/boardle/rt/ready", { ready: nowReady });
  }

  function onVote(length: number, onError: (m: string) => void) {
    if (!state?.in_round) return;
    action("/api/boardle/rt/vote", { length }, onError);
  }

  function onChoose(word: string, hint: string, onError: (m: string) => void) {
    action("/api/boardle/rt/choose", { word, hint }, onError);
  }

  function onContinue() {
    action("/api/boardle/rt/continue", {});
  }

  function renderPlaying() {
    const me = state!.me!;
    const n = state!.num_boards ?? 0;
    const len = state!.word_length ?? 0;
    const max = state!.max_guesses ?? 0;
    const activeRowIndex = me.finished ? -1 : me.guesses_used;
    const answers = me.answers ?? [];
    const kbClamped = kbBoard >= n ? 0 : kbBoard;

    return (
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_240px]">
        <div className="min-w-0 space-y-4">
          <div
            className={`text-center font-mono text-3xl font-bold tabular-nums ${remaining <= 60 ? "text-destructive" : ""}`}
          >
            {fmtMMSS(remaining)}
          </div>

          <div className="grid grid-cols-2 gap-3.5" style={{ gridTemplateColumns: `repeat(${Math.min(n, 4)}, minmax(0, 1fr))` }}>
            {Array.from({ length: n }, (_, b) => {
              const isMine = b === state!.my_position;
              return (
                <Board
                  key={b}
                  index={b}
                  length={len}
                  maxGuesses={max}
                  bonusRow={-1}
                  isOwned={isMine}
                  answer={isMine ? me.own_word : me.finished ? (answers[b] ?? null) : null}
                  solved={me.solved_boards[b]}
                  finished={me.finished}
                  guesses={me.guesses}
                  activeRowIndex={activeRowIndex}
                  buffer={buffer}
                  pickable={false}
                  title={state!.players?.[b] || `Board ${b + 1}`}
                  hintText={me.hints[b] ?? ""}
                />
              );
            })}
          </div>

          <p className="min-h-[1.2em] text-center text-sm text-muted-foreground">
            {playMsg ||
              (me.finished
                ? me.solved
                  ? "You solved every board!"
                  : "No guesses left — waiting for the round to end."
                : "")}
          </p>

          <Keyboard
            numBoards={n}
            kbBoard={kbClamped}
            onSelectBoard={setKbBoard}
            solvedBoards={me.solved_boards}
            letterStates={n > 0 ? kbStatesFor(kbClamped) : {}}
            disabled={me.finished}
            onKey={handleKey}
            pressedKey={pressedKey}
          />
        </div>

        {/* Opponents' live progress: colors only, and each opponent's own
            (auto-solved) board is skipped — it's not real progress and would
            leak color hints about their own word. */}
        <aside className="space-y-4">
          <p className="eyebrow">Competition</p>
          {(state!.opponents ?? []).map((o) => (
            <div key={o.username} className="glow-card overflow-hidden rounded-xl border bg-card p-3">
              <div className="mb-2 flex items-center justify-between text-sm font-semibold">
                <span>{o.username}</span>
                {o.solved && <span className="text-emerald-500">✓</span>}
              </div>
              <div className="space-y-2">
                {Array.from({ length: n }, (_, b) => {
                  if (b === o.position) return null;
                  let shown = o.boards.length;
                  if (o.solved_boards[b]) {
                    for (let r = 0; r < o.boards.length; r++) {
                      if (o.boards[r].boards[b].every((c: CellColor) => c === "green")) {
                        shown = r + 1;
                        break;
                      }
                    }
                  }
                  return (
                    <div
                      key={b}
                      className="grid gap-[2px]"
                      style={{ gridTemplateColumns: `repeat(${len}, minmax(0, 1fr))` }}
                    >
                      {o.boards.slice(0, shown).map((g, r) =>
                        g.boards[b].map((c: CellColor, i: number) => (
                          <div
                            key={`${r}-${i}`}
                            className={`aspect-square rounded-[2px] ${
                              c === "green"
                                ? "bg-emerald-600"
                                : c === "orange"
                                  ? "bg-amber-500"
                                  : c === "grey"
                                    ? "bg-neutral-500"
                                    : "bg-muted"
                            }`}
                          />
                        )),
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </aside>
      </div>
    );
  }
}

// ── Lobby ────────────────────────────────────────────────────────────────
// Only whoever currently has this window open shows up here (tracked via a
// presence heartbeat sent with every poll) — not every site user, and not
// everyone who's ever clicked ready. A round only starts once everyone
// shown here is ready.
function LobbyPhase({
  state,
  myUsername,
  onToggleReady,
}: {
  state: BoardleRtPayload;
  myUsername: string;
  onToggleReady: () => void;
}) {
  const active = state.active ?? [];
  const mine = active.find((p) => p.username === myUsername);
  const iAmReady = mine ? mine.ready : false;

  const sub =
    active.length < (state.min_players ?? 2)
      ? `Waiting for at least ${state.min_players ?? 2} players to open this page…`
      : active.every((p) => p.ready)
        ? "Starting…"
        : "Waiting for everyone here to be ready…";

  return (
    <div className="space-y-6 py-10 text-center">
      <h1 className="font-heading text-3xl font-bold">
        Unlimited <span className="gradient-text">Boardle</span>
      </h1>
      <p className="text-sm text-muted-foreground">{sub}</p>
      <div className="flex flex-wrap justify-center gap-3">
        {active.map((p) => (
          <div
            key={p.username}
            className={`glow-card w-32 space-y-1.5 overflow-hidden rounded-xl border bg-card p-4 ${
              p.ready ? "border-emerald-500/60" : ""
            } ${p.username === myUsername ? "ring-2 ring-primary/40" : ""}`}
          >
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted text-lg font-bold">
              {p.username.slice(0, 1).toUpperCase()}
            </div>
            <div className="text-sm font-semibold">{p.username}</div>
            <div className={`text-xs ${p.ready ? "text-emerald-500" : "text-muted-foreground"}`}>
              {p.ready ? "Ready" : "Waiting"}
            </div>
          </div>
        ))}
      </div>
      <Button size="lg" onClick={onToggleReady}>
        {iAmReady ? "Cancel ready" : "I'm ready"}
      </Button>
    </div>
  );
}

// ── Voting ───────────────────────────────────────────────────────────────
function VotingPhase({
  state,
  remaining,
  onVote,
}: {
  state: BoardleRtPayload;
  remaining: number;
  onVote: (length: number, onError: (m: string) => void) => void;
}) {
  const [msg, setMsg] = useState("");
  return (
    <div className="space-y-6 py-10 text-center">
      <h1 className="font-heading text-2xl font-bold">Vote a word length</h1>
      <div
        className={`font-mono text-4xl font-bold tabular-nums ${remaining <= 5 ? "text-destructive" : ""}`}
      >
        {Math.max(0, Math.ceil(remaining))}
      </div>
      <div className="flex flex-wrap justify-center gap-3">
        {(state.length_choices ?? []).map((len) => {
          const picked = state.my_vote === len;
          const n = state.tally?.[len] ?? 0;
          return (
            <button
              key={len}
              type="button"
              onClick={() => onVote(len, setMsg)}
              className={`glow-card w-36 cursor-pointer space-y-1 overflow-hidden rounded-xl border bg-card p-5 text-lg font-bold transition-colors hover:border-primary/50 ${
                picked ? "border-primary ring-2 ring-primary/40" : ""
              }`}
            >
              {len} letters
              <span className="block text-xs font-medium text-muted-foreground">
                {n} vote{n === 1 ? "" : "s"}
              </span>
            </button>
          );
        })}
      </div>
      {!state.in_round && <p className="text-sm text-muted-foreground">Spectating this round.</p>}
      {msg && <p className="text-sm text-destructive">{msg}</p>}
    </div>
  );
}

// ── Selecting ────────────────────────────────────────────────────────────
function SelectingPhase({
  state,
  remaining,
  onChoose,
}: {
  state: BoardleRtPayload;
  remaining: number;
  onChoose: (word: string, hint: string, onError: (m: string) => void) => void;
}) {
  const [suggestions, setSuggestions] = useState<string[]>([]);
  const [word, setWord] = useState("");
  const [hint, setHint] = useState("");
  const [msg, setMsg] = useState("");

  const loadSuggestions = useCallback(() => {
    fetch("/api/boardle/rt/suggest")
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((res: { suggestions: string[] }) => setSuggestions(res.suggestions ?? []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadSuggestions();
  }, [loadSuggestions, state.word_length]);

  const waiting = state.i_chose || !state.in_round;

  return (
    <div className="mx-auto max-w-lg space-y-5 py-10 text-center">
      <h1 className="font-heading text-2xl font-bold">
        Pick your word <span className="text-muted-foreground">({state.word_length} letters)</span>
      </h1>
      <div
        className={`font-mono text-4xl font-bold tabular-nums ${remaining <= 20 ? "text-destructive" : ""}`}
      >
        {fmtMMSS(remaining)}
      </div>

      {waiting ? (
        <p className="text-sm text-muted-foreground">
          {state.in_round ? "Word locked in. Waiting for the others…" : "Spectating this round."}
        </p>
      ) : (
        <>
          <div className="flex flex-wrap justify-center gap-2">
            {suggestions.map((w) => (
              <button
                key={w}
                type="button"
                onClick={() => setWord(w)}
                className={`cursor-pointer rounded-full border px-4 py-1.5 font-mono text-sm tracking-wider uppercase hover:border-primary/50 ${
                  word === w ? "border-primary bg-primary/10" : "bg-card"
                }`}
              >
                {w}
              </button>
            ))}
          </div>
          <Button type="button" variant="outline" size="sm" onClick={loadSuggestions}>
            Regenerate
          </Button>
          <input
            type="text"
            value={word}
            onChange={(e) => setWord(e.target.value)}
            placeholder="or type your own word"
            autoComplete="off"
            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
          />
          <textarea
            value={hint}
            onChange={(e) => setHint(e.target.value)}
            placeholder="Mandatory hint for whoever plays your board…"
            className="min-h-20 w-full resize-y rounded-md border bg-background px-3 py-2 text-sm"
          />
          <Button
            type="button"
            onClick={() => {
              const w = word.trim();
              const h = hint.trim();
              if (!w) {
                setMsg("Pick or type a word.");
                return;
              }
              if (!h) {
                setMsg("A hint is mandatory.");
                return;
              }
              setMsg("");
              onChoose(w, h, setMsg);
            }}
          >
            Confirm word + hint
          </Button>
          {msg && <p className="text-sm text-destructive">{msg}</p>}
        </>
      )}
      <p className="text-xs text-muted-foreground">
        {state.chosen_count ?? 0}/{state.num_boards ?? 0} words locked in
      </p>
    </div>
  );
}

// ── Results ──────────────────────────────────────────────────────────────
function Podium({ title, rows, field }: { title: string; rows: RtStandingRow[]; field: "speed_wins" | "guess_wins" }) {
  return (
    <div className="glow-card overflow-hidden rounded-xl border bg-card p-4 text-left">
      <h3 className="mb-2 text-sm font-semibold">{title}</h3>
      <ol className="space-y-1 text-sm">
        {rows.slice(0, 3).map((r) => (
          <li key={r.username} className="flex items-baseline justify-between text-muted-foreground">
            <span className="font-medium text-foreground">{r.username}</span>
            <span>
              {r[field]} win{r[field] === 1 ? "" : "s"}
            </span>
          </li>
        ))}
      </ol>
    </div>
  );
}

function ResultsPhase({ state, onContinue }: { state: BoardleRtPayload; onContinue: () => void }) {
  const rows = state.results ?? [];
  const standings = state.standings ?? { speed: [], guesses: [] };
  return (
    <div className="mx-auto max-w-2xl space-y-6 py-10 text-center">
      <h1 className="font-heading text-2xl font-bold">Round over</h1>

      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs text-muted-foreground uppercase">
            <th className="py-2">Player</th>
            <th className="py-2">Solved</th>
            <th className="py-2">Guesses</th>
            <th className="py-2"></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.username} className="border-b text-left">
              <td className="py-2 font-medium">{r.username}</td>
              <td className="py-2">{r.solved ? "✓" : "✗"}</td>
              <td className="py-2 tabular-nums">{r.guesses_used}</td>
              <td className="space-x-1.5 py-2">
                {r.speed_win && (
                  <span className="rounded-full border border-sky-500/50 bg-sky-500/15 px-2 py-0.5 text-xs font-bold text-sky-500">
                    Fastest
                  </span>
                )}
                {r.guess_win && (
                  <span className="rounded-full border border-emerald-500/50 bg-emerald-500/15 px-2 py-0.5 text-xs font-bold text-emerald-500">
                    Fewest guesses
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {state.answers && state.answers.length > 0 && (
        <p className="text-sm text-muted-foreground">
          Words:{" "}
          {state.answers.map((w, i) => (
            <strong key={i} className="mr-2 font-mono tracking-widest text-foreground uppercase">
              {w}
            </strong>
          ))}
        </p>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Podium title="Fastest solvers" rows={standings.speed} field="speed_wins" />
        <Podium title="Fewest guesses" rows={standings.guesses} field="guess_wins" />
      </div>

      <Button size="lg" onClick={onContinue}>
        Continue
      </Button>
    </div>
  );
}
