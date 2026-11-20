import { reactive } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { pwa, platformGroup } from '@/stores/pwa.js';

/**
 * Push notifications on this device. The browser holds the subscription; the
 * server keeps a copy linked to the signed-in account so announcements reach it.
 *
 * state: checking | on | off | denied (blocked in the browser settings)
 *        | install-first (iPhone: only the home-screen app can receive them)
 *        | unsupported | unavailable (not set up on the server yet)
 */
export const push = reactive({ state: 'checking', busy: false, error: '' });

let publicKey = null;
let syncedEndpoint = null;

/*
 * Which account this device's subscription was linked to, so a signed-out
 * device, or one where someone else signs in, never keeps receiving that
 * account's notifications. (A subscription made before anyone signed in,
 * on the welcome screens, has no owner yet and is kept.)
 */
const OWNER_KEY = 'workmate.pushOwner';
function owner() {
    try { return localStorage.getItem(OWNER_KEY); } catch { return null; }
}
function setOwner(id) {
    try {
        if (id) localStorage.setItem(OWNER_KEY, String(id)); else localStorage.removeItem(OWNER_KEY);
    } catch { /* private mode: the server side still protects */ }
}

function keyBytes(base64url) {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), c => c.charCodeAt(0));
}

function sameKey(subscription) {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true;   // older browsers don't say; assume it matches
    const a = new Uint8Array(current);
    const b = keyBytes(publicKey);
    return a.length === b.length && a.every((x, i) => x === b[i]);
}

function encoding() {
    const supported = window.PushManager?.supportedContentEncodings || ['aes128gcm'];
    return supported.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
}

async function registration() {
    return navigator.serviceWorker.getRegistration();
}

/** Sends this device's subscription to the server for the signed-in account */
async function save(subscription) {
    const json = subscription.toJSON();
    await api('/me/push-subscriptions', {
        method: 'POST',
        body: { endpoint: json.endpoint, keys: json.keys, content_encoding: encoding(), platform: platformGroup() },
    });
    syncedEndpoint = json.endpoint;
    setOwner(auth.user?.id);
}

/** This device's push address, if it has one (sent when signing out) */
export async function currentPushEndpoint() {
    try {
        if (!('serviceWorker' in navigator)) return null;
        const subscription = await (await registration())?.pushManager.getSubscription();
        return subscription?.endpoint || null;
    } catch {
        return null;
    }
}

/**
 * After signing out (or on a device whose account was signed out): stops this
 * device receiving anything at all. The next person turns notifications on
 * for themselves.
 */
export async function releaseThisDevice() {
    try {
        const subscription = 'serviceWorker' in navigator ? await (await registration())?.pushManager.getSubscription() : null;
        if (subscription) await subscription.unsubscribe();
    } catch {
        // The server has already forgotten it, so nothing more is sent here
    }
    syncedEndpoint = null;
    setOwner(null);
    if (push.state === 'on') push.state = 'off';
}

/**
 * Opened signed out: any subscription on this device is left over from an
 * account (notifications can only be turned on while signed in), e.g. one
 * signed out before sign-out removed subscriptions, or whose session ran out.
 * It's released, so that account's notifications stop arriving here.
 */
export async function releaseIfSignedOut() {
    if (auth.user) return;
    try {
        if (!('serviceWorker' in navigator)) return;
        const subscription = await (await registration())?.pushManager.getSubscription();
        if (subscription || owner()) await releaseThisDevice();
    } catch {
        // Nothing to release
    }
}

/** Works out the state, and makes sure the server knows about this device */
export async function refreshPush() {
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!supported) {
        push.state = pwa.platform.startsWith('ios') && !pwa.standalone ? 'install-first' : 'unsupported';
        return;
    }

    try {
        if (publicKey === null) {
            const { data } = await api('/push/config');
            publicKey = data.enabled ? data.public_key : '';
        }
        if (!publicKey) {
            push.state = 'unavailable';
            return;
        }

        const reg = await registration();
        if (!reg) {
            push.state = 'unsupported';   // no service worker, e.g. while developing with npm run dev
            return;
        }
        if (Notification.permission === 'denied') {
            push.state = 'denied';
            return;
        }

        let subscription = await reg.pushManager.getSubscription();
        if (subscription && !sameKey(subscription)) {
            // The server's keys changed: the old subscription can't receive anything
            await subscription.unsubscribe().catch(() => {});
            subscription = null;
        }
        // Linked to another account, or to one that has signed out: release it rather than
        // passing that person's notifications on (whoever is here turns them on for themselves)
        const linkedTo = owner();
        if (subscription && linkedTo && (!auth.user || String(auth.user.id) !== linkedTo)) {
            await releaseThisDevice();
            subscription = null;
        }
        if (subscription && Notification.permission === 'granted') {
            push.state = 'on';
            if (auth.user && syncedEndpoint !== subscription.endpoint) await save(subscription).catch(() => {});
        } else {
            push.state = 'off';
        }
    } catch {
        if (push.state === 'checking') push.state = 'unsupported';
    }
}

export async function enablePush() {
    push.busy = true;
    push.error = '';
    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            push.state = permission === 'denied' ? 'denied' : 'off';
            return;
        }
        const reg = await navigator.serviceWorker.ready;
        const subscription = await reg.pushManager.getSubscription()
            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
        // Before signing in (the welcome screens) the device is ready; refreshPush
        // links it to the account once someone signs in
        if (auth.user) await save(subscription);
        push.state = 'on';
    } catch (e) {
        push.error = e?.status ? e.message : 'Notifications couldn’t be turned on. Please try again.';
    } finally {
        push.busy = false;
    }
}

export async function disablePush() {
    push.busy = true;
    push.error = '';
    try {
        const subscription = await (await registration())?.pushManager.getSubscription();
        if (subscription) {
            const { endpoint } = subscription;
            await subscription.unsubscribe();
            await api('/me/push-subscriptions', { method: 'DELETE', body: { endpoint } }).catch(() => {});
        }
        syncedEndpoint = null;
        push.state = 'off';
        // They chose this: don't remind them for a month
        snoozeReminder(30);
    } catch {
        push.error = 'Notifications couldn’t be turned off. Please try again.';
    } finally {
        push.busy = false;
    }
}

/* ---------------- Reminder to turn notifications on ---------------- */

const REMINDER_KEY = 'wm-push-reminder';
const DAY = 86400000;
const MAX_REMINDERS = 3;

function reminderRecord() {
    try { return JSON.parse(localStorage.getItem(REMINDER_KEY)) || { shown: 0, until: 0 }; } catch { return { shown: 0, until: 0 }; }
}

function saveReminder(record) {
    try { localStorage.setItem(REMINDER_KEY, JSON.stringify(record)); } catch { /* storage unavailable: it may show again */ }
}

/** Whether this device may be reminded now: at most once a week, three times in all */
export function reminderDue() {
    const r = reminderRecord();
    return r.shown < MAX_REMINDERS && Date.now() >= r.until;
}

/** Counts a reminder shown and waits a week before the next */
export function reminderShown() {
    const r = reminderRecord();
    saveReminder({ shown: r.shown + 1, until: Date.now() + 7 * DAY });
}

export function snoozeReminder(days) {
    const r = reminderRecord();
    saveReminder({ ...r, until: Date.now() + days * DAY });
}
