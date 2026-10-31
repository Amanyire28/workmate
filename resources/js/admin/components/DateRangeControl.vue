<template>
  <div class="flex flex-wrap items-center gap-2">
    <div ref="group" class="inline-flex rounded-lg wm-surface p-0.5" role="radiogroup" :aria-label="label">
      <button
        v-for="p in presets"
        :key="p.days"
        type="button"
        role="radio"
        :aria-checked="modelValue === p.days"
        :tabindex="modelValue === p.days ? 0 : -1"
        class="h-9 px-3 rounded-md text-[13px] font-medium transition-colors"
        :class="modelValue === p.days ? 'bg-primary text-white shadow-sm' : 'text-[#475569] hover:bg-[#F1F5F9]'"
        @click="$emit('update:modelValue', p.days)"
        @keydown.right.prevent="step(1)"
        @keydown.left.prevent="step(-1)"
      >{{ p.label }}</button>
    </div>
    <label v-if="showCompare" class="inline-flex items-center gap-2 h-9 px-3 rounded-lg wm-surface text-[13px] text-[#475569] cursor-pointer select-none">
      <input
        type="checkbox"
        class="w-4 h-4 accent-[#1769D2]"
        :checked="compare"
        @change="$emit('update:compare', $event.target.checked)"
      />
      Compare with previous period
    </label>
  </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';

const group = ref(null);
const props = defineProps({
  modelValue:  { type: Number, required: true },   // days
  compare:     { type: Boolean, default: true },
  showCompare: { type: Boolean, default: true },
  presets:     { type: Array, default: () => [{ days: 7, label: '7 days' }, { days: 30, label: '30 days' }, { days: 90, label: '90 days' }] },
  label:       { type: String, default: 'Date range' },
});
const emit = defineEmits(['update:modelValue', 'update:compare']);

function step(dir) {
  const i = props.presets.findIndex(p => p.days === props.modelValue);
  const next = props.presets[(i + dir + props.presets.length) % props.presets.length];
  emit('update:modelValue', next.days);
  // Roving focus follows the selection
  nextTick(() => group.value?.querySelector('[aria-checked="true"]')?.focus());
}
</script>
