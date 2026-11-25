<template>
  <!-- A pointer on My Work to My Finances, shown only when the server's own figures say there is something to know or do: commission still
       to pay, overdue or in credit, a commission payment waiting to be checked, or customer payments waiting or questioned. Otherwise it stays out of the way (My Finances
       is always one tap away under "More" and in Account). While the figures load nothing is shown; if they cannot be loaded this says so, and
       it never treats missing figures as "nothing owed". -->
  <router-link v-if="lines.length" to="/my-finances" class="wm-card wm-card-link flex items-center gap-3 !py-3" data-finance-snapshot>
    <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-wallet2"></i></span>
    <span class="min-w-0 flex-1">
      <span class="block text-[15px] font-semibold text-ink">My Finances</span>
      <span v-for="l in lines" :key="l.key" class="block wm-meta" :class="l.strong ? '!text-error font-semibold' : ''">{{ l.text }}</span>
    </span>
    <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
  </router-link>
  <p v-else-if="failed" class="wm-notice wm-notice-warning" role="status" data-finance-snapshot>
    <i class="bi bi-wallet2" aria-hidden="true"></i>
    <span>We couldn’t check your finances just now. <button type="button" class="wm-link" @click="load">Try again</button> or <router-link to="/my-finances" class="wm-link">open My Finances</router-link>.</span>
  </p>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { api } from '@/api/client.js';
import { ugx } from '@/utils/myFinances.js';

const data = ref(null);
const failed = ref(false);

async function load() {
  failed.value = false;
  try {
    data.value = (await api('/me/finance/summary?period=all')).data;
  } catch (e) {
    data.value = null;
    // No access (a team member without it) or no business: nothing to show. Anything else is a failure we say so about.
    failed.value = !(e && [401, 403, 404].includes(e.status));
  }
}
onMounted(load);

const lines = computed(() => {
  const d = data.value;
  if (!d) return [];
  const c = (d.commission && d.commission.as_of) || {};
  const p = d.customer_payments || {};
  const out = [];
  if (c.overdue_ugx > 0) out.push({ key: 'overdue', strong: true, text: `Overdue commission: ${ugx(c.overdue_ugx)}` });
  if (c.outstanding_ugx > 0) out.push({ key: 'owed', text: `Commission still to pay: ${ugx(c.outstanding_ugx)}` });
  if (c.pending_ugx > 0) out.push({ key: 'pending', text: `Commission payment waiting to be checked: ${ugx(c.pending_ugx)}` });
  if (c.credit_ugx > 0) out.push({ key: 'credit', text: `Credit with WorkMate: ${ugx(c.credit_ugx)}` });
  if (p.questioned && p.questioned.count > 0) out.push({ key: 'questioned', strong: true, text: `${p.questioned.count} customer ${p.questioned.count === 1 ? 'payment is' : 'payments are'} questioned` });
  if (p.awaiting && p.awaiting.count > 0) out.push({ key: 'awaiting', text: `${p.awaiting.count} ${p.awaiting.count === 1 ? 'payment is' : 'payments are'} waiting for the customer to confirm` });

  return out;
});
</script>
