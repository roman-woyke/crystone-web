import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow, dbToday, diffSeconds } from "@/lib/dates";
import { resolveStudyModule } from "@/lib/study-modules";
import { segOpen, segClose, segClearLive } from "@/lib/study-segments";
import { studyStatusPayload } from "@/lib/study-status";

type LogRequestSession = { module?: unknown; seconds?: unknown };

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const action = String(body.action ?? "");

  const row = await prisma.studyStatus.findUnique({ where: { userId } });

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const extra: Record<string, any> = {};

  if (action === "start") {
    if (row && row.mode === "timer") {
      if (row.startedAt === null) {
        const now = dbNow();
        await prisma.studyStatus.update({ where: { userId }, data: { startedAt: now, updatedAt: now } });
        await segOpen(userId, row.moduleName);
      }
    } else {
      const moduleInput = String(body.module ?? "").trim();
      const newModule = String(body.new_module ?? "").trim();
      const { resolved, customAdded } = await resolveStudyModule(userId, moduleInput, newModule);
      if (resolved === null) {
        return new NextResponse("Pick a module first.", { status: 400 });
      }

      const now = dbNow();
      await prisma.studyStatus.upsert({
        where: { userId },
        create: {
          userId,
          mode: "timer",
          moduleName: resolved,
          startedAt: now,
          accumulated: 0,
          sessionStart: now,
          updatedAt: now,
        },
        update: {
          mode: "timer",
          moduleName: resolved,
          startedAt: now,
          accumulated: 0,
          sessionStart: now,
          updatedAt: now,
        },
      });
      await segClearLive(userId);
      await segOpen(userId, resolved);
      extra.custom_added = customAdded;
    }
  } else if (action === "presence") {
    if (!row) {
      const moduleInput = String(body.module ?? "").trim();
      const newModule = String(body.new_module ?? "").trim();
      let resolved: string | null = null;
      let customAdded = false;
      if (moduleInput !== "" || newModule !== "") {
        ({ resolved, customAdded } = await resolveStudyModule(userId, moduleInput, newModule));
      }
      const now = dbNow();
      await prisma.studyStatus.create({
        data: {
          userId,
          mode: "presence",
          moduleName: resolved,
          startedAt: now,
          accumulated: 0,
          sessionStart: now,
          updatedAt: now,
        },
      });
      await segClearLive(userId);
      await segOpen(userId, resolved);
      extra.custom_added = customAdded;
    } else if (row.startedAt === null) {
      const now = dbNow();
      await prisma.studyStatus.update({ where: { userId }, data: { startedAt: now, updatedAt: now } });
      await segOpen(userId, row.moduleName);
    }
  } else if (action === "resume") {
    if (row && row.startedAt === null) {
      const now = dbNow();
      await prisma.studyStatus.update({ where: { userId }, data: { startedAt: now, updatedAt: now } });
      await segOpen(userId, row.moduleName);
    }
  } else if (action === "set_module") {
    if (!row) {
      return new NextResponse("Not studying.", { status: 400 });
    }
    const moduleInput = String(body.module ?? "").trim();
    const newModule = String(body.new_module ?? "").trim();
    const { resolved, customAdded } = await resolveStudyModule(userId, moduleInput, newModule);
    if (resolved === null) {
      return new NextResponse("Pick a module first.", { status: 400 });
    }

    const open = await prisma.studySegment.findFirst({
      where: { userId, sessionId: null, endedAt: null },
      orderBy: { id: "desc" },
    });

    await prisma.studyStatus.update({ where: { userId }, data: { moduleName: resolved, updatedAt: dbNow() } });

    if (open) {
      if (open.moduleName === null) {
        await prisma.studySegment.updateMany({
          where: { userId, sessionId: null, moduleName: null },
          data: { moduleName: resolved },
        });
      } else if (open.moduleName.toLowerCase() !== resolved.toLowerCase()) {
        await segClose(userId);
        await segOpen(userId, resolved);
      }
      // Same module re-selected: nothing to do.
    } else if (row.startedAt === null) {
      // Paused — picking a module means "I'm back to studying it", so end
      // the break and open a fresh interval under the new module instead
      // of silently queuing it for whenever resume is next clicked.
      await prisma.studyStatus.update({ where: { userId }, data: { startedAt: dbNow() } });
      await segOpen(userId, resolved);
    }

    extra.custom_added = customAdded;
  } else if (action === "pause") {
    if (row && row.startedAt !== null) {
      const now = dbNow();
      const accumulated = row.accumulated + diffSeconds(row.startedAt, now);
      await prisma.studyStatus.update({
        where: { userId },
        data: { accumulated, startedAt: null, updatedAt: now },
      });
      await segClose(userId);
    }
  } else if (action === "library") {
    if (row) {
      await prisma.studyStatus.update({
        where: { userId },
        data: { atLibrary: !row.atLibrary, updatedAt: row.updatedAt },
      });
    }
  } else if (action === "reset" || action === "stop") {
    await segClearLive(userId);
    await prisma.studyStatus.deleteMany({ where: { userId } });
  } else if (action === "log") {
    extra.logged = false;
    extra.count = 0;
    extra.seconds = 0;
    extra.modules = [];

    if (row) {
      await segClose(userId);

      const reqList: LogRequestSession[] = Array.isArray(body.sessions) ? body.sessions : [];
      // module (lower-cased) -> requested seconds (0/absent = keep full).
      // A module can appear as more than one row here — the breakdown shows
      // separate lines for runs split by a real break — and since byMod
      // below sums *all* of that module's segments regardless of gaps, the
      // requested amounts must be summed too. Overwriting instead of summing
      // (the previous bug) let the last row silently clobber the others'
      // requested seconds, so keeping every row at its full amount could
      // still read as "only keep the last run's seconds" and trim — or
      // entirely delete — the earlier ones.
      const reqByMod = new Map<string, number>();
      for (const s of reqList) {
        if (typeof s !== "object" || s === null) continue;
        const m = String(s.module ?? "").trim();
        if (m === "") continue;
        const key = m.toLowerCase();
        reqByMod.set(key, (reqByMod.get(key) ?? 0) + (Number(s.seconds ?? 0) | 0));
      }

      const segments = await prisma.studySegment.findMany({
        where: { userId, sessionId: null, endedAt: { not: null } },
        orderBy: [{ moduleName: "asc" }, { startedAt: "asc" }],
      });

      type Group = {
        module: string;
        total: number;
        segs: { id: number; startedAt: Date; endedAt: Date; dur: number }[];
      };
      const byMod = new Map<string, Group>();
      for (const seg of segments) {
        if (seg.moduleName === null) continue;
        const dur = diffSeconds(seg.startedAt, seg.endedAt as Date);
        const key = seg.moduleName.toLowerCase();
        const g = byMod.get(key) ?? { module: seg.moduleName, total: 0, segs: [] };
        g.total += dur;
        g.segs.push({ id: seg.id, startedAt: seg.startedAt, endedAt: seg.endedAt as Date, dur });
        byMod.set(key, g);
      }

      // Fold short (< 5 min) gaps between chronologically-adjacent segments
      // of the same module into that module's total — a brief break
      // shouldn't cost the module time it would have kept counting through,
      // matching what the live breakdown already showed before Stop was
      // clicked (see studyMergeRuns() in lib/study-status.ts).
      const chrono = [...segments].sort((a, b) => a.startedAt.getTime() - b.startedAt.getTime());
      for (let i = 1; i < chrono.length; i++) {
        const prev = chrono[i - 1];
        const cur = chrono[i];
        if (prev.moduleName === null || prev.moduleName !== cur.moduleName) continue;
        const gap = diffSeconds(prev.endedAt as Date, cur.startedAt);
        if (gap > 0 && gap < 300) {
          const key = prev.moduleName.toLowerCase();
          const g = byMod.get(key);
          if (g) g.total += gap;
        }
      }

      let loggedSeconds = 0;
      let loggedCount = 0;
      const loggedModules: string[] = [];

      for (const [key, g] of byMod) {
        if (!reqByMod.has(key)) continue;

        const actual = g.total;
        const req = reqByMod.get(key)!;
        const target = req <= 0 ? actual : Math.max(1, Math.min(req, 86400));
        if (target <= 0) continue;

        const segs = g.segs;
        if (target < actual) {
          let toRemove = actual - target;
          for (let i = segs.length - 1; i >= 0 && toRemove > 0; i--) {
            const dur = segs[i].dur;
            if (dur <= toRemove) {
              await prisma.studySegment.delete({ where: { id: segs[i].id } });
              toRemove -= dur;
            } else {
              const keep = dur - toRemove;
              const newEnd = new Date(segs[i].startedAt.getTime() + keep * 1000);
              await prisma.studySegment.update({ where: { id: segs[i].id }, data: { endedAt: newEnd } });
              toRemove = 0;
            }
          }
        } else if (target > actual && segs.length > 0) {
          const last = segs[segs.length - 1];
          const newDur = last.dur + (target - actual);
          const newEnd = new Date(last.startedAt.getTime() + newDur * 1000);
          await prisma.studySegment.update({ where: { id: last.id }, data: { endedAt: newEnd } });
        }

        const startAtAgg = await prisma.studySegment.aggregate({
          where: { userId, sessionId: null, moduleName: g.module, endedAt: { not: null } },
          _min: { startedAt: true },
        });
        const startAt = startAtAgg._min.startedAt ?? row.sessionStart ?? dbNow();

        const created = await prisma.studySession.create({
          data: {
            userId,
            moduleName: g.module,
            seconds: target,
            studiedOn: dbToday(),
            startedAt: startAt,
            atLibrary: row.atLibrary,
            createdAt: dbNow(),
          },
        });

        await prisma.studySegment.updateMany({
          where: { userId, sessionId: null, moduleName: g.module },
          data: { sessionId: created.id },
        });

        loggedSeconds += target;
        loggedCount++;
        loggedModules.push(g.module);
      }

      await segClearLive(userId);
      await prisma.studyStatus.deleteMany({ where: { userId } });

      extra.logged = loggedCount > 0;
      extra.count = loggedCount;
      extra.seconds = loggedSeconds;
      extra.modules = loggedModules;
    }
  } else {
    return new NextResponse("Invalid action.", { status: 400 });
  }

  const payload = await studyStatusPayload(userId);
  return NextResponse.json({ ...extra, ...payload });
}
