<template>
  <!-- One tick: grey once documents are approved, blue when the system qualifies them -->
  <span v-if="tick" class="inline-flex align-[-2px]" :class="tick.colorClass" title="Verified">
    <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
    <span class="sr-only">Verified</span>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { tickInfo } from '@/utils/trust.js';

const props = defineProps({
  provider: { type: Object, required: true },
});

const tick = computed(() => tickInfo(props.provider));
</script>
