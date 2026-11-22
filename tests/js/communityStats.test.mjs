// The member's own numbers belong to ONE account: switching accounts without reloading the page must never
// show, keep or accept another account's numbers.
//   node --import ./tests/js/register.mjs --test "tests/js/*.test.mjs"
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { auth } from '@/stores/auth.js';
import { answer, answerLast, fail, reset, server } from '@/api/client.js';
import { stats, loadSummary, loadDetail, forgetStats } from '../../resources/js/stores/communityStats.js';

const SUMMARY = '/me/community/stats';
const DETAIL = '/me/community/stats?detail=1';
const A = { id: 17, name: 'Ann' };
const B = { id: 22, name: 'Bob' };
const A_NUMBERS = { posts: 12, videos: 3, likes_received: 4, followers: 2 };
const B_NUMBERS = { posts: 1, videos: 1, likes_received: 0, followers: 0 };
const A_DETAIL = { audience: { posts: 12, videos: 3, likes_received: 4, comments_received: 1, saves_received: 1, followers: 2 }, activity: { following: 0, likes_given: 1, comments_written: 1, posts_saved: 0, providers_saved: 0 } };
const B_DETAIL = { audience: { posts: 1, videos: 1, likes_received: 0, comments_received: 0, saves_received: 0, followers: 0 }, activity: { following: 3, likes_given: 0, comments_written: 0, posts_saved: 0, providers_saved: 0 } };

const tick = () => new Promise(r => setImmediate(r));

beforeEach(() => {
    auth.user = null;       // (clears the store too)
    reset();
    forgetStats();
});

test('account A signs in and loads its numbers', async () => {
    auth.user = A;
    const p = loadSummary();
    answer(SUMMARY, A_NUMBERS);
    await p;
    assert.deepEqual(stats.summary, A_NUMBERS);
    assert.equal(stats.summaryFor, A.id);
});

test('signing out drops every number at once, before anything can draw them', async () => {
    auth.user = A;
    let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
    p = loadDetail(); answer(DETAIL, A_DETAIL); await p;
    assert.ok(stats.summary && stats.detail);

    auth.user = null;       // synchronous: no await between this line and the checks
    assert.equal(stats.summary, null);
    assert.equal(stats.detail, null);
    assert.equal(stats.summaryFor, null);
    assert.equal(stats.detailFor, null);
});

test('account B signing in right after A never sees A’s numbers, and gets its own', async () => {
    auth.user = A;
    let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;

    auth.user = null;
    auth.user = B;
    assert.equal(stats.summary, null, 'B must start with nothing, not A’s numbers');

    p = loadSummary();
    assert.equal(stats.summary, null, 'and still nothing while B’s numbers load');
    assert.equal(server.calls.length, 2, 'B asks the server itself: A’s earlier answer is not reused');
    answer(SUMMARY, B_NUMBERS);
    await p;
    assert.deepEqual(stats.summary, B_NUMBERS);
    assert.equal(stats.summaryFor, B.id);
});

test('switching straight from A to B (no sign-out step in between) is also a clean start', async () => {
    auth.user = A;
    let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
    auth.user = B;
    assert.equal(stats.summary, null);
});

test('an answer asked for under A that arrives after B signed in is ignored', async () => {
    auth.user = A;
    const late = loadSummary();                       // A's request is still on its way…
    auth.user = null;
    auth.user = B;                                    // …when B signs in
    const mine = loadSummary();
    assert.equal(server.calls.length, 2, 'B does not get A’s in-flight request handed to it');

    answerLast(SUMMARY, B_NUMBERS);                   // B's answer first
    await mine;
    assert.deepEqual(stats.summary, B_NUMBERS);

    answer(SUMMARY, A_NUMBERS);                       // then A's answer (the oldest still waiting) finally arrives
    await late;
    await tick();
    assert.deepEqual(stats.summary, B_NUMBERS, 'A’s late answer must not replace B’s numbers');
    assert.equal(stats.summaryFor, B.id);
    assert.equal(stats.summaryLoading, false);
});

test('A’s late answer arriving before B’s own does not fill B’s page either', async () => {
    auth.user = A;
    const late = loadSummary();
    auth.user = B;
    const mine = loadSummary();
    answer(SUMMARY, A_NUMBERS);                       // the oldest waiting is A's
    await late;
    assert.equal(stats.summary, null, 'nothing is shown until B’s own numbers arrive');
    answer(SUMMARY, B_NUMBERS);
    await mine;
    assert.deepEqual(stats.summary, B_NUMBERS);
});

test('a failure of A’s late request does not put an error on B’s page', async () => {
    auth.user = A;
    const late = loadSummary();
    auth.user = B;
    fail(SUMMARY, 'A: server trouble');
    await late;
    await tick();
    assert.equal(stats.summaryError, '');
    const mine = loadSummary();
    answer(SUMMARY, B_NUMBERS);
    await mine;
    assert.deepEqual(stats.summary, B_NUMBERS);
});

