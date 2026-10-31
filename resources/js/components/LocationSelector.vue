<template>
  <div class="relative inline-flex items-center gap-2.5 min-h-11 max-w-full">
    <i class="bi bi-geo-alt-fill text-primary text-[18px] leading-none" aria-hidden="true"></i>
    <span class="flex flex-col min-w-0">
      <label :for="selectId" class="text-xs text-muted leading-tight">{{ label }}</label>
      <span class="flex items-center gap-1 text-sm font-semibold text-ink leading-tight" aria-hidden="true">
        <span class="truncate">{{ modelValue || 'All of Kampala' }}</span>
        <i class="bi bi-chevron-down text-[11px] text-muted"></i>
      </span>
    </span>
    <!-- Native select stretched over the visual control: keyboard + mobile pickers for free -->
    <select
      :id="selectId"
      :value="modelValue"
      class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
      @change="$emit('update:modelValue', $event.target.value)"
    >
      <option value="">All of Kampala</option>
      <option v-for="area in kampalaAreas" :key="area" :value="area">{{ area }}</option>
    </select>
  </div>
</template>

<script setup>
import { kampalaAreas } from '@/stores/catalog.js';

defineProps({
  modelValue: { type: String, default: '' },
  label:      { type: String, default: 'Your location' },
  selectId:   { type: String, default: 'location-select' },
});
defineEmits(['update:modelValue']);
</script>
