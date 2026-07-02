import { Files, Hourglass, MessagesSquare, BadgeCheck, Sparkles } from "lucide-react";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { scorePoints, peakStatus } from "@/lib/scoring";
import { daysSince, relativeDaysLabel } from "@/lib/dates";
import { AddApplicationDialog } from "@/components/dashboard/AddApplicationDialog";
import { ApplicationsTable, type ApplicationRow } from "@/components/dashboard/ApplicationsTable";
import { PageHeader } from "@/components/layout/PageHeader";
import { StatTile } from "@/components/layout/StatTile";

export default async function DashboardPage() {
  const session = await auth();
  const userId = Number(session!.user.id);

  const applications = await prisma.application.findMany({
    where: { userId },
    include: { history: { orderBy: { changedAt: "desc" } } },
    orderBy: { createdAt: "desc" },
  });

  let pendingCount = 0;
  let interviewCount = 0;
  let offerCount = 0;
  let myScore = 0;

  const rows: ApplicationRow[] = [];

  for (const app of applications) {
    if (app.status === "PENDING") pendingCount++;

    const peak = peakStatus(app.history);
    if (peak === "INTERVIEW") interviewCount++;
    if (peak === "OFFER") offerCount++;

    for (const event of app.history) {
      myScore += scorePoints(event.status, app.tag);
    }

    const lastStatusChange = app.history[0]?.changedAt ?? null;

    rows.push({
      id: app.id,
      companyName: app.companyName,
      jobTitle: app.jobTitle,
      jobLink: app.jobLink,
      location: app.location,
      status: app.status,
      tag: app.tag,
      notes: app.notes,
      peakStatus: peak,
      createdLabel: relativeDaysLabel(daysSince(app.createdAt)),
      lastUpdateLabel: relativeDaysLabel(daysSince(lastStatusChange)),
    });
  }

  return (
    <div className="space-y-8">
      <PageHeader
        eyebrow="Overview"
        title={
          <>
            My <span className="gradient-text">Dashboard</span>
          </>
        }
        description={
          <>
            Logged in as <strong className="text-foreground">{session!.user.name}</strong> — track
            your applications and keep the score climbing.
          </>
        }
        actions={<AddApplicationDialog />}
      />

      <div className="rise rise-1 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <StatTile label="Applications" value={applications.length} icon={<Files />} accent="#a78bfa" />
        <StatTile label="Pending" value={pendingCount} icon={<Hourglass />} accent="#38bdf8" />
        <StatTile label="Interviews" value={interviewCount} icon={<MessagesSquare />} accent="#fbbf24" />
        <StatTile label="Offers" value={offerCount} icon={<BadgeCheck />} accent="#34d399" />
        <StatTile label="My Score" value={myScore} icon={<Sparkles />} accent="#f472b6" />
      </div>

      <div className="rise rise-2 space-y-4">
        <div className="flex items-baseline gap-3">
          <h2 className="font-heading text-xl font-semibold tracking-tight">My applications</h2>
          <span className="rounded-full border border-white/10 bg-white/5 px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">
            {applications.length}
          </span>
        </div>
        <ApplicationsTable applications={rows} />
      </div>
    </div>
  );
}
