<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader :title="title" />
    <div class="rounded-xl wm-surface">
      <StateBlock kind="empty" icon="bi-slash-circle" title="Service currently not available." />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import StateBlock from '../components/StateBlock.vue';

// Shown instead of a screen that has no real data behind it yet: it never displays sample or invented figures.
const route = useRoute();
const title = computed(() => route.meta?.pageLabel || 'Not available');
</script>
