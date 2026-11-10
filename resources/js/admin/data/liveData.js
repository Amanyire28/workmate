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

// version goes up after every reload, so open screens know to refresh their figures
export const live = reactive({ status: 'idle', error: '', generatedAt: null, trackedSeries: [], version: 0 });

export const daily = reactive([]);
export const categories = reactive([]);          // [{ id, label, description, visible }]
export const categoryLabels = reactive({});      // id → label
export const professions = reactive([]);         // [{ id: slug, label, category }] active professions
export const catalogueProfessions = reactive([]); // every profession, hidden ones too, with provider counts
export const categoryWeights = reactive({});     // id → 1 (kept for code that lists every category)
export const AREAS = reactive([]);               // [[name, weight, town]] as the demo shaped it
export const townOf = reactive({});              // area name → town
export const areaOptions = reactive([]);         // [[slug, label]] places for pickers (quick picks and places in use)
// Web app install figures, for administrators with usage.view
export const installs = reactive({ available: false, trackingSince: null, devicesWithApp: 0, daily: [] });
// Push notification set-up and subscribed devices, for administrators with comms.view
export const push = reactive({ configured: false, subscribers: 0, byPlatform: {} });
// Who is on WorkMate now and how many used it today (from the presence heartbeat)
export const presenceNow = reactive({ available: false, onlineNow: 0, onlineProviders: 0, activeToday: 0 });
// Sign-ins in the last 24 hours, for administrators with security.view
export const signIns = reactive({ available: false, signIns24h: 0, failedSignIns24h: 0, accountsTargeted24h: 0 });

const emptyCore = () => ({
    customers: [], providers: [], applicants: [], jobs: [], quotations: [], reviews: [],
    bookings: [], transactions: [], refunds: [], payouts: [], disputes: [],
});
const OPS_KEYS = ['reports', 'videos', 'portfolio', 'deliveries', 'templates', 'campaigns', 'notificationTypes',
    'homepageSections', 'announcements', 'help', 'translations', 'areas', 'coverage', 'unmatched', 'alerts',
    'health', 'errors', 'integrations', 'failedJobs', 'scheduled', 'audit', 'security', 'adminUsers', 'flags',
    'dataRequests', 'events', 'searchLocations', 'pushAnnouncements', 'professionSuggestions', 'verifications',
    'referrals', 'milestones', 'badgeAwards'];
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
            fill(professions, data.professions || []);
            fill(catalogueProfessions, data.catalogueProfessions || []);
            for (const k of Object.keys(categoryLabels)) delete categoryLabels[k];
            for (const k of Object.keys(categoryWeights)) delete categoryWeights[k];
            for (const c of data.categories) { categoryLabels[c.id] = c.label; categoryWeights[c.id] = 1; }
            fill(AREAS, data.areas.map(a => [a.name, 1, a.town]));
            fill(areaOptions, data.areas.map(a => [a.slug, a.district && a.district !== a.name ? `${a.name}, ${a.district}` : a.name]));
            for (const a of data.areas) townOf[a.name] = a.town;
            installs.available = !!data.installs;
            installs.trackingSince = data.installs?.trackingSince || null;
            installs.devicesWithApp = data.installs?.devicesWithApp || 0;
            fill(installs.daily, data.installs?.daily || []);
            push.configured = !!data.push?.configured;
            push.subscribers = data.push?.subscribers || 0;
            push.byPlatform = data.push?.byPlatform || {};
            Object.assign(presenceNow, { available: !!data.presenceNow, onlineNow: 0, onlineProviders: 0, activeToday: 0 }, data.presenceNow || {});
            Object.assign(signIns, { available: !!data.security, signIns24h: 0, failedSignIns24h: 0, accountsTargeted24h: 0 }, data.security || {});
            live.trackedSeries = data.trackedSeries;
            live.generatedAt = data.generatedAt;
            live.status = 'ready';
            listeners.forEach(fn => fn());
            live.version++;
        })
        .catch((e) => {
            live.status = 'error';
            live.error = e.message;
            throw e;
        })
        .finally(() => { pending = null; });

    return pending;
}

/* ---------------- Live updates ---------------- */

/**
 * While the admin is open and on screen it asks the server every few seconds
 * whether anything changed (GET /admin/pulse, a cheap fingerprint). Only when
 * it did is the data reloaded, and every open screen refreshes in place.
 * state: live (checking), paused (tab hidden), offline (checks failing).
 */
export const LIVE_INTERVAL_MS = 10000;
export const liveSync = reactive({ state: 'idle', lastCheck: null, lastChange: null });

let syncTimer = null;
let lastStamp = null;
let checking = false;

async function checkPulse() {
    if (checking || document.visibilityState !== 'visible' || live.status !== 'ready') return;
    checking = true;
    try {
        const { data } = await api('/admin/pulse');
        liveSync.lastCheck = new Date();
        liveSync.state = 'live';
        if (lastStamp !== null && data.stamp !== lastStamp) {
            await loadAdminData({ force: true });
            liveSync.lastChange = new Date();
        }
        lastStamp = data.stamp;
    } catch {
        liveSync.state = 'offline';
    } finally {
        checking = false;
    }
}

function onVisibility() {
    if (document.visibilityState === 'visible') {
        liveSync.state = liveSync.state === 'offline' ? 'offline' : 'live';
        checkPulse();
    } else {
        liveSync.state = 'paused';
    }
}

export function startLiveSync() {
    if (syncTimer) return;
    liveSync.state = 'live';
    checkPulse();
    syncTimer = setInterval(checkPulse, LIVE_INTERVAL_MS);
    document.addEventListener('visibilitychange', onVisibility);
}

export function stopLiveSync() {
    clearInterval(syncTimer);
    syncTimer = null;
    lastStamp = null;
    liveSync.state = 'idle';
    document.removeEventListener('visibilitychange', onVisibility);
}
