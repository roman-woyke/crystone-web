import Link from "next/link";

import { signOut } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { AvatarButton } from "@/components/layout/AvatarButton";

const NAV_LINKS = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/leaderboard", label: "Leaderboard" },
  { href: "/study", label: "Study Counter" },
];

export function Header({ username, avatar }: { username: string; avatar: string | null }) {
  async function logout() {
    "use server";
    await signOut({ redirectTo: "/login" });
  }

  return (
    <header className="border-b">
      <div className="container mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
        <div className="flex items-center gap-6">
          <Link href="/dashboard" className="font-semibold tracking-tight">
            InternTrack
          </Link>
          <nav className="flex items-center gap-4 text-sm text-muted-foreground">
            {NAV_LINKS.map((link) => (
              <Link key={link.href} href={link.href} className="hover:text-foreground">
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
