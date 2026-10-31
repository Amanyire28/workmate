/**
 * Admin data service.
 *
 * Pages call these async functions only. Every function reads the records loaded
 * from the Laravel API (liveData.js → GET /api/v1/admin/data), which only contains
 * what the signed-in administrator may see. Parts of WorkMate that aren't built
 * yet come back empty, so their screens show empty states, never invented rows.
 *
 * Test states: add ?demo=error, ?demo=empty or ?demo=slow to any admin URL.
 */
import {
    core, ops, daily, COMMISSION_RATE, PROCESSING_FEE_RATE, categoryLabels, categoryWeights, NOW, DAY, townOf,
    categories, loadAdminData, onReload,
} from './liveData.js';
import { patches, created, settings } from '../stores/prototypeChanges.js';
import { api } from '@/api/client.js';
import { SEARCH_CONFIG } from '@/utils/nearbySearch.js';
import { blueTickRules } from '../stores/blueTickRules.js';

const SOURCE = 'database';
const PLATFORMS = ['Android browser', 'iPhone browser', 'Installed web app', 'Desktop browser'];

// No live feed exists yet; the presence page hides its feed when this is null
export const simulatedEvent = null;

function scenario() {
    try { return new URLSearchParams(window.location.search).get('demo') || ''; } catch { return ''; }
}

async function respond(build) {
    const mode = scenario();
    if (mode === 'slow') await new Promise(r => setTimeout(r, 2500));
    if (mode === 'error') {
        const err = new Error('The admin service did not respond. This is a simulated failure (?demo=error).');
        err.simulated = true;
        throw err;
    }
    await loadAdminData();
    return build(mode === 'empty');
}

/** Reload from the server, e.g. after an action changed a record */
export function reloadAdminData() {
    return loadAdminData({ force: true });
}

// Lookups and the search index are rebuilt from fresh data
onReload(() => { rel = null; index = null; });

/* ---------------- Relations (lookups for detail views) ---------------- */
let rel = null;
export function relations() {
    if (rel) return rel;
    const c = core();
    const group = (list, key) => {
        const m = new Map();
        for (const x of list) { const k = x[key]; if (!m.has(k)) m.set(k, []); m.get(k).push(x); }
        return (id) => m.get(id) || [];
    };
    const byId = (list) => { const m = new Map(list.map(x => [x.id, x])); return (id) => m.get(id) || null; };
    rel = {
        customer: byId(c.customers), provider: byId(c.providers), booking: byId(c.bookings), job: byId(c.jobs), transaction: byId(c.transactions),
        bookingsOfCustomer: group(c.bookings, 'customerId'), bookingsOfProvider: group(c.bookings, 'providerId'),
        txOfCustomer: group(c.transactions, 'customerId'), txOfBooking: group(c.transactions, 'bookingId'),
        disputesOfCustomer: group(c.disputes, 'customerId'), disputesOfProvider: group(c.disputes, 'providerId'),
        reviewsOfProvider: group(c.reviews, 'providerId'), reviewsOfCustomer: group(c.reviews, 'customerId'),
        payoutsOfProvider: group(c.payouts, 'providerId'), quotesOfJob: group(c.quotations, 'jobId'),
        refundsOfTransaction: group(c.refunds, 'transactionId'),
        videosOfProvider: group(ops().videos, 'providerId'),
        nameOf: (id) => (id?.startsWith('C-') ? rel.customer(id)?.name : id?.startsWith('P-') ? rel.provider(id)?.name : null) || id,
    };
    return rel;
}

/* ---------------- Snapshot: point-in-time counts, derived from the records ---------------- */
export function snapshot() {
    const c = core();
    const o = ops();
    // Figures describe real activity: sample records are listed but not counted
    const real = (list) => list.filter(x => !x.isSample);
    const openJobs = real(c.jobs).filter(j => j.status === 'open');
    return {
        totalCustomers: real(c.customers).length,
        totalProviders: real(c.providers).length,
        providersAwaitingApproval: c.applicants.filter(a => ['submitted', 'pending_review', 'info_requested'].includes(a.status)).length,
        openJobs: openJobs.length,
        jobsNeedingIntervention: openJobs.filter(j => NOW - new Date(j.createdAt).getTime() > 48 * 3600000 && j.matching !== 'searching').length,
        activeBookings: c.bookings.filter(b => ['accepted', 'scheduled', 'in_progress'].includes(b.status)).length,
        pendingPayouts: c.payouts.filter(p => p.status === 'pending').reduce((s, p) => s + p.amount, 0),
        pendingPayoutCount: c.payouts.filter(p => p.status === 'pending').length,
        failedPayouts: c.payouts.filter(p => p.status === 'failed').length,
        openDisputes: c.disputes.filter(d => !d.resolvedAt).length,
        highDisputes: c.disputes.filter(d => !d.resolvedAt && d.severity === 'high').length,
        unresolvedReports: o.reports.filter(r => !['resolved', 'closed'].includes(r.status)).length,
        failedNotifications: o.deliveries.filter(d => (d.deliveryStatus === 'undelivered' || d.sendStatus === 'failed') && NOW - new Date(d.createdAt).getTime() < DAY).length,
        repeatedErrors: o.errors.filter(e => e.status === 'open' && e.severity === 'critical').length,
        paymentsToReconcile: c.transactions.filter(t => t.reconciliation === 'unmatched' || t.reconciliation === 'duplicate').length,
        pendingVideos: o.videos.filter(v => v.status === 'pending_moderation').length,
        ...verificationSnapshot(),
        ...blueTickSnapshot(),
        ...usageSnapshot(),
    };
}

