/**
 * What a provider can do with a received request, and which one thing to put first.
 *
 * Pure functions with one import, so they run the same in the app and in the Node tests. Nothing here is a business rule:
 * every condition below was already in the screens (ReceivedRequestCard, PaymentProviderActions) and is only gathered in one
 * place so the card can show ONE primary action and keep every other permitted action in "More". The server still checks
 * each action again.
 *
 * ctx = { pricingOn: boolean }  (the "flexible pricing" switch, as the app already knows it)
 */
import { priceState, openKind } from './pricing.js';

/** The friendly work tabs and the existing statuses they stand for (no new states) */
export const WORK_TABS = [
    { id: 'new', label: 'New', statuses: ['open'], empty: 'No new requests right now.' },
    { id: 'upcoming', label: 'Upcoming', statuses: ['scheduled'], empty: 'No upcoming bookings.' },
    { id: 'working', label: 'Working', statuses: ['in_progress'], empty: 'No jobs in progress.' },
    { id: 'history', label: 'History', statuses: ['completed', 'cancelled'], empty: 'Completed and cancelled jobs show here.' },
];

export const tabOf = (req) => (WORK_TABS.find((t) => t.statuses.includes(req.status)) || WORK_TABS[0]).id;

export function tabCounts(requests) {
    const out = { new: 0, upcoming: 0, working: 0, history: 0 };
    (requests || []).forEach((r) => { out[tabOf(r)]++; });

    return out;
}

/** The tab to open on: new requests first, then jobs in progress, then upcoming, then history; "new" (and its empty message) when there is nothing */
export function defaultTab(requests) {
    const c = tabCounts(requests);
    if (c.new) return 'new';
    if (c.working) return 'working';
    if (c.upcoming) return 'upcoming';
    if (c.history) return 'history';

    return 'new';
}

/* ---------------- Conditions (copied from the screens that already used them) ---------------- */

const LIVE = ['open', 'scheduled', 'in_progress'];

/** Owner or manager, a job still to be done, made with the booking system, pricing options on, and a price block sent */
export const canProposePrice = (req, ctx) => !!(ctx.pricingOn && req.pricing && req.can_manage_pricing && !req.is_legacy && LIVE.includes(req.status));

export const canTakeBack = (req) => !!(req.pricing && req.pricing.open && ['pending_customer', 'revision_requested'].includes(req.pricing.open.status));

export const canMarkInspection = (req, ctx) => !!(ctx.pricingOn && req.can_manage_pricing && !req.is_legacy && LIVE.includes(req.status));

/** Owner or manager, a job that was accepted (also once done), made with the booking system, pricing options on */
export const canReportPayment = (req, ctx) => !!(ctx.pricingOn && req.payments && req.can_manage_pricing && !req.is_legacy
    && ['scheduled', 'in_progress', 'completed'].includes(req.status));

/** What can still be reported against the final price (null: no final price, nothing to compare with) */
export function paymentLeft(payments) {
    return payments && payments.final_price ? Math.max(payments.final_price.amount - payments.totals.reported, 0) : null;
}

/** "Report a payment" is offered unless the whole final price has been reported */
export const reportPaymentOffered = (req, ctx) => canReportPayment(req, ctx) && paymentLeft(req.payments) !== 0;

const reports = (req) => (req.payments && req.payments.reports) || [];

/** Payments the customer says are not right */
export const questionedCount = (req) => reports(req).filter((r) => r.status === 'disputed').length;

/** Payments waiting for the customer's answer */
export const waitingReportCount = (req) => reports(req).filter((r) => r.status === 'pending_confirmation').length;

export const proposedTime = (req) => !!(req.schedule && req.schedule.proposed_start);

/** The state of the price, in the same words as the price card (agreed / proposed / change_requested / amendment_... / expired / closed / none) */
export const priceStateOf = (req) => priceState(req.pricing, !!req.inspection_required);

/** The customer asked for a different price and the provider can send one */
export const priceChangeAsked = (req, ctx) => canProposePrice(req, ctx) && !!req.pricing && openKind(priceStateOf(req)) === 'change_requested';

/** A price proposal is waiting for the customer */
export const priceWaiting = (req) => !!req.pricing && openKind(priceStateOf(req)) === 'proposed';

