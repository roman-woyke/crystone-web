import Link from "next/link";

// Segmented pill tabs used for the user switchers (calendar, projects).
export type PillTab = { href: string; label: string; active: boolean };

export function PillTabs({ tabs }: { tabs: PillTab[] }) {
  return (
    <div className="inline-flex flex-wrap items-center gap-1 rounded-full border border-white/10 bg-white/[0.04] p-1 backdrop-blur-md">
      {tabs.map((tab) => (
        <Link
          key={tab.href}
          href={tab.href}
          className={`rounded-full px-4 py-1.5 text-sm font-medium transition-all ${
            tab.active
              ? "bg-gradient-to-b from-primary to-primary/75 text-primary-foreground shadow-[0_2px_10px_-2px_rgba(139,92,246,0.6)]"
              : "text-muted-foreground hover:bg-white/5 hover:text-foreground"
          }`}
        >
          {tab.label}
        </Link>
      ))}
    </div>
  );
}
