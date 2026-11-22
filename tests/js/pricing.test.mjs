// Budgets and estimates as words, and as what the server is sent (Phase B1). Pure functions; nothing here is the
// price of a job. Same runner as the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ugx, budgetKind, budgetParts, budgetText, budgetFromApi, estimateParts, estimateFromApi,
    blankBudget, validateBudget, hasErrors, budgetBody, budgetFormText,
    blankEstimate, validateEstimate, estimateBody, rangeLabel,
} from '../../resources/js/utils/pricing.js';

/* ---------------- Reading a budget ---------------- */

test('with pricing options off a budget reads exactly as it always has', () => {
    assert.deepEqual(budgetParts({ budget: 50000, budgetBasis: 'per day', budgetType: 'range', budgetMin: 1000, budgetMax: 2000 }, false), { main: 'UGX 50,000', basis: 'per day' });
    assert.deepEqual(budgetParts({ budget: null, budgetBasis: '' }, false), { main: null, basis: '' });
    assert.equal(budgetText({ budget: null }, false), 'Budget to be discussed');
});

test('each kind of budget is said in words when pricing options are on', () => {
    assert.deepEqual(budgetParts({ budgetType: 'fixed', budget: 50000, budgetBasis: 'total' }), { main: 'UGX 50,000', basis: 'total' });
    assert.deepEqual(budgetParts({ budgetType: 'range', budgetMin: 30000, budgetMax: 60000, budgetBasis: 'per day' }), { main: 'UGX 30,000 – UGX 60,000', basis: 'per day' });
    assert.deepEqual(budgetParts({ budgetType: 'negotiable', budget: 40000 }), { main: 'Negotiable', basis: 'about UGX 40,000' });
    assert.deepEqual(budgetParts({ budgetType: 'negotiable', budget: null }), { main: 'Negotiable', basis: '' });
    assert.deepEqual(budgetParts({ budgetType: 'none' }), { main: null, basis: '' });
    assert.equal(budgetText({ budgetType: 'none' }), 'Budget to be discussed');
    assert.equal(budgetText({ budgetType: 'none' }, true, 'Open to offers'), 'Open to offers');
});

test('a budget without a stored kind is read from the older fields', () => {
    assert.equal(budgetKind({ budget: 50000, budgetBasis: 'fixed' }), 'fixed');
    assert.equal(budgetKind({ budget: 50000, budgetBasis: 'negotiable' }), 'negotiable');
    assert.equal(budgetKind({ budget: null }), 'none');
    assert.equal(budgetKind({ budgetType: '', budget: 1000 }), 'fixed');
    assert.equal(budgetKind({ budgetType: 'nonsense', budget: null }), 'none');
});

test('a request from the API is read in the same shape', () => {
    assert.deepEqual(budgetFromApi({ budget_type: 'range', budget: null, budget_basis: 'total', budget_min: 20000, budget_max: 45000 }),
        { budgetType: 'range', budget: null, budgetBasis: 'total', budgetMin: 20000, budgetMax: 45000 });
    assert.equal(budgetText(budgetFromApi({ budget_type: 'range', budget_basis: 'total', budget_min: 20000, budget_max: 45000 })), 'UGX 20,000 – UGX 45,000 · total');
});

/* ---------------- Estimates ---------------- */

test('an estimate is a guide, said as one', () => {
    assert.deepEqual(estimateParts({ estimateType: 'fixed', amount: 45000 }), { main: 'UGX 45,000', basis: 'estimate' });
    assert.deepEqual(estimateParts({ estimateType: 'range', amount: 30000, amountMax: 60000 }), { main: 'UGX 30,000 – UGX 60,000', basis: 'estimate' });
    assert.deepEqual(estimateParts({ estimateType: 'inspection_required', amount: null }), { main: 'Needs to see the job first', basis: 'no price yet' });
    // An older application: just an amount; none at all: nothing to show
    assert.deepEqual(estimateParts({ estimateType: null, amount: 38000 }), { main: 'UGX 38,000', basis: 'estimate' });
    assert.deepEqual(estimateParts({ estimateType: null, amount: null }), { main: null, basis: '' });
    // Switched off: the plain amount, as before
    assert.deepEqual(estimateParts({ estimateType: 'range', amount: 30000, amountMax: 60000 }, false), { main: 'UGX 30,000', basis: '' });
    assert.deepEqual(estimateFromApi({ estimate_type: 'range', amount: 1, amount_max: 2 }), { estimateType: 'range', amount: 1, amountMax: 2 });
});