/* ---------------- Verification ---------------- */

/** Document verification isn't connected yet (it needs secure private file storage) */
function verificationApplications() {
    return [];
}

function verificationSnapshot() {
    const apps = verificationApplications();
    const soon = NOW + 30 * DAY;
    const open = apps.filter(a => ['pending_review', 'under_review', 'info_requested'].includes(a.status));
    return {
        verifiedProviders: apps.filter(a => a.status === 'approved').length,
        pendingVerification: apps.filter(a => a.status === 'pending_review' || a.status === 'under_review').length,
        docsAwaitingReview: open.reduce((n, a) => n + a.documents.filter(d => d.status === 'awaiting_review').length, 0),
        verificationInfoRequested: apps.filter(a => a.status === 'info_requested').length,
        verificationRejected30: apps.filter(a => a.status === 'rejected' && NOW - new Date(a.lastActionAt).getTime() < 30 * DAY).length,
        docsAwaitingReplacement: open.reduce((n, a) => n + a.documents.filter(d => d.status === 'replacement_required' || d.status === 'rejected').length, 0),
        docsExpiringSoon: apps.filter(a => a.status === 'approved').reduce((n, a) => n + a.documents.filter(d => d.expiresAt && new Date(d.expiresAt).getTime() < soon && new Date(d.expiresAt).getTime() > NOW).length, 0),
    };
}

/* ---------------- Blue tick ---------------- */

/** Automatic blue tick evaluation isn't running yet, so nobody is listed */
function blueTickRows() {
    return [];
}

function blueTickSnapshot() {
    const rows = blueTickRows();
    const recent = (iso) => iso && NOW - new Date(iso).getTime() < 30 * DAY;
    return {
        blueTickTotal: rows.filter(r => ['awarded', 'under_review'].includes(r.status)).length,
        blueTickNew30: rows.filter(r => r.status === 'awarded' && recent(r.awardedAt)).length,
        blueTickApproaching: rows.filter(r => r.status === 'approaching').length,
        blueTickLost30: rows.filter(r => r.status === 'removed' && recent(r.removedAt)).length,
        blueTickReassess: rows.filter(r => r.status === 'under_review' || r.status === 'eligible').length,
        blueTickDraft: blueTickRules.draft ? 1 : 0,
    };
}

/** Evaluate all providers under a draft rule set, for the rules page preview */
export function previewRules() {
    // Evaluation needs completed bookings and response data, which don't exist yet
    return respond(() => ({
        source: SOURCE, currentHolders: 0, nextQualifiers: 0, gain: [], gainCount: 0, lose: [], loseCount: 0, notEvaluable: true,
    }));
}

/* ---------------- Usage and presence ---------------- */
export const PRESENCE_DEFAULTS = { presenceOnlineMin: 5, presenceRecentMin: 30, presenceHeartbeatSec: 60 };

export function presenceSettings() {
    const s = { ...PRESENCE_DEFAULTS, ...(settings.platform || {}) };
    return { onlineMin: Number(s.presenceOnlineMin), recentMin: Number(s.presenceRecentMin), heartbeatSec: Number(s.presenceHeartbeatSec) };
}

// App usage and presence heartbeats aren't collected yet: nobody's presence is known
const TRACKING_DAYS = 0;

function peopleWithPresence() {
    return [];
}

/** Daily usage rows: registrations come from the database, everything else isn't tracked yet */
function usageRows() {
    return daily.map(d => ({
        date: d.date, tracked: false,
        dau: 0, newUsers: d.customersNew, returning: 0, wau: 0, mau: 0,
        launches: null, sessions: null, avgSessionSec: null, installPrompts: null, installs: null, firstLaunches: null,
        launchesByPlatform: null, registrations: d.customersNew,
    }));
}

