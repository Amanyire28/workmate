<template>
  <Teleport to="body">
    <div
      ref="root"
      class="fixed inset-0 z-[120] flex flex-col bg-[#0B1220]/95 text-white"
      role="dialog"
      aria-modal="true"
      :aria-label="label"
      tabindex="-1"
      @keydown.esc="$emit('close')"
      @keydown.left="go(-1)"
      @keydown.right="go(1)"
    >
      <div class="flex items-center justify-between px-3 pt-[calc(8px+env(safe-area-inset-top))] pb-2">
        <p class="pl-2 text-sm text-white/80" aria-live="polite">{{ index + 1 }} / {{ media.length }}</p>
        <button ref="closeBtn" type="button" class="wm-icon-btn !text-white hover:!bg-white/10" aria-label="Close photos" @click="$emit('close')">
          <i class="bi bi-x-lg text-xl" aria-hidden="true"></i>
        </button>
      </div>

      <div class="relative flex min-h-0 flex-1 items-center justify-center px-2 pb-[calc(16px+env(safe-area-inset-bottom))]"
        @touchstart.passive="touchStart" @touchend.passive="touchEnd" @click.self="$emit('close')">
        <img :key="current.id" :src="current.url" :width="current.width" :height="current.height" alt=""
          class="max-h-full max-w-full rounded-lg object-contain select-none" decoding="async" />

        <template v-if="media.length > 1">
          <button type="button" class="absolute left-2 top-1/2 -translate-y-1/2 hidden sm:inline-flex wm-icon-btn !bg-white/10 !text-white hover:!bg-white/20"
            :disabled="index === 0" aria-label="Previous photo" @click="go(-1)">
            <i class="bi bi-chevron-left text-xl" aria-hidden="true"></i>
          </button>
          <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 hidden sm:inline-flex wm-icon-btn !bg-white/10 !text-white hover:!bg-white/20"
            :disabled="index === media.length - 1" aria-label="Next photo" @click="go(1)">
            <i class="bi bi-chevron-right text-xl" aria-hidden="true"></i>
          </button>
        </template>
      </div>

      <!-- Dots on phones, where people swipe -->
      <div v-if="media.length > 1" class="flex justify-center gap-1.5 pb-[calc(14px+env(safe-area-inset-bottom))] sm:hidden" aria-hidden="true">
        <span v-for="(m, i) in media" :key="m.id" class="h-1.5 w-1.5 rounded-full" :class="i === index ? 'bg-white' : 'bg-white/35'"></span>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
  media: { type: Array, required: true },
  start: { type: Number, default: 0 },
  label: { type: String, default: 'Photos' },
});
defineEmits(['close']);

const index = ref(props.start);
const current = computed(() => props.media[index.value]);
const root = ref(null);
const closeBtn = ref(null);

function go(step) {
  index.value = Math.min(props.media.length - 1, Math.max(0, index.value + step));
}

let startX = null;
function touchStart(e) { startX = e.changedTouches[0].clientX; }
function touchEnd(e) {
  if (startX === null) return;
  const dx = e.changedTouches[0].clientX - startX;
  if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
  startX = null;
}

// The page behind doesn't scroll; focus comes back to where it was
let returnFocus = null;
let overflow = '';
onMounted(async () => {
  returnFocus = document.activeElement;
  overflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  await nextTick();
  closeBtn.value?.focus();
});
onBeforeUnmount(() => {
  document.body.style.overflow = overflow;
  returnFocus?.focus?.();
});
</script>
