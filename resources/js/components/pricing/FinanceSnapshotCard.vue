<template>
  <!-- A small pointer on My Work to My Finances. It repeats nothing: three figures from the same server summary and one link, so there is
       one place that explains the money. Shown only to people who may see the business's finances, and only once there is something. -->
  <router-link v-if="data && data.has_history" to="/my-finances" class="wm-card wm-card-link flex items-center gap-4" data-finance-snapshot>
    <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-wallet2"></i></span>
    <span class="min-w-0 flex-1">
      <span class="block text-[15px] font-semibold text-ink">My Finances</span>
      <span class="block wm-meta">Confirmed by customers: {{ ugx(data.customer_payments.confirmed_ugx) }}</span>
      <span class="block wm-meta">Commission still to pay: {{ ugx(data.commission.as_of.outstanding_ugx) }}<template v-if="data.commission.as_of.overdue_ugx"> · <strong class="text-error">{{ ugx(data.commission.as_of.overdue_ugx) }} overdue</strong></template></span>
    </span>
    <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
  </router-link>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '@/api/client.js';
import { ugx } from '@/utils/myFinances.js';

const data = ref(null);

onMounted(async () => {
  try {
    data.value = (await api('/me/finance/summary?period=all')).data;
  } catch {
    data.value = null;   // no access, or nothing to show: nothing is shown
  }
});
</script>
