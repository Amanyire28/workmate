<template>
  <dl class="flex flex-col gap-3 text-sm">
    <div class="flex items-start gap-3">
      <dt class="sr-only">Budget</dt>
      <i class="bi bi-cash-stack text-muted text-base mt-0.5" aria-hidden="true"></i>
      <dd v-if="job.budget">
        <span class="text-base font-semibold text-ink">{{ formatPrice(job.budget) }}</span>
        <span class="text-muted"> · {{ job.budgetBasis }}</span>
      </dd>
      <dd v-else class="text-muted">Budget to be discussed</dd>
    </div>
    <div class="flex items-start gap-3">
      <dt class="sr-only">Location</dt>
      <i class="bi bi-geo-alt text-muted text-base mt-0.5" aria-hidden="true"></i>
      <dd class="text-ink">{{ job.area }}</dd>
    </div>
    <div class="flex items-start gap-3">
      <dt class="sr-only">When</dt>
      <i class="bi bi-calendar3 text-muted text-base mt-0.5" aria-hidden="true"></i>
      <dd class="text-ink">{{ job.date }}</dd>
    </div>
    <div class="flex items-start gap-3">
      <dt class="sr-only">Workers needed</dt>
      <i class="bi bi-people text-muted text-base mt-0.5" aria-hidden="true"></i>
      <dd class="text-ink">{{ job.workersNeeded }} {{ job.workersNeeded === 1 ? 'worker' : 'workers' }} needed</dd>
    </div>
  </dl>
</template>

<script setup>
import { formatPrice } from '@/utils/ui.js';

defineProps({
  job: { type: Object, required: true },
});
</script>
