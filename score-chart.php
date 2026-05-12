<?php
// includes/score-chart.php
// Renders the score evolution chart below the leaderboard table.
// Fetches data from /api/get-score-history.php and /api/get-raw-events.php
?>

<div id="score-chart-section" style="margin-top: 40px;">
    <h2>Score Evolution</h2>
    <canvas id="scoreChart" height="120"></canvas>

    <div id="chart-scrollbar-track" style="display:none;">
        <div id="chart-scrollbar-thumb"></div>
    </div>
    <div class="chart-scrollbar-labels">
        <span id="chart-label-left"></span>
        <span id="chart-label-right"></span>
    </div>

    <p id="chart-status" style="color: #9ca3af; font-size: 0.9rem;"></p>
</div>

<style>
    #chart-scrollbar-track {
        position: relative;
        width: 100%;
        height: 10px;
        background: #374151;
        border-radius: 5px;
        margin-top: 14px;
        cursor: pointer;
        user-select: none;
    }

    #chart-scrollbar-thumb {
        position: absolute;
        top: 0;
        left: 0;
        height: 100%;
        background: #2563eb;
        border-radius: 5px;
        cursor: grab;
        transition: background 0.15s;
        min-width: 24px;
    }

    #chart-scrollbar-thumb:hover {
        background: #3b82f6;
    }

    #chart-scrollbar-thumb.dragging {
        cursor: grabbing;
        background: #1d4ed8;
    }

    .chart-scrollbar-labels {
        display: flex;
        justify-content: space-between;
        margin-top: 6px;
        font-size: 0.78rem;
        color: #6b7280;
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<script>
(function () {

    const USER_COLORS = [
        "#60a5fa", // blue
        "#f87171", // red
        "#34d399", // green
        "#fbbf24", // amber
        "#a78bfa", // purple
        "#fb923c", // orange
        "#38bdf8", // sky
        "#f472b6", // pink
    ];

    const VIEWPORT_DAYS = 7;

    // ── Helpers ──────────────────────────────────────────────────────

    function parseDate(str) {
        const [y, m, d] = str.split("-").map(Number);
        return new Date(y, m - 1, d);
    }

    function addDays(date, n) {
        const d = new Date(date);
        d.setDate(d.getDate() + n);
        return d;
    }

    function toDateStr(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, "0");
        const d = String(date.getDate()).padStart(2, "0");
        return `${y}-${m}-${d}`;
    }

    function formatLabel(date) {
        return date.toLocaleDateString(undefined, { month: "short", day: "numeric" });
    }

    // ── State ────────────────────────────────────────────────────────

    let chart        = null;
    let allPoints    = [];   // full flat list of { x (fractional day index), y, label, tooltipLines }
    let allDates     = [];   // the integer day-boundary dates for axis reference
    let today        = null;
    let scrollOffset = 0;    // in days

    // ── Bundle consecutive same-status events ────────────────────────
    //
    // Input:  [ { status, score_delta }, ... ]
    // Output: [ { status, count, score_delta }, ... ]
    // Rule:   only consecutive same-status events within a day bundle together

    function bundleEvents(events) {
        const bundles = [];
        for (const ev of events) {
            const last = bundles[bundles.length - 1];
            if (last && last.status === ev.status) {
                last.count       += 1;
                last.score_delta += ev.score_delta;
            } else {
                bundles.push({ status: ev.status, count: 1, score_delta: ev.score_delta });
            }
        }
        return bundles;
    }

    // ── Build the full point list ────────────────────────────────────
    //
    // Strategy:
    // 1. Build a lookup of score at each day-anchor from score history
    // 2. Build a lookup of raw events per day from raw events
    // 3. For each day boundary, emit the anchor point
    // 4. Between two anchor points (day N and day N+1), spread the
    //    bundles of day N's events as sub-points evenly across [N, N+1)
    //
    // x-axis uses fractional day indices: day 0 = index 0, day 1 = index 1,
    // a sub-point halfway between day 0 and day 1 = index 0.5

    function buildFullData(scoreHistory, rawEvents) {
        today = new Date();
        today.setHours(0, 0, 0, 0);

        // Index score history: username → date → cumulative score
        const scoreByUserDate = {};
        scoreHistory.forEach(user => {
            scoreByUserDate[user.username] = {};
            user.points.forEach(p => {
                scoreByUserDate[user.username][p.date] = p.score;
            });
        });

        // Index raw events: username → date → [ { status, score_delta } ]
        const eventsByUserDate = {};
        rawEvents.forEach(user => {
            eventsByUserDate[user.username] = {};
            user.events.forEach(ev => {
                if (!eventsByUserDate[user.username][ev.date]) {
                    eventsByUserDate[user.username][ev.date] = [];
                }
                eventsByUserDate[user.username][ev.date].push({
                    status:      ev.status,
                    score_delta: ev.score_delta,
                });
            });
        });

        // Find global earliest date across all users (the zero-point day)
        let earliest = null;
        scoreHistory.forEach(user => {
            if (user.points.length === 0) return;
            const first = parseDate(user.points[0].date);
            if (!earliest || first < earliest) earliest = first;
        });

        if (!earliest) return false;

        // Build the day-boundary axis: earliest → today (min 7 days)
        const minEnd  = addDays(earliest, VIEWPORT_DAYS - 1);
        const axisEnd = today > minEnd ? today : minEnd;

        allDates = [];
        let cursor = new Date(earliest);
        while (cursor <= axisEnd) {
            allDates.push(new Date(cursor));
            cursor = addDays(cursor, 1);
        }

        // For each user, build their flat point list
        // allPoints will hold one dataset per user
        const datasets = [];

        scoreHistory.forEach((user, userIndex) => {
            const username    = user.username;
            const scoreLookup = scoreByUserDate[username] ?? {};
            const eventsLookup = eventsByUserDate[username] ?? {};

            // Carry score forward across all days
            const dailyScore = {};
            let lastScore = 0;
            allDates.forEach(date => {
                const str = toDateStr(date);
                if (scoreLookup[str] !== undefined) lastScore = scoreLookup[str];
                dailyScore[str] = lastScore;
            });

            // Build flat point list: x is fractional day index, y is score
            // Each point carries tooltip lines for hover display
            const points = []; // { x, y, tooltipLines }

            allDates.forEach((date, dayIndex) => {
                if (date > today) return;

                const dateStr   = toDateStr(date);
                const anchorY   = dailyScore[dateStr] ?? 0;  // end-of-day score
                const dayEvents = eventsLookup[dateStr] ?? [];
                const bundles   = bundleEvents(dayEvents);

                // Sub-points: spread within (dayIndex-1, dayIndex].
                // The LAST sub-point lands exactly on the day grid line (x = dayIndex)
                // and serves as the anchor — no separate anchor point needed.
                // If there are no bundles, emit a plain anchor at the grid line.
                if (bundles.length > 0 && dayIndex > 0) {
                    const totalDelta = bundles.reduce((sum, b) => sum + b.score_delta, 0);
                    let runningScore = anchorY - totalDelta;

                    // Step = 1/n so points land at 1/n, 2/n, ..., n/n=1 (grid line)
                    const step = 1 / bundles.length;

                    bundles.forEach((bundle, bi) => {
                        runningScore += bundle.score_delta;
                        const subX = (dayIndex - 1) + step * (bi + 1);
                        const sign = bundle.score_delta >= 0 ? "+" : "";
                        points.push({
                            x: subX,
                            y: runningScore,
                            tooltipLines: [
                                `${username}: ${runningScore} pts`,
                                `${sign}${bundle.count} ${bundle.status}`,
                            ],
                        });
                    });
                } else {
                    // No events this day — plain anchor at the grid line
                    points.push({
                        x:            dayIndex,
                        y:            anchorY,
                        tooltipLines: [`${username}: ${anchorY} pts`],
                    });
                }
            });

            const color = USER_COLORS[userIndex % USER_COLORS.length];

            datasets.push({
                username,
                color,
                points,
            });
        });

        return datasets;
    }

    // ── Get the viewport slice ───────────────────────────────────────
    //
    // We filter points whose x falls within [scrollOffset, scrollOffset + VIEWPORT_DAYS]
    // and remap x to start at 0 for the visible window.
    // Labels are the day-boundary dates in the window.

    function getViewport(datasets) {
        const xMin = scrollOffset;
        const xMax = scrollOffset + VIEWPORT_DAYS;

        // Build x-axis labels for integer positions in the window
        const labels = [];
        for (let i = xMin; i <= xMax; i++) {
            const date = allDates[i];
            labels.push(date ? formatLabel(date) : "");
        }

        const chartDatasets = datasets.map(ds => {
            // Keep points within the viewport (with a small margin for points
            // exactly on the boundary)
            const visible = ds.points.filter(p => p.x >= xMin - 0.01 && p.x <= xMax + 0.01);

            return {
                label:                ds.username,
                data:                 visible.map(p => ({ x: p.x - xMin, y: p.y, _tooltip: p.tooltipLines })),
                borderColor:          ds.color,
                backgroundColor:      ds.color,
                pointBackgroundColor: ds.color,
                pointRadius:          5,
                pointHoverRadius:     7,
                tension:              0,
                spanGaps:             false,
                parsing:              false, // we supply {x,y} objects directly
            };
        });

        return { labels, chartDatasets };
    }

    // ── Scrollbar ────────────────────────────────────────────────────

    const track = document.getElementById("chart-scrollbar-track");
    const thumb = document.getElementById("chart-scrollbar-thumb");

    function updateScrollbar() {
        const total     = allDates.length;
        const maxOffset = Math.max(0, total - VIEWPORT_DAYS);
        const thumbPct  = (VIEWPORT_DAYS / total) * 100;
        thumb.style.width = thumbPct + "%";
        const leftPct = maxOffset > 0
            ? (scrollOffset / maxOffset) * (100 - thumbPct)
            : 0;
        thumb.style.left = leftPct + "%";
    }

    function updateLabels() {
        const start = allDates[scrollOffset];
        const end   = allDates[Math.min(scrollOffset + VIEWPORT_DAYS - 1, allDates.length - 1)];
        document.getElementById("chart-label-left").textContent  = start ? formatLabel(start) : "";
        document.getElementById("chart-label-right").textContent = end   ? formatLabel(end)   : "";
    }

    let dragStartX      = null;
    let dragStartOffset = null;

    thumb.addEventListener("mousedown", e => {
        dragStartX      = e.clientX;
        dragStartOffset = scrollOffset;
        thumb.classList.add("dragging");
        e.preventDefault();
    });

    document.addEventListener("mousemove", e => {
        if (dragStartX === null) return;
        const total      = allDates.length;
        const maxOffset  = Math.max(0, total - VIEWPORT_DAYS);
        const trackWidth = track.getBoundingClientRect().width;
        const thumbPct   = VIEWPORT_DAYS / total;
        const pxPerDay   = trackWidth * (1 - thumbPct) / (maxOffset || 1);
        const daysDragged = Math.round((e.clientX - dragStartX) / pxPerDay);
        scrollOffset = Math.max(0, Math.min(maxOffset, dragStartOffset + daysDragged));
        applyScroll();
    });

    document.addEventListener("mouseup", () => {
        if (dragStartX === null) return;
        dragStartX = null;
        thumb.classList.remove("dragging");
    });

    track.addEventListener("click", e => {
        if (e.target === thumb) return;
        const total     = allDates.length;
        const maxOffset = Math.max(0, total - VIEWPORT_DAYS);
        const rect      = track.getBoundingClientRect();
        const clickPct  = (e.clientX - rect.left) / rect.width;
        scrollOffset = Math.max(0, Math.min(maxOffset, Math.round(clickPct * maxOffset)));
        applyScroll();
    });

    // ── Render ───────────────────────────────────────────────────────

    let builtDatasets = null;

    function applyScroll() {
        if (!builtDatasets || !chart) return;
        const { labels, chartDatasets } = getViewport(builtDatasets);
        chart.data.labels   = labels;
        chart.data.datasets = chartDatasets;
        chart.update("none");
        updateScrollbar();
        updateLabels();
    }

    // ── Main ─────────────────────────────────────────────────────────

    Promise.all([
        fetch("/ben/api/get-score-history.php").then(r => { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); }),
        fetch("/ben/api/get-raw-events.php").then(r => { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); }),
    ])
        .then(([scoreHistory, rawEvents]) => {
            if (scoreHistory.length === 0) {
                document.getElementById("chart-status").textContent = "No score history yet.";
                return;
            }

            builtDatasets = buildFullData(scoreHistory, rawEvents);
            if (!builtDatasets) {
                document.getElementById("chart-status").textContent = "No data to display.";
                return;
            }

            // Default scroll: today at the right edge
            scrollOffset = Math.max(0, allDates.length - VIEWPORT_DAYS);

            const ctx = document.getElementById("scoreChart").getContext("2d");
            chart = new Chart(ctx, {
                type: "line",
                data: { labels: [], datasets: [] },
                options: {
                    responsive: true,
                    animation:  { duration: 150 },
                    interaction: { mode: "nearest", intersect: true },
                    plugins: {
                        legend: {
                            labels: { color: "#f3f4f6" },
                        },
                        tooltip: {
                            backgroundColor: "#1f2937",
                            borderColor:     "#374151",
                            borderWidth:     1,
                            titleColor:      "#f3f4f6",
                            bodyColor:       "#d1d5db",
                            padding:         10,
                            callbacks: {
                                title() { return ""; }, // date is in the point label already
                                label(item) {
                                    return item.raw._tooltip ?? [];
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            type:   "linear",
                            min:    0,
                            max:    VIEWPORT_DAYS,
                            ticks: {
                                color:      "#9ca3af",
                                stepSize:   1,
                                // Only show labels at integer positions (day boundaries)
                                callback(value) {
                                    if (!Number.isInteger(value)) return "";
                                    const labels = this.chart.data.labels;
                                    return labels[value] ?? "";
                                },
                            },
                            grid: { color: "#374151" },
                        },
                        y: {
                            beginAtZero: true,
                            ticks: { color: "#9ca3af" },
                            grid:  { color: "#374151" },
                            title: {
                                display: true,
                                text:    "Score",
                                color:   "#9ca3af",
                            },
                        },
                    },
                },
            });

            applyScroll();

            if (allDates.length > VIEWPORT_DAYS) {
                track.style.display = "block";
            }
        })
        .catch(err => {
            document.getElementById("chart-status").textContent =
                "Could not load chart data. (" + err.message + ")";
        });

}());
</script>