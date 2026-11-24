// The commission settlement screens' pure helpers (Phase B7). Run with the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    CYCLE_STATUS, cycleLabel, PAYMENT_STATUS, paymentWords, channelLabel, dueWords, jobsWords, canReport, blankReport, validateReport, hasErrors, reportBody, ADMIN_TABS, EXCEPTION_WORDS,
} from '../../resources/js/utils/settlement.js';

test('every cycle and payment state has plain words, and nothing mentions penalties or fees', () => {
    for (const s of ['open', 'due', 'partially_paid', 'overdue', 'settled', 'void']) assert.ok(CYCLE_STATUS[s].label.length > 2, s);
    assert.equal(cycleLabel('partially_paid'), 'Part paid');
    assert.equal(cycleLabel('something_new'), 'something new');
    for (const s of ['pending', 'verified', 'rejected']) assert.ok(PAYMENT_STATUS[s].why.length > 10, s);
    assert.equal(paymentWords('pending').label, 'Awaiting verification');
    assert.match(paymentWords('pending').why, /Nothing is taken off yet/);
    const all = JSON.stringify([CYCLE_STATUS, PAYMENT_STATUS, EXCEPTION_WORDS]).toLowerCase();
    for (const word of ['penalt', 'interest', 'suspend', 'fine ', 'deduct']) assert.equal(all.includes(word), false, word);
});

test('channels read as people say them', () => {
    assert.equal(channelLabel('mtn_momo'), 'MTN Mobile Money');
    assert.equal(channelLabel('airtel_money'), 'Airtel Money');
    assert.equal(channelLabel('bank_transfer'), 'Bank transfer');
    assert.equal(channelLabel('cash'), 'Cash');
});

test('the due date is explained by the trigger that applies', () => {
    const date = (iso) => `D(${iso})`;
    assert.match(dueWords({ status: 'open', due_at: null }, date), /3 jobs.*7 days/);
    assert.equal(dueWords({ status: 'open', due_at: 'X', trigger: 'seven_days' }, date), 'Due on D(X), or sooner if you reach 3 jobs.');
    assert.match(dueWords({ status: 'due', due_at: 'X', trigger: 'three_jobs' }, date), /Due now: you reached 3 jobs/);
    assert.match(dueWords({ status: 'overdue', due_at: 'X', trigger: 'seven_days' }, date), /Was due on D\(X\)/);
    assert.match(dueWords({ status: 'overdue', due_at: 'X', trigger: 'three_jobs' }, date), /past the 7-day limit/);
    assert.equal(dueWords({ status: 'settled' }, date), '');
    assert.equal(dueWords(null, date), '');
    assert.equal(jobsWords({ jobs: 2 }), '2 of 3 jobs');
});

test('only a settlement with something left to pay can be reported against', () => {
    assert.equal(canReport({ outstanding_ugx: 1 }), true);
    assert.equal(canReport({ outstanding_ugx: 0 }), false);
    assert.equal(canReport({ outstanding_ugx: -5 }), false);
    assert.equal(canReport(null), false);
});

test('a payment report is checked field by field', () => {
    const channels = ['mtn_momo', 'airtel_money', 'bank_transfer'];
    const ok = { ...blankReport(), amount: '10000', channel: 'mtn_momo', reference: 'MP123456' };
    assert.equal(hasErrors(validateReport(ok, channels, '2026-12-01')), false);
    assert.ok(validateReport({ ...ok, amount: '' }, channels).amount);
    assert.ok(validateReport({ ...ok, amount: '50' }, channels).amount);
    assert.ok(validateReport({ ...ok, amount: '1.5' }, channels).amount);
    assert.ok(validateReport({ ...ok, amount: '-100' }, channels).amount);
    assert.equal(validateReport({ ...ok, amount: '100' }, channels).amount, '');
    assert.ok(validateReport({ ...ok, channel: '' }, channels).channel);
    assert.ok(validateReport({ ...ok, channel: 'paypal' }, channels).channel);
    assert.ok(validateReport({ ...ok, channel: 'cash' }, channels).channel);   // cash is not on offer unless approved
    assert.ok(validateReport({ ...ok, reference: '' }, channels).reference);
    assert.ok(validateReport({ ...ok, reference: 'PIN 1234 <b>' }, channels).reference);
    assert.equal(validateReport({ ...ok, channel: 'cash', reference: '' }, [...channels, 'cash']).reference, '');
    assert.ok(validateReport({ ...ok, paid_on: '2026-12-02' }, channels, '2026-12-01').paid_on);
    assert.equal(validateReport({ ...ok, paid_on: '2026-12-01' }, channels, '2026-12-01').paid_on, '');
});

test('a report is sent as whole UGX with only the fields that were filled in', () => {
    const body = reportBody({ amount: ' 10000 ', channel: 'airtel_money', reference: ' AB-1 ', paid_on: '', note: '  ' }, 7, 'tok');
    assert.deepEqual(body, { cycle_id: 7, amount: 10000, channel: 'airtel_money', client_token: 'tok', reference: 'AB-1' });
    const full = reportBody({ amount: '500', channel: 'bank_transfer', reference: 'R', paid_on: '2026-11-30', note: 'Paid early' }, 1, 't');
    assert.equal(full.paid_on, '2026-11-30');
    assert.equal(full.note, 'Paid early');
});

test('the administrator tabs and exception words are defined', () => {
    assert.deepEqual(ADMIN_TABS.map((t) => t.id), ['cycles', 'exceptions']);
    assert.ok(EXCEPTION_WORDS.overpayment && EXCEPTION_WORDS.excess_credit);
    assert.match(EXCEPTION_WORDS.excess_credit, /Nothing is refunded automatically/);
});

test('a trigger that was reached and later reversed is explained, and a live one is not', async () => {
    const { earlierTriggerWords } = await import('../../resources/js/utils/settlement.js');
    const gone = { history: [{ event: 'three_jobs_reached', at: 'T', jobs: 3, in_effect: false }] };
    assert.match(earlierTriggerWords(gone, (x) => `D(${x})`), /You reached 3 jobs on D\(T\).*open again.*kept/);
    assert.equal(earlierTriggerWords({ history: [{ event: 'three_jobs_reached', at: 'T', jobs: 3, in_effect: true }] }), '');
    assert.equal(earlierTriggerWords({ history: [] }), '');
    assert.equal(earlierTriggerWords(null), '');
});
