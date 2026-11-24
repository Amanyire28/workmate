// My Finances' pure helpers (Phase B9). Run with the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    PERIODS, periodLabel, periodProblem, query, statementUrl, TABS, tabIds, lineWords, paymentState, disputeStage, methodLabel, roleWords, ugx, customerNote,
} from '../../resources/js/utils/myFinances.js';

test('the five periods are the ones asked for, in plain words', () => {
    assert.deepEqual(PERIODS.map((p) => p.id), ['this_week', 'this_month', 'last_month', 'all', 'custom']);
    assert.equal(periodLabel('all'), 'All time');
    assert.equal(periodLabel('nonsense'), '');
});

test('chosen dates are checked before anything is asked of the server', () => {
    assert.equal(periodProblem('this_month', '', ''), '');
    assert.equal(periodProblem('custom', '2026-12-01', '2026-12-31'), '');
    assert.equal(periodProblem('custom', '2026-12-31', '2026-12-01'), 'The first day must be before the last day.');
    assert.ok(periodProblem('custom', '', '2026-12-01'));
    assert.ok(periodProblem('custom', '2018-01-01', '2026-12-01'));
});

test('the query carries dates only for a chosen period, plus whatever else is filled in', () => {
    assert.equal(query('this_month', '2026-01-01', '2026-01-31'), 'period=this_month');
    assert.equal(query('custom', '2026-12-01', '2026-12-31'), 'period=custom&from=2026-12-01&to=2026-12-31');
    assert.equal(query('all', '', '', { page: 2, status: '' }), 'period=all&page=2');
    assert.equal(statementUrl('commission', 'pdf', 'last_month', '', ''), '/api/v1/me/finance/statements/commission.pdf?period=last_month');
    assert.equal(statementUrl('payments', 'csv', 'custom', '2026-12-01', '2026-12-05'), '/api/v1/me/finance/statements/payments.csv?period=custom&from=2026-12-01&to=2026-12-05');
});

test('the tabs are the ones that exist, and the statement kinds stay separate', () => {
    assert.deepEqual(tabIds(), ['overview', 'statement', 'payments', 'settlements', 'to-workmate', 'disputes', 'downloads']);
    assert.equal(TABS.find((t) => t.id === 'statement').label, 'Commission statement');
    assert.equal(TABS.find((t) => t.id === 'payments').label, 'Customer payments');
});

test('a payment is only "paid" once the customer confirmed it', () => {
    assert.equal(paymentState('confirmed').label, 'Confirmed by the customer');
    assert.equal(paymentState('pending_confirmation').label, 'Waiting for the customer');
    assert.match(paymentState('pending_confirmation').why, /Not counted as paid/);
    assert.match(paymentState('disputed').why, /not counted as paid/);
    assert.equal(paymentState('mystery').label, 'Waiting for the customer');
    for (const word of ['received', 'earned', 'wallet', 'balance held']) {
        assert.equal(JSON.stringify(['pending_confirmation', 'disputed'].map((s) => paymentState(s))).toLowerCase().includes(word), false, word);
    }
});

test('ledger lines, dispute stages, methods and roles read in plain words', () => {
    assert.equal(lineWords('assessment').label, 'Commission added');
    assert.equal(lineWords('reversal').label, 'Taken off');
    assert.equal(lineWords('settlement').label, 'Payment to WorkMate verified');
    assert.equal(lineWords('odd_one').label, 'odd one');
    assert.equal(disputeStage('escalated'), 'Waiting for WorkMate');
    assert.equal(methodLabel('mobile_money'), 'Mobile money');
    assert.equal(roleWords('manager'), 'Manager');
    assert.equal(roleWords('member'), 'Team member');
});

test('money is shown in whole UGX and a note says what is not counted as paid', () => {
    assert.equal(ugx(1250000), 'UGX 1,250,000');
    assert.equal(ugx(0), 'UGX 0');
    assert.equal(ugx(null), 'UGX 0');
    assert.equal(customerNote({ awaiting: { count: 2 }, questioned: { count: 1 } }), 'Not counted as paid: 2 waiting for the customer, 1 questioned.');
    assert.equal(customerNote({ awaiting: { count: 0 }, questioned: { count: 0 } }), '');
    assert.equal(customerNote(null), '');
});
