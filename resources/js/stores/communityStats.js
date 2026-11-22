import { reactive } from 'vue';
import { api } from '@/api/client.js';

/**
 * The signed-in member's own community numbers (Account and My Space). Real counts from the server,
 * never estimated here. A recent answer is reused for a moment, so going Account → My Space doesn't
 * ask twice; after something changes (a post deleted) `forgetStats()` makes the next look fetch again.
 *
 * summary: posts, videos, likes_received, followers (Account)
 * detail:  { audience: {...}, activity: {...} } (My Space → Performance)
 */
const FRESH_MS = 30 * 1000;

export const stats = reactive({
    summary: null, summaryLoading: false, summaryError: '', summaryAt: 0,
    detail: null, detailLoading: false, detailError: '', detailAt: 0,
});

let summaryRequest = null;
let detailRequest = null;

export function loadSummary({ force = false } = {}) {
    if (!force && stats.summary && Date.now() - stats.summaryAt < FRESH_MS) return Promise.resolve(stats.summary);
    if (summaryRequest) return summaryRequest;
    stats.summaryLoading = true;
    stats.summaryError = '';
    summaryRequest = api('/me/community/stats')
        .then((r) => { stats.summary = r.data; stats.summaryAt = Date.now(); return r.data; })
        .catch((e) => { stats.summaryError = e.message || 'We couldn’t load your numbers.'; return null; })
        .finally(() => { stats.summaryLoading = false; summaryRequest = null; });
    return summaryRequest;
}

export function loadDetail({ force = false } = {}) {
    if (!force && stats.detail && Date.now() - stats.detailAt < FRESH_MS) return Promise.resolve(stats.detail);
    if (detailRequest) return detailRequest;
    stats.detailLoading = true;
    stats.detailError = '';
    detailRequest = api('/me/community/stats?detail=1')
        .then((r) => { stats.detail = r.data; stats.detailAt = Date.now(); return r.data; })
        .catch((e) => { stats.detailError = e.message || 'We couldn’t load your numbers.'; return null; })
        .finally(() => { stats.detailLoading = false; detailRequest = null; });
    return detailRequest;
}

/** Something changed (a post was deleted, someone else signed in): the next look fetches fresh numbers */
export function forgetStats() {
    stats.summary = null; stats.summaryAt = 0;
    stats.detail = null; stats.detailAt = 0;
}
