// The shadow commission screens' pure helpers (Phase B4). Everything on those screens is an estimate, not collected;
// rates are whole basis points and are converted with whole-number arithmetic only. Same runner as the rest:
//   node --import ./tests/js/register.mjs --test --test-timeout=20000 "tests/js/*.test.mjs"
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ESTIMATE_LABEL, bpToPercent, percentToBp, estimate, SCOPES, reasonLabel, REASON_LABEL, blankRule, validateRule, hasErrors, ruleBody, localInput, barPercent,
} from '../../resources/js/admin/utils/commission.js';

test('everything is labelled as an estimate', () => {
    assert.equal(ESTIMATE_LABEL, 'Estimated — Not Collected');
});

test('basis points read as percentages without any floating point drift', () => {
    assert.equal(bpToPercent(500), '5%');
    assert.equal(bpToPercent(250), '2.5%');
    assert.equal(bpToPercent(5), '0.05%');
    assert.equal(bpToPercent(0), '0%');
    assert.equal(bpToPercent(2000), '20%');
    assert.equal(bpToPercent(1250), '12.5%');
    assert.equal(bpToPercent(10000), '100%');
    assert.equal(bpToPercent(105), '1.05%');
    assert.equal(bpToPercent(-1), '');
    assert.equal(bpToPercent('abc'), '');
    assert.equal(bpToPercent(2.5), '');
});

test('what an administrator types becomes exact basis points, or nothing at all', () => {
    assert.equal(percentToBp('5'), 500);
    assert.equal(percentToBp('5%'), 500);
    assert.equal(percentToBp(' 5 % '), 500);
    assert.equal(percentToBp('2.5'), 250);
    assert.equal(percentToBp('0.25'), 25);
    assert.equal(percentToBp('0.05'), 5);
    assert.equal(percentToBp('7.07'), 707);
    assert.equal(percentToBp('0'), 0);
    assert.equal(percentToBp('100'), 10000);
    // Not a rate: refused rather than guessed
    for (const bad of ['', 'five', '5,5', '-1', '101', '5.555', '5.', '.5', '1e2', '5%%', null, undefined]) assert.equal(percentToBp(bad), null, String(bad));
    // The famous floating point trap does not appear: 0.07 and 1.15 are exact
    assert.equal(percentToBp('0.07'), 7);
    assert.equal(percentToBp('1.15'), 115);
    // And a rate survives the round trip
    for (const bp of [0, 1, 5, 25, 99, 100, 250, 505, 707, 1999, 2000, 10000]) assert.equal(percentToBp(bpToPercent(bp)), bp, String(bp));
});

test('the examples on screen are worked the way the server works them: half up, whole numbers', () => {
    assert.equal(estimate(100000, 500), 5000);
    assert.equal(100000 - estimate(100000, 500), 95000);
    assert.equal(estimate(33333, 500), 1667);
    assert.equal(estimate(33330, 500), 1667);
    assert.equal(estimate(33329, 500), 1666);
    assert.equal(estimate(110, 500), 6);
    assert.equal(estimate(101, 500), 5);
    assert.equal(estimate(100, 1), 0);
    assert.equal(estimate(100, 50), 1);
    assert.equal(estimate(100000000, 2000), 20000000);
    assert.ok(Number.isInteger(estimate(99999, 333)));
});

test('the order the engine looks in is shown most specific first, and the default last', () => {
    assert.deepEqual(SCOPES.map((s) => s.id), ['provider', 'profession', 'category', 'area', 'district', 'global']);
});

test('every reason a payment has no estimate is said in plain words', () => {
    for (const r of ['no_policy', 'ambiguous_policy', 'sample_record', 'job_cancelled', 'legacy_request', 'missing_reference', 'awaiting_confirmation', 'disputed', 'withdrawn']) {
        assert.ok(REASON_LABEL[r] && REASON_LABEL[r].length > 5, r);
    }
    assert.equal(reasonLabel('something_new'), 'something new');
    assert.equal(reasonLabel(null), '');
    const all = JSON.stringify(REASON_LABEL).toLowerCase();
    for (const word of ['debt', 'invoice', 'owed', 'overdue', 'penalty']) assert.equal(all.includes(word), false, word);
});

