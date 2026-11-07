import { reactive } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';

/**
 * Legal documents and this person's acceptances.
 *
 * Signed-in people: the server says what is still to accept (user.legal_pending)
 * and records each acceptance. Before signing in there is no account to record
 * against, so this device remembers which versions were agreed to; signing up
 * then records the acceptance on the account.
 */
export const legal = reactive({ documents: [], loaded: false, error: '' });

const WELCOME_KEY = 'wm-welcome';
const PERMISSIONS_KEY = 'wm-permissions-asked';

let pending = null;

/** The published documents (titles, versions and summaries) */
export function loadLegalDocuments({ force = false } = {}) {
    if (legal.loaded && !force) return Promise.resolve(legal.documents);
    if (pending) return pending;
    pending = api('/legal')
        .then((res) => { legal.documents = res.data; legal.loaded = true; legal.error = ''; return legal.documents; })
        .catch((e) => { legal.error = e.message; throw e; })
        .finally(() => { pending = null; });
    return pending;
}

export function fetchDocument(key, versionId = null) {
    return api(`/legal/${encodeURIComponent(key)}${versionId ? `?version_id=${versionId}` : ''}`).then(res => res.data);
}

/* ---------------- This device, before signing in ---------------- */

function readJson(key) {
    try { return JSON.parse(localStorage.getItem(key)); } catch { return null; }
}
function writeJson(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* storage blocked: asked again next time */ }
}

/** Documents for everyone that this device hasn't agreed to at their current version */
export function guestPending() {
    const agreed = readJson(WELCOME_KEY)?.versions || {};
    return legal.documents.filter(d => d.audience === 'everyone' && agreed[d.key] !== d.version_id);
}

export function agreeOnThisDevice(documents) {
    const record = readJson(WELCOME_KEY) || { versions: {} };
    for (const d of documents) record.versions[d.key] = d.version_id;
    record.at = new Date().toISOString();
    writeJson(WELCOME_KEY, record);
}

/** Whether this device already went through the permissions step */
export function permissionsAsked() {
    return !!readJson(PERMISSIONS_KEY);
}
export function markPermissionsAsked() {
    writeJson(PERMISSIONS_KEY, { at: new Date().toISOString() });
}

/* ---------------- Signed in ---------------- */

export async function acceptDocuments(versionIds) {
    const res = await api('/me/legal/accept', { method: 'POST', body: { version_ids: versionIds } });
    auth.user = res.data;
    return auth.user;
}

export function fetchMyLegal() {
    return api('/me/legal').then(res => res.data);
}

export async function setConsent(purpose, granted) {
    const res = await api('/me/consents', { method: 'PUT', body: { purpose, granted } });
    return res.data;
}
