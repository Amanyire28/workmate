import { reactive, watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';

/**
 * The notification list under the bell. The unread count arrives with the
 * presence heartbeat (stores/presence.js), so there is no extra polling.
 * What WorkMate tells people about is chosen per kind in the settings.
 */
export const notifications = reactive({
    unread: 0,
    items: [],
    cursor: null,
    loaded: false,
    loading: false,
    error: '',
    choices: null,
    // 'all' or 'unread'
    filter: 'all',
    // quiet hours, whether smart push is on, whether this server can push at all
    meta: null,
});

export const KIND_ICONS = {
    requests: 'bi-briefcase',
    job_alerts: 'bi-geo-alt',
    messages: 'bi-chat',
    community_comments: 'bi-chat-square-text',
    community_mentions: 'bi-at',
    community_posts: 'bi-file-earmark-richtext',
    community_likes: 'bi-hand-thumbs-up',
    community_follows: 'bi-person-plus',
    referrals: 'bi-gift',
    account: 'bi-shield-check',
};

export function setUnread(count) {
    if (typeof count === 'number') notifications.unread = count;
}

// Another account (or none) starts from nothing
watch(() => auth.user?.id, () => {
    Object.assign(notifications, { unread: 0, items: [], cursor: null, loaded: false, error: '', choices: null, meta: null, filter: 'all' });
});

export async function loadNotifications(fresh = false) {
    if (notifications.loading && !fresh) return;
    if (fresh) notifications.cursor = null;
    notifications.loading = true;
    notifications.error = '';
    try {
        const q = new URLSearchParams();
        if (notifications.cursor) q.set('cursor', notifications.cursor);
        if (notifications.filter === 'unread') q.set('filter', 'unread');
        const r = await api(`/me/notifications${q.toString() ? `?${q}` : ''}`);
        notifications.items = fresh ? r.data : [...notifications.items, ...r.data];
        notifications.cursor = r.meta.next_cursor;
        notifications.unread = r.meta.unread;
        notifications.loaded = true;
    } catch (e) {
        notifications.error = e.message;
    } finally {
        notifications.loading = false;
    }
}

/** Read straight away on screen; the server follows */
export async function markRead(item = null) {
    if (item) {
        if (item.read) return;
        item.read = true;
        notifications.unread = Math.max(0, notifications.unread - 1);
    } else {
        notifications.items.forEach(n => { n.read = true; });
        notifications.unread = 0;
        // Nothing is left to show in the unread tab
        if (notifications.filter === 'unread') { notifications.items = []; notifications.cursor = null; }
    }
    try {
        const r = await api(item ? `/me/notifications/${item.id}/read` : '/me/notifications/read', { method: 'POST', body: {} });
        notifications.unread = r.data.unread;
    } catch { /* corrected on the next heartbeat */ }
}

/** All or unread. Switching starts the list again from the newest. */
export function setFilter(filter) {
    if (notifications.filter === filter) return;
    notifications.filter = filter;
    return loadNotifications(true);
}

function applySettings(r) {
    notifications.choices = r.data;
    notifications.meta = r.meta || null;
}

export async function loadChoices() {
    applySettings(await api('/me/notification-settings'));
    return notifications.choices;
}

/** In the list (the bell): on or off for one kind */
export async function setChoice(kind, on) {
    const before = notifications.choices[kind].on;
    notifications.choices[kind].on = on;
    try {
        applySettings(await api('/me/notification-settings', { method: 'PUT', body: { [kind]: on } }));
    } catch (e) {
        notifications.choices[kind].on = before;
        throw e;
    }
}

/** On this account's phones and browsers: whether one kind may also push (the list keeps it either way) */
export async function setPush(kind, on) {
    const before = notifications.choices[kind].push;
    notifications.choices[kind].push = on;
    try {
        applySettings(await api('/me/notification-settings', { method: 'PUT', body: { push: { [kind]: on } } }));
    } catch (e) {
        notifications.choices[kind].push = before;
        throw e;
    }
}

/** Quiet hours: { enabled, start: 'HH:MM', end: 'HH:MM' }; the timezone is this device's */
export async function saveQuietHours(q) {
    const timezone = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone; } catch { return null; } })();
    applySettings(await api('/me/notification-settings', { method: 'PUT', body: { quiet_hours: { ...q, timezone } } }));
}
