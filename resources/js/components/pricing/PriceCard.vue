<template>
  <!-- Where a request's price stands, in plain words: the agreed price, and any newer price waiting. Shown to both
       sides; only the customer's own "Accept price" ever makes a price agreed. -->
  <div v-if="state !== 'none'" class="flex flex-col gap-2" data-price-card>
    <!-- The agreed price (stays shown while a newer one is waiting) -->
    <section v-if="pricing.agreed" class="rounded-xl px-3.5 py-3" :class="TONES.success" aria-label="Agreed price">
      <p class="text-[14.5px] font-semibold"><i class="bi bi-check-circle-fill mr-1.5" aria-hidden="true"></i>{{ PRICE_WORDS.agreed.title }}</p>
      <p class="text-[24px] font-bold leading-tight">{{ ugx(pricing.agreed.amount) }}</p>
      <p class="text-[13px] opacity-90">{{ PRICE_WORDS.agreed[role] }}</p>
      <details class="mt-1.5 text-[13.5px]">
        <summary class="cursor-pointer font-medium min-h-9 inline-flex items-center">What the price includes</summary>
        <p class="whitespace-pre-line mt-1">{{ pricing.agreed.scope }}</p>
        <p v-if="pricing.agreed.exclusions" class="whitespace-pre-line mt-1.5"><strong>Not included:</strong> {{ pricing.agreed.exclusions }}</p>
      </details>
    </section>

    <!-- A price still being talked about -->
    <section v-if="pricing.open" class="rounded-xl px-3.5 py-3" :class="TONES[openWords.tone]" :aria-label="openTitle">
      <p class="text-[14.5px] font-semibold">{{ openTitle }}</p>
      <p v-if="pricing.open.status !== 'expired'" class="text-[24px] font-bold leading-tight">{{ ugx(pricing.open.amount) }}</p>
      <p class="text-[13px] opacity-90">{{ openWords[role] }}</p>
      <p v-if="pricing.open.status === 'pending_customer' && pricing.open.expires_at" class="text-[12.5px] opacity-80">Answer by {{ shortDate(pricing.open.expires_at) }}.</p>
      <p v-if="pricing.open.status === 'revision_requested' && pricing.open.response_note" class="text-[13.5px] mt-1">“{{ pricing.open.response_note }}”</p>
      <p v-if="pricing.open.status === 'revision_requested' && pricing.open.suggested_amount" class="text-[13px] mt-0.5">
        {{ role === 'provider' ? 'The customer would like about' : 'You said you would like about' }} {{ ugx(pricing.open.suggested_amount) }}.
      </p>
      <details v-if="pricing.open.status !== 'expired'" class="mt-1.5 text-[13.5px]">
        <summary class="cursor-pointer font-medium min-h-9 inline-flex items-center">What the price includes</summary>
        <p class="whitespace-pre-line mt-1">{{ pricing.open.scope }}</p>
        <p v-if="pricing.open.exclusions" class="whitespace-pre-line mt-1.5"><strong>Not included:</strong> {{ pricing.open.exclusions }}</p>
      </details>
    </section>

    <!-- Nothing open or agreed: how the last price ended -->
    <section v-else-if="!pricing.agreed && pricing.latest" class="rounded-xl px-3.5 py-2.5" :class="TONES.neutral">
      <p class="text-[14px] font-semibold">{{ closedTitle }}</p>
      <p class="text-[13px] opacity-90">{{ role === 'provider' ? 'You can send a new price.' : 'Ask the provider for a new price in the chat.' }}</p>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { ugx, priceState, openKind, shortDate, PRICE_WORDS } from '@/utils/pricing.js';

const props = defineProps({
  // The `pricing` block of a request: { agreed, open, latest }
  pricing: { type: Object, default: null },
  // Who is reading: the customer or the provider business
  role: { type: String, default: 'customer' },
});

const TONES = {
  info: 'bg-primary-tint text-primary',
  success: 'bg-success-tint text-success',
  warning: 'bg-[#FFFBEB] text-[#92400E]',
  neutral: 'bg-[#F1F5F9] text-[#475569]',
};

// A closed negotiation still shows how it ended, so "none" only when nothing was ever proposed
const state = computed(() => priceState(props.pricing));
const openWords = computed(() => PRICE_WORDS[openKind(state.value)] || PRICE_WORDS.none);
// While a price is agreed, the newer one is an amendment: said as "new price", never as a replacement already made
const openTitle = computed(() => (props.pricing.agreed && props.pricing.open.status === 'pending_customer' ? 'New price proposed' : openWords.value.title));
const closedTitle = computed(() => ({
  rejected: props.role === 'provider' ? 'The customer didn’t accept your price' : 'You didn’t accept the last price',
  cancelled: 'The price offer was taken back',
  expired: 'The price offer ended without an answer',
  superseded: 'A newer price replaced it',
}[props.pricing.latest.status] || 'The last price offer ended'));
</script>
