/**
 * Financial administration screens (Phase B8), as pure functions with no imports so they run the same in the app and in the Node tests.
 * Nothing on these screens posts to the ledger: an approved adjustment is a recorded decision, not a posted correction.
 */

export const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'reports', label: 'Reports' },
    { id: 'adjustments', label: 'Adjustments' },
    { id: 'exceptions', label: 'Exceptions' },
    { id: 'audit', label: 'Audit trail' },
];

export const REPORTS = [
    { id: 'obligations', label: 'Commission obligations', hint: 'What was assessed, reversed and is still on the ledger.' },
    { id: 'collections', label: 'Collections', hint: 'Money WorkMate verified it received. Pending or rejected reports are not in it.' },
    { id: 'outstanding', label: 'Outstanding and overdue', hint: 'Who owes commission now (a position as of now, not activity in the period).' },
    { id: 'performance', label: 'Settlement performance', hint: 'Settlement cycles by state and verified payment activity.' },
    { id: 'adjustments', label: 'Adjustments and exceptions', hint: 'Requests and reconciliation exceptions in the period.' },
];

export const ADJUSTMENT_TYPES = [
    { id: 'accounting_error', label: 'A verified accounting error' },
    { id: 'obligation_correction', label: 'A commission obligation that needs correcting' },
    { id: 'credit_allocation_correction', label: 'A credit that was allocated wrongly' },
    { id: 'reconciliation_correction', label: 'A reconciliation discrepancy' },
];

export const typeLabel = (id) => (ADJUSTMENT_TYPES.find((t) => t.id === id) || { label: String(id || '').replace(/_/g, ' ') }).label;

export const DIRECTIONS = [
    { id: 'decrease', label: 'Decrease what the provider owes' },
    { id: 'increase', label: 'Increase what the provider owes' },
];

export const STATUS_WORDS = {
    pending: 'Waiting for a decision',
    approved: 'Approved (not posted)',
    rejected: 'Rejected',
    open: 'Needs a decision',
    under_review: 'Being investigated',
    resolved: 'Resolved',
};

export const statusWords = (s) => STATUS_WORDS[s] || String(s || '').replace(/_/g, ' ');

export const EXCEPTION_KINDS = {
    overpayment: 'Overpayment: more was received than the cycle owed',
    excess_credit: 'Excess credit: verified payments now exceed what is owed',
};

/** Whole-UGX "YYYY-MM-DD" bounds for the default period (the last 30 days) */
export function defaultPeriod(now = new Date()) {
    const to = new Date(now);
    const from = new Date(now);
    from.setDate(from.getDate() - 29);
    const f = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    return { from: f(from), to: f(to) };
}

/** What is wrong with a period, or '' */
export function periodProblem(from, to) {
    if (!from || !to) return 'Choose the start and the end of the period.';
    if (from > to) return 'The start of the period must be before its end.';
    const days = (new Date(to) - new Date(from)) / 86400000;

    return days > 366 ? 'Choose a period of up to one year.' : '';
}

/** The query string for a report or its CSV: only the filters that are filled in */
export function query(filters, extra = {}) {
    const q = new URLSearchParams();
    for (const [k, v] of Object.entries({ ...filters, ...extra })) {
        if (v !== '' && v !== null && v !== undefined) q.set(k, String(v));
    }

    return q.toString();
}

export const csvUrl = (key, filters) => `/api/v1/admin/financial/reports/${key}.csv?${query(filters)}`;

export const blankAdjustment = () => ({ provider_id: '', type: '', direction: 'decrease', amount_ugx: '', subject: 'cycle_id', subject_id: '', reference: '', reason: '', provider_reason: '' });

export const SUBJECTS = [
    { id: 'cycle_id', label: 'A settlement (number)' },
    { id: 'assessment_id', label: 'A commission obligation (number)' },
    { id: 'commission_payment_id', label: 'A commission payment report (number)' },
    { id: 'exception_id', label: 'A reconciliation exception (number)' },
    { id: 'payment_report_id', label: 'A customer payment report (number)' },
    { id: 'dispute_id', label: 'A payment dispute (number)' },
];

/** What is wrong with an adjustment request, field by field ('' when fine). The server checks everything again. */
export function validateAdjustment(f) {
    const errors = { provider_id: '', type: '', amount_ugx: '', subject_id: '', reason: '' };
    if (!/^\d+$/.test(String(f.provider_id).trim()) || Number(f.provider_id) < 1) errors.provider_id = 'Enter the provider’s number.';
    if (!ADJUSTMENT_TYPES.some((t) => t.id === f.type)) errors.type = 'Choose what kind of adjustment this is.';
    const amount = String(f.amount_ugx).trim();
    if (!/^\d+$/.test(amount) || Number(amount) < 1 || Number(amount) > 100000000) errors.amount_ugx = 'Enter the amount in whole UGX.';
    if (!/^\d+$/.test(String(f.subject_id).trim()) || Number(f.subject_id) < 1) errors.subject_id = 'Enter the number of the record this is about.';
    if (String(f.reason).trim().length < 20) errors.reason = 'Explain why (at least 20 characters). A reviewer will read it.';

    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

export function adjustmentBody(f, token) {
    const body = {
        provider_id: Number(f.provider_id), type: f.type, direction: f.direction, amount_ugx: Number(String(f.amount_ugx).trim()),
        reason: String(f.reason).trim(), client_token: token, [f.subject]: Number(f.subject_id),
    };
    if (String(f.reference).trim()) body.reference = String(f.reference).trim();
    if (String(f.provider_reason).trim()) body.provider_reason = String(f.provider_reason).trim();

    return body;
}

/** The overview figures in the order a reader checks them, with the group each belongs to (never mixed) */
export function overviewCards(o) {
    if (!o) return { period: [], asOf: [] };

    return {
        period: [
            { key: 'assessed', label: 'Commission assessed', value: o.period.assessed_ugx, hint: 'Obligations recorded in the period.' },
            { key: 'reversed', label: 'Commission reversed', value: o.period.reversed_ugx, hint: 'Valid reversals recorded in the period.' },
            { key: 'net', label: 'Net commission obligations', value: o.period.net_obligations_ugx, hint: 'Assessed less reversed. Approved adjustments are not posted, so they are not in it.' },
            { key: 'collected', label: 'Commission collected', value: o.period.collected_ugx, hint: 'Money WorkMate verified it received in the period. Pending or rejected reports are never counted.' },
        ],
        asOf: [
            { key: 'outstanding', label: 'Outstanding commission', value: o.as_of.outstanding_ugx, hint: 'What providers owe today (a provider’s credit never reduces another’s debt).' },
            { key: 'overdue', label: 'Overdue commission', value: o.as_of.overdue_ugx, hint: 'Unpaid amounts in settlements past their deadline.' },
            { key: 'pending', label: 'Awaiting verification', value: o.as_of.pending_verification.amount_ugx, count: o.as_of.pending_verification.count, hint: 'Reported by providers, not yet checked. Not collected.' },
            { key: 'exceptions', label: 'Open reconciliation exceptions', value: o.as_of.open_exceptions.amount_ugx, count: o.as_of.open_exceptions.count, hint: 'Waiting for an investigation or a documented decision.' },
        ],
    };
}
