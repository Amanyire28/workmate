<template>
  <!-- Shared loading / empty / error / restricted state for cards, tables and pages -->
  <div
    class="flex flex-col items-center justify-center text-center gap-2 px-4"
    :class="compact ? 'py-6' : 'py-12'"
    :role="kind === 'error' ? 'alert' : 'status'"
    :aria-busy="kind === 'loading' ? 'true' : undefined"
  >
    <span v-if="kind === 'loading'" class="wm-spinner !w-6 !h-6 text-primary" aria-hidden="true"></span>
    <span
      v-else
      class="inline-flex items-center justify-center w-11 h-11 rounded-full text-xl"
      :class="{
        'bg-[#FDECEC] text-error': kind === 'error',
        'bg-[#F1F5F9] text-[#64748B]': kind === 'empty' || kind === 'restricted',
      }"
      aria-hidden="true"
    ><i :class="['bi', icon || defaultIcon]"></i></span>
    <p class="text-sm font-semibold text-ink">{{ title }}</p>
    <p v-if="message" class="text-[13px] text-[#64748B] max-w-[420px]">{{ message }}</p>
    <div v-if="$slots.default" class="mt-1 flex flex-wrap justify-center gap-2"><slot /></div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  kind:    { type: String, default: 'empty' },   // loading | empty | error | restricted
  title:   { type: String, required: true },
  message: { type: String, default: '' },
  icon:    { type: String, default: '' },
  compact: { type: Boolean, default: false },
});

const defaultIcon = computed(() => ({
  empty: 'bi-inbox', error: 'bi-exclamation-triangle', restricted: 'bi-lock',
})[props.kind] || 'bi-inbox');
</script>
