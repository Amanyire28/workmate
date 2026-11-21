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

    <div class="mt-3 flex gap-2" role="group" aria-label="Which notifications to show">
      <button v-for="t in tabs" :key="t.key" type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-full px-4 text-sm font-semibold transition-colors"
        :class="notifications.filter === t.key ? 'bg-primary text-white' : 'bg-white text-ink ring-1 ring-line hover:bg-primary-tint/60'"
        :aria-pressed="notifications.filter === t.key ? 'true' : 'false'" @click="setFilter(t.key)">
        {{ t.label }}<span v-if="t.key === 'unread' && notifications.unread" class="rounded-full px-1.5 text-xs" :class="notifications.filter === 'unread' ? 'bg-white/25' : 'bg-primary text-white'">{{ notifications.unread > 99 ? '99+' : notifications.unread }}</span>
      </button>
    </div>

    <div v-if="notifications.loading && !notifications.items.length" class="mt-4 flex flex-col gap-2" aria-busy="true">
      <div v-for="n in 4" :key="n" class="wm-card flex gap-3"><div class="wm-skeleton h-10 w-10 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-40"></div><div class="wm-skeleton mt-2 h-3 w-full"></div></div></div>
    </div>

    <EmptyState v-else-if="notifications.error && !notifications.items.length" class="mt-4" tone="error" icon="bi-wifi-off" title="Notifications couldn’t load" :message="notifications.error">
      <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="loadNotifications(true)">Try again</button>
    </EmptyState>

    <EmptyState v-else-if="!notifications.items.length && notifications.filter === 'unread'" class="mt-4" icon="bi-check2-circle" title="You’re all caught up"
      message="Nothing unread right now." />

    <EmptyState v-else-if="!notifications.items.length" class="mt-4" icon="bi-bell" title="Nothing yet"
      message="Requests, messages and activity on your posts will show up here." />

    <template v-else>
      <section v-for="group in groups" :key="group.label" class="mt-5" :aria-labelledby="`group-${group.key}`">
        <h2 :id="`group-${group.key}`" class="wm-meta mb-2 px-1 font-medium uppercase tracking-wide">{{ group.label }}</h2>
        <ul class="wm-card !p-1.5 flex flex-col">
          <li v-for="n in group.items" :key="n.id" class="relative flex items-start gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-white/70" :class="{ 'bg-primary-tint/60': !n.read }">
            <!-- The whole row opens what it is about; the person's picture and name, and "see all", sit above it and do their own thing -->
            <a :href="n.url" class="absolute inset-0 rounded-2xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" :aria-label="rowLabel(n)" @click.prevent="open(n)"></a>

            <!-- The people it is about (real pictures, or initials); otherwise the kind of event -->
            <span v-if="n.actors && n.actors.length" class="relative z-10 shrink-0 pb-1 pr-1" :style="{ minWidth: `${40 + (Math.min(n.actors.length, 3) - 1) * 24}px` }">
              <button v-if="n.activity" type="button" class="block rounded-full" :aria-label="seeAllLabel(n)" @click="openActivity(n)">
                <AvatarStack :actors="n.actors" :size="40" />
              </button>
              <router-link v-else-if="n.actors[0].profile" :to="n.actors[0].profile" class="block rounded-full" :aria-label="`View ${n.actors[0].name}’s profile`">
                <AvatarStack :actors="n.actors" :size="40" />
              </router-link>
              <AvatarStack v-else :actors="n.actors" :size="40" />
              <span class="pointer-events-none absolute -bottom-0.5 -right-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] text-white ring-2 ring-white" aria-hidden="true">
                <i :class="['bi', KIND_ICONS[n.kind] || 'bi-bell']"></i>
              </span>
            </span>
            <span v-else class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="n.read ? 'bg-[#F1F5F9] text-muted' : 'bg-white text-primary'" aria-hidden="true">
              <i :class="['bi text-lg', KIND_ICONS[n.kind] || 'bi-bell']"></i>
            </span>

            <span class="pointer-events-none min-w-0 flex-1">
              <template v-if="n.lead">
                <span class="block text-[15px] leading-snug text-ink" :class="n.read ? '' : 'font-semibold'">
                  <router-link v-if="!n.grouped && n.actors[0] && n.actors[0].profile" :to="n.actors[0].profile" class="pointer-events-auto relative z-10 font-semibold hover:underline">{{ n.lead }}</router-link>
                  <strong v-else class="font-semibold">{{ n.lead }}</strong>{{ n.tail }}
                </span>
              </template>
              <template v-else>
                <span class="block text-[15px] leading-snug" :class="n.read ? 'text-ink' : 'font-semibold text-ink'">{{ n.title }}</span>
                <span class="mt-0.5 block text-[14px] leading-snug text-muted">{{ n.body }}</span>
              </template>
              <!-- A short plain-text look at the comment (never shown on the phone's lock screen) -->
              <span v-if="n.preview" class="mt-1.5 line-clamp-2 break-words rounded-xl bg-white/70 px-3 py-1.5 text-[14px] leading-snug text-ink ring-1 ring-line">{{ n.preview }}</span>
              <span class="mt-1 flex flex-wrap items-center gap-x-2 text-[12.5px] text-muted">
                <time :datetime="n.at">{{ agoLabel(n.at) }}</time>
                <span v-if="n.detail">· {{ n.detail }}</span>
                <button v-if="n.activity" type="button" class="pointer-events-auto relative z-10 font-semibold text-primary hover:underline" @click="openActivity(n)">{{ n.comments ? 'See all comments' : 'See who' }}</button>
              </span>
            </span>
            <span v-if="!n.read" class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
            <span v-if="!n.read" class="sr-only">Unread</span>
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

    <!-- Who is behind a grouped notification, or each comment in a group -->
    <BottomSheet :open="!!activity.item" :title="activity.item && activity.item.comments ? 'New comments' : 'People'" @close="activity.item = null">
      <p v-if="activity.loading" class="py-6 text-center wm-meta" aria-busy="true">Loading…</p>
      <p v-else-if="activity.error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ activity.error }}</span></p>
      <ul v-else class="flex flex-col divide-y divide-line">
        <li v-for="a in activity.actors" :key="a.id" class="flex items-center gap-3 py-3">
          <UserAvatar :name="a.name" :src="a.avatar_url || ''" :size="40" />
          <router-link v-if="a.profile" :to="a.profile" class="min-w-0 flex-1 truncate text-[15px] font-medium text-ink hover:underline" @click="activity.item = null">{{ a.name }}</router-link>
          <span v-else class="min-w-0 flex-1 truncate text-[15px] font-medium text-ink">{{ a.name }}</span>
        </li>
        <li v-for="c in activity.comments" :key="c.id" class="py-3">
          <router-link :to="c.url" class="flex items-start gap-3" @click="activity.item = null">
            <UserAvatar :name="c.actor.name" :src="c.actor.avatar_url || ''" :size="40" />
            <span class="min-w-0 flex-1">
              <span class="block text-[15px] font-medium text-ink">{{ c.actor.name }} <time class="ml-1 text-[12.5px] font-normal text-muted" :datetime="c.at">{{ agoLabel(c.at) }}</time></span>
              <span class="line-clamp-2 break-words text-[14px] leading-snug text-muted">{{ c.preview }}</span>
            </span>
          </router-link>
        </li>
      </ul>
    </BottomSheet>

    <!-- What to be told about -->
    <BottomSheet :open="settingsOpen" title="Notification settings" @close="closeSettings">
      <p class="text-sm text-muted">This list keeps what you choose below, whether or not your phone is told. <strong class="font-semibold text-ink">In the list</strong> controls what appears here; <strong class="font-semibold text-ink">On my phone</strong> controls whether it also sends a phone notification. Notices about your account, documents and reports always arrive.</p>
      <p v-if="choicesError" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ choicesError }}</span></p>
      <ul v-if="notifications.choices" class="mt-3 flex flex-col divide-y divide-line">
        <li v-for="(c, kind) in notifications.choices" :key="kind" class="py-3">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-tint text-primary" aria-hidden="true"><i :class="['bi', KIND_ICONS[kind]]"></i></span>
            <span class="min-w-0 flex-1">
              <span :id="`choice-${kind}`" class="block text-[15px] font-medium text-ink">{{ c.label }}</span>
              <span class="block text-[13px] text-muted">{{ c.hint }}</span>
            </span>
          </div>
          <div class="mt-2 flex items-center justify-between gap-4 pl-12">
            <span class="flex items-center gap-2 text-[13.5px] text-ink"><span :id="`bell-${kind}`">In the list</span>
              <button type="button" role="switch" :aria-checked="c.on ? 'true' : 'false'" :aria-labelledby="`bell-${kind} choice-${kind}`"
                class="relative h-7 w-12 shrink-0 rounded-full transition-colors" :class="c.on ? 'bg-primary' : 'bg-[#CBD5E1]'" @click="toggle(kind, !c.on)">
                <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="c.on ? 'translate-x-5' : ''" aria-hidden="true"></span>
              </button>
            </span>
            <span class="flex items-center gap-2 text-[13.5px]" :class="c.on ? 'text-ink' : 'text-muted'"><span :id="`phone-${kind}`">On my phone</span>
              <button type="button" role="switch" :disabled="!c.on" :aria-checked="c.on && c.push ? 'true' : 'false'" :aria-labelledby="`phone-${kind} choice-${kind}`"
                class="relative h-7 w-12 shrink-0 rounded-full transition-colors disabled:opacity-50" :class="c.on && c.push ? 'bg-primary' : 'bg-[#CBD5E1]'" @click="togglePush(kind, !c.push)">
                <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="c.on && c.push ? 'translate-x-5' : ''" aria-hidden="true"></span>
              </button>
            </span>
          </div>
        </li>
      </ul>
      <p v-else-if="!choicesError" class="py-6 text-center wm-meta">Loading…</p>

      <!-- Quiet hours work only while the platform's smart push is on -->
      <section v-if="notifications.meta && notifications.meta.smart_push" class="mt-5 rounded-2xl bg-primary-tint/50 p-4" aria-labelledby="quiet-title">
        <div class="flex items-center gap-3">
          <span class="min-w-0 flex-1">
            <span id="quiet-title" class="block text-[15px] font-medium text-ink">Quiet hours</span>
            <span class="block text-[13px] text-muted">No phone notifications for likes, follows and activity at night. Bookings and messages still come through, and the list keeps everything.</span>
          </span>
          <button type="button" role="switch" :aria-checked="quiet.enabled ? 'true' : 'false'" aria-labelledby="quiet-title"
            class="relative h-7 w-12 shrink-0 rounded-full transition-colors" :class="quiet.enabled ? 'bg-primary' : 'bg-[#CBD5E1]'" @click="toggleQuiet">
            <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="quiet.enabled ? 'translate-x-5' : ''" aria-hidden="true"></span>
          </button>
        </div>
        <div v-if="quiet.enabled" class="mt-3 flex items-end gap-3">
          <label class="flex-1 text-[13px] text-muted">From
            <input v-model="quiet.start" type="time" class="wm-input mt-1 !min-h-11" @change="saveQuiet">
          </label>
          <label class="flex-1 text-[13px] text-muted">Until
            <input v-model="quiet.end" type="time" class="wm-input mt-1 !min-h-11" @change="saveQuiet">
          </label>
        </div>
        <p v-if="quiet.enabled" class="mt-2 text-[12.5px] text-muted">In your time ({{ zoneName }}). It can run past midnight.</p>
        <p v-if="quietError" class="wm-field-error mt-2" role="alert">{{ quietError }}</p>
      </section>
      <PushNotificationSetting />
    </BottomSheet>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import EmptyState from '@/components/EmptyState.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import PushNotificationSetting from '@/components/PushNotificationSetting.vue';
