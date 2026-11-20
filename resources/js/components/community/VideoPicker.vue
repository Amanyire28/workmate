<template>
  <div>
    <!-- The file box; the composer's "Add video" and "Replace" buttons are labels for it -->
    <input :id="inputId" type="file" :accept="VIDEO_ACCEPT" class="sr-only" :disabled="disabled || !!working || !!trimming" @change="pick" />

    <!-- Too big: choose the part to keep -->
    <VideoTrimmer
      v-if="trimming && !working"
      :key="trimming.key"
      :url="trimming.url"
      :size="trimming.file.size"
      :duration="trimming.info.duration"
      :max-bytes="trimming.maxBytes"
      :playable="trimming.playable"
      :note="trimming.note"
      :initial="trimming.last"
      @trim="cut"
      @cancel="stop('Trimming cancelled. Your words are still here; choose a video again when you’re ready.')"
    />

    <!-- Making the shorter file: real progress, and a way out -->
    <div v-else-if="working" class="flex w-full flex-col gap-3 rounded-xl bg-primary-tint/70 px-4 py-4" role="status" aria-live="polite">
      <div class="flex items-center gap-3">
        <span class="wm-spinner text-primary" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 text-[14px] font-semibold text-ink">
          {{ working.label }}<template v-if="working.measurable"> {{ Math.round(working.progress * 100) }}%</template>
        </p>
      </div>
      <div v-if="working.measurable" class="wm-upload-bar" role="progressbar" :aria-label="working.label" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(working.progress * 100)">
        <span :style="{ width: `${Math.round(working.progress * 100)}%` }"></span>
      </div>
      <p class="text-[13px] text-muted">Keep this page open; your words stay as they are.</p>
      <button type="button" class="wm-btn wm-btn-secondary !min-h-10 self-start !px-4 !text-[13px]" @click="stop('Stopped. Choose the video again when you’re ready.')">
        <i class="bi bi-x-lg" aria-hidden="true"></i>Stop
      </button>
    </div>

    <div v-else-if="checking" class="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-xl bg-[#E2E8F0] text-muted" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>
      <span class="text-[14px]">Checking your video…</span>
    </div>

    <figure v-else-if="modelValue" class="overflow-hidden rounded-xl bg-[#0B1220]">
      <video
        v-if="modelValue.previewable"
        :key="modelValue.key"
        :src="modelValue.url"
        :poster="modelValue.posterUrl || undefined"
        controls
        playsinline
        preload="metadata"
        class="block max-h-[46vh] w-full object-contain"
        :aria-label="`Your video${modelValue.duration ? `, ${formatDuration(modelValue.duration)} long` : ''}`"
      ></video>
      <div v-else class="flex aspect-video w-full flex-col items-center justify-center gap-2 px-6 text-center text-white">
        <i class="bi bi-camera-video text-2xl" aria-hidden="true"></i>
        <p class="text-[14px] font-semibold">This browser can’t show a preview of this video.</p>
        <p class="text-[13px] text-white/75">It will be shared exactly as recorded. People whose phones support its format will be able to watch it.</p>
      </div>
      <figcaption class="flex flex-wrap items-center justify-between gap-2 bg-white px-1 pt-2">
        <span class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
          <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>
          <span class="tabular-nums">{{ modelValue.duration ? formatDuration(modelValue.duration) : 'length unknown' }}</span><span aria-hidden="true">·</span><span class="tabular-nums">{{ formatBytes(modelValue.size) }}</span>
          <template v-if="modelValue.trimmed"><span aria-hidden="true">·</span><span>trimmed</span></template>
        </span>
        <span class="flex gap-2">
          <label :for="inputId" class="wm-btn wm-btn-secondary !min-h-10 !px-3.5 !text-[13px] cursor-pointer" :class="{ 'pointer-events-none opacity-60': disabled }">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Replace
          </label>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !px-3.5 !text-[13px] !text-error" :disabled="disabled" @click="remove">
            <i class="bi bi-trash" aria-hidden="true"></i>Remove
          </button>
        </span>
      </figcaption>
    </figure>
  </div>
</template>

