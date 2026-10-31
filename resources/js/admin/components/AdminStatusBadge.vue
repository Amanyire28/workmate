<template>
  <!-- Tone colour always comes with a text label, never colour alone -->
  <span :class="['wm-pill', `wm-pill-${tone}`, '!h-6 !text-[11.5px]']">{{ statusLabel(status) }}</span>
</template>

<script setup>
import { computed } from 'vue';
import { STATUS, statusLabel } from '../utils/statuses.js';

const props = defineProps({
  status: { type: String, required: true },
});

const tone = computed(() => STATUS[props.status]?.[1] || 'neutral');
</script>
