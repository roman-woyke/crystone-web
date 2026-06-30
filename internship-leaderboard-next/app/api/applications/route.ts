import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { ApplicationStatus } from "@/app/generated/prisma/enums";

const ALLOWED_TAGS = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];

export async function GET() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }

  const applications = await prisma.application.findMany({
    where: { userId: Number(session.user.id) },
    orderBy: { createdAt: "desc" },
  });

  return NextResponse.json(applications);
}

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const companyName = String(body.company_name ?? "").trim();
  const jobTitle = String(body.job_title ?? "").trim();
  const jobLink = String(body.job_link ?? "").trim();
  const location = String(body.location ?? "").trim();
  const notes = String(body.notes ?? "").trim();
  const tagRaw = String(body.tag ?? "").trim();

  if (companyName === "") {
    return new NextResponse("Company name is required.", { status: 400 });
  }

  const tag = ALLOWED_TAGS.includes(tagRaw) ? tagRaw : null;

  const application = await prisma.$transaction(async (tx) => {
    const created = await tx.application.create({
      data: {
        userId,
        companyName,
        jobTitle: jobTitle || null,
        jobLink: jobLink || null,
        location: location || null,
        notes: notes || null,
        tag,
        status: ApplicationStatus.PENDING,
      },
    });

    await tx.applicationStatusHistory.create({
      data: {
        applicationId: created.id,
        userId,
        status: ApplicationStatus.PENDING,
      },
    });

    return created;
  });

  return NextResponse.json(application, { status: 201 });
}
