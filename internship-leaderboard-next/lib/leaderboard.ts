import { prisma } from "@/lib/prisma";
import { scorePoints, peakStatus } from "@/lib/scoring";
import { ApplicationStatus } from "@/app/generated/prisma/enums";

export type LeaderboardApplication = {
  applicationId: number;
  companyName: string;
  jobTitle: string | null;
  jobLink: string | null;
  location: string | null;
  status: ApplicationStatus;
  notes: string | null;
  tag: string | null;
  createdAt: Date | null;
  updatedAt: Date | null;
  lastStatusChange: Date | null;
  peakStatus: ApplicationStatus | null;
};

export type LeaderboardUser = {
  userId: number;
  username: string;
  totalApplications: number;
  pending: number;
  rejected: number;
  ghosted: number;
  interviews: number;
  offers: number;
  score: number;
  applications: LeaderboardApplication[];
};

export async function getLeaderboard(): Promise<LeaderboardUser[]> {
  const users = await prisma.user.findMany({
    orderBy: { id: "asc" },
    include: {
      applications: {
        orderBy: { createdAt: "desc" },
        include: { history: { orderBy: { changedAt: "desc" } } },
      },
    },
  });

  const result: LeaderboardUser[] = users.map((user) => {
    let pending = 0;
    let rejected = 0;
    let ghosted = 0;
    let interviews = 0;
    let offers = 0;
    let score = 0;

    const applications: LeaderboardApplication[] = [];

    for (const app of user.applications) {
      if (app.status === "PENDING") pending++;
      if (app.status === "REJECTED") rejected++;
      if (app.status === "GHOSTED") ghosted++;

      const peak = peakStatus(app.history);
      if (peak === "INTERVIEW") interviews++;
      if (peak === "OFFER") offers++;

      for (const event of app.history) {
        score += scorePoints(event.status, app.tag);
      }

      applications.push({
        applicationId: app.id,
        companyName: app.companyName,
        jobTitle: app.jobTitle,
        jobLink: app.jobLink,
        location: app.location,
        status: app.status,
        notes: app.notes,
        tag: app.tag,
        createdAt: app.createdAt,
        updatedAt: app.updatedAt,
        lastStatusChange: app.history[0]?.changedAt ?? null,
        peakStatus: peak,
      });
    }

    return {
      userId: user.id,
      username: user.username,
      totalApplications: user.applications.length,
      pending,
      rejected,
      ghosted,
      interviews,
      offers,
      score,
      applications,
    };
  });

  result.sort(
    (a, b) =>
      b.score - a.score ||
      b.offers - a.offers ||
      b.interviews - a.interviews ||
      b.totalApplications - a.totalApplications,
  );

  return result;
}
