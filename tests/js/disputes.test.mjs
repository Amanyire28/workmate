// The payment dispute screens' pure helpers (Phase B5). Run with the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    STAGE_WORDS, stageWords, isActive, AGREEMENTS, agreementLabel, ESCALATION_REASONS, escalationReasonLabel, DECISIONS, decisionLabel, resolutionWords, eventWords,
    fileProblem, fileSize, isImage, validateEscalation, validateDecision, hasErrors, QUEUE_TABS, MAX_FILES,
} from '../../resources/js/utils/disputes.js';

test('every stage has plain words and a closed case is not active', () => {
    for (const s of ['open', 'discussion', 'escalated', 'under_review', 'resolved']) assert.ok(STAGE_WORDS[s].title.length > 3, s);
    assert.equal(stageWords('nonsense'), STAGE_WORDS.open);
    assert.equal(isActive('resolved'), false);
    assert.equal(isActive('escalated'), true);
    assert.equal(isActive(null), false);
});

test('nothing on these screens talks about debt, fees or penalties', () => {
    const all = JSON.stringify([STAGE_WORDS, AGREEMENTS, ESCALATION_REASONS, DECISIONS]).toLowerCase();
    for (const word of ['debt', 'invoice', 'overdue', 'penalt', 'commission', 'owes']) assert.equal(all.includes(word), false, word);
});

test('the two sides can only agree on two outcomes, and an administrator has four decisions', () => {
    assert.deepEqual(AGREEMENTS.map((a) => a.id), ['payment_received', 'payment_not_received']);
    assert.equal(agreementLabel('payment_received'), 'The payment was received');
    assert.equal(agreementLabel('x'), '');
    assert.deepEqual(DECISIONS.map((d) => d.id), ['not_established', 'received', 'partial', 'insufficient_evidence']);
    assert.equal(decisionLabel('partial'), 'Part of the payment received');
    // An administrator is told, before choosing, that "received" does not confirm the payment for the customer
    assert.match(DECISIONS.find((d) => d.id === 'received').effect, /can’t confirm for the customer/);
});

test('a closed case is explained to each side in words', () => {
    assert.match(resolutionWords({ type: 'agreed_received' }, 'customer'), /agreed the payment was received/);
    assert.match(resolutionWords({ type: 'provider_withdrew' }, 'provider'), /^You took/);
    assert.match(resolutionWords({ type: 'provider_withdrew' }, 'customer'), /^The provider took/);
    assert.match(resolutionWords({ type: 'partial', amount: 60000 }, 'customer'), /UGX 60,000/);
    assert.match(resolutionWords({ type: 'not_established' }, 'provider'), /withdrawn/);
    assert.equal(resolutionWords(null, 'customer'), '');
});

test('timeline lines say who did what', () => {
    assert.equal(eventWords({ event: 'message', by: 'customer', name: 'Aisha' }), 'Aisha wrote');
    assert.equal(eventWords({ event: 'escalated', by: 'provider', name: 'Okello Works' }), 'Okello Works asked WorkMate to step in');
    assert.equal(eventWords({ event: 'decided', by: 'admin', name: 'WorkMate' }), 'WorkMate recorded a decision');
    assert.equal(eventWords({ event: 'some_new_step' }), 'some new step');
});

test('files are checked before they are sent (the server checks again)', () => {
    assert.equal(fileProblem({ name: 'receipt.PDF', size: 1000 }), '');
    assert.equal(fileProblem({ name: 'screenshot.jpeg', size: 1000 }), '');
    assert.equal(fileProblem({ name: 'screenshot.png', size: 5 * 1024 * 1024 }), '');
    assert.ok(fileProblem({ name: 'screenshot.png', size: 5 * 1024 * 1024 + 1 }));
    for (const bad of ['virus.exe', 'notes.txt', 'photo.gif', 'noextension', '.pdf.exe', '']) assert.ok(fileProblem({ name: bad, size: 1 }), bad);
    assert.ok(fileProblem(null));
    assert.equal(MAX_FILES, 5);
    assert.equal(fileSize(500), '500 B');
    assert.equal(fileSize(2048), '2 KB');
    assert.equal(fileSize(3 * 1024 * 1024), '3.0 MB');
    assert.equal(isImage('image/jpeg'), true);
    assert.equal(isImage('application/pdf'), false);
});

test('asking WorkMate to step in needs a reason and a few words', () => {
    assert.equal(hasErrors(validateEscalation('no_agreement', 'We cannot agree on it.')), false);
    assert.ok(validateEscalation('', 'We cannot agree on it.').reason);
    assert.ok(validateEscalation('bogus', 'We cannot agree on it.').reason);
    assert.ok(validateEscalation('other', 'no').note);
    assert.ok(validateEscalation('other', '    ').note);
    assert.equal(escalationReasonLabel('no_response'), 'The other side isn’t answering');
});

test('a decision needs an outcome, a written reason and, for a part payment, a sensible amount', () => {
    assert.equal(hasErrors(validateDecision('not_established', 'No receipt was shown at all.', '', 100000)), false);
    assert.ok(validateDecision('', 'No receipt was shown at all.', '', 100000).outcome);
    assert.ok(validateDecision('received', 'short', '', 100000).reason);
    assert.ok(validateDecision('received', '          ', '', 100000).reason);
    assert.equal(hasErrors(validateDecision('partial', 'Part of it was paid, per the receipt.', '60000', 100000)), false);
    for (const bad of ['', '0', '50', '100000', '100001', 'lots', '1.5', '-5']) assert.ok(validateDecision('partial', 'Part of it was paid, per the receipt.', bad, 100000).amount, bad);
    // An amount is only asked about for a part payment
    assert.equal(validateDecision('received', 'WorkMate found the proof convincing.', '', 100000).amount, '');
});

test('the queue tabs add up from the counts', () => {
    const counts = { open: 2, escalated: 3, under_review: 1, resolved: 5 };
    assert.deepEqual(QUEUE_TABS.map((t) => t.count(counts)), [9, 3, 1, 5, 2]);
    assert.deepEqual(QUEUE_TABS.map((t) => t.id), ['', 'escalated', 'under_review', 'resolved', 'open']);
});
