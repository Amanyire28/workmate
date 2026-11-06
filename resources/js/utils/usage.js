import { api } from '@/api/client.js';

/**
 * Anonymous usage counts for the admin dashboard: searches (and how they
 * turned out) and provider profile views. Only the day's total goes up on the
 * server; nothing about the person, device, place or search text is sent.
 */
export function countUsage(metric, outcome = null) {
    api('/app-events/count', { method: 'POST', body: outcome ? { metric, outcome } : { metric } }).catch(() => { /* never gets in the way */ });
}

const viewed = new Set();

/** One view per provider per visit, however often the page is reopened */
export function countProfileView(providerId) {
    let seen = viewed;
    try {
        seen = new Set(JSON.parse(sessionStorage.getItem('wm-profile-views') || '[]'));
    } catch { /* private mode: count once per page load */ }
    if (seen.has(providerId)) return;
    seen.add(providerId);
    viewed.add(providerId);
    try { sessionStorage.setItem('wm-profile-views', JSON.stringify([...seen].slice(-200))); } catch { /* ignore */ }
    countUsage('profile_view');
}
