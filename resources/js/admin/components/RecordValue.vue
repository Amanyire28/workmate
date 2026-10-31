<template>
  <AdminStatusBadge v-if="format === 'status' && value != null && value !== ''" :status="String(value)" />
  <span v-else-if="format === 'bool'" :class="value ? 'text-[#15803D]' : 'text-[#64748B]'">
    <i :class="['bi', value ? 'bi-check-circle-fill' : 'bi-dash-circle']" aria-hidden="true"></i> {{ value ? (yes || 'Yes') : (no || 'No') }}
  </span>
  <time v-else-if="(format === 'date' || format === 'relative') && value" :datetime="value" :title="formatDateTime(value)" class="whitespace-nowrap">
    {{ format === 'relative' ? formatRelative(value) : formatDateTime(value) }}
  </time>
  <span v-else-if="value == null || value === ''" class="text-[#94A3B8]">{{ empty }}</span>
  <span v-else :class="{ 'tabular-nums whitespace-nowrap': numeric }">{{ formatValue(value, format) }}</span>
</template>

<script>
import { formatNumber, formatUGX, formatUGXCompact, formatPercent, formatDateTime } from '../utils/format.js';
import { categoryLabels } from '../data/liveData.js';

/**
 * Plain-text formatting shared by tables, detail views and CSV exports.
 * format: money | moneyCompact | number | percent | date | relative | status | bool | category | km | text
 */
export function formatValue(value, format) {
  if (value == null || value === '') return '';
  switch (format) {
    case 'money': return formatUGX(value);
    case 'moneyCompact': return formatUGXCompact(value);
    case 'number': return formatNumber(value);
    case 'percent': return formatPercent(value);
    case 'date': case 'relative': return formatDateTime(value);
    case 'category': return Array.isArray(value) ? value.map(v => categoryLabels[v] || v).join(', ') : (categoryLabels[value] || value);
    case 'km': return `${value} km`;
    case 'bool': return value ? 'Yes' : 'No';
    case 'status': return String(value).replace(/_/g, ' ');
    default: return Array.isArray(value) ? value.join(', ') : String(value);
  }
}
</script>

<script setup>
import { computed } from 'vue';
import AdminStatusBadge from './AdminStatusBadge.vue';
import { formatRelative } from '../utils/format.js';

const props = defineProps({
  value:  { default: null },
  format: { type: String, default: 'text' },
  empty:  { type: String, default: '—' },
  yes:    { type: String, default: '' },
  no:     { type: String, default: '' },
});

const numeric = computed(() => ['money', 'moneyCompact', 'number', 'percent', 'km'].includes(props.format));
</script>
