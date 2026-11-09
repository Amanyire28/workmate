<template>
  <router-link to="/" class="inline-flex items-center gap-2 h-11 rounded-lg shrink-0" aria-label="WorkMate home">
    <!-- "W" brand mark -->
    <svg width="30" height="24" viewBox="0 0 30 24" aria-hidden="true" class="shrink-0">
      <defs>
        <linearGradient id="wm-mark" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#2B7BE6" />
          <stop offset="1" stop-color="#1257B3" />
        </linearGradient>
      </defs>
      <path d="M2 3 L8.5 21 L15 8 L21.5 21 L28 3" fill="none" stroke="url(#wm-mark)" stroke-width="4.2" stroke-linecap="round" stroke-linejoin="round" />
      <circle cx="15" cy="3.2" r="2.4" fill="#38BDF8" />
    </svg>
    <span class="text-[20px] font-bold tracking-tight leading-none" :class="[light ? 'text-white' : 'text-ink', { 'hidden lg:inline': mobileTitle }]" aria-hidden="true">WorkMate</span>
    <!-- Phones: the section's name in place of the brand name -->
    <span v-if="mobileTitle" class="lg:hidden text-[20px] font-bold tracking-tight leading-none" :class="light ? 'text-white' : 'text-ink'" aria-hidden="true">{{ mobileTitle }}</span>
  </router-link>
</template>

<script setup>
defineProps({
  light: { type: Boolean, default: false },
  mobileTitle: { type: String, default: '' },
});
</script>
