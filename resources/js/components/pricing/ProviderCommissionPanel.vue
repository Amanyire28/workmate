<template>
  <!-- A provider business's own commission, in plain words. Shown only while WorkMate is recording commission, and only for the owner or a
       manager. Counts only payments the customer confirmed; waiting or questioned payments are never part of it. A payment the provider
       reports to WorkMate is shown as awaiting verification and never as settled until finance has checked it. -->
  <section v-if="data" class="wm-card flex flex-col gap-3" aria-label="Your commission" data-commission-panel>
    <div>
      <h2 class="text-[16px] font-semibold text-ink">Your commission</h2>
      <p class="text-[13.5px] text-muted">What WorkMate has recorded from payments your customers confirmed. Waiting or questioned payments are not included.</p>
    </div>

    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-4">
      <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Customer-confirmed payments</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.confirmed_payments_ugx) }}</dd></div>
      <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Commission recorded</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.assessed_ugx - data.reversed_ugx) }}</dd></div>
      <div v-if="settlement" class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Verified payments to WorkMate</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(data.settled_ugx) }}</dd></div>
      <div class="rounded-xl bg-primary-tint px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">{{ settlement ? 'Remaining balance' : 'Outstanding' }}</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(Math.max(data.outstanding_ugx, 0)) }}</dd></div>
    </dl>
    <p v-if="data.reversed_ugx" class="text-[13px] text-muted">{{ ugx(data.reversed_ugx) }} was taken off because the payment no longer counted. The original entry stays in your history.</p>
    <p v-if="data.outstanding_ugx < 0" class="text-[13px] text-muted">You have paid {{ ugx(-data.outstanding_ugx) }} more than is owed. WorkMate will look at it; nothing is refunded automatically.</p>
    <p v-if="data.not_counted.waiting_for_customer || data.not_counted.questioned" class="text-[13px] text-muted">
      Not counted yet: {{ data.not_counted.waiting_for_customer }} waiting for the customer, {{ data.not_counted.questioned }} questioned.
    </p>

    <!-- Settlement (only while it is switched on) -->
    <template v-if="settlement">
      <section v-if="settlement.current" class="flex flex-col gap-2 rounded-xl border border-line px-3.5 py-3" aria-label="Current settlement">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-[14.5px] font-semibold text-ink">Current settlement</p>
          <span class="wm-pill" :class="`wm-pill-${CYCLE_STATUS[settlement.current.status].tone}`">{{ cycleLabel(settlement.current.status) }}</span>
        </div>
        <p class="text-[13.5px] text-ink">{{ ugx(settlement.current.outstanding_ugx) }} to pay · {{ jobsWords(settlement.current) }} with commission</p>
        <p class="text-[13px] text-muted">{{ dueWords(settlement.current, shortDate) }}</p>
        <p v-if="earlierTriggerWords(settlement.current, shortDate)" class="text-[13px] text-muted">{{ earlierTriggerWords(settlement.current, shortDate) }}</p>
        <p v-if="settlement.current.credits_ugx" class="text-[13px] text-muted">Already verified: {{ ugx(settlement.current.credits_ugx) }}. Part payments don’t change the deadline.</p>
        <p v-if="settlement.pending_ugx" class="text-[13px] text-muted">{{ ugx(settlement.pending_ugx) }} reported and awaiting verification. It isn’t counted until WorkMate checks it.</p>
        <div v-if="settlement.instructions" class="rounded-lg bg-[#F8FAFC] px-3 py-2 text-[13px]"><p class="font-medium text-ink">How to pay WorkMate</p><p class="whitespace-pre-line text-muted">{{ settlement.instructions }}</p></div>
        <button v-if="canReport(settlement.current)" type="button" class="wm-btn wm-btn-primary !min-h-12 w-full" @click="sheet = true"><i class="bi bi-cash-coin" aria-hidden="true"></i>Report commission payment</button>
      </section>
      <p v-else class="text-[13.5px] text-muted">Nothing to settle right now.</p>

      <details v-if="settlement.payments.length" class="text-[13.5px]" :open="settlement.payments.some((p) => p.status !== 'verified')">
        <summary class="inline-flex min-h-9 cursor-pointer items-center font-medium text-muted">Your commission payments ({{ settlement.payments.length }})</summary>
        <ul class="mt-1 flex flex-col gap-2">
          <li v-for="p in settlement.payments" :key="p.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2">
            <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-ink">{{ ugx(p.amount_ugx) }} · {{ channelLabel(p.channel) }}</strong>
              <span class="wm-pill !py-0" :class="`wm-pill-${paymentWords(p.status).tone}`">{{ paymentWords(p.status).label }}</span></p>
            <p class="text-muted">{{ paymentWords(p.status).why }}<template v-if="p.reference"> Reference {{ p.reference }}.</template></p>
            <p v-if="p.status === 'rejected' && p.decision_note" class="text-ink">Why: “{{ p.decision_note }}”</p>
            <p v-if="p.status === 'verified' && p.excess_ugx" class="text-muted">{{ ugx(p.excess_ugx) }} of it was more than was owed and is not applied until WorkMate reviews it.</p>
          </li>
        </ul>
      </details>

      <details v-if="settlement.cycles.length" class="text-[13.5px]">
        <summary class="inline-flex min-h-9 cursor-pointer items-center font-medium text-muted">Settlement history ({{ settlement.cycles.length }})</summary>
        <ul class="mt-1 flex flex-col gap-2">
          <li v-for="c in settlement.cycles" :key="c.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2">
            <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-ink">{{ c.jobs }} {{ c.jobs === 1 ? 'job' : 'jobs' }} · {{ ugx(c.payable_ugx) }}</strong>
              <span class="wm-pill !py-0" :class="`wm-pill-${CYCLE_STATUS[c.status].tone}`">{{ cycleLabel(c.status) }}</span></p>
            <p class="text-muted">Paid and verified {{ ugx(c.credits_ugx) }} · still to pay {{ ugx(Math.max(c.outstanding_ugx, 0)) }}</p>
          </li>
        </ul>
      </details>

      <ReportCommissionPaymentSheet :open="sheet" :cycle="settlement.current" :channels="settlement.channels" :instructions="settlement.instructions" @close="sheet = false" @done="done" />
    </template>

    <details v-if="data.items.length" class="text-[13.5px]">
      <summary class="inline-flex min-h-9 cursor-pointer items-center font-medium text-muted">The jobs behind it ({{ data.items.length }})</summary>
      <ul class="mt-1 flex flex-col gap-2">
        <li v-for="i in data.items" :key="i.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2">
          <p class="flex flex-wrap items-baseline justify-between gap-2">
            <strong class="text-ink">{{ i.service || 'Job' }}<template v-if="i.job"> · {{ i.job }}</template></strong>
            <span class="wm-pill !py-0" :class="i.status === 'outstanding' ? 'wm-pill-warning' : 'wm-pill-neutral'">{{ i.status === 'outstanding' ? 'Recorded' : 'Taken off' }}</span>
          </p>
          <p class="text-muted">Payment {{ ugx(i.payment_ugx) }} · rate {{ i.rate }} · commission {{ ugx(i.commission_ugx) }} · {{ shortDate(i.assessed_at) }}</p>
        </li>
      </ul>
    </details>
  </section>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { api } from '@/api/client.js';
import { ugx, shortDate } from '@/utils/pricing.js';
import ReportCommissionPaymentSheet from '@/components/pricing/ReportCommissionPaymentSheet.vue';
import { CYCLE_STATUS, cycleLabel, paymentWords, channelLabel, dueWords, jobsWords, canReport, earlierTriggerWords } from '@/utils/settlement.js';

const data = ref(null);
const sheet = ref(false);
const settlement = computed(() => (data.value ? data.value.settlement : null));

async function load() {
  try {
    data.value = (await api('/me/commission')).data;
  } catch {
    data.value = null;   // nothing to show is better than a broken page
  }
}

async function done() {
  sheet.value = false;
  await load();
}

onMounted(load);
</script>
