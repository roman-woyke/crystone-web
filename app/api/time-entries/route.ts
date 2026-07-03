import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const projectId = Number(body.project_id);
  let seconds = Number(body.seconds);
  const note = String(body.note ?? "").trim();

  if (!Number.isInteger(projectId) || projectId <= 0) {
    return new NextResponse("Missing project ID.", { status: 400 });
  }
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return new NextResponse("Duration must be greater than zero.", { status: 400 });
  }
  // Cap absurd values (e.g. a forgotten running timer) at 24h.
  seconds = Math.min(Math.floor(seconds), 86400);

  const owned = await prisma.project.findFirst({ where: { id: projectId, userId }, select: { id: true } });
  if (!owned) {
    return new NextResponse("Project not found.", { status: 404 });
  }

  const entry = await prisma.projectTimeEntry.create({
    data: { projectId, userId, seconds, note: note === "" ? null : note.slice(0, 255), loggedAt: dbNow() },
  });

  return NextResponse.json(entry, { status: 201 });
}
