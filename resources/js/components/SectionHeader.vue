<template>
  <div class="flex items-center justify-between gap-4 mb-2 lg:mb-3">
    <h2 :id="id" class="wm-h2">{{ title }}</h2>
    <router-link v-if="linkTo" :to="linkTo" class="wm-link inline-flex items-center gap-1 min-h-11 shrink-0">
      {{ linkLabel }} <i class="bi bi-chevron-right text-[11px]" aria-hidden="true"></i>
    </router-link>
  </div>
</template>

<script setup>
defineProps({
  title:     { type: String, required: true },
  id:        { type: String, default: undefined },
  linkTo:    { type: [String, Object], default: null },
  linkLabel: { type: String, default: 'See all' },
});
</script>
