<template>
  <span :class="['wm-pill', `wm-pill-${info.tone}`]">
    {{ info.label }}<template v-if="showDetail && info.detail"> · {{ info.detail }}</template>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { availabilityInfo } from '@/utils/trust.js';

const props = defineProps({
  provider:   { type: Object, required: true },
  showDetail: { type: Boolean, default: false },
});

const info = computed(() => availabilityInfo(props.provider));
</script>