function usageSnapshot() {
    return {
        installsTotal: 0, dauToday: 0, launchesToday: 0, onlineNow: 0, onlineProviders: 0, offlineUsers: 0,
        analyticsFailures: 1,   // app usage and presence collection isn't connected yet
    };
}

/** Usage series for analytics pages; sums every numeric field per bucket */
export function getUsage({ days = 30, compare = true } = {}) {
    return respond((empty) => {
        const usage = usageRows();
        const installsBySource = [];
        const zero = (u) => Object.fromEntries(Object.entries(u).map(([k, v]) => [k, typeof v === 'number' ? 0 : v]));
        const rows = usage.slice(-days).map(u => (empty ? zero(u) : u));
        if (!rows.length) rows.push(...emptyDays(days).map(d => ({ ...d, tracked: false, dau: 0, newUsers: 0, registrations: 0 })));
        const prevRows = usage.slice(-days * 2, -days);
        const sumRows = (list) => {
            const out = {};
            for (const u of list) for (const [k, v] of Object.entries(u)) if (typeof v === 'number') out[k] = (out[k] || 0) + v;
            for (const p of PLATFORMS) out[`platform:${p}`] = list.reduce((s, u) => s + (u.launchesByPlatform?.[p] || 0), 0);
            return out;
        };
        const size = rows.length > 31 ? 7 : 1;
        const buckets = [];
        for (let end = rows.length; end > 0; end -= size) {
            const slice = rows.slice(Math.max(0, end - size), end);
            buckets.unshift({ start: slice[0].date, end: slice[slice.length - 1].date, days: slice.length, partial: slice.length < size, ...sumRows(slice) });
        }
        const people = peopleWithPresence();
        const areaCounts = new Map();
        for (const p of people) if (p.presence === 'online' || p.presence === 'recently_active') areaCounts.set(p.area, (areaCounts.get(p.area) || 0) + 1);
        return {
            source: SOURCE, trackingDays: TRACKING_DAYS,
            range: { days, start: rows[0].date, end: rows[rows.length - 1].date },
            daily: rows, size, buckets,
            totals: sumRows(rows),
            previous: compare && !empty && prevRows.length === days ? sumRows(prevRows) : null,
            latest: rows[rows.length - 1],
            presence: empty ? { online: 0, recent: 0, offline: 0, unknown: 0 } : {
                online: people.filter(p => p.presence === 'online').length,
                recent: people.filter(p => p.presence === 'recently_active').length,
                offline: people.filter(p => p.presence === 'offline').length,
                unknown: people.filter(p => p.presence === 'unknown').length,
            },
            activeByArea: empty ? [] : [...areaCounts.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value),
            installsBySource,
            totalRegistered: core().customers.length + core().providers.length,
        };
    });
}

/** Presence lists and counts for the live presence page */
export function getPresence() {
    return respond((empty) => {
        const people = empty ? [] : peopleWithPresence();
        const count = (type, presence) => people.filter(p => p.type === type && p.presence === presence).length;
        return {
            source: SOURCE, settings: presenceSettings(), checkedAt: new Date(NOW).toISOString(),
            people,
            counts: Object.fromEntries(['customer', 'provider'].map(t => [t, Object.fromEntries(['online', 'recently_active', 'offline', 'unknown'].map(s => [s, count(t, s)]))])),
        };
    });
}

/** One verification application with its requirement snapshot, for the review workspace */
export function getVerificationApplication(id) {
    return respond(() => {
        const app = verificationApplications().find(a => a.id === id);
        return { source: SOURCE, application: app || null, snapshot: null, current: null, provider: null };
    });
}

/* ---------------- Series ---------------- */
const FLOW_KEYS = ['customersNew', 'providersNew', 'activeUsers', 'searches', 'searchNearby', 'searchWider', 'searchNone', 'gpsDenied', 'gpsTimeout',
    'profileViews', 'enquiries', 'jobsCreated', 'jobsCompleted', 'bookingsCreated', 'bookingsCompleted', 'bookingsCancelled',
    'gtv', 'commission', 'fees', 'refunds', 'disputesOpened', 'disputesResolved'];

export function totals(rows) {
    const out = Object.fromEntries(FLOW_KEYS.map(k => [k, 0]));
    for (const r of rows) for (const k of FLOW_KEYS) out[k] += r[k];
    // Active users is a distinct-user count: a daily average stands in until real tracking exists
    out.activeUsers = rows.length ? Math.round(out.activeUsers / rows.length) : 0;
    return out;
}

