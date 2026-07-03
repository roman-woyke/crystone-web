import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

// Only custom modules (in study_modules) can be deleted. Default modules
// come from exams and aren't stored here. No ownership check beyond being
// logged in — matches the original app (any user can remove any custom
// module; past sessions using it are kept, they just store the name as text).
export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Unauthorized", { status: 401 });
  }

  const body = await request.json();
  const name = String(body.name ?? "").trim();
  if (name === "") {
    return new NextResponse("Module name is required.", { status: 400 });
  }

  await prisma.studyModule.deleteMany({ where: { name } });

  return new NextResponse("OK");
}
