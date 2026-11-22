<template>
  <!-- The customer's budget: a set amount, a range, negotiable, or none. A budget is what they have in mind, never a price. -->
  <div class="flex flex-col gap-4">
    <OptionCards v-model="spec.type" :options="BUDGET_CHOICES" legend="What is your budget?" />

    <FormField v-if="spec.type === 'fixed' || spec.type === 'negotiable'" v-model="spec.amount" type="number" inputmode="numeric" min="0" step="1000"
      :label="spec.type === 'fixed' ? 'Budget' : 'About how much?'" :optional="spec.type === 'negotiable'" :required="spec.type === 'fixed'" prefix="UGX"
      placeholder="e.g. 50000" :error-message="errors.amount" />

    <div v-else-if="spec.type === 'range'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <FormField v-model="spec.min" type="number" inputmode="numeric" min="0" step="1000" label="Lowest" required prefix="UGX" placeholder="e.g. 30000" :error-message="errors.min" />
      <FormField v-model="spec.max" type="number" inputmode="numeric" min="0" step="1000" label="Highest" required prefix="UGX" placeholder="e.g. 60000" :error-message="errors.max" />
    </div>

    <p v-else class="wm-field-hint">Workers will suggest a price when they apply.</p>

    <div v-if="withBasis && (spec.type === 'fixed' || spec.type === 'range')" class="flex flex-col gap-1.5">
      <label :for="basisId" class="wm-label">This amount is</label>
      <select :id="basisId" v-model="spec.basis" class="wm-input">
        <option v-for="b in BASIS_CHOICES" :key="b.id" :value="b.id">{{ b.label }}</option>
      </select>
    </div>

    <p class="text-[12.5px] text-muted">You agree the final price with the worker. Nothing is charged by WorkMate.</p>
  </div>
</template>

<script setup>
import { useId } from 'vue';
import FormField from '@/components/FormField.vue';
import OptionCards from '@/components/pricing/OptionCards.vue';
import { BUDGET_CHOICES, BASIS_CHOICES } from '@/utils/pricing.js';

// The budget being filled in: { type, amount, min, max, basis } (see blankBudget)
const spec = defineModel({ type: Object, required: true });
defineProps({
  // Messages for { amount, min, max }
  errors: { type: Object, default: () => ({}) },
  // Ask what the amount is "per" (the job form does; a request to one provider doesn't)
  withBasis: { type: Boolean, default: false },
});

const basisId = `budget-basis-${useId()}`;
</script>
