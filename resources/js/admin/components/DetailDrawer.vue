<template>
  <Teleport to="body">
    <Transition name="wm-drawer">
      <div v-if="open" class="fixed inset-0 z-[120] flex justify-end" @keydown.esc="$emit('close')">
        <div class="wm-drawer-backdrop absolute inset-0 bg-[#0F172A]/30" aria-hidden="true" @click="$emit('close')"></div>
        <aside
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="wm-drawer-panel wm-glass-pop relative flex h-full w-full max-w-[520px] flex-col !border-y-0 !border-r-0 focus:outline-none"
          @keydown.tab="trapFocus"
        >
          <header class="flex items-start justify-between gap-3 border-b border-[#E2E8F0] px-5 py-4">
            <div class="min-w-0">
              <p v-if="eyebrow" class="text-[12px] font-medium uppercase tracking-wide text-[#64748B]">{{ eyebrow }}</p>
              <h2 :id="titleId" class="mt-0.5 text-lg font-semibold text-ink leading-snug">{{ title }}</h2>
              <slot name="subtitle" />
            </div>
            <button type="button" class="wm-icon-btn -mr-2 shrink-0" aria-label="Close" @click="$emit('close')">
              <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
            </button>
          </header>
          <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-4">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="flex flex-wrap gap-2 border-t border-[#E2E8F0] px-5 py-3">
            <slot name="footer" />
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onBeforeUnmount, useId } from 'vue';

const props = defineProps({
  open:    { type: Boolean, default: false },
  title:   { type: String, default: '' },
  eyebrow: { type: String, default: '' },
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
  const focusable = panel.value?.querySelectorAll('button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])');
  if (!focusable?.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
</script>

<style scoped>
.wm-drawer-enter-active, .wm-drawer-leave-active { transition: opacity 0.2s ease; }
.wm-drawer-enter-active .wm-drawer-panel, .wm-drawer-leave-active .wm-drawer-panel { transition: transform 0.24s cubic-bezier(0.2, 0.7, 0.2, 1); }
.wm-drawer-enter-from, .wm-drawer-leave-to { opacity: 0; }
.wm-drawer-enter-from .wm-drawer-panel, .wm-drawer-leave-to .wm-drawer-panel { transform: translateX(100%); }
</style>
