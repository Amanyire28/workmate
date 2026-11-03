<template>
  <div class="wm-container wm-page">
    <div class="mx-auto w-full max-w-[640px]">
      <BackLink to="/community">Community</BackLink>

      <div v-if="loading" class="wm-card mt-3" aria-busy="true">
        <div class="flex items-center gap-3"><div class="wm-skeleton h-11 w-11 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-40"></div><div class="wm-skeleton mt-2 h-3 w-24"></div></div></div>
        <div class="wm-skeleton mt-4 h-4 w-full"></div><div class="wm-skeleton mt-2 h-4 w-3/4"></div>
      </div>

      <EmptyState v-else-if="missing" class="mt-3" icon="bi-file-earmark-x" title="This post isn’t available" message="It may have been deleted by its author or removed by WorkMate.">
        <router-link to="/community" class="wm-btn wm-btn-primary !min-h-12">Back to the community</router-link>
      </EmptyState>

      <EmptyState v-else-if="error" class="mt-3" tone="error" icon="bi-wifi-off" title="This post couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load">Try again</button>
      </EmptyState>

      <template v-else-if="post">
        <PostCard class="mt-3" :post="post" full @edit="editing = $event" @delete="remove" @report="reporting = { about: 'post', postId: $event.id }" @comment="focusComment" />
        <CommentThread v-if="!post.removed" class="mt-4" :post="post" @report="reporting = { about: 'comment', commentId: $event.id }" />
      </template>
    </div>

    <PostComposer :open="!!editing" :post="editing" @close="editing = null" @updated="p => Object.assign(post, p)" />
    <ReportSheet :open="!!reporting" :about="reporting?.about || 'post'" :post-id="reporting?.postId" :comment-id="reporting?.commentId" @close="reporting = null" />
  </div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import PostCard from '@/components/community/PostCard.vue';
import PostComposer from '@/components/community/PostComposer.vue';
import CommentThread from '@/components/community/CommentThread.vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { cachedPost, deletePost, showCommunityError } from '@/stores/community.js';
import { showFlash } from '@/stores/flash.js';

const route = useRoute();
const router = useRouter();

const post = ref(null);
const loading = ref(true);
const missing = ref(false);
const error = ref('');
const editing = ref(null);
const reporting = ref(null);

async function load() {
  const id = route.params.id;
  // Shown at once when it came from the feed; the server's copy follows
  const known = cachedPost(id);
  post.value = known;
  loading.value = !known;
  missing.value = false;
  error.value = '';
  try {
    const fresh = (await api(`/community/posts/${id}`)).data;
    if (known) Object.assign(known, fresh);
    else post.value = fresh;
    document.title = `${post.value.author.name} on the WorkMate community`;
  } catch (e) {
    if (e.status === 404) missing.value = true;
    else if (!known) error.value = e.message;
  } finally {
    loading.value = false;
  }
  if (route.hash === '#comments') focusComment();
}
watch(() => route.params.id, (id) => { if (id) load(); }, { immediate: true });

async function focusComment() {
  await nextTick();
  document.getElementById('comments')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  // Signed-out visitors are asked to sign in only when they choose to comment
  if (auth.user) setTimeout(() => document.getElementById('comment-new')?.focus({ preventScroll: true }), 350);
}

async function remove(p) {
  if (!window.confirm('Delete this post? It can’t be undone.')) return;
  try {
    await deletePost(p);
    showFlash('Post deleted.');
    router.replace('/community');
  } catch (e) {
    showCommunityError(e);
  }
}
</script>
