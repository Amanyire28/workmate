<template>
  <div>
    <!-- The file box; the composer's "Add video" and "Replace" buttons are labels for it -->
    <input :id="inputId" type="file" :accept="VIDEO_ACCEPT" class="sr-only" :disabled="disabled" @change="pick" />

    <div v-if="checking" class="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-xl bg-[#E2E8F0] text-muted" role="status">
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
        <span class="flex items-center gap-1.5 text-[13px] text-muted">
          <i class="bi bi-camera-video text-primary" aria-hidden="true"></i>
          <span class="tabular-nums">{{ formatDuration(modelValue.duration) }}</span><span aria-hidden="true">·</span><span class="tabular-nums">{{ formatBytes(modelValue.size) }}</span>
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
import { VIDEO_ACCEPT, checkVideo, makePoster, formatBytes, formatDuration } from '@/utils/video.js';
import { videoLimits } from '@/stores/video.js';

/**
 * Choosing one video for a post: checked (type, size, length) and shown
 * before anything is uploaded. v-model holds
 * { key, file, url, poster, posterUrl, duration, width, height, size } or null.
 */
const props = defineProps({
  modelValue: { type: Object, default: null },
  inputId: { type: String, required: true },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'error', 'checking']);

const checking = ref(false);
let keys = 0;
let generation = 0;

async function pick(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  emit('error', '');
  const mine = ++generation;
  checking.value = true;
  emit('checking', true);
  const url = URL.createObjectURL(file);
  try {
    const result = await checkVideo(file, url, videoLimits.value);
    if (mine !== generation) { URL.revokeObjectURL(url); return; }
    if (!result.ok) {
      URL.revokeObjectURL(url);
      emit('error', result.error);
      return;
    }
    // A still for the card before it plays (not every phone can make one)
    const poster = await makePoster(url);
    if (mine !== generation) { URL.revokeObjectURL(url); return; }
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
    });
  } finally {
    if (mine === generation) {
      checking.value = false;
      emit('checking', false);
    }
  }
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

onBeforeUnmount(() => { generation++; });
defineExpose({ release });
</script>
