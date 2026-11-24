import test from 'node:test';
import assert from 'node:assert/strict';
import { ref, nextTick } from 'vue';

// A very small page: just what useModalBehaviour touches.
function makePage() {
    const listeners = {};
    const style = () => ({ _p: {}, setProperty(k, v) { this._p[k] = v; }, removeProperty(k) { delete this._p[k]; } });
    const doc = {
        documentElement: { style: style(), clientWidth: 1000 },
        body: { style: style() },
        activeElement: { focus() {}, isConnected: true },
        addEventListener: (t, fn) => { (listeners[t] = listeners[t] || []).push(fn); },
        removeEventListener: (t, fn) => { listeners[t] = (listeners[t] || []).filter((f) => f !== fn); },
        querySelector: () => null,
    };
    const scrolls = [];
    const win = {
        scrollX: 0, scrollY: 640, innerWidth: 1000, innerHeight: 800, visualViewport: null,
        scrollTo: (x, y) => scrolls.push([x, y]),
        addEventListener() {}, removeEventListener() {},
    };
    globalThis.document = doc;
    globalThis.window = win;

    return { listeners, scrolls, doc };
}

const modal = (open, closed) => {
    const panel = ref({ focus() {}, contains: () => true, querySelectorAll: () => [], tagName: 'DIV' });
    return { open, panel, close: () => closed.push(open), focus: undefined };
};

test('scroll stays locked until the LAST modal closes, then returns to the original position', async () => {
    const page = makePage();
    const { useModalBehaviour } = await import('../../resources/js/components/useModalBehaviour.js');
    const closed = [];
    const a = ref(false);
    const b = ref(false);
    useModalBehaviour({ ...modal(a, closed), open: a });
    useModalBehaviour({ ...modal(b, closed), open: b });

    a.value = true; await nextTick(); await nextTick();
    assert.equal(page.doc.body.style.position, 'fixed');
    assert.equal(page.doc.body.style.top, '-640px');

    b.value = true; await nextTick(); await nextTick();
    b.value = false; await nextTick(); await nextTick();
    // The first modal is still open: the page must still be locked and must not have jumped
    assert.equal(page.doc.body.style.position, 'fixed');
    assert.equal(page.scrolls.length, 0);

    a.value = false; await nextTick(); await nextTick();
    assert.notEqual(page.doc.body.style.position, 'fixed');
    assert.deepEqual(page.scrolls, [[0, 640]]);
});

test('only the topmost modal answers Escape', async () => {
    const page = makePage();
    const { useModalBehaviour } = await import('../../resources/js/components/useModalBehaviour.js');
    const closed = [];
    const a = ref(false);
    const b = ref(false);
    useModalBehaviour({ open: a, panel: ref({ focus() {}, contains: () => true, querySelectorAll: () => [] }), close: () => closed.push('A') });
    useModalBehaviour({ open: b, panel: ref({ focus() {}, contains: () => true, querySelectorAll: () => [] }), close: () => closed.push('B') });
    a.value = true; await nextTick(); await nextTick();
    b.value = true; await nextTick(); await nextTick();

    const press = (key) => (page.listeners.keydown || []).forEach((fn) => fn({ key, defaultPrevented: false, preventDefault() {}, shiftKey: false }));
    press('Escape');
    assert.deepEqual(closed, ['B']);
    b.value = false; await nextTick(); await nextTick();
    press('Escape');
    assert.deepEqual(closed, ['B', 'A']);
    a.value = false; await nextTick(); await nextTick();
    assert.equal((page.listeners.keydown || []).length, 0, 'the key listener is removed when no modal is open');
});

test('Escape already used by an information bubble does not also close the modal', async () => {
    const page = makePage();
    const { useModalBehaviour } = await import('../../resources/js/components/useModalBehaviour.js');
    const closed = [];
    const a = ref(false);
    useModalBehaviour({ open: a, panel: ref({ focus() {}, contains: () => true, querySelectorAll: () => [] }), close: () => closed.push('A') });
    a.value = true; await nextTick(); await nextTick();
    (page.listeners.keydown || []).forEach((fn) => fn({ key: 'Escape', defaultPrevented: true, preventDefault() {} }));
    assert.deepEqual(closed, []);
    a.value = false; await nextTick(); await nextTick();
});
