<template>
  <Teleport to="body">
    <Transition name="wm-sheet">
      <!-- Sized to what is really visible (--wm-vvh), so the keyboard never covers the buttons -->
      <div
        v-if="open"
        class="fixed inset-x-0 z-[100] flex justify-center"
        :class="full ? 'items-stretch sm:items-center sm:p-6' : 'items-end sm:items-center sm:p-6'"
        :style="{ top: 'var(--wm-vvt, 0px)', height: 'var(--wm-vvh, 100dvh)' }"
      >
        <div class="wm-sheet-backdrop absolute inset-0 bg-ink/35 backdrop-blur-[2px]" aria-hidden="true" @click="$emit('close')"></div>

        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="wm-sheet-panel wm-glass-strong relative flex w-full min-w-0 max-w-full flex-col !bg-white/85 !shadow-[0_-8px_40px_rgba(15,23,42,0.18),inset_0_1px_0_#fff] focus:outline-none sm:max-w-lg sm:rounded-[28px]"
          :class="full ? 'h-full rounded-none sm:h-auto sm:max-h-[88%]' : 'max-h-[88%] rounded-t-[28px]'"
        >
          <div class="flex items-center justify-between gap-4 px-5 pb-2" :class="full ? 'pt-[max(12px,env(safe-area-inset-top,0px))] sm:pt-3' : 'pt-3'">
            <h2 :id="titleId" class="wm-h2 min-w-0 break-words !text-xl">{{ title }}</h2>
            <button type="button" class="wm-icon-btn -mr-2 shrink-0" aria-label="Close" @click="$emit('close')">
              <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
            </button>
          </div>

          <!-- Scrolls on its own; long references, file names and amounts wrap instead of widening the sheet -->
          <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 pb-5 [overflow-wrap:anywhere]">
            <slot />
          </div>

          <div v-if="$slots.footer" class="flex flex-wrap gap-3 border-t border-line px-5 pt-3 pb-[calc(12px+var(--wm-safe-bottom))] sm:pb-4 [&>*]:min-w-0 [&_.wm-btn]:whitespace-normal [&_.wm-btn]:px-4 [&_.wm-btn]:text-center">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, toRef, useId } from 'vue';
import { useModalBehaviour } from './useModalBehaviour.js';

/**
 * The app's one bottom sheet. Short interactions use the default; a long form passes `full` to fill the screen on a phone
 * (a centred panel on larger screens). Both scroll inside, keep the footer buttons reachable and respect the safe areas.
 */
const props = defineProps({
  open:  { type: Boolean, default: false },
  title: { type: String, required: true },
  full:  { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const titleId = useId();
const panel = ref(null);
useModalBehaviour({ open: toRef(props, 'open'), panel, close: () => emit('close') });
</script>

<style scoped>
.wm-sheet-enter-active, .wm-sheet-leave-active { transition: opacity 0.2s ease; }
.wm-sheet-enter-active .wm-sheet-panel, .wm-sheet-leave-active .wm-sheet-panel { transition: transform 0.25s ease; }
.wm-sheet-enter-from, .wm-sheet-leave-to { opacity: 0; }
.wm-sheet-enter-from .wm-sheet-panel, .wm-sheet-leave-to .wm-sheet-panel { transform: translateY(100%); }
</style>
