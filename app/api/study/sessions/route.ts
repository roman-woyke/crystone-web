import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow, dbToday, toDbDateStr } from "@/lib/dates";
import { allowedStudyModules } from "@/lib/study-modules";

function parseHM(str: string): { h: number; m: number } | null {
  const match = str.match(/^(\d{1,2}):(\d{2})$/);
  if (!match) return null;
  return { h: Number(match[1]), m: Number(match[2]) };
}

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const moduleInput = String(body.module ?? "").trim();
  const newModule = String(body.new_module ?? "").trim();
  const startTime = String(body.start_time ?? "").trim();
  const endTime = String(body.end_time ?? "").trim();

  let seconds: number;
  let studiedOnStr: string;
  let startedAt: Date | null = null;
  let endedAt: Date | null = null;

  if (startTime !== "" && endTime !== "") {
    // Exact mode: a start & end time for today.
    studiedOnStr = toDbDateStr(dbToday());

    const st = parseHM(startTime);
    const et = parseHM(endTime);
    if (!st || !et) {
      return new NextResponse("Invalid time.", { status: 400 });
    }

    const [y, mo, d] = studiedOnStr.split("-").map(Number);
    const startDT = new Date(Date.UTC(y, mo - 1, d, st.h, st.m, 0));
    const endDT = new Date(Date.UTC(y, mo - 1, d, et.h, et.m, 0));

    seconds = Math.round((endDT.getTime() - startDT.getTime()) / 1000);
    if (seconds <= 0) {
      return new NextResponse("End time must be after the start time.", { status: 400 });
    }
    if (seconds > 86400) {
      return new NextResponse("Invalid duration.", { status: 400 });
    }

    startedAt = startDT;
    endedAt = endDT;
  } else {
    // Duration mode: a length + a date (today or earlier).
    const secondsRaw = Number(body.seconds);
    if (!Number.isInteger(secondsRaw) || secondsRaw <= 0 || secondsRaw > 86400) {
      return new NextResponse("Invalid duration.", { status: 400 });
    }
    seconds = secondsRaw;

    const studiedOnInput = String(body.studied_on ?? "").trim();
    const todayStr = toDbDateStr(dbToday());

    if (studiedOnInput === "") {
      studiedOnStr = todayStr;
    } else {
      const match = studiedOnInput.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!match) {
        return new NextResponse("Invalid date.", { status: 400 });
      }
      const [, yy, mm, dd] = match.map(Number) as unknown as [string, number, number, number];
      const test = new Date(Date.UTC(yy, mm - 1, dd));
      if (test.getUTCFullYear() !== yy || test.getUTCMonth() !== mm - 1 || test.getUTCDate() !== dd) {
        return new NextResponse("Invalid date.", { status: 400 });
      }
      if (studiedOnInput > todayStr) {
        return new NextResponse("Date cannot be in the future.", { status: 400 });
      }
      studiedOnStr = studiedOnInput;
    }
  }

  const { examTitles, all } = await allowedStudyModules();

  let finalModule: string | null = null;
  let isCustom = false;

  if (newModule !== "") {
    if (newModule.length > 255) {
      return new NextResponse("Module name too long.", { status: 400 });
    }
    const existing = all.find((m) => m.toLowerCase() === newModule.toLowerCase());
    if (existing) {
      finalModule = existing;
      isCustom = !examTitles.includes(finalModule);
    } else {
      await prisma.studyModule.create({ data: { name: newModule, createdBy: userId } });
      finalModule = newModule;
      isCustom = true;
    }
  } else {
    const existing = all.find((m) => m.toLowerCase() === moduleInput.toLowerCase());
    if (!existing) {
      return new NextResponse("Unknown module.", { status: 400 });
    }
    finalModule = existing;
    isCustom = !examTitles.includes(finalModule);
  }

  const studiedOn = new Date(`${studiedOnStr}T00:00:00Z`);
  const now = dbNow();

  const created = await prisma.studySession.create({
    data: { userId, moduleName: finalModule, seconds, studiedOn, startedAt, createdAt: now },
  });

  if (startedAt !== null) {
    await prisma.studySegment.create({
      data: { userId, sessionId: created.id, startedAt, endedAt, createdAt: now },
    });
  }

  return NextResponse.json({ ok: true, module: finalModule, custom: isCustom, seconds, date: studiedOnStr });
}
