<template>
  <!-- One compact layout for every post (text, photos, video): header, words, media,
       actions. Full width on phones, a lightly bordered block on wider screens. -->
  <article class="wm-feed-post" :aria-labelledby="`${uid}-author`">
    <!-- Removed by WorkMate: only the author sees this -->
    <p v-if="post.removed" class="wm-notice wm-notice-warning !rounded-none !m-0">
      <i class="bi bi-eye-slash" aria-hidden="true"></i>
      <span><strong class="font-semibold">WorkMate removed this post.</strong> Only you can see it. Reason: {{ post.removed.reason }}</span>
    </p>

    <header class="flex items-start gap-2.5 px-4 pt-3">
      <component :is="profileLink ? 'router-link' : 'span'" :to="profileLink || undefined" class="shrink-0" :tabindex="profileLink ? -1 : undefined" aria-hidden="true">
        <UserAvatar :name="post.author.name" :src="post.author.avatar_url || ''" :size="40" />
      </component>
      <div class="min-w-0 flex-1">
        <p :id="`${uid}-author`" class="flex items-center gap-1.5 text-[15px] font-semibold text-ink leading-tight">
          <router-link v-if="profileLink" :to="profileLink" class="truncate hover:underline">{{ post.author.name }}</router-link>
          <span v-else class="truncate">{{ post.author.name }}</span>
          <VerifiedBadge :provider="{ documentVerified: post.author.document_verified, blueTick: post.author.blue_tick }" />
        </p>
        <p class="mt-0.5 text-[12.5px] text-muted truncate">
          <template v-if="post.author.title">{{ post.author.title }} · </template>
          <template v-if="post.area">{{ post.area }} · </template>
          <router-link :to="`/community/post/${post.id}`" class="hover:underline"><time :datetime="post.created_at">{{ agoLabel(post.created_at) }}</time></router-link>
          <template v-if="post.edited_at"> · edited</template>
        </p>
        <p class="mt-0.5 text-[12px] text-muted"><i :class="['bi', category.icon]" aria-hidden="true"></i> {{ category.label }}</p>
      </div>
      <div class="relative -mr-2 -mt-1">
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

    <!-- Words: a few lines first (3 with a photo or video, 6 on their own), "See more" in place -->
    <div v-if="post.body" class="px-4 pt-2">
      <p
        :id="`${uid}-body`"
        ref="bodyEl"
        class="text-[15px] leading-[1.45] text-ink whitespace-pre-line break-words"
        :class="clamped ? (hasMedia ? 'line-clamp-3' : 'line-clamp-6') : ''"
      >{{ post.body }}</p>
      <button v-if="!full && (overflowing || expanded)" type="button" class="wm-see-more" :aria-expanded="expanded" :aria-controls="`${uid}-body`" @click="expanded = !expanded">
        {{ expanded ? 'See less' : 'See more' }}
      </button>
    </div>

    <!-- A video (one per post) -->
    <div v-if="video" class="mt-2.5">
      <VideoPlayer :media="video" :label="`Video by ${post.author.name}`" :opens-viewer="videoViewer" @open="$emit('open-video', post, $event)" />
    </div>

    <!-- One photo keeps its whole shape (blurred copy behind); more go in a grid of up to four -->
    <div v-else-if="photos.length" class="mt-2.5" :class="photos.length > 1 ? 'grid grid-cols-2 gap-0.5' : ''">
      <button
        v-for="(m, i) in shownMedia"
        :key="m.id"
        type="button"
        class="relative block w-full overflow-hidden bg-[#E2E8F0] focus-visible:outline-offset-[-3px]"
        :class="tileClass(i)"
        :style="photos.length === 1 ? { aspectRatio: singleRatio } : null"
        :aria-label="`Open photo ${i + 1} of ${photos.length}`"
        @click="viewer = i"
      >
        <img v-if="photos.length === 1" :src="m.thumb_url" alt="" aria-hidden="true" class="wm-media-backdrop" loading="lazy" decoding="async" />
        <img :src="m.thumb_url" :width="m.width" :height="m.height" alt="" loading="lazy" decoding="async" class="relative h-full w-full" :class="photos.length === 1 ? 'object-contain' : 'object-cover'" />
        <span v-if="i === shownMedia.length - 1 && hiddenCount" class="absolute inset-0 flex items-center justify-center bg-ink/50 text-2xl font-semibold text-white">+{{ hiddenCount }}</span>
      </button>
    </div>

    <p v-if="VIDEO_MOCK && post.mock" class="wm-notice wm-notice-warning mx-4 mt-2.5 !text-[13px]">
      <i class="bi bi-cone-striped" aria-hidden="true"></i><span>Development preview: this post isn’t saved, and liking or commenting on it won’t work.</span>
    </p>

    <!-- A provider's own work: their profile, or a request, a tap away (kept low-key) -->
    <div v-if="showProviderActions" class="flex items-center justify-between gap-2 px-4 pt-2">
      <router-link :to="profileLink" class="inline-flex min-h-10 min-w-0 items-center text-[13px] font-semibold text-primary hover:underline">
        <span class="truncate">View profile and reviews</span>
      </router-link>
      <router-link :to="`/provider/${post.author.provider_id}/request`" class="wm-btn wm-btn-secondary !min-h-10 !px-3.5 !text-[13px] shrink-0">
        <i class="bi bi-calendar-plus" aria-hidden="true"></i>Request service
      </router-link>
    </div>

    <!-- Actions: the like and comment icons carry their counts -->
    <div v-if="!post.removed" class="mt-2 grid grid-cols-4 border-t border-line/60 px-1 py-0.5">
      <button type="button" class="wm-post-action" :class="{ 'text-primary': post.liked }" :aria-pressed="post.liked" :aria-label="`Like. ${countLabel(post.likes_count, 'like')}`" @click="toggleLike(post)">
        <i :class="['bi', post.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up']" aria-hidden="true"></i><span v-if="post.likes_count" class="tabular-nums">{{ shortCount(post.likes_count) }}</span>
      </button>
      <button v-if="sheetComments" type="button" class="wm-post-action" :aria-label="`Comments. ${countLabel(post.comments_count, 'comment')}`" @click="onComment">
        <i class="bi bi-chat" aria-hidden="true"></i><span v-if="post.comments_count" class="tabular-nums">{{ shortCount(post.comments_count) }}</span>
      </button>
      <router-link v-else :to="`/community/post/${post.id}#comments`" class="wm-post-action" :aria-label="`Comments. ${countLabel(post.comments_count, 'comment')}`" @click="onComment">
        <i class="bi bi-chat" aria-hidden="true"></i><span v-if="post.comments_count" class="tabular-nums">{{ shortCount(post.comments_count) }}</span>
      </router-link>
      <button type="button" class="wm-post-action" @click="sharePost(post)">
        <i class="bi bi-share" aria-hidden="true"></i><span>Share</span>
      </button>
      <button type="button" class="wm-post-action" :class="{ 'text-primary': post.saved }" :aria-pressed="post.saved" @click="toggleSave(post)">
        <i :class="['bi', post.saved ? 'bi-bookmark-fill' : 'bi-bookmark']" aria-hidden="true"></i><span>{{ post.saved ? 'Saved' : 'Save' }}</span>
      </button>
    </div>
    <div v-else class="h-3"></div>

    <PhotoViewer v-if="viewer !== null" :media="photos" :start="viewer" :label="`Photos by ${post.author.name}`" @close="viewer = null" />
  </article>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount, useId } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import PhotoViewer from './PhotoViewer.vue';
