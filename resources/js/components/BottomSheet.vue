<template>
  <Teleport to="body">
    <Transition name="wm-sheet">
      <div v-if="open" class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center sm:p-6" @keydown.esc="$emit('close')">
        <div class="wm-sheet-backdrop absolute inset-0 bg-ink/35 backdrop-blur-[2px]" aria-hidden="true" @click="$emit('close')"></div>

        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="wm-sheet-panel wm-glass-strong relative w-full sm:max-w-lg max-h-[88dvh] flex flex-col rounded-t-[28px] sm:rounded-[28px] !bg-white/85 !shadow-[0_-8px_40px_rgba(15,23,42,0.18),inset_0_1px_0_#fff] focus:outline-none"
          @keydown.tab="trapFocus"
        >
          <div class="flex items-center justify-between gap-4 px-5 pt-3 pb-2">
            <h2 :id="titleId" class="wm-h2 !text-xl">{{ title }}</h2>
            <button type="button" class="wm-icon-btn -mr-2" aria-label="Close" @click="$emit('close')">
              <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
            </button>
          </div>

          <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-5">
            <slot />
          </div>

          <div v-if="$slots.footer" class="flex gap-3 px-5 pt-3 pb-[calc(12px+var(--wm-safe-bottom))] sm:pb-4 border-t border-line">
            <slot name="footer" />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onBeforeUnmount, useId } from 'vue';

const props = defineProps({
  open:  { type: Boolean, default: false },
  title: { type: String, required: true },
});
defineEmits(['close']);

const titleId = useId();
const panel = ref(null);
let returnFocusTo = null;

watch(() => props.open, async (isOpen) => {
  if (isOpen) {
    returnFocusTo = document.activeElement;
    document.documentElement.style.overflow = 'hidden';
    await nextTick();
    panel.value?.focus();
  } else {
    document.documentElement.style.overflow = '';
    returnFocusTo?.focus?.();
  }
});

onBeforeUnmount(() => { document.documentElement.style.overflow = ''; });

function trapFocus(e) {
  const focusable = panel.value?.querySelectorAll(
    'button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
  );
  if (!focusable?.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) {
    e.preventDefault(); last.focus();
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault(); first.focus();
  }
}
</script>

<style scoped>
.wm-sheet-enter-active, .wm-sheet-leave-active { transition: opacity 0.2s ease; }
.wm-sheet-enter-active .wm-sheet-panel, .wm-sheet-leave-active .wm-sheet-panel { transition: transform 0.25s ease; }
.wm-sheet-enter-from, .wm-sheet-leave-to { opacity: 0; }
.wm-sheet-enter-from .wm-sheet-panel, .wm-sheet-leave-to .wm-sheet-panel { transform: translateY(100%); }
</style>
