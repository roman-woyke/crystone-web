"use client";

import { useCallback, useEffect, useRef, useState } from "react";

export type TooltipState = { html: string; x: number; y: number; visible: boolean };

export function useTooltip() {
  const [tooltip, setTooltip] = useState<TooltipState>({ html: "", x: 0, y: 0, visible: false });
  const elRef = useRef<HTMLDivElement>(null);

  const show = useCallback((html: string, x: number, y: number) => {
    setTooltip({ html, x, y, visible: true });
  }, []);

  const hide = useCallback(() => {
    setTooltip((t) => ({ ...t, visible: false }));
  }, []);

  return { tooltip, elRef, show, hide };
}

export function TooltipEl({ tooltip, elRef }: { tooltip: TooltipState; elRef: React.RefObject<HTMLDivElement | null> }) {
  const [size, setSize] = useState({ width: 0, height: 0 });

  useEffect(() => {
    if (elRef.current) {
      setSize({ width: elRef.current.offsetWidth, height: elRef.current.offsetHeight });
    }
  }, [tooltip.html, elRef]);

  const overflowX = typeof window !== "undefined" && tooltip.x + 14 + size.width > window.innerWidth;
  const overflowY = typeof window !== "undefined" && tooltip.y + 14 + size.height > window.innerHeight;

  const left = overflowX ? tooltip.x - size.width - 14 : tooltip.x + 14;
  const top = overflowY ? tooltip.y - size.height - 14 : tooltip.y + 14;

  return (
    <div
      ref={elRef}
      className="pointer-events-none fixed z-50 max-w-xs rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md transition-opacity"
      style={{ left, top, opacity: tooltip.visible ? 1 : 0 }}
      dangerouslySetInnerHTML={{ __html: tooltip.html }}
    />
  );
}
