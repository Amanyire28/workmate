<template>
  <article class="wm-card !p-0 overflow-hidden" :aria-labelledby="`${uid}-author`">
    <!-- Removed by WorkMate: only the author sees this -->
    <p v-if="post.removed" class="wm-notice wm-notice-warning !rounded-none !m-0">
      <i class="bi bi-eye-slash" aria-hidden="true"></i>
      <span><strong class="font-semibold">WorkMate removed this post.</strong> Only you can see it. Reason: {{ post.removed.reason }}</span>
    </p>

    <header class="flex items-start gap-3 px-4 pt-4 lg:px-5">
      <component :is="profileLink ? 'router-link' : 'span'" :to="profileLink || undefined" class="shrink-0" :tabindex="profileLink ? -1 : undefined" aria-hidden="true">
        <UserAvatar :name="post.author.name" :src="post.author.avatar_url || ''" :size="44" />
      </component>
      <div class="min-w-0 flex-1">
        <p :id="`${uid}-author`" class="flex items-center gap-1.5 text-[15px] font-semibold text-ink leading-tight">
          <router-link v-if="profileLink" :to="profileLink" class="truncate hover:underline">{{ post.author.name }}</router-link>
          <span v-else class="truncate">{{ post.author.name }}</span>
          <VerifiedBadge :provider="{ documentVerified: post.author.document_verified, blueTick: post.author.blue_tick }" />
        </p>
        <p class="mt-0.5 text-[13px] text-muted truncate">
          <span class="sm:hidden"><i :class="['bi', category.icon]" aria-hidden="true"></i> {{ category.label }} · </span>
          <template v-if="post.author.title">{{ post.author.title }} · </template>
          <template v-if="post.area">{{ post.area }} · </template>
          <router-link :to="`/community/post/${post.id}`" class="hover:underline"><time :datetime="post.created_at">{{ agoLabel(post.created_at) }}</time></router-link>
          <template v-if="post.edited_at"> · edited</template>
        </p>
      </div>
      <span class="hidden sm:inline-flex wm-pill wm-pill-plain wm-pill-neutral !h-7 shrink-0 mt-0.5">
        <i :class="['bi', category.icon]" aria-hidden="true"></i>{{ category.label }}
      </span>
      <div class="relative -mr-2 -mt-1.5">
        <button type="button" class="wm-icon-btn" :aria-expanded="menuOpen" :aria-controls="`${uid}-menu`" aria-label="More options" @click="menuOpen = !menuOpen">
          <i class="bi bi-three-dots" aria-hidden="true"></i>
        </button>
        <div v-if="menuOpen" class="fixed inset-0 z-30" aria-hidden="true" @click="menuOpen = false"></div>
        <ul v-if="menuOpen" :id="`${uid}-menu`" class="absolute right-0 top-11 z-40 w-52 rounded-2xl bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.16)] ring-1 ring-black/5" @keydown.esc="menuOpen = false">
          <li v-if="post.mine && !post.removed"><button type="button" class="wm-menu-item" @click="act('edit')"><i class="bi bi-pencil" aria-hidden="true"></i>Edit post</button></li>
          <li v-if="post.mine"><button type="button" class="wm-menu-item text-error" @click="act('delete')"><i class="bi bi-trash" aria-hidden="true"></i>Delete post</button></li>
          <li v-if="!post.mine && !post.removed"><button type="button" class="wm-menu-item" @click="act('follow')"><i :class="['bi', post.author.following ? 'bi-person-dash' : 'bi-person-plus']" aria-hidden="true"></i>{{ post.author.following ? 'Unfollow' : 'Follow' }} {{ post.author.name }}</button></li>
          <li v-if="!post.removed"><button type="button" class="wm-menu-item" @click="act('copy')"><i class="bi bi-link-45deg" aria-hidden="true"></i>Copy link</button></li>
          <li v-if="!post.mine"><button type="button" class="wm-menu-item" @click="act('report')"><i class="bi bi-flag" aria-hidden="true"></i>Report post</button></li>
        </ul>
      </div>
    </header>

    <!-- Words: long posts open with "Read more" -->
    <div v-if="post.body" class="px-4 pt-3 lg:px-5">
      <p :id="`${uid}-body`" class="text-[15px] leading-relaxed text-ink whitespace-pre-line break-words" :class="{ 'line-clamp-6': clamped }">{{ post.body }}</p>
      <button v-if="long && !full" type="button" class="wm-link mt-1 text-sm" :aria-expanded="!clamped" :aria-controls="`${uid}-body`" @click="expanded = !expanded">
        {{ clamped ? 'Read more' : 'Show less' }}
      </button>
    </div>

    <!-- Photos -->
    <div v-if="post.media.length" class="mt-3" :class="post.media.length > 1 ? 'grid grid-cols-2 gap-0.5' : ''">
      <button
        v-for="(m, i) in shownMedia"
        :key="m.id"
        type="button"
        class="relative block w-full overflow-hidden bg-[#E2E8F0] focus-visible:outline-offset-[-3px]"
        :class="tileClass(i)"
        :style="post.media.length === 1 ? { aspectRatio: singleRatio } : null"
        :aria-label="`Open photo ${i + 1} of ${post.media.length}`"
        @click="viewer = i"
      >
        <img :src="m.thumb_url" :width="m.width" :height="m.height" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover" />
        <span v-if="i === shownMedia.length - 1 && hiddenCount" class="absolute inset-0 flex items-center justify-center bg-ink/50 text-2xl font-semibold text-white">+{{ hiddenCount }}</span>
      </button>
    </div>

    <!-- A provider's own work: their profile is a tap away -->
    <div v-if="post.author.provider_id && !post.mine && post.category === 'my_work'" class="mx-4 mt-3 flex items-center justify-between gap-3 rounded-2xl bg-primary-tint/70 px-3.5 py-2.5 lg:mx-5">
      <p class="min-w-0 text-[13px] text-ink truncate">Like this work?<span class="hidden sm:inline text-muted"> See their profile and reviews.</span></p>
      <router-link :to="profileLink" class="wm-btn wm-btn-secondary !min-h-9 !px-3.5 !text-[13px] shrink-0">View profile</router-link>
    </div>

    <!-- Counts -->
    <p v-if="post.likes_count || post.comments_count" class="flex items-center justify-between px-4 pt-3 text-[13px] text-muted lg:px-5">
      <span>
        <template v-if="post.likes_count"><i class="bi bi-hand-thumbs-up-fill text-primary" aria-hidden="true"></i> {{ post.likes_count }} {{ post.likes_count === 1 ? 'like' : 'likes' }}</template>
      </span>
      <template v-if="post.comments_count">
        <button v-if="sheetComments" type="button" class="min-h-8 hover:underline" @click="onComment">{{ post.comments_count }} {{ post.comments_count === 1 ? 'comment' : 'comments' }}</button>
        <router-link v-else :to="`/community/post/${post.id}#comments`" class="hover:underline">{{ post.comments_count }} {{ post.comments_count === 1 ? 'comment' : 'comments' }}</router-link>
      </template>
    </p>

    <!-- Actions -->
    <div v-if="!post.removed" class="mt-2 grid grid-cols-4 border-t border-line/70 px-1.5 py-1">
      <button type="button" class="wm-post-action" :class="{ 'text-primary': post.liked }" :aria-pressed="post.liked" @click="toggleLike(post)">
        <i :class="['bi', post.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up']" aria-hidden="true"></i><span>Like</span>
      </button>
      <button v-if="sheetComments" type="button" class="wm-post-action" @click="onComment">
        <i class="bi bi-chat" aria-hidden="true"></i><span>Comment</span>
      </button>
      <router-link v-else :to="`/community/post/${post.id}#comments`" class="wm-post-action" @click="onComment">
        <i class="bi bi-chat" aria-hidden="true"></i><span>Comment</span>
      </router-link>
      <button type="button" class="wm-post-action" @click="sharePost(post)">
        <i class="bi bi-share" aria-hidden="true"></i><span>Share</span>
      </button>
      <button type="button" class="wm-post-action" :class="{ 'text-primary': post.saved }" :aria-pressed="post.saved" @click="toggleSave(post)">
        <i :class="['bi', post.saved ? 'bi-bookmark-fill' : 'bi-bookmark']" aria-hidden="true"></i><span>{{ post.saved ? 'Saved' : 'Save' }}</span>
      </button>
    </div>
    <div v-else class="h-4"></div>

    <PhotoViewer v-if="viewer !== null" :media="post.media" :start="viewer" :label="`Photos by ${post.author.name}`" @close="viewer = null" />
  </article>
