<template>
  <div>
    <!-- The file box; the composer's "Add video" and "Replace" buttons are labels for it -->
    <input :id="inputId" type="file" :accept="VIDEO_ACCEPT" class="sr-only" :disabled="disabled || !!converting" @change="pick" />

    <!-- Converting on the phone: real progress, and a way out -->
    <div v-if="converting" class="flex w-full flex-col gap-3 rounded-xl bg-primary-tint/70 px-4 py-4" role="status" aria-live="polite">
      <div class="flex items-center gap-3">
        <span class="wm-spinner text-primary" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 text-[14px] font-semibold text-ink">
          {{ converting.stage === 'reading' ? 'Checking your video…' : `Preparing your video… ${Math.round(converting.progress * 100)}%` }}
        </p>
      </div>
      <div v-if="converting.stage === 'converting'" class="wm-upload-bar" role="progressbar" aria-label="Preparing the video" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(converting.progress * 100)">
        <span :style="{ width: `${Math.round(converting.progress * 100)}%` }"></span>
      </div>
      <p class="text-[13px] text-muted">{{ converting.note }} Keep this page open; your words stay as they are.</p>
      <button type="button" class="wm-btn wm-btn-secondary !min-h-10 self-start !px-4 !text-[13px]" @click="stop">
        <i class="bi bi-x-lg" aria-hidden="true"></i>Stop
      </button>
    </div>

    <div v-else-if="checking" class="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-xl bg-[#E2E8F0] text-muted" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>
      <span class="text-[14px]">Checking your video…</span>
    </div>

    <figure v-else-if="modelValue" class="overflow-hidden rounded-xl bg-[#0B1220]">
      <video
        :key="modelValue.key"
        :src="modelValue.url"
        :poster="modelValue.posterUrl || undefined"
        controls
        playsinline
        preload="metadata"
        class="block max-h-[46vh] w-full object-contain"
        :aria-label="`Your video, ${formatDuration(modelValue.duration)} long`"
      ></video>
      <figcaption class="flex flex-wrap items-center justify-between gap-2 bg-white px-1 pt-2">
        <span class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
          <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>
          <span class="tabular-nums">{{ formatDuration(modelValue.duration) }}</span><span aria-hidden="true">·</span><span class="tabular-nums">{{ formatBytes(modelValue.size) }}</span>
          <template v-if="modelValue.converted"><span aria-hidden="true">·</span><span>prepared for every phone</span></template>
          <template v-if="modelValue.silent"><span aria-hidden="true">·</span><span>no sound</span></template>
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
import { VIDEO_ACCEPT, checkVideo, makePoster, formatBytes, formatDuration, tooLongMessage, videoLog } from '@/utils/video.js';
import { videoLimits } from '@/stores/video.js';

/**
 * Choosing one video for a post: checked (type, size, length) and shown
 * before anything is uploaded. Videos that aren't already a small H.264 MP4
 * are converted on the phone first, where its browser can. v-model holds
 * { key, file, url, poster, posterUrl, duration, width, height, size, converted, silent } or null.
 */
