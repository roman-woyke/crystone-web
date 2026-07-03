"use client";

// Mounts the site-wide visual effects: the animated WebGL background, the
// cursor click sparks, and the cursor-reactive card glow. Rendered once from
// the root layout, behind (background) and above (sparks) all page content.
// Everything is skipped when the user prefers reduced motion.

import { useEffect, useState } from "react";

import ColorBends from "@/components/fx/ColorBends";
import ClickSpark from "@/components/fx/ClickSpark";
import CardEffects from "@/components/fx/CardEffects";

export function Effects() {
  // WebGL/canvas can only exist client-side; render nothing during SSR and
  // hydrate the effects in after mount (null = not mounted yet).
  const [reducedMotion, setReducedMotion] = useState<boolean | null>(null);

  useEffect(() => {
    // One-time mount sync with a browser API (matchMedia) that doesn't exist
    // during SSR — this can't be derived during render.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setReducedMotion(window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }, []);

  if (reducedMotion !== false) return null;

  return (
    <>
      <div aria-hidden className="fixed inset-0 z-0 opacity-55">
        <ColorBends
          colors={["#8b5cf6", "#4f46e5", "#0ea5e9"]}
          rotation={115}
          speed={0.14}
          scale={1.25}
          frequency={1}
          warpStrength={0.9}
          mouseInfluence={0.6}
          noise={0.06}
          parallax={0.4}
          iterations={2}
          intensity={0.85}
          bandWidth={6}
          transparent
        />
      </div>
      <ClickSpark sparkColor="#a78bfa" sparkSize={9} sparkRadius={18} sparkCount={8} duration={450} />
      <CardEffects />
    </>
  );
}
