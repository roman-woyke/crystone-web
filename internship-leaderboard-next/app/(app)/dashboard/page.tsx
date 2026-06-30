import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { scorePoints, peakStatus } from "@/lib/scoring";
import { daysSince, relativeDaysLabel } from "@/lib/dates";
import { AddApplicationDialog } from "@/components/dashboard/AddApplicationDialog";
import { ApplicationsTable, type ApplicationRow } from "@/components/dashboard/ApplicationsTable";
import { Card, CardContent } from "@/components/ui/card";

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
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h1 className="text-2xl font-semibold tracking-tight">My Dashboard</h1>
        <p className="text-muted-foreground">
          Logged in as <strong className="text-foreground">{session!.user.name}</strong>
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
        {[
          { label: "Applications", value: applications.length },
          { label: "Pending", value: pendingCount },
          { label: "Interviews", value: interviewCount },
          { label: "Offers", value: offerCount },
          { label: "My Score", value: myScore },
        ].map((stat) => (
          <Card key={stat.label}>
            <CardContent className="flex flex-col items-center gap-1 py-4">
              <span className="text-2xl font-bold">{stat.value}</span>
              <span className="text-xs text-muted-foreground">{stat.label}</span>
            </CardContent>
          </Card>
        ))}
      </div>

      <AddApplicationDialog />

      <div>
        <h2 className="mb-4 text-lg font-semibold">My applications</h2>
        <ApplicationsTable applications={rows} />
      </div>
    </div>
  );
}