export function priceActionLabel(req) {
    const p = req.pricing;
    if (!p) return 'Propose a price';
    if (p.agreed && !p.open) return 'Change the agreed price';
    if (p.open && p.open.status === 'expired') return 'Send the price again';
    if (p.open) return 'Send a new price';

    return 'Propose a price';
}

/* ---------------- Needs your attention ---------------- */

/** Which attention conditions a job has. A job can have several; each category counts it once. */
export function attentionOf(req, ctx) {
    return {
        new: req.status === 'open' && !proposedTime(req),
        price: priceChangeAsked(req, ctx),
        payment: questionedCount(req) > 0,
    };
}

export const ATTENTION = [
    { id: 'new', one: 'new request', many: 'new requests', icon: 'bi-inbox' },
    { id: 'price', one: 'price change asked', many: 'price changes asked', icon: 'bi-cash-coin' },
    { id: 'payment', one: 'payment questioned', many: 'payments questioned', icon: 'bi-chat-left-text' },
];

/** The ids of the jobs in each attention category */
export function attentionIndex(requests, ctx) {
    const out = { new: [], price: [], payment: [] };
    (requests || []).forEach((r) => {
        const a = attentionOf(r, ctx);
        Object.keys(out).forEach((k) => { if (a[k]) out[k].push(r.id); });
    });

    return out;
}

/** Where to look for one category: the tab when all its jobs are in one, otherwise '' (show them across tabs) */
export function tabFor(requests, ids) {
    const tabs = Array.from(new Set((requests || []).filter((r) => ids.includes(r.id)).map(tabOf)));

    return tabs.length === 1 ? tabs[0] : '';
}

/* ---------------- The one primary action, and everything else ---------------- */

/**
 * The primary action: what the provider should do next, from the whole picture (status, a time they suggested, a price the
 * customer asked to change, a payment the customer questioned, their permissions, legacy jobs, the pricing switch).
 * null when nothing is waiting on them. kind: 'send' (an action on the server), 'sheet' (opens a form), 'details' (shows the
 * part of the card that needs a look).
 */
export function primaryAction(req, ctx) {
    const s = req.status;
    if (s === 'cancelled') return null;
    if (['scheduled', 'in_progress', 'completed'].includes(s) && questionedCount(req) > 0) {
        return { key: 'review_payment', label: 'Review payment', icon: 'bi-chat-left-text', kind: 'details' };
    }
    if (s === 'open') {
        // Already suggested another time: it is the customer's turn, so nothing is pushed at the provider
        return proposedTime(req) ? null : { key: 'accept', label: 'Accept', icon: 'bi-check2', kind: 'send' };
    }
    if (priceChangeAsked(req, ctx)) return { key: 'send_price', label: priceActionLabel(req), icon: 'bi-cash-coin', kind: 'sheet' };
    if (s === 'scheduled') return { key: 'start', label: 'Start work', icon: 'bi-play-fill', kind: 'send' };
    if (s === 'in_progress') return { key: 'complete', label: 'Mark as done', icon: 'bi-check2-all', kind: 'send' };
    if (s === 'completed' && reportPaymentOffered(req, ctx)) return { key: 'report_payment', label: 'Report a payment', icon: 'bi-cash-coin', kind: 'sheet' };

    return null;
}

