<template>
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
      <!-- Hovered column wash -->
      <rect
        v-if="active != null"
        :x="colX(active)" :y="pad.t" :width="colW" :height="plotBottom - pad.t"
        fill="#F1F5F9" rx="4"
      />

      <g v-for="t in scale.ticks" :key="t">
        <line :x1="pad.l" :x2="width - pad.r" :y1="y(t)" :y2="y(t)" :stroke="t === 0 ? CHART_INK.axis : CHART_INK.grid" stroke-width="1" />
        <text :x="pad.l - 8" :y="y(t) + 4" text-anchor="end" font-size="11" :fill="CHART_INK.label" class="tabular-nums">{{ formatTick(t) }}</text>
      </g>

      <g v-for="(_, i) in labels" :key="`c${i}`">
        <path v-for="b in bars(i)" :key="b.key" :d="b.d" :fill="b.color" />
      </g>

      <text
        v-for="i in xTicks" :key="`x${i}`"
        :x="colX(i) + colW / 2" :y="height - 6" font-size="11" :fill="CHART_INK.label" text-anchor="middle"
      >{{ labels[i] }}</text>

      <!-- Whole-column hit areas: bigger than the bars -->
      <rect
        v-for="(_, i) in labels" :key="`h${i}`"
        :x="colX(i)" :y="0" :width="colW" :height="height"
        fill="transparent"
        @pointerenter="active = i; keyboard = false"
        @pointerleave="clear"
      />
    </svg>

    <ChartTooltip
      v-if="active != null && width"
      :title="tooltipTitles?.[active] || labels[active]"
      :note="tooltipNotes?.[active] || ''"
      :rows="tooltipRows(active)"
      :x="colX(active) + colW / 2"
      :width="width"
    />
    <p v-if="keyboard && active != null" class="sr-only" aria-live="polite">
      {{ tooltipTitles?.[active] || labels[active] }}: {{ tooltipRows(active).map(r => `${r.label} ${r.value}`).join(', ') }}
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import ChartTooltip from './ChartTooltip.vue';
import { useChartFrame } from './useChartFrame.js';
import { CHART_INK, niceScale, xTickIndices, roundedTopRect } from './chartUtils.js';

const props = defineProps({
  labels:        { type: Array, required: true },
  tooltipTitles: { type: Array, default: null },
  tooltipNotes:  { type: Array, default: null },    // e.g. "Partial week (6 days)"
  series:        { type: Array, required: true },   // [{ key, label, color, values }]
  stacked:       { type: Boolean, default: false },
  height:        { type: Number, default: 220 },
  formatTick:    { type: Function, default: v => String(v) },
  formatValue:   { type: Function, default: v => String(v) },
  ariaLabel:     { type: String, required: true },
});

const n = () => props.labels.length;
const { wrap, width, active, keyboard, onKeydown, onFocus, clear } = useChartFrame(n);

const GAP = 2;   // surface gap between adjacent bars and stacked segments

const max = computed(() => (props.stacked
  ? Math.max(0, ...props.labels.map((_, i) => props.series.reduce((s, ser) => s + (ser.values[i] || 0), 0)))
  : Math.max(0, ...props.series.flatMap(s => s.values))));
const scale = computed(() => niceScale(max.value));
const pad = computed(() => ({
  l: Math.max(...scale.value.ticks.map(t => props.formatTick(t).length)) * 6.6 + 14,
  r: 8, t: 10, b: 26,
}));
const plotBottom = computed(() => props.height - pad.value.b);
const colW = computed(() => (width.value - pad.value.l - pad.value.r) / Math.max(n(), 1));
const colX = (i) => pad.value.l + i * colW.value;
const y = (v) => plotBottom.value - (v / scale.value.top) * (plotBottom.value - pad.value.t);
const h = (v) => (v / scale.value.top) * (plotBottom.value - pad.value.t);

const xTicks = computed(() => xTickIndices(n(), width.value < 420 ? 4 : 7));

function bars(i) {
  const inner = colW.value * (props.stacked ? 0.56 : 0.72);
  const left = colX(i) + (colW.value - inner) / 2;
  if (props.stacked) {
    const w = Math.min(inner, 32);
    const x0 = colX(i) + (colW.value - w) / 2;
    let base = plotBottom.value;
    const visible = props.series.filter(s => (s.values[i] || 0) > 0);
    return visible.map((s, k) => {
      const segH = h(s.values[i]);
      const top = base - segH;
      // Gap above every segment except the top; only the top segment gets the rounded data end
      const isTop = k === visible.length - 1;
      const drawH = Math.max(segH - (isTop ? 0 : GAP), 0.5);
      const d = isTop ? roundedTopRect(x0, top, w, drawH) : `M${x0},${top + GAP}h${w}v${drawH}h${-w}Z`;
      base = top;
      return { key: s.key, color: s.color, d };
    });
  }
  const k = props.series.length;
  const w = Math.min((inner - GAP * (k - 1)) / k, 22);
  const groupW = w * k + GAP * (k - 1);
  const x0 = colX(i) + (colW.value - groupW) / 2;
  return props.series.map((s, j) => {
    const v = s.values[i] || 0;
    return { key: s.key, color: s.color, d: roundedTopRect(x0 + j * (w + GAP), y(v), w, h(v)) };
  });
}

function tooltipRows(i) {
  const rows = props.series.map(s => ({ label: s.label, color: s.color, value: props.formatValue(s.values[i] || 0) }));
  if (props.stacked) {
    const total = props.series.reduce((sum, s) => sum + (s.values[i] || 0), 0);
    rows.push({ label: 'Total', color: 'transparent', value: props.formatValue(total) });
  }
  return rows;
}
</script>
