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

    // GradientBlinds WebGL background — venetian-blind shader with mouse spotlight
    document.addEventListener("DOMContentLoaded", function () {
        var container = document.getElementById("gradient-blinds");
        if (!container) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        var dpr = window.devicePixelRatio || 1;

        // Colors: very dark violet -> dark blue cycle, matches site accent palette
        var GRADIENT_COLORS = ["#05040d", "#110830", "#060f30", "#05040d"];
        var NOISE           = 0.10;
        var BLIND_COUNT     = 8;
        var BLIND_MIN_WIDTH = 80;
        var DAMPENING       = 0.15;
        var SPOT_RADIUS     = 0.55;
        var SPOT_SOFTNESS   = 1.0;
        var SPOT_OPACITY    = 0.45;
        var MAX_COLORS      = 8;

        function hexToRgb(hex) {
            var h = hex.replace("#", "").padEnd(6, "0");
            return [
                parseInt(h.slice(0, 2), 16) / 255,
                parseInt(h.slice(2, 4), 16) / 255,
                parseInt(h.slice(4, 6), 16) / 255
            ];
        }

        function prepStops(stops) {
            var base = stops.slice(0, MAX_COLORS);
            if (base.length === 1) base.push(base[0]);
            while (base.length < MAX_COLORS) base.push(base[base.length - 1]);
            var arr = [];
            for (var ci = 0; ci < MAX_COLORS; ci++) arr.push(hexToRgb(base[ci]));
            return { arr: arr, count: Math.max(2, Math.min(MAX_COLORS, stops.length)) };
        }

        var canvas = document.createElement("canvas");
        canvas.style.cssText = "position:absolute;inset:0;width:100%;height:100%;display:block;";
        container.appendChild(canvas);

        var gl = canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
        if (!gl) return;

        var vertSrc = [
            "attribute vec2 position;",
            "attribute vec2 uv;",
            "varying vec2 vUv;",
            "void main() {",
            "  vUv = uv;",
            "  gl_Position = vec4(position, 0.0, 1.0);",
            "}"
        ].join("\n");

        // vUv is already in [0,1] x [0,1] (same as fragCoord / iResolution)
        var fragSrc = [
            "#ifdef GL_ES",
            "precision mediump float;",
            "#endif",
            "uniform vec3  iResolution;",
            "uniform vec2  iMouse;",
            "uniform float iTime;",
            "uniform float uAngle;",
            "uniform float uNoise;",
            "uniform float uBlindCount;",
            "uniform float uSpotlightRadius;",
            "uniform float uSpotlightSoftness;",
            "uniform float uSpotlightOpacity;",
            "uniform float uMirror;",
            "uniform float uDistort;",
            "uniform float uShineFlip;",
            "uniform vec3  uColor0;",
            "uniform vec3  uColor1;",
            "uniform vec3  uColor2;",
            "uniform vec3  uColor3;",
            "uniform vec3  uColor4;",
            "uniform vec3  uColor5;",
            "uniform vec3  uColor6;",
            "uniform vec3  uColor7;",
            "uniform int   uColorCount;",
            "varying vec2 vUv;",
            "float rand(vec2 co) {",
            "  return fract(sin(dot(co, vec2(12.9898, 78.233))) * 43758.5453);",
            "}",
            "vec2 rotate2D(vec2 p, float a) {",
            "  float c = cos(a); float s = sin(a);",
            "  return mat2(c, -s, s, c) * p;",
            "}",
            "vec3 gradColor(float t) {",
            "  float tt = clamp(t, 0.0, 1.0);",
            "  int n = uColorCount;",
            "  if (n < 2) n = 2;",
            "  float sc = tt * float(n - 1);",
            "  float sg = floor(sc);",
            "  float f  = fract(sc);",
            "  if (sg < 1.0)           return mix(uColor0, uColor1, f);",
            "  if (sg < 2.0 && n > 2)  return mix(uColor1, uColor2, f);",
            "  if (sg < 3.0 && n > 3)  return mix(uColor2, uColor3, f);",
            "  if (sg < 4.0 && n > 4)  return mix(uColor3, uColor4, f);",
            "  if (sg < 5.0 && n > 5)  return mix(uColor4, uColor5, f);",
            "  if (sg < 6.0 && n > 6)  return mix(uColor5, uColor6, f);",
            "  if (sg < 7.0 && n > 7)  return mix(uColor6, uColor7, f);",
            "  if (n > 7) return uColor7;",
            "  if (n > 6) return uColor6;",
            "  if (n > 5) return uColor5;",
            "  if (n > 4) return uColor4;",
            "  if (n > 3) return uColor3;",
            "  if (n > 2) return uColor2;",
            "  return uColor1;",
            "}",
            "void main() {",
            "  vec2 uv0 = vUv;",
            "  float aspect = iResolution.x / iResolution.y;",
            "  vec2 p = uv0 * 2.0 - 1.0;",
            "  p.x *= aspect;",
            "  vec2 pr = rotate2D(p, uAngle);",
            "  pr.x /= aspect;",
            "  vec2 uv = pr * 0.5 + 0.5;",
            "  vec2 uvM = uv;",
            "  if (uDistort > 0.0) {",
            "    uvM.x += sin(uvM.y * 6.0) * 0.01 * uDistort;",
            "    uvM.y += cos(uvM.x * 6.0) * 0.01 * uDistort;",
            "  }",
            "  float t = uvM.x;",
            "  if (uMirror > 0.5) t = 1.0 - abs(1.0 - 2.0 * fract(t));",
            "  vec3 base = gradColor(t);",
            "  vec2 mo = vec2(iMouse.x / iResolution.x, iMouse.y / iResolution.y);",
            "  float d  = length(uv0 - mo);",
            "  float dn = d / max(uSpotlightRadius, 1e-4);",
            "  float spot = (1.0 - 2.0 * pow(dn, uSpotlightSoftness)) * uSpotlightOpacity;",
            "  float stripe = fract(uvM.x * max(uBlindCount, 1.0));",
            "  if (uShineFlip > 0.5) stripe = 1.0 - stripe;",
            "  vec3 col = vec3(spot) + base - vec3(stripe);",
            "  col += (rand(gl_FragCoord.xy + iTime) - 0.5) * uNoise;",
            "  gl_FragColor = vec4(col, 1.0);",
            "}"
        ].join("\n");

        function makeShader(type, src) {
            var sh = gl.createShader(type);
            gl.shaderSource(sh, src);
            gl.compileShader(sh);
            if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
                console.warn("GradientBlinds shader:", gl.getShaderInfoLog(sh));
                gl.deleteShader(sh);
                return null;
            }
            return sh;
        }

        var vs = makeShader(gl.VERTEX_SHADER, vertSrc);
        var fs = makeShader(gl.FRAGMENT_SHADER, fragSrc);
        if (!vs || !fs) return;

        var prog = gl.createProgram();
        gl.attachShader(prog, vs);
        gl.attachShader(prog, fs);
        gl.linkProgram(prog);
        if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
            console.warn("GradientBlinds link:", gl.getProgramInfoLog(prog));
            return;
        }
        gl.useProgram(prog);

        // Full-screen triangle: pos and uv interleaved (stride 16 bytes)
        var vbo = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, vbo);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([
            -1, -1,  0, 0,
             3, -1,  2, 0,
            -1,  3,  0, 2
        ]), gl.STATIC_DRAW);

        var posLoc = gl.getAttribLocation(prog, "position");
        var uvLoc  = gl.getAttribLocation(prog, "uv");
        gl.enableVertexAttribArray(posLoc);
        gl.vertexAttribPointer(posLoc, 2, gl.FLOAT, false, 16, 0);
        gl.enableVertexAttribArray(uvLoc);
        gl.vertexAttribPointer(uvLoc,  2, gl.FLOAT, false, 16, 8);

        var uRes  = gl.getUniformLocation(prog, "iResolution");
        var uMo   = gl.getUniformLocation(prog, "iMouse");
        var uT    = gl.getUniformLocation(prog, "iTime");
        var uN    = gl.getUniformLocation(prog, "uNoise");
        var uB    = gl.getUniformLocation(prog, "uBlindCount");
        var uSR   = gl.getUniformLocation(prog, "uSpotlightRadius");
        var uSS   = gl.getUniformLocation(prog, "uSpotlightSoftness");
        var uSO   = gl.getUniformLocation(prog, "uSpotlightOpacity");
        var uCnt  = gl.getUniformLocation(prog, "uColorCount");
        var uCols = [];
        for (var ci = 0; ci < 8; ci++) uCols.push(gl.getUniformLocation(prog, "uColor" + ci));

        var cd = prepStops(GRADIENT_COLORS);
        gl.uniform1f(uN,  NOISE);
        gl.uniform1f(uSR, SPOT_RADIUS);
        gl.uniform1f(uSS, SPOT_SOFTNESS);
        gl.uniform1f(uSO, SPOT_OPACITY);
        gl.uniform1f(gl.getUniformLocation(prog, "uAngle"),    0.0);
        gl.uniform1f(gl.getUniformLocation(prog, "uMirror"),   0.0);
        gl.uniform1f(gl.getUniformLocation(prog, "uDistort"),  0.0);
        gl.uniform1f(gl.getUniformLocation(prog, "uShineFlip"), 0.0);
        gl.uniform1i(uCnt, cd.count);
        cd.arr.forEach(function (c, i) { gl.uniform3fv(uCols[i], c); });

        var mx = 0, my = 0, tx = 0, ty = 0, firstSize = true, lastTs = 0, t0 = null;

        if (window.ResizeObserver) {
            var ro = new ResizeObserver(function () {
                var rect = container.getBoundingClientRect();
                var w = Math.round(rect.width  * dpr);
                var h = Math.round(rect.height * dpr);
                canvas.width  = w;
                canvas.height = h;
                gl.viewport(0, 0, w, h);
                gl.uniform3f(uRes, w, h, 1);
                var eff = BLIND_MIN_WIDTH > 0
                    ? Math.min(BLIND_COUNT, Math.max(1, Math.floor(rect.width / BLIND_MIN_WIDTH)))
                    : BLIND_COUNT;
                gl.uniform1f(uB, Math.max(1, eff));
                if (firstSize) {
                    firstSize = false;
                    tx = w / 2; ty = h / 2;
                    mx = tx;    my = ty;
                    gl.uniform2f(uMo, mx, my);
                }
            });
            ro.observe(container);
        } else {
            // Fallback for browsers without ResizeObserver
            var doResize = function () {
                var w = Math.round(window.innerWidth  * dpr);
                var h = Math.round(window.innerHeight * dpr);
                canvas.width  = w;
                canvas.height = h;
                gl.viewport(0, 0, w, h);
                gl.uniform3f(uRes, w, h, 1);
                var eff = BLIND_MIN_WIDTH > 0
                    ? Math.min(BLIND_COUNT, Math.max(1, Math.floor(window.innerWidth / BLIND_MIN_WIDTH)))
                    : BLIND_COUNT;
                gl.uniform1f(uB, Math.max(1, eff));
                if (firstSize) {
                    firstSize = false;
                    tx = w / 2; ty = h / 2;
                    mx = tx;    my = ty;
                    gl.uniform2f(uMo, mx, my);
                }
            };
            window.addEventListener("resize", doResize);
            doResize();
        }

        // Listen on document so pointer events still reach page content above
        document.addEventListener("pointermove", function (e) {
            tx = e.clientX * dpr;
            ty = (window.innerHeight - e.clientY) * dpr;
        });

        function loop(ts) {
            requestAnimationFrame(loop);
            if (t0 === null) t0 = ts;
            var t  = (ts - t0) * 0.001;
            var dt = lastTs ? (ts - lastTs) / 1000 : 0;
            lastTs = ts;
            if (DAMPENING > 0 && dt > 0) {
                var factor = Math.min(1, 1 - Math.exp(-dt / Math.max(1e-4, DAMPENING)));
                mx += (tx - mx) * factor;
                my += (ty - my) * factor;
            } else {
                mx = tx; my = ty;
            }
            gl.uniform1f(uT, t);
            gl.uniform2f(uMo, mx, my);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.drawArrays(gl.TRIANGLES, 0, 3);
        }

        requestAnimationFrame(loop);
    });
})();
