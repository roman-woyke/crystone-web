import { redirect } from "next/navigation";

import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { Header } from "@/components/layout/Header";
import { Footer } from "@/components/layout/Footer";

export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await auth();
  if (!session?.user) {
    redirect("/login");
  }

  const me = await prisma.user.findUnique({
    where: { id: Number(session.user.id) },
    select: { username: true, avatar: true },
  });

  return (
    <div className="flex min-h-screen flex-col">
      <Header username={me?.username ?? session.user.name ?? ""} avatar={me?.avatar ?? null} />
      <main className="container mx-auto w-full max-w-[1400px] flex-1 px-4 py-8 sm:px-6">{children}</main>
      <Footer />
    </div>
  );
}
