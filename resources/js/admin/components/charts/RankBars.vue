<template>
  <!-- Horizontal ranking: one series, one colour, every value labelled -->
  <ol class="flex flex-col gap-2" :aria-label="ariaLabel">
    <li v-for="row in rows" :key="row.label" class="grid grid-cols-[minmax(84px,34%)_1fr_auto] items-center gap-3 text-[13px]">
      <span class="truncate" :class="row.muted ? 'text-[#64748B]' : 'text-ink'" :title="row.label">{{ row.label }}</span>
      <span class="h-2.5 rounded-full bg-[#F1F5F9]" aria-hidden="true">
        <span
          class="block h-full rounded-full transition-[width] duration-500"
          :style="{ width: `${max ? Math.max((row.value / max) * 100, row.value ? 1.5 : 0) : 0}%`, background: row.muted ? MUTED_FILL : color }"
        ></span>
      </span>
      <span class="min-w-[3.5rem] text-right font-medium text-ink tabular-nums">
        {{ formatValue(row.value) }}<span v-if="showShare && total" class="ml-1 font-normal text-[#64748B]">{{ Math.round((row.value / total) * 100) }}%</span>
      </span>
    </li>
  </ol>
</template>

<script setup>
import { computed } from 'vue';
import { SERIES_COLORS, MUTED_FILL } from './chartUtils.js';

const props = defineProps({
  rows:        { type: Array, required: true },   // [{ label, value, muted? }]
  color:       { type: String, default: SERIES_COLORS[0] },
  formatValue: { type: Function, default: v => String(v) },
  showShare:   { type: Boolean, default: false },
  ariaLabel:   { type: String, required: true },
});

const max = computed(() => Math.max(0, ...props.rows.map(r => r.value)));
const total = computed(() => props.rows.reduce((s, r) => s + r.value, 0));
</script>
