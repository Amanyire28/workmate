<template>
  <div class="wm-container wm-page">
    <div class="mx-auto w-full max-w-[640px]">
      <BackLink to="/community">Community</BackLink>

      <div v-if="loading" class="wm-card mt-3 flex items-center gap-4" aria-busy="true">
        <div class="wm-skeleton h-[72px] w-[72px] !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-5 w-44"></div><div class="wm-skeleton mt-2 h-4 w-28"></div></div>
      </div>

      <EmptyState v-else-if="missing" class="mt-3" icon="bi-person-x" title="This person isn’t in the community" message="They may not have shared anything yet, or their account is no longer active.">
        <router-link to="/community/people" class="wm-btn wm-btn-primary !min-h-12">Discover people</router-link>
      </EmptyState>

      <EmptyState v-else-if="error" class="mt-3" tone="error" icon="bi-wifi-off" title="This page couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load">Try again</button>
      </EmptyState>

      <template v-else-if="person">
        <!-- Who they are -->
        <section class="wm-card mt-3" aria-labelledby="person-name">
          <div class="flex items-start gap-4">
            <UserAvatar :name="person.name" :src="person.avatar_url || ''" :size="72" />
            <div class="min-w-0 flex-1">
              <h1 id="person-name" class="flex items-center gap-2 text-[22px] font-bold leading-tight text-ink">
                <span class="truncate">{{ person.name }}</span>
                <VerifiedBadge :provider="{ documentVerified: person.document_verified, blueTick: person.blue_tick }" />
              </h1>
              <p v-if="person.title || person.area" class="mt-1 text-[14px] text-muted">{{ [person.title, person.area].filter(Boolean).join(' · ') }}</p>
              <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[14px]">
                <span><strong class="font-semibold text-ink">{{ person.posts_count }}</strong> <span class="text-muted">{{ person.posts_count === 1 ? 'post' : 'posts' }}</span></span>
                <button type="button" class="hover:underline" @click="openList('followers')"><strong class="font-semibold text-ink">{{ person.followers_count }}</strong> <span class="text-muted">{{ person.followers_count === 1 ? 'follower' : 'followers' }}</span></button>
                <button type="button" class="hover:underline" @click="openList('following')"><strong class="font-semibold text-ink">{{ person.following_count }}</strong> <span class="text-muted">following</span></button>
              </p>
            </div>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="!person.me" type="button" class="wm-btn flex-1 sm:flex-none !min-h-11" :class="person.following ? 'wm-btn-secondary' : 'wm-btn-primary'" :aria-pressed="person.following" @click="toggleFollow(person)">
              <i :class="['bi', person.following ? 'bi-check2' : 'bi-person-plus']" aria-hidden="true"></i>{{ person.following ? 'Following' : 'Follow' }}
            </button>
            <router-link v-if="person.provider_id" :to="`/provider/${person.provider_id}`" class="wm-btn wm-btn-secondary flex-1 sm:flex-none !min-h-11">
              <i class="bi bi-person-badge" aria-hidden="true"></i>{{ person.me ? 'My provider profile' : 'Provider profile and reviews' }}
            </router-link>
            <router-link v-if="person.me" to="/account" class="wm-btn wm-btn-secondary flex-1 sm:flex-none !min-h-11"><i class="bi bi-gear" aria-hidden="true"></i>Account</router-link>
          </div>
        </section>

        <!-- What they've shared -->
        <h2 class="mt-6 text-[17px] font-semibold text-ink">{{ person.me ? 'Your posts' : 'Posts' }}</h2>
        <p class="wm-meta mt-0.5">Shared by {{ person.me ? 'you' : person.name }}.<template v-if="person.provider_id"> Customer reviews are on the provider profile.</template></p>

        <div v-if="posts.loading && !posts.items.length" class="wm-card mt-3" aria-busy="true"><div class="wm-skeleton h-4 w-full"></div><div class="wm-skeleton mt-2 h-4 w-2/3"></div></div>
        <p v-else-if="posts.error && !posts.items.length" class="wm-notice wm-notice-error mt-3" role="alert">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ posts.error }} <button type="button" class="wm-link" @click="loadPosts(true)">Try again</button></span>
        </p>
        <EmptyState v-else-if="!posts.items.length" class="mt-2" icon="bi-chat-square-text" :title="person.me ? 'You haven’t posted yet' : 'Nothing shared yet'"
          :message="person.me ? 'Share a job you’re proud of or a tip that helps others.' : 'When they share something, it’ll appear here.'">
          <router-link v-if="person.me" to="/community?compose=1" class="wm-btn wm-btn-primary !min-h-12">Share your work</router-link>
        </EmptyState>
        <template v-else>
          <ul class="mt-3 flex flex-col gap-4">
            <li v-for="p in posts.items" :key="p.id">
              <PostCard :post="p" @edit="editing = $event" @delete="remove" @report="reporting = { about: 'post', postId: $event.id }" />
            </li>
          </ul>
          <div class="mt-4 flex justify-center">
            <button v-if="posts.cursor" type="button" class="wm-btn wm-btn-secondary !min-h-11" :disabled="posts.loading" @click="loadPosts()">{{ posts.loading ? 'Loading…' : 'Show more posts' }}</button>
          </div>
        </template>
      </template>
    </div>

    <!-- Followers and following -->
    <BottomSheet :open="!!list.kind" :title="list.kind === 'followers' ? 'Followers' : 'Following'" @close="list.kind = ''">
      <p v-if="list.loading && !list.items.length" class="py-6 text-center wm-meta">Loading…</p>
      <p v-else-if="list.error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ list.error }}</span></p>
      <p v-else-if="!list.items.length" class="py-6 text-center text-sm text-muted">{{ list.kind === 'followers' ? 'No followers yet.' : 'Not following anyone yet.' }}</p>
      <ul v-else class="flex flex-col gap-3">
        <li v-for="p in list.items" :key="p.id" @click.capture="maybeClose($event)"><PersonRow :person="p" compact /></li>
      </ul>
      <button v-if="list.next" type="button" class="wm-btn wm-btn-ghost mt-3 !min-h-10" :disabled="list.loading" @click="loadList()">Show more</button>
    </BottomSheet>

    <PostComposer :open="!!editing" :post="editing" @close="editing = null" @updated="updated" />
    <ReportSheet :open="!!reporting" :about="reporting?.about || 'post'" :post-id="reporting?.postId" @close="reporting = null" />
  </div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import { useRoute } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import PostCard from '@/components/community/PostCard.vue';
