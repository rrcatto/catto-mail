/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import {
    Chart, BarController, BarElement, CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, ScatterController, Tooltip,
} from 'chart.js';

/*
 * Draws a dashboard chart with Chart.js (vendored through the import map, served by the
 * application itself). The server renders the data as JSON in a data attribute; the
 * canvas carries a text alternative and the figures are also on the page, so the chart
 * is an enhancement: without JavaScript nothing is lost but the picture.
 *
 *   <canvas data-controller="chart" data-chart-kind-value="flow" data-chart-config-value="{…}"></canvas>
 *
 * Kinds: "trend" (KPI line), "flow" (messages per bucket), "scatter" (send jobs by
 * hard-bounce rate), "band" (typical to peak submissions per minute, against the ceiling). Colours follow assets/styles/app.css. Loaded lazily: pages without a
 * chart never download Chart.js.
 */
Chart.register(BarController, BarElement, CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, ScatterController, Tooltip);

const C = { ink: '#0E1411', muted: '#66726B', grid: '#EEF3EF', green: '#0E8A44', dark: '#0B6B35', neon: '#22D56B', line: '#CFE3D6', bad: '#C4202F' };
const number = new Intl.NumberFormat('en-US');
/* Dates in the dashboard's time zone (APP_TIMEZONE, sent with the chart data). */
const dates = (timeZone) => ({
    day: new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone }),
    dayTime: new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone }),
    hour: new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', timeZone }),
});

function hatch(stroke, fill, width = 2.2) {
    const tile = document.createElement('canvas');
    tile.width = 8;
    tile.height = 8;
    const g = tile.getContext('2d');
    g.fillStyle = fill;
    g.fillRect(0, 0, 8, 8);
    g.strokeStyle = stroke;
    g.lineWidth = width;
    g.beginPath();
    g.moveTo(-1, 9); g.lineTo(9, -1);
    g.moveTo(-1, 1); g.lineTo(1, -1);
    g.moveTo(7, 9); g.lineTo(9, 7);
    g.stroke();
    return g.createPattern(tile, 'repeat');
}

function pill(ctx, x, y, text, { fill = '#FFFFFF', border = '#DCE6DF', color = C.ink } = {}) {
    ctx.save();
    ctx.font = `600 12px ${Chart.defaults.font.family}`;
    const w = ctx.measureText(text).width + 16;
    const h = 22;
    const left = Math.max(2, Math.min(x - w / 2, ctx.canvas.clientWidth - w - 2));
    ctx.beginPath();
    ctx.roundRect(left, y - h, w, h, h / 2);
    ctx.fillStyle = fill;
    ctx.fill();
    ctx.strokeStyle = border;
    ctx.stroke();
    ctx.fillStyle = color;
    ctx.textBaseline = 'middle';
    ctx.fillText(text, left + 8, y - h / 2 + 0.5);
    ctx.restore();
}

/* The busiest bucket of a flow chart gets its value above the bar. */
const valueTag = {
    id: 'valueTag',
    afterDatasetsDraw(chart, _args, options) {
        if (options.index === null || options.index === undefined) {
            return;
        }
        const bar = chart.getDatasetMeta(0).data[options.index];
        if (bar) {
            pill(chart.ctx, bar.x, bar.y - 8, options.text);
        }
    },
};

/* A dashed threshold line across the plot, with its label at the right. */
const threshold = {
    id: 'threshold',
    afterDraw(chart, _args, options) {
        if (!options.value) {
            return;
        }
        const { ctx, chartArea: area, scales: { y } } = chart;
        const py = y.getPixelForValue(options.value);
        if (py < area.top || py > area.bottom) {
            return;
        }
        const colour = options.colour || C.ink;
        ctx.save();
        ctx.setLineDash([6, 5]);
        ctx.lineWidth = 2;
        ctx.strokeStyle = colour;
        ctx.beginPath();
        ctx.moveTo(area.left, py);
        ctx.lineTo(area.right, py);
        ctx.stroke();
        ctx.restore();
        pill(ctx, area.right - 70, py - 4, options.label, { fill: colour, border: colour, color: '#FFFFFF' });
    },
};

