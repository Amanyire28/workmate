/**
 * Small helpers shared by the module definitions in this folder.
 */
import { reactive } from 'vue';
import { categories, AREAS, onReload } from '../data/liveData.js';
import { auth } from '@/stores/auth.js';
import { formatNumber, formatUGXCompact, formatPercent } from '../utils/format.js';
import { statusLabel } from '../utils/statuses.js';

export { statusOptions } from '../utils/statuses.js';
export { formatNumber, formatUGX, formatUGXCompact, formatPercent, formatDateTime, formatRelative, formatAge } from '../utils/format.js';

/** The signed-in administrator's name, for "assigned to me" and decision records */
export const me = () => auth.user?.name || 'Administrator';

export const DAY = 86400000;
export const within = (iso, days) => iso && Date.now() - new Date(iso).getTime() <= days * DAY;
export const count = (rows, fn) => rows.reduce((n, r) => n + (fn(r) ? 1 : 0), 0);
export const sum = (rows, fn) => rows.reduce((n, r) => n + (fn(r) || 0), 0);
export const share = (part, whole) => (whole ? part / whole : 0);

// Filter choices from the database, kept current when the admin data reloads
export const categoryOptions = reactive([]);
export const areaOptions = reactive([]);
function syncOptions() {
    categoryOptions.splice(0, categoryOptions.length, ...categories.map(c => [c.id, c.label]));
    areaOptions.splice(0, areaOptions.length, ...AREAS.map(([a]) => [a, a]).sort((a, b) => a[0].localeCompare(b[0])));
}
syncOptions();
onReload(syncOptions);

export const categoryLabel = (id) => categories.find(c => c.id === id)?.label || id;

/** A KPI tile */
export const kpi = (label, value, extra = {}) => ({ label, value, ...extra });
export const kpiCount = (label, n, extra = {}) => kpi(label, formatNumber(n), extra);
export const kpiMoney = (label, n, extra = {}) => kpi(label, formatUGXCompact(n), extra);
export const kpiRate = (label, r, extra = {}) => kpi(label, formatPercent(r), extra);

/** Status tabs with counts: [['', 'All'], ['pending', …]] */
export function statusTabs(keys, key = 'status', first = { id: 'all', label: 'All' }) {
    return [first, ...keys.map(k => ({ id: k, label: statusLabel(k), filter: r => r[key] === k }))];
}

/**
 * An action that moves a record to another status.
 * reason: 'required' | 'optional' | false
 */
export function setStatus(id, label, to, { done, tone, when, permission, reason = false, message, icon, confirmTitle, extra } = {}) {
    return {
        id, label, done: done || label, tone, when, permission, reason, message, icon, confirmTitle,
        apply: (row, values) => ({ patch: { status: to, ...(extra ? extra(row, values) : {}) } }),
    };
}

/** History section + a note about where actions are kept */
export const historySection = { title: 'History', timeline: true };

/** Mini table for related records */
export function related(title, rows, columns, empty, limit) {
    return { title, table: { rows, columns, empty, limit } };
}