/* ---------------- The budget form ---------------- */

test('a new budget form starts with no budget', () => {
    assert.deepEqual(blankBudget(), { type: 'none', amount: '', min: '', max: '', basis: 'total' });
    assert.equal(hasErrors(validateBudget(blankBudget())), false);
});

test('each budget type is checked on its own fields', () => {
    const fixed = (amount) => validateBudget({ ...blankBudget(), type: 'fixed', amount });
    assert.ok(fixed('').amount);
    assert.ok(fixed('999').amount);
    assert.ok(fixed('12.5').amount);
    assert.equal(fixed('1000').amount, '');
    assert.equal(fixed(50000).amount, '');

    const negotiable = (amount) => validateBudget({ ...blankBudget(), type: 'negotiable', amount });
    assert.equal(negotiable('').amount, '');
    assert.ok(negotiable('500').amount);
    assert.equal(negotiable('40000').amount, '');

    const range = (min, max) => validateBudget({ ...blankBudget(), type: 'range', min, max });
    assert.ok(range('', '').min && range('', '').max);
    assert.ok(range('30000', '').max);
    assert.ok(range('30000', '30000').max);
    assert.ok(range('30000', '20000').max);
    assert.ok(range('500', '20000').min);
    assert.equal(hasErrors(range('30000', '60000')), false);
});

test('a budget is sent as the server expects, and only the fields of its type', () => {
    assert.deepEqual(budgetBody({ type: 'fixed', amount: '50000', min: '1', max: '2', basis: 'per day' }), { budget_type: 'fixed', budget: 50000 });
    assert.deepEqual(budgetBody({ type: 'fixed', amount: '50000', min: '', max: '', basis: 'per day' }, true), { budget_type: 'fixed', budget: 50000, budget_basis: 'per day' });
    assert.deepEqual(budgetBody({ type: 'range', amount: '', min: '30000', max: '60000', basis: 'total' }, true), { budget_type: 'range', budget_min: 30000, budget_max: 60000, budget_basis: 'total' });
    assert.deepEqual(budgetBody({ type: 'negotiable', amount: '', min: '', max: '', basis: 'per day' }, true), { budget_type: 'negotiable', budget: null });
    assert.deepEqual(budgetBody({ type: 'none', amount: '99999', min: '1', max: '2', basis: 'total' }, true), { budget_type: 'none' });
});

test('the review step reads a budget form in words', () => {
    assert.equal(budgetFormText(blankBudget(), true), 'Open to offers');
    assert.equal(budgetFormText({ ...blankBudget(), type: 'fixed', amount: '50000', basis: 'per person' }, true), 'UGX 50,000 · per person');
    assert.equal(budgetFormText({ ...blankBudget(), type: 'range', min: '30000', max: '60000' }, false), 'UGX 30,000 – UGX 60,000');
    assert.equal(budgetFormText({ ...blankBudget(), type: 'negotiable', amount: '40000' }, true), 'Negotiable · about UGX 40,000');
});

/* ---------------- The estimate form ---------------- */

test('an application without an estimate carries no price, as it always could', () => {
    assert.deepEqual(blankEstimate(), { type: '', amount: '', max: '' });
    assert.equal(hasErrors(validateEstimate(blankEstimate())), false);
    assert.deepEqual(estimateBody(blankEstimate()), { amount: null });
});

