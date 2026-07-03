import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { dbNow } from "@/lib/dates";
import { DEFAULT_PROJECT_COLOR, PROJECT_PALETTE } from "@/lib/project-colors";

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const name = String(body.name ?? "").trim();
  const descriptionRaw = String(body.description ?? "").trim();
  const colorRaw = String(body.color ?? "");

  if (name === "") {
    return new NextResponse("Project name is required.", { status: 400 });
  }

  const color = colorRaw in PROJECT_PALETTE ? colorRaw : DEFAULT_PROJECT_COLOR;
  const description = descriptionRaw === "" ? null : descriptionRaw.slice(0, 1000);

  const project = await prisma.project.create({
    data: { userId, name: name.slice(0, 255), description, color, createdAt: dbNow() },
  });

  return NextResponse.json(project, { status: 201 });
}