import PostComposer from '@/components/community/PostComposer.vue';
import PersonRow from '@/components/community/PersonRow.vue';
import { api } from '@/api/client.js';
import { toggleFollow, deletePost, showCommunityError } from '@/stores/community.js';
import { showFlash } from '@/stores/flash.js';

const route = useRoute();
const person = ref(null);
const loading = ref(true);
const missing = ref(false);
const error = ref('');
const editing = ref(null);
const reporting = ref(null);

async function load() {
  loading.value = true;
  missing.value = false;
  error.value = '';
  try {
    person.value = (await api(`/community/people/${route.params.id}`)).data;
    document.title = `${person.value.name} – WorkMate community`;
    loadPosts(true);
  } catch (e) {
    if (e.status === 404) missing.value = true;
    else error.value = e.message;
  } finally {
    loading.value = false;
  }
}
watch(() => route.params.id, (id) => { if (id) load(); }, { immediate: true });

/* Their posts, page by page */
const posts = reactive({ items: [], cursor: null, loading: false, error: '' });
async function loadPosts(fresh = false) {
  if (fresh) Object.assign(posts, { items: [], cursor: null });
  posts.loading = true;
  posts.error = '';
  try {
    const q = new URLSearchParams({ tab: 'latest', author: route.params.id });
    if (posts.cursor) q.set('cursor', posts.cursor);
    const r = await api(`/community/posts?${q}`);
    posts.items.push(...r.data);
    posts.cursor = r.meta.next_cursor;
  } catch (e) {
    posts.error = e.message;
  } finally {
    posts.loading = false;
  }
}

function updated(p) {
  const found = posts.items.find(x => x.id === p.id);
  if (found) Object.assign(found, p);
}

async function remove(p) {
  if (!window.confirm('Delete this post? It can’t be undone.')) return;
  try {
    await deletePost(p);
    posts.items = posts.items.filter(x => x.id !== p.id);
    person.value.posts_count = Math.max(0, person.value.posts_count - 1);
    showFlash('Post deleted.');
  } catch (e) {
    showCommunityError(e);
  }
}

/* Followers and following */
const list = reactive({ kind: '', items: [], next: null, loading: false, error: '' });
function openList(kind) {
  Object.assign(list, { kind, items: [], next: 1, error: '' });
  loadList();
}
async function loadList() {
  list.loading = true;
  try {
    const r = await api(`/community/people/${route.params.id}/${list.kind}?page=${list.next}`);
    list.items.push(...r.data);
    list.next = r.meta.next_page;
  } catch (e) {
    list.error = e.message;
  } finally {
    list.loading = false;
  }
}
// Following a link in the list leaves the sheet
function maybeClose(e) {
  if (e.target.closest('a')) list.kind = '';
}
</script>
