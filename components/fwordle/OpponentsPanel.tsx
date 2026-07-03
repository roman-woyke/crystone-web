import type { OpponentState } from "@/lib/fwordle";

export function OpponentsPanel({
  opponents,
  numBoards,
  length,
}: {
  opponents: OpponentState[];
  numBoards: number;
  length: number;
}) {
  if (opponents.length === 0) {
    return <p className="text-sm text-muted-foreground">No one else has played yet today.</p>;
  }

  return (
    <div className="space-y-3">
      {opponents.map((o) => {
        const solvedCount = o.solved_boards.filter(Boolean).length;
        const jokerTxt = o.jokers_used > 0 ? ` · 🃏${o.jokers_used}` : "";
        const statusTxt =
          (o.finished
            ? o.solved
              ? `solved in ${o.guesses_used}`
              : `done · ${solvedCount}/${numBoards}`
            : `${o.guesses_used}/${o.max_guesses} guesses`) + jokerTxt;

        return (
          <div
            key={o.username}
            className={`glow-card overflow-hidden rounded-md border bg-card p-3 ${o.finished && o.solved ? "border-emerald-500/40" : ""}`}
          >
            <div className="mb-2 flex items-baseline justify-between gap-2">
              <span className={`text-sm font-bold ${o.finished && o.solved ? "text-emerald-500" : ""}`}>{o.username}</span>
              <span className="text-xs text-muted-foreground tabular-nums">{statusTxt}</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {Array.from({ length: numBoards }, (_, b) => (
                <MiniBoard key={b} board={o} boardIndex={b} length={length} />
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function MiniBoard({ board, boardIndex, length }: { board: OpponentState; boardIndex: number; length: number }) {
  const oMax = board.max_guesses;
  const owned = board.owned;
  const cells: React.ReactNode[] = [];

  if (owned.includes(boardIndex)) {
    // Auto-solved (their own pick) — a single green row (never actually guessed).
    for (let r = 0; r < oMax; r++) {
      for (let i = 0; i < length; i++) {
        cells.push(<div key={`${r}-${i}`} className={`aspect-square rounded-[2px] ${r === 0 ? "bg-emerald-600" : "bg-muted"}`} />);
      }
    }
  } else {
    // Freeze a solved board at its winning (all-green) row.
    let shown = board.guesses.length;
    if (board.solved_boards[boardIndex]) {
      for (let r = 0; r < board.guesses.length; r++) {
        if (board.guesses[r].boards[boardIndex].every((c) => c === "green")) {
          shown = r + 1;
          break;
        }
      }
    }
    for (let r = 0; r < oMax; r++) {
      for (let i = 0; i < length; i++) {
        const c = r < shown ? board.guesses[r].boards[boardIndex][i] : "";
        const cls = c === "green" ? "bg-emerald-600" : c === "orange" ? "bg-amber-500" : c === "grey" ? "bg-neutral-500" : "bg-muted";
        cells.push(<div key={`${r}-${i}`} className={`aspect-square rounded-[2px] ${cls}`} />);
      }
    }
  }

  return (
    <div className="grid flex-1 gap-0.5" style={{ gridTemplateColumns: `repeat(${length}, minmax(0, 1fr))` }}>
      {cells}
    </div>
  );
}