import VideoPlayer from './VideoPlayer.vue';
import { VIDEO_MOCK } from '@/stores/video.js';
import { agoLabel } from '@/stores/catalog.js';
import { categoryOf, toggleLike, toggleSave, toggleFollow, sharePost, postUrl, needsSignIn, personLink, shortCount, countLabel } from '@/stores/community.js';
import { showFlash } from '@/stores/flash.js';

const props = defineProps({
  post: { type: Object, required: true },
  // On the post's own page: the whole text, no "Read more"
  full: { type: Boolean, default: false },
  // In the feed: comments open in a sheet over the page
  sheetComments: { type: Boolean, default: false },
  // In the feed: tapping the video opens the full-screen viewer
  videoViewer: { type: Boolean, default: false },
});
const emit = defineEmits(['edit', 'delete', 'report', 'comment', 'open-video']);

const uid = useId();
const category = computed(() => categoryOf(props.post.category));
// A listed provider's profile, otherwise their community page
const profileLink = computed(() => personLink(props.post.author));

/* A post has one video or up to six photos (media without a type are photos) */
const video = computed(() => props.post.media.find(m => m.type === 'video') || null);
const photos = computed(() => props.post.media.filter(m => m.type !== 'video'));
const hasMedia = computed(() => !!video.value || photos.value.length > 0);

/* The words: shortened until "See more"; whether they're cut off is measured, not guessed */
const bodyEl = ref(null);
const expanded = ref(false);
const overflowing = ref(false);
const clamped = computed(() => !props.full && !expanded.value);
function measure() {
  const el = bodyEl.value;
  if (!el || !clamped.value) return;
  overflowing.value = el.scrollHeight > el.clientHeight + 2;
}
let resizing = null;
onMounted(() => {
  measure();
  if (typeof ResizeObserver === 'function' && bodyEl.value) {
    resizing = new ResizeObserver(measure);
    resizing.observe(bodyEl.value);
  }
});
onBeforeUnmount(() => resizing?.disconnect());
watch(() => props.post.body, () => { expanded.value = false; nextTick(measure); });

/* A listed provider's own work (or video): see them, or ask for the service. Not on
   tips, questions and the like, so service buttons never take over a conversation. */
const showProviderActions = computed(() => !!props.post.author.provider_id && !props.post.mine && !props.post.removed
  && (props.post.category === 'my_work' || !!video.value));

/* Photo layout: one keeps its shape (within limits), more go in a grid of up to four */
const shownMedia = computed(() => photos.value.slice(0, 4));
const hiddenCount = computed(() => Math.max(0, photos.value.length - 4));
const singleRatio = computed(() => {
  const m = photos.value[0];
  const ratio = m.width / m.height;
  return String(Math.min(16 / 9, Math.max(4 / 5, ratio)));
});
function tileClass(i) {
  const n = photos.value.length;
  if (n === 1) return 'max-h-[min(70vh,560px)]';
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
