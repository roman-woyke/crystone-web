import { prisma } from "@/lib/prisma";
import { dbNow, diffMinutes, diffSeconds } from "@/lib/dates";
import { StudyMode } from "@/app/generated/prisma/enums";

export type StudyPart = {
  type: "module" | "break";
  module: string | null;
  seconds: number;
  live: boolean;
};

export type StudyingEntry = {
  username: string;
  mode: StudyMode;
  module: string | null;
  elapsed: number;
  running: boolean;
  break_elapsed: number;
  at_library: boolean;
  parts: StudyPart[];
};

export type MyStudyStatus = { active: false } | (StudyingEntry & { active: true });

export type RecapBlock = {
  username: string;
  module: string | null;
  start_min: number;
  end_min: number;
  seconds: number;
  live: boolean;
};

export type StudyStatusPayload = {
  me: MyStudyStatus;
  studying: StudyingEntry[];
  recap: RecapBlock[];
  recap_prev: RecapBlock[];
};

// Fold an interruption shorter than `minSecs` into the run before it instead
// of letting it fragment — or silently drop time from — what's really one
// continuous stretch: a short break taken mid-module (or a short module
// check taken mid-break) doesn't end that run, it's absorbed into it.
//
// `items` is chronological. Consecutive items of the same type (and, for
// modules, the same name) always merge into one run regardless of how short
// either side is — merging never depends on the *incoming* item's own size,
// only on whether it matches the run it would extend, which is what makes a
// previously-live run that's just closed (and turned out short on its own)
// keep counting as part of the bigger run it was folded into, rather than
// resetting. An item that doesn't match the run before it (or has nothing
// before it, e.g. a short module tap that opens the session before the first
// break) only survives on its own if it's substantial or still live —
// otherwise it's remembered as a pending gap and backdated onto whichever
// run comes *next*, of any type, so its span still ends up counted somewhere
// instead of vanishing between two runs it didn't match either side of.
type RunItem = { type: "module" | "break"; module: string | null; start: Date; end: Date | null };
type MergedRun = RunItem & { live: boolean; seconds: number };

function studyMergeRuns(items: RunItem[], now: Date, minSecs = 300): MergedRun[] {
  const out: (RunItem & { live: boolean })[] = [];
  // Start of a filler run that hasn't found a home yet — e.g. a session
  // that opens with a 2-minute module tap before the first break has
  // nothing of matching type before it to extend, so on its own it would
  // just vanish, taking those 2 minutes with it. Instead we remember where
  // it began and backdate the *next* accepted run (of any type) to start
  // there too, so its span still ends up counted somewhere.
  let pendingStart: Date | null = null;

  for (const it of items) {
    const isLive = it.end === null;
    const secs = diffSeconds(it.start, it.end ?? now);

    const last = out[out.length - 1];
    const sameRun =
      last !== undefined &&
      !last.live &&
      ((last.type === "module" && it.type === "module" && last.module === it.module) ||
        (last.type === "break" && it.type === "break"));

    if (sameRun) {
      last.end = it.end;
      last.live = isLive;
      pendingStart = null;
      continue;
    }

    if (isLive || secs >= minSecs) {
      const accepted = { ...it, live: isLive };
      if (pendingStart !== null) {
        accepted.start = pendingStart;
        pendingStart = null;
      }
      out.push(accepted);
    } else if (pendingStart === null) {
      pendingStart = it.start;
    }
  }

  return out.map((r) => ({ ...r, seconds: diffSeconds(r.start, r.end ?? now) }));
}

