<template>
  <Teleport to="body">
    <div
      ref="root"
      class="fixed inset-0 z-[80] bg-black text-white"
      role="dialog"
      aria-modal="true"
      aria-label="Community videos"
      tabindex="-1"
      @keydown="onKey"
    >
      <!-- Swipe up and down; only the previous, current and next videos exist on the page -->
      <div
        ref="stage"
        class="wm-viewer-stage relative mx-auto h-full w-full max-w-[560px] overflow-hidden"
        @touchstart.passive="touchStart"
        @touchmove="touchMove"
        @touchend="touchEnd"
        @touchcancel="touchEnd"
        @wheel.prevent="onWheel"
      >
        <section
          v-for="slot in slots"
          :key="slot.post.id"
          class="absolute inset-0"
          :style="slideStyle(slot.offset)"
          :aria-hidden="slot.offset !== 0 ? 'true' : undefined"
          :inert="slot.offset !== 0 ? true : undefined"
        >
          <VideoPlayer
            :ref="(el) => { if (slot.offset === 0) player = el; }"
            :media="videoOf(slot.post)"
            :label="`Video by ${slot.post.author.name}`"
            fill
            :active="slot.offset === 0"
            :prime="slot.offset === 1 && !videoPrefs.dataSaver"
            :start-at="slot.post.id === startId && !moved ? startAt : 0"
          />

          <!-- Easier reading over bright videos -->
          <div class="pointer-events-none absolute inset-x-0 bottom-0 z-[5] h-[46%] bg-gradient-to-t from-black/80 via-black/35 to-transparent" aria-hidden="true"></div>

          <!-- Actions on the right, clear of the picture's centre -->
          <div class="absolute right-2 z-[6] flex flex-col items-center gap-2.5 bottom-[calc(108px+env(safe-area-inset-bottom))]">
            <button type="button" class="wm-viewer-action" :class="{ 'text-[#7CB8FF]': slot.post.liked }" :aria-pressed="slot.post.liked" :aria-label="`Like. ${countLabel(slot.post.likes_count, 'like')}`" @click="toggleLike(slot.post)">
              <i :class="['bi text-[24px]', slot.post.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up']" aria-hidden="true"></i>
              <span class="text-[12px] font-semibold tabular-nums" aria-hidden="true">{{ slot.post.likes_count ? shortCount(slot.post.likes_count) : 'Like' }}</span>
            </button>
            <button type="button" class="wm-viewer-action" :aria-label="`Comments. ${countLabel(slot.post.comments_count, 'comment')}`" @click="$emit('comment', slot.post)">
              <i class="bi bi-chat text-[24px]" aria-hidden="true"></i>
              <span class="text-[12px] font-semibold tabular-nums" aria-hidden="true">{{ slot.post.comments_count ? shortCount(slot.post.comments_count) : 'Comment' }}</span>
            </button>
            <button type="button" class="wm-viewer-action" aria-label="Share" @click="sharePost(slot.post)">
              <i class="bi bi-share text-[22px]" aria-hidden="true"></i>
              <span class="text-[12px] font-semibold" aria-hidden="true">Share</span>
            </button>
            <button type="button" class="wm-viewer-action" :class="{ 'text-[#7CB8FF]': slot.post.saved }" :aria-pressed="slot.post.saved" :aria-label="slot.post.saved ? 'Saved' : 'Save'" @click="toggleSave(slot.post)">
              <i :class="['bi text-[22px]', slot.post.saved ? 'bi-bookmark-fill' : 'bi-bookmark']" aria-hidden="true"></i>
              <span class="text-[12px] font-semibold" aria-hidden="true">{{ slot.post.saved ? 'Saved' : 'Save' }}</span>
            </button>
          </div>

          <!-- Who, the words, and (for providers' work) the profile and a request -->
          <div class="absolute inset-x-0 bottom-0 z-[6] pl-4 pr-[76px] pb-[calc(14px+env(safe-area-inset-bottom))]">
            <component :is="link(slot.post) ? 'router-link' : 'div'" :to="link(slot.post) || undefined" class="inline-flex max-w-full items-center gap-2.5" @click="leaving">
              <UserAvatar :name="slot.post.author.name" :src="slot.post.author.avatar_url || ''" :size="38" class="ring-2 ring-white/70" />
              <span class="flex min-w-0 items-center gap-1.5 text-[15px] font-semibold drop-shadow">
                <span class="truncate">{{ slot.post.author.name }}</span>
                <VerifiedBadge :provider="{ documentVerified: slot.post.author.document_verified, blueTick: slot.post.author.blue_tick }" />
              </span>
            </component>
            <p v-if="slot.post.author.title || slot.post.area" class="mt-0.5 text-[12.5px] text-white/80 truncate">
              {{ [slot.post.author.title, slot.post.area].filter(Boolean).join(' · ') }}
            </p>
            <div v-if="slot.post.body" class="mt-1.5" :data-no-swipe="expanded ? '' : undefined">
              <p
                class="whitespace-pre-line break-words text-[14px] leading-[1.45] text-white drop-shadow"
                :class="expanded ? 'max-h-[34vh] overflow-y-auto overscroll-contain pr-1' : 'line-clamp-2'"
              >{{ slot.post.body }}</p>
              <button v-if="expanded || long(slot.post)" type="button" class="min-h-8 text-[13px] font-semibold text-white/85" :aria-expanded="expanded" @click="expanded = !expanded">
                {{ expanded ? 'See less' : 'See more' }}
              </button>
            </div>
            <div v-if="offersService(slot.post)" class="mt-2 flex flex-wrap gap-2">
              <router-link :to="`/provider/${slot.post.author.provider_id}`" class="wm-viewer-pill" @click="leaving">
                <i class="bi bi-person-badge" aria-hidden="true"></i>View provider
              </router-link>
              <router-link :to="`/provider/${slot.post.author.provider_id}/request`" class="wm-viewer-pill !bg-white !text-ink" @click="leaving">
                <i class="bi bi-calendar-plus" aria-hidden="true"></i>Request service
              </router-link>
            </div>
          </div>
        </section>

        <!-- End of the list / loading more -->
        <p v-if="edgeNote" class="pointer-events-none absolute inset-x-0 top-1/2 z-[8] mx-auto w-max max-w-[80%] -translate-y-1/2 rounded-full bg-black/70 px-4 py-2 text-center text-[13px] font-semibold" role="status">{{ edgeNote }}</p>
      </div>

      <!-- Close and sound, top corners (clear of the status bar and camera cut-out) -->
      <div class="pointer-events-none fixed inset-x-0 top-0 z-[9] mx-auto flex max-w-[560px] items-center justify-between px-3 pt-[calc(10px+env(safe-area-inset-top))]">
        <button ref="closeBtn" type="button" class="wm-viewer-round pointer-events-auto" aria-label="Close videos" @click="$emit('close')">
          <i class="bi bi-x-lg text-[20px]" aria-hidden="true"></i>
        </button>
        <button type="button" class="wm-viewer-round pointer-events-auto" :aria-label="soundOff ? 'Turn sound on' : 'Mute'" :aria-pressed="!soundOff" @click="toggleSound">
          <i :class="['bi text-[20px]', soundOff ? 'bi-volume-mute-fill' : 'bi-volume-up-fill']" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Keyboard and mouse: previous and next -->
      <div class="fixed right-4 top-1/2 z-[9] hidden -translate-y-1/2 flex-col gap-2 sm:flex">
        <button type="button" class="wm-viewer-round" aria-label="Previous video" :disabled="index === 0" @click="go(-1)"><i class="bi bi-chevron-up text-[20px]" aria-hidden="true"></i></button>
        <button type="button" class="wm-viewer-round" aria-label="Next video" :disabled="atEnd && done" @click="go(1)"><i class="bi bi-chevron-down text-[20px]" aria-hidden="true"></i></button>
      </div>

      <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import VideoPlayer from './VideoPlayer.vue';
import { videoPrefs, setVideoMuted } from '@/stores/video.js';
import { toggleLike, toggleSave, sharePost, personLink, shortCount, countLabel } from '@/stores/community.js';

/**
 * Community videos, full screen, one at a time: swipe up for the next, down
 * for the previous. The posts are the feed's own objects, so a like or save
 * here shows in the feed too. Only the previous, current and next videos are
 * on the page; only the current one plays, and the next one is readied (its
 * header only) when Data Saver is off.
 */
const props = defineProps({
  // The feed's video posts, in feed order
  videos: { type: Array, required: true },
  startId: { type: String, required: true },
  // Seconds into the first video (where it had got to in the feed)
  startAt: { type: Number, default: 0 },
  // No more feed pages to load
  done: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'comment', 'load-more']);

const root = ref(null);
const stage = ref(null);
const closeBtn = ref(null);
// The playing slide's player (for the sound button)
const player = shallowRef(null);

const currentId = ref(props.startId);
const index = computed(() => Math.max(0, props.videos.findIndex(p => p.id === currentId.value)));
const atEnd = computed(() => index.value >= props.videos.length - 1);
const moved = ref(false);       // left the first video (its start time no longer applies)
const expanded = ref(false);
const edgeNote = ref('');
const wantsNext = ref(false);   // swiped past the end while more were loading

const slots = computed(() => [-1, 0, 1]
  .map(offset => ({ offset, post: props.videos[index.value + offset] }))
  .filter(s => s.post));
const soundOff = computed(() => (player.value ? player.value.muted : videoPrefs.muted));
// The same sound choice as the feed's videos (the playing one changes at once, without restarting)
function toggleSound() {
  if (player.value) player.value.toggleMute();
  else setVideoMuted(!videoPrefs.muted);
}
const announcement = computed(() => {
  const p = props.videos[index.value];
  return p ? `Video ${index.value + 1}${props.done ? ` of ${props.videos.length}` : ''}, by ${p.author.name}` : '';
});

const videoOf = (post) => post.media.find(m => m.type === 'video');
const link = (post) => personLink(post.author);
const offersService = (post) => !!post.author.provider_id && !post.mine;
const long = (post) => (post.body || '').length > 90 || (post.body || '').split('\n').length > 2;

/* ---------------- Moving between videos ---------------- */

function go(step) {
  if (step > 0) {
    if (!atEnd.value) {
      show(index.value + 1);
    } else if (!props.done) {
      wantsNext.value = true;
      note('Loading more videos…', 0);
      emit('load-more');
    } else {
      note('That’s all the videos for now.');
    }
  } else if (index.value > 0) {
    show(index.value - 1);
  } else {
    note('This is the first video.');
  }
}

function show(i) {
  const post = props.videos[i];
  if (!post) return;
  moved.value = true;
  expanded.value = false;
  currentId.value = post.id;
  // Close to the end: fetch more in good time (the page skips this if already loading)
  if (i >= props.videos.length - 2 && !props.done) emit('load-more');
}

// More loaded while they waited at the end: go on to the next
watch(() => props.videos.length, () => {
  if (wantsNext.value && !atEnd.value) {
    wantsNext.value = false;
    edgeNote.value = '';
    show(index.value + 1);
  }
});
watch(() => props.done, (done) => {
  if (done && wantsNext.value) { wantsNext.value = false; note('That’s all the videos for now.'); }
});
// The current video removed (deleted or taken down): the nearest one, or close
watch(() => props.videos.map(p => p.id).join(), () => {
  if (props.videos.some(p => p.id === currentId.value)) return;
  if (!props.videos.length) { emit('close'); return; }
  currentId.value = props.videos[Math.min(props.videos.length - 1, index.value)].id;
});

let noteTimer = null;
function note(text, ms = 1800) {
  edgeNote.value = text;
  clearTimeout(noteTimer);
  if (ms) noteTimer = setTimeout(() => { edgeNote.value = ''; }, ms);
}

/* ---------------- Swiping ---------------- */

const drag = ref(0);
const dragging = ref(false);
let startY = null;
let startX = 0;
let startT = 0;
let vertical = null;
let moveY = 0;      // how far the finger really moved (the slide moves less at either end)

const reduceMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

function slideStyle(offset) {
  return {
    transform: `translate3d(0, calc(${offset * 100}% + ${drag.value}px), 0)`,
    transition: dragging.value || reduceMotion ? 'none' : 'transform 260ms cubic-bezier(0.22, 0.8, 0.3, 1)',
  };
}

function touchStart(e) {
  if (e.touches.length !== 1 || e.target.closest('[data-no-swipe]')) { startY = null; return; }
  startY = e.touches[0].clientY;
  startX = e.touches[0].clientX;
  startT = Date.now();
  vertical = null;
  moveY = 0;
}
function touchMove(e) {
  if (startY === null) return;
  const dy = e.touches[0].clientY - startY;
  const dx = e.touches[0].clientX - startX;
  // Decide once whether this is an up/down swipe; small or sideways movements are left alone
  if (vertical === null && (Math.abs(dy) > 8 || Math.abs(dx) > 8)) vertical = Math.abs(dy) > Math.abs(dx);
  if (!vertical) return;
  e.preventDefault();
  dragging.value = true;
  moveY = dy;
  // Resistance at either end
  const edge = (dy > 0 && index.value === 0) || (dy < 0 && atEnd.value && props.done);
  drag.value = edge ? dy * 0.25 : dy;
}
function touchEnd() {
  if (startY === null) return;
  const dy = moveY;
  const h = stage.value?.clientHeight || window.innerHeight;
  const speed = dy / Math.max(1, Date.now() - startT);
  dragging.value = false;
  drag.value = 0;
  startY = null;
  if (!vertical) return;
  // A clear swipe: a fifth of the screen, or a quick flick (small nudges do nothing)
  if (dy < -h * 0.2 || (dy < -40 && speed < -0.45)) go(1);
  else if (dy > h * 0.2 || (dy > 40 && speed > 0.45)) go(-1);
}

let wheelCooling = false;
function onWheel(e) {
  if (wheelCooling || Math.abs(e.deltaY) < 30 || e.target.closest('[data-no-swipe]')) return;
  wheelCooling = true;
  setTimeout(() => { wheelCooling = false; }, 600);
  go(e.deltaY > 0 ? 1 : -1);
}

/* ---------------- Keyboard and focus ---------------- */

function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); emit('close'); return; }
  if (['ArrowDown', 'PageDown'].includes(e.key)) { e.preventDefault(); go(1); return; }
  if (['ArrowUp', 'PageUp'].includes(e.key)) { e.preventDefault(); go(-1); return; }
  if (e.key === 'Tab') {
    // Focus stays inside the viewer while it's open
    const items = [...root.value.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])')]
      .filter(el => !el.disabled && !el.closest('[inert]') && el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
}

/** Following a link out of the viewer (a profile, a request): let it close first */
function leaving() {
  player.value?.pause();
}

let overflow = '';
onMounted(async () => {
  overflow = document.documentElement.style.overflow;
  document.documentElement.style.overflow = 'hidden';
  await nextTick();
  closeBtn.value?.focus({ preventScroll: true });
});
onBeforeUnmount(() => {
  clearTimeout(noteTimer);
  document.documentElement.style.overflow = overflow;
});
</script>
