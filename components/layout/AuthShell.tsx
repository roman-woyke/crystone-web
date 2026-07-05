import type { ReactNode } from "react";
import { CalendarDays, Gamepad2, Timer, Trophy } from "lucide-react";

const FEATURES = [
  {
    icon: Trophy,
    title: "Application leaderboard",
    text: "Every status change scores points — permanently.",
  },
  {
    icon: Timer,
    title: "Live study counter",
    text: "See who is grinding right now, breaks included.",
  },
  {
    icon: CalendarDays,
    title: "Exam calendar",
    text: "All exams for the crew, one shared view.",
  },
  {
    icon: Gamepad2,
    title: "Daily Boardle",
    text: "Four boards a day. Winners pick tomorrow's words.",
  },
];

// Split auth layout: brand panel on the left (desktop only), form on the
// right. Shared by the login and register pages.
export function AuthShell({ children }: { children: ReactNode }) {
  return (
    <div className="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
      <aside className="relative hidden overflow-hidden border-r border-white/10 bg-white/[0.02] p-12 backdrop-blur-sm lg:flex lg:flex-col lg:justify-between">
        <div
          aria-hidden
          className="pointer-events-none absolute -top-32 -left-32 size-96 rounded-full bg-violet-500/20 blur-3xl"
        />
        <div
          aria-hidden
          className="pointer-events-none absolute -right-32 -bottom-32 size-96 rounded-full bg-sky-500/15 blur-3xl"
        />

        <div className="rise flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500 shadow-[0_4px_14px_-4px_rgba(139,92,246,0.7)]">
            <Trophy className="size-4.5 text-white" />
          </span>
          <span className="font-heading text-xl font-bold tracking-tight">
            Intern<span className="gradient-text">Track</span>
          </span>
        </div>

        <div className="relative space-y-10">
          <h1 className="rise rise-1 font-heading max-w-md text-4xl leading-tight font-bold tracking-tight xl:text-5xl">
            Every application <span className="gradient-text">scores points.</span>
          </h1>
          <ul className="space-y-5">
            {FEATURES.map((f, i) => (
              <li key={f.title} className={`rise rise-${i + 2} flex items-start gap-3.5`}>
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-primary/10 text-primary">
                  <f.icon className="size-4" />
                </span>
                <div>
                  <p className="font-semibold">{f.title}</p>
                  <p className="text-sm text-muted-foreground">{f.text}</p>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <p className="rise rise-5 text-xs text-muted-foreground/70">
          A shared board for roman, basti and ben. Invite only.
        </p>
      </aside>

      <main className="flex items-center justify-center p-4 sm:p-8">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center justify-center gap-2.5 lg:hidden">
            <span className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500">
              <Trophy className="size-4 text-white" />
            </span>
            <span className="font-heading text-lg font-bold tracking-tight">
              Intern<span className="gradient-text">Track</span>
            </span>
          </div>
          {children}
        </div>
      </main>
    </div>
  );
}
