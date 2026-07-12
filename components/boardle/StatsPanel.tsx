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
        // Frozen/on-fire effects replace the podium tint (both style the same
        // left border + background); the podium name color stays either way.
        const rowTint = s.frozen ? "stat-frozen" : s.on_fire ? "stat-onfire" : (RANK_ROW[i] ?? "");
        return (
          <div
            key={s.username}
            title={
              s.frozen
                ? "Streak frozen — a freeze bridged a missed day"
                : s.on_fire
                  ? "On fire — 7+ day streak without using a freeze"
                  : undefined
            }
            className={`flex items-baseline gap-2 overflow-hidden border-t px-3 py-2 text-xs text-muted-foreground tabular-nums first:border-t-0 ${
              played ? "" : "opacity-50"
            } ${rowTint}`}
          >
            {s.frozen && (
              <span className="fx-ice" aria-hidden="true">
                <i className="fx-ice-crack fx-ice-crack-a" />
                <i className="fx-ice-crack fx-ice-crack-b" />
                {Array.from({ length: 5 }).map((_, m) => (
                  <i key={m} className="fx-frost-mote" />
                ))}
              </span>
            )}
            {s.on_fire && (
              <span className="fx-fire" aria-hidden="true">
                <i className="fx-flame fx-flame-a" />
                <i className="fx-flame fx-flame-b" />
                <i className="fx-flame fx-flame-c" />
                {Array.from({ length: 6 }).map((_, m) => (
                  <i key={m} className="fx-ember" />
                ))}
              </span>
            )}
            <span className={`relative z-10 min-w-0 flex-1 truncate text-sm font-bold ${RANK_NAME[i] ?? "text-foreground"}`}>
              {s.username}
            </span>
            <span className="relative z-10 font-bold text-foreground" title="Day streak">
              🔥 {s.streak}
            </span>
            <span className="relative z-10 font-bold text-sky-500" title="Streak freezes">
              🧊 {s.freezes}
            </span>
            <span className="relative z-10 whitespace-nowrap" title="Average guesses on solved days">
              {s.avg_guesses != null ? `${s.avg_guesses} avg` : "—"}
            </span>
          </div>
        );
      })}
    </div>
  );
}
