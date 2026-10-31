<template>
  <!-- Pointer and arrow keys move the crosshair; the card's table view holds every value too -->
  <div
    ref="wrap"
    class="relative rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    :style="{ height: `${height}px` }"
    tabindex="0"
    role="group"
    aria-roledescription="chart"
    :aria-label="`${ariaLabel}. Use left and right arrow keys to read values.`"
    @keydown="onKeydown"
    @focus="onFocus"
    @blur="clear"
  >
    <svg v-if="width" :width="width" :height="height" class="block" aria-hidden="true">
      <g v-for="t in scale.ticks" :key="t">
        <line :x1="pad.l" :x2="width - pad.r" :y1="y(t)" :y2="y(t)" :stroke="t === 0 ? CHART_INK.axis : CHART_INK.grid" stroke-width="1" />
        <text :x="pad.l - 8" :y="y(t) + 4" text-anchor="end" font-size="11" :fill="CHART_INK.label" class="tabular-nums">{{ formatTick(t) }}</text>
      </g>
      <text
        v-for="i in xTicks" :key="`x${i}`"
        :x="x(i)" :y="height - 6" font-size="11" :fill="CHART_INK.label"
        :text-anchor="i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'"
      >{{ labels[i] }}</text>

      <line v-if="active != null" :x1="x(active)" :x2="x(active)" :y1="pad.t" :y2="plotBottom" :stroke="CHART_INK.axis" stroke-width="1" />

      <path
        v-for="s in series" :key="s.key"
        :d="path(s.values)" fill="none" :stroke="s.color" stroke-width="2"
        stroke-linejoin="round" stroke-linecap="round"
      />

      <template v-if="active != null">
        <circle
          v-for="s in series" :key="`dot${s.key}`"
          :cx="x(active)" :cy="y(s.values[active] ?? 0)" r="4"
          :fill="s.color" stroke="#fff" stroke-width="2"
        />
      </template>

      <rect
        :x="pad.l" :y="0" :width="Math.max(0, width - pad.l - pad.r)" :height="height"
        fill="transparent"
        @pointermove="onPointer"
        @pointerleave="clear"
      />
    </svg>

    <ChartTooltip
      v-if="active != null && width"
      :title="tooltipTitles?.[active] || labels[active]"
      :rows="series.map(s => ({ label: s.label, color: s.color, value: formatValue(s.values[active]) }))"
      :x="x(active)"
      :width="width"
    />
    <p v-if="keyboard && active != null" class="sr-only" aria-live="polite">
      {{ tooltipTitles?.[active] || labels[active] }}: {{ series.map(s => `${s.label} ${formatValue(s.values[active])}`).join(', ') }}
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import ChartTooltip from './ChartTooltip.vue';
import { useChartFrame } from './useChartFrame.js';
import { CHART_INK, niceScale, xTickIndices } from './chartUtils.js';

const props = defineProps({
  labels:        { type: Array, required: true },              // x labels, one per point
  tooltipTitles: { type: Array, default: null },               // fuller x labels for the tooltip
  series:        { type: Array, required: true },              // [{ key, label, color, values }]
  height:        { type: Number, default: 220 },
  formatTick:    { type: Function, default: v => String(v) },
  formatValue:   { type: Function, default: v => String(v) },
  ariaLabel:     { type: String, required: true },
});

const n = () => props.labels.length;
const { wrap, width, active, keyboard, onKeydown, onFocus, clear } = useChartFrame(n);

const scale = computed(() => niceScale(Math.max(0, ...props.series.flatMap(s => s.values))));
// Left padding fits the widest tick label
const pad = computed(() => ({
  l: Math.max(...scale.value.ticks.map(t => props.formatTick(t).length)) * 6.6 + 14,
  r: 12, t: 10, b: 26,
}));
const plotBottom = computed(() => props.height - pad.value.b);

const x = (i) => pad.value.l + (n() <= 1 ? 0 : (i / (n() - 1)) * (width.value - pad.value.l - pad.value.r));
const y = (v) => plotBottom.value - (v / scale.value.top) * (plotBottom.value - pad.value.t);

const xTicks = computed(() => xTickIndices(n(), width.value < 420 ? 4 : 6));

function path(values) {
  return values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('');
}

function onPointer(e) {
  keyboard.value = false;
  const rect = wrap.value.getBoundingClientRect();
  const px = e.clientX - rect.left - pad.value.l;
  const plotW = width.value - pad.value.l - pad.value.r;
  active.value = Math.min(n() - 1, Math.max(0, Math.round((px / plotW) * (n() - 1))));
}
</script>
