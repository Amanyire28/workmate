<template>
  <div class="wm-container wm-page">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_300px]">
      <!-- ============ Left: community navigation (desktop) ============ -->
      <aside class="hidden lg:block" aria-label="Community">
        <div class="sticky top-[calc(var(--wm-header-h)+16px)] flex flex-col gap-5">
          <nav class="flex flex-col gap-0.5" aria-label="Community pages">
            <router-link v-for="l in sideLinks" :key="l.to" :to="l.to" class="flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-medium"
              :class="l.active ? 'bg-primary-tint text-primary' : 'text-ink hover:bg-white/60'" :aria-current="l.active ? 'page' : undefined">
              <i :class="['bi text-lg', l.icon]" aria-hidden="true"></i>{{ l.label }}
            </router-link>
          </nav>

          <div v-if="!saved">
            <p class="px-3 text-[12px] font-semibold uppercase tracking-wide text-muted">Kinds of posts</p>
            <div class="mt-1.5 flex flex-col gap-0.5">
              <button v-for="c in [{ id: '', label: 'All posts', icon: 'bi-grid' }, ...POST_CATEGORIES]" :key="c.id" type="button"
                class="flex min-h-10 items-center gap-3 rounded-xl px-3 text-left text-[14px]"
                :class="(filters.category || '') === c.id ? 'bg-white/80 font-semibold text-ink shadow-sm' : 'text-muted hover:bg-white/60 hover:text-ink'"
                :aria-pressed="(filters.category || '') === c.id" @click="setQuery({ category: c.id })">
                <i :class="['bi', c.icon]" aria-hidden="true"></i>{{ c.label }}
              </button>
            </div>
          </div>

          <div v-if="!saved" class="flex flex-col gap-3 px-1">
            <div>
              <label for="side-topic" class="px-2 text-[12px] font-semibold uppercase tracking-wide text-muted">Profession</label>
              <select id="side-topic" class="wm-input mt-1.5" :value="filters.topic" @change="setQuery({ topic: $event.target.value })">
                <option value="">All professions</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
              </select>
            </div>
            <div>
              <p class="px-2 text-[12px] font-semibold uppercase tracking-wide text-muted">Place</p>
              <PlacePicker class="mt-1.5" id="side-area" :model-value="filters.area" any-label="Anywhere" placeholder="Near a place" @update:model-value="setQuery({ area: $event })" />
            </div>
            <button v-if="filtered" type="button" class="wm-link self-start px-2 text-sm min-h-10" @click="clearFilters">Clear filters</button>
          </div>
        </div>
      </aside>

      <!-- ============ Centre: the feed ============ -->
      <main class="min-w-0 mx-auto w-full max-w-[640px]">
        <div class="flex items-center justify-between gap-3">
          <!-- On phones the top bar already says Community -->
          <h1 class="wm-h1" :class="{ 'sr-only lg:not-sr-only': !saved }">{{ saved ? 'Saved posts' : 'Community' }}</h1>
          <router-link v-if="saved" to="/community" class="lg:hidden wm-btn wm-btn-ghost !min-h-10">Community</router-link>
        </div>

        <!-- Phones: people, your page and saved posts -->
        <nav v-if="!saved" class="lg:hidden grid gap-2" :class="auth.user ? 'grid-cols-3' : 'grid-cols-1'" aria-label="Community pages">
          <router-link v-for="l in phoneLinks" :key="l.to" :to="l.to"
            class="flex min-h-12 items-center justify-center gap-1.5 rounded-2xl border border-primary/15 bg-primary-tint px-2 text-[14px] font-semibold text-primary shadow-[0_2px_8px_rgba(23,105,210,0.08)] hover:bg-[#DCEBFF]">
            <i :class="['bi text-[17px]', l.icon]" aria-hidden="true"></i><span class="truncate">{{ l.label }}</span>
          </router-link>
        </nav>

        <!-- Start a post (phones use the + button) -->
        <div v-if="!saved" class="wm-card mt-4 hidden lg:flex items-center gap-3 !py-3">
          <UserAvatar v-if="auth.user" :name="auth.user.name" :src="auth.user.avatar_url || ''" :size="40" />
          <span v-else class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-tint text-primary" aria-hidden="true"><i class="bi bi-person"></i></span>
          <button type="button" class="min-h-11 flex-1 rounded-full bg-[#F1F5F9] px-4 text-left text-[15px] text-muted hover:bg-[#E9EEF5]" @click="compose()">
            Share something about your work...
          </button>
          <button type="button" class="wm-icon-btn text-primary" aria-label="Share photos of your work" @click="compose()">
            <i class="bi bi-images text-xl" aria-hidden="true"></i>
          </button>
        </div>

        <!-- Posts -->
        <div v-if="state.loading && !state.posts.length" class="mt-4 flex flex-col gap-4" aria-busy="true" aria-label="Loading posts">
          <div v-for="n in 3" :key="n" class="wm-card">
            <div class="flex items-center gap-3"><div class="wm-skeleton h-11 w-11 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-40"></div><div class="wm-skeleton mt-2 h-3 w-24"></div></div></div>
            <div class="wm-skeleton mt-4 h-4 w-full"></div><div class="wm-skeleton mt-2 h-4 w-4/5"></div>
            <div v-if="n === 1" class="wm-skeleton mt-4 h-48 w-full !rounded-xl"></div>
          </div>
        </div>

        <EmptyState v-else-if="state.error && !state.posts.length" class="mt-4" tone="error" icon="bi-wifi-off" title="The community couldn’t load" :message="state.error">
          <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load(true)">Try again</button>
        </EmptyState>

        <section v-else-if="!state.posts.length && tab === 'following' && !filtered" class="wm-card mt-4" aria-labelledby="follow-heading">
          <h2 id="follow-heading" class="text-[17px] font-semibold text-ink">{{ me.card?.following_count ? 'Nothing new from people you follow' : 'Follow people to see their posts here' }}</h2>
          <p class="mt-1 text-sm text-muted">Providers and members who share their work. Follow a few to start.</p>
          <ul v-if="suggested.length" class="mt-4 flex flex-col gap-3">
            <li v-for="p in suggested" :key="p.id"><PersonRow :person="p" /></li>
          </ul>
          <router-link to="/community/people" class="wm-btn wm-btn-secondary mt-4 !min-h-11">Discover more people</router-link>
        </section>

        <EmptyState v-else-if="!state.posts.length" class="mt-4" :icon="empty.icon" :title="empty.title" :message="empty.message">
          <button v-if="empty.action === 'compose'" type="button" class="wm-btn wm-btn-primary !min-h-12" @click="compose()">Share your work</button>
          <button v-else-if="empty.action === 'clear'" type="button" class="wm-btn wm-btn-primary !min-h-12" @click="clearFilters">Clear filters</button>
          <button v-else-if="empty.action === 'latest'" type="button" class="wm-btn wm-btn-primary !min-h-12" @click="setQuery({ tab: 'latest' })">See all latest posts</button>
          <router-link v-else-if="empty.action === 'browse'" to="/community" class="wm-btn wm-btn-primary !min-h-12">Browse the community</router-link>
        </EmptyState>

        <template v-else>
          <ul class="mt-4 flex flex-col gap-4">
            <li v-for="post in state.posts" :key="post.id">
              <PostCard :post="post" sheet-comments @comment="commenting = $event" @edit="editing = $event" @delete="remove" @report="reporting = { about: 'post', postId: $event.id }" />
            </li>
          </ul>

          <div ref="sentinel" class="h-px" aria-hidden="true"></div>
          <div class="mt-4 flex justify-center">
            <button v-if="!state.done" type="button" class="wm-btn wm-btn-secondary !min-h-11" :disabled="state.loading" @click="load()">
              <span v-if="state.loading" class="wm-spinner" aria-hidden="true"></span>{{ state.loading ? 'Loading…' : 'Show more posts' }}
            </button>
            <p v-else class="wm-meta py-2">You’re all caught up.</p>
          </div>
          <p v-if="state.error && state.posts.length" class="wm-notice wm-notice-error mt-3" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ state.error }} <button type="button" class="wm-link" @click="load()">Try again</button></span>
          </p>
        </template>
      </main>

      <!-- ============ Right: open jobs and the ground rules (wide screens) ============ -->
      <aside class="hidden xl:block" aria-label="More from WorkMate">
        <div class="sticky top-[calc(var(--wm-header-h)+16px)] flex flex-col gap-4">
          <section v-if="suggested.length" class="wm-card" aria-labelledby="suggested-heading">
            <div class="flex items-center justify-between">
              <h2 id="suggested-heading" class="text-[15px] font-semibold text-ink">People to follow</h2>
              <router-link to="/community/people" class="wm-link text-sm">See more</router-link>
            </div>
            <ul class="mt-3 flex flex-col gap-3">
              <li v-for="p in suggested" :key="p.id"><PersonRow :person="p" compact /></li>
            </ul>
          </section>

          <section v-if="topics.length" class="wm-card" aria-labelledby="topics-heading">
            <h2 id="topics-heading" class="text-[15px] font-semibold text-ink">Active this week</h2>
            <ul class="mt-2 flex flex-col">
              <li v-for="t in topics" :key="t.slug">
                <button type="button" class="flex w-full min-h-10 items-center justify-between gap-3 rounded-lg px-1 text-left text-[14px] hover:bg-white/60"
                  :aria-pressed="filters.topic === t.slug" @click="setQuery({ topic: filters.topic === t.slug ? '' : t.slug })">
                  <span class="font-medium" :class="filters.topic === t.slug ? 'text-primary' : 'text-ink'">{{ t.name }}</span>
                  <span class="text-[12.5px] text-muted">{{ t.posts }} {{ t.posts === 1 ? 'post' : 'posts' }}</span>
                </button>
              </li>
            </ul>
          </section>

          <section class="wm-card" aria-labelledby="jobs-heading">
            <div class="flex items-center justify-between">
              <h2 id="jobs-heading" class="text-[15px] font-semibold text-ink">Open jobs</h2>
              <router-link to="/find-work" class="wm-link text-sm">See all</router-link>
            </div>
            <ul v-if="openJobs.length" class="mt-2 flex flex-col divide-y divide-line/70">
              <li v-for="j in openJobs" :key="j.id">
                <router-link :to="`/job/${j.id}`" class="block py-2.5 hover:text-primary">
                  <span class="block text-[14px] font-medium text-ink line-clamp-2">{{ j.title }}</span>
                  <span class="block text-[12.5px] text-muted">{{ categoryLabel(j.category) }}<template v-if="j.area"> · {{ j.area }}</template></span>
                </router-link>
              </li>
            </ul>
            <p v-else class="mt-2 text-sm text-muted">No open jobs right now.</p>
          </section>

          <section class="wm-card" aria-labelledby="rules-heading">
            <h2 id="rules-heading" class="text-[15px] font-semibold text-ink">Keep it useful</h2>
            <ul class="mt-2 flex flex-col gap-2 text-[13.5px] text-muted">
              <li class="flex gap-2"><i class="bi bi-check2 text-primary" aria-hidden="true"></i>Share real work, honest advice and good questions.</li>
              <li class="flex gap-2"><i class="bi bi-check2 text-primary" aria-hidden="true"></i>Be respectful. No insults, spam or scams.</li>
              <li class="flex gap-2"><i class="bi bi-check2 text-primary" aria-hidden="true"></i>Keep phone numbers and payments inside WorkMate.</li>
            </ul>
            <p class="mt-3 text-[12.5px] text-muted">Posts are shared by members. Reviews on provider profiles come from customers who hired them through WorkMate.</p>
          </section>
        </div>
      </aside>
    </div>

    <!-- Phones: compose from anywhere on the page -->
    <button v-if="!saved" type="button" class="lg:hidden fixed right-4 z-30 inline-flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-[0_8px_24px_rgba(23,105,210,0.4)] bottom-[calc(var(--wm-nav-clearance)+12px)]"
      aria-label="Create a post" @click="compose()">
      <i class="bi bi-plus-lg text-2xl" aria-hidden="true"></i>
    </button>

    <CommentsSheet :post="commenting" @close="commenting = null" @report="reporting = { about: 'comment', commentId: $event.id }" />
    <PostComposer :open="composerOpen || !!editing" :post="editing" @close="composerOpen = false; editing = null" @published="published" @updated="updated" />
    <ReportSheet :open="!!reporting" :about="reporting?.about || 'post'" :post-id="reporting?.postId" :comment-id="reporting?.commentId" @close="reporting = null" />

  </div>
