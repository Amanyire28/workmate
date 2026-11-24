import test from 'node:test';
import assert from 'node:assert/strict';
import { isDismissed, dismiss, reopen, reopenAll, dismissedIds, onNoticeChange } from '../../resources/js/admin/utils/noticeMemory.js';

/** A tiny stand-in for localStorage */
function memory() {
    const data = new Map();

    return {
        get length() { return data.size; },
        key: (i) => Array.from(data.keys())[i] || null,
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => { data.set(k, String(v)); },
        removeItem: (k) => { data.delete(k); },
        raw: data,
    };
}

test('a notice shows until it is dismissed, then stays hidden', () => {
    const s = memory();
    assert.equal(isDismissed(7, 'fin.ledger', 'a', s), false);
    assert.equal(dismiss(7, 'fin.ledger', 'a', s), true);
    assert.equal(isDismissed(7, 'fin.ledger', 'a', s), true);
});

test('dismissal is per administrator and per notice', () => {
    const s = memory();
    dismiss(7, 'fin.ledger', 'a', s);
    assert.equal(isDismissed(8, 'fin.ledger', 'a', s), false);
    assert.equal(isDismissed(7, 'fin.other', 'a', s), false);
});

test('a notice comes back when its version (content or condition) changes', () => {
    const s = memory();
    dismiss(7, 'est.off', 'off', s);
    assert.equal(isDismissed(7, 'est.off', 'off', s), true);
    assert.equal(isDismissed(7, 'est.off', 'on', s), false);
});

test('a dismissed notice can be reopened, one or all of a screen', () => {
    const s = memory();
    dismiss(7, 'est.a', '1', s);
    dismiss(7, 'est.b', '1', s);
    dismiss(7, 'disp.a', '1', s);
    reopen(7, 'est.a', s);
    assert.equal(isDismissed(7, 'est.a', '1', s), false);
    assert.deepEqual(dismissedIds(7, 'est.', s), ['est.b']);
    reopenAll(7, 'est.', s);
    assert.deepEqual(dismissedIds(7, '', s), ['disp.a']);
});

test('only a short version is stored: no figures, no names', () => {
    const s = memory();
    dismiss(7, 'est.a', 'x'.repeat(500), s);
    const stored = Array.from(s.raw.values())[0];
    assert.ok(stored.length <= 60);
});

test('without a signed-in administrator nothing is remembered', () => {
    const s = memory();
    assert.equal(dismiss(null, 'est.a', '1', s), false);
    assert.equal(isDismissed(null, 'est.a', '1', s), false);
    assert.equal(s.raw.size, 0);
});

test('a storage that throws never breaks anything', () => {
    const broken = {
        get length() { throw new Error('blocked'); },
        key() { throw new Error('blocked'); },
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('quota'); },
        removeItem() { throw new Error('blocked'); },
    };
    assert.equal(isDismissed(7, 'a', '1', broken), false);
    assert.equal(dismiss(7, 'a', '1', broken), false);
    assert.doesNotThrow(() => reopen(7, 'a', broken));
    assert.doesNotThrow(() => reopenAll(7, '', broken));
    assert.deepEqual(dismissedIds(7, '', broken), []);
});

test('listeners hear about changes, and a failing listener does not stop the others', () => {
    const s = memory();
    let heard = 0;
    const off1 = onNoticeChange(() => { throw new Error('bad listener'); });
    const off2 = onNoticeChange(() => { heard++; });
    dismiss(7, 'a', '1', s);
    reopen(7, 'a', s);
    assert.equal(heard, 2);
    off1(); off2();
});