/** Bar-chart buckets: days for short ranges, weeks (ending today) for long ones */
function bucketize(rows) {
    const size = rows.length > 31 ? 7 : 1;
    const buckets = [];
    for (let end = rows.length; end > 0; end -= size) {
        const slice = rows.slice(Math.max(0, end - size), end);
        buckets.unshift({ start: slice[0].date, end: slice[slice.length - 1].date, days: slice.length, partial: slice.length < size, ...totals(slice) });
    }
    return { size, buckets };
}

/** Zero-filled days, for administrators who may not see the daily figures */
function emptyDays(days) {
    return Array.from({ length: days }, (_, i) => ({
        date: new Date(NOW - (days - 1 - i) * DAY).toISOString().slice(0, 10),
        ...Object.fromEntries(FLOW_KEYS.map(k => [k, 0])),
    }));
}

function seriesFor(days, compare, empty) {
    const current = daily.length ? daily.slice(-days) : emptyDays(days);
    const previousRows = daily.slice(-days * 2, -days);
    const rows = empty ? current.map(r => ({ ...r, ...Object.fromEntries(FLOW_KEYS.map(k => [k, 0])) })) : current;
    return {
        range: { days, start: rows[0].date, end: rows[rows.length - 1].date },
        totals: totals(rows),
        previous: compare && !empty && previousRows.length === days ? totals(previousRows) : null,
        daily: rows,
        ...bucketize(rows),
    };
}

/** Daily series for analytics pages */
export function getSeries({ days = 30, compare = true } = {}) {
    return respond((empty) => {
        core();   // the series' money and resolution figures are filled from the records
        return { source: SOURCE, commissionRate: COMMISSION_RATE, feeRate: PROCESSING_FEE_RATE, ...seriesFor(days, compare, empty) };
    });
}

/* ---------------- Dashboard ---------------- */
export function getDashboard({ days = 30, compare = true } = {}) {
    return respond((empty) => {
        const c = core();
        const series = seriesFor(days, compare, empty);
        const since = NOW - days * DAY;

        // Job requests by category in the period, from the job records
        const counts = Object.fromEntries(Object.keys(categoryWeights).map(k => [k, 0]));
        if (!empty) for (const j of c.jobs) if (new Date(j.createdAt).getTime() >= since) counts[j.category]++;
        const categoryDemand = Object.entries(counts).map(([id, value]) => ({ id, label: categoryLabels[id] || id, value })).sort((a, b) => b.value - a.value);

        // Registered providers by base area, from the provider records
        const byArea = new Map();
        let noArea = 0;
        for (const p of c.providers) { if (!p.area) noArea++; else byArea.set(p.area, (byArea.get(p.area) || 0) + 1); }
        const ranked = [...byArea.entries()].sort((a, b) => b[1] - a[1]);
        const providerAreas = empty ? [] : [
            ...ranked.slice(0, 10).map(([label, value]) => ({ label, value })),
            { label: 'Other areas', value: ranked.slice(10).reduce((s, a) => s + a[1], 0), muted: true },
            { label: 'No area set', value: noArea, muted: true },
        ];

        const s = snapshot();
        return {
            source: SOURCE,
            generatedAt: new Date().toISOString(),
            commissionRate: COMMISSION_RATE,
            ...series,
            snapshot: empty ? Object.fromEntries(Object.keys(s).map(k => [k, 0])) : s,
            categoryDemand,
            providerAreas,
            priorities: empty ? [] : priorities(),
            activity: empty ? [] : ops().events.slice(0, 8),
        };
    });
}

/** Items needing an administrator, most severe first (the top bar's alert menu) */
export function getPriorities() {
    return respond((empty) => ({ source: SOURCE, priorities: empty ? [] : priorities() }));
}

