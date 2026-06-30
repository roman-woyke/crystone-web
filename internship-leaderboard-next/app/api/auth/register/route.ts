import { NextRequest, NextResponse } from "next/server";
import bcrypt from "bcryptjs";

import { prisma } from "@/lib/prisma";

export async function POST(request: NextRequest) {
  const body = await request.json();
  const username = String(body.username ?? "").trim();
  const password = String(body.password ?? "");
  const inviteCode = String(body.invite_code ?? "").trim();

  if (username === "" || password === "") {
    return new NextResponse("Username and password are required.", { status: 400 });
  }

  if (inviteCode !== process.env.INVITE_CODE) {
    return new NextResponse("Invalid invite code.", { status: 400 });
  }

  const passwordHash = await bcrypt.hash(password, 10);

  try {
    const user = await prisma.user.create({
      data: { username, passwordHash },
    });
    return NextResponse.json({ id: user.id, username: user.username }, { status: 201 });
  } catch (error: unknown) {
    if (error instanceof Object && "code" in error && error.code === "P2002") {
      return new NextResponse("Username already exists.", { status: 409 });
    }
    return new NextResponse("Registration failed.", { status: 500 });
  }
}