<script setup>
import { ref, onBeforeUnmount } from 'vue';
import VideoTrimmer from './VideoTrimmer.vue';
import { VIDEO_ACCEPT, UNKNOWN_FORMAT, checkVideo, readVideoMetadata, makePoster, formatBytes, formatDuration, videoLog } from '@/utils/video.js';
import { videoLimits } from '@/stores/video.js';

/**
 * Choosing one video for a post. The original file is used as it is; one
 * over the size limit can be trimmed here first (same format and quality).
 * Nothing incomplete is ever handed on. v-model holds
 * { key, file, url, poster, posterUrl, duration, width, height, size, trimmed, previewable } or null.
 */
const props = defineProps({
  modelValue: { type: Object, default: null },
  inputId: { type: String, required: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'error', 'checking']);

const checking = ref(false);
const trimming = ref(null);   // { key, file, url, info, maxBytes, playable, note, last }
const working = ref(null);    // { label, progress, measurable }
let control = null;
let keys = 0;
let generation = 0;

const TRIM_ALTERNATIVE = 'Trim it in your phone’s Gallery (Edit → Trim) and choose it again, or choose a smaller video.';

async function loadTools() {
  try {
    // Only downloaded when a video needs it
    return await import('@/utils/videoTrim.js');
  } catch (e) {
    videoLog('trim tools did not load', { error: String(e?.message || e) }, true);
    return null;
  }
}

async function pick(e) {
  const original = e.target.files?.[0];
  e.target.value = '';
  if (!original) return;
  emit('error', '');
  const mine = ++generation;
  checking.value = true;
  emit('checking', true);
  const maxBytes = videoLimits.value.maxMb * 1024 * 1024;
  let url = URL.createObjectURL(original);
  let keepWaiting = false;
  try {
    const result = await checkVideo(original, url, videoLimits.value);
    if (mine !== generation) return;
    videoLog('chosen', { name: original.name, type: original.type, bytes: original.size, family: result.family || null, ready: !!result.ok, needsReading: !!result.probe, needsTrim: !!result.trim, error: result.error || null });
    if (result.error) {
      emit('error', result.error);
      return;
    }

    if (result.ok) {
      await use(original, url, { duration: result.duration, width: result.width, height: result.height }, { previewable: true }, mine);
      url = null;
      return;
    }

    // Either this browser can't play it (read its details from the file), or it's too big (trim)
    const tools = await loadTools();
    if (mine !== generation) return;
    if (!tools) {
      emit('error', result.trim ? `This video is ${formatBytes(original.size)}, over the ${formatBytes(maxBytes)} limit, and the trimming tool couldn’t load. Check your connection and try again.` : 'We couldn’t read this video. Check your connection and try again.');
      return;
    }
    let info;
    try {
      info = await tools.probe(original);
    } catch (err) {
      videoLog('could not read', { error: String(err?.message || err) }, true);
      emit('error', result.trim ? `This video is ${formatBytes(original.size)}, and this browser can’t read its format to trim it. ${TRIM_ALTERNATIVE}` : UNKNOWN_FORMAT);
      return;
    }
    if (mine !== generation) return;
    videoLog('read', { container: info.container, seconds: info.duration, video: info.video, audio: info.audio, userAgent: navigator.userAgent });
    if (!info.video || !info.video.width || !info.video.height) {
      // Too big and unreadable here: say what can't be done, and what to do instead
      emit('error', result.trim
        ? `This video is ${formatBytes(original.size)}, and this browser can’t read its format to trim it. ${TRIM_ALTERNATIVE}`
        : 'This file has no picture we can read. Choose a video recorded with your phone’s camera.');
      return;
    }

    if (result.probe) {
      // Fine to share as it is; this browser just can't show it
      await use(original, url, { duration: info.duration, width: info.video.width, height: info.video.height }, { previewable: false }, mine);
      url = null;
      return;
    }

    // Too big: open the trimmer (the member chooses; nothing happens until they do)
    if (!info.duration) {
      emit('error', `This video is ${formatBytes(original.size)}, and its length can’t be read here to trim it. ${TRIM_ALTERNATIVE}`);
      return;
    }
    let playable = true;
    try { await readVideoMetadata(url); } catch { playable = false; }
    if (mine !== generation) return;
    trimming.value = { key: mine, file: original, url, info, maxBytes, playable, note: '', last: null, tools };
    url = null;
    keepWaiting = true;
  } finally {
    if (url) URL.revokeObjectURL(url);
    if (mine === generation) {
      checking.value = false;
      if (!keepWaiting) emit('checking', false);
    }
  }
}

/** Makes the shorter file, checks its real size, and uses it (or asks again) */
async function cut({ start, end }) {
  const t = trimming.value;
  if (!t) return;
  const mine = generation;
  working.value = { label: 'Trimming your video…', progress: 0, measurable: true };
  control = new AbortController();
  const started = Date.now();
  let file;
  try {
    file = await t.tools.trim(t.file, t.info, start, end, {
      signal: control.signal,
      // Whole percents only: the screen doesn't need redrawing for every frame
      onProgress: (p) => {
        if (working.value && Math.floor(p * 100) !== Math.floor(working.value.progress * 100)) working.value.progress = p;
      },
    });
  } catch (e) {
    if (mine !== generation) return;
    working.value = null;
    if (e?.name === 'AbortError') return;
    videoLog('trim failed', { code: e?.code || null, reasons: e?.reasons || null, error: String(e?.message || e), container: t.info.container, video: t.info.video, audio: t.info.audio }, true);
    const why = e?.code === 'audio_not_copyable' ? 'its sound can’t be copied in this browser (and we won’t share it without sound)'
      : 'this browser can’t copy its format';
    stop(`This video can’t be trimmed here: ${why}. ${TRIM_ALTERNATIVE}`);
    return;
  } finally {
    control = null;
  }
  if (mine !== generation) return;
  working.value = null;
  videoLog('trimmed', { seconds: (Date.now() - started) / 1000, chosen: [start, end], inputBytes: t.file.size, outputBytes: file.size, limitBytes: t.maxBytes });

  if (file.size > t.maxBytes) {
    // The real result decides: back to the trimmer to shorten it
    trimming.value = { ...t, key: t.key + 0.5, last: { start, end }, note: `That part came out at ${formatBytes(file.size)}, over the ${formatBytes(t.maxBytes)} limit. Make it a little shorter.` };
    return;
  }
  const url = URL.createObjectURL(file);
  let meta = { duration: end - start, width: t.info.video.width, height: t.info.video.height };
  let previewable = false;
  try {
    const m = await readVideoMetadata(url);
    if (m.width && m.height) { meta = m; previewable = true; }
  } catch {
    // Not playable in this browser: shared as it is
  }
  if (mine !== generation) { URL.revokeObjectURL(url); return; }
  URL.revokeObjectURL(t.url);
  trimming.value = null;
  await use(file, url, meta, { previewable, trimmed: true }, mine);
  emit('checking', false);
}

/** Hands a finished file to the composer, with a poster where the browser can make one */
async function use(file, url, meta, { previewable, trimmed = false }, mine) {
  const poster = previewable ? await makePoster(url) : null;
  if (mine !== generation) { URL.revokeObjectURL(url); return; }
  release(props.modelValue);
  emit('update:modelValue', {
    key: ++keys,
    file,
    url,
    poster,
    posterUrl: poster ? URL.createObjectURL(poster) : null,
    duration: meta.duration || null,
    width: meta.width,
    height: meta.height,
    size: file.size,
    trimmed,
    previewable,
  });
}

/** Stops checking or trimming (the caption is untouched) */
function stop(message) {
  generation++;
  control?.abort();
  if (trimming.value) URL.revokeObjectURL(trimming.value.url);
  trimming.value = null;
  working.value = null;
  checking.value = false;
  emit('checking', false);
  if (message) emit('error', message);
}

function release(v) {
  if (!v) return;
  URL.revokeObjectURL(v.url);
  if (v.posterUrl) URL.revokeObjectURL(v.posterUrl);
}

function remove() {
  release(props.modelValue);
  emit('update:modelValue', null);
}

onBeforeUnmount(() => {
  generation++;
  control?.abort();
  if (trimming.value) URL.revokeObjectURL(trimming.value.url);
});
defineExpose({ release, stop: () => stop('Stopped. Choose the video again when you’re ready.') });
</script>
