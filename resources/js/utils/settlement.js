/**
 * Commission settlement screens (Phase B7), as pure functions with no imports so they run the same in the app and in the Node tests.
 * Plain words only: nothing here mentions penalties, interest or restrictions, because none exist.
 */

export const MIN_AMOUNT = 100;
export const JOBS_TRIGGER = 3;

export const CYCLE_STATUS = {
    open: { label: 'Building up', tone: 'neutral' },
    due: { label: 'Due', tone: 'warning' },
    partially_paid: { label: 'Part paid', tone: 'info' },
    overdue: { label: 'Overdue', tone: 'danger' },
    settled: { label: 'Settled', tone: 'success' },
    void: { label: 'Nothing owed', tone: 'neutral' },
};

export const cycleLabel = (status) => (CYCLE_STATUS[status] || { label: String(status || '').replace(/_/g, ' ') }).label;

export const PAYMENT_STATUS = {
    pending: { label: 'Awaiting verification', tone: 'warning', why: 'WorkMate has to check the money arrived before it counts. Nothing is taken off yet.' },
    verified: { label: 'Verified', tone: 'success', why: 'WorkMate checked it and it was taken off what you owe.' },
    rejected: { label: 'Rejected', tone: 'danger', why: 'WorkMate could not accept it. Nothing was taken off.' },
};

export const paymentWords = (status) => PAYMENT_STATUS[status] || PAYMENT_STATUS.pending;

export const CHANNELS = {
    mtn_momo: 'MTN Mobile Money',
    airtel_money: 'Airtel Money',
    bank_transfer: 'Bank transfer',
    cash: 'Cash',
};

export const channelLabel = (id) => CHANNELS[id] || String(id || '');

/** When it is due, in words: the trigger that applies, or what is still needed to trigger it */
export function dueWords(cycle, formatDate) {
    if (!cycle || cycle.status === 'settled' || cycle.status === 'void') return '';
    const date = (iso) => (formatDate ? formatDate(iso) : iso);
    if (!cycle.due_at) return `Due after ${JOBS_TRIGGER} jobs with commission, or 7 days after the first one.`;
    if (cycle.trigger === 'three_jobs') {
        return cycle.status === 'overdue'
            ? `Became due when you reached ${JOBS_TRIGGER} jobs, and is past the 7-day limit.`
            : `Due now: you reached ${JOBS_TRIGGER} jobs with commission.`;
    }
    return cycle.status === 'overdue' ? `Was due on ${date(cycle.due_at)}.` : `Due on ${date(cycle.due_at)}, or sooner if you reach ${JOBS_TRIGGER} jobs.`;
}

/** What the record says about a trigger that is no longer in effect today (a reversal took the live count back under three); '' when there is nothing to say */
export function earlierTriggerWords(cycle, formatDate) {
    const e = cycle && Array.isArray(cycle.history) ? cycle.history.find((h) => h.event === 'three_jobs_reached' && !h.in_effect) : null;
    if (!e) return '';

    return `You reached ${JOBS_TRIGGER} jobs on ${formatDate ? formatDate(e.at) : e.at}. A job was reversed since, so this settlement is open again. The earlier record is kept.`;
}

/** "2 of 3 jobs" for a cycle that has not yet triggered */
export const jobsWords = (cycle) => `${cycle.jobs} of ${JOBS_TRIGGER} jobs`;

/** What can be reported against a cycle: only one that still has something to pay */
export const canReport = (cycle) => !!cycle && cycle.outstanding_ugx > 0;

export const blankReport = () => ({ amount: '', channel: '', reference: '', paid_on: '', note: '' });

/** What is wrong with a payment report, field by field ('' when fine). The server checks everything again. */
export function validateReport(form, channels, today = new Date().toISOString().slice(0, 10)) {
    const errors = { amount: '', channel: '', reference: '', paid_on: '' };
    const amount = String(form.amount || '').trim();
    if (!/^\d+$/.test(amount) || Number(amount) < MIN_AMOUNT) errors.amount = `Enter the amount in whole UGX, at least ${MIN_AMOUNT}.`;
    if (!channels.includes(form.channel)) errors.channel = 'Choose how you paid WorkMate.';
    const reference = String(form.reference || '').trim();
    if (form.channel !== 'cash' && !reference) errors.reference = 'Enter the transaction reference from your payment message or receipt.';
    else if (reference && !/^[A-Za-z0-9 ._/#:-]{1,60}$/.test(reference)) errors.reference = 'Use only letters, numbers and - . / in the reference. Never type a PIN or password.';
    if (form.paid_on && form.paid_on > today) errors.paid_on = 'The payment date can’t be in the future.';

    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

/** The fields to send, as whole numbers and trimmed text (empty optional fields are left out) */
export function reportBody(form, cycleId, token) {
    const body = { cycle_id: cycleId, amount: Number(String(form.amount).trim()), channel: form.channel, client_token: token };
    if (String(form.reference || '').trim()) body.reference = String(form.reference).trim();
    if (form.paid_on) body.paid_on = form.paid_on;
    if (String(form.note || '').trim()) body.note = String(form.note).trim();

    return body;
}

/** The tabs on the administrator page, with their counts */
export const ADMIN_TABS = [
    { id: 'payments', label: 'To verify' },
    { id: 'cycles', label: 'Cycles' },
    { id: 'exceptions', label: 'Exceptions' },
];

export const EXCEPTION_WORDS = {
    overpayment: 'More was received than the cycle owed. The extra is not applied anywhere.',
    excess_credit: 'Verified payments now exceed what is owed (an obligation was reversed). Nothing is refunded automatically.',
};
