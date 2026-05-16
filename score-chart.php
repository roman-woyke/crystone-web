<?php
// includes/score-chart.php
// Renders the score evolution chart below the leaderboard table.
// Fetches data from /api/get-score-history.php and /api/get-raw-events.php
?>

<div id="score-chart-section" style="margin-top: 40px;">
    <h2>Score Evolution</h2>
    <canvas id="scoreChart" height="120"></canvas>

    <p id="chart-status" style="color: #9ca3af; font-size: 0.9rem;"></p>
</div>

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

    let chart     = null;
    let allDates  = [];   // the integer day-boundary dates for axis reference
    let today     = null;

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

        // Build the day-boundary axis: earliest → today
        allDates = [];
        let cursor = new Date(earliest);
        while (cursor <= today) {
            allDates.push(new Date(cursor));
            cursor = addDays(cursor, 1);
        }

        // For each user, build their flat point list
        const datasets = [];

        scoreHistory.forEach((user, userIndex) => {
            const username     = user.username;
            const scoreLookup  = scoreByUserDate[username] ?? {};
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
            const points = []; // { x, y, tooltipLines }

            allDates.forEach((date, dayIndex) => {
                if (date > today) return;

                const dateStr   = toDateStr(date);
                const anchorY   = dailyScore[dateStr] ?? 0;
                const dayEvents = eventsLookup[dateStr] ?? [];
                const bundles   = bundleEvents(dayEvents);

                // Sub-points: spread within (dayIndex-1, dayIndex].
                // The LAST sub-point lands exactly on the day grid line (x = dayIndex)
                // and serves as the anchor — no separate anchor point needed.
                // If there are no bundles, emit a plain anchor at the grid line.
                if (bundles.length > 0 && dayIndex > 0) {
                    const totalDelta = bundles.reduce((sum, b) => sum + b.score_delta, 0);
                    let runningScore = anchorY - totalDelta;

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
                    points.push({
                        x:            dayIndex,
                        y:            anchorY,
                        tooltipLines: [`${username}: ${anchorY} pts`],
                    });
                }
            });

            const color = USER_COLORS[userIndex % USER_COLORS.length];

            datasets.push({
                label:                username,
                data:                 points.map(p => ({ x: p.x, y: p.y, _tooltip: p.tooltipLines })),
                borderColor:          color,
                backgroundColor:      color,
                pointBackgroundColor: color,
                pointRadius:          5,
                pointHoverRadius:     7,
                tension:              0,
                spanGaps:             false,
                parsing:              false,
            });
        });

        return datasets;
    }

    // ── Main ─────────────────────────────────────────────────────────

    Promise.all([
        fetch("/roman/api/get-score-history.php").then(r => { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); }),
        fetch("/roman/api/get-raw-events.php").then(r => { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); }),
    ])
        .then(([scoreHistory, rawEvents]) => {
            if (scoreHistory.length === 0) {
                document.getElementById("chart-status").textContent = "No score history yet.";
                return;
            }

            const chartDatasets = buildFullData(scoreHistory, rawEvents);
            if (!chartDatasets) {
                document.getElementById("chart-status").textContent = "No data to display.";
                return;
            }

            // Build x-axis labels for all day boundaries
            const labels = allDates.map(d => formatLabel(d));

            const ctx = document.getElementById("scoreChart").getContext("2d");
            chart = new Chart(ctx, {
                type: "line",
                data: { labels, datasets: chartDatasets },
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
                                title() { return ""; },
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
                            max:    allDates.length, // number of data days + 1 padding
                            ticks: {
                                color:    "#9ca3af",
                                stepSize: 1,
                                callback(value) {
                                    if (!Number.isInteger(value)) return "";
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
        })
        .catch(err => {
            document.getElementById("chart-status").textContent =
                "Could not load chart data. (" + err.message + ")";
        });

}());
</script>