test('A signs in again later and gets its own numbers, not B’s', async () => {
    auth.user = A;
    let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
    auth.user = null; auth.user = B;
    p = loadSummary(); answer(SUMMARY, B_NUMBERS); await p;
    auth.user = null; auth.user = A;
    assert.equal(stats.summary, null);
    p = loadSummary();
    assert.equal(server.calls.length, 3, 'fetched again, not served from B’s cache');
    answer(SUMMARY, { ...A_NUMBERS, posts: 13 });
    await p;
    assert.equal(stats.summary.posts, 13);
    assert.equal(stats.summaryFor, A.id);
});

test('the Performance details are kept apart between accounts too', async () => {
    auth.user = A;
    let p = loadDetail(); answer(DETAIL, A_DETAIL); await p;
    assert.deepEqual(stats.detail, A_DETAIL);

    auth.user = null; auth.user = B;
    assert.equal(stats.detail, null);
    const late = loadDetail();
    auth.user = null; auth.user = A;                  // A back while B's detail request is on its way
    answer(DETAIL, B_DETAIL);
    await late;
    await tick();
    assert.equal(stats.detail, null, 'B’s answer must not land on A’s store');

    p = loadDetail(); answer(DETAIL, A_DETAIL); await p;
    assert.deepEqual(stats.detail, A_DETAIL);
});

test('an in-flight detail request for A is ignored once B is signed in', async () => {
    auth.user = A;
    const late = loadDetail();
    auth.user = null; auth.user = B;
    answer(DETAIL, A_DETAIL);
    await late;
    await tick();
    assert.equal(stats.detail, null);
    assert.equal(stats.detailLoading, false);
});

test('while nobody is signed in nothing is asked for and nothing is kept', async () => {
    assert.equal(await loadSummary(), null);
    assert.equal(await loadDetail(), null);
    assert.equal(server.calls.length, 0);
});

test('the same account still reuses a fresh answer for 30 seconds, then asks again', async () => {
    const realNow = Date.now;
    let clock = 1_000_000;
    Date.now = () => clock;
    try {
        auth.user = A;
        let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
        clock += 10_000;
        assert.deepEqual(await loadSummary(), A_NUMBERS);
        assert.equal(server.calls.length, 1, 'inside 30 seconds: no second request');

        clock += 25_000;                              // 35 s after the first answer
        p = loadSummary();
        assert.equal(server.calls.length, 2, 'after 30 seconds: asks again');
        answer(SUMMARY, { ...A_NUMBERS, posts: 14 });
        await p;
        assert.equal(stats.summary.posts, 14);

        p = loadSummary({ force: true });             // a forced look always asks
        assert.equal(server.calls.length, 3);
        answer(SUMMARY, A_NUMBERS);
        await p;
    } finally {
        Date.now = realNow;
    }
});

test('two looks at once share one request (same account)', async () => {
    auth.user = A;
    const one = loadSummary();
    const two = loadSummary();
    assert.equal(server.calls.length, 1);
    answer(SUMMARY, A_NUMBERS);
    assert.deepEqual(await one, await two);
});

test('the same account being refreshed from the server (same id, new object) keeps its numbers', async () => {
    auth.user = A;
    const p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
    auth.user = { ...A, name: 'Ann N.' };             // e.g. a profile update replaces the user object
    assert.deepEqual(stats.summary, A_NUMBERS);
    assert.deepEqual(await loadSummary(), A_NUMBERS);
    assert.equal(server.calls.length, 1);
});

test('after a post is deleted the next look fetches fresh numbers for that same account', async () => {
    auth.user = A;
    let p = loadSummary(); answer(SUMMARY, A_NUMBERS); await p;
    forgetStats();
    assert.equal(stats.summary, null);
    p = loadSummary();
    assert.equal(server.calls.length, 2);
    answer(SUMMARY, { ...A_NUMBERS, posts: 11 });
    await p;
    assert.equal(stats.summary.posts, 11);
    assert.equal(stats.summaryFor, A.id);
});

test('a request counted before a delete cannot put the old numbers back', async () => {
    auth.user = A;
    const before = loadSummary();                     // asked before the delete…
    forgetStats();                                    // …then a post is deleted
    const after = loadSummary();
    answer(SUMMARY, A_NUMBERS);                       // the old answer arrives (posts: 12)
    await before;
    await tick();
    assert.equal(stats.summary, null, 'the stale answer is dropped');
    answer(SUMMARY, { ...A_NUMBERS, posts: 11 });     // the fresh one
    await after;
    assert.equal(stats.summary.posts, 11);
});
