<template>
  <component
    :is="to ? 'router-link' : 'button'"
    v-bind="to ? { to } : { type: 'button', 'aria-pressed': active }"
    class="wm-glass flex flex-col items-start justify-between gap-2 w-full p-3 rounded-[20px] text-left transition-[background-color,box-shadow,transform] hover:-translate-y-px"
    :class="[
      active ? '!bg-primary/10 !border-primary' : 'hover:!bg-white/80',
      compact ? 'min-h-[88px]' : 'min-h-[108px] lg:min-h-[124px] lg:p-4',
    ]"
    @click="!to && $emit('select', category.id)"
  >
    <span class="wm-icon-tile" aria-hidden="true">
      <i :class="['bi', categoryIcon(category.id)]"></i>
    </span>
    <span class="text-sm font-semibold text-ink leading-snug">{{ category.label }}</span>
  </component>
</template>

<script setup>
import { categoryIcon } from '@/utils/ui.js';

defineProps({
  category: { type: Object, required: true },
  active:   { type: Boolean, default: false },
  /** When set, renders as a link instead of a toggle button */
  to:       { type: [String, Object], default: null },
  compact:  { type: Boolean, default: false },
});
defineEmits(['select']);
</script>
