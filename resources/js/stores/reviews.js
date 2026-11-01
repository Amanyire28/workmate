import { reactive } from 'vue';
import { api } from '@/api/client.js';
import { loadCatalog } from '@/stores/catalog.js';

/**
 * Asking customers to rate providers who finished work for them. When the
 * app opens (or comes back to the screen), finished jobs the customer hasn't
 * been asked about appear one at a time. Rating is never required: "Continue
 * to WorkMate" closes it, and they can still rate later from My Work.
 */
export const ratePrompt = reactive({
    queue: [],        // [{ request_id, title, category, provider: { id, display_name, avatar_url } }]
    current: null,
    fromAppOpen: false,
    busy: false,
    error: '',
    thanked: false,
});

let lastCheck = 0;

/** Called when the app opens or returns to the screen, for a signed-in account */
export async function checkWaitingReviews({ force = false } = {}) {
    if (ratePrompt.current) return;
    if (!force && Date.now() - lastCheck < 5 * 60 * 1000) return;
    lastCheck = Date.now();
    try {
        ratePrompt.queue = (await api('/me/reviews/waiting')).data;
        showNext(true);
    } catch {
        // Not important enough to tell anyone: it's asked again next time
    }
}

/** Rate a finished job from My Work (not part of the app-open queue) */
export function openRating(item) {
    Object.assign(ratePrompt, { current: item, fromAppOpen: false, error: '', thanked: false });
}

function showNext(fromAppOpen) {
    Object.assign(ratePrompt, { current: ratePrompt.queue.shift() || null, fromAppOpen, error: '', thanked: false });
}

/** @returns {Promise<boolean>} whether the rating was saved */
export async function submitRating(rating, comment, onSaved) {
    const item = ratePrompt.current;
    ratePrompt.busy = true;
    ratePrompt.error = '';
    try {
        await api(`/me/requests/${item.request_id}/review`, { method: 'POST', body: { rating, comment: comment.trim() || null } });
        ratePrompt.thanked = true;
        onSaved?.(item.request_id, { rating, comment: comment.trim() || null });
        // The provider's stars change for everyone
        loadCatalog({ force: true });
        return true;
    } catch (e) {
        ratePrompt.error = e.message;
        return false;
    } finally {
        ratePrompt.busy = false;
    }
}

/** "Continue to WorkMate": not asked about this job again when the app opens */
export function notNow() {
    const item = ratePrompt.current;
    if (item && ratePrompt.fromAppOpen) {
        api(`/me/requests/${item.request_id}/review/later`, { method: 'POST', body: {} }).catch(() => {});
    }
    next();
}

/** Close the thank-you (or skip), then show the next finished job if any */
export function next() {
    if (ratePrompt.fromAppOpen && ratePrompt.queue.length) showNext(true);
    else Object.assign(ratePrompt, { current: null, error: '', thanked: false });
}