</template>

<script setup>
import { ref, computed, useId } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import PhotoViewer from './PhotoViewer.vue';
import { agoLabel } from '@/stores/catalog.js';
import { categoryOf, toggleLike, toggleSave, toggleFollow, sharePost, postUrl, needsSignIn, personLink } from '@/stores/community.js';
import { showFlash } from '@/stores/flash.js';

const props = defineProps({
  post: { type: Object, required: true },
  // On the post's own page: the whole text, no "Read more"
  full: { type: Boolean, default: false },
  // In the feed: comments open in a sheet over the page
  sheetComments: { type: Boolean, default: false },
});
const emit = defineEmits(['edit', 'delete', 'report', 'comment']);

const uid = useId();
const category = computed(() => categoryOf(props.post.category));
// A listed provider's profile, otherwise their community page
const profileLink = computed(() => personLink(props.post.author));

const long = computed(() => {
  const body = props.post.body || '';
  return body.length > 360 || body.split('\n').length > 6;
});
const expanded = ref(false);
const clamped = computed(() => long.value && !props.full && !expanded.value);

/* Photo layout: one keeps its shape (within limits), more go in a grid of up to four */
const shownMedia = computed(() => props.post.media.slice(0, 4));
const hiddenCount = computed(() => Math.max(0, props.post.media.length - 4));
const singleRatio = computed(() => {
  const m = props.post.media[0];
  const ratio = m.width / m.height;
  return String(Math.min(16 / 9, Math.max(4 / 5, ratio)));
});
function tileClass(i) {
  const n = props.post.media.length;
  if (n === 1) return 'max-h-[560px]';
  if (n === 3 && i === 0) return 'row-span-2 h-full';
  return 'aspect-square';
}
const viewer = ref(null);

const menuOpen = ref(false);
async function act(what) {
  menuOpen.value = false;
  if (what === 'copy') {
    try {
      await navigator.clipboard.writeText(postUrl(props.post));
      showFlash('Link copied.');
    } catch {
      sharePost(props.post);
    }
    return;
  }
  if (what === 'follow') {
    toggleFollow(props.post.author);
    return;
  }
  if (what === 'report' && needsSignIn()) return;
  emit(what, props.post);
}

function onComment() {
  emit('comment', props.post);
}
</script>
