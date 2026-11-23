/**
 * Payment disputes (Phase B5), as pure functions with no imports so they run the same in the app and in the Node tests.
 * Words here are plain and never talk about debt, penalties or fees: a dispute is only about whether a payment happened.
 */

export const STAGE_WORDS = {
    open: { title: 'Between you and the other side', next: 'Talk it through here. If you can’t agree, either of you can ask WorkMate to step in.', tone: 'warning' },
    discussion: { title: 'You are talking it through', next: 'If you can’t agree, either of you can ask WorkMate to step in.', tone: 'warning' },
    escalated: { title: 'Waiting for WorkMate', next: 'WorkMate was asked to step in. You can still add messages and files.', tone: 'info' },
    under_review: { title: 'WorkMate is reviewing', next: 'A WorkMate administrator is looking at what you both sent. They may ask for more.', tone: 'info' },
    resolved: { title: 'Closed', next: '', tone: 'success' },
};

export const stageWords = (status) => STAGE_WORDS[status] || STAGE_WORDS.open;

export const isActive = (status) => !!status && status !== 'resolved';

/** What the two sides can agree on between themselves */
export const AGREEMENTS = [
    { id: 'payment_received', label: 'The payment was received', hint: 'The customer’s confirmation is recorded.' },
    { id: 'payment_not_received', label: 'The payment was not received', hint: 'The provider’s report is withdrawn.' },
];

export const agreementLabel = (id) => (AGREEMENTS.find((a) => a.id === id) || { label: '' }).label;

export const ESCALATION_REASONS = [
    { id: 'no_agreement', label: 'We can’t agree' },
    { id: 'no_response', label: 'The other side isn’t answering' },
    { id: 'evidence_disagreement', label: 'We disagree about the proof' },
    { id: 'other', label: 'Something else' },
];

export const escalationReasonLabel = (id) => (ESCALATION_REASONS.find((r) => r.id === id) || { label: String(id || '').replace(/_/g, ' ') }).label;

/** What an administrator can decide (and what it does to the payment report, in words the administrator reads before choosing) */
export const DECISIONS = [
    { id: 'not_established', label: 'Payment not established as received', effect: 'The provider’s report is withdrawn (disputed → withdrawn), attributed to you. The provider can report a corrected amount.' },
    { id: 'received', label: 'Payment received', effect: 'Recorded as WorkMate’s decision only. You can’t confirm for the customer, so the report is not changed and a separate financial correction is flagged.' },
    { id: 'partial', label: 'Part of the payment received', effect: 'Recorded with the amount, and flagged for a separate financial correction. The report is not changed.', needsAmount: true },
    { id: 'insufficient_evidence', label: 'Closed: not enough evidence', effect: 'No finding about payment. Nothing about the report changes.' },
];

export const decisionLabel = (id) => (DECISIONS.find((d) => d.id === id) || { label: '' }).label;

/** How a closed case is described to the two sides (who: 'customer' | 'provider') */
export function resolutionWords(resolution, who) {
    if (!resolution) return '';
    switch (resolution.type) {
        case 'agreed_received': return 'You both agreed the payment was received. It is now confirmed.';
        case 'agreed_not_received': return 'You both agreed the payment was not received. The report was withdrawn.';
        case 'provider_withdrew': return who === 'provider' ? 'You took the payment report back.' : 'The provider took the payment report back.';
        case 'not_established': return 'WorkMate decided the payment was not established as received. The report was withdrawn.';
        case 'received': return 'WorkMate decided the payment was received.';
        case 'partial': return `WorkMate decided that part of the payment was received${resolution.amount ? ` (UGX ${Number(resolution.amount).toLocaleString('en-UG')})` : ''}.`;
        case 'insufficient_evidence': return 'WorkMate closed the case: there wasn’t enough evidence either way.';
        default: return 'The case is closed.';
    }
}

export const CORRECTION_LABEL = 'Financial correction pending';

/** What the payment report itself currently says (separate from any decision about it) */
export const REPORT_STATE = {
    pending_confirmation: 'waiting for the customer',
    confirmed: 'confirmed by the customer',
    disputed: 'questioned by the customer',
    cancelled: 'taken back by the provider',
};

