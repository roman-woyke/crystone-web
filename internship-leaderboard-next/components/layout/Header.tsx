import Link from "next/link";
import { LogOut, Trophy } from "lucide-react";

import { signOut } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { AvatarButton } from "@/components/layout/AvatarButton";
import { MainNav } from "@/components/layout/MainNav";

export function Header({ username, avatar }: { username: string; avatar: string | null }) {
  async function logout() {
    "use server";
    await signOut({ redirectTo: "/login" });
  }

  return (
    <header className="sticky top-0 z-40 bg-background/55 backdrop-blur-xl">
      <div className="container relative mx-auto flex max-w-[1400px] items-center justify-between gap-4 px-4 py-3.5 sm:px-6">
        <div className="flex items-center gap-6">
          <Link href="/dashboard" className="group flex items-center gap-2.5">
            <span className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500 shadow-[0_4px_14px_-4px_rgba(139,92,246,0.7)] transition-transform group-hover:scale-105">
              <Trophy className="size-4 text-white" />
            </span>
            <span className="font-heading text-lg font-bold tracking-tight">
              Intern<span className="gradient-text">Track</span>
            </span>
          </Link>
          <MainNav />
        </div>
        <div className="flex items-center gap-3 text-sm">
          <AvatarButton username={username} avatar={avatar} />
          <span className="hidden font-medium text-muted-foreground sm:inline">{username}</span>
          <form action={logout}>
            <Button
              type="submit"
              variant="ghost"
              size="sm"
              className="gap-1.5 text-muted-foreground hover:text-foreground"
            >
              <LogOut data-icon="inline-start" />
              <span className="hidden sm:inline">Logout</span>
            </Button>
          </form>
        </div>
      </div>
      <div className="hairline" />
    </header>
  );
}
