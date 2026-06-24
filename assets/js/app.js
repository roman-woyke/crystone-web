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

    // Interactive dot-grid background
    document.addEventListener("DOMContentLoaded", function () {
        var canvas = document.getElementById("dot-grid");
        if (!canvas) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        var ctx = canvas.getContext("2d");
        var SPACING    = 36;
        var DOT_R      = 1.5;
        var REPEL_R    = 120;
        var REPEL_STR  = 70;
        var IDLE_AMP   = 4;
        var IDLE_SPEED = 0.00075;

        var dots = [];
        var mx = -9999, my = -9999;

        function build() {
            canvas.width  = window.innerWidth;
            canvas.height = window.innerHeight;
            dots = [];
            var cols = Math.ceil(canvas.width  / SPACING);
            var rows = Math.ceil(canvas.height / SPACING);
            for (var r = 0; r <= rows; r++) {
                for (var c = 0; c <= cols; c++) {
                    dots.push({
                        bx: c * SPACING,
                        by: r * SPACING,
                        px: Math.random() * Math.PI * 2,
                        py: Math.random() * Math.PI * 2,
                        fx: 0.6 + Math.random() * 0.8,
                        fy: 0.6 + Math.random() * 0.8
                    });
                }
            }
        }

        document.addEventListener("mousemove", function (e) { mx = e.clientX; my = e.clientY; });
        document.addEventListener("mouseleave", function ()  { mx = -9999;     my = -9999;     });

        var t0 = null;

        function frame(ts) {
            if (t0 === null) t0 = ts;
            var t = ts - t0;

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            for (var i = 0; i < dots.length; i++) {
                var d    = dots[i];
                var ix   = Math.sin(t * IDLE_SPEED * d.fx + d.px) * IDLE_AMP;
                var iy   = Math.sin(t * IDLE_SPEED * d.fy + d.py) * IDLE_AMP;
                var dx   = d.bx - mx;
                var dy   = d.by - my;
                var dist = Math.sqrt(dx * dx + dy * dy);
                var rx   = 0, ry = 0;

                if (dist < REPEL_R && dist > 0) {
                    var force = (1 - dist / REPEL_R) * REPEL_STR;
                    rx = (dx / dist) * force;
                    ry = (dy / dist) * force;
                }

                ctx.beginPath();
                ctx.arc(d.bx + ix + rx, d.by + iy + ry, DOT_R, 0, Math.PI * 2);
                ctx.fillStyle = dist < REPEL_R
                    ? "rgba(255,255,255," + (0.18 + (1 - dist / REPEL_R) * 0.22) + ")"
                    : "rgba(255,255,255,0.18)";
                ctx.fill();
            }

            requestAnimationFrame(frame);
        }

        window.addEventListener("resize", build);
        build();
        requestAnimationFrame(frame);
    });
})();
