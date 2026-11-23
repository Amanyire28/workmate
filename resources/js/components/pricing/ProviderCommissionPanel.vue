<template>
  <!-- A provider business's own commission, in plain words. Shown only while WorkMate is recording commission, and only for the owner or a
       manager. Counts only payments the customer confirmed; waiting or questioned payments are never part of it. -->
  <section v-if="data" class="wm-card flex flex-col gap-3" aria-label="Your commission" data-commission-panel>
    <div>
      <h2 class="text-[16px] font-semibold text-ink">Your commission</h2>
      <p class="text-[13.5px] text-muted">What WorkMate has recorded from payments your customers confirmed. Waiting or questioned payments are not included.</p>
    </div>

    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
      <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Customer-confirmed payments</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.confirmed_payments_ugx) }}</dd></div>
      <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Commission recorded</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.assessed_ugx - data.reversed_ugx) }}</dd></div>
      <div class="rounded-xl bg-primary-tint px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Outstanding</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.outstanding_ugx) }}</dd></div>
    </dl>
    <p v-if="data.reversed_ugx" class="text-[13px] text-muted">{{ ugx(data.reversed_ugx) }} was taken off because the payment no longer counted. The original entry stays in your history.</p>
    <p v-if="data.not_counted.waiting_for_customer || data.not_counted.questioned" class="text-[13px] text-muted">
      Not counted yet: {{ data.not_counted.waiting_for_customer }} waiting for the customer, {{ data.not_counted.questioned }} questioned.
    </p>

    <details v-if="data.items.length" class="text-[13.5px]">
      <summary class="inline-flex min-h-9 cursor-pointer items-center font-medium text-muted">The jobs behind it ({{ data.items.length }})</summary>
      <ul class="mt-1 flex flex-col gap-2">
        <li v-for="i in data.items" :key="i.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2">
          <p class="flex flex-wrap items-baseline justify-between gap-2">
            <strong class="text-ink">{{ i.service || 'Job' }}<template v-if="i.job"> · {{ i.job }}</template></strong>
            <span class="wm-pill !py-0" :class="i.status === 'outstanding' ? 'wm-pill-warning' : 'wm-pill-neutral'">{{ i.status === 'outstanding' ? 'Outstanding' : 'Taken off' }}</span>
          </p>
          <p class="text-muted">Payment {{ ugx(i.payment_ugx) }} · rate {{ i.rate }} · commission {{ ugx(i.commission_ugx) }} · {{ shortDate(i.assessed_at) }}</p>
        </li>
      </ul>
    </details>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api/client.js';
import { ugx, shortDate } from '@/utils/pricing.js';

const data = ref(null);

onMounted(async () => {
  try {
    data.value = (await api('/me/commission')).data;
  } catch {
    data.value = null;   // nothing to show is better than a broken page
  }
});
</script>
