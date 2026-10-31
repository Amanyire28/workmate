<template>
  <span
    class="inline-flex items-center justify-center shrink-0 rounded-full overflow-hidden font-semibold select-none text-primary bg-[linear-gradient(160deg,rgba(23,105,210,0.18),rgba(56,189,248,0.12))] shadow-[inset_0_1px_0_rgba(255,255,255,0.8),0_0_0_1px_rgba(255,255,255,0.6)]"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.36)}px` }"
    aria-hidden="true"
  >
    <img
      v-if="src && !failed"
      :src="src"
      alt=""
      loading="lazy"
      decoding="async"
      class="h-full w-full object-cover"
      @error="failed = true"
    />
    <template v-else>{{ initials(name) }}</template>
  </span>
</template>

<script setup>
import { ref, watch } from 'vue';
import { initials } from '@/utils/ui.js';

const props = defineProps({
  name: { type: String, required: true },
  size: { type: Number, default: 52 },
  // Profile photo; initials show when there is none or it can't load
  src:  { type: String, default: '' },
});

const failed = ref(false);
watch(() => props.src, () => { failed.value = false; });
</script>
