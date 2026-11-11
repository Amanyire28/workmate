<template>
  <!-- A service request in the chat. It shows the request as it is now (the request
       itself is the record); the exact location only to those allowed to see it. -->
  <article class="w-full max-w-[420px] self-center rounded-[18px] border border-line bg-white shadow-[0_1px_3px_rgba(15,23,42,0.08)]" :aria-label="`Service request ${r.reference}`">
    <header class="flex items-center justify-between gap-2 border-b border-line px-4 py-2.5">
      <p class="text-[13px] font-semibold uppercase tracking-wide text-primary"><i class="bi bi-clipboard-check mr-1" aria-hidden="true"></i>{{ r.kind === 'posted' ? 'Job you were hired for' : 'Service request' }}</p>
      <span class="text-[12px] text-muted">{{ r.reference }}</span>
    </header>
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 px-4 py-3 text-[14px]">
      <dt class="text-muted">Service</dt><dd class="text-ink font-medium">{{ r.title }}</dd>
      <template v-if="r.schedule?.start">
        <dt class="text-muted">Date</dt><dd class="text-ink">{{ formatDay(r.schedule.start) }}</dd>
        <dt class="text-muted">Time</dt><dd class="text-ink">{{ formatTime(r.schedule.start) }}</dd>
      </template>
      <template v-else><dt class="text-muted">When</dt><dd class="text-ink">{{ r.preferred_time || 'Flexible' }}</dd></template>
      <dt class="text-muted">Location</dt>
      <dd class="text-ink">{{ r.location.area_name }}<template v-if="r.location.landmark"> · {{ r.location.landmark }}</template></dd>
      <template v-if="r.budget"><dt class="text-muted">Budget</dt><dd class="text-ink">{{ formatPrice(r.budget) }}</dd></template>
      <dt class="text-muted">Status</dt><dd><span class="wm-pill !py-0" :class="`wm-pill-${status.tone}`">{{ status.title }}</span></dd>
    </dl>
    <p v-if="r.description" class="px-4 pb-3 text-[13.5px] text-[#475569] line-clamp-3 whitespace-pre-line">{{ r.description }}</p>
    <footer class="flex flex-wrap gap-2 border-t border-line px-3 py-2.5">
      <router-link :to="asCustomer ? '/activity?tab=requests' : '/activity?tab=received'" class="wm-btn wm-btn-tint !min-h-10 !px-3 flex-1">View request</router-link>
      <a v-if="r.location.latitude != null" :href="directionsUrl(r.location.latitude, r.location.longitude)" target="_blank" rel="noopener" class="wm-btn wm-btn-secondary !min-h-10 !px-3 flex-1">
        <i class="bi bi-sign-turn-right" aria-hidden="true"></i>View location
      </a>
    </footer>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import { formatPrice } from '@/utils/ui.js';
import { formatDay, formatTime, directionsUrl, customerStatus } from '@/utils/booking.js';

const props = defineProps({
  // The request as the chat API sends it
  r: { type: Object, required: true },
  // Whether the person reading is the customer (else the provider)
  asCustomer: { type: Boolean, default: false },
});

// "Awaiting provider response" while it's new; otherwise the same words customers see
const status = computed(() => (props.r.status === 'open' && !props.r.schedule?.proposed_start
  ? { title: 'Awaiting provider response', tone: 'info' }
  : customerStatus(props.r)));
</script>