</template>

<script setup>
import { reactive, ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import UserAvatar from '@/components/UserAvatar.vue';
import EmptyState from '@/components/EmptyState.vue';
import PlacePicker from '@/components/PlacePicker.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import PostCard from '@/components/community/PostCard.vue';
import PostComposer from '@/components/community/PostComposer.vue';
import CommentsSheet from '@/components/community/CommentsSheet.vue';
import PersonRow from '@/components/community/PersonRow.vue';
import { api } from '@/api/client.js';
import { auth, isProvider } from '@/stores/auth.js';
import { categories, jobs, loadCatalog } from '@/stores/catalog.js';
import { categoryLabel } from '@/utils/ui.js';
import { POST_CATEGORIES, feedCache, needsSignIn, deletePost, showCommunityError, me, loadMe } from '@/stores/community.js';
import { showFlash } from '@/stores/flash.js';

const route = useRoute();
const router = useRouter();

const saved = computed(() => route.path === '/community/saved');
const filters = computed(() => ({ category: route.query.category || '', topic: route.query.topic || '', area: route.query.area || '' }));
const filtered = computed(() => !!(filters.value.category || filters.value.topic || filters.value.area));
// "For you" mixes the people a provider follows with their profession and area; for
// everyone else it would be the same as Following, so they get Following and Latest
const canPersonalise = computed(() => isProvider.value);
const tabs = computed(() => [
  ...(canPersonalise.value ? [{ id: 'for_you', label: 'For you' }] : []),
  ...(auth.user ? [{ id: 'following', label: 'Following' }] : []),
  { id: 'latest', label: 'Latest' },
]);
// No tabs on the page: everyone sees all posts, newest first ("Following" stays a link on wide screens)
const defaultTab = computed(() => 'latest');
const tab = computed(() => (tabs.value.some(t => t.id === route.query.tab) ? route.query.tab : defaultTab.value));

const sideLinks = computed(() => [
  { to: '/community', label: 'Feed', icon: 'bi-house', active: !saved.value && tab.value !== 'following' },
  ...(auth.user ? [{ to: '/community?tab=following', label: 'Following', icon: 'bi-people', active: !saved.value && tab.value === 'following' }] : []),
  { to: '/community/people', label: 'Discover people', icon: 'bi-person-plus', active: false },
  ...(auth.user ? [{ to: '/community/saved', label: 'Saved posts', icon: 'bi-bookmark', active: saved.value }] : []),
  ...(auth.user ? [{ to: `/community/people/${auth.user.id}`, label: 'My profile', icon: 'bi-person-circle', active: false }] : []),
]);
const phoneLinks = computed(() => [
  { to: '/community/people', label: 'Discover', icon: 'bi-person-plus' },
  ...(auth.user ? [
    { to: `/community/people/${auth.user.id}`, label: 'My profile', icon: 'bi-person-circle' },
    { to: '/community/saved', label: 'Saved', icon: 'bi-bookmark' },
  ] : []),
]);

/* People to follow and what's being talked about, both from real activity */
const suggested = ref([]);
const topics = ref([]);
async function loadSidebar() {
  try { suggested.value = (await api('/community/people/suggested')).data; } catch { /* optional */ }
  try { topics.value = (await api('/community/topics')).data; } catch { /* optional */ }
}

const openJobs = computed(() => jobs.filter(j => (!j.status || j.status === 'open') && !j.isSample).slice(0, 4));

function setQuery(change) {
  const query = { ...route.query, ...change };
  for (const k of Object.keys(query)) if (!query[k]) delete query[k];
  router.replace({ query });
}
function clearFilters() {
  router.replace({ query: route.query.tab ? { tab: route.query.tab } : {} });
}

/* ---------------- Loading, page by page ---------------- */
const state = reactive({ posts: [], cursor: null, page: 1, done: false, loading: false, error: '', personalised: false });
const feedKey = computed(() => JSON.stringify({ saved: saved.value, tab: tab.value, ...filters.value, user: auth.user?.id || null }));

let generation = 0;
async function load(fresh = false) {
  if (state.loading && !fresh) return;
  const mine = ++generation;
  if (fresh) Object.assign(state, { posts: [], cursor: null, page: 1, done: false, error: '' });
  state.loading = true;
  state.error = '';
  try {
    let r;
    if (saved.value) {
      r = await api(`/community/saved?page=${state.page}`);
    } else {
      const q = new URLSearchParams({ tab: tab.value });
      for (const [k, v] of Object.entries(filters.value)) if (v) q.set(k, v);
      if (state.cursor) q.set('cursor', state.cursor);
      r = await api(`/community/posts?${q}`);
    }
    if (mine !== generation) return;
    // The same post can't appear twice, even if new ones arrived in between
    const seen = new Set(state.posts.map(p => p.id));
    state.posts.push(...r.data.filter(p => !seen.has(p.id)));
    state.cursor = r.meta.next_cursor || null;
    state.page += 1;
    state.done = saved.value ? !r.meta.next_page : !r.meta.next_cursor;
    state.personalised = !!r.meta.personalised;
  } catch (e) {
    if (mine === generation) state.error = e.message;
  } finally {
    if (mine === generation) state.loading = false;
  }
}

const empty = computed(() => {
  if (saved.value) return { icon: 'bi-bookmark', title: 'No saved posts yet', message: 'Tap Save on a post to keep it here for later.', action: 'browse' };
  if (filtered.value) return { icon: 'bi-funnel', title: 'Nothing here yet', message: 'No posts match these filters. Try another kind of post, profession or place.', action: 'clear' };
  if (tab.value === 'for_you' && state.personalised) return { icon: 'bi-stars', title: 'Nothing from your field yet', message: 'Be the first to share your work, or see what everyone else is posting.', action: 'latest' };
  return { icon: 'bi-people', title: 'Start the conversation', message: 'Share a job you’re proud of, a tip that saves time, or a question for people who know.', action: 'compose' };
});

/* Come back to where you were after opening a post */
const sentinel = ref(null);
let observer = null;
function watchSentinel() {
  observer?.disconnect();
  if (!('IntersectionObserver' in window)) return;
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !state.done && !state.loading && !state.error) load();
  }, { rootMargin: '600px 0px' });
  if (sentinel.value) observer.observe(sentinel.value);
}

