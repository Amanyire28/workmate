<template>
  <!-- A few choices as large, tappable cards (one is always chosen) -->
  <fieldset class="flex flex-col gap-2">
    <legend class="wm-label mb-1">{{ legend }}</legend>
    <div class="grid gap-2" :class="columns === 2 ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-4'">
      <label
        v-for="o in options"
        :key="o.id"
        class="flex min-h-14 cursor-pointer flex-col justify-center gap-0.5 rounded-xl border px-3 py-2 text-left focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary"
        :class="modelValue === o.id ? 'border-primary bg-primary-tint text-primary' : 'border-line text-ink hover:bg-[#F8FAFC]'"
      >
        <input :checked="modelValue === o.id" type="radio" :name="name" :value="o.id" class="sr-only" @change="$emit('update:modelValue', o.id)" />
        <span class="text-[14.5px] font-semibold leading-tight">{{ o.label }}</span>
        <span v-if="o.hint" class="text-[12px] text-muted leading-tight">{{ o.hint }}</span>
      </label>
    </div>
  </fieldset>
</template>

<script setup>
import { useId } from 'vue';

defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, required: true },   // [{ id, label, hint? }]
  legend: { type: String, required: true },
  columns: { type: Number, default: 2 },
});
defineEmits(['update:modelValue']);

const name = `options-${useId()}`;
</script>
