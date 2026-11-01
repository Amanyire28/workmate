import { reactive, computed } from 'vue';
import { api, ApiError } from '@/api/client.js';
import { refreshJobs, loadCatalog } from '@/stores/catalog.js';

/**
 * The signed-in account, as the server reports it.
 *
 * status: 'unknown' before the first check, 'loading' during it, 'ready' once
 * we know (user is null when signed out) and 'unavailable' when the server
 * could not answer. Showing or hiding things from this is convenience only:
 * Laravel checks every request.
 */
export const auth = reactive({ user: null, status: 'unknown' });

export const isSignedIn = computed(() => !!auth.user);
export const isProvider = computed(() => !!auth.user?.capabilities?.provider);

/** Whether this provider profile belongs to the signed-in account */
export function isOwnProvider(providerId) {
    return !!auth.user?.provider_ids?.includes(Number(providerId));
}

let pending = null;

export function loadSession({ force = false } = {}) {
    if (!force && auth.status === 'ready') return Promise.resolve(auth.user);
    if (pending) return pending;
    if (auth.status !== 'ready') auth.status = 'loading';

    pending = api('/me')
        .then((res) => { auth.user = res.data; auth.status = 'ready'; return auth.user; })
        .catch((e) => {
            auth.user = null;
            // Signed out (or an inactive account) is a normal answer; anything else means we couldn't check
            const signedOut = e instanceof ApiError && (e.status === 401 || e.code === 'account_inactive');
            auth.status = signedOut ? 'ready' : 'unavailable';
            return null;
        })
        .finally(() => { pending = null; });

    return pending;
}

function signedIn(user) {
    auth.user = user;
    auth.status = 'ready';
    accountChanged();
    return user;
}

// Jobs say whether they are yours or you applied, so reload them for the new account
function accountChanged() {
    refreshJobs().catch(() => { /* shown again on the next load */ });
}

export async function signIn({ email, password, remember }) {
    const res = await api('/auth/login', { method: 'POST', body: { email, password, remember } });
    return signedIn(res.data);
}

export async function register(fields) {
    const res = await api('/auth/register', { method: 'POST', body: fields });
    return signedIn(res.data);
}

export async function signOut() {
    try {
        await api('/auth/logout', { method: 'POST' });
    } finally {
        auth.user = null;
        accountChanged();
    }
}

/** A provider's photo shows on their public listing, so reload it there */
export function loadCatalogAfterPhoto(user) {
    if (user?.capabilities?.provider) loadCatalog({ force: true });
}

export async function updateProfile(fields) {
    const res = await api('/me', { method: 'PATCH', body: fields });
    return signedIn(res.data);
}

/** Where to go after signing in: only paths inside this site */
export function safeRedirect(value, fallback = '/account') {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : fallback;
}
