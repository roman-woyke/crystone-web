import { Crown } from "lucide-react";

type PodiumUser = { username: string; score: number; offers: number; interviews: number };

const MEDALS = [
  {
    disc: "bg-gradient-to-br from-amber-200 via-amber-400 to-amber-600",
    ring: "ring-amber-400/50",
    glow: "shadow-[0_0_40px_-8px_rgba(251,191,36,0.45)]",
    label: "text-amber-300",
  },
  {
    disc: "bg-gradient-to-br from-slate-100 via-slate-300 to-slate-500",
    ring: "ring-slate-300/40",
    glow: "",
    label: "text-slate-300",
  },
  {
    disc: "bg-gradient-to-br from-orange-300 via-orange-500 to-orange-800",
    ring: "ring-orange-500/40",
    glow: "",
    label: "text-orange-400",
  },
];

// Podium order on desktop: 2nd — 1st (elevated) — 3rd.
const ORDER_CLASS = ["sm:order-2", "sm:order-1", "sm:order-3"];

export function Podium({ users }: { users: PodiumUser[] }) {
  if (users.length === 0) return null;

  return (
    <div className="grid grid-cols-1 items-end gap-4 sm:grid-cols-3">
      {users.slice(0, 3).map((user, i) => {
        const medal = MEDALS[i];
        const first = i === 0;
        return (
          <div
            key={user.username}
            className={`glow-card relative overflow-hidden rounded-2xl border bg-card text-center ring-1 backdrop-blur-md ${medal.ring} ${medal.glow} ${ORDER_CLASS[i]} ${
              first ? "halo px-6 py-8 sm:py-10" : "px-6 py-6"
            }`}
          >
            {first && (
              <Crown
                aria-hidden
                className="absolute top-3 right-3 size-5 rotate-12 text-amber-300/80"
              />
            )}
            <div className="flex flex-col items-center gap-2">
              <span
                className={`flex items-center justify-center rounded-full font-heading font-bold text-black/80 shadow-lg ${medal.disc} ${
                  first ? "size-12 text-xl" : "size-10 text-lg"
                }`}
              >
                {i + 1}
              </span>
              <span className={`font-heading font-semibold ${first ? "text-lg" : ""}`}>
                {user.username}
              </span>
              <span
                className={`tabular font-heading font-bold leading-none ${
                  first ? "gradient-text text-5xl" : "text-4xl"
                }`}
              >
                {user.score}
              </span>
              <span className="text-xs text-muted-foreground">
                {user.offers} offers · {user.interviews} interviews
              </span>
            </div>
          </div>
        );
      })}
    </div>
  );
}
