<template>
  <section class="flex flex-col gap-3 rounded-xl bg-primary-tint/60 p-3.5" :aria-labelledby="`${uid}-title`">
    <div>
      <h3 :id="`${uid}-title`" class="text-[15px] font-semibold text-ink">Choose the part to share</h3>
      <p class="mt-0.5 text-[13px] text-muted">This video is {{ formatBytes(size) }}. Videos can be up to {{ formatBytes(maxBytes) }}, so keep a shorter part.</p>
    </div>

    <video
      v-if="playable"
      ref="preview"
      :src="url"
      controls
      playsinline
      preload="metadata"
      class="block max-h-[38vh] w-full rounded-lg bg-[#0B1220] object-contain"
      aria-label="Your video"
    ></video>
    <p v-else class="wm-notice wm-notice-info !text-[13px]">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>This browser can’t show this video, but it can still trim it. Choose the times below.</span>
    </p>

    <div class="flex flex-col gap-2.5">
      <label class="flex flex-col gap-1">
        <span class="flex justify-between text-[13px]"><span class="font-semibold text-ink">Start</span><span class="tabular-nums text-muted">{{ clock(start) }}</span></span>
        <input v-model.number="start" type="range" min="0" :max="duration" step="0.1" class="wm-trim-range" :aria-valuetext="clock(start)" @input="moved('start')" />
      </label>
      <label class="flex flex-col gap-1">
        <span class="flex justify-between text-[13px]"><span class="font-semibold text-ink">End</span><span class="tabular-nums text-muted">{{ clock(end) }}</span></span>
        <input v-model.number="end" type="range" min="0" :max="duration" step="0.1" class="wm-trim-range" :aria-valuetext="clock(end)" @input="moved('end')" />
      </label>
    </div>

    <p class="text-[13px]" :class="tooBig ? 'text-error font-semibold' : 'text-ink'" aria-live="polite">
      Keeping {{ clock(end - start) }} · about {{ formatBytes(estimate) }}
      <template v-if="tooBig"> · too big: make it shorter</template>
    </p>
    <p v-if="note" class="wm-notice wm-notice-warning !text-[13px]" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ note }}</span>
    </p>

    <p class="text-[12.5px] text-muted">The picture and sound stay exactly as they are. The cut is made at the nearest key frame, so the clip may start a second or two earlier.</p>

    <div class="flex gap-2">
      <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="$emit('cancel')">Cancel</button>
      <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-[2]" :disabled="tooBig || end - start < 1" @click="$emit('trim', { start, end })">
        <i class="bi bi-scissors" aria-hidden="true"></i>Trim video
      </button>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onMounted, useId } from 'vue';
import { formatBytes } from '@/utils/video.js';

/**
 * Choosing the part of a too-big video to keep. Only the times are chosen
 * here; VideoPicker makes the real (shorter) file and checks its real size.
 */
const props = defineProps({
  url: { type: String, required: true },
  size: { type: Number, required: true },
  duration: { type: Number, required: true },
  maxBytes: { type: Number, required: true },
  playable: { type: Boolean, default: true },
  // Why the last try didn't work (e.g. still too big)
  note: { type: String, default: '' },
  // The last choice, to adjust
  initial: { type: Object, default: null },
});
defineEmits(['trim', 'cancel']);

const uid = useId();
const preview = ref(null);
// The file's own rate: a fair estimate (the result is measured anyway)
const bytesPerSecond = props.size / props.duration;
const fits = Math.max(1, (props.maxBytes * 0.9) / bytesPerSecond);

const start = ref(props.initial ? props.initial.start : 0);
const end = ref(props.initial ? props.initial.end : Math.min(props.duration, fits));

const estimate = computed(() => Math.max(0, end.value - start.value) * bytesPerSecond);
const tooBig = computed(() => estimate.value > props.maxBytes * 0.97);

function clock(seconds) {
  const s = Math.max(0, seconds || 0);
  const m = Math.floor(s / 60);
  return `${m}:${(s - m * 60).toFixed(1).padStart(4, '0')}`;
}

/** Keeps start before end, and shows that moment in the preview */
function moved(which) {
  if (which === 'start' && start.value > end.value - 1) end.value = Math.min(props.duration, start.value + 1);
  if (which === 'end' && end.value < start.value + 1) start.value = Math.max(0, end.value - 1);
  const v = preview.value;
  if (v && Number.isFinite(v.duration)) {
    v.pause();
    v.currentTime = which === 'start' ? start.value : end.value;
  }
}

onMounted(() => {
  if (preview.value) preview.value.currentTime = start.value;
});
</script>
