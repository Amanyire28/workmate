<template>
  <!-- Single-line location picker: native select stretched over a styled pill -->
  <div
    class="relative inline-flex items-center gap-1.5 h-11 rounded-full text-sm font-medium transition-colors focus-within:ring-2 focus-within:ring-primary"
    :class="dark
      ? 'px-1 text-white'
      : 'px-3.5 bg-white border border-[#E2E8F0] text-ink hover:border-[#CBD5E1]'"
  >
    <i class="bi bi-geo-alt-fill text-[15px]" :class="dark ? 'text-white' : 'text-primary'" aria-hidden="true"></i>
    <span class="truncate max-w-[140px]" aria-hidden="true">{{ selectedArea || 'Kampala' }}</span>
    <i class="bi bi-chevron-down text-[11px] opacity-70" aria-hidden="true"></i>
    <label :for="id" class="sr-only">Your location</label>
    <select
      :id="id"
      v-model="selectedArea"
      class="absolute -inset-px opacity-0 cursor-pointer"
    >
      <option value="">All of Kampala</option>
      <option v-for="area in kampalaAreas" :key="area" :value="area">{{ area }}</option>
    </select>
  </div>
</template>

<script setup>
import { kampalaAreas } from '@/stores/catalog.js';
import { selectedArea } from '@/stores/location.js';

defineProps({
  id:   { type: String, required: true },
  dark: { type: Boolean, default: false },   // white text for use over photos
});
</script>
