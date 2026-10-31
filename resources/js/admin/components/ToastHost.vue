<template>
  <!-- Polite announcements; never claims something reached a server -->
  <div class="pointer-events-none fixed bottom-4 right-4 left-4 z-[160] flex flex-col items-end gap-2 sm:left-auto" aria-live="polite" role="status">
    <TransitionGroup name="wm-toast">
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto flex w-full max-w-[380px] items-start gap-2.5 rounded-xl border border-white/70 bg-[#0F172A]/90 px-4 py-3 text-[13.5px] text-white shadow-[0_12px_32px_rgba(15,23,42,0.3)] backdrop-blur-xl"
      >
        <i :class="['bi mt-0.5', t.tone === 'warning' ? 'bi-exclamation-triangle text-[#FBBF24]' : 'bi-check2-circle text-[#7DD3FC]']" aria-hidden="true"></i>
        <span class="flex-1">{{ t.message }}</span>
        <button type="button" class="-mr-1 text-white/70 hover:text-white" aria-label="Dismiss" @click="dismiss(t.id)">
          <i class="bi bi-x-lg text-[13px]" aria-hidden="true"></i>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { toasts } from '../stores/prototypeChanges.js';

function dismiss(id) {
  const i = toasts.findIndex(t => t.id === id);
  if (i >= 0) toasts.splice(i, 1);
}
</script>

<style scoped>
.wm-toast-enter-active, .wm-toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.wm-toast-enter-from, .wm-toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