test('each estimate type is checked and sent as the server expects', () => {
    assert.ok(validateEstimate({ type: 'fixed', amount: '', max: '' }).amount);
    assert.equal(hasErrors(validateEstimate({ type: 'fixed', amount: '45000', max: '' })), false);
    assert.ok(validateEstimate({ type: 'range', amount: '30000', max: '' }).max);
    assert.ok(validateEstimate({ type: 'range', amount: '30000', max: '30000' }).max);
    assert.ok(validateEstimate({ type: 'range', amount: '', max: '60000' }).amount);
    assert.equal(hasErrors(validateEstimate({ type: 'range', amount: '30000', max: '60000' })), false);
    // Seeing the job first needs no figure
    assert.equal(hasErrors(validateEstimate({ type: 'inspection_required', amount: '', max: '' })), false);

    assert.deepEqual(estimateBody({ type: 'fixed', amount: '45000', max: '999' }), { estimate_type: 'fixed', amount: 45000 });
    assert.deepEqual(estimateBody({ type: 'range', amount: '30000', max: '60000' }), { estimate_type: 'range', amount: 30000, amount_max: 60000 });
    assert.deepEqual(estimateBody({ type: 'inspection_required', amount: '45000', max: '60000' }), { estimate_type: 'inspection_required' });
});

/* ---------------- Listed ranges ---------------- */

test('a listed price range reads as a guide with its unit', () => {
    assert.equal(ugx(1500000), 'UGX 1,500,000');
    assert.equal(rangeLabel({ price: 20000, price_max: 45000 }, 'per visit'), 'UGX 20,000 – UGX 45,000 per visit');
    assert.equal(rangeLabel({ price: 20000, price_max: 45000 }), 'UGX 20,000 – UGX 45,000');
});

/* ---------------- Price proposals and the agreed price (Phase B2) ---------------- */

import {
    priceState, PRICE_WORDS, openKind, shortDate, blankProposal, validateProposal, proposalBody, eventWords, PRICE_STALE_CODES, MIN_SCOPE,
} from '../../resources/js/utils/pricing.js';

const version = (status, extra = {}) => ({ id: 1, revision_no: 1, amount: 150000, scope: 'Replace the pipes.', status, ...extra });

test('a price is only "agreed" when the server says an agreed version exists, never inferred', () => {
    assert.equal(priceState(null), 'none');
    assert.equal(priceState({ agreed: null, open: null, latest: null }), 'none');
    assert.equal(priceState({ agreed: null, open: version('pending_customer') }), 'proposed');
    assert.equal(priceState({ agreed: null, open: version('revision_requested') }), 'change_requested');
    assert.equal(priceState({ agreed: null, open: version('expired') }), 'expired');
    assert.equal(priceState({ agreed: version('agreed'), open: null }), 'agreed');
    // A proposal alone, however it looks, is not an agreement
    assert.notEqual(priceState({ agreed: null, open: version('pending_customer') }), 'agreed');
});

test('while a price is agreed, a newer one is an amendment and the agreed price still stands', () => {
    assert.equal(priceState({ agreed: version('agreed'), open: version('pending_customer') }), 'amendment_proposed');
    assert.equal(priceState({ agreed: version('agreed'), open: version('revision_requested') }), 'amendment_change_requested');
    assert.equal(priceState({ agreed: version('agreed'), open: version('expired') }), 'amendment_expired');
    assert.equal(openKind('amendment_proposed'), 'proposed');
    assert.equal(openKind('proposed'), 'proposed');
});

test('a negotiation that ended is said as ended, and inspection shows only when nothing else does', () => {
    assert.equal(priceState({ agreed: null, open: null, latest: version('rejected') }), 'closed');
    assert.equal(priceState({ agreed: null, open: null, latest: null }, true), 'inspection');
    assert.equal(priceState({ agreed: null, open: version('pending_customer') }, true), 'proposed');
    assert.equal(priceState({ agreed: version('agreed'), open: null }, true), 'agreed');
});

test('each state has simple words for both sides, with no financial jargon', () => {
    for (const key of ['inspection', 'proposed', 'change_requested', 'expired', 'agreed', 'closed']) {
        assert.ok(PRICE_WORDS[key].title, key);
        assert.ok(PRICE_WORDS[key].customer && PRICE_WORDS[key].provider, key);
    }
    assert.equal(PRICE_WORDS.proposed.title, 'Proposed price');
    assert.equal(PRICE_WORDS.agreed.title, 'Price agreed');
    assert.equal(PRICE_WORDS.inspection.title, 'Inspection needed');
    const all = JSON.stringify(PRICE_WORDS).toLowerCase();
    for (const word of ['ledger', 'commission', 'invoice', 'settlement', 'escrow']) assert.equal(all.includes(word), false, word);
});

