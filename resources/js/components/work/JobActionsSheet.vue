<template>
  <!-- Everything else a job allows, one tap each. The main action stays on the card. -->
  <BottomSheet :open="open" title="More options" @close="$emit('close')">
    <p v-if="subtitle" class="-mt-1 mb-2 truncate text-[13.5px] text-muted">{{ subtitle }}</p>
    <ul class="flex flex-col divide-y divide-line" role="list">
      <li v-for="a in actions" :key="a.key">
        <button type="button" class="flex min-h-12 w-full items-center gap-3 px-1 py-2.5 text-left text-[15px] font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="a.tone === 'danger' ? 'text-[#B91C1C]' : a.tone === 'muted' ? 'text-muted' : 'text-ink'" :disabled="busy" @click="$emit('pick', a.key)">
          <i :class="['bi w-5 shrink-0 text-center text-[17px]', a.icon]" aria-hidden="true"></i>
          <span class="min-w-0 flex-1">{{ a.label }}</span>
        </button>
      </li>
    </ul>
  </BottomSheet>
</template>

<script setup>
import BottomSheet from '@/components/BottomSheet.vue';

defineProps({
  open:     { type: Boolean, default: false },
  actions:  { type: Array, default: () => [] },   // [{ key, label, icon, tone? }]
  subtitle: { type: String, default: '' },
  busy:     { type: Boolean, default: false },
});
defineEmits(['close', 'pick']);
</script>
