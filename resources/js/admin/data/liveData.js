import { reactive } from 'vue';
import { api } from '@/api/client.js';

/**
 * The admin area's data, loaded from the Laravel API (GET /api/v1/admin/data).
 *
 * It replaces the old demo generators and exports the same names (core(),
 * ops(), daily, categoryLabels, AREAS…), so the module definitions and pages
 * keep working unchanged. What an administrator receives depends on their
 * permissions; the server leaves out anything they may not see.
 *
 * Parts of WorkMate that aren't built yet (payments, bookings, disputes,
 * videos, notifications, usage tracking, document verification) come back
 * as empty lists, so their screens show empty states rather than invented rows.
 */

export const DAY = 86400000;
export let NOW = Date.now();

// Illustrative until the commission is configured in Business Rules
export const COMMISSION_RATE = 0.10;
export const PROCESSING_FEE_RATE = 0.015;

export const live = reactive({ status: 'idle', error: '', generatedAt: null, trackedSeries: [] });

export const daily = reactive([]);
export const categories = reactive([]);          // [{ id, label, description, visible }]
export const categoryLabels = reactive({});      // id → label
export const categoryWeights = reactive({});     // id → 1 (kept for code that lists every category)
export const AREAS = reactive([]);               // [[name, weight, town]] as the demo shaped it
export const townOf = reactive({});              // area name → town

const emptyCore = () => ({
    customers: [], providers: [], applicants: [], jobs: [], quotations: [], reviews: [],
    bookings: [], transactions: [], refunds: [], payouts: [], disputes: [],
});
const OPS_KEYS = ['reports', 'videos', 'portfolio', 'deliveries', 'templates', 'campaigns', 'notificationTypes',
    'homepageSections', 'announcements', 'help', 'translations', 'areas', 'coverage', 'unmatched', 'alerts',
    'health', 'errors', 'integrations', 'failedJobs', 'scheduled', 'audit', 'security', 'adminUsers', 'flags',
    'dataRequests', 'events', 'searchLocations'];
const emptyOps = () => Object.fromEntries(OPS_KEYS.map(k => [k, []]));

let coreData = emptyCore();
let opsData = emptyOps();

export function core() { return coreData; }
export function ops() { return opsData; }

/** Series keys the server actually records; the rest are not tracked yet */
export function isTracked(key) {
    return live.trackedSeries.includes(key);
}

function fill(target, items) {
    target.splice(0, target.length, ...items);
}

let pending = null;
const listeners = new Set();

/** Called after every reload, so caches built from the old data are dropped */
export function onReload(fn) { listeners.add(fn); }

export function loadAdminData({ force = false } = {}) {
    if (!force && live.status === 'ready') return Promise.resolve();
    if (pending) return pending;
    live.status = 'loading';
    live.error = '';

    pending = api('/admin/data')
        .then(({ data }) => {
            NOW = Date.now();
            coreData = { ...emptyCore(), ...data.core };
            opsData = { ...emptyOps(), ...data.ops };
            fill(daily, data.daily);
            fill(categories, data.categories);
            for (const k of Object.keys(categoryLabels)) delete categoryLabels[k];
            for (const k of Object.keys(categoryWeights)) delete categoryWeights[k];
            for (const c of data.categories) { categoryLabels[c.id] = c.label; categoryWeights[c.id] = 1; }
            fill(AREAS, data.areas.map(a => [a.name, 1, a.town]));
            for (const a of data.areas) townOf[a.name] = a.town;
            live.trackedSeries = data.trackedSeries;
            live.generatedAt = data.generatedAt;
            live.status = 'ready';
            listeners.forEach(fn => fn());
        })
        .catch((e) => {
            live.status = 'error';
            live.error = e.message;
            throw e;
        })
        .finally(() => { pending = null; });

    return pending;
}
