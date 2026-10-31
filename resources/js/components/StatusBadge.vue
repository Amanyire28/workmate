<template>
  <span :class="['wm-pill', `wm-pill-${info.tone}`]">{{ info.label }}</span>
</template>

<script setup>
import { computed } from 'vue';
import { statusInfo } from '@/utils/ui.js';

const props = defineProps({
  /** A status key from statusLabels in stores/catalog.js, e.g. 'open', 'pending' */
  status: { type: String, required: true },
});

const info = computed(() => statusInfo(props.status));
</script>
