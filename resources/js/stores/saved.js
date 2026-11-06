import { reactive, watch } from 'vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { showFlash } from '@/stores/flash.js';
import router from '@/router/index.js';

/**
 * Providers the signed-in account saved to come back to. The heart changes
 * straight away and goes back if the server says no.
 */
export const saved = reactive({ ids: new Set(), list: [], loaded: false });

export async function loadSaved() {
    if (!auth.user) return;
    try {
        const r = await api('/me/saved-providers');
        saved.list = r.data;
        saved.ids = new Set(r.data.map(s => s.provider_id));
        saved.loaded = true;
    } catch { /* hearts show empty until the next load */ }
}

// Another account (or none) has its own list
watch(() => auth.user?.id, (id) => {
    Object.assign(saved, { ids: new Set(), list: [], loaded: false });
    if (id) loadSaved();
}, { immediate: true });

export const isSaved = (providerId) => saved.ids.has(Number(providerId));

export async function toggleSaved(providerId, name = 'this provider') {
    if (!auth.user) {
        router.push({ path: '/sign-in', query: { redirect: router.currentRoute.value.fullPath } });
        return;
    }
    const id = Number(providerId);
    const on = !saved.ids.has(id);
    const ids = new Set(saved.ids);
    on ? ids.add(id) : ids.delete(id);
    saved.ids = ids;
    try {
        await api(`/providers/${id}/save`, { method: on ? 'POST' : 'DELETE' });
        if (on) saved.list = [{ provider_id: id, saved_at: new Date().toISOString(), available: true }, ...saved.list.filter(s => s.provider_id !== id)];
        else saved.list = saved.list.filter(s => s.provider_id !== id);
        showFlash(on ? `Saved ${name}. Find them under Saved providers.` : `Removed ${name} from your saved providers.`);
    } catch (e) {
        const back = new Set(saved.ids);
        on ? back.delete(id) : back.add(id);
        saved.ids = back;
        showFlash(e.message || 'That didn’t work. Please try again.', 'error');
    }
}
