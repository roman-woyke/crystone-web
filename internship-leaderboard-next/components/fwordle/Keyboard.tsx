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
      <div className="flex max-w-xl flex-col gap-1.5">
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
        "h-12 max-w-11 flex-1 rounded-md bg-muted text-sm font-semibold uppercase transition-transform disabled:cursor-not-allowed disabled:opacity-50",
        wide && "max-w-16 text-xs",
        space && "max-w-64 text-xs tracking-widest",
        state === "green" && "bg-emerald-600 text-white",
        state === "orange" && "bg-amber-500 text-white",
        state === "grey" && "bg-neutral-500 text-white opacity-85",
        pressedKey === k && "scale-90",
      )}
    >
      {label}
    </button>
  );
}
