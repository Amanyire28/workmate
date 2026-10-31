<template>
  <nav v-if="total" class="flex flex-wrap items-center justify-between gap-3 text-[13px] text-[#64748B]" :aria-label="`${label} pages`">
    <p aria-live="polite">
      Showing <span class="font-medium text-ink tabular-nums">{{ formatNumber(from) }}–{{ formatNumber(to) }}</span> of
      <span class="font-medium text-ink tabular-nums">{{ formatNumber(total) }}</span>
    </p>
    <div class="flex items-center gap-1">
      <button
        type="button"
        class="wm-icon-btn !w-9 !h-9 !text-base wm-surface disabled:opacity-40 disabled:cursor-not-allowed"
        :disabled="page <= 1"
        aria-label="Previous page"
        @click="$emit('update:page', page - 1)"
      ><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
      <span class="px-2 tabular-nums">Page {{ formatNumber(page) }} of {{ formatNumber(pages) }}</span>
      <button
        type="button"
        class="wm-icon-btn !w-9 !h-9 !text-base wm-surface disabled:opacity-40 disabled:cursor-not-allowed"
        :disabled="page >= pages"
        aria-label="Next page"
        @click="$emit('update:page', page + 1)"
      ><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    </div>
  </nav>
</template>

<script setup>
import { computed } from 'vue';
import { formatNumber } from '../utils/format.js';

const props = defineProps({
  page:     { type: Number, required: true },
  pageSize: { type: Number, required: true },
  total:    { type: Number, required: true },
  label:    { type: String, default: 'Results' },
});
defineEmits(['update:page']);

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.pageSize)));
const from = computed(() => (props.page - 1) * props.pageSize + 1);
const to = computed(() => Math.min(props.page * props.pageSize, props.total));
</script>
