/**
 * My Finances (Phase B9), as pure functions with no imports so they run the same in the app and in the Node tests. Nothing here works
 * out money: every amount comes from the server (the ledger and the payment reports are the only source). These only choose words,
 * check the dates a person types, and build links.
 */

export const PERIODS = [
    { id: 'this_week', label: 'This week' },
    { id: 'this_month', label: 'This month' },
    { id: 'last_month', label: 'Last month' },
    { id: 'all', label: 'All time' },
    { id: 'custom', label: 'Choose dates' },
];

export const periodLabel = (id) => (PERIODS.find((p) => p.id === id) || { label: '' }).label;

/** What is wrong with a chosen period, or '' */
export function periodProblem(period, from, to) {
    if (period !== 'custom') return '';
    if (!from || !to) return 'Choose the first and the last day.';
    if (from > to) return 'The first day must be before the last day.';
    return (new Date(to) - new Date(from)) / 86400000 > 1830 ? 'Choose up to five years, or “All time”.' : '';
}

/** The query for any finance request: only what matters for the period chosen */
export function query(period, from, to, extra = {}) {
    const q = new URLSearchParams({ period });
    if (period === 'custom') {
        q.set('from', from);
        q.set('to', to);
    }
    for (const [k, v] of Object.entries(extra)) {
        if (v !== '' && v !== null && v !== undefined) q.set(k, String(v));
    }

    return q.toString();
}

export const statementUrl = (kind, format, period, from, to) => `/api/v1/me/finance/statements/${kind}.${format}?${query(period, from, to)}`;

export const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'statement', label: 'Commission statement' },
    { id: 'payments', label: 'Customer payments' },
    { id: 'settlements', label: 'Settlements' },
    { id: 'to-workmate', label: 'Paid to WorkMate' },
    { id: 'disputes', label: 'Disputes' },
    { id: 'downloads', label: 'Downloads' },
];

export const tabIds = () => TABS.map((t) => t.id);

export const LINE_WORDS = {
    assessment: { label: 'Commission added', sign: '+', tone: 'neutral' },
    reversal: { label: 'Taken off', sign: '−', tone: 'success' },
    settlement: { label: 'Payment to WorkMate verified', sign: '−', tone: 'success' },
};

export const lineWords = (type) => LINE_WORDS[type] || { label: String(type || '').replace(/_/g, ' '), sign: '', tone: 'neutral' };

/** The payment states a provider sees, from what the customer did (never "received" until the customer confirmed) */
export const PAYMENT_STATE = {
    pending_confirmation: { label: 'Waiting for the customer', tone: 'warning', why: 'Not counted as paid until the customer confirms it.' },
    confirmed: { label: 'Confirmed by the customer', tone: 'success', why: 'The customer confirmed they paid.' },
    disputed: { label: 'Questioned by the customer', tone: 'danger', why: 'The customer says it isn’t right. It is not counted as paid.' },
    cancelled: { label: 'Taken back', tone: 'neutral', why: 'You took this report back.' },
};

export const paymentState = (s) => PAYMENT_STATE[s] || PAYMENT_STATE.pending_confirmation;

export const DISPUTE_STAGE = {
    open: 'Between you and the customer', discussion: 'Talking it through', escalated: 'Waiting for WorkMate', under_review: 'WorkMate is reviewing', resolved: 'Closed',
};

export const disputeStage = (s) => DISPUTE_STAGE[s] || String(s || '').replace(/_/g, ' ');

export const METHODS = { cash: 'Cash', mobile_money: 'Mobile money', bank_transfer: 'Bank transfer', other: 'Other' };

export const methodLabel = (m) => METHODS[m] || String(m || '');

/** The people who may be given access: who they are and whether they have it */
export const roleWords = (role) => ({ owner: 'Owner', manager: 'Manager', member: 'Team member' }[role] || role);

/** Whole UGX the way people read it: "UGX 1,250,000" */
export const ugx = (n) => `UGX ${Number(n || 0).toLocaleString('en-UG')}`;

/** A reading of the commission figures that never calls money earned unless the customer confirmed it */
export function customerNote(cp) {
    if (!cp) return '';
    const parts = [];
    if (cp.awaiting && cp.awaiting.count) parts.push(`${cp.awaiting.count} waiting for the customer`);
    if (cp.questioned && cp.questioned.count) parts.push(`${cp.questioned.count} questioned`);

    return parts.length ? `Not counted as paid: ${parts.join(', ')}.` : '';
}
