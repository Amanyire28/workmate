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
});

export const KIND_ICONS = {
    requests: 'bi-briefcase',
    job_alerts: 'bi-geo-alt',
    messages: 'bi-chat',
    community_comments: 'bi-chat-square-text',
    community_posts: 'bi-file-earmark-richtext',
    community_likes: 'bi-hand-thumbs-up',
    community_follows: 'bi-person-plus',
    account: 'bi-shield-check',
};

export function setUnread(count) {
    if (typeof count === 'number') notifications.unread = count;
}

// Another account (or none) starts from nothing
watch(() => auth.user?.id, () => {
    Object.assign(notifications, { unread: 0, items: [], cursor: null, loaded: false, error: '', choices: null });
});

export async function loadNotifications(fresh = false) {
    if (notifications.loading && !fresh) return;
    if (fresh) notifications.cursor = null;
    notifications.loading = true;
    notifications.error = '';
    try {
        const r = await api(`/me/notifications${notifications.cursor ? `?cursor=${encodeURIComponent(notifications.cursor)}` : ''}`);
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
    }
    try {
        const r = await api(item ? `/me/notifications/${item.id}/read` : '/me/notifications/read', { method: 'POST', body: {} });
        notifications.unread = r.data.unread;
    } catch { /* corrected on the next heartbeat */ }
}

export async function loadChoices() {
    notifications.choices = (await api('/me/notification-settings')).data;
    return notifications.choices;
}

export async function setChoice(kind, on) {
    const before = notifications.choices[kind].on;
    notifications.choices[kind].on = on;
    try {
        notifications.choices = (await api('/me/notification-settings', { method: 'PUT', body: { [kind]: on } })).data;
    } catch (e) {
        notifications.choices[kind].on = before;
        throw e;
    }
}
