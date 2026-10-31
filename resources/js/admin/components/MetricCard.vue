<template>
  <component
    :is="to ? 'router-link' : 'div'"
    :to="to || undefined"
    class="group flex flex-col rounded-xl wm-glass-card"
    :class="[
      primary ? 'p-4 gap-2' : 'px-3.5 py-3 gap-1',
      to ? 'wm-hover-card cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary' : '',
    ]"
  >
    <p class="flex items-center gap-1.5 text-[13px] font-medium text-[#64748B]">
      <span class="truncate">{{ label }}</span>
      <i v-if="hint" class="bi bi-info-circle text-[12px] text-[#94A3B8]" :title="hint" aria-hidden="true"></i>
      <span v-if="hint" class="sr-only">({{ hint }})</span>
    </p>

    <div class="flex items-end justify-between gap-3">
      <p v-if="available" class="whitespace-nowrap font-semibold text-ink tracking-[-0.01em]" :class="primary ? 'text-[26px] leading-8' : 'text-xl leading-7'">{{ value }}</p>
      <p v-else class="text-[15px] font-medium text-[#64748B]" :title="unavailableReason">Not available<span class="sr-only">: {{ unavailableReason }}</span></p>
      <!-- Shrinks (or gives way) before the number ever wraps -->
      <Sparkline v-if="trend && trend.length > 1 && available" :values="trend" class="mb-1 min-w-0 flex-1 max-w-24" />
    </div>

    <p v-if="available && delta != null" class="flex flex-wrap items-center gap-x-1.5 text-[12.5px] text-[#64748B]">
      <span class="inline-flex items-center gap-0.5 font-semibold" :class="deltaClass">
        <i :class="['bi', delta >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short', 'text-base leading-none']" aria-hidden="true"></i>
        {{ formatPercent(Math.abs(delta)) }}
        <span class="sr-only">{{ delta >= 0 ? 'increase' : 'decrease' }}</span>
      </span>
      <span>vs previous period</span>
    </p>
    <p v-if="available && context" class="text-[12.5px] text-[#64748B]">{{ context }}</p>
  </component>
</template>

<script setup>
import { computed } from 'vue';
import Sparkline from './charts/Sparkline.vue';
import { formatPercent } from '../utils/format.js';

const props = defineProps({
  label:             { type: String, required: true },
  value:             { type: String, default: '' },
  delta:             { type: Number, default: null },     // ratio vs previous period
  goodWhen:          { type: String, default: 'up' },     // 'up' | 'down' | 'neutral'
  context:           { type: String, default: '' },
  hint:              { type: String, default: '' },       // definition of the metric
  trend:             { type: Array, default: null },
  to:                { type: String, default: '' },
  primary:           { type: Boolean, default: false },
  available:         { type: Boolean, default: true },    // false → "Not available", never a fake zero
  unavailableReason: { type: String, default: 'This data is not collected yet.' },
});

// Green/red only where direction has a meaning; up/down is also in the icon and text
const deltaClass = computed(() => {
  if (props.goodWhen === 'neutral' || props.delta === 0) return 'text-[#475569]';
  const good = props.goodWhen === 'up' ? props.delta > 0 : props.delta < 0;
  return good ? 'text-[#15803D]' : 'text-[#B91C1C]';
});
</script>