import AvatarStack from '@/components/AvatarStack.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { api } from '@/api/client.js';
import { agoLabel } from '@/stores/catalog.js';
import { notifications, KIND_ICONS, loadNotifications, markRead, loadChoices, setChoice, setPush, setFilter, saveQuietHours } from '@/stores/notifications.js';

const route = useRoute();
const router = useRouter();

const tabs = [{ key: 'all', label: 'All' }, { key: 'unread', label: 'Unread' }];

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

/* A readable name for the whole-row link */
function rowLabel(n) {
  return [n.lead ? `${n.lead}${n.tail}` : `${n.title}. ${n.body}`, n.preview ? `“${n.preview}”` : '', n.detail || '', n.read ? '' : 'Unread'].filter(Boolean).join(' ');
}
function seeAllLabel(n) {
  return n.comments ? 'See all the comments' : 'See everyone who did this';
}

/* The people (or comments) inside a grouped notification */
const activity = reactive({ item: null, loading: false, error: '', actors: [], comments: [] });
async function openActivity(n) {
  Object.assign(activity, { item: n, loading: true, error: '', actors: [], comments: [] });
  try {
    const r = await api(`/me/notifications/${n.id}/activity`);
    if (activity.item !== n) return;
    activity.actors = r.data.actors || [];
    activity.comments = r.data.comments || [];
  } catch (e) {
    if (activity.item === n) activity.error = e.message || 'This couldn’t load.';
  } finally {
    if (activity.item === n) activity.loading = false;
  }
}

