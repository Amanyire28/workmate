<template>
  <!-- A provider's estimate when applying: a guide for the customer. The price is agreed, and confirmed by the customer, later. -->
  <div class="flex flex-col gap-4">
    <OptionCards v-model="spec.type" :options="ESTIMATE_CHOICES" legend="What can you tell them about price?" :columns="4" />

    <FormField v-if="spec.type === 'fixed'" v-model="spec.amount" type="number" inputmode="numeric" min="0" step="1000" label="Your price" required prefix="UGX"
      :placeholder="placeholder" :error-message="errors.amount" />

    <div v-else-if="spec.type === 'range'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <FormField v-model="spec.amount" type="number" inputmode="numeric" min="0" step="1000" label="Lowest" required prefix="UGX" :placeholder="placeholder" :error-message="errors.amount" />
      <FormField v-model="spec.max" type="number" inputmode="numeric" min="0" step="1000" label="Highest" required prefix="UGX" :error-message="errors.max" />
    </div>

    <p v-else-if="spec.type === 'inspection_required'" class="wm-field-hint">The customer is told you need to see the job before you can give a price.</p>
    <p v-else class="wm-field-hint">{{ hint }}</p>

    <p class="text-[12.5px] text-muted">An estimate is a guide. The final price is agreed with the customer before the work starts.</p>
  </div>
</template>

<script setup>
import FormField from '@/components/FormField.vue';
import OptionCards from '@/components/pricing/OptionCards.vue';
import { ESTIMATE_CHOICES } from '@/utils/pricing.js';

// The estimate being filled in: { type, amount, max } (see blankEstimate)
const spec = defineModel({ type: Object, required: true });
defineProps({
  errors: { type: Object, default: () => ({}) },
  placeholder: { type: String, default: 'e.g. 50000' },
  hint: { type: String, default: 'Leave the price for now and agree it in chat.' },
});
</script>
