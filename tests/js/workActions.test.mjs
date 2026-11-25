import test from 'node:test';
import assert from 'node:assert/strict';
import {
    WORK_TABS, tabOf, tabCounts, defaultTab, primaryAction, moreActions, attentionOf, attentionIndex, tabFor, describe, priceLabel, needsDetails,
    canProposePrice, reportPaymentOffered,
} from '../../resources/js/utils/workActions.js';

const ON = { pricingOn: true };
const OFF = { pricingOn: false };

/** A received request as the API sends it, with the parts that matter here */
const req = (over = {}) => ({
    id: 1, status: 'open', is_legacy: false, can_manage_pricing: true, inspection_required: false,
    schedule: { start: null, proposed_start: null, cancelled_by: null },
    pricing: { agreed: null, open: null, latest: null },
    payments: { final_price: null, totals: { reported: 0, pending: 0, disputed: 0, confirmed: 0 }, reports: [] },
    ...over,
});
const agreed = (amount = 5000000) => ({ agreed: { amount }, open: null, latest: { status: 'agreed' } });
const revision = () => ({ agreed: { amount: 5000000 }, open: { status: 'revision_requested', id: 9 }, latest: null });
const proposed = () => ({ agreed: null, open: { status: 'pending_customer', id: 9 }, latest: null });
const withReports = (reports, extra = {}) => ({ final_price: { amount: 5000000 }, totals: { reported: 200000, pending: 0, disputed: 0, confirmed: 0 }, reports, ...extra });
const keys = (list) => list.map((a) => a.key);

test('the friendly tabs map to the existing statuses and add no new state', () => {
    assert.deepEqual(WORK_TABS.map((t) => t.id), ['new', 'upcoming', 'working', 'history']);
    assert.equal(tabOf(req({ status: 'open' })), 'new');
    assert.equal(tabOf(req({ status: 'scheduled' })), 'upcoming');
    assert.equal(tabOf(req({ status: 'in_progress' })), 'working');
    assert.equal(tabOf(req({ status: 'completed' })), 'history');
    assert.equal(tabOf(req({ status: 'cancelled' })), 'history');
    // every status the app knows lands in exactly one tab
    ['open', 'scheduled', 'in_progress', 'completed', 'cancelled'].forEach((s) => assert.equal(WORK_TABS.filter((t) => t.statuses.includes(s)).length, 1));
});

test('the tab to open on: new, then working, then upcoming, then history, and new when empty', () => {
    assert.equal(defaultTab([]), 'new');
    assert.equal(defaultTab([req({ status: 'completed' })]), 'history');
    assert.equal(defaultTab([req({ status: 'scheduled' }), req({ id: 2, status: 'completed' })]), 'upcoming');
    assert.equal(defaultTab([req({ status: 'scheduled' }), req({ id: 2, status: 'in_progress' })]), 'working');
    assert.equal(defaultTab([req({ status: 'in_progress' }), req({ id: 2, status: 'open' })]), 'new');
    assert.deepEqual(tabCounts([req(), req({ id: 2, status: 'cancelled' }), req({ id: 3, status: 'completed' })]), { new: 1, upcoming: 0, working: 0, history: 2 });
});

test('one primary action per status', () => {
    assert.equal(primaryAction(req({ status: 'open' }), ON).key, 'accept');
    assert.equal(primaryAction(req({ status: 'scheduled' }), ON).key, 'start');
    assert.equal(primaryAction(req({ status: 'in_progress' }), ON).key, 'complete');
    assert.equal(primaryAction(req({ status: 'cancelled' }), ON), null);
});

test('a completed job leads with reporting a payment only while some of the price is unreported and the provider may report', () => {
    const done = req({ status: 'completed', payments: withReports([]) });
    assert.equal(primaryAction(done, ON).key, 'report_payment');
    assert.equal(primaryAction(req({ status: 'completed', payments: withReports([], { totals: { reported: 5000000 } }) }), ON), null, 'all of it reported');
    assert.equal(primaryAction(req({ status: 'completed', can_manage_pricing: false, payments: withReports([]) }), ON), null, 'not the owner or a manager');
    assert.equal(primaryAction(done, OFF), null, 'pricing options off');
    assert.equal(primaryAction(req({ status: 'completed', is_legacy: true, payments: withReports([]) }), ON), null, 'legacy job');
});