export async function studyStatusPayload(userId: number): Promise<StudyStatusPayload> {
  const rows = await prisma.studyStatus.findMany({
    include: { user: { select: { id: true, username: true } } },
    orderBy: { user: { username: "asc" } },
  });

  const now = dbNow();
  const studyingByUser = new Map<number, StudyingEntry>();
  let me: MyStudyStatus = { active: false };

  for (const r of rows) {
    const running = r.startedAt !== null;
    const elapsed = r.accumulated + (running ? diffSeconds(r.startedAt as Date, now) : 0);
    const breakElapsed = running ? 0 : diffSeconds(r.updatedAt ?? now, now);

    const entry: StudyingEntry = {
      username: r.user.username,
      mode: r.mode,
      module: r.moduleName,
      elapsed,
      running,
      break_elapsed: breakElapsed,
      at_library: r.atLibrary,
      parts: [],
    };
    studyingByUser.set(r.userId, entry);

    if (r.userId === userId) {
      me = { active: true, ...entry };
    }
  }

  // `parts` — every active user's in-progress session broken down into its
  // full chronological history: one entry per module run *and* one per
  // break in between, in the order they actually happened. A module
  // switched away from and back to (X -> Y -> X) shows as three separate
  // lines rather than collapsing X's two intervals into one — and a break
  // taken mid-session shows as its own "Break" line instead of vanishing.
  // Interruptions under 5 minutes are folded into the run they interrupted
  // via studyMergeRuns() (see there for why), so a brief break doesn't
  // fragment a module's line, and vice versa — the stop checklist reads
  // `me.parts` directly, so this is also what ends up logged. A null
  // module is the time studied before any module was assigned (not
  // loggable).
  if (studyingByUser.size) {
    // Pre-assignment intervals (module_name NULL) fall back to that user's
    // session's current module, so they don't show up as orphaned time.
    const segments = await prisma.studySegment.findMany({
      where: { sessionId: null },
      orderBy: [{ userId: "asc" }, { startedAt: "asc" }],
    });

    const segsByUser = new Map<number, typeof segments>();
    for (const seg of segments) {
      const arr = segsByUser.get(seg.userId) ?? [];
      arr.push(seg);
      segsByUser.set(seg.userId, arr);
    }

    for (const [uid, segs] of segsByUser) {
      const owner = studyingByUser.get(uid);
      if (!owner) continue;

      const items: RunItem[] = [];
      let prevEnd: Date | null = null; // ended_at of the previous closed segment, for gap detection

      for (const seg of segs) {
        if (prevEnd !== null && seg.startedAt > prevEnd) {
          items.push({ type: "break", module: null, start: prevEnd, end: seg.startedAt });
        }
        const isLive = seg.endedAt === null;
        items.push({ type: "module", module: seg.moduleName ?? owner.module, start: seg.startedAt, end: seg.endedAt });
        prevEnd = isLive ? null : seg.endedAt;
      }

      // A break currently in progress (paused, not yet resumed) has no
      // closing segment to anchor it — it runs from the last close to now.
      if (prevEnd !== null && !owner.running) {
        items.push({ type: "break", module: null, start: prevEnd, end: null });
      }

      const parts: StudyPart[] = studyMergeRuns(items, now).map((r) => ({
        type: r.type,
        module: r.module,
        seconds: r.seconds,
        live: r.live,
      }));
      owner.parts = parts;

      // The top-level "elapsed" (the "Session · total" header above the
      // breakdown, and the focus-mode clock) must agree with the sum of
      // the module lines actually listed below it — otherwise a short
      // break folded into a module's line (see studyMergeRuns()) makes
      // that line's own number bigger than the DB's raw accumulated
      // running-clock value, which never counted the folded break as
      // study time. Recomputing from the merged parts keeps this in
      // sync with what stop/log actually stores (it folds the same
      // short gaps into the same module totals).
      const sessionSecs = parts.reduce((sum, p) => sum + (p.type === "module" ? p.seconds : 0), 0);
      owner.elapsed = sessionSecs;
      if (me.active && uid === userId) me.elapsed = sessionSecs;

      // Same reasoning for break_elapsed (used outside the breakdown,
      // e.g. the dock's simple "on break" state): it should agree with
      // the merged live break line rather than only counting time
      // since the *last* pause — otherwise a short module tap
      // mid-break would make this number jump backward even though
      // the breakdown correctly kept counting through it.
      const liveBreak = parts[parts.length - 1];
      if (liveBreak && liveBreak.type === "break" && liveBreak.live) {
        owner.break_elapsed = liveBreak.seconds;
        if (me.active && uid === userId) me.break_elapsed = liveBreak.seconds;
      }
    }

    if (me.active) {
      me.parts = studyingByUser.get(userId)?.parts ?? [];
    }
  }

  return {
    me,
    studying: [...studyingByUser.values()],
    recap: await studyRecap(0),
    recap_prev: await studyRecap(1),
  };
}

// The study day runs 07:00 -> 07:00 the next morning; before 07:00 we're
// still on yesterday's study day. Returns that day's midnight (Berlin
// wall-clock, encoded the same way as every other DB-derived Date in this
// app), shifted back `daysBack` further days.
function studyDayBase(daysBack: number, now: Date): Date {
  const base = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  if (now.getUTCHours() < 7) {
    base.setUTCDate(base.getUTCDate() - 1);
  }
  base.setUTCDate(base.getUTCDate() - daysBack);
  return base;
}

