<template>
  <!-- The price of the job and the payments the provider reported, in plain words. A payment counts as paid only once the
       customer confirms it: waiting and disputed ones are never shown as paid. WorkMate takes no payment. -->
  <section v-if="price || reports.length" class="flex flex-col gap-2 rounded-xl border border-line bg-white/70 px-3.5 py-3" aria-label="Payments" data-payment-summary>
    <p v-if="price" class="flex items-baseline justify-between gap-3 text-[14px] text-ink">
      <span class="text-muted">{{ price.label }}</span><strong class="text-[16px]">{{ ugx(price.amount) }}</strong>
    </p>

    <ul v-if="lines.length" class="flex flex-col gap-1">
      <li v-for="l in lines" :key="l.key" class="flex items-baseline justify-between gap-3 text-[14px]">
        <span :class="TEXT[l.tone]">{{ l.label }}</span><strong class="shrink-0 text-ink">{{ ugx(l.amount) }}</strong>
      </li>
    </ul>

    <details v-if="reports.length" class="text-[13.5px]" :open="attention">
      <summary class="cursor-pointer font-medium text-muted min-h-9 inline-flex items-center">Payment history ({{ reports.length }})</summary>
      <ol class="mt-1 flex flex-col gap-2">
        <li v-for="r in reports" :key="r.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2">
          <p class="flex flex-wrap items-center justify-between gap-2">
            <strong class="text-ink">{{ ugx(r.amount) }}</strong>
            <span class="wm-pill !py-0" :class="`wm-pill-${words(r).tone}`">{{ words(r).title }}</span>
          </p>
          <p class="text-muted">{{ methodLabel(r.method) }}<template v-if="r.paid_on"> · {{ shortDate(r.paid_on) }}</template><template v-if="r.reference"> · {{ r.reference }}</template></p>
          <p v-if="r.note" class="text-ink">“{{ r.note }}”</p>
          <p v-if="r.response_note" class="text-ink">{{ role === 'provider' ? 'The customer wrote' : 'You wrote' }}: “{{ r.response_note }}”</p>
          <p v-if="r.cancel_reason" class="text-ink">{{ role === 'provider' ? 'You wrote' : 'The provider wrote' }}: “{{ r.cancel_reason }}”</p>
          <p v-if="r.corrects_id" class="text-[12.5px] text-muted">This replaces an earlier report.</p>
        </li>
      </ol>
    </details>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { ugx, methodLabel, paymentWords, paymentLines, priceLine, shortDate, uncorrectedReports } from '@/utils/pricing.js';

const props = defineProps({
  // The `payments` block of a request: { agreed_amount, final_price, totals, reports }
  payments: { type: Object, default: null },
  // Who is reading: the customer or the provider business
  role: { type: String, default: 'customer' },
});

const TEXT = { success: 'text-success', info: 'text-primary', warning: 'text-[#92400E]', neutral: 'text-muted' };

const reports = computed(() => (props.payments && props.payments.reports) || []);
const lines = computed(() => paymentLines(props.payments, props.role));
// What needs the provider's eye is shown open (a waiting report, a dispute and its reason, one taken back and not yet corrected);
// the customer is asked about waiting reports separately, and their own totals already say what they confirmed or questioned, so for them it stays closed
const attention = computed(() => (props.role === 'provider'
  ? reports.value.some((r) => r.status === 'pending_confirmation' || r.status === 'disputed') || uncorrectedReports(props.payments).length > 0
  : false));
const price = computed(() => priceLine(props.payments));
const words = (r) => paymentWords(r.status, props.role);
</script>
