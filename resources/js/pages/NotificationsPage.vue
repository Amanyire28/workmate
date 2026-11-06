<template>
  <div class="wm-container wm-container-sm wm-page">
    <div class="flex items-center justify-between gap-3">
      <h1 class="wm-h1">Notifications</h1>
      <div class="flex shrink-0 items-center -mr-2">
        <button v-if="notifications.unread" type="button" class="wm-btn wm-btn-ghost !min-h-10 !px-3 text-sm" @click="markRead()">Mark all as read</button>
        <button type="button" class="wm-icon-btn" aria-label="Choose what you’re notified about" @click="openSettings">
          <i class="bi bi-gear text-lg" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <div v-if="notifications.loading && !notifications.items.length" class="mt-4 flex flex-col gap-2" aria-busy="true">
      <div v-for="n in 4" :key="n" class="wm-card flex gap-3"><div class="wm-skeleton h-10 w-10 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-40"></div><div class="wm-skeleton mt-2 h-3 w-full"></div></div></div>
    </div>

    <EmptyState v-else-if="notifications.error && !notifications.items.length" class="mt-4" tone="error" icon="bi-wifi-off" title="Notifications couldn’t load" :message="notifications.error">
      <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="loadNotifications(true)">Try again</button>
    </EmptyState>

    <EmptyState v-else-if="!notifications.items.length" class="mt-4" icon="bi-bell" title="Nothing yet"
      message="Requests, messages and activity on your posts will show up here." />

    <template v-else>
      <section v-for="group in groups" :key="group.label" class="mt-5" :aria-labelledby="`group-${group.key}`">
        <h2 :id="`group-${group.key}`" class="wm-meta mb-2 px-1 font-medium uppercase tracking-wide">{{ group.label }}</h2>
        <ul class="wm-card !p-1.5 flex flex-col">
          <li v-for="n in group.items" :key="n.id">
            <a :href="n.url" class="flex items-start gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-white/70" :class="{ 'bg-primary-tint/60': !n.read }" @click.prevent="open(n)">
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="n.read ? 'bg-[#F1F5F9] text-muted' : 'bg-white text-primary'" aria-hidden="true">
                <i :class="['bi text-lg', KIND_ICONS[n.kind] || 'bi-bell']"></i>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-[15px] leading-snug" :class="n.read ? 'text-ink' : 'font-semibold text-ink'">{{ n.title }}</span>
                <span class="mt-0.5 block text-[14px] leading-snug text-muted">{{ n.body }}</span>
                <span class="mt-1 block text-[12.5px] text-muted"><time :datetime="n.at">{{ agoLabel(n.at) }}</time></span>
              </span>
              <span v-if="!n.read" class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
              <span v-if="!n.read" class="sr-only">Unread</span>
            </a>
          </li>
        </ul>
      </section>

      <div class="mt-4 flex justify-center">
        <button v-if="notifications.cursor" type="button" class="wm-btn wm-btn-secondary !min-h-11" :disabled="notifications.loading" @click="loadNotifications()">
          {{ notifications.loading ? 'Loading…' : 'Show older' }}
        </button>
        <p v-else class="wm-meta py-2">Notifications are kept for 90 days.</p>
      </div>
    </template>

    <!-- What to be told about -->
    <BottomSheet :open="settingsOpen" title="Notification settings" @close="closeSettings">
      <p class="text-sm text-muted">Turn off what you don’t need. It stops both phone notifications and this list. Notices about your account, documents and reports always arrive.</p>
      <p v-if="choicesError" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ choicesError }}</span></p>
      <ul v-if="notifications.choices" class="mt-3 flex flex-col divide-y divide-line">
        <li v-for="(c, kind) in notifications.choices" :key="kind" class="flex items-center gap-3 py-3">
          <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-tint text-primary" aria-hidden="true"><i :class="['bi', KIND_ICONS[kind]]"></i></span>
          <span class="min-w-0 flex-1">
            <span :id="`choice-${kind}`" class="block text-[15px] font-medium text-ink">{{ c.label }}</span>
            <span class="block text-[13px] text-muted">{{ c.hint }}</span>
          </span>
          <button type="button" role="switch" :aria-checked="c.on ? 'true' : 'false'" :aria-labelledby="`choice-${kind}`"
            class="relative h-7 w-12 shrink-0 rounded-full transition-colors" :class="c.on ? 'bg-primary' : 'bg-[#CBD5E1]'" @click="toggle(kind, !c.on)">
            <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="c.on ? 'translate-x-5' : ''" aria-hidden="true"></span>
          </button>
        </li>
      </ul>
      <p v-else-if="!choicesError" class="py-6 text-center wm-meta">Loading…</p>
      <PushNotificationSetting />
    </BottomSheet>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import EmptyState from '@/components/EmptyState.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import PushNotificationSetting from '@/components/PushNotificationSetting.vue';
import { agoLabel } from '@/stores/catalog.js';
import { notifications, KIND_ICONS, loadNotifications, markRead, loadChoices, setChoice } from '@/stores/notifications.js';

const route = useRoute();
const router = useRouter();

/* Today, this week, earlier */
const groups = computed(() => {
  const startOfToday = new Date(); startOfToday.setHours(0, 0, 0, 0);
  const weekAgo = startOfToday.getTime() - 6 * 86400000;
  const out = [
    { key: 'today', label: 'Today', items: [] },
    { key: 'week', label: 'This week', items: [] },
    { key: 'earlier', label: 'Earlier', items: [] },
  ];
  for (const n of notifications.items) {
    const t = new Date(n.at).getTime();
    out[t >= startOfToday.getTime() ? 0 : t >= weekAgo ? 1 : 2].items.push(n);
  }
  return out.filter(g => g.items.length);
});

function open(n) {
  markRead(n);
  router.push(n.url);
}

/* Settings */
const settingsOpen = ref(false);
const choicesError = ref('');
async function openSettings() {
  settingsOpen.value = true;
  choicesError.value = '';
  try { await loadChoices(); } catch (e) { choicesError.value = e.message; }
}
function closeSettings() {
  settingsOpen.value = false;
  if (route.query.settings) router.replace({ query: {} });
}
async function toggle(kind, on) {
  choicesError.value = '';
  try { await setChoice(kind, on); } catch (e) { choicesError.value = e.message; }
}

onMounted(() => {
  loadNotifications(true);
  if (route.query.settings) openSettings();
});
</script>
