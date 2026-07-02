import Link from "next/link";
import { Trophy } from "lucide-react";

const FOOTER_LINKS = [
  { href: "/dashboard", label: "Dashboard" },
  { href: "/leaderboard", label: "Leaderboard" },
  { href: "/study", label: "Study Counter" },
  { href: "/calendar", label: "Calendar" },
  { href: "/projects", label: "Projects" },
  { href: "/fwordle", label: "fWordle" },
];

export function Footer() {
  return (
    <footer className="mt-12 bg-background/40 backdrop-blur-xl">
      <div className="hairline" />
      <div className="container mx-auto flex max-w-[1400px] flex-col gap-6 px-4 py-8 sm:px-6 md:flex-row md:items-start md:justify-between">
        <div className="space-y-2">
          <div className="flex items-center gap-2">
            <span className="flex size-6 items-center justify-center rounded-md bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500">
              <Trophy className="size-3 text-white" />
            </span>
            <span className="font-heading font-bold tracking-tight">
              Intern<span className="gradient-text">Track</span>
            </span>
          </div>
          <p className="max-w-xs text-sm text-muted-foreground">
            Every application scores points. Study, ship, apply — and stay on top of the board.
          </p>
        </div>

        <nav className="grid grid-cols-2 gap-x-10 gap-y-2 text-sm sm:grid-cols-3">
          {FOOTER_LINKS.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="text-muted-foreground transition-colors hover:text-foreground"
            >
              {link.label}
            </Link>
          ))}
        </nav>
      </div>
      <div className="container mx-auto max-w-[1400px] px-4 pb-6 sm:px-6">
        <p className="border-t border-white/5 pt-4 text-xs text-muted-foreground/70">
          Built by roman, basti and ben — one shared board, no excuses.
        </p>
      </div>
    </footer>
  );
}