test('the date an offer ends reads short, and a bad date reads as nothing', () => {
    assert.equal(shortDate(''), '');
    assert.equal(shortDate(null), '');
    assert.equal(shortDate('not a date'), '');
    assert.match(shortDate('2026-11-28T10:00:00+03:00'), /28/);
});

test('a price proposal is checked field by field', () => {
    const ok = { amount: '150000', scope: 'Replace the pipes and fit a tap.', exclusions: '' };
    assert.equal(hasErrors(validateProposal(ok)), false);
    assert.ok(validateProposal({ ...ok, amount: '' }).amount);
    assert.ok(validateProposal({ ...ok, amount: '999' }).amount);
    assert.ok(validateProposal({ ...ok, amount: '100000001' }).amount);
    assert.ok(validateProposal({ ...ok, amount: '12.5' }).amount);
    assert.ok(validateProposal({ ...ok, amount: '-5000' }).amount);
    assert.ok(validateProposal({ ...ok, amount: '150k' }).amount);
    assert.equal(validateProposal({ ...ok, amount: '1000' }).amount, '');
    assert.equal(validateProposal({ ...ok, amount: '100000000' }).amount, '');
    assert.ok(validateProposal({ ...ok, scope: 'x'.repeat(MIN_SCOPE - 1) }).scope);
    assert.equal(validateProposal({ ...ok, scope: 'x'.repeat(MIN_SCOPE) }).scope, '');
    assert.ok(validateProposal({ ...ok, scope: 'x'.repeat(2001) }).scope);
    assert.ok(validateProposal({ ...ok, exclusions: 'x'.repeat(1001) }).exclusions);
});

test('a proposal is sent as whole UGX with one key per try, and an empty exclusion is sent as none', () => {
    assert.deepEqual(blankProposal(), { amount: '', scope: '', exclusions: '' });
    assert.deepEqual(blankProposal(150000), { amount: '150000', scope: '', exclusions: '' });
    assert.deepEqual(blankProposal(null), { amount: '', scope: '', exclusions: '' });
    assert.deepEqual(proposalBody({ amount: ' 150000 ', scope: '  Replace the pipes.  ', exclusions: '   ' }, 'tok'),
        { amount: 150000, scope: 'Replace the pipes.', exclusions: null, client_token: 'tok' });
    assert.deepEqual(proposalBody({ amount: '90000', scope: 'Fit a tap.', exclusions: ' Tiles ' }, 't2'),
        { amount: 90000, scope: 'Fit a tap.', exclusions: 'Tiles', client_token: 't2' });
});

test('the history is told in plain words', () => {
    assert.equal(eventWords({ event: 'proposed', by: 'provider', amount: 150000 }), 'Provider proposed UGX 150,000');
    assert.equal(eventWords({ event: 'revised', by: 'provider', amount: 130000 }), 'New price proposed: UGX 130,000');
    assert.equal(eventWords({ event: 'accepted', by: 'customer', amount: 130000 }), 'Price agreed: UGX 130,000');
    assert.equal(eventWords({ event: 'change_requested', by: 'customer' }), 'A change was requested');
    assert.equal(eventWords({ event: 'rejected', by: 'customer' }), 'Price declined');
    assert.equal(eventWords({ event: 'expired', by: 'system' }), 'Offer ended without an answer');
    assert.equal(eventWords({ event: 'superseded', by: 'provider', revision_no: 1 }), 'Version 1 was replaced');
    assert.equal(eventWords({ event: 'something_new', by: 'system' }), 'something_new');
});

test('answers that mean the price changed meanwhile make the app look again', () => {
    for (const code of ['stale_proposal', 'proposal_expired', 'already_decided', 'request_status_conflict']) assert.ok(PRICE_STALE_CODES.includes(code), code);
    assert.equal(PRICE_STALE_CODES.includes('amount_invalid'), false);
});
