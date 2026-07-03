"use client";

import { cn } from "@/lib/utils";
import type { MeState } from "@/lib/fwordle";

const HINT_TYPES = [
  { type: "armor" as const, board: false, icon: "🛡️", label: "Armor (+1 guess)" },
  { type: "orange" as const, board: true, icon: "🔍", label: "Reveal (+2 🟨)" },
  { type: "green" as const, board: true, icon: "🧩", label: "Place (+1 🟩)" },
];

// The shared joker bar (also doubles as the legend); click one, then pick a
// board for the two board-attached types. Each costs 1 streak by default —
// the wallet chips double as the payment-mode switch (pay with a freeze
// instead).
export function JokerBar({
  me,
  pendingHint,
  canPickBoard,
  payMode,
  onSelect,
  onSetPayMode,
}: {
  me: MeState;
  pendingHint: string | null;
  canPickBoard: boolean;
  payMode: "streak" | "freeze";
  onSelect: (type: string) => void;
  onSetPayMode: (mode: "streak" | "freeze") => void;
}) {
  const typesUsed = me.hint_types_used;
  const canSpendStreak = me.streak >= 1 || me.free_joker;
  const canFreeze = me.can_freeze_joker;
  const broke = !canSpendStreak && !canFreeze;
  const payFreezeNow = canFreeze && (payMode === "freeze" || !canSpendStreak);

  const streakSelectable = canSpendStreak && canFreeze;
  const freezeSelectable = canFreeze;

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2.5">
        {HINT_TYPES.map((t) => {
          const used = typesUsed.includes(t.type);
          const active = pendingHint === t.type;
          const disabled = used || me.finished || broke || (t.board && !canPickBoard);
          const cost = used ? null : payFreezeNow ? "🧊 1" : me.free_joker ? "free" : "🔥 1";
          return (
            <button
              key={t.type}
              type="button"
              disabled={disabled}
              onClick={() => onSelect(t.type)}
              className={cn(
                "flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition-colors",
                active && "border-primary ring-2 ring-primary/30",
                used && "opacity-45",
                disabled && !used && "cursor-not-allowed opacity-50",
              )}
            >
              <span>{t.icon}</span>
              <span>
                {used ? "✓ " : ""}
                {t.label}
              </span>
              {cost && (
                <span
                  className={cn(
                    "rounded-full px-2 py-0.5 text-xs font-bold",
                    cost === "free"
                      ? "bg-emerald-600 text-white"
                      : cost.startsWith("🧊")
                        ? "bg-sky-500/15 text-sky-500"
                        : "bg-destructive/10 text-destructive",
                  )}
                >
                  {cost}
                </span>
              )}
            </button>
          );
        })}

        <button
          type="button"
          onClick={() => streakSelectable && onSetPayMode("streak")}
          title={streakSelectable ? "Click to pay jokers with streak" : "Streak you can spend on jokers"}
          className={cn(
            "flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-bold text-destructive",
            streakSelectable && "cursor-pointer",
            !payFreezeNow && "border-destructive bg-destructive/10",
          )}
        >
          🔥 {me.streak}
          {me.free_joker && " · 1 free"}
        </button>
        <button
          type="button"
          onClick={() => freezeSelectable && onSetPayMode("freeze")}
          title={freezeSelectable ? "Click to pay your next joker with a freeze (keeps your streak)" : "Streak freezes"}
          className={cn(
            "flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-bold text-sky-500",
            freezeSelectable && "cursor-pointer",
            payFreezeNow && "border-sky-500 bg-sky-500/10",
          )}
        >
          🧊 {me.freezes}
        </button>
      </div>
      <p className="min-h-[1.2em] text-sm text-primary">
        {pendingHint
          ? `Pick a board for the ${pendingHint} joker (click the joker again to cancel).`
          : payFreezeNow
            ? "Paying with a freeze — your streak stays safe (click 🔥 to pay with streak)."
            : me.free_joker
              ? "No streak — your first joker today is free."
              : broke
                ? "No streak or freezes left for jokers."
                : canFreeze
                  ? "Each joker costs 1 streak (click 🧊 to pay with a freeze)."
                  : "Each joker costs 1 streak."}
      </p>
    </div>
  );
}
