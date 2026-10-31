/**
 * Shared chart parameters. Categorical slots validated with the dataviz palette
 * checker against the white card surface (adjacent CVD ΔE ≥ 9.2, normal-vision ≥ 27.6).
 * Slot 3 is under 3:1 contrast, so every chart ships a legend and a data-table view.
 * Use slots in this order; never more than three series in one chart.
 */
export const SERIES_COLORS = ['#1769D2', '#EB6834', '#1BAF7A'];
export const MUTED_FILL = '#94A3B8';

export const CHART_INK = {
    grid: '#EEF2F6',
    axis: '#CBD5E1',
    label: '#64748B',
};

/** Round a maximum up to a readable axis top, with 4 equal steps */
export function niceScale(max, steps = 4) {
    if (!max || max <= 0) return { top: steps, ticks: Array.from({ length: steps + 1 }, (_, i) => i) };
    const raw = max / steps;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const norm = raw / mag;
    const step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * mag;
    return { top: step * steps, ticks: Array.from({ length: steps + 1 }, (_, i) => i * step) };
}

/** Indices to label on the x axis: about `max` evenly spaced, always the last */
export function xTickIndices(n, max = 6) {
    if (n <= max) return Array.from({ length: n }, (_, i) => i);
    const step = Math.ceil((n - 1) / (max - 1));
    const out = [];
    for (let i = n - 1; i >= 0; i -= step) out.unshift(i);
    return out;
}

/** Rectangle with only its top corners rounded (the bar's data end) */
export function roundedTopRect(x, y, w, h, r = 4) {
    if (h <= 0 || w <= 0) return '';
    const rr = Math.min(r, w / 2, h);
    return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}
