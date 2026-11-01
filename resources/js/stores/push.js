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
        await save(subscription);
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
    } catch {
        push.error = 'Notifications couldn’t be turned off. Please try again.';
    } finally {
        push.busy = false;
    }
}