const props = defineProps({
  modelValue: { type: Object, default: null },
  inputId: { type: String, required: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'error', 'checking']);

const checking = ref(false);
const converting = ref(null);   // { stage: 'reading'|'converting', progress, note }
let control = null;
let keys = 0;
let generation = 0;

async function pick(e) {
  const original = e.target.files?.[0];
  e.target.value = '';
  if (!original) return;
  emit('error', '');
  const mine = ++generation;
  checking.value = true;
  emit('checking', true);
  const limits = videoLimits.value;
  let url = URL.createObjectURL(original);
  let file = original;
  let converted = false;
  let silent = false;
  try {
    let result = await checkVideo(original, url, limits);
    if (mine !== generation) return;
    videoLog('chosen', { name: original.name, type: original.type, bytes: original.size, fastPath: !!result.ok, convert: !!result.convert, error: result.error || null });

    if (!result.ok && result.convert) {
      URL.revokeObjectURL(url);
      url = null;
      const made = await prepare(original, limits, mine);
      if (!made) return;
      ({ file, silent } = made);
      converted = true;
      url = URL.createObjectURL(file);
      // The converted file goes through the same checks as any other
      result = await checkVideo(file, url, limits);
      if (mine !== generation) return;
      if (!result.ok) {
        emit('error', result.error || 'This video couldn’t be prepared on this phone. Try another video.');
        return;
      }
    }
    if (!result.ok) {
      emit('error', result.error);
      return;
    }

    // A still for the card before it plays (not every phone can make one)
    const poster = await makePoster(url);
    if (mine !== generation) return;
    release(props.modelValue);
    emit('update:modelValue', {
      key: ++keys,
      file,
      url,
      poster,
      posterUrl: poster ? URL.createObjectURL(poster) : null,
      duration: result.duration,
      width: result.width,
      height: result.height,
      size: file.size,
      converted,
      silent,
    });
    url = null; // now owned by the chosen video
  } finally {
    if (url) URL.revokeObjectURL(url);
    if (mine === generation) {
      checking.value = false;
      converting.value = null;
      emit('checking', false);
    }
  }
}

/**
 * Converts on the phone. Returns { file, silent } or null (refused, stopped
 * or failed: the reason has been shown). Nothing incomplete is ever returned.
 */
async function prepare(original, { maxMb, maxSeconds }, mine) {
  converting.value = { stage: 'reading', progress: 0, note: 'We’re making it playable on every phone.' };
  let tools;
  try {
    // Only downloaded when a video needs it
    tools = await import('@/utils/videoConvert.js');
  } catch (e) {
    videoLog('converter did not load', { error: String(e?.message || e) }, true);
    emit('error', 'The video converter couldn’t load. Check your connection and try again.');
    return null;
  }
  const maxBytes = maxMb * 1024 * 1024;
  let info;
  try {
    info = await tools.probe(original);
  } catch (e) {
    videoLog('could not read', { error: String(e?.message || e) }, true);
    emit('error', 'We couldn’t read this video. It may be damaged or in a format we can’t use. Try another video.');
    return null;
  }
  if (mine !== generation) return null;
  if (Number.isFinite(info.duration) && info.duration > maxSeconds + 0.5) {
    emit('error', tooLongMessage(info.duration, maxSeconds));
    return null;
  }
  videoLog('read', { container: info.container, seconds: info.duration, video: info.video, audio: info.audio,
    webcodecs: typeof VideoEncoder !== 'undefined', userAgent: navigator.userAgent, deviceMemoryGb: navigator.deviceMemory || null });
  let chosen = await tools.plan(info, original, { maxBytes, maxSeconds });
  if (mine !== generation) return null;
  videoLog('plan', { action: chosen.action, reason: chosen.reason || null, remuxOnly: !!chosen.remuxOnly, video: chosen.video || null, audio: chosen.audio || null });
  if (chosen.action !== 'convert') {
    emit('error', chosen.message);
    return null;
  }
  if (chosen.needsAudioConsent && !window.confirm('This video’s sound can’t be converted on this phone. Share it without sound?')) {
    emit('error', 'Choose another video, or share this one without sound.');
    return null;
  }

  converting.value = {
    stage: 'converting',
    progress: 0,
    note: chosen.remuxOnly ? 'Saving it as MP4 (quick).'
      : `Making it ${chosen.target.scaled ? 'smaller and ' : ''}playable on every phone. This can take about as long as the video itself.`,
  };
  // At most two tries: if the first result is still too big, once more at a lower bitrate
  for (let attempt = 0; attempt < 2; attempt++) {
    control = new AbortController();
    const started = Date.now();
    let file;
    try {
      file = await tools.convert(original, chosen, {
        signal: control.signal,
        // Whole percents only: the screen doesn't need redrawing for every frame
        onProgress: (p) => {
          if (converting.value && Math.floor(p * 100) !== Math.floor(converting.value.progress * 100)) converting.value.progress = p;
        },
      });
    } catch (e) {
      if (e?.name === 'AbortError') {
        videoLog('stopped', { afterSeconds: (Date.now() - started) / 1000 });
        if (mine === generation) emit('error', 'Stopped. Choose the video again when you’re ready.');
      } else {
        videoLog('conversion failed', { name: e?.name, code: e?.code || null, error: String(e?.message || e), afterSeconds: (Date.now() - started) / 1000 }, true);
        emit('error', e?.code === 'audio_not_convertible'
          ? 'This video’s sound can’t be converted on this phone. Try another video.'
          : 'This video couldn’t be prepared on this phone. Try another video, or record a new one with your phone’s camera.');
      }
      return null;
    } finally {
      control = null;
    }
    if (mine !== generation) return null;
    videoLog('converted', { attempt: attempt + 1, seconds: (Date.now() - started) / 1000, inputBytes: original.size, outputBytes: file.size, limitBytes: maxBytes });
    if (file.size <= maxBytes) return { file, silent: !info.audio || chosen.audio === 'drop' };
    if (!chosen.video) {
      emit('error', `This video is ${formatBytes(file.size)}. Videos can be up to ${maxMb} MB. Try a shorter clip.`);
      return null;
    }
    chosen = { ...chosen, video: { ...chosen.video, bitrate: Math.floor(chosen.video.bitrate * (maxBytes / file.size) * 0.85) } };
    converting.value = { stage: 'converting', progress: 0, note: 'Making it a little smaller…' };
  }
  emit('error', `We couldn’t make this video small enough (up to ${maxMb} MB). Try a shorter clip.`);
  return null;
}

/** Stops checking or converting (the caption is untouched) */
function stop() {
  generation++;
  control?.abort();
  checking.value = false;
  converting.value = null;
  emit('checking', false);
  emit('error', 'Stopped. Choose the video again when you’re ready.');
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
});
defineExpose({ release, stop, busy: () => checking.value || !!converting.value });
</script>
