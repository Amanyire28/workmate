<template>
  <div>
    <!-- Filters: what is shown (live or removed) and which kind of post -->
    <div class="grid grid-cols-2 gap-2">
      <label class="block min-w-0">
        <span class="wm-meta mb-1 block px-1">Show</span>
        <select v-model="status" class="wm-input !min-h-11 w-full" :aria-label="`Show ${type === 'video' ? 'videos' : 'posts'} that are`">
          <option value="all">All</option>
          <option value="published">Live</option>
          <option value="removed">Removed by WorkMate</option>
        </select>
      </label>
      <label class="block min-w-0">
        <span class="wm-meta mb-1 block px-1">Topic</span>
        <select v-model="category" class="wm-input !min-h-11 w-full" aria-label="Topic">
          <option value="">All topics</option>
          <option v-for="c in POST_CATEGORIES" :key="c.id" :value="c.id">{{ c.label }}</option>
        </select>
      </label>
    </div>

    <!-- First load -->
    <div v-if="loading && !items.length" class="mt-3 flex flex-col gap-2" aria-busy="true" role="status">
      <span class="sr-only">Loading your {{ type === 'video' ? 'videos' : 'posts' }}</span>
      <div v-for="n in 2" :key="n" class="wm-card"><div class="wm-skeleton h-4 w-2/3"></div><div class="wm-skeleton mt-3 h-24 w-full"></div></div>
    </div>

    <p v-else-if="error && !items.length" class="wm-notice wm-notice-error mt-3" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ error }} <button type="button" class="wm-link" @click="load(true)">Try again</button></span>
    </p>

    <EmptyState v-else-if="!items.length && filtered" class="mt-3" icon="bi-funnel" title="Nothing matches these choices" message="Try a different filter to see more.">
      <button type="button" class="wm-btn wm-btn-secondary !min-h-12" @click="reset">Show everything</button>
    </EmptyState>

    <EmptyState v-else-if="!items.length" class="mt-3" :icon="type === 'video' ? 'bi-camera-video' : 'bi-chat-square-text'"
      :title="type === 'video' ? 'You haven’t shared a video yet' : 'You haven’t posted yet'"
      :message="type === 'video' ? 'Show a job you’re proud of, or how you do something. Short videos work best.' : 'Share a job you’re proud of or a tip that helps others.'">
      <router-link to="/community?compose=1" class="wm-btn wm-btn-primary !min-h-12">{{ type === 'video' ? 'Share a video' : 'Share your work' }}</router-link>
    </EmptyState>

    <template v-else>
      <ul class="mt-3 flex flex-col gap-2">
        <li v-for="p in items" :key="p.id">
          <PostCard :post="p" @edit="editing = $event" @delete="remove" />
        </li>
      </ul>
      <p v-if="error" class="wm-notice wm-notice-error mt-3" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ error }}</span>
      </p>
      <div class="mt-4 flex justify-center">
        <button v-if="cursor" type="button" class="wm-btn wm-btn-secondary !min-h-11" :disabled="loading" @click="load()">
          {{ loading ? 'Loading…' : (type === 'video' ? 'Show more videos' : 'Show more posts') }}
        </button>
        <p v-else class="wm-meta py-2">That’s all of them.</p>
      </div>
    </template>

    <PostComposer :open="!!editing" :post="editing" @close="editing = null" @updated="updated" />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PostCard from '@/components/community/PostCard.vue';
import PostComposer from '@/components/community/PostComposer.vue';
import { api } from '@/api/client.js';
import { POST_CATEGORIES, deletePost, showCommunityError } from '@/stores/community.js';
import { forgetStats } from '@/stores/communityStats.js';
import { showFlash } from '@/stores/flash.js';

const props = defineProps({
  // 'all': every post of the member (videos included); 'video': only the posts that have a video
  type: { type: String, default: 'all' },
});

const items = ref([]);
const cursor = ref(null);
const loading = ref(false);
const error = ref('');
const editing = ref(null);
const status = ref('all');
const category = ref('');

const filtered = computed(() => status.value !== 'all' || category.value !== '');

// Only the newest answer counts: a slow earlier page must not overwrite a newer filter's list
let ticket = 0;

async function load(fresh = false) {
  const mine = ++ticket;
  if (fresh) { items.value = []; cursor.value = null; }
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ type: props.type, status: status.value });
    if (category.value) q.set('category', category.value);
    if (!fresh && cursor.value) q.set('cursor', cursor.value);
    const r = await api(`/me/community/posts?${q}`);
    if (mine !== ticket) return;
    items.value.push(...r.data);
    cursor.value = r.meta.next_cursor;
  } catch (e) {
    if (mine === ticket) error.value = e.message || 'We couldn’t load your posts.';
  } finally {
    if (mine === ticket) loading.value = false;
  }
}

function reset() {
  status.value = 'all';
  category.value = '';
}

watch([status, category, () => props.type], () => load(true), { immediate: true });

function updated(p) {
  const found = items.value.find(x => x.id === p.id);
  if (found) Object.assign(found, p);
}

async function remove(p) {
  if (!window.confirm('Delete this post? It can’t be undone.')) return;
  try {
    await deletePost(p);
    items.value = items.value.filter(x => x.id !== p.id);
    forgetStats();
    showFlash('Post deleted.');
  } catch (e) {
    showCommunityError(e);
  }
}
</script>