/* KPI lines: a dashed marker and a dot at the newest value. */
const lastPoint = {
    id: 'lastPoint',
    afterDatasetsDraw(chart) {
        const points = chart.getDatasetMeta(0).data;
        const values = chart.data.datasets[0].data;
        let i = values.length - 1;
        while (i >= 0 && (values[i] === null || values[i] === undefined)) {
            i -= 1;
        }
        if (i < 0 || !points[i]) {
            return;
        }
        const { ctx, chartArea: area } = chart;
        const { x, y } = points[i];
        ctx.save();
        ctx.setLineDash([3, 3]);
        ctx.strokeStyle = '#C5D3CA';
        ctx.beginPath();
        ctx.moveTo(x, area.top);
        ctx.lineTo(x, area.bottom);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.beginPath();
        ctx.arc(x, y, 4.5, 0, Math.PI * 2);
        ctx.fillStyle = '#FFFFFF';
        ctx.fill();
        ctx.lineWidth = 2;
        ctx.strokeStyle = C.green;
        ctx.stroke();
        ctx.restore();
    },
};

const tooltip = {
    backgroundColor: C.ink, titleColor: '#FFFFFF', bodyColor: '#D9E2DC', padding: 10, cornerRadius: 12, displayColors: false,
    titleFont: { weight: '600' },
};

export default class extends Controller {
    static values = { kind: String, config: Object };

