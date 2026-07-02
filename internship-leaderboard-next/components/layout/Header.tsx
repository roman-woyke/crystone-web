import Link from "next/link";

import { signOut } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { AvatarButton } from "@/components/layout/AvatarButton";

const NAV_LINKS = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/leaderboard", label: "Leaderboard" },
  { href: "/study", label: "Study Counter" },
  { href: "/calendar", label: "Calendar" },
  { href: "/projects", label: "Projects" },
  { href: "/fwordle", label: "fWordle" },
];

export function Header({ username, avatar }: { username: string; avatar: string | null }) {
  async function logout() {
    "use server";
    await signOut({ redirectTo: "/login" });
  }

  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-background/60 backdrop-blur-xl">
      <div className="container mx-auto flex max-w-[1400px] items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <div className="flex items-center gap-6">
          <Link href="/dashboard" className="text-lg font-bold tracking-tight">
            Intern<span className="gradient-text">Track</span>
          </Link>
          <nav className="flex items-center gap-1 text-sm text-muted-foreground">
            {NAV_LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="rounded-md px-3 py-1.5 transition-colors hover:bg-white/5 hover:text-foreground"
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-4 text-sm">
          <AvatarButton username={username} avatar={avatar} />
          <span className="text-muted-foreground">{username}</span>
          <form action={logout}>
            <Button type="submit" variant="ghost" size="sm">
              Logout
            </Button>
          </form>
        </div>
      </div>
    </header>
  );
}
