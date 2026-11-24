import test from 'node:test';
import assert from 'node:assert/strict';
import {
    UNAVAILABLE, methodName, amountProblem, normalizePhone, phoneProblem, leftAfter, methodChoices, attemptWords, isFinal, manualProblems, hasErrors,
    showPayButton, historyWords,
} from '../../resources/js/utils/payCommission.js';
import {
    statusWords, methodLabel, verificationLabel, buildQuery, canDecide, verifyProblem, rejectProblem, eventWords, auditWords, FILTER_STATUSES,
} from '../../resources/js/admin/utils/commissionPayments.js';

test('the unavailable message is the one short sentence and nothing else', () => {
    assert.equal(UNAVAILABLE, 'Service currently not available.');
});

test('an amount must be whole UGX, at least 100 and never more than can be paid now', () => {
    assert.equal(amountProblem('10000', 30000), '');
    assert.equal(amountProblem('30000', 30000), '');
    assert.ok(amountProblem('30001', 30000).includes('30,000'));
    assert.ok(amountProblem('', 30000));
    assert.ok(amountProblem('99', 30000));
    assert.ok(amountProblem('1.5', 30000));
    assert.ok(amountProblem('-500', 30000));
    assert.ok(amountProblem('abc', 30000));
    assert.equal(amountProblem('100', 0), 'Nothing more can be paid right now.');
});

test('phone numbers are Ugandan mobiles in any usual spelling', () => {
    for (const ok of ['0772123456', '0772 123 456', '+256772123456', '256-772-123-456', '(0772) 123456']) assert.equal(normalizePhone(ok), '0772123456', ok);
    for (const bad of ['', '12345', '0212345678', '+12025550100', 'abc', '077212345']) {
        assert.equal(normalizePhone(bad), '', bad);
        assert.ok(phoneProblem(bad), bad);
    }
    assert.equal(phoneProblem('0772123456'), '');
});

test('what is left after a payment never goes below zero', () => {
    assert.equal(leftAfter(30000, '12000'), 18000);
    assert.equal(leftAfter(30000, 50000), 0);
    assert.equal(leftAfter(30000, ''), 30000);
});

test('methods keep their names, styles and availability, and an unavailable one is not usable', () => {
    const list = methodChoices([{ id: 'mtn_momo', available: false }, { id: 'airtel_money', available: true }, { id: 'manual', available: true }]);
    assert.deepEqual(list.map((m) => [m.name, m.usable]), [['MTN Mobile Money', false], ['Airtel Money', true], ['Manual payment with proof', true]]);
    assert.equal(list[0].bg, '#FFCC00');
    assert.equal(methodName('nothing_like_this'), 'nothing like this');
});

test('a request is worded briefly and only some states are final', () => {
    assert.equal(attemptWords('processing'), 'Payment request sent');
    assert.equal(attemptWords('successful'), 'Payment successful');
    assert.equal(attemptWords('failed'), 'Payment failed');
    for (const s of ['successful', 'failed', 'expired', 'cancelled']) assert.equal(isFinal(s), true, s);
    for (const s of ['pending', 'processing']) assert.equal(isFinal(s), false, s);
});

test('a manual payment needs a reference, a date that is not in the future, and proof', () => {
    const ok = { reference: 'BANK-123', paid_on: '2026-12-01' };
    assert.equal(hasErrors(manualProblems(ok, true, '2026-12-02')), false);
    assert.ok(manualProblems({ ...ok, reference: '' }, true).reference);
    assert.ok(manualProblems({ ...ok, reference: 'PIN <1234>' }, true).reference);
    assert.ok(manualProblems({ ...ok, paid_on: '' }, true).paid_on);
    assert.ok(manualProblems({ ...ok, paid_on: '2026-12-03' }, true, '2026-12-02').paid_on);
    assert.ok(manualProblems(ok, false).proof);
});

test('the pay button shows only when the server says so and something can be paid', () => {
    assert.equal(showPayButton({ show_pay_button: true, available_ugx: 100 }), true);
    assert.equal(showPayButton({ show_pay_button: true, available_ugx: 0 }), false);
    assert.equal(showPayButton({ show_pay_button: false, available_ugx: 5000 }), false);
    assert.equal(showPayButton(null), false);
    assert.equal(showPayButton({ available_ugx: 5000 }), false);
});

test('payment history is worded for the provider', () => {
    assert.equal(historyWords('verified').label, 'Verified');
    assert.equal(historyWords('rejected').tone, 'danger');
    assert.equal(historyWords('anything').label, 'Awaiting verification');
});

test('administrator payment words, filters and decisions', () => {
    assert.equal(statusWords('awaiting_verification').label, 'Awaiting verification');
    assert.equal(statusWords('successful').tone, 'success');
    assert.equal(statusWords('odd_new_thing').label, 'odd new thing');
    assert.equal(methodLabel('mtn_momo'), 'MTN Mobile Money');
    assert.equal(verificationLabel('automatic'), 'Automatic');
    assert.deepEqual(FILTER_STATUSES.length, 8);
    assert.equal(buildQuery({ q: ' ref ', status: '', method: 'manual' }, 1), 'q=ref&method=manual');
    assert.equal(buildQuery({ status: 'failed' }, 3), 'status=failed&page=3');
    assert.equal(canDecide({ kind: 'report', status: 'awaiting_verification' }, true), true);
    assert.equal(canDecide({ kind: 'report', status: 'awaiting_verification' }, false), false);
    assert.equal(canDecide({ kind: 'attempt', status: 'processing' }, true), false);
    assert.equal(canDecide({ kind: 'report', status: 'successful' }, true), false);
    assert.ok(verifyProblem({ amount: '', confirmed: true }));
    assert.ok(verifyProblem({ amount: '5000', confirmed: false }));
    assert.equal(verifyProblem({ amount: '5000', confirmed: true }), '');
    assert.ok(rejectProblem('no'));
    assert.equal(rejectProblem('Unreadable photo'), '');
    assert.equal(eventWords('expired'), 'Request expired');
    assert.equal(auditWords('commission.payment_verified'), 'Verified and credited');
});
