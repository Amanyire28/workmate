<template>
  <div
    ref="root"
    class="wm-video relative w-full overflow-hidden bg-[#0B1220] text-white select-none"
    :class="{ 'wm-video-full': fullscreen, 'wm-video-fill': fill }"
    :style="fullscreen || fill ? null : { aspectRatio: boxRatio }"
    role="group"
    :aria-label="label"
  >
    <!-- The space beside a tall or wide video: a blurred copy of its poster -->
    <img v-if="poster" :src="media.thumb_url || poster" alt="" aria-hidden="true" class="wm-media-backdrop" loading="lazy" decoding="async" />
    <!-- Nothing is downloaded until the video is played (or, with Data Saver off, scrolled into view) -->
    <video
      v-if="armed"
      ref="video"
      class="absolute inset-0 h-full w-full object-contain"
      :src="media.url"
      :poster="poster || undefined"
      playsinline
      webkit-playsinline
      preload="metadata"
      :muted="muted"
      disablepictureinpicture
      @loadedmetadata="onMetadata"
      @playing="onPlaying"
      @pause="onPause"
      @waiting="waiting = true"
      @canplay="waiting = false"
      @timeupdate="onTime"
      @ended="onEnded"
      @error="onError"
    ></video>
    <img v-else-if="poster" :src="poster" alt="" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-contain" />

    <!-- Processing or failed on the server: nothing to play yet -->
    <div v-if="status !== 'ready'" class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-[#0B1220]/70 px-6 text-center" role="status">
      <template v-if="status === 'processing'">
        <span class="wm-spinner" aria-hidden="true"></span>
        <p class="text-[14px] font-semibold">Getting this video ready…</p>
        <p class="text-[13px] text-white/75">It will play here in a few minutes.</p>
      </template>
      <template v-else>
        <i class="bi bi-camera-video-off text-2xl" aria-hidden="true"></i>
        <p class="text-[14px] font-semibold">{{ status === 'unavailable' ? 'This video isn’t available.' : 'This video couldn’t be prepared.' }}</p>
      </template>
    </div>

    <template v-else>
      <!-- Tap anywhere to play or pause (in the feed: open the full-screen viewer) -->
      <button
        type="button"
        class="absolute inset-0 z-[1] h-full w-full cursor-pointer focus-visible:outline-offset-[-4px]"
        :aria-label="opensViewer ? `Play video full screen. ${durationLabel}` : playing ? `Pause video. ${durationLabel}` : `Play video. ${durationLabel}`"
        :aria-haspopup="opensViewer ? 'dialog' : undefined"
        @click="tapped"
      ></button>

      <!-- The big play button while paused -->
      <span v-if="!playing && !error && !waitingVisible" class="pointer-events-none absolute left-1/2 top-1/2 z-[2] inline-flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-[#0B1220]/60 ring-2 ring-white/80 backdrop-blur-[2px]" aria-hidden="true">
        <i class="bi bi-play-fill ml-1 text-[34px] leading-none"></i>
      </span>
      <span v-if="waitingVisible && !error" class="pointer-events-none absolute left-1/2 top-1/2 z-[2] -translate-x-1/2 -translate-y-1/2" aria-hidden="true">
        <span class="wm-spinner !h-9 !w-9"></span>
      </span>
      <p v-if="waitingVisible && !error" class="sr-only" role="status">Loading video</p>

      <!-- Couldn't play -->
      <div v-if="error" class="absolute inset-0 z-[3] flex flex-col items-center justify-center gap-3 bg-[#0B1220]/80 px-6 text-center" role="alert">
        <i class="bi bi-exclamation-circle text-2xl" aria-hidden="true"></i>
        <p class="text-[14px] font-semibold">{{ error }}</p>
        <button v-if="error !== 'This video isn’t available any more.'" type="button" class="wm-btn wm-btn-secondary !min-h-11" @click="retry()">Try again</button>
      </div>

      <!-- Sound, in every feed video (one choice for all videos); in its own corner, so it never opens the viewer -->
      <button
        v-if="!fill && !error && (opensViewer || !started)"
        type="button"
        class="wm-video-sound"
        :class="{ 'wm-video-sound-hint': soundBlocked && playing }"
        :aria-label="muted ? 'Turn sound on for videos' : 'Mute videos'"
        :aria-pressed="!muted"
        @click.stop="toggleMute"
        @pointerdown.stop
        @touchstart.stop.passive
      >
        <span class="wm-video-sound-face">
          <i :class="['bi', muted ? 'bi-volume-mute-fill' : 'bi-volume-up-fill']" aria-hidden="true"></i>
          <span v-if="soundBlocked && playing" class="text-[12px] font-semibold">Tap for sound</span>
        </span>
      </button>

      <!-- Length before playing; controls once it has started -->
      <span v-if="(!started || opensViewer) && !error && !fill" class="pointer-events-none absolute bottom-2.5 left-2.5 z-[2] rounded-full bg-[#0B1220]/70 px-2.5 py-1 text-[12px] font-semibold tabular-nums" aria-hidden="true">
        <i class="bi bi-camera-video mr-1" aria-hidden="true"></i>{{ formatDuration(length) }}
      </span>
      <!-- Full screen: a thin progress line only (the viewer has its own buttons) -->
      <div v-if="fill && started && !error" class="pointer-events-none absolute inset-x-0 bottom-0 z-[2] h-[3px] bg-white/25" aria-hidden="true">
        <div class="h-full bg-white" :style="{ width: `${length ? (position / length) * 100 : 0}%` }"></div>
      </div>
      <div v-else-if="started && !error && !opensViewer && !fill" class="absolute inset-x-0 bottom-0 z-[2] flex items-center gap-1 bg-gradient-to-t from-[#0B1220]/80 to-transparent px-1.5 pb-1 pt-6">
        <label class="flex min-h-11 min-w-0 flex-1 items-center px-2">
          <span class="sr-only">Video position</span>
          <input
            type="range"
            class="wm-video-seek w-full"
            min="0"
            :max="length || 0"
            step="0.1"
            :value="position"
            :aria-valuetext="`${formatDuration(position)} of ${formatDuration(length)}`"
            :style="{ '--wm-seek': `${length ? (position / length) * 100 : 0}%` }"
            @input="seek($event.target.value)"
          />
        </label>
        <span class="w-11 shrink-0 text-center text-[12px] font-semibold tabular-nums" aria-hidden="true">{{ formatDuration(Math.max(0, length - position)) }}</span>
        <button type="button" class="wm-video-btn" :aria-label="muted ? 'Turn sound on' : 'Mute'" :aria-pressed="!muted" @click="toggleMute">
          <i :class="['bi', muted ? 'bi-volume-mute-fill' : 'bi-volume-up-fill']" aria-hidden="true"></i>
        </button>
        <button type="button" class="wm-video-btn" :aria-label="fullscreen ? 'Exit full screen' : 'Full screen'" @click="toggleFullscreen">
          <i :class="['bi', fullscreen ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen']" aria-hidden="true"></i>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { formatDuration, videoLog } from '@/utils/video.js';
import { api } from '@/api/client.js';
import { videoPrefs, autoplayAllowed, claimPlayback, releasePlayback, setVideoMuted } from '@/stores/video.js';

const props = defineProps({
  // { url, poster_url, thumb_url, width, height, duration, status }
  media: { type: Object, required: true },
  label: { type: String, default: 'Video' },
  // In the feed: a tap opens the full-screen viewer instead of playing here
  opensViewer: { type: Boolean, default: false },
  // In the viewer: fills the screen (whole picture, never stretched or cropped)
  fill: { type: Boolean, default: false },
  // In the viewer: plays while true, and is fully released while false (null: the feed decides)
  active: { type: Boolean, default: null },
  // In the viewer: the next video, readied by fetching only its header (Data Saver off)
  prime: { type: Boolean, default: false },
  // Where to start (seconds), e.g. the moment it had reached in the feed
  startAt: { type: Number, default: 0 },
});
const emit = defineEmits(['open']);

const root = ref(null);
const video = ref(null);

const armed = ref(false);       // the <video> is on the page (and may download)
const playing = ref(false);
const started = ref(false);     // played at least once
const waiting = ref(false);
const error = ref('');
const position = ref(0);
const loadedLength = ref(0);
// The browser refused sound without a tap (e.g. iPhone): playing silently until they tap the sound button
const soundBlocked = ref(false);
// Shared by every video: what they chose, unless the browser made this one silent
const muted = computed(() => videoPrefs.muted || soundBlocked.value);
const fullscreen = ref(false);
let resumeAt = props.startAt || 0;
let userPaused = false;          // they paused it: don't start it again by itself
let autoStarted = false;

const status = computed(() => props.media.status || 'ready');
const poster = computed(() => props.media.poster_url || props.media.thumb_url || '');
const length = computed(() => loadedLength.value || Number(props.media.duration) || 0);
const durationLabel = computed(() => (length.value ? `${formatDuration(length.value)} long` : ''));
// Short pauses for loading don't flash a spinner
const waitingVisible = computed(() => waiting.value && armed.value);

// Its own shape, within limits; tall and wide videos get dark bars, never cropped
const boxRatio = computed(() => {
  const w = Number(props.media.width) || 16;
  const h = Number(props.media.height) || 9;
  return String(Math.min(16 / 9, Math.max(4 / 5, w / h)));
});
const controlled = computed(() => props.active !== null);

/* ---------------- Playing ---------------- */

const handle = { pause: () => video.value?.pause() };

async function start({ auto = false } = {}) {
  if (status.value !== 'ready') return;
  error.value = '';
  if (!armed.value) {
    armed.value = true;
    await nextTick();
  }
  const v = video.value;
  if (!v) return;
  // Released earlier (its address taken off) while the page kept the same address: put it back
  if (!v.getAttribute('src') && props.media.url) v.src = props.media.url;
  autoStarted = auto;
  // Their sound choice, whether it was tapped or started by itself
  soundBlocked.value = false;
  v.muted = muted.value;
  claimPlayback(handle);
  try {
    await v.play();
  } catch (e) {
    if (e?.name === 'NotAllowedError' && !v.muted) {
      // Sound needs a tap here (iPhones, some Android browsers): play silently and offer "Tap for sound";
      // if even that is refused (e.g. Low Power Mode), the play button stays for a tap
      soundBlocked.value = true;
      v.muted = true;
      try { await v.play(); } catch { /* the play button stays */ }
    } else if (e?.name !== 'AbortError' && !auto && !v.error) {
      // A file that can't load is handled once, by the error event (not here as well)
      onError();
    }
  }
}

/** In the feed: open the viewer where this one had got to (and stop it here) */
function tapped() {
  if (props.opensViewer) {
    const at = video.value?.currentTime || 0;
    // The viewer plays it from here; this copy lets go of its download
    unload();
    emit('open', { at });
    return;
  }
  toggle();
}

function toggle() {
  if (playing.value) {
    userPaused = true;
    video.value?.pause();
  } else {
    userPaused = false;
    // Tapping a video that started by itself keeps it running with their sound choice
    start();
  }
}

/** Sound on or off for every video; this one keeps playing from where it is */
function toggleMute() {
  const soundOn = muted.value;
  soundBlocked.value = false;
  setVideoMuted(!soundOn);
  // Straight away, inside the tap (browsers allow sound only then)
  if (video.value) video.value.muted = !soundOn;
  autoStarted = false;
}

// Changed on another video, or in the viewer: follow it (no restart, same position)
watch(() => videoPrefs.muted, (m) => {
  if (!m) soundBlocked.value = false;
  if (video.value) video.value.muted = muted.value;
});

function seek(value) {
  const v = video.value;
  if (!v) return;
  v.currentTime = Number(value);
  position.value = Number(value);
}

function onMetadata() {
  const v = video.value;
  if (Number.isFinite(v.duration)) loadedLength.value = v.duration;
  if (resumeAt) {
    v.currentTime = resumeAt;
    resumeAt = 0;
  }
}
function onPlaying() {
  playing.value = true;
  started.value = true;
  waiting.value = false;
}
function onPause() {
  playing.value = false;
  waiting.value = false;
}
function onTime() {
  position.value = video.value?.currentTime || 0;
}
function onEnded() {
  playing.value = false;
  userPaused = true;
  releasePlayback(handle);
}
let handlingError = false;
async function onError() {
  if (handlingError) return;
  handlingError = true;
  try {
    await handleError();
  } finally {
    handlingError = false;
  }
}
async function handleError() {
  const code = video.value?.error?.code;
  // The browser's reason (1 stopped, 2 network, 3 decoding, 4 not supported or not found); never the address
  videoLog('playback error', { media: props.media.id, mediaErrorCode: code ?? null, message: video.value?.error?.message || null, retriedWithFreshAddress: refreshedOnce }, true);
  playing.value = false;
  waiting.value = false;
  releasePlayback(handle);
  // The signed address may simply have run out (a page left open): get a fresh one, once
  if (!refreshedOnce && await refreshUrl()) {
    refreshedOnce = true;
    retry({ refresh: false });
    return;
  }
  // Network trouble, or (after a fresh address didn't help) a format this browser can't play:
  // videos are kept as recorded, so e.g. H.265/HEVC plays only where the device supports it
  error.value = code === 2
    ? 'The video stopped loading. Check your connection.'
    : 'This video’s format can’t play in this browser. It may play on another phone or browser.';
}

/** A fresh playback address from WorkMate; false when there is none (or it's a development preview) */
let refreshedOnce = false;
async function refreshUrl() {
  const id = props.media.id;
  if (!id || String(id).startsWith('mock-')) return false;
  try {
    const r = await api(`/community/media/${id}/playback`);
    // The same media object the post card holds, so the new address is kept
    // eslint-disable-next-line vue/no-mutating-props
    props.media.url = r.data.url;
    return true;
  } catch (e) {
    if (e?.status === 404) error.value = 'This video isn’t available any more.';
    return false;
  }
}

async function retry({ refresh = true } = {}) {
  error.value = '';
  resumeAt = position.value;
  unload();
  if (refresh) await refreshUrl();
  if (error.value) return;
  start();
}

/** Stops any download (keeping their place) */
function unload() {
  const v = video.value;
  if (v) {
    if (v.currentTime) resumeAt = v.currentTime;
    v.pause();
    v.removeAttribute('src');
    v.load();
  }
  armed.value = false;
  playing.value = false;
  waiting.value = false;
  releasePlayback(handle);
}

/* ---------------- Scrolling ---------------- */

let observer = null;
let visible = 0;
onMounted(() => {
  // In the viewer, the viewer decides what plays
  if (controlled.value || !('IntersectionObserver' in window)) return;
  observer = new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting ? entry.intersectionRatio : 0;
    if (fullscreen.value) return;
    if (visible < 0.3 && playing.value) {
      video.value?.pause();
      // A fresh start next time it comes into view
      userPaused = false;
    }
    // Well off the screen: stop downloading it
    if (!entry.isIntersecting && armed.value) unload();
    if (visible >= 0.6 && autoplayAllowed.value && !playing.value && !userPaused && !error.value) start({ auto: true });
  }, { threshold: [0, 0.3, 0.6] });
  observer.observe(root.value);
});

