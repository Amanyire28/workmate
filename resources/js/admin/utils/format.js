/**
 * Formatting shared by every admin page, so numbers, money and dates read the same everywhere.
 */

const nf = new Intl.NumberFormat('en-UG');

export function formatNumber(value) {
    if (value == null) return '—';
    return nf.format(Math.round(value));
}

/** "UGX 18,450,000" */
export function formatUGX(value) {
    if (value == null) return '—';
    return `UGX ${nf.format(Math.round(value))}`;
}

/** "UGX 18.5M", "UGX 940K" for cards and axis ticks */
export function formatUGXCompact(value) {
    if (value == null) return '—';
    const abs = Math.abs(value);
    if (abs >= 1e9) return `UGX ${trim(value / 1e9)}B`;
    if (abs >= 1e6) return `UGX ${trim(value / 1e6)}M`;
    if (abs >= 1e3) return `UGX ${trim(value / 1e3)}K`;
    return `UGX ${Math.round(value)}`;
}

/** 12400 → "12.4K" (axis ticks) */
export function formatCompact(value) {
    const abs = Math.abs(value);
    if (abs >= 1e6) return `${trim(value / 1e6)}M`;
    if (abs >= 1e3) return `${trim(value / 1e3)}K`;
    return `${Math.round(value)}`;
}

function trim(n) {
    return n >= 100 ? Math.round(n).toString() : n.toFixed(1).replace(/\.0$/, '');
}

/** 0.1234 → "12.3%" */
export function formatPercent(ratio, digits = 1) {
    if (ratio == null || Number.isNaN(ratio)) return '—';
    return `${(ratio * 100).toFixed(digits).replace(/\.0$/, '')}%`;
}

/** Change between two period totals; null when there is nothing to compare against */
export function percentChange(current, previous) {
    if (previous == null || previous === 0) return null;
    return (current - previous) / previous;
}

const dateFmt = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' });
const dateTimeFmt = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** "14 Oct" */
export function formatDay(date) {
    return dateFmt.format(new Date(date));
}

/** "14 Oct 2026, 09:42" */
export function formatDateTime(date) {
    return dateTimeFmt.format(new Date(date));
}

/** "Just now", "12 min ago", "3 h ago", "2 days ago" */
export function formatRelative(date, now = Date.now()) {
    const s = Math.round((now - new Date(date).getTime()) / 1000);
    if (s < 60) return 'Just now';
    if (s < 3600) return `${Math.round(s / 60)} min ago`;
    if (s < 86400) return `${Math.round(s / 3600)} h ago`;
    const d = Math.round(s / 86400);
    return d === 1 ? 'Yesterday' : `${d} days ago`;
}

/** "3 days", "5 h" — how long something has been waiting */
export function formatAge(date, now = Date.now()) {
    const h = Math.round((now - new Date(date).getTime()) / 3600000);
    if (h < 24) return `${Math.max(h, 1)} h`;
    const d = Math.round(h / 24);
    return `${d} ${d === 1 ? 'day' : 'days'}`;
}