test('a rate rule is checked field by field', () => {
    const now = new Date('2026-11-27T10:00:00');
    const ok = { ...blankRule(), scope_type: 'global', percent: '5', from: '2026-11-28T09:00' };
    assert.equal(hasErrors(validateRule(ok, 2000, now)), false);

    assert.ok(validateRule({ ...ok, percent: '' }, 2000, now).percent);
    assert.ok(validateRule({ ...ok, percent: 'lots' }, 2000, now).percent);
    assert.ok(validateRule({ ...ok, percent: '20.01' }, 2000, now).percent);
    assert.equal(validateRule({ ...ok, percent: '20' }, 2000, now).percent, '');
    assert.equal(validateRule({ ...ok, percent: '0' }, 2000, now).percent, '');
    assert.ok(validateRule({ ...ok, scope_type: 'planet' }, 2000, now).scope);
    assert.ok(validateRule({ ...ok, scope_type: 'category', scope_ref: '' }, 2000, now).scope);
    assert.equal(validateRule({ ...ok, scope_type: 'category', scope_ref: '12' }, 2000, now).scope, '');
    assert.ok(validateRule({ ...ok, scope_type: 'district', scope_key: ' ' }, 2000, now).scope);
    assert.equal(validateRule({ ...ok, scope_type: 'district', scope_key: 'wakiso' }, 2000, now).scope, '');
    assert.ok(validateRule({ ...ok, from: '' }, 2000, now).from);
    assert.ok(validateRule({ ...ok, from: '2026-11-26T09:00' }, 2000, now).from);
    assert.ok(validateRule({ ...ok, to: '2026-11-28T09:00' }, 2000, now).to);
    assert.ok(validateRule({ ...ok, to: '2026-11-27T09:00' }, 2000, now).to);
    assert.equal(validateRule({ ...ok, to: '2026-12-28T09:00' }, 2000, now).to, '');
    // A lower ceiling applies at once
    assert.ok(validateRule({ ...ok, percent: '10' }, 500, now).percent);
});

test('a rule is sent as exact basis points and exact moments, with only the fields of its scope', () => {
    const base = { ...blankRule(), percent: '2.5', from: '2026-11-28T09:00', to: '' };
    const global = ruleBody({ ...base, scope_type: 'global', scope_ref: '7', scope_key: 'x' });
    assert.deepEqual(Object.keys(global).sort(), ['effective_from', 'effective_to', 'rate_bp', 'scope_type']);
    assert.equal(global.rate_bp, 250);
    assert.equal(global.effective_to, null);
    assert.equal(global.effective_from, new Date('2026-11-28T09:00').toISOString());

    const category = ruleBody({ ...base, scope_type: 'category', scope_ref: '12' });
    assert.equal(category.scope_ref, 12);
    assert.equal('scope_key' in category, false);
    const district = ruleBody({ ...base, scope_type: 'district', scope_key: ' Wakiso ', to: '2026-12-31T09:00' });
    assert.equal(district.scope_key, 'Wakiso');
    assert.equal('scope_ref' in district, false);
    assert.equal(district.effective_to, new Date('2026-12-31T09:00').toISOString());
});

test('dates for the form and the bars for the tables are plain', () => {
    assert.match(localInput(new Date()), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
    assert.equal(barPercent(0, 100), 0);
    assert.equal(barPercent(50, 100), 50);
    assert.equal(barPercent(100, 100), 100);
    assert.equal(barPercent(1, 1000), 2);
    assert.equal(barPercent(5, 0), 0);
    assert.equal(barPercent(-5, 100), 0);
});
