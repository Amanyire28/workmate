/**
 * Commission Payments (Phase B10), as pure functions with no imports so they run the same in the app and in the Node tests.
 * The server decides everything; this only turns its answers into words and filters into a query.
 */

export const STATUS = {
    awaiting_verification: { label: 'Awaiting verification', tone: 'warning' },
    pending: { label: 'Pending', tone: 'warning' },
    processing: { label: 'Processing', tone: 'info' },
    successful: { label: 'Successful', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    failed: { label: 'Failed', tone: 'danger' },
    expired: { label: 'Expired', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

export const statusWords = (status) => STATUS[status] || { label: String(status || '').replace(/_/g, ' '), tone: 'neutral' };

export const METHODS = { manual: 'Manual payment', mtn_momo: 'MTN Mobile Money', airtel_money: 'Airtel Money', bank_transfer: 'Bank transfer', cash: 'Cash' };

export const methodLabel = (id) => METHODS[id] || String(id || '').replace(/_/g, ' ');

export const VERIFICATION = { awaiting: 'Awaiting', verified: 'Verified', automatic: 'Automatic', rejected: 'Rejected', not_applicable: 'Not applicable' };

export const verificationLabel = (v) => VERIFICATION[v] || '';

export const TONE_CLASS = {
    warning: 'bg-[#FEF3C7] text-[#92400E]', info: 'bg-[#DBEAFE] text-[#1E40AF]', success: 'bg-[#DCFCE7] text-[#166534]',
    danger: 'bg-[#FEE2E2] text-[#991B1B]', neutral: 'bg-[#F1F5F9] text-[#475569]',
};

export const FILTER_STATUSES = ['awaiting_verification', 'pending', 'processing', 'successful', 'rejected', 'failed', 'expired', 'cancelled'];

/** The query the server understands; blank filters are left out */
export function buildQuery(filters, page = 1) {
    const q = new URLSearchParams();
    for (const key of ['q', 'status', 'method', 'source', 'provider', 'from', 'to', 'sort', 'dir']) {
        const v = filters[key];
        if (v !== undefined && v !== null && String(v).trim() !== '') q.set(key, String(v).trim());
    }
    if (page > 1) q.set('page', String(page));

    return q.toString();
}

/** Only a manual proof that is still waiting can be decided, and only by someone allowed to */
export const canDecide = (row, allowed) => !!allowed && !!row && row.kind === 'report' && row.status === 'awaiting_verification';

/** Why a verify form cannot be sent yet ('' when it can). The server checks everything again. */
export function verifyProblem(form) {
    if (!/^\d+$/.test(String(form.amount || '').trim()) || Number(form.amount) < 100) return 'Enter the amount actually received, in whole UGX.';
    if (!form.confirmed) return 'Confirm that you checked the money arrived in WorkMate’s own records.';

    return '';
}

export const rejectProblem = (reason) => (String(reason || '').trim().length < 5 ? 'Say why (at least 5 characters). The provider will read it.' : '');

/** What happened, in one short line each, for the timeline */
export const EVENT_WORDS = {
    created: 'Payment started', sent: 'Request sent to the phone', callback_successful: 'Gateway confirmed payment', callback_failed: 'Gateway reported failure',
    callback_cancelled: 'Gateway reported cancellation', successful: 'Payment recorded', failed: 'Payment failed', cancelled: 'Cancelled', expired: 'Request expired',
    late_confirmation: 'Confirmed after the request ended', mismatch: 'Confirmed a different amount',
};

export const eventWords = (event) => EVENT_WORDS[event] || String(event || '').replace(/_/g, ' ');

export const AUDIT_WORDS = {
    'commission.payment_reported': 'Proof submitted', 'commission.payment_verified': 'Verified and credited', 'commission.payment_rejected': 'Rejected',
    'commission.payment_evidence_viewed': 'Proof opened', 'commission.payment_attempt_started': 'Payment started',
};

export const auditWords = (action) => AUDIT_WORDS[action] || String(action || '').replace(/[._]/g, ' ');
