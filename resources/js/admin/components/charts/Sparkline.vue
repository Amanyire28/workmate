<template>
  <!-- Shape-only trend for metric cards; the number beside it carries the value -->
  <!-- Stretches to its box; the stroke keeps its width -->
  <svg :viewBox="`0 0 ${w} ${h}`" preserveAspectRatio="none" class="block h-7 w-full overflow-visible" aria-hidden="true">
    <path :d="area" :fill="color" fill-opacity="0.08" />
    <path :d="line" fill="none" :stroke="color" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>

<script setup>
import { computed } from 'vue';
import { SERIES_COLORS } from './chartUtils.js';

const props = defineProps({
  values: { type: Array, required: true },
  color:  { type: String, default: SERIES_COLORS[0] },
});

const w = 96;
const h = 28;
const points = computed(() => {
  const v = props.values;
  const min = Math.min(...v);
  const max = Math.max(...v);
  const span = max - min || 1;
  return v.map((val, i) => [(i / Math.max(v.length - 1, 1)) * w, h - 2 - ((val - min) / span) * (h - 4)]);
});
const line = computed(() => points.value.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(''));
const area = computed(() => `${line.value}L${w},${h}L0,${h}Z`);
</script>
