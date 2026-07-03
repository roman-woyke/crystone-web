"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";

// Animated stat tile: icon chip, big tabular value, small uppercase label.
// Numeric values count up on mount (skipped under prefers-reduced-motion);
// string values (e.g. "3h 20m") render as-is.
export function StatTile({
  label,
  value,
  icon,
  accent = "#a78bfa",
}: {
  label: string;
  value: number | string;
  icon?: ReactNode;
  accent?: string;
}) {
  const numeric = typeof value === "number";
  const [display, setDisplay] = useState<number>(numeric ? 0 : 0);
  const done = useRef(false);

  useEffect(() => {
    if (!numeric || done.current) return;
    done.current = true;
    const target = value;
    // Under prefers-reduced-motion the value still lands via rAF, just with
    // no tween — the first frame jumps straight to the target.
    const duration = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 750;
    const start = performance.now();
    let raf = 0;
    const tick = (now: number) => {
      const t = duration === 0 ? 1 : Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      setDisplay(Math.round(target * eased));
      if (t < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [numeric, value]);

  return (
    <div
      className="glow-card group relative overflow-hidden rounded-xl border bg-card p-4 backdrop-blur-md transition-transform"
      style={{ "--tile-accent": accent } as React.CSSProperties}
    >
      <div
        aria-hidden
        className="pointer-events-none absolute -top-8 -right-8 size-24 rounded-full opacity-[0.12] blur-2xl transition-opacity group-hover:opacity-25"
        style={{ background: accent }}
      />
      <div className="flex items-center gap-3">
        {icon && (
          <span
            className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-white/10 [&_svg]:size-4"
            style={{ color: accent, background: `color-mix(in oklab, ${accent} 14%, transparent)` }}
          >
            {icon}
          </span>
        )}
        <div className="min-w-0">
          <div className="tabular font-heading text-2xl leading-tight font-bold">
            {numeric ? display : value}
          </div>
          <div className="truncate text-[0.7rem] font-semibold tracking-wider text-muted-foreground uppercase">
            {label}
          </div>
        </div>
      </div>
    </div>
  );
}
