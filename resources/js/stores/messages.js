import { ref, watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';

/**
 * Chats between customers and providers, stored by the Laravel API.
 * Only the two sides of a chat can read it; the server checks every request.
 *
 * New messages are picked up by checking every so often while the site is
 * open (live delivery comes with real-time messaging later).
 */

export const unreadTotal = ref(0);

const UNREAD_CHECK_MS = 60_000;

export async function refreshUnread() {
    if (!auth.user) {
        unreadTotal.value = 0;
        return;
    }
    try {
        unreadTotal.value = (await api('/me/conversations/unread-count')).data.unread;
    } catch { /* keep the last known count */ }
}

watch(() => auth.user?.id, refreshUnread, { immediate: true });
setInterval(() => {
    if (document.visibilityState === 'visible') refreshUnread();
}, UNREAD_CHECK_MS);

/** Chats with at least one message, newest first */
export async function loadThreads() {
    return (await api('/me/conversations')).data;
}

/** Opens a chat (marking the other side's messages read) by provider or by conversation id */
export async function openChat({ providerId, conversationId }) {
    const res = providerId
        ? await api(`/me/conversations/provider/${providerId}`, { method: 'POST', body: {} })
        : await api(`/me/conversations/${conversationId}`);
    refreshUnread();
    return res.data;
}

export async function sendMessage(conversationId, body) {
    return (await api(`/me/conversations/${conversationId}/messages`, { method: 'POST', body: { body } })).data;
}