test('a time the provider already suggested leaves nothing pushed at them, and says it is the customer\'s turn', () => {
    const r = req({ status: 'open', schedule: { start: null, proposed_start: '2026-12-01 10:00', cancelled_by: null } });
    assert.equal(primaryAction(r, ON), null);
    assert.ok(keys(moreActions(r, ON)).includes('accept'), 'accepting the original time is still possible');
    const d = describe(r, ON);
    assert.equal(d.next.tone, 'neutral');
    assert.ok(d.waiting.some((w) => /Waiting for the customer/.test(w)));
    assert.equal(attentionOf(r, ON).new, false, 'not counted as needing the provider');
});

test('a price the customer asked to change comes first (not on a brand-new request, which still leads with Accept)', () => {
    assert.equal(primaryAction(req({ status: 'scheduled', pricing: revision() }), ON).key, 'send_price');
    assert.equal(primaryAction(req({ status: 'in_progress', pricing: revision() }), ON).key, 'send_price');
    assert.equal(primaryAction(req({ status: 'open', pricing: revision() }), ON).key, 'accept');
    // the displaced status action is still there
    assert.ok(keys(moreActions(req({ status: 'scheduled', pricing: revision() }), ON)).includes('start'));
    // without permission or with pricing off it is not asked of them
    assert.equal(primaryAction(req({ status: 'scheduled', can_manage_pricing: false, pricing: revision() }), ON).key, 'start');
    assert.equal(primaryAction(req({ status: 'scheduled', pricing: revision() }), OFF).key, 'start');
});

test('a price still waiting for the customer is a waiting line, not a task', () => {
    const r = req({ status: 'scheduled', pricing: proposed() });
    assert.equal(primaryAction(r, ON).key, 'start');
    assert.ok(describe(r, ON).waiting.some((w) => /price is waiting/.test(w)));
    assert.equal(attentionOf(r, ON).price, false);
});

test('a questioned payment leads with a review, and the status action stays reachable', () => {
    const q = { status: 'disputed', amount: 100000, id: 4 };
    const r = req({ status: 'in_progress', payments: withReports([q]) });
    assert.equal(primaryAction(r, ON).key, 'review_payment');
    assert.equal(primaryAction(r, ON).kind, 'details');
    assert.ok(keys(moreActions(r, ON)).includes('complete'));
    assert.equal(attentionOf(r, ON).payment, true);
    assert.equal(describe(r, ON).next.tone, 'warning');
    assert.equal(needsDetails(r, ON), true);
});

test('every permitted action stays reachable: primary plus More covers what the old card offered', () => {
    const all = (r, ctx = ON) => new Set([primaryAction(r, ctx)?.key, ...keys(moreActions(r, ctx))].filter(Boolean));
    const open = all(req({ status: 'open', pricing: { agreed: null, open: null, latest: null }, payments: withReports([]) }));
    ['accept', 'propose_time', 'decline', 'send_price', 'inspect', 'report_customer'].forEach((k) => assert.ok(open.has(k), `open: ${k}`));
    const booked = all(req({ status: 'scheduled', pricing: proposed(), payments: withReports([]) }));
    ['start', 'complete', 'propose_time', 'cancel', 'send_price', 'withdraw_price', 'inspect', 'report_payment', 'report_customer'].forEach((k) => assert.ok(booked.has(k), `scheduled: ${k}`));
    const working = all(req({ status: 'in_progress', pricing: agreed(), payments: withReports([]) }));
    ['complete', 'send_price', 'inspect', 'report_payment'].forEach((k) => assert.ok(working.has(k), `in progress: ${k}`));
    const finished = all(req({ status: 'completed', payments: withReports([]) }));
    assert.ok(finished.has('report_payment') && finished.has('report_customer'));
    assert.ok(all(req({ status: 'cancelled' })).has('report_customer'));
});

