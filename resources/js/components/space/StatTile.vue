<template>
  <!-- One real number. A link when `to` is given (a tap target of at least 44 px); the whole tile is the target -->
  <component
    :is="to ? 'router-link' : 'div'"
    :to="to || undefined"
    class="wm-card flex min-h-[76px] min-w-0 flex-col justify-center gap-0.5 !p-3"
    :class="to ? 'wm-card-link focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary' : ''"
    :aria-label="to && !loading ? `${label}: ${shown}` : undefined"
    :aria-busy="loading ? 'true' : undefined"
  >
    <span v-if="loading" class="wm-skeleton h-6 w-10" aria-hidden="true"></span>
    <span v-else class="text-[22px] font-bold leading-tight text-ink tabular-nums" aria-hidden="true">{{ shown }}</span>
    <span class="break-words text-[13px] leading-tight text-muted" aria-hidden="true">{{ label }}</span>
  </component>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  value: { type: Number, default: null },
  label: { type: String, required: true },
  to: { type: [String, Object], default: null },
  loading: { type: Boolean, default: false },
});

// 1,234 style grouping for the thousands; the exact number is always shown to screen readers
const shown = computed(() => (props.value === null ? '–' : props.value.toLocaleString('en-UG')));
</script>