export const reportStateWords = (status) => REPORT_STATE[status] || String(status || '').replace(/_/g, ' ');

/**
 * For a decision that the payment record cannot carry by itself ("received", "partial"): says plainly that a decision was made and
 * that the payment report has NOT been changed. Empty when there is nothing pending.
 */
export function correctionWords(resolution, paymentStatus) {
    if (!resolution || !resolution.financial_correction_required) return '';
    const now = paymentStatus === 'cancelled'
        ? 'The provider has since taken the report back.'
        : `The payment report still shows as ${reportStateWords(paymentStatus)}.`;
    return `WorkMate has decided, but the payment record has not been changed yet. ${now} WorkMate does not confirm a payment on a customer’s behalf; the provider can take the report back and report the right amount for the customer to confirm.`;
}

/** One line of the timeline */
export function eventWords(e) {
    const who = e.by === 'admin' || e.by === 'system' ? 'WorkMate' : e.name;
    switch (e.event) {
        case 'opened': return `${who} said the payment isn’t right`;
        case 'discussion_started': return 'Talking it through';
        case 'message': return `${who} wrote`;
        case 'escalated': return `${who} asked WorkMate to step in`;
        case 'proposed': return `${who} suggested a way to settle it`;
        case 'proposal_cleared': return `${who} took the suggestion off the table`;
        case 'agreed': return 'Both sides agreed';
        case 'review_started': return 'WorkMate started reviewing';
        case 'info_requested': return 'WorkMate asked for more information';
        case 'decided': return 'WorkMate recorded a decision';
        case 'report_withdrawn': return 'The provider took the payment report back after the decision';
        case 'resolved': return 'Closed';
        default: return String(e.event || '').replace(/_/g, ' ');
    }
}

export const MAX_FILES = 5;
export const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ALLOWED = /\.(jpe?g|png|pdf)$/i;

/** Why a file can't be attached (the server checks again), or '' */
export function fileProblem(file) {
    if (!file || !ALLOWED.test(file.name || '')) return 'Attach photos (JPG or PNG) or PDF files only.';
    if (file.size > MAX_FILE_BYTES) return 'Each file can be up to 5 MB.';
    return '';
}

export function fileSize(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
    return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

export const isImage = (mime) => String(mime).startsWith('image/');

/** What is wrong with an escalation, field by field ('' when fine) */
export function validateEscalation(reason, note) {
    return {
        reason: ESCALATION_REASONS.some((r) => r.id === reason) ? '' : 'Choose why you are asking WorkMate to step in.',
        note: String(note || '').trim().length >= 5 ? '' : 'Say in a few words why (at least 5 characters).',
    };
}

/** What is wrong with a decision, field by field. `reported` is the payment report's amount. */
export function validateDecision(outcome, reason, amount, reported) {
    const errors = { outcome: '', reason: '', amount: '' };
    const d = DECISIONS.find((x) => x.id === outcome);
    if (!d) errors.outcome = 'Choose the decision.';
    if (String(reason || '').trim().length < 10) errors.reason = 'Write the reason (at least 10 characters). Both sides will read it.';
    if (d && d.needsAmount) {
        const n = Number(amount);
        if (!/^\d+$/.test(String(amount || '')) || n < 100) errors.amount = 'Enter the whole UGX amount that was received.';
        else if (n >= Number(reported)) errors.amount = 'A partial payment is less than the reported amount. If all of it was received, choose “Payment received”.';
    }
    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

/** The numbers on the queue tabs */
export const QUEUE_TABS = [
    { id: '', label: 'With WorkMate', count: (c) => c.escalated + c.under_review + c.resolved },
    { id: 'escalated', label: 'Waiting', count: (c) => c.escalated },
    { id: 'under_review', label: 'In review', count: (c) => c.under_review },
    { id: 'resolved', label: 'Closed', count: (c) => c.resolved },
    { id: 'open', label: 'Between the sides', count: (c) => c.open },
];