function priorities() {
    const s = snapshot();
    const c = core();
    const pending = c.applicants.filter(a => ['submitted', 'pending_review', 'info_requested'].includes(a.status));
    const oldest = (list, key) => list.reduce((a, b) => (!a || b[key] < a[key] ? b : a), null);
    const oldestApplicant = oldest(pending, 'submittedAt') || {};
    const oldestDispute = oldest(c.disputes.filter(d => !d.resolvedAt), 'openedAt') || {};
    const list = [
        { id: 'errors', severity: 'critical', count: s.repeatedErrors, title: 'Repeated application errors', detail: 'Video transcoding failing on retry', to: '/admin/system/errors', permission: 'system.view' },
        { id: 'disputes', severity: s.highDisputes ? 'critical' : 'warning', count: s.openDisputes, title: 'Disputes awaiting response', detail: `${s.highDisputes} high severity`, since: oldestDispute.openedAt, to: '/admin/trust/disputes', permission: 'trust.view' },
        { id: 'applications', severity: 'warning', count: s.providersAwaitingApproval, title: 'Provider applications awaiting review', detail: 'Identity and service checks', since: oldestApplicant.submittedAt, to: '/admin/marketplace/applications', permission: 'providers.review' },
        { id: 'reconciliation', severity: 'warning', count: s.paymentsToReconcile, title: 'Payments needing reconciliation', detail: 'Unmatched or duplicate payment references', to: '/admin/finance/reconciliation', permission: 'finance.view' },
        { id: 'reports', severity: 'warning', count: s.unresolvedReports, title: 'Reports requiring moderation', detail: 'Profiles, reviews and videos', to: '/admin/trust/reports', permission: 'trust.view' },
        { id: 'jobs', severity: 'warning', count: s.jobsNeedingIntervention, title: 'Jobs needing intervention', detail: 'Open over 48 h with no provider match', to: '/admin/marketplace/jobs', permission: 'marketplace.view' },
        { id: 'notifications', severity: 'info', count: s.failedNotifications, title: 'Failed notification deliveries', detail: 'Last 24 h', to: '/admin/comms/delivery', permission: 'comms.view' },
        { id: 'verification', severity: 'warning', count: s.pendingVerification, title: 'Verification applications awaiting review', detail: `${s.docsAwaitingReview} documents to check`, to: '/admin/verification/applications', permission: 'documents.review' },
        { id: 'expiring', severity: 'info', count: s.docsExpiringSoon, title: 'Verification documents expiring', detail: 'Within 30 days; providers need to renew', to: '/admin/verification/applications', permission: 'documents.review' },
        { id: 'replacements', severity: 'info', count: s.docsAwaitingReplacement, title: 'Rejected documents awaiting replacement', detail: 'Waiting on providers', to: '/admin/verification/applications', permission: 'documents.review' },
        { id: 'rules-draft', severity: 'warning', count: s.blueTickDraft, title: 'Blue tick rule changes awaiting publication', detail: 'A saved draft hasn’t been published', to: '/admin/reputation/rules', permission: 'reputation.view' },
        { id: 'reassess', severity: 'info', count: s.blueTickReassess, title: 'Providers needing eligibility reassessment', detail: 'Under review or awaiting the next run', to: '/admin/reputation/blue-tick', permission: 'reputation.view' },
        { id: 'analytics', severity: 'warning', count: s.analyticsFailures, title: 'Activity collection not connected', detail: 'App usage and online presence aren’t being recorded yet', to: '/admin/system/integrations', permission: 'system.view' },
    ];
    const rank = { critical: 0, warning: 1, info: 2 };
    return list.filter(p => p.count > 0).sort((a, b) => rank[a.severity] - rank[b.severity]);
}

/* ---------------- Activity centre ---------------- */
export const EVENT_CATEGORIES = {
    accounts: 'Accounts', providers: 'Providers', search: 'Search and matching', marketplace: 'Jobs and bookings',
    finance: 'Payments and payouts', trust: 'Disputes and reports', content: 'Videos and content',
    notifications: 'Notifications', security: 'Security', admin: 'Admin actions', system: 'System',
};
const severityRank = { critical: 0, warning: 1, info: 2 };

/**
 * Operational events. `exclude` drops categories the caller may not see (security
 * events belong to roles with security access; the real API must filter server-side).
 */
export function listEvents({ q = '', category = '', severity = '', outcome = '', days = 14, exclude = [], sort = 'timestamp', dir = 'desc', page = 1, pageSize = 15 } = {}) {
    return respond((empty) => {
        const since = Date.now() - days * DAY;
        const needle = q.trim().toLowerCase();
        let rows = empty ? [] : ops().events.filter(e =>
            !exclude.includes(e.category) &&
            new Date(e.timestamp).getTime() >= since &&
            (!category || e.category === category) &&
            (!severity || e.severity === severity) &&
            (!outcome || (outcome === 'success' ? e.outcome === 'success' : e.outcome !== 'success')) &&
            (!needle || [e.summary, e.type, e.id, e.correlationId, e.entity.id, e.entity.label, e.actorName].join(' ').toLowerCase().includes(needle))
        );
        const mul = dir === 'asc' ? 1 : -1;
        rows = [...rows].sort((a, b) => {
            if (sort === 'severity') return mul * (severityRank[b.severity] - severityRank[a.severity]) || b.timestamp.localeCompare(a.timestamp);
            if (sort === 'category') return mul * a.category.localeCompare(b.category) || b.timestamp.localeCompare(a.timestamp);
            return mul * a.timestamp.localeCompare(b.timestamp);
        });
        const total = rows.length;
        const start = (page - 1) * pageSize;
        return { source: SOURCE, rows: rows.slice(start, start + pageSize), total, page, pageSize };
    });
}

