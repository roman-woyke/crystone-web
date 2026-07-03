import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

export async function DELETE(_request: NextRequest, { params }: RouteContext<"/api/time-entries/[id]">) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const { id } = await params;
  const entryId = Number(id);
  if (!Number.isInteger(entryId)) {
    return new NextResponse("Invalid entry ID.", { status: 400 });
  }

  const result = await prisma.projectTimeEntry.deleteMany({ where: { id: entryId, userId } });

  if (result.count === 0) {
    return new NextResponse("Entry not found.", { status: 404 });
  }

  return new NextResponse("OK");
}