// Build one study day's recap timeline, `daysBack` study days before the
// current one (0 = today's study day, 1 = the previous one):
//   1) logged segments (the real intervals of finished sessions)
//   2) sessions with no segments (manual entries / pre-migration) -> one block
//   3) live segments of an in-progress session (only on the current study
//      day, since they're happening right now)
async function studyRecap(daysBack = 0): Promise<RecapBlock[]> {
  const now = dbNow();
  const base = studyDayBase(daysBack, now);
  const winStart = new Date(base.getTime() + 7 * 3_600_000);
  const winEnd = new Date(base.getTime() + 31 * 3_600_000);

  type Row = { username: string; module: string | null; start_min: number; end_min: number; seconds: number; live: boolean; sortAt: Date };
  const rows: Row[] = [];

  const loggedSegments = await prisma.studySegment.findMany({
    where: {
      startedAt: { gte: winStart, lt: winEnd },
      endedAt: { not: null },
      sessionId: { not: null },
    },
    include: {
      user: { select: { username: true } },
      session: { select: { moduleName: true } },
    },
  });
  for (const seg of loggedSegments) {
    rows.push({
      username: seg.user.username,
      module: seg.session?.moduleName ?? null,
      start_min: diffMinutes(base, seg.startedAt),
      end_min: diffMinutes(base, seg.endedAt as Date),
      seconds: diffSeconds(seg.startedAt, seg.endedAt as Date),
      live: false,
      sortAt: seg.startedAt,
    });
  }

  const manualSessions = await prisma.studySession.findMany({
    where: { studiedOn: base, segments: { none: {} } },
    include: { user: { select: { username: true } } },
  });
  for (const s of manualSessions) {
    const start = s.startedAt ?? new Date((s.createdAt ?? now).getTime() - s.seconds * 1000);
    rows.push({
      username: s.user.username,
      module: s.moduleName,
      start_min: diffMinutes(base, start),
      end_min: diffMinutes(base, s.createdAt ?? now),
      seconds: s.seconds,
      live: false,
      sortAt: start,
    });
  }

  if (daysBack === 0) {
    const liveSegments = await prisma.studySegment.findMany({
      where: { sessionId: null },
      include: {
        user: { select: { username: true, studyStatus: { select: { moduleName: true } } } },
      },
    });
    for (const seg of liveSegments) {
      const end = seg.endedAt ?? now;
      rows.push({
        username: seg.user.username,
        module: seg.moduleName ?? seg.user.studyStatus?.moduleName ?? null,
        start_min: diffMinutes(base, seg.startedAt),
        end_min: diffMinutes(base, end),
        seconds: diffSeconds(seg.startedAt, end),
        live: seg.endedAt === null,
        sortAt: seg.startedAt,
      });
    }
  }

  rows.sort((a, b) => a.sortAt.getTime() - b.sortAt.getTime());

  return studyMergeRecapBlocks(
    rows.map(({ username, module, start_min, end_min, seconds, live }) => ({
      username,
      module,
      start_min,
      end_min,
      seconds,
      live,
    })),
  );
}

// Companion to studyMergeRuns() for the recap timeline, which only ever draws
// module blocks (breaks are just the whitespace between them, nothing to
// merge on their own). Two consecutive blocks of the *same* module for the
// *same* user, separated by a gap under 5 minutes, are joined into one —
// same reasoning as studyMergeRuns(): a short break shouldn't visually
// fragment (or drop the time from) what's really one continuous stretch.
function studyMergeRecapBlocks(recap: RecapBlock[], minGapMin = 5): RecapBlock[] {
  const byUser = new Map<string, RecapBlock[]>();
  for (const r of recap) {
    const arr = byUser.get(r.username) ?? [];
    arr.push(r);
    byUser.set(r.username, arr);
  }

  const out: RecapBlock[] = [];
  for (const blocks of byUser.values()) {
    const merged: RecapBlock[] = [];
    for (const r of blocks) {
      const last = merged[merged.length - 1];
      if (
        last !== undefined &&
        !last.live &&
        last.module !== null &&
        last.module === r.module &&
        r.start_min - last.end_min < minGapMin
      ) {
        last.seconds += (r.start_min - last.end_min) * 60 + r.seconds;
        last.end_min = r.end_min;
        last.live = r.live;
      } else {
        merged.push({ ...r });
      }
    }
    out.push(...merged);
  }

  return out;
}