/* ---------------- In the viewer ---------------- */

// The current slide plays (they chose it: a tap or a swipe); any other is released at once
watch(() => props.active, (active) => {
  if (active === null) return;
  if (active) {
    userPaused = false;
    start();
  } else {
    unload();
    resumeAt = 0;   // coming back to it starts from the beginning
    if (props.prime) prepare();
  }
}, { immediate: true });

// The next video: only its header is fetched, so it starts at once when swiped to
function prepare() {
  if (status.value !== 'ready' || armed.value || props.active) return;
  armed.value = true;
}
watch(() => props.prime, (prime) => {
  if (!controlled.value || props.active) return;
  if (prime) prepare(); else if (armed.value) unload();
}, { immediate: true });

defineExpose({
  get muted() { return muted.value; },
  get playing() { return playing.value; },
  toggleMute,
  pause: () => video.value?.pause(),
});

// Data Saver turned on while a video plays by itself: stop it
watch(autoplayAllowed, (allowed) => {
  if (controlled.value) return;
  if (!allowed && playing.value && autoStarted) video.value?.pause();
  if (allowed && visible >= 0.6 && !playing.value && !userPaused) start({ auto: true });
});

/* ---------------- Full screen ---------------- */

function onFullscreenChange() {
  fullscreen.value = (document.fullscreenElement || document.webkitFullscreenElement) === root.value;
}
async function toggleFullscreen() {
  if (fullscreen.value) {
    (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
    return;
  }
  const el = root.value;
  if (el.requestFullscreen) {
    try { await el.requestFullscreen(); } catch { /* not allowed here */ }
  } else if (el.webkitRequestFullscreen) {
    el.webkitRequestFullscreen();
  } else if (video.value?.webkitEnterFullscreen) {
    // iPhones: only the video itself can go full screen
    video.value.webkitEnterFullscreen();
  }
}
document.addEventListener('fullscreenchange', onFullscreenChange);
document.addEventListener('webkitfullscreenchange', onFullscreenChange);

onBeforeUnmount(() => {
  observer?.disconnect();
  document.removeEventListener('fullscreenchange', onFullscreenChange);
  document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
  unload();
});
</script>
