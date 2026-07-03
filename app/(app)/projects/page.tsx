import { CalendarRange, Clock, FolderKanban, ListChecks, Sun } from "lucide-react";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { aggregateProjectTime, relativeTime } from "@/lib/projects";
import { fmtTime } from "@/lib/study-format";
import { Card, CardContent } from "@/components/ui/card";
import { AddProjectDialog } from "@/components/projects/AddProjectDialog";
import { ProjectCard, type ProjectData } from "@/components/projects/ProjectCard";
import { Standings, type StandingRow } from "@/components/projects/Standings";
import { PageHeader } from "@/components/layout/PageHeader";
import { PillTabs } from "@/components/layout/PillTabs";
import { StatTile } from "@/components/layout/StatTile";
import { InfoBanner } from "@/components/layout/InfoBanner";

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
      <PageHeader
        eyebrow="Side quests"
        title={
          <>
            Project <span className="gradient-text">Time</span>
          </>
        }
        description="Track the hours you pour into each project. Run a live timer or log time you spent offline, and watch your contribution add up."
        actions={canEdit ? <AddProjectDialog /> : undefined}
      />

      <div className="rise rise-1 flex flex-wrap items-center gap-4">
        <PillTabs
          tabs={users.map((u) => ({
            href: `/projects?user=${encodeURIComponent(u.username)}`,
            label: u.id === myUserId ? `${u.username} (you)` : u.username,
            active: u.id === viewUserId,
          }))}
        />
      </div>

      <div className="rise rise-2">
        <InfoBanner>
          {canEdit ? (
            <>
              Viewing <strong className="text-foreground">your</strong> projects — log time, add
              projects, and edit freely.
            </>
          ) : (
            <>
              Viewing <strong className="text-foreground">{viewUsername}</strong>&apos;s projects.{" "}
              <em>Read only — switch to your own tab to make changes.</em>
            </>
          )}
        </InfoBanner>
      </div>

      <div className="rise rise-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <StatTile label="Total tracked" value={fmtTime(grand.total)} icon={<Clock />} accent="#a78bfa" />
        <StatTile label="Today" value={fmtTime(grand.today)} icon={<Sun />} accent="#fbbf24" />
        <StatTile label="Last 7 days" value={fmtTime(grand.week)} icon={<CalendarRange />} accent="#38bdf8" />
        <StatTile label="Projects" value={projectRows.length} icon={<FolderKanban />} accent="#34d399" />
        <StatTile label="Sessions" value={grand.entries} icon={<ListChecks />} accent="#f472b6" />
      </div>

      {projectRows.length === 0 ? (
        <Card className="rise rise-4">
          <CardContent className="flex flex-col items-center gap-3 py-14 text-center text-muted-foreground">
            <span className="flex size-12 items-center justify-center rounded-xl border border-white/10 bg-primary/10 text-primary">
              <FolderKanban className="size-5" />
            </span>
            {canEdit
              ? "No projects yet — create one above and start logging your hours."
              : `${viewUsername} hasn't created any projects yet.`}
          </CardContent>
        </Card>
      ) : (
        <div className="rise rise-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {projectRows.map((p) => (
            <ProjectCard key={p.id} project={p} canEdit={canEdit} />
          ))}
        </div>
      )}

      {standings.length > 1 && <Standings standings={standings} myUserId={myUserId} viewUserId={viewUserId} />}
    </div>
  );
}
