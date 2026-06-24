/* Shared site-wide helpers: mobile nav toggle + animated count-up. */

(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        var burger = document.getElementById("nav-burger");
        var links = document.getElementById("nav-links");
        var navbar = burger ? burger.closest(".navbar") : null;

        if (burger && links) {
            burger.addEventListener("click", function () {
                var open = links.classList.toggle("open");
                burger.classList.toggle("open", open);
                burger.setAttribute("aria-expanded", open ? "true" : "false");
                if (navbar) navbar.classList.toggle("nav-open", open);
            });
        }

        var mobileLogout = document.getElementById("nav-logout-mobile");
        if (mobileLogout) {
            mobileLogout.addEventListener("click", function (e) {
                e.preventDefault();
                if (window.confirm("Wirklich ausloggen?")) {
                    window.location.href = mobileLogout.href;
                }
            });
        }
    });

    var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    /**
     * Animate an element's text from 0 to target with an ease-out curve.
     * Respects prefers-reduced-motion by setting the final value directly.
     */
    window.countUp = function (el, target, durationMs) {
        durationMs = durationMs || 900;
        target = Number(target) || 0;

        if (reducedMotion.matches || durationMs <= 0) {
            el.textContent = String(target);
            return;
        }

        var start = null;

        function frame(ts) {
            if (start === null) start = ts;

            var progress = Math.min((ts - start) / durationMs, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            el.textContent = String(Math.round(target * eased));

            if (progress < 1) {
                requestAnimationFrame(frame);
            }
        }

        requestAnimationFrame(frame);
    };

    /**
     * Run countUp on every element matching [data-countup] once it scrolls
     * into view. Elements carry their target in data-countup.
     */
    window.initCountUps = function (root) {
        var els = (root || document).querySelectorAll("[data-countup]");
        if (!els.length) return;

        if (!("IntersectionObserver" in window)) {
            els.forEach(function (el) {
                el.textContent = el.getAttribute("data-countup");
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;

                var el = entry.target;
                observer.unobserve(el);
                window.countUp(el, el.getAttribute("data-countup"), 1100);
            });
        }, { threshold: 0.4 });

        els.forEach(function (el) {
            observer.observe(el);
        });
    };

    document.addEventListener("DOMContentLoaded", function () {
        window.initCountUps(document);
    });

    // Interactive dot-grid background — fluid / spring physics
    document.addEventListener("DOMContentLoaded", function () {
        var canvas = document.getElementById("dot-grid");
        if (!canvas) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        var ctx = canvas.getContext("2d");

        // Grid
        var SPACING  = 36;
        var DOT_R    = 1.5;

        // Spring physics per dot (SI-like: distance in px, time in s)
        var SPRING   = 18;   // stiffness  (s^-2)   — higher = snappier return
        var DAMP     = 5.5;  // damping    (s^-1)   — higher = less oscillation
        // ζ = DAMP / (2*sqrt(SPRING)) ≈ 0.65  →  underdamped, ~1 visible oscillation

        // Cursor drag
        var DRAG_R   = 160;  // influence radius (px)
        var DRAG_STR = 5;    // cursor-velocity → dot-velocity multiplier

        // Idle sway (subtle, on top of physics)
        var IDLE_AMP = 2;
        var IDLE_SPD = 0.0005;

        var dots = [];
        var mx = -9999, my = -9999;
        var prevMx = -9999, prevMy = -9999;

        function build() {
            canvas.width  = window.innerWidth;
            canvas.height = window.innerHeight;
            var cols = Math.ceil(canvas.width  / SPACING);
            var rows = Math.ceil(canvas.height / SPACING);
            dots = [];
            for (var r = 0; r <= rows; r++) {
                for (var c = 0; c <= cols; c++) {
                    dots.push({
                        bx: c * SPACING,
                        by: r * SPACING,
                        ox: 0, oy: 0,  // physics offset from base
                        vx: 0, vy: 0,  // velocity
                        px: Math.random() * Math.PI * 2,
                        py: Math.random() * Math.PI * 2,
                        fx: 0.5 + Math.random() * 0.8,
                        fy: 0.5 + Math.random() * 0.8
                    });
                }
            }
        }

        document.addEventListener("mousemove", function (e) { mx = e.clientX; my = e.clientY; });
        document.addEventListener("mouseleave", function ()  { mx = -9999;    my = -9999;    });

        var t0 = null, prevTs = null;

        function frame(ts) {
            if (t0 === null) { t0 = ts; prevTs = ts; }
            var t  = ts - t0;
            var dt = Math.min((ts - prevTs) / 1000, 0.05); // seconds, capped at 50 ms
            prevTs = ts;

            // cursor velocity this frame (px/frame, then scaled by DRAG_STR)
            var cvx = 0, cvy = 0;
            if (prevMx > -9000 && mx > -9000) {
                cvx = (mx - prevMx) * DRAG_STR;
                cvy = (my - prevMy) * DRAG_STR;
            }
            prevMx = mx;
            prevMy = my;

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // single batched path — all dots same colour, one fill() call
            ctx.fillStyle = "rgba(255,255,255,0.22)";
            ctx.beginPath();

            for (var i = 0; i < dots.length; i++) {
                var d = dots[i];

                // --- cursor drag: push dot in the direction the cursor moved ---
                if (cvx !== 0 || cvy !== 0) {
                    var dx   = d.bx - mx;
                    var dy   = d.by - my;
                    var dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < DRAG_R) {
                        var inf = 1 - dist / DRAG_R;
                        inf = inf * inf;          // quadratic falloff
                        d.vx += cvx * inf;
                        d.vy += cvy * inf;
                    }
                }

                // --- spring: pull offset back toward (0,0) ---
                // --- damping: resist velocity                ---
                var ax = -SPRING * d.ox - DAMP * d.vx;
                var ay = -SPRING * d.oy - DAMP * d.vy;
                d.vx += ax * dt;
                d.vy += ay * dt;
                d.ox += d.vx * dt;
                d.oy += d.vy * dt;

                // --- idle sway (sinusoidal, independent per dot) ---
                var ix = Math.sin(t * IDLE_SPD * d.fx + d.px) * IDLE_AMP;
                var iy = Math.sin(t * IDLE_SPD * d.fy + d.py) * IDLE_AMP;

                var x = d.bx + d.ox + ix;
                var y = d.by + d.oy + iy;

                ctx.moveTo(x + DOT_R, y);
                ctx.arc(x, y, DOT_R, 0, Math.PI * 2);
            }

            ctx.fill();

            requestAnimationFrame(frame);
        }

        window.addEventListener("resize", build);
        build();
        requestAnimationFrame(frame);
    });
})();