/* ---------------- Global search ---------------- */
export const SEARCH_TYPES = {
    customer: 'Customers', provider: 'Providers', applicant: 'Applications', job: 'Jobs',
    booking: 'Bookings', transaction: 'Transactions', dispute: 'Disputes',
};
const label = (id) => categoryLabels[id] || id;

let index = null;
function searchIndex() {
    if (index) return index;
    const c = core();
    const r = relations();
    const entry = (type, row, title, subtitle, to, permission) => ({ type, id: row.id, row, title, subtitle, to, permission, text: `${row.id} ${title} ${subtitle}`.toLowerCase() });
    index = [
        ...c.customers.map(x => entry('customer', x, x.name, `Customer · ${x.area}`, '/admin/marketplace/customers', 'marketplace.view')),
        ...c.providers.map(x => entry('provider', x, x.name, `Provider · ${x.services.map(label).join(', ')} · ${x.area || 'No area'}`, '/admin/marketplace/providers', 'marketplace.view')),
        ...c.applicants.map(x => entry('applicant', x, x.name, `Provider application · ${x.services.map(label).join(', ')}`, '/admin/marketplace/applications', 'providers.review')),
        ...c.jobs.map(x => entry('job', x, x.title, `Job · ${label(x.category)} · ${x.area}`, '/admin/marketplace/jobs', 'marketplace.view')),
        ...c.bookings.map(x => entry('booking', x, `${label(x.service)} booking`, `Booking · ${r.nameOf(x.customerId)} → ${r.nameOf(x.providerId)}`, '/admin/marketplace/bookings', 'marketplace.view')),
        ...c.transactions.map(x => entry('transaction', x, `Payment ${x.id}`, `Transaction · ${x.bookingId} · ${x.method}`, '/admin/finance/transactions', 'finance.view')),
        ...c.disputes.map(x => entry('dispute', x, `${x.category} dispute`, `Dispute · ${x.bookingId} · ${x.severity} severity`, '/admin/trust/disputes', 'trust.view')),
    ];
    return index;
}

/** Records matching `q`, among the types the caller is allowed to see. Opens in the module's record view. */
export function globalSearch(q, allowed = () => true) {
    return respond(() => {
        const needle = q.trim().toLowerCase();
        if (needle.length < 2) return { source: SOURCE, results: [] };
        const results = [];
        for (const e of searchIndex()) {
            if (!e.text.includes(needle) || !allowed(e.permission)) continue;
            results.push({ type: e.type, id: e.id, title: e.title, subtitle: e.subtitle, status: e.row.status, to: `${e.to}?open=${encodeURIComponent(e.id)}` });
            if (results.length >= 12) break;
        }
        return { source: SOURCE, results };
    });
}

/* ---------------- Module datasets ---------------- */
const since = (d) => NOW - d * DAY;

function categoryRows() {
    const c = core();
    return categories.map((cat, i) => {
        const jobs30 = c.jobs.filter(j => j.category === cat.id && new Date(j.createdAt).getTime() >= since(30));
        const completed = c.bookings.filter(b => b.service === cat.id && b.status === 'completed');
        return {
            id: cat.id, name: cat.label, description: cat.description, position: i + 1, visible: true,
            providers: c.providers.filter(p => p.status === 'active' && p.services.includes(cat.id)).length,
            jobs30: jobs30.length,
            avgPrice: completed.length ? Math.round(completed.reduce((s, b) => s + b.price, 0) / completed.length) : null,
            keywords: '', history: [],
        };
    });
}

function moderationRows() {
    const c = core();
    const o = ops();
    return [
        ...c.reviews.filter(r => r.status === 'reported').map(r => ({ id: r.id, kind: 'Review', title: `“${r.comment}”`, ownerId: r.customerId, owner: relations().nameOf(r.customerId), reason: `${r.reports} report${r.reports > 1 ? 's' : ''}`, submittedAt: r.createdAt, status: 'pending', history: [] })),
        ...o.portfolio.filter(p => p.status === 'pending').map(p => ({ id: p.id, kind: 'Portfolio image', title: p.title, ownerId: p.providerId, owner: p.providerName, reason: 'New upload', submittedAt: p.uploadedAt, status: 'pending', history: [] })),
        ...o.videos.filter(v => v.status === 'pending_moderation').map(v => ({ id: v.id, kind: 'Video', title: v.caption, ownerId: v.providerId, owner: v.providerName, reason: 'New upload', submittedAt: v.uploadedAt, status: 'pending', history: [] })),
    ].sort((a, b) => a.submittedAt.localeCompare(b.submittedAt));
}

