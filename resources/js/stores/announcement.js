import { watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';

/**
 * "What's New on WorkMate": which release announcements this person has seen.
 * Signed in: remembered on the server (every device). Guests: in this
 * browser only. A guest who signs in carries what they've seen over, so the
 * same announcement doesn't come back. Separate from notifications.
 */
const SEEN_KEY = 'workmate.announcementsSeen';

export function seenHere(id) {
    try { return JSON.parse(localStorage.getItem(SEEN_KEY) || '[]').includes(id); } catch { return false; }
}

function rememberHere(id) {
    try {
        const list = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]').filter(x => x !== id);
        localStorage.setItem(SEEN_KEY, JSON.stringify([id, ...list].slice(0, 20)));
    } catch { /* storage blocked: the server still remembers signed-in people */ }
}

/** The announcement to show now, or null (switched off, not for them, or already seen) */
export async function loadAnnouncement() {
    const a = (await api('/announcements/current')).data;
    if (!a) return null;
    if (a.acknowledged) {
        rememberHere(a.id);
        return null;
    }
    if (seenHere(a.id)) {
        // Seen here before signing in: tell the server too
        if (auth.user) tellServer(a.id, 'dismissed');
        return null;
    }
    return a;
}

/** Seen: closed (dismissed), read in full (read), or gone by itself (expired) */
export function acknowledge(id, how) {
    rememberHere(id);
    if (auth.user) tellServer(id, how);
}

function tellServer(id, how) {
    api(`/me/announcements/${encodeURIComponent(id)}/acknowledge`, { method: 'POST', body: { how } }).catch(() => {
        // Kept in this browser; the server hears again next time they sign in here
    });
}

// Signing in during a visit that began as a guest, after seeing it here: carry it over
// (only then: an app opened already signed in asks the server, which knows)
let guestThisVisit = false;
watch(() => [auth.status, auth.user?.id], ([status, id]) => {
    if (status === 'ready' && !id) guestThisVisit = true;
    if (id && guestThisVisit) {
        guestThisVisit = false;
        try {
            const latest = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')[0];
            if (latest) tellServer(latest, 'dismissed');
        } catch { /* nothing to carry over */ }
    }
}, { immediate: true });
