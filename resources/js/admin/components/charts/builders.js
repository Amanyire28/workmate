/**
 * Chart config builders over a series response (getSeries / getDashboard), so pages
 * describe charts in a few lines and every chart gets the same labels, tooltips,
 * colours (fixed slot order) and data-table view.
 */
import { SERIES_COLORS } from './chartUtils.js';
import { formatDay, formatNumber, formatCompact, formatPercent, formatUGX } from '../../utils/format.js';

const weekdayFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });

/** Axis labels, tooltip titles and notes for daily points and for bar buckets */
export function axes(d) {
    return {
        dayLabels: d.daily.map(r => formatDay(r.date)),
        dayTitles: d.daily.map(r => weekdayFmt.format(new Date(r.date))),
        bLabels: d.buckets.map(b => formatDay(d.size === 7 ? b.start : b.end)),
        bTitles: d.buckets.map(b => (b.days > 1 ? `${formatDay(b.start)} – ${formatDay(b.end)}` : weekdayFmt.format(new Date(b.end)))),
        bNotes: d.buckets.map(b => (b.partial ? `Partial week (${b.days} days)` : '')),
        bucketCol: { key: 'label', label: d.size === 7 ? 'Week' : 'Day' },
    };
}

const withColors = (series) => series.map((s, i) => ({ ...s, color: SERIES_COLORS[i] }));
const fmtFor = (kind) => ({
    number: { formatTick: formatCompact, formatValue: formatNumber, cell: formatNumber },
    percent: { formatTick: v => formatPercent(v, 0), formatValue: v => formatPercent(v), cell: v => formatPercent(v) },
    money: { formatTick: formatCompact, formatValue: formatUGX, cell: formatNumber },
})[kind];

/** Daily line chart. series: [{ key, label, value: row => number }] (max 3) */
export function dailyLine(d, { id, title, summary, series, unit = 'number', ariaLabel, wide }) {
    const a = axes(d);
    const f = fmtFor(unit);
    const s = withColors(series).map(x => ({ ...x, values: d.daily.map(x.value) }));
    return {
        id, kind: 'line', title, summary, wide,
        legend: s.length > 1 ? s.map(x => ({ label: x.label, color: x.color })) : [],
        props: { labels: a.dayLabels, tooltipTitles: a.dayTitles, ariaLabel: ariaLabel || title, series: s.map(({ key, label, color, values }) => ({ key, label, color, values })), formatTick: f.formatTick, formatValue: f.formatValue },
        table: { columns: [{ key: 'label', label: 'Day' }, ...s.map(x => ({ key: x.key, label: x.label, format: f.cell }))], rows: d.daily.map((r, i) => ({ label: a.dayTitles[i], ...Object.fromEntries(s.map(x => [x.key, x.values[i]])) })) },
        empty: s.every(x => x.values.every(v => !v)),
    };
}

/** Bucketed bar chart (grouped, or stacked for parts of a whole). series: [{ key, label, value: bucket => number }] */
export function bucketBars(d, { id, title, summary, series, unit = 'number', stacked = false, ariaLabel, wide }) {
    const a = axes(d);
    const f = fmtFor(unit);
    const s = withColors(series).map(x => ({ ...x, values: d.buckets.map(x.value) }));
    return {
        id, kind: 'bar', title, summary, wide,
        legend: s.map(x => ({ label: x.label, color: x.color })),
        props: { labels: a.bLabels, tooltipTitles: a.bTitles, tooltipNotes: a.bNotes, stacked, ariaLabel: ariaLabel || title, series: s.map(({ key, label, color, values }) => ({ key, label, color, values })), formatTick: f.formatTick, formatValue: f.formatValue },
        table: { columns: [a.bucketCol, ...s.map(x => ({ key: x.key, label: x.label, format: f.cell }))], rows: d.buckets.map((b, i) => ({ label: a.bTitles[i], ...Object.fromEntries(s.map(x => [x.key, x.values[i]])) })) },
        empty: s.every(x => x.values.every(v => !v)),
    };
}

/** Bucketed rate lines (ratios 0–1) */
export function bucketRates(d, { id, title, summary, series, ariaLabel, wide }) {
    const a = axes(d);
    const f = fmtFor('percent');
    const s = withColors(series).map(x => ({ ...x, values: d.buckets.map(x.value) }));
    return {
        id, kind: 'line', title, summary, wide,
        legend: s.length > 1 ? s.map(x => ({ label: x.label, color: x.color })) : [],
        props: { labels: a.bLabels, tooltipTitles: a.bTitles, ariaLabel: ariaLabel || title, series: s.map(({ key, label, color, values }) => ({ key, label, color, values })), formatTick: f.formatTick, formatValue: f.formatValue },
        table: { columns: [a.bucketCol, ...s.map(x => ({ key: x.key, label: x.label, format: f.cell }))], rows: d.buckets.map((b, i) => ({ label: a.bTitles[i], ...Object.fromEntries(s.map(x => [x.key, x.values[i]])) })) },
        empty: d.buckets.every(b => !b.jobsCreated && !b.searches),
    };
}

/** Ranked horizontal bars. rows: [{ label, value, muted? }] */
export function ranking({ id, title, summary, rows, unit = 'number', showShare = false, columnLabel = 'Value', wide }) {
    const f = fmtFor(unit);
    return {
        id, kind: 'rank', title, summary, wide,
        props: { rows, formatValue: unit === 'money' ? formatCompactMoney : f.cell, showShare, ariaLabel: title },
        table: { columns: [{ key: 'label', label: 'Name' }, { key: 'value', label: columnLabel, format: f.cell }], rows },
        empty: !rows.some(r => r.value),
    };
}

const formatCompactMoney = (v) => `UGX ${formatCompact(v)}`;

/** Two single-series panels sharing the x axis (different scales). unit: money | number */
export function moneyPanels(d, { id, title, summary, panels, unit = 'money' }) {
    const a = axes(d);
    return {
        id, kind: 'panels', title, summary, minHeight: 300,
        panels: panels.map(p => ({ title: p.label, props: {
            labels: a.dayLabels, tooltipTitles: a.dayTitles, height: 112, ariaLabel: p.label,
            series: [{ key: p.key, label: p.label, color: SERIES_COLORS[0], values: d.daily.map(p.value) }],
            formatTick: formatCompact, formatValue: unit === 'money' ? formatUGX : formatNumber,
        } })),
        table: { columns: [{ key: 'label', label: 'Day' }, ...panels.map(p => ({ key: p.key, label: unit === 'money' ? `${p.label} (UGX)` : p.label, format: formatNumber }))],
            rows: d.daily.map((r, i) => ({ label: a.dayTitles[i], ...Object.fromEntries(panels.map(p => [p.key, p.value(r)])) })) },
        empty: d.daily.every(r => panels.every(p => !p.value(r))),
    };
}
