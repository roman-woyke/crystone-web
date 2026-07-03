import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { PROJECT_PALETTE } from "@/lib/project-colors";

export async function PATCH(request: NextRequest, { params }: RouteContext<"/api/projects/[id]">) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const projectId = Number(id);
  if (!Number.isInteger(projectId)) {
    return new NextResponse("Invalid project ID.", { status: 400 });
  }

  const body = await request.json();
  const data: { name?: string; description?: string | null; color?: string } = {};

  if (body.name !== undefined) {
    const name = String(body.name).trim();
    if (name === "") {
      return new NextResponse("Name cannot be empty.", { status: 400 });
    }
    data.name = name.slice(0, 255);
  }

  if (body.description !== undefined) {
    const description = String(body.description).trim();
    data.description = description === "" ? null : description.slice(0, 1000);
  }

  if (body.color !== undefined) {
    if (!(body.color in PROJECT_PALETTE)) {
      return new NextResponse("Invalid color.", { status: 400 });
    }
    data.color = body.color;
  }

  if (Object.keys(data).length === 0) {
    return new NextResponse("Nothing to update.", { status: 400 });
  }

  const result = await prisma.project.updateMany({ where: { id: projectId, userId }, data });

  if (result.count === 0) {
    return new NextResponse("Project not found.", { status: 404 });
  }

  return new NextResponse("OK");
}

export async function DELETE(_request: NextRequest, { params }: RouteContext<"/api/projects/[id]">) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const projectId = Number(id);
  if (!Number.isInteger(projectId)) {
    return new NextResponse("Invalid project ID.", { status: 400 });
  }

  // project_time_entries carries ON DELETE CASCADE from projects, so deleting
  // the project alone is enough.
  const result = await prisma.project.deleteMany({ where: { id: projectId, userId } });

  if (result.count === 0) {
    return new NextResponse("Project not found.", { status: 404 });
  }

  return new NextResponse("OK");
}
