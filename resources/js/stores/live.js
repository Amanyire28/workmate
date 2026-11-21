import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { notifications, setUnread, loadNotifications } from '@/stores/notifications.js';

/**
 * Keeps the bell and an open notification list current without waiting for the next check-in.
 *
 * With Web Push, the service worker tells every open WorkMate window when a push arrives (and
 * skips the phone alert if one is on screen): the window then asks for the unread count, and
 * reloads the list if it is open. Without Web Push (permission not given, or blocked, or no
 * support) nothing arrives that way, so while the notification list itself is open and on
 * screen it asks every few seconds. Everywhere else the presence check-in (stores/presence.js)
 * already brings the unread count with it.
 */
const LIST_POLL_SECONDS = 20;

let started = false;
let busy = false;
let again = false;
let timer = null;

const onList = () => typeof location !== 'undefined' && location.pathname === '/notifications';

/** The unread count now; the list too when it is the page being looked at */
export async function refreshNow() {
    if (!auth.user) return;
    if (busy) { again = true; return; }
    busy = true;
    try {
        const before = notifications.unread;
        const r = await api('/me/notifications/unread-count');
        setUnread(r?.data?.unread);
        if (onList() && notifications.loaded && !notifications.loading && (r?.data?.unread !== before || again)) {
            await loadNotifications(true);
        }
    } catch { /* the next check-in or message will do it */ } finally {
        busy = false;
        if (again) { again = false; refreshNow(); }
    }
}

function listPoll() {
    if (document.visibilityState !== 'visible' || !onList() || !auth.user) return;
    refreshNow();
}

export function startLiveNotifications() {
    if (started) return;
    started = true;
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'wm-notification') {
                // A push arrived: the list is out of date whatever the count says
                again = true;
                refreshNow();
            }
        });
    }
    timer = setInterval(listPoll, LIST_POLL_SECONDS * 1000);
}

export function stopLiveNotifications() {
    clearInterval(timer);
    started = false;
}
