import Link from "next/link";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { aggregateProjectTime, relativeTime } from "@/lib/projects";
import { fmtTime } from "@/lib/study-format";
import { Card, CardContent } from "@/components/ui/card";
import { AddProjectDialog } from "@/components/projects/AddProjectDialog";
import { ProjectCard, type ProjectData } from "@/components/projects/ProjectCard";
import { Standings, type StandingRow } from "@/components/projects/Standings";

export default async function ProjectsPage({
  searchParams,
}: {
  searchParams: Promise<{ user?: string }>;
}) {
  const session = await auth();
  const myUserId = Number(session!.user.id);

  const users = await prisma.user.findMany({
    select: { id: true, username: true },
    orderBy: { username: "asc" },
  });

  const { user: userParam } = await searchParams;
  const requested = (userParam ?? "").trim();
  const matchedUser = requested
    ? users.find((u) => u.username.toLowerCase() === requested.toLowerCase())
    : undefined;
  const viewUserId = matchedUser?.id ?? myUserId;
  const viewUsername = matchedUser?.username ?? users.find((u) => u.id === myUserId)?.username ?? session!.user.name ?? "";
  const canEdit = viewUserId === myUserId;

  const [projects, allProjects] = await Promise.all([
    prisma.project.findMany({
      where: { userId: viewUserId },
      include: { timeEntries: { orderBy: { loggedAt: "desc" } } },
    }),
    prisma.project.findMany({
      include: {
        user: { select: { username: true } },
        timeEntries: { select: { seconds: true } },
      },
    }),
  ]);

  const withTotals = projects.map((p) => ({ project: p, agg: aggregateProjectTime(p.timeEntries) }));
  withTotals.sort(
    (a, b) =>
      b.agg.totalSeconds - a.agg.totalSeconds ||
      (b.project.createdAt?.getTime() ?? 0) - (a.project.createdAt?.getTime() ?? 0),
  );

  const projectRows: ProjectData[] = withTotals.map(({ project: p, agg }) => ({
    id: p.id,
    name: p.name,
    description: p.description,
    color: p.color,
    ...agg,
    entries: p.timeEntries.map((e) => ({
      id: e.id,
      seconds: e.seconds,
      note: e.note,
      loggedAtLabel: relativeTime(e.loggedAt),
    })),
  }));

  const grand = projectRows.reduce(
    (acc, p) => ({
      total: acc.total + p.totalSeconds,
      today: acc.today + p.todaySeconds,
      week: acc.week + p.weekSeconds,
      entries: acc.entries + p.entryCount,
    }),
    { total: 0, today: 0, week: 0, entries: 0 },
  );

  const userStats = new Map<number, StandingRow>();
  for (const p of allProjects) {
    const total = p.timeEntries.reduce((sum, e) => sum + e.seconds, 0);
    const existing = userStats.get(p.userId) ?? { userId: p.userId, username: p.user.username, total: 0, projectCount: 0 };
    existing.total += total;
    existing.projectCount += 1;
    userStats.set(p.userId, existing);
  }
  const standings = [...userStats.values()].sort((a, b) => b.total - a.total);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          Project <span className="text-primary">Time</span>
        </h1>
        <p className="mt-1 max-w-2xl text-muted-foreground">
          Track the hours you pour into each project. Run a live timer or log time you spent offline,
          jot down what it&apos;s about, and watch your contribution add up.
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {users.map((u) => (
          <Link
            key={u.id}
            href={`/projects?user=${encodeURIComponent(u.username)}`}
            className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${
              u.id === viewUserId
                ? "border-transparent bg-primary text-primary-foreground"
                : "text-muted-foreground hover:border-foreground/30 hover:text-foreground"
            }`}
          >
            {u.username}
            {u.id === myUserId ? " (you)" : ""}
          </Link>
        ))}
      </div>

      <p className="rounded-md border bg-muted/40 px-4 py-2.5 text-sm text-muted-foreground">
        {canEdit ? (
          <>
            Viewing <strong className="text-foreground">your</strong> projects — log time, add projects, and
            edit freely.
          </>
        ) : (
          <>
            Viewing <strong className="text-foreground">{viewUsername}</strong>&apos;s projects.{" "}
            <em>Read only — switch to your own tab to make changes.</em>
          </>
        )}
      </p>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-5">
        {[
          { label: "Total tracked", value: fmtTime(grand.total) },
          { label: "Today", value: fmtTime(grand.today) },
          { label: "Last 7 days", value: fmtTime(grand.week) },
          { label: "Projects", value: String(projectRows.length) },
          { label: "Sessions", value: String(grand.entries) },
        ].map((stat) => (
          <Card key={stat.label}>
            <CardContent className="flex flex-col items-center gap-1 py-4">
              <span className="text-xl font-bold">{stat.value}</span>
              <span className="text-xs text-muted-foreground">{stat.label}</span>
            </CardContent>
          </Card>
        ))}
      </div>

      {canEdit && <AddProjectDialog />}

      {projectRows.length === 0 ? (
        <Card>
          <CardContent className="py-14 text-center text-muted-foreground">
            <span className="mb-2 block text-3xl">🗂️</span>
            {canEdit
              ? "No projects yet — create one above and start logging your hours."
              : `${viewUsername} hasn't created any projects yet.`}
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {projectRows.map((p) => (
            <ProjectCard key={p.id} project={p} canEdit={canEdit} />
          ))}
        </div>
      )}

      {standings.length > 1 && <Standings standings={standings} myUserId={myUserId} viewUserId={viewUserId} />}
    </div>
  );
}
