// The financial administration screens' pure helpers (Phase B8). Run with the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    TABS, REPORTS, ADJUSTMENT_TYPES, typeLabel, statusWords, defaultPeriod, periodProblem, query, csvUrl, blankAdjustment, validateAdjustment, hasErrors, adjustmentBody, overviewCards, SUBJECTS, EXCEPTION_KINDS,
} from '../../resources/js/admin/utils/finance.js';

test('the tabs and reports are the ones that exist, and nothing promises posting', () => {
    assert.deepEqual(TABS.map((t) => t.id), ['overview', 'reports', 'adjustments', 'exceptions', 'audit']);
    assert.deepEqual(REPORTS.map((r) => r.id), ['obligations', 'collections', 'outstanding', 'performance', 'adjustments']);
    assert.equal(statusWords('approved'), 'Approved (not posted)');
    assert.equal(statusWords('under_review'), 'Being investigated');
    assert.equal(statusWords('something_new'), 'something new');
    const all = JSON.stringify([REPORTS, EXCEPTION_KINDS]).toLowerCase();
    for (const word of ['penalt', 'suspend', 'refunded automatically', 'payout']) assert.equal(all.includes(word), false, word);
});

test('the default period is the last thirty days, inclusive, and a period is checked', () => {
    assert.deepEqual(defaultPeriod(new Date(2026, 10, 30)), { from: '2026-11-01', to: '2026-11-30' });
    assert.equal(periodProblem('2026-11-01', '2026-11-30'), '');
    assert.ok(periodProblem('2026-12-02', '2026-12-01'));
    assert.ok(periodProblem('', '2026-12-01'));
    assert.ok(periodProblem('2024-01-01', '2026-01-01'));
    assert.equal(periodProblem('2025-12-01', '2026-12-01'), '');
});

test('a query keeps only the filters that are filled in, and the CSV link carries them', () => {
    assert.equal(query({ from: '2026-11-01', to: '2026-11-30', provider: '' }), 'from=2026-11-01&to=2026-11-30');
    assert.equal(query({ from: 'a', provider: 4 }, { page: 2 }), 'from=a&provider=4&page=2');
    assert.equal(csvUrl('collections', { from: '2026-11-01', to: '2026-11-30', provider: 9 }), '/api/v1/admin/financial/reports/collections.csv?from=2026-11-01&to=2026-11-30&provider=9');
});

test('an adjustment request is checked field by field and sent as whole numbers', () => {
    const ok = { ...blankAdjustment(), provider_id: '7', type: 'accounting_error', amount_ugx: '5000', subject: 'cycle_id', subject_id: '12', reason: 'The statement shows this was counted twice.' };
    assert.equal(hasErrors(validateAdjustment(ok)), false);
    assert.ok(validateAdjustment({ ...ok, provider_id: '' }).provider_id);
    assert.ok(validateAdjustment({ ...ok, type: 'refund' }).type);
    for (const bad of ['', '0', '1.5', '-5', 'lots', '100000001']) assert.ok(validateAdjustment({ ...ok, amount_ugx: bad }).amount_ugx, bad);
    assert.ok(validateAdjustment({ ...ok, subject_id: '' }).subject_id);
    assert.ok(validateAdjustment({ ...ok, reason: 'Too short' }).reason);
    const body = adjustmentBody({ ...ok, reference: ' MTN line 14 ', provider_reason: '' }, 'tok');
    assert.deepEqual(body, { provider_id: 7, type: 'accounting_error', direction: 'decrease', amount_ugx: 5000, reason: 'The statement shows this was counted twice.', client_token: 'tok', cycle_id: 12, reference: 'MTN line 14' });
    assert.equal(adjustmentBody({ ...ok, subject: 'exception_id', subject_id: '3' }, 't').exception_id, 3);
    assert.equal(SUBJECTS.length, 6);
    assert.equal(typeLabel('accounting_error'), 'A verified accounting error');
    assert.equal(ADJUSTMENT_TYPES.length, 4);
});

test('the overview keeps the period and the position apart and never mixes pending with collected', () => {
    assert.deepEqual(overviewCards(null), { period: [], asOf: [] });
    const o = {
        period: { assessed_ugx: 40000, reversed_ugx: 5000, net_obligations_ugx: 35000, collected_ugx: 20000 },
        as_of: { outstanding_ugx: 20000, overdue_ugx: 0, pending_verification: { count: 2, amount_ugx: 8000 }, open_exceptions: { count: 1, amount_ugx: 20000 } },
    };
    const c = overviewCards(o);
    assert.deepEqual(c.period.map((x) => x.key), ['assessed', 'reversed', 'net', 'collected']);
    assert.deepEqual(c.asOf.map((x) => x.key), ['outstanding', 'overdue', 'pending', 'exceptions']);
    assert.equal(c.period.find((x) => x.key === 'collected').value, 20000);
    assert.equal(c.asOf.find((x) => x.key === 'pending').value, 8000);
    assert.equal(c.asOf.find((x) => x.key === 'pending').count, 2);
    assert.match(c.period.find((x) => x.key === 'collected').hint, /Pending or rejected reports are never counted/);
});
