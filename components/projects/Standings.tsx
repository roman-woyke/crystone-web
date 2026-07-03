import Link from "next/link";
import { Trophy } from "lucide-react";

import { fmtTime } from "@/lib/study-format";

const MEDAL_DISC = [
  "bg-gradient-to-br from-amber-200 via-amber-400 to-amber-600",
  "bg-gradient-to-br from-slate-100 via-slate-300 to-slate-500",
  "bg-gradient-to-br from-orange-300 via-orange-500 to-orange-800",
];

const MEDAL_RING = ["ring-amber-400/50", "ring-slate-300/40", "ring-orange-500/40"];

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
    <section className="space-y-4">
      <div>
        <h2 className="font-heading flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Trophy className="size-5 text-amber-300" aria-hidden />
          Standings
        </h2>
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
            className={`glow-card block overflow-hidden rounded-2xl border bg-card p-5 text-center ring-1 backdrop-blur-md transition-transform hover:-translate-y-1 ${MEDAL_RING[i]} ${
              s.userId === viewUserId ? "outline-2 outline-primary/50" : ""
            }`}
          >
            <div className="flex flex-col items-center gap-1.5">
              <span
                className={`flex size-9 items-center justify-center rounded-full font-heading text-base font-bold text-black/80 shadow-lg ${MEDAL_DISC[i]}`}
              >
                {i + 1}
              </span>
              <span className="font-heading font-semibold">
                {s.username}
                {s.userId === myUserId ? " (you)" : ""}
              </span>
              <span className="tabular font-heading text-3xl font-bold leading-none">
                {fmtTime(s.total)}
              </span>
              <span className="text-xs text-muted-foreground">
                {s.projectCount} project{s.projectCount === 1 ? "" : "s"}
              </span>
            </div>
          </Link>
        ))}
      </div>
      <p className="text-sm text-muted-foreground">Or pick anyone from the tabs at the top.</p>
    </section>
  );
}
