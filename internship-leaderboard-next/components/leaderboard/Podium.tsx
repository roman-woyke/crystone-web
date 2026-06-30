import { Card, CardContent } from "@/components/ui/card";

type PodiumUser = { username: string; score: number; offers: number; interviews: number };

const RANK_STYLES = [
  "border-amber-400/60 bg-amber-400/5",
  "border-slate-300/60 bg-slate-300/5",
  "border-orange-600/50 bg-orange-600/5",
];

export function Podium({ users }: { users: PodiumUser[] }) {
  if (users.length === 0) return null;

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      {users.slice(0, 3).map((user, i) => (
        <Card key={user.username} className={`border-2 ${RANK_STYLES[i]}`}>
          <CardContent className="flex flex-col items-center gap-1 py-6">
            <span className="text-2xl font-bold">{i + 1}</span>
            <span className="font-semibold">{user.username}</span>
            <span className="text-3xl font-bold">{user.score}</span>
            <span className="text-xs text-muted-foreground">
              {user.offers} offers · {user.interviews} interviews
            </span>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}