onMounted(async () => {
  loadCatalog().catch(() => {});
  loadSidebar();
  loadMe();
  if (feedCache.key === feedKey.value && Date.now() - feedCache.at < 10 * 60 * 1000 && feedCache.posts.length) {
    Object.assign(state, { posts: feedCache.posts, cursor: feedCache.cursor, done: feedCache.done, personalised: feedCache.personalised, page: feedCache.page || 2 });
    await nextTick();
    window.scrollTo(0, feedCache.scrollY);
  } else {
    await load(true);
  }
  await nextTick();
  watchSentinel();
});
onBeforeUnmount(() => {
  observer?.disconnect();
  Object.assign(feedCache, { key: feedKey.value, posts: state.posts, cursor: state.cursor, done: state.done, personalised: state.personalised, page: state.page, scrollY: window.scrollY, at: Date.now() });
});

watch(feedKey, async () => {
  await load(true);
  await nextTick();
  watchSentinel();
});
watch(() => state.posts.length, async () => { await nextTick(); if (sentinel.value && observer) observer.observe(sentinel.value); });

/* ---------------- Writing, editing, deleting, reporting ---------------- */
const composerOpen = ref(false);
// The post whose comments are open in the sheet
const commenting = ref(null);
const editing = ref(null);
const reporting = ref(null);

function compose() {
  if (needsSignIn()) return;
  composerOpen.value = true;
}

function published(post) {
  state.posts.unshift(post);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updated(post) {
  const i = state.posts.findIndex(p => p.id === post.id);
  if (i !== -1) Object.assign(state.posts[i], post);
}

async function remove(post) {
  if (!window.confirm('Delete this post? It can’t be undone.')) return;
  try {
    await deletePost(post);
    state.posts = state.posts.filter(p => p.id !== post.id);
    showFlash('Post deleted.');
  } catch (e) {
    showCommunityError(e);
  }
}

// Opening the composer from elsewhere (e.g. /community?compose=1)
if (route.query.compose) {
  const { compose: _drop, ...rest } = route.query;
  router.replace({ query: rest });
  if (auth.user) composerOpen.value = true;
}
</script>
