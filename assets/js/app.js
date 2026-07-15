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

})();
