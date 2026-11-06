import { watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { appSettings } from '@/stores/catalog.js';
import { setUnread } from '@/stores/notifications.js';

/**
 * While WorkMate is open and on screen, a signed-in account checks in every
 * presence.heartbeat_seconds (Admin → Settings), so administrators can see
 * who is online or recently active. Only the time is recorded, never a
 * location. Nothing is sent while the app is in the background. The answer
 * carries the unread notification count for the bell.
 */
let timer = null;
let started = false;

function beat() {
    if (!auth.user || document.visibilityState !== 'visible') return;
    api('/me/heartbeat', { method: 'POST', body: {} }).then(r => setUnread(r?.data?.unread_notifications)).catch(() => {});
}

function schedule() {
    clearInterval(timer);
    timer = null;
    if (!auth.user) return;
    beat();
    timer = setInterval(beat, Math.max(15, appSettings.heartbeatSeconds || 60) * 1000);
}

export function startPresence() {
    if (started) return;
    started = true;
    watch(() => [auth.user?.id, appSettings.heartbeatSeconds], schedule, { immediate: true });
    // Back on screen: check in straight away
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') beat(); });
}
