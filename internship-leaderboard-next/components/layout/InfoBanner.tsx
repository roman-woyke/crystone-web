import type { ReactNode } from "react";
import { Info } from "lucide-react";

// Slim glass notice bar (view-context hints on calendar/projects).
export function InfoBanner({ children }: { children: ReactNode }) {
  return (
    <p className="flex items-center gap-2.5 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-2.5 text-sm text-muted-foreground backdrop-blur-md">
      <Info className="size-4 shrink-0 text-primary" aria-hidden />
      <span>{children}</span>
    </p>
  );
}
