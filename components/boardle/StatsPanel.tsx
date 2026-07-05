import type { PlayerStats } from "@/lib/boardle-streak";

// Podium tint for the top 3 rows (the list arrives already sorted by rank).
const RANK_ROW = [
  "border-l-[3px] border-l-amber-500 bg-gradient-to-r from-amber-400/15 to-transparent",
  "border-l-[3px] border-l-gray-400 bg-gradient-to-r from-gray-300/15 to-transparent",
  "border-l-[3px] border-l-amber-700 bg-gradient-to-r from-amber-600/15 to-transparent",
];
const RANK_NAME = ["text-amber-400", "text-gray-300", "text-amber-600"];

// Standings — one compact container, one line per player.
export function StatsPanel({ stats }: { stats: PlayerStats[] }) {
  return (
    <div className="glow-card overflow-hidden rounded-md border bg-card">
      {stats.map((s, i) => {
        const played = s.solves > 0 || s.streak > 0;
        return (
          <div
            key={s.username}
            className={`flex items-baseline gap-2 border-t px-3 py-2 text-xs text-muted-foreground tabular-nums first:border-t-0 ${
              played ? "" : "opacity-50"
            } ${RANK_ROW[i] ?? ""}`}
          >
            <span className={`min-w-0 flex-1 truncate text-sm font-bold ${RANK_NAME[i] ?? "text-foreground"}`}>
              {s.username}
            </span>
            <span className="font-bold text-foreground" title="Day streak">
              🔥 {s.streak}
            </span>
            <span className="font-bold text-sky-500" title="Streak freezes">
              🧊 {s.freezes}
            </span>
            <span className="whitespace-nowrap" title="Average guesses on solved days">
              {s.avg_guesses != null ? `${s.avg_guesses} avg` : "—"}
            </span>
          </div>
        );
      })}
    </div>
  );
}
