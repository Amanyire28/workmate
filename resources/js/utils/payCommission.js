/**
 * Paying WorkMate commission (Phase B10), as pure functions with no imports so they run the same in the app and in the Node tests.
 * The server owns every figure and every decision: this only checks what can be checked on the phone before sending, and words the answers.
 * No PIN is ever asked for: mobile money payments are approved on the payer's own phone.
 */

export const MIN_AMOUNT = 100;
export const UNAVAILABLE = 'Service currently not available.';

export const METHOD_STYLE = {
    mtn_momo: { name: 'MTN Mobile Money', badge: 'MTN', bg: '#FFCC00', fg: '#1A1A1A' },
    airtel_money: { name: 'Airtel Money', badge: 'airtel', bg: '#E40000', fg: '#FFFFFF' },
    manual: { name: 'Manual payment with proof', badge: null, bg: '#EAF2FD', fg: '#1769D2' },
};

export const methodName = (id) => (METHOD_STYLE[id] || { name: String(id || '').replace(/_/g, ' ') }).name;

/** What is wrong with the amount ('' when fine). `available` is what the server says can be paid now. */
export function amountProblem(value, available) {
    const text = String(value ?? '').trim();
    if (!/^\d+$/.test(text) || Number(text) < MIN_AMOUNT) return `Enter an amount of at least UGX ${MIN_AMOUNT}.`;
    if (Number(text) > Number(available)) return Number(available) > 0 ? `You can pay up to UGX ${Number(available).toLocaleString('en-US')} now.` : 'Nothing more can be paid right now.';

    return '';
}

/** A Ugandan mobile number as 07XXXXXXXX; '' when it is not one */
export function normalizePhone(value) {
    const digits = String(value ?? '').replace(/[\s\-()]/g, '');
    const m = digits.match(/^(?:\+?256|0)(7\d{8})$/);

    return m ? `0${m[1]}` : '';
}

export const phoneProblem = (value) => (normalizePhone(value) ? '' : 'Enter a valid mobile money number, like 0772 123 456.');

/** Left to pay once this payment is recorded (for showing only: the server works out the real balance) */
export const leftAfter = (payable, amount) => Math.max(Number(payable) - (Number(amount) || 0), 0);

/** The method list as the screen shows it: an unavailable method stays visible but cannot be used */
export function methodChoices(methods) {
    return (methods || []).map((m) => ({ ...m, ...(METHOD_STYLE[m.id] || {}), name: methodName(m.id), usable: !!m.available }));
}

/** Words for where a mobile money request is, short and plain */
export const ATTEMPT_WORDS = {
    pending: 'Payment request sent', processing: 'Payment request sent', successful: 'Payment successful',
    failed: 'Payment failed', expired: 'Payment expired', cancelled: 'Payment cancelled',
};

export const attemptWords = (status) => ATTEMPT_WORDS[status] || '';

export const isFinal = (status) => ['successful', 'failed', 'expired', 'cancelled'].includes(status);

/** What is wrong with a manual payment's details ('' per field when fine). The proof and the reference are required. */
export function manualProblems(form, hasFile, today = new Date().toISOString().slice(0, 10)) {
    const errors = { reference: '', paid_on: '', proof: '' };
    const reference = String(form.reference || '').trim();
    if (!reference) errors.reference = 'Enter the transaction reference.';
    else if (!/^[A-Za-z0-9 ._/#:-]{1,60}$/.test(reference)) errors.reference = 'Use only letters, numbers and - . / in the reference.';
    if (!form.paid_on) errors.paid_on = 'Choose the date you paid.';
    else if (form.paid_on > today) errors.paid_on = 'The date can’t be in the future.';
    if (!hasFile) errors.proof = 'Add a photo or PDF of your proof of payment.';

    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

/** The "Pay commission" button: only with something to pay, the right to pay, and a payment that can still be offered */
export const showPayButton = (pay) => !!pay && pay.show_pay_button === true && Number(pay.available_ugx) > 0;

/** The way a payment's status is worded in the provider's history */
export const HISTORY_WORDS = {
    pending: { label: 'Awaiting verification', tone: 'warning' },
    verified: { label: 'Verified', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
};

export const historyWords = (status) => HISTORY_WORDS[status] || HISTORY_WORDS.pending;
