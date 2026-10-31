<template>
  <section class="rounded-xl wm-glass-card p-4" :aria-labelledby="headingId">
    <h2 :id="headingId" class="text-[13px] font-semibold text-[#475569]">{{ title }}</h2>
    <!-- Rows that lead somewhere are whole-row links; the rest are plain -->
    <ul class="mt-2 -mx-2 divide-y divide-[#EEF3FA]">
      <li v-for="m in items" :key="m.label">
        <component
          :is="m.to ? 'router-link' : 'div'"
          :to="m.to || undefined"
          class="group flex min-h-10 items-center justify-between gap-3 rounded-lg px-2 py-2"
          :class="m.to ? 'wm-hover-row focus:outline-none focus-visible:ring-2 focus-visible:ring-primary' : ''"
        >
          <span class="flex min-w-0 items-center gap-1.5 text-[13px] text-[#475569]" :class="{ 'group-hover:text-primary': m.to }">
            <span class="truncate">{{ m.label }}</span>
            <i v-if="m.hint" class="bi bi-info-circle text-[11px] text-[#94A3B8]" :title="m.hint" aria-hidden="true"></i>
            <span v-if="m.hint" class="sr-only">({{ m.hint }})</span>
          </span>
          <span class="flex shrink-0 items-baseline gap-2">
            <span v-if="m.delta != null" class="text-[12px] font-semibold" :class="deltaClass(m)">
              {{ m.delta >= 0 ? '+' : '−' }}{{ formatPercent(Math.abs(m.delta)) }}<span class="sr-only"> vs previous period</span>
            </span>
            <span class="text-[15px] font-semibold text-ink tabular-nums">{{ m.value }}</span>
            <i class="bi bi-chevron-right self-center text-[11px] transition-transform" :class="m.to ? 'text-[#94A3B8] group-hover:translate-x-0.5 group-hover:text-primary' : 'invisible'" aria-hidden="true"></i>
          </span>
        </component>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { useId } from 'vue';
import { formatPercent } from '../utils/format.js';

defineProps({
  title: { type: String, required: true },
  items: { type: Array, required: true },   // [{ label, value, delta?, goodWhen?, to?, hint? }]
});
const headingId = useId();

function deltaClass(m) {
  if (m.goodWhen === 'neutral' || !m.delta) return 'text-[#475569]';
  const good = (m.goodWhen || 'up') === 'up' ? m.delta > 0 : m.delta < 0;
  return good ? 'text-[#15803D]' : 'text-[#B91C1C]';
}
</script>
