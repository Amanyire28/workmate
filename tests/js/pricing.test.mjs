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