function restrictedRows() {
    const c = core();
    // The reason given when suspending is kept on providers; for accounts it's in the audit log
    const restricted = (x, kind) => ({
        id: x.id, name: x.name, kind, status: x.status, area: x.area,
        reason: x.suspensionReason || 'See the audit log for the reason given',
        since: x.lastActiveAt, expires: null, appeal: 'None recorded', history: [],
    });
    return [
        ...c.customers.filter(x => x.status === 'suspended' || x.status === 'restricted').map(x => restricted(x, 'Customer')),
        ...c.providers.filter(x => ['suspended', 'restricted', 'deactivated'].includes(x.status)).map(x => restricted(x, 'Provider')),
    ];
}

function commissionRows() {
    const c = core();
    const cut = since(30);
    return categories.map(cat => {
        const tx = c.transactions.filter(t => t.status !== 'failed' && t.status !== 'pending' && t.reconciliation !== 'duplicate' && new Date(t.createdAt).getTime() >= cut && relations().booking(t.bookingId)?.service === cat.id);
        const gtv = tx.reduce((s, t) => s + t.amount, 0);
        return { id: cat.id, name: cat.label, rate: COMMISSION_RATE, transactions: tx.length, gtv30: gtv, commission30: tx.reduce((s, t) => s + t.commission, 0), fees30: tx.reduce((s, t) => s + t.fee, 0), history: [] };
    });
}

function reconciliationRows() {
    const c = core();
    return [
        ...c.transactions.filter(t => t.reconciliation === 'unmatched').map(t => ({ id: `EX-${t.id}`, kind: 'Unmatched reference', reference: t.providerRef, transactionId: t.id, amount: t.amount, difference: t.amount, detectedAt: t.settledAt, status: 'open', history: [] })),
        ...c.transactions.filter(t => t.reconciliation === 'duplicate').map(t => ({ id: `EX-${t.id}`, kind: 'Duplicate reference', reference: t.providerRef, transactionId: t.id, amount: t.amount, difference: t.amount, detectedAt: t.createdAt, status: 'open', history: [] })),
        ...c.payouts.filter(p => p.status === 'failed').slice(-12).map(p => ({ id: `EX-${p.id}`, kind: 'Failed payout', reference: p.id, transactionId: null, amount: p.amount, difference: p.amount, detectedAt: p.requestedAt, status: 'open', history: [] })),
    ].sort((a, b) => b.detectedAt.localeCompare(a.detectedAt));
}

/** No payments are recorded yet, so there are no monthly statements */
function statementRows() {
    return [];
}

// Saved reports need their own table; none exist yet
const savedReports = [];

const DATASETS = {
    alerts: () => ops().alerts,
    customers: () => core().customers,
    providers: providerRows,
    applications: () => core().applicants,
    jobs: () => core().jobs,
    quotations: () => core().quotations,
    bookings: () => core().bookings,
    categories: categoryRows,
    portfolios: () => ops().portfolio,
    reviews: () => core().reviews,
    'location-overview': () => ops().areas,
    coverage: () => ops().coverage,
    unmatched: () => ops().unmatched,
    'verification-applications': verificationApplications,
    bluetick: blueTickRows,
    'activity-explorer': peopleWithPresence,
    disputes: () => core().disputes,
    reports: () => ops().reports,
    moderation: moderationRows,
    restricted: restrictedRows,
    transactions: () => core().transactions,
    commissions: commissionRows,
    refunds: () => core().refunds,
    payouts: () => core().payouts,
    reconciliation: reconciliationRows,
    'finance-reports': statementRows,
    'in-action': () => ops().videos,
    'video-moderation': () => ops().videos.filter(v => v.status === 'pending_moderation' || v.reports > 0),
    homepage: () => ops().homepageSections,
    announcements: () => ops().announcements,
    help: () => ops().help,
    translations: () => ops().translations,
    notifications: () => ops().notificationTypes,
    templates: () => ops().templates,
    campaigns: () => ops().campaigns,
    delivery: () => ops().deliveries,
    'report-builder': () => savedReports,
    health: () => ops().health,
    errors: () => ops().errors,
    integrations: () => ops().integrations,
    queues: () => ops().failedJobs,
    scheduled: () => ops().scheduled,
    // Only the server's audit log: nothing recorded in a browser belongs here
    audit: () => ops().audit,
    security: () => ops().security,
    'admin-users': () => ops().adminUsers,
    flags: () => ops().flags,
    data: () => ops().dataRequests,
};

