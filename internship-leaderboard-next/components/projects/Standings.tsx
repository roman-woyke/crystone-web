import Link from "next/link";

import { fmtTime } from "@/lib/study-format";

const RANK_STYLES = [
  "border-amber-400/60 bg-amber-400/5",
  "border-slate-300/60 bg-slate-300/5",
  "border-orange-600/50 bg-orange-600/5",
];

export type StandingRow = { userId: number; username: string; total: number; projectCount: number };

export function Standings({
  standings,
  myUserId,
  viewUserId,
}: {
  standings: StandingRow[];
  myUserId: number;
  viewUserId: number;
}) {
  const podium = standings.slice(0, 3);

  return (
    <section className="space-y-3">
      <div>
        <h2 className="text-lg font-semibold">🏆 Standings</h2>
        <p className="text-sm text-muted-foreground">
          Total time tracked across all projects — tap anyone to open their view.
        </p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {podium.map((s, i) => (
          <Link
            key={s.userId}
            href={`/projects?user=${encodeURIComponent(s.username)}`}
            data-no-tilt
            className={`glow-card block overflow-hidden rounded-xl border-2 p-4 text-center transition-transform hover:-translate-y-1 ${RANK_STYLES[i]} ${
              s.userId === viewUserId ? "ring-2 ring-primary/40" : ""
            }`}
          >
            <div className="text-2xl font-bold">{i + 1}</div>
            <div className="font-semibold">
              {s.username}
              {s.userId === myUserId ? " (you)" : ""}
            </div>
            <div className="text-2xl font-bold">{fmtTime(s.total)}</div>
            <div className="text-xs text-muted-foreground">
              {s.projectCount} project{s.projectCount === 1 ? "" : "s"}
            </div>
          </Link>
        ))}
      </div>
      <p className="text-sm text-muted-foreground">Or pick anyone from the tabs at the top.</p>
    </section>
  );
}
