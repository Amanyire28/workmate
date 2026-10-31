<template>
  <div
    class="pointer-events-none absolute top-1 z-10 min-w-[150px] max-w-[240px] rounded-lg wm-glass-pop px-3 py-2"
    :style="style"
  >
    <p class="text-xs font-semibold text-ink">{{ title }}</p>
    <p v-if="note" class="text-[11px] text-[#64748B]">{{ note }}</p>
    <ul class="mt-1.5 flex flex-col gap-1">
      <li v-for="r in rows" :key="r.label" class="flex items-center gap-2 text-xs">
        <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: r.color }" aria-hidden="true"></span>
        <span class="flex-1 text-[#475569]">{{ r.label }}</span>
        <span class="font-semibold text-ink tabular-nums">{{ r.value }}</span>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  title: { type: String, required: true },
  note:  { type: String, default: '' },
  rows:  { type: Array, required: true },   // [{ label, color, value }]
  x:     { type: Number, required: true },  // anchor in px
  width: { type: Number, required: true },  // container width
});

// Sit beside the crosshair, flipping to the left near the right edge
const style = computed(() => (props.x > props.width * 0.6
  ? { right: `${props.width - props.x + 12}px` }
  : { left: `${props.x + 12}px` }));
</script>
