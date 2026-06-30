import { NextRequest, NextResponse } from "next/server";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

const MAX_BYTES = 400 * 1024;

function looksLikeImage(buf: Buffer): boolean {
  if (buf.length < 12) return false;
  if (buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) {
    return true; // PNG
  }
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return true; // JPEG
  if (buf.subarray(0, 4).toString("ascii") === "RIFF" && buf.subarray(8, 12).toString("ascii") === "WEBP") {
    return true;
  }
  return false;
}

export async function POST(request: NextRequest) {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  const body = await request.json();
  const avatar = String(body.avatar ?? "");

  const match = avatar.match(/^data:image\/(png|jpeg|jpg|webp);base64,/);
  if (!match) {
    return new NextResponse("Invalid image.", { status: 400 });
  }

  const base64 = avatar.slice(avatar.indexOf(",") + 1);
  let binary: Buffer;
  try {
    binary = Buffer.from(base64, "base64");
  } catch {
    return new NextResponse("Invalid image data.", { status: 400 });
  }

  if (binary.byteLength > MAX_BYTES) {
    return new NextResponse("Image too large.", { status: 413 });
  }

  if (!looksLikeImage(binary)) {
    return new NextResponse("Not a valid image.", { status: 400 });
  }

  await prisma.user.update({ where: { id: userId }, data: { avatar } });

  return NextResponse.json({ ok: true, avatar });
}

export async function DELETE() {
  const session = await auth();
  if (!session?.user?.id) {
    return new NextResponse("Not logged in.", { status: 401 });
  }
  const userId = Number(session.user.id);

  await prisma.user.update({ where: { id: userId }, data: { avatar: null } });

  return NextResponse.json({ ok: true, avatar: null });
}
