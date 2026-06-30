"use client";

import { useEffect, useRef, useState } from "react";
import {
  Chart,
  type ChartDataset,
  type TooltipItem,
  LineController,
  LineElement,
  PointElement,
  LinearScale,
  Tooltip,
  Legend,
} from "chart.js";

Chart.register(LineController, LineElement, PointElement, LinearScale, Tooltip, Legend);

const USER_COLORS = [
  "#8b5cf6", // violet
  "#3b82f6", // blue
  "#34d399", // green
  "#fbbf24", // amber
  "#f472b6", // pink
  "#38bdf8", // sky
  "#fb923c", // orange
  "#f87171", // red
];

type ScorePoint = { date: string; score: number };
type ScoreHistoryUser = { username: string; points: ScorePoint[] };
type RawEvent = { date: string; status: string; score_delta: number };
type RawEventsUser = { username: string; events: RawEvent[] };

type ChartPoint = { x: number; y: number; _tooltip: string[] };

function hexToRgba(hex: string, alpha: number): string {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function gradientStroke(context: { chart: Chart }, color: string) {
  const { ctx, chartArea } = context.chart;
  if (!chartArea) return color;
  const g = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
  g.addColorStop(0, hexToRgba(color, 0.65));
  g.addColorStop(1, color);
  return g;
}

function gradientFill(context: { chart: Chart }, color: string) {
  const { ctx, chartArea } = context.chart;
  if (!chartArea) return hexToRgba(color, 0.08);
  const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
  g.addColorStop(0, hexToRgba(color, 0.16));
  g.addColorStop(1, hexToRgba(color, 0));
  return g;
}

function parseDate(str: string): Date {
  const [y, m, d] = str.split("-").map(Number);
  return new Date(y, m - 1, d);
}

function addDays(date: Date, n: number): Date {
  const d = new Date(date);
  d.setDate(d.getDate() + n);
  return d;
}

function toDateStr(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function formatLabel(date: Date): string {
  return date.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

// Only consecutive same-status events within a day bundle together.
function bundleEvents(events: RawEvent[]) {
  const bundles: { status: string; count: number; score_delta: number }[] = [];
  for (const ev of events) {
    const last = bundles[bundles.length - 1];
    if (last && last.status === ev.status) {
      last.count += 1;
      last.score_delta += ev.score_delta;
    } else {
      bundles.push({ status: ev.status, count: 1, score_delta: ev.score_delta });
    }
  }
  return bundles;
}

function buildFullData(scoreHistory: ScoreHistoryUser[], rawEvents: RawEventsUser[]) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const scoreByUserDate = new Map<string, Map<string, number>>();
  for (const user of scoreHistory) {
    const m = new Map<string, number>();
    for (const p of user.points) m.set(p.date, p.score);
    scoreByUserDate.set(user.username, m);
  }

  const eventsByUserDate = new Map<string, Map<string, RawEvent[]>>();
  for (const user of rawEvents) {
    const m = new Map<string, RawEvent[]>();
    for (const ev of user.events) {
      const list = m.get(ev.date) ?? [];
      list.push(ev);
      m.set(ev.date, list);
    }
    eventsByUserDate.set(user.username, m);
  }

  let earliest: Date | null = null;
  for (const user of scoreHistory) {
    if (user.points.length === 0) continue;
    const first = parseDate(user.points[0].date);
    if (!earliest || first < earliest) earliest = first;
  }

  if (!earliest) return null;

  const allDates: Date[] = [];
  let cursor = new Date(earliest);
  while (cursor <= today) {
    allDates.push(new Date(cursor));
    cursor = addDays(cursor, 1);
  }

  const datasets: ChartDataset<"line", ChartPoint[]>[] = [];

  scoreHistory.forEach((user, userIndex) => {
    const username = user.username;
    const scoreLookup = scoreByUserDate.get(username) ?? new Map<string, number>();
    const eventsLookup = eventsByUserDate.get(username) ?? new Map<string, RawEvent[]>();

    const dailyScore = new Map<string, number>();
    let lastScore = 0;
    for (const date of allDates) {
      const str = toDateStr(date);
      if (scoreLookup.has(str)) lastScore = scoreLookup.get(str)!;
      dailyScore.set(str, lastScore);
    }

    const points: ChartPoint[] = [];

    allDates.forEach((date, dayIndex) => {
      if (date > today) return;

      const dateStr = toDateStr(date);
      const anchorY = dailyScore.get(dateStr) ?? 0;
      const dayEvents = eventsLookup.get(dateStr) ?? [];
      const bundles = bundleEvents(dayEvents);

      if (bundles.length > 0 && dayIndex > 0) {
        const totalDelta = bundles.reduce((sum, b) => sum + b.score_delta, 0);
        let runningScore = anchorY - totalDelta;
        const step = 1 / bundles.length;

        bundles.forEach((bundle, bi) => {
          runningScore += bundle.score_delta;
          const subX = dayIndex - 1 + step * (bi + 1);
          const sign = bundle.score_delta >= 0 ? "+" : "";
          points.push({
            x: subX,
            y: runningScore,
            _tooltip: [`${username}: ${runningScore} pts`, `${sign}${bundle.count} ${bundle.status}`],
          });
        });
      } else {
        points.push({ x: dayIndex, y: anchorY, _tooltip: [`${username}: ${anchorY} pts`] });
      }
    });

    const color = USER_COLORS[userIndex % USER_COLORS.length];

    datasets.push({
      label: username,
      data: points,
      borderColor: (context) => gradientStroke(context, color),
      borderWidth: 2.5,
      backgroundColor: (context) => gradientFill(context, color),
      fill: "origin",
      pointBackgroundColor: color,
      pointBorderColor: "rgba(255, 255, 255, 0.9)",
      pointBorderWidth: 1.5,
      pointRadius: 3.5,
      pointHoverRadius: 6,
      tension: 0,
      spanGaps: false,
      parsing: false,
    });
  });

  return { datasets, allDates };
}

export function ScoreChart() {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const chartRef = useRef<Chart | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      fetch("/api/score-history").then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      }),
      fetch("/api/raw-events").then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      }),
    ])
      .then(([scoreHistory, rawEvents]: [ScoreHistoryUser[], RawEventsUser[]]) => {
        if (cancelled) return;

        if (scoreHistory.length === 0) {
          setStatus("No score history yet.");
          return;
        }

        const built = buildFullData(scoreHistory, rawEvents);
        if (!built || !canvasRef.current) {
          setStatus("No data to display.");
          return;
        }

        const { datasets, allDates } = built;
        const labels = allDates.map((d) => formatLabel(d));
        const tickStep = Math.max(1, Math.ceil(allDates.length / 12));

        chartRef.current?.destroy();
        chartRef.current = new Chart(canvasRef.current, {
          type: "line",
          data: { labels, datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 150 },
            interaction: { mode: "nearest", intersect: true },
            plugins: {
              legend: {
                labels: { usePointStyle: true, pointStyle: "circle", padding: 18 },
              },
              tooltip: {
                padding: 12,
                cornerRadius: 10,
                callbacks: {
                  title: () => "",
                  label: (item: TooltipItem<"line">) =>
                    (item.raw as ChartPoint)._tooltip ?? [],
                },
              },
            },
            scales: {
              x: {
                type: "linear",
                min: 0,
                max: allDates.length,
                ticks: {
                  stepSize: tickStep,
                  callback: (value) => (Number.isInteger(value) ? (labels[value as number] ?? "") : ""),
                },
                grid: { color: "rgba(255, 255, 255, 0.05)" },
              },
              y: {
                beginAtZero: true,
                grid: { color: "rgba(255, 255, 255, 0.05)" },
                title: { display: true, text: "Score" },
              },
            },
          },
        });
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setStatus(`Could not load chart data. (${error instanceof Error ? error.message : "error"})`);
        }
      });

    return () => {
      cancelled = true;
      chartRef.current?.destroy();
      chartRef.current = null;
    };
  }, []);

  return (
    <div className="rounded-xl border p-6">
      <h2 className="mb-4 text-lg font-semibold">Score Evolution</h2>
      <div className="relative min-h-[360px] w-full">
        <canvas ref={canvasRef} />
      </div>
      {status && <p className="mt-2 text-sm text-muted-foreground">{status}</p>}
    </div>
  );
}
