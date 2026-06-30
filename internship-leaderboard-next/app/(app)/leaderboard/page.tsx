import { getLeaderboard } from "@/lib/leaderboard";
import { daysSince, relativeDaysLabel } from "@/lib/dates";
import { Podium } from "@/components/leaderboard/Podium";
import { LeaderboardTable, type LeaderboardRow } from "@/components/leaderboard/LeaderboardTable";
import { ScoreChart } from "@/components/leaderboard/ScoreChart";

function lastUpdateColor(
  days: number | null,
  peakStatus: string | null,
  status: string,
): "neutral" | "green" | "yellow" | "orange" | "red" {
  if (days === null) return "neutral";
  if (peakStatus === "INTERVIEW" || peakStatus === "OFFER" || status === "REJECTED") return "neutral";
  if (days <= 7) return "green";
  if (days <= 14) return "yellow";
  if (days <= 30) return "orange";
  return "red";
}

export default async function LeaderboardPage() {
  const leaderboard = await getLeaderboard();

  const rows: LeaderboardRow[] = leaderboard.map((user) => ({
    userId: user.userId,
    username: user.username,
    totalApplications: user.totalApplications,
    pending: user.pending,
    rejected: user.rejected,
    ghosted: user.ghosted,
    interviews: user.interviews,
    offers: user.offers,
    score: user.score,
    applications: user.applications.map((app) => {
      const lastChange = app.lastStatusChange ?? app.updatedAt;
      const days = daysSince(lastChange);

      return {
        applicationId: app.applicationId,
        companyName: app.companyName,
        jobTitle: app.jobTitle,
        jobLink: app.jobLink,
        location: app.location,
        status: app.status,
        peakStatus: app.peakStatus,
        tag: app.tag,
        notes: app.notes,
        createdLabel: relativeDaysLabel(daysSince(app.createdAt)),
        lastUpdateLabel: relativeDaysLabel(days),
        lastUpdateColor: lastUpdateColor(days, app.peakStatus, app.status),
      };
    }),
  }));

  return (
    <div className="space-y-8">
      <h1 className="text-2xl font-semibold tracking-tight">Internship Application Leaderboard</h1>

      <Podium users={rows} />

      <LeaderboardTable users={rows} />

      <ScoreChart />
    </div>
  );
}
