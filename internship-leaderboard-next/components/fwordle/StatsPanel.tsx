import type { PlayerStats } from "@/lib/fwordle-streak";

export function StatsPanel({ stats }: { stats: PlayerStats[] }) {
  return (
    <div className="grid grid-cols-2 gap-2">
      {stats.map((s) => {
        const played = s.solves > 0 || s.streak > 0;
        return (
          <div key={s.username} className={`glow-card overflow-hidden rounded-md border bg-card p-2.5 ${played ? "" : "opacity-50"}`}>
            <div className="mb-1 truncate text-sm font-bold">{s.username}</div>
            <div className="flex items-baseline justify-between gap-1.5 text-xs text-muted-foreground tabular-nums">
              <span className="flex items-baseline gap-2">
                <span className="font-bold text-foreground" title="Day streak">
                  🔥 {s.streak}
                </span>
                <span className="font-bold text-sky-500" title="Streak freezes">
                  🧊 {s.freezes}
                </span>
              </span>
              <span title="Average guesses on solved days">{s.avg_guesses != null ? `${s.avg_guesses} avg` : "—"}</span>
            </div>
          </div>
        );
      })}
    </div>
  );
}