/** Providers joined with their document verification and blue tick status */
function providerRows() {
    const apps = new Map(verificationApplications().filter(a => a.providerId).map(a => [a.providerId, a]));
    const ticks = new Map(blueTickRows().map(r => [r.id, r]));
    return core().providers.map(p => {
        const app = apps.get(p.id);
        const tick = ticks.get(p.id);
        return { ...p, verification: app ? (app.status === 'approved' ? 'verified' : app.status) : 'none', verificationApp: app?.id || null, blueTick: tick?.status || 'not_eligible' };
    });
}

/** All records for a module, with this session's prototype changes applied */
export function fetchDataset(moduleId) {
    return respond((empty) => {
        if (empty) return { source: SOURCE, rows: [] };
        const base = (DATASETS[moduleId] || (() => []))();
        const changes = patches[moduleId] || {};
        const rows = [...(created[moduleId] || []), ...base.map(r => (changes[r.id] ? { ...r, ...changes[r.id] } : r))];
        return { source: SOURCE, rows };
    });
}

/** Re-read one record with its latest changes (after an action) */
export function withChanges(moduleId, row) {
    const p = patches[moduleId]?.[row.id];
    return p ? { ...row, ...p } : row;
}

/* ---------------- Settings ---------------- */
const SETTINGS_DEFAULTS = {
    matching: () => ({
        nearbyKm: SEARCH_CONFIG.nearbyKm, extendedKm: SEARCH_CONFIG.extendedKm, maxKm: 25,
        minNearbyResults: SEARCH_CONFIG.minNearbyResults, gpsAreaMatchKm: SEARCH_CONFIG.gpsAreaMatchKm,
        useServiceAreas: true, sameTownFallback: true, noCoordinates: 'town_only', distancePolicy: 'straight_line',
        rankAvailability: true, rankVerification: true, rankRating: false, gpsMaxAgeMin: 2,
    }),
    preferences: () => ({
        smsBooking: true, pushBooking: true, emailReceipts: true, smsReminders: true, pushQuotes: true,
        marketingDefault: false, quietHoursStart: '21:00', quietHoursEnd: '07:00', defaultLanguage: 'English',
    }),
    platform: () => ({
        platformName: 'WorkMate', supportPhone: '+256 700 000 000', supportEmail: 'support@workmate.example',
        defaultCity: 'Kampala', currency: 'UGX', languages: 'English, Luganda', maintenanceMode: false, providerSignups: true,
        ...PRESENCE_DEFAULTS,
    }),
    rules: () => ({
        commissionRate: COMMISSION_RATE * 100, freeCancellationHours: 12, providerResponseHours: 24, quoteExpiryDays: 3,
        autoCompleteDays: 3, minPayout: 20000, payoutDay: 'Monday', disputeWindowDays: 7, maxActiveBookingsNewCustomer: 3,
    }),
};

/**
 * Settings that exist on the server (config/workmate.php), by screen field.
 * Only these are saved; every other field on the settings screens isn't
 * connected yet and is shown read-only.
 */
export const SERVER_SETTINGS = {
    matching: { nearbyKm: 'search.nearby_km', extendedKm: 'search.extended_km', minNearbyResults: 'search.min_nearby_results' },
    platform: {
        presenceOnlineMin: 'presence.online_minutes', presenceRecentMin: 'presence.recent_minutes',
        presenceHeartbeatSec: 'presence.heartbeat_seconds',
    },
};

/**
 * Current values: live ones from GET /admin/settings, the rest shown at their
 * planned defaults. `connected` maps each live field to its server key and
 * whether this administrator may change it.
 */
export async function getSettings(moduleId) {
    const defaults = SETTINGS_DEFAULTS[moduleId] ? SETTINGS_DEFAULTS[moduleId]() : {};
    const values = { ...defaults };
    const connected = {};
    const mapped = SERVER_SETTINGS[moduleId] || {};

    if (Object.keys(mapped).length) {
        const server = new Map((await api('/admin/settings')).data.map(s => [s.key, s]));
        for (const [field, key] of Object.entries(mapped)) {
            const s = server.get(key);
            if (!s) continue;   // this role may not see it
            values[field] = s.value;
            defaults[field] = s.default;
            connected[field] = { key, canChange: s.can_change, min: s.min, max: s.max };
        }
    }

    return { source: SOURCE, defaults, values, connected };
}

/** Saves changed live settings one by one; the server validates and audits each */
export async function saveSettings(moduleId, changes, reason) {
    for (const [field, value] of Object.entries(changes)) {
        const key = SERVER_SETTINGS[moduleId]?.[field];
        if (key) await api(`/admin/settings/${key}`, { method: 'PATCH', body: { value, reason } });
    }
}

export { townOf };
