<template>
  <router-link :to="to" class="inline-flex items-center gap-1.5 min-h-11 -ml-1 px-1 rounded-lg text-sm font-medium text-muted hover:text-ink">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>
    <slot />
  </router-link>
</template>

<script setup>
defineProps({
  to: { type: [String, Object], required: true },
});
</script>
