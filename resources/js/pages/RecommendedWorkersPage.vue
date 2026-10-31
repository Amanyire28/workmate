<template>
  <div class="wm-container wm-page">
    <BackLink to="/">Home</BackLink>
    <h1 class="wm-h1 mt-2">Top-rated providers</h1>
    <p class="wm-lead mt-1.5">
      <template v-if="selectedArea">Providers in {{ selectedArea }} first, then the highest rated across Kampala.</template>
      <template v-else>The highest rated providers across Kampala.</template>
    </p>

    <ul class="mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-4">
      <li v-for="p in list" :key="p.id">
        <ProviderCard :provider="p" />
      </li>
    </ul>

    <div class="mt-6 flex justify-center">
      <router-link to="/browse" class="wm-btn wm-btn-secondary">
        Search and filter all providers
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import BackLink from '@/components/BackLink.vue';
import ProviderCard from '@/components/ProviderCard.vue';
import { providers } from '@/stores/catalog.js';
import { selectedArea } from '@/stores/location.js';
import { recommendProviders } from '@/utils/recommend.js';

const list = computed(() => recommendProviders(providers, selectedArea.value));
</script>
