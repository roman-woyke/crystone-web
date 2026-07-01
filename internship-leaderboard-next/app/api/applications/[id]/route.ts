import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { ApplicationStatus } from "@/app/generated/prisma/enums";

const ALLOWED_STATUSES: string[] = Object.values(ApplicationStatus);
const ALLOWED_TAGS = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];

export async function PATCH(
  request: NextRequest,
  { params }: RouteContext<"/api/applications/[id]">,
) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const applicationId = Number(id);
  if (!Number.isInteger(applicationId)) {
    return new NextResponse("Invalid application ID.", { status: 400 });
  }

  const body = await request.json();
  const data: {
    status?: ApplicationStatus;
    tag?: string | null;
    notes?: string | null;
    jobLink?: string | null;
  } = {};

  if (body.status !== undefined) {
    if (!ALLOWED_STATUSES.includes(body.status)) {
      return new NextResponse("Invalid status.", { status: 400 });
    }
    data.status = body.status as ApplicationStatus;
  }

  if (body.tag !== undefined) {
    const tag = body.tag === "" ? null : body.tag;
    if (tag !== null && !ALLOWED_TAGS.includes(tag)) {
      return new NextResponse("Invalid tag.", { status: 400 });
    }
    data.tag = tag;
  }

  if (body.notes !== undefined) {
    const notes = String(body.notes).trim();
    data.notes = notes === "" ? null : notes;
  }

  if (body.job_link !== undefined) {
    const jobLink = String(body.job_link).trim();
    data.jobLink = jobLink === "" ? null : jobLink;
  }

  if (Object.keys(data).length === 0) {
    return new NextResponse("Nothing to update.", { status: 400 });
  }

  const now = dbNow();

  const result = await prisma.application.updateMany({
    where: { id: applicationId, userId },
    data: { ...data, updatedAt: now },
  });

  if (result.count === 0) {
    return new NextResponse("Application not found.", { status: 404 });
  }

  if (data.status !== undefined) {
    await prisma.applicationStatusHistory.create({
      data: { applicationId, userId, status: data.status, changedAt: now },
    });
  }

  return new NextResponse("OK");
}

export async function DELETE(
  _request: NextRequest,
  { params }: RouteContext<"/api/applications/[id]">,
) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const applicationId = Number(id);
  if (!Number.isInteger(applicationId)) {
    return new NextResponse("Invalid application ID.", { status: 400 });
  }

  const owned = await prisma.application.findFirst({
    where: { id: applicationId, userId },
    select: { id: true },
  });

  if (!owned) {
    return new NextResponse("Application not found.", { status: 404 });
  }

  await prisma.$transaction([
    prisma.applicationStatusHistory.deleteMany({
      where: { applicationId, userId },
    }),
    prisma.application.delete({ where: { id: applicationId } }),
  ]);

  return new NextResponse("OK");
}
