/**
 * Remembers which notices an administrator closed, in this browser only.
 *
 * What is stored is just a short "version" string per administrator and notice, for example "off" or "on" for a switch.
 * It never holds a figure, a name or any financial record. A notice stays closed only while its version is unchanged: when
 * the content or the condition it describes changes, the version changes and the notice shows again. Reading or writing can
 * fail (private browsing, storage full, storage blocked); every function then behaves as if nothing was remembered.
 */
const PREFIX = 'wm.admin.notice.';
const MAX_VERSION = 60;

const listeners = new Set();

function store(storage) {
    if (storage) return storage;
    try { return typeof localStorage !== 'undefined' ? localStorage : null; } catch (e) { return null; }
}

const keyFor = (userId, noticeId) => `${PREFIX}${userId}.${noticeId}`;
const usable = (userId, noticeId) => userId !== null && userId !== undefined && userId !== '' && !!noticeId;
const clean = (version) => String(version === undefined || version === null ? '' : version).slice(0, MAX_VERSION);

function changed(kind) {
    listeners.forEach((fn) => { try { fn(kind); } catch (e) { /* a listener must not break the others */ } });
}

/** Called whenever a notice is closed or reopened (kind is 'dismiss' or 'reopen'), so screens can update. Returns an unsubscribe function. */
export function onNoticeChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/** True only when this administrator closed this notice AND its version has not changed since */
export function isDismissed(userId, noticeId, version = '', storage) {
    if (!usable(userId, noticeId)) return false;
    try {
        const s = store(storage);
        return !!s && s.getItem(keyFor(userId, noticeId)) === clean(version);
    } catch (e) {
        return false;
    }
}

/** Remember the close. Returns false when it could not be remembered (the notice is still hidden for this visit). */
export function dismiss(userId, noticeId, version = '', storage) {
    if (!usable(userId, noticeId)) return false;
    let ok = false;
    try {
        const s = store(storage);
        if (s) { s.setItem(keyFor(userId, noticeId), clean(version)); ok = true; }
    } catch (e) {
        ok = false;
    }
    changed('dismiss');

    return ok;
}

export function reopen(userId, noticeId, storage) {
    if (!usable(userId, noticeId)) return;
    try { store(storage)?.removeItem(keyFor(userId, noticeId)); } catch (e) { /* nothing remembered */ }
    changed('reopen');
}

/** The ids of this administrator's closed notices, optionally only those starting with a prefix such as "finance.estimates." */
export function dismissedIds(userId, scope = '', storage) {
    const out = [];
    if (userId === null || userId === undefined || userId === '') return out;
    try {
        const s = store(storage);
        if (!s) return out;
        const head = `${PREFIX}${userId}.`;
        for (let i = 0; i < s.length; i++) {
            const k = s.key(i);
            if (k && k.indexOf(head) === 0) {
                const id = k.slice(head.length);
                if (!scope || id.indexOf(scope) === 0) out.push(id);
            }
        }
    } catch (e) {
        return [];
    }

    return out;
}

/** Show every closed notice of this administrator again (optionally only one screen's, by id prefix) */
export function reopenAll(userId, scope = '', storage) {
    dismissedIds(userId, scope, storage).forEach((id) => {
        try { store(storage)?.removeItem(keyFor(userId, id)); } catch (e) { /* nothing remembered */ }
    });
    changed('reopen');
}