test('permissions and switches are carried over unchanged', () => {
    const legacy = req({ status: 'open', is_legacy: true });
    assert.ok(!keys(moreActions(legacy, ON)).includes('propose_time'), 'a legacy request cannot be given another time');
    assert.ok(keys(moreActions(legacy, ON)).includes('decline'));
    const member = req({ status: 'in_progress', can_manage_pricing: false, pricing: agreed(), payments: withReports([]) });
    ['send_price', 'inspect', 'report_payment', 'withdraw_price'].forEach((k) => assert.ok(!keys(moreActions(member, ON)).includes(k), `member: ${k}`));
    const off = req({ status: 'in_progress', pricing: agreed(), payments: withReports([]) });
    ['send_price', 'inspect', 'report_payment'].forEach((k) => assert.ok(!keys(moreActions(off, OFF)).includes(k), `pricing off: ${k}`));
    assert.equal(canProposePrice(req({ status: 'completed', pricing: agreed() }), ON), false, 'a finished job is not repriced');
    assert.equal(reportPaymentOffered(req({ status: 'open', payments: withReports([]) }), ON), false, 'nothing to report before it is accepted');
});

test('inspection: marking, then editing or clearing', () => {
    assert.ok(keys(moreActions(req({ status: 'open' }), ON)).includes('inspect'));
    const marked = keys(moreActions(req({ status: 'open', inspection_required: true }), ON));
    assert.ok(marked.includes('inspect_edit') && marked.includes('inspect_clear') && !marked.includes('inspect'));
});

test('attention counts each job once per category and never sums categories', () => {
    const a = req({ id: 1, status: 'open' });
    const b = req({ id: 2, status: 'scheduled', pricing: revision(), payments: withReports([{ status: 'disputed', amount: 1, id: 1 }]) });
    const c = req({ id: 3, status: 'open' });
    const done = req({ id: 4, status: 'completed' });
    const idx = attentionIndex([a, b, c, done], ON);
    assert.deepEqual(idx, { new: [1, 3], price: [2], payment: [2] });
    // job 2 has two conditions and appears once in each
    assert.equal(idx.price.length + idx.payment.length, 2);
    assert.equal(attentionIndex([done], ON).new.length + attentionIndex([done], ON).price.length + attentionIndex([done], ON).payment.length, 0, 'nothing to show');
});

test('an attention category selects the tab when its jobs are all in one, otherwise shows them across tabs', () => {
    const list = [req({ id: 1, status: 'open' }), req({ id: 2, status: 'open' }), req({ id: 3, status: 'scheduled', pricing: revision() }), req({ id: 4, status: 'in_progress', pricing: revision() })];
    const idx = attentionIndex(list, ON);
    assert.equal(tabFor(list, idx.new), 'new');
    assert.equal(tabFor(list, idx.price), '', 'upcoming and working: show across tabs');
    assert.equal(tabFor([list[2]], [3]), 'upcoming');
});

test('the card says which price it is showing: agreed, final, or the customer\'s budget', () => {
    assert.deepEqual(priceLabel(req({ pricing: agreed(300000) }), 'UGX 100,000'), { kind: 'agreed', label: 'Agreed price', amount: 300000 });
    assert.equal(priceLabel(req({ payments: withReports([]) }), 'UGX 100,000').label, 'Final price');
    assert.deepEqual(priceLabel(req(), 'UGX 100,000'), { kind: 'budget', label: 'Customer’s budget', text: 'UGX 100,000' });
    assert.equal(priceLabel(req(), ''), null);
});

test('waiting on the customer to confirm a payment is said as waiting', () => {
    const r = req({ status: 'completed', payments: withReports([{ status: 'pending_confirmation', amount: 200000, id: 2 }]) });
    assert.ok(describe(r, ON, (n) => `UGX ${n}`).waiting.some((w) => w.includes('UGX 200000')));
    assert.equal(needsDetails(r, ON), true);
});
