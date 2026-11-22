import { reactive, watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';

/**
 * The signed-in member's own community numbers (Account and My Space). Real counts from the server,
 * never estimated here.
 *
 * Whose numbers these are is part of the store, not an assumption:
 *  - everything held here belongs to ONE account (`summaryFor` / `detailFor`); a recent answer is reused
 *    for a moment only for that same account, so Account → My Space doesn't ask twice;
 *  - the moment the signed-in account changes (sign out, sign in as someone else, session ended) all of it
 *    is dropped, synchronously, before anything can draw it;
 *  - an answer that was asked for under a previous account is ignored when it arrives (each answer is
 *    checked against the account and generation it was asked under), and a request in flight for one
 *    account is never handed to another.
 *
 * summary: posts, videos, likes_received, followers (Account)
 * detail:  { audience: {...}, activity: {...} } (My Space → Performance)
 */
const FRESH_MS = 30 * 1000;

export const stats = reactive({
    summary: null, summaryFor: null, summaryLoading: false, summaryError: '', summaryAt: 0,
    detail: null, detailFor: null, detailLoading: false, detailError: '', detailAt: 0,
});

const currentId = () => (auth.user && auth.user.id) || null;

// Bumped whenever what is held (or being fetched) stops being valid; an answer from an older one is dropped
let generation = 0;
let summaryRequest = null;
let detailRequest = null;

function clearAll() {
    generation++;
    summaryRequest = null;
    detailRequest = null;
    Object.assign(stats, {
        summary: null, summaryFor: null, summaryLoading: false, summaryError: '', summaryAt: 0,
        detail: null, detailFor: null, detailLoading: false, detailError: '', detailAt: 0,
    });
}

// A different account (or none): drop everything at once. 'sync' so nothing renders in between.
watch(currentId, () => clearAll(), { flush: 'sync' });

export function loadSummary({ force = false } = {}) {
    const me = currentId();
    if (!me) return Promise.resolve(null);
    if (!force && stats.summary && stats.summaryFor === me && Date.now() - stats.summaryAt < FRESH_MS) return Promise.resolve(stats.summary);
    if (summaryRequest) return summaryRequest;

    const gen = generation;
    stats.summaryLoading = true;
    stats.summaryError = '';
    const request = api('/me/community/stats')
        .then((r) => {
            if (gen !== generation || currentId() !== me) return null;   // asked under another account (or before a reset)
            stats.summary = r.data; stats.summaryFor = me; stats.summaryAt = Date.now();
            return r.data;
        })
        .catch((e) => {
            if (gen === generation && currentId() === me) stats.summaryError = e.message || 'We couldn’t load your numbers.';
            return null;
        })
        .finally(() => {
            if (gen !== generation) return;                                // the reset already cleared the flags
            stats.summaryLoading = false;
            summaryRequest = null;
        });
    summaryRequest = request;
    return request;
}

export function loadDetail({ force = false } = {}) {
    const me = currentId();
    if (!me) return Promise.resolve(null);
    if (!force && stats.detail && stats.detailFor === me && Date.now() - stats.detailAt < FRESH_MS) return Promise.resolve(stats.detail);
    if (detailRequest) return detailRequest;

    const gen = generation;
    stats.detailLoading = true;
    stats.detailError = '';
    const request = api('/me/community/stats?detail=1')
        .then((r) => {
            if (gen !== generation || currentId() !== me) return null;
            stats.detail = r.data; stats.detailFor = me; stats.detailAt = Date.now();
            return r.data;
        })
        .catch((e) => {
            if (gen === generation && currentId() === me) stats.detailError = e.message || 'We couldn’t load your numbers.';
            return null;
        })
        .finally(() => {
            if (gen !== generation) return;
            stats.detailLoading = false;
            detailRequest = null;
        });
    detailRequest = request;
    return request;
}

/**
 * Something changed (a post was deleted): the next look fetches fresh numbers. Also drops anything still
 * on its way, so an answer counted before the change can't put the old numbers back.
 */
export function forgetStats() {
    clearAll();
}