/** Every other action this job allows now, for the "More" sheet. Chat is a button of its own, so it is not here. */
export function moreActions(req, ctx) {
    const s = req.status;
    const all = [];
    if (s === 'open') {
        all.push({ key: 'accept', label: proposedTime(req) ? 'Accept at the original time' : 'Accept', icon: 'bi-check2' });
        if (!req.is_legacy) all.push({ key: 'propose_time', label: 'Suggest another time', icon: 'bi-clock-history' });
        all.push({ key: 'decline', label: 'Decline', icon: 'bi-x-lg', tone: 'danger' });
    } else if (s === 'scheduled') {
        all.push({ key: 'start', label: 'Start work', icon: 'bi-play-fill' });
        all.push({ key: 'complete', label: 'Mark as done', icon: 'bi-check2-all' });
        all.push({ key: 'propose_time', label: 'Change time', icon: 'bi-clock-history' });
        all.push({ key: 'cancel', label: 'Cancel booking', icon: 'bi-x-circle', tone: 'danger' });
    } else if (s === 'in_progress') {
        all.push({ key: 'complete', label: 'Mark as done', icon: 'bi-check2-all' });
    }
    if (canProposePrice(req, ctx)) all.push({ key: 'send_price', label: priceActionLabel(req), icon: 'bi-cash-coin' });
    if (canProposePrice(req, ctx) && canTakeBack(req)) all.push({ key: 'withdraw_price', label: 'Take the price back', icon: 'bi-arrow-counterclockwise' });
    if (canMarkInspection(req, ctx)) {
        if (!req.inspection_required) all.push({ key: 'inspect', label: 'I need to see the job first', icon: 'bi-search' });
        else {
            all.push({ key: 'inspect_edit', label: 'Edit the inspection note', icon: 'bi-pencil' });
            all.push({ key: 'inspect_clear', label: 'No need to inspect', icon: 'bi-x-lg' });
        }
    }
    if (reportPaymentOffered(req, ctx)) all.push({ key: 'report_payment', label: 'Report a payment', icon: 'bi-cash-coin' });
    all.push({ key: 'report_customer', label: 'Report this customer', icon: 'bi-flag', tone: 'muted' });

    const first = primaryAction(req, ctx);

    return all.filter((a) => !first || a.key !== first.key);
}

/* ---------------- What to say ---------------- */

/**
 * Plain words for the card: one "next" line (what the provider should do, or that they need do nothing) and, separately, what is
 * only waiting on the customer, so a waiting state never reads like a task.
 * Returns { next: {text, tone}, waiting: [text] }.
 */
export function describe(req, ctx, money = (n) => String(n)) {
    const waiting = [];
    let next;
    const questioned = questionedCount(req) > 0;
    const s = req.status;
    if (s === 'cancelled') {
        next = { text: req.schedule && req.schedule.cancelled_by ? 'This job was cancelled.' : 'You declined this request.', tone: 'neutral' };
    } else if (questioned) {
        next = { text: 'The customer says a payment isn’t right. Have a look.', tone: 'warning' };
    } else if (s === 'open' && proposedTime(req)) {
        next = { text: 'You suggested another time.', tone: 'neutral' };
        waiting.push('Waiting for the customer to answer your suggested time.');
    } else if (s === 'open') {
        next = { text: 'Reply to this request: accept it, or suggest another time.', tone: 'info' };
    } else if (priceChangeAsked(req, ctx)) {
        next = { text: 'The customer asked for a different price. Send a new price.', tone: 'warning' };
    } else if (s === 'scheduled') {
        next = { text: 'Start the work when you arrive.', tone: 'info' };
    } else if (s === 'in_progress') {
        next = { text: 'Mark the job as done when the work is finished.', tone: 'info' };
    } else if (s === 'completed') {
        next = reportPaymentOffered(req, ctx)
            ? { text: 'Report any payment the customer made you.', tone: 'info' }
            : { text: 'This job is done.', tone: 'success' };
    } else {
        next = { text: '', tone: 'neutral' };
    }
    if (s !== 'cancelled') {
        if (priceWaiting(req)) waiting.push('Your price is waiting for the customer to answer.');
        const w = reports(req).filter((r) => r.status === 'pending_confirmation');
        if (w.length) waiting.push(`Waiting for the customer to confirm ${money(w.reduce((n, r) => n + Number(r.amount || 0), 0))}.`);
    }

    return { next, waiting };
}

/** The price to show on the card, saying which it is: an agreed price (or the locked final price), else the customer's budget (never mixed up) */
export function priceLabel(req, budgetText = '') {
    const final = req.payments && req.payments.final_price ? req.payments.final_price.amount : null;
    const agreed = req.pricing && req.pricing.agreed ? req.pricing.agreed.amount : null;
    if (final !== null && final !== undefined) return { kind: 'agreed', label: 'Final price', amount: final };
    if (agreed !== null && agreed !== undefined) return { kind: 'agreed', label: 'Agreed price', amount: agreed };
    if (budgetText) return { kind: 'budget', label: 'Customer’s budget', text: budgetText };

    return null;
}

/** True when something on the job needs the provider's eye, so the card opens its Details by itself */
export function needsDetails(req, ctx) {
    return questionedCount(req) > 0 || priceChangeAsked(req, ctx) || waitingReportCount(req) > 0
        || reports(req).some((r) => r.status === 'cancelled' && !reports(req).some((x) => x.corrects_id === r.id));
}