/* Settings */
const settingsOpen = ref(false);
const choicesError = ref('');
async function openSettings() {
  settingsOpen.value = true;
  choicesError.value = '';
  try { await loadChoices(); syncQuiet(); } catch (e) { choicesError.value = e.message; }
}
function closeSettings() {
  settingsOpen.value = false;
  if (route.query.settings) router.replace({ query: {} });
}
async function toggle(kind, on) {
  choicesError.value = '';
  try { await setChoice(kind, on); } catch (e) { choicesError.value = e.message; }
}
async function togglePush(kind, on) {
  choicesError.value = '';
  try { await setPush(kind, on); } catch (e) { choicesError.value = e.message; }
}

/* Quiet hours: 22:00 to 07:00 is offered the first time it is switched on */
const quiet = reactive({ enabled: false, start: '22:00', end: '07:00' });
const quietError = ref('');
const zoneName = computed(() => (notifications.meta && notifications.meta.quiet_hours && notifications.meta.quiet_hours.timezone) || 'Africa/Kampala');
function syncQuiet() {
  const q = notifications.meta && notifications.meta.quiet_hours;
  if (!q) return;
  quiet.enabled = q.enabled;
  if (q.start) quiet.start = q.start;
  if (q.end) quiet.end = q.end;
}
async function saveQuiet() {
  quietError.value = '';
  try {
    await saveQuietHours({ enabled: quiet.enabled, start: quiet.start, end: quiet.end });
    syncQuiet();
  } catch (e) {
    quietError.value = e.message;
  }
}
function toggleQuiet() {
  quiet.enabled = !quiet.enabled;
  saveQuiet();
}

onMounted(() => {
  loadNotifications(true);
  if (route.query.settings) openSettings();
});
</script>
