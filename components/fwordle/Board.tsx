"use client";

import { cn } from "@/lib/utils";
import type { BoardGuessRow, CellColor } from "@/lib/fwordle-score";

type CellClass = CellColor | "active" | "blank";

const CELL_CLASS: Record<CellClass, string> = {
  empty: "border-2 border-border text-foreground",
  active: "border-2 border-primary bg-muted text-foreground",
  blank: "border-2 border-dashed border-muted-foreground/30",
  green: "bg-emerald-600 text-white",
  orange: "bg-amber-500 text-white",
  grey: "bg-neutral-500 text-white",
};

function Cell({ letter, cls, bonus }: { letter: string; cls: CellClass; bonus: boolean }) {
  return (
    <div
      className={cn(
        "flex aspect-square min-w-0 items-center justify-center rounded-md text-[clamp(0.6rem,3.6cqw,1.1rem)] leading-none font-bold uppercase",
        CELL_CLASS[cls],
        bonus && "ring-2 ring-sky-400 ring-offset-1 ring-offset-background",
      )}
    >
      {letter}
    </div>
  );
}

// One board: a fixed-length x max-guesses grid. `guesses`/`buffer` are shared
// across every board (one guess is scored against all of them at once) — only
// the per-cell colouring differs.
export function Board({
  index,
  length,
  maxGuesses,
  bonusRow,
  isOwned,
  answer,
  solved,
  finished,
  guesses,
  activeRowIndex,
  buffer,
  pickable,
  onPick,
}: {
  index: number;
  length: number;
  maxGuesses: number;
  bonusRow: number;
  isOwned: boolean;
  answer: string | null;
  solved: boolean;
  finished: boolean;
  guesses: BoardGuessRow[];
  activeRowIndex: number;
  buffer: string;
  pickable: boolean;
  onPick?: () => void;
}) {
  const failed = finished && !solved;
  const rows: React.ReactNode[][] = [];

  if (isOwned) {
    for (let r = 0; r < maxGuesses; r++) {
      const cells: React.ReactNode[] = [];
      for (let i = 0; i < length; i++) {
        cells.push(
          r === 0 ? (
            <Cell key={i} letter={(answer ?? "")[i] ?? ""} cls="green" bonus={r === bonusRow} />
          ) : (
            <Cell key={i} letter="" cls="empty" bonus={r === bonusRow} />
          ),
        );
      }
      rows.push(cells);
    }
  } else {
    // A solved board freezes at its winning row — later guesses (aimed at
    // other boards) shouldn't keep filling it in.
    let shown = guesses.length;
    if (solved) {
      for (let r = 0; r < guesses.length; r++) {
        if (guesses[r].boards[index].every((c) => c === "green")) {
          shown = r + 1;
          break;
        }
      }
    }
    for (let r = 0; r < maxGuesses; r++) {
      const cells: React.ReactNode[] = [];
      if (r < shown) {
        const g = guesses[r];
        const colors = g.boards[index];
        for (let i = 0; i < length; i++) {
          const ch = g.text[i] === "_" ? "" : g.text[i];
          cells.push(<Cell key={i} letter={ch} cls={colors[i]} bonus={r === bonusRow} />);
        }
      } else if (r === activeRowIndex && !solved) {
        for (let i = 0; i < length; i++) {
          const ch = buffer[i];
          if (ch && ch !== " ") cells.push(<Cell key={i} letter={ch} cls="active" bonus={r === bonusRow} />);
          else cells.push(<Cell key={i} letter="" cls={buffer.length ? "blank" : "empty"} bonus={r === bonusRow} />);
        }
      } else {
        for (let i = 0; i < length; i++) cells.push(<Cell key={i} letter="" cls="empty" bonus={r === bonusRow} />);
      }
      rows.push(cells);
    }
  }

  return (
    <div
      onClick={pickable ? onPick : undefined}
      className={cn(
        "glow-card overflow-hidden rounded-xl border p-3 [container-type:inline-size]",
        solved && "border-emerald-500/60 bg-emerald-500/5",
        failed && "border-destructive/50",
        pickable && "cursor-pointer border-primary/70 ring-2 ring-primary/30 hover:bg-primary/5",
      )}
    >
      <div className="mb-2 flex items-center justify-between text-[0.68rem] font-bold tracking-wide text-muted-foreground uppercase">
        <span>Board {index + 1}</span>
        {isOwned ? (
          <span className="text-emerald-500">★ your word</span>
        ) : solved ? (
          <span className="text-emerald-500">✓ solved</span>
        ) : null}
      </div>
      <div className="grid gap-1" style={{ gridTemplateColumns: `repeat(${length}, minmax(0, 1fr))` }}>
        {rows.map((cells, r) => (
          <div key={r} className="contents">
            {cells}
          </div>
        ))}
      </div>
      {!isOwned && failed && answer && (
        <div className="mt-2 text-center text-sm text-destructive">
          Answer: <strong className="font-mono tracking-widest text-foreground uppercase">{answer}</strong>
        </div>
      )}
    </div>
  );
}
