"use client";

import { cn } from "@/lib/utils";

const ROWS = ["qwertyuiop", "asdfghjkl", "zxcvbnm"];

type LetterColor = "green" | "orange" | "grey";

export function Keyboard({
  numBoards,
  kbBoard,
  onSelectBoard,
  solvedBoards,
  letterStates,
  disabled,
  onKey,
  pressedKey,
}: {
  numBoards: number;
  kbBoard: number;
  onSelectBoard: (b: number) => void;
  solvedBoards: boolean[];
  letterStates: Record<string, LetterColor>;
  disabled: boolean;
  onKey: (key: string) => void;
  pressedKey: string | null;
}) {
  return (
    <div className="flex items-start justify-center gap-2.5">
      {numBoards > 1 && (
        <div className="flex flex-shrink-0 flex-col gap-1.5">
          {Array.from({ length: numBoards }, (_, b) => (
            <button
              key={b}
              type="button"
              onClick={() => onSelectBoard(b)}
              title={`Board ${b + 1}${solvedBoards[b] ? " (solved)" : ""}`}
              className={cn(
                "flex h-12 w-10 items-center justify-center rounded-md bg-muted text-sm font-semibold transition-colors",
                b === kbBoard && "bg-primary text-primary-foreground",
                solvedBoards[b] && "ring-2 ring-emerald-500 ring-offset-1 ring-offset-background",
              )}
            >
              {b + 1}
            </button>
          ))}
        </div>
      )}
      <div className="flex w-full max-w-3xl flex-col gap-1.5">
        {ROWS.map((row, idx) => (
          <div key={idx} className="flex justify-center gap-1.5">
            {idx === 2 && <Key label="Enter" k="enter" wide disabled={disabled} onKey={onKey} pressedKey={pressedKey} />}
            {row.split("").map((ch) => (
              <Key key={ch} label={ch} k={ch} state={letterStates[ch]} disabled={disabled} onKey={onKey} pressedKey={pressedKey} />
            ))}
            {idx === 2 && <Key label="⌫" k="back" wide disabled={disabled} onKey={onKey} pressedKey={pressedKey} />}
          </div>
        ))}
        <div className="flex justify-center gap-1.5">
          <Key label="space" k="space" space disabled={disabled} onKey={onKey} pressedKey={pressedKey} />
        </div>
      </div>
    </div>
  );
}

function Key({
  label,
  k,
  state,
  wide,
  space,
  disabled,
  onKey,
  pressedKey,
}: {
  label: string;
  k: string;
  state?: LetterColor;
  wide?: boolean;
  space?: boolean;
  disabled: boolean;
  onKey: (key: string) => void;
  pressedKey: string | null;
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => onKey(k)}
      className={cn(
        // Wordle-style keys: neutral grey with white text; a struck-out
        // (grey-state) key drops to near-background and shrinks slightly so
        // dead letters visibly recede from the live ones.
        "h-12 max-w-16 flex-1 rounded-md border border-black/15 bg-[#818384] text-sm font-semibold text-white uppercase transition-transform hover:border-black/25 hover:bg-[#6f7071] disabled:cursor-not-allowed disabled:opacity-50",
        wide && "max-w-24 text-xs",
        space && "max-w-96 text-xs tracking-widest",
        state === "green" && "border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-600",
        state === "orange" && "border-amber-500 bg-amber-500 text-white hover:bg-amber-500",
        state === "grey" && "scale-[0.92] border-[#222224] bg-[#222224] text-white opacity-70 hover:bg-[#222224]",
        pressedKey === k && "scale-90",
      )}
    >
      {label}
    </button>
  );
}
