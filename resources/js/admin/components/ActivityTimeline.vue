<template>
  <ol class="relative flex flex-col">
    <li v-for="(e, i) in events" :key="e.id" class="relative flex gap-3 pb-4 last:pb-0">
      <!-- Rail -->
      <span v-if="i < events.length - 1" class="absolute left-[15px] top-8 bottom-0 w-px bg-[#E2E8F0]" aria-hidden="true"></span>
      <span
        class="relative z-[1] mt-0.5 inline-flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full text-[14px]"
        :class="toneClass(e)"
        aria-hidden="true"
      ><i :class="['bi', iconFor(e)]"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[13.5px] text-ink leading-snug">
          <component :is="linkable ? 'button' : 'span'" :type="linkable ? 'button' : undefined" class="text-left" :class="{ 'hover:text-primary hover:underline': linkable }" @click="linkable && $emit('select', e)">
            {{ e.summary }}
          </component>
        </p>
        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[12px] text-[#64748B]">
          <time :datetime="e.timestamp" :title="formatDateTime(e.timestamp)">{{ formatRelative(e.timestamp) }}</time>
          <span aria-hidden="true">·</span>
          <span>{{ e.actorName }}</span>
          <template v-if="e.demo"><span aria-hidden="true">·</span><span class="text-[#92400E]">Demo event</span></template>
        </p>
      </div>
    </li>
  </ol>
</template>

<script setup>
import { formatRelative, formatDateTime } from '../utils/format.js';

defineProps({
  events:   { type: Array, required: true },
  linkable: { type: Boolean, default: false },
});
defineEmits(['select']);

const icons = {
  accounts: 'bi-person-plus', providers: 'bi-person-badge', search: 'bi-search', marketplace: 'bi-briefcase',
  finance: 'bi-cash-coin', trust: 'bi-shield-exclamation', content: 'bi-camera-video', notifications: 'bi-bell',
  security: 'bi-shield-lock', admin: 'bi-sliders', system: 'bi-cpu',
};
const iconFor = (e) => icons[e.category] || 'bi-dot';
function toneClass(e) {
  if (e.severity === 'critical') return 'bg-[#FDECEC] text-[#B91C1C]';
  if (e.severity === 'warning' || e.outcome === 'failure') return 'bg-[#FEF3E2] text-[#B45309]';
  return 'bg-[#EFF6FF] text-primary';
}
</script>
