<template>
  <Teleport to="body">
    <Transition name="wm-drawer">
      <div v-if="open" class="fixed inset-x-0 z-[120] flex justify-end" :style="{ top: 'var(--wm-vvt, 0px)', height: 'var(--wm-vvh, 100dvh)' }">
        <div class="wm-drawer-backdrop absolute inset-0 bg-[#0F172A]/30" aria-hidden="true" @click="$emit('close')"></div>
        <aside
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          tabindex="-1"
          class="wm-drawer-panel wm-glass-pop relative flex h-full w-full min-w-0 max-w-[520px] flex-col !border-y-0 !border-r-0 focus:outline-none"
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
          <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 [overflow-wrap:anywhere]">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="flex flex-wrap gap-2 border-t border-[#E2E8F0] px-5 pt-3 pb-[calc(12px+env(safe-area-inset-bottom,0px))]">
            <slot name="footer" />
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, toRef, useId } from 'vue';
import { useModalBehaviour } from '@/components/useModalBehaviour.js';

const props = defineProps({
  open:    { type: Boolean, default: false },
  title:   { type: String, default: '' },
  eyebrow: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const titleId = useId();
const panel = ref(null);
useModalBehaviour({ open: toRef(props, 'open'), panel, close: () => emit('close') });
</script>

<style scoped>
.wm-drawer-enter-active, .wm-drawer-leave-active { transition: opacity 0.2s ease; }
.wm-drawer-enter-active .wm-drawer-panel, .wm-drawer-leave-active .wm-drawer-panel { transition: transform 0.24s cubic-bezier(0.2, 0.7, 0.2, 1); }
.wm-drawer-enter-from, .wm-drawer-leave-to { opacity: 0; }
.wm-drawer-enter-from .wm-drawer-panel, .wm-drawer-leave-to .wm-drawer-panel { transform: translateX(100%); }
</style>
