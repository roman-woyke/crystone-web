"use client";

// Cursor-reactive card effects (adapted from React Bits' MagicBento):
// a global spotlight that follows the pointer, a border glow on every card
// near it, a subtle tilt/magnetism on the hovered card, and a ripple on
// click. Works on any element carrying `.glow-card` or shadcn's
// `[data-slot="card"]` — the visual part lives in globals.css, this
// component only drives the CSS variables and gsap transforms.

import { useEffect } from "react";
import { gsap } from "gsap";

const CARD_SELECTOR = '.glow-card, [data-slot="card"]';
const GLOW_RGB = "139, 92, 246";
const SPOTLIGHT_RADIUS = 320;

export default function CardEffects() {
  useEffect(() => {
    if (window.innerWidth <= 768) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    const spotlight = document.createElement("div");
    spotlight.className = "fx-spotlight";
    spotlight.style.cssText = `
      position: fixed;
      width: 780px;
      height: 780px;
      border-radius: 50%;
      pointer-events: none;
      background: radial-gradient(circle,
        rgba(${GLOW_RGB}, 0.10) 0%,
        rgba(${GLOW_RGB}, 0.05) 20%,
        rgba(${GLOW_RGB}, 0.02) 40%,
        transparent 68%
      );
      z-index: 80;
      opacity: 0;
      transform: translate(-50%, -50%);
      mix-blend-mode: screen;
      will-change: transform, opacity;
    `;
    document.body.appendChild(spotlight);

    const proximity = SPOTLIGHT_RADIUS * 0.5;
    const fadeDistance = SPOTLIGHT_RADIUS * 0.75;

    let tilted: HTMLElement | null = null;
    let raf = 0;
    let lastEvent: MouseEvent | null = null;

    const resetTilt = (el: HTMLElement) => {
      gsap.to(el, { rotateX: 0, rotateY: 0, x: 0, y: 0, duration: 0.35, ease: "power2.out" });
    };

    const update = () => {
      raf = 0;
      const e = lastEvent;
      if (!e) return;

      const cards = document.querySelectorAll<HTMLElement>(CARD_SELECTOR);
      let minDistance = Infinity;

      cards.forEach((card) => {
        const rect = card.getBoundingClientRect();
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;
        const distance =
          Math.hypot(e.clientX - centerX, e.clientY - centerY) - Math.max(rect.width, rect.height) / 2;
        const effective = Math.max(0, distance);
        minDistance = Math.min(minDistance, effective);

        let intensity = 0;
        if (effective <= proximity) intensity = 1;
        else if (effective <= fadeDistance) intensity = (fadeDistance - effective) / (fadeDistance - proximity);

        card.style.setProperty("--glow-x", `${((e.clientX - rect.left) / rect.width) * 100}%`);
        card.style.setProperty("--glow-y", `${((e.clientY - rect.top) / rect.height) * 100}%`);
        card.style.setProperty("--glow-intensity", intensity.toString());
      });

      gsap.to(spotlight, { left: e.clientX, top: e.clientY, duration: 0.12, ease: "power2.out" });
      const targetOpacity =
        minDistance <= proximity
          ? 0.8
          : minDistance <= fadeDistance
            ? ((fadeDistance - minDistance) / (fadeDistance - proximity)) * 0.8
            : 0;
      gsap.to(spotlight, { opacity: targetOpacity, duration: targetOpacity > 0 ? 0.2 : 0.5, ease: "power2.out" });

      // Subtle tilt + magnetism on the card directly under the pointer.
      const target = e.target instanceof Element ? e.target.closest<HTMLElement>(CARD_SELECTOR) : null;
      const hovered = target && !target.hasAttribute("data-no-tilt") ? target : null;
      if (tilted && tilted !== hovered) {
        resetTilt(tilted);
        tilted = null;
      }
      if (hovered) {
        tilted = hovered;
        const rect = hovered.getBoundingClientRect();
        const px = (e.clientX - rect.left) / rect.width - 0.5;
        const py = (e.clientY - rect.top) / rect.height - 0.5;
        gsap.to(hovered, {
          rotateX: py * -4,
          rotateY: px * 4,
          x: px * 6,
          y: py * 6,
          duration: 0.25,
          ease: "power2.out",
          transformPerspective: 900,
        });
      }
    };

    const handleMouseMove = (e: MouseEvent) => {
      lastEvent = e;
      if (!raf) raf = requestAnimationFrame(update);
    };

    const handleMouseLeave = () => {
      document.querySelectorAll<HTMLElement>(CARD_SELECTOR).forEach((card) => {
        card.style.setProperty("--glow-intensity", "0");
      });
      if (tilted) {
        resetTilt(tilted);
        tilted = null;
      }
      gsap.to(spotlight, { opacity: 0, duration: 0.3, ease: "power2.out" });
    };

    const handleClick = (e: MouseEvent) => {
      const card = e.target instanceof Element ? e.target.closest<HTMLElement>(CARD_SELECTOR) : null;
      if (!card) return;
      // Only ripple inside elements that clip their content, otherwise the
      // expanding circle would paint far outside the card.
      if (!/(hidden|clip|auto|scroll)/.test(getComputedStyle(card).overflow)) return;

      const rect = card.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const y = e.clientY - rect.top;
      const maxDistance = Math.max(
        Math.hypot(x, y),
        Math.hypot(x - rect.width, y),
        Math.hypot(x, y - rect.height),
        Math.hypot(x - rect.width, y - rect.height),
      );

      const ripple = document.createElement("div");
      ripple.style.cssText = `
        position: absolute;
        width: ${maxDistance * 2}px;
        height: ${maxDistance * 2}px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(${GLOW_RGB}, 0.3) 0%, rgba(${GLOW_RGB}, 0.14) 30%, transparent 70%);
        left: ${x - maxDistance}px;
        top: ${y - maxDistance}px;
        pointer-events: none;
        z-index: 40;
      `;
      card.appendChild(ripple);
      gsap.fromTo(
        ripple,
        { scale: 0, opacity: 1 },
        { scale: 1, opacity: 0, duration: 0.8, ease: "power2.out", onComplete: () => ripple.remove() },
      );
    };

    document.addEventListener("mousemove", handleMouseMove);
    document.addEventListener("mouseleave", handleMouseLeave);
    document.addEventListener("click", handleClick);

    return () => {
      if (raf) cancelAnimationFrame(raf);
      document.removeEventListener("mousemove", handleMouseMove);
      document.removeEventListener("mouseleave", handleMouseLeave);
      document.removeEventListener("click", handleClick);
      spotlight.remove();
    };
  }, []);

  return null;
}
