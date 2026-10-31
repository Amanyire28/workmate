<template>
  <div class="flex flex-col items-center justify-center text-center gap-3 py-12 px-4" :role="role">
    <span
      class="inline-flex items-center justify-center w-20 h-20 rounded-full text-[34px]"
      :class="tone === 'error' ? 'bg-error-tint text-error' : 'bg-primary-tint text-primary'"
      aria-hidden="true"
    >
      <i :class="['bi', icon]"></i>
    </span>
    <h2 class="text-xl font-semibold text-ink">{{ title }}</h2>
    <p v-if="message" class="text-sm text-muted max-w-[320px]">{{ message }}</p>
    <div v-if="$slots.default" class="mt-1 flex flex-wrap justify-center gap-2">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  icon:    { type: String, default: 'bi-inbox' },
  title:   { type: String, required: true },
  message: { type: String, default: '' },
  tone:    { type: String, default: 'neutral' },  // neutral | error
});

const role = computed(() => (props.tone === 'error' ? 'alert' : 'status'));
</script>