    connect() {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = C.muted;
        Chart.defaults.animation = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 };
        const ready = document.fonts ? document.fonts.ready : Promise.resolve();
        const build = { trend: this.trend, flow: this.flow, scatter: this.scatter, band: this.band }[this.kindValue];
        ready.then(() => {
            if (build && this.element.isConnected && !this.chart) {
                this.chart = new Chart(this.element, build.call(this, this.configValue));
            }
        });
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
    }

    trend(c) {
        const percent = c.format === 'percent';
        return {
            type: 'line',
            data: { labels: c.labels, datasets: [{ data: c.values, borderColor: C.green, borderWidth: 2, backgroundColor: 'rgba(34,213,107,0.10)', fill: 'origin', tension: 0.4, pointRadius: 0, pointHoverRadius: 3, spanGaps: true }] },
            options: {
                maintainAspectRatio: false,
                layout: { padding: { top: 6, bottom: 2, right: 6 } },
                scales: { x: { display: false }, y: { display: false, beginAtZero: !percent } },
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: { ...tooltip, callbacks: { label: (i) => (i.raw === null ? 'no mail' : (percent ? `${i.raw.toFixed(2)} %` : number.format(i.raw))) } } },
            },
            plugins: [lastPoint],
        };
    }

    flow(c) {
        const plain = hatch(C.green, '#FFFFFF');
        const busy = hatch(C.green, C.neon);
        const hi = c.highlight;
        return {
            type: 'bar',
            data: {
                labels: c.labels,
                datasets: [{
                    data: c.totals,
                    backgroundColor: c.totals.map((_, i) => (i === hi ? busy : plain)),
                    borderColor: c.totals.map((_, i) => (i === hi ? C.dark : C.line)),
                    borderWidth: c.totals.map((_, i) => (i === hi ? 2 : 1)),
                    borderRadius: 999,
                    borderSkipped: false,
                    categoryPercentage: 0.82,
                    barPercentage: 0.9,
                    maxBarThickness: 34,
                    minBarLength: 3,
                }],
            },
            options: {
                maintainAspectRatio: false,
                layout: { padding: { top: 30 } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                    y: { beginAtZero: true, grid: { color: C.grid }, border: { display: false }, ticks: { maxTicksLimit: 5, precision: 0, callback: (v) => (v >= 1000 ? `${number.format(v / 1000)}k` : number.format(v)) } },
                },
                plugins: {
                    legend: { display: false },
                    valueTag: { index: hi, text: hi === null ? '' : `${c.labels[hi]} · ${number.format(c.totals[hi])}` },
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            label: (i) => `${number.format(i.raw)} messages`,
                            afterLabel: (i) => [
                                `Remote accepted ${number.format(c.accepted[i.dataIndex])}`,
                                `Deferred or soft bounced ${number.format(c.deferredSoft[i.dataIndex])}`,
                                `Hard bounced or complained ${number.format(c.bad[i.dataIndex])}`,
                                `Suppressed ${number.format(c.suppressed[i.dataIndex])}`,
                            ],
                        },
                    },
                },
            },
            plugins: [valueTag],
        };
    }

    band(c) {
        const plain = hatch(C.green, '#FFFFFF', 2.6);
        const busy = hatch(C.green, C.neon, 2.6);
        const hi = c.highlight;
        const data = c.labels.map((_, i) => (c.peak[i] === null ? null : [Math.max(0, c.typical[i] ?? 0), c.peak[i]]));
        const highest = Math.max(1, ...c.peak.filter((v) => v !== null));
        return {
            type: 'bar',
            data: {
                labels: c.labels,
                datasets: [{
                    data,
                    backgroundColor: data.map((_, i) => (i === hi ? busy : plain)),
                    borderColor: data.map((_, i) => (i === hi ? C.dark : C.line)),
                    borderWidth: data.map((_, i) => (i === hi ? 2 : 1)),
                    borderRadius: 999,
                    borderSkipped: false,
                    categoryPercentage: 0.82,
                    barPercentage: 0.8,
                    maxBarThickness: 30,
                    minBarLength: 8,
                }],
            },
            options: {
                maintainAspectRatio: false,
                layout: { padding: { top: 30 } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                    y: {
                        beginAtZero: true, suggestedMax: Math.max(highest * 1.2, (c.ceiling || 0) * 1.12), grid: { color: C.grid }, border: { display: false },
                        ticks: { maxTicksLimit: 5, precision: 0, callback: (v) => `${number.format(v)}/min` },
                    },
                },
                plugins: {
                    legend: { display: false },
                    valueTag: { index: hi, text: hi === null ? '' : `${c.labels[hi]} · peak ${number.format(c.peak[hi])}/min` },
                    threshold: { value: c.ceiling, label: `rate ceiling ${c.ceiling}/min`, colour: C.bad },
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            title: (items) => c.titles[items[0].dataIndex],
                            label: (i) => `Typical ${number.format(c.typical[i.dataIndex])}/min · peak ${number.format(c.peak[i.dataIndex])}/min`,
                        },
                    },
                },
            },
            plugins: [valueTag, threshold],
        };
    }

    scatter(c) {
        const highest = Math.max(0, ...c.series.flatMap((s) => s.points.map((p) => p.y)));
        // A rate never exceeds 100 %: once the scale would reach it, 100 % is the top.
        const top = Math.max(c.warning * 1.6, highest * 1.15);
        const { day, dayTime, hour } = dates(c.timeZone || 'UTC');
        const ticks = c.hourly ? hour : day;
        return {
            type: 'scatter',
            data: {
                datasets: c.series.map((s) => ({
                    label: s.label, data: s.points, pointStyle: 'rect', pointRadius: 6, pointHoverRadius: 8,
                    backgroundColor: s.colour, borderColor: s.edge, borderWidth: 1, clip: 10,
                })),
            },
            options: {
                maintainAspectRatio: false,
                layout: { padding: { top: 10, right: 8 } },
                scales: {
                    x: { type: 'linear', min: c.min, max: c.max, grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 6, maxRotation: 0, callback: (v) => ticks.format(new Date(v)) } },
                    y: { beginAtZero: true, ...(top >= 80 ? { max: 100 } : { suggestedMax: top }), grid: { color: C.grid }, border: { display: false }, ticks: { maxTicksLimit: 6, callback: (v) => `${v} %` } },
                },
                plugins: {
                    legend: { display: false },
                    threshold: { value: c.warning, label: `warning ${c.warning} %` },
                    tooltip: {
                        ...tooltip,
                        callbacks: {
                            title: (items) => dayTime.format(new Date(items[0].raw.x)),
                            label: (i) => `${i.raw.client}: ${i.raw.y.toFixed(2)} % of ${number.format(i.raw.n)}`,
                            afterLabel: (i) => `Job ${i.raw.ref}`,
                        },
                    },
                },
            },
            plugins: [threshold],
        };
    }
}
