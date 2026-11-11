<template>
  <div class="wm-container wm-page">
    <BackLink to="/">Home</BackLink>
    <h1 class="wm-h1 mt-2">Top-rated providers</h1>
    <p class="wm-lead mt-1.5">
      <template v-if="place">Providers near {{ areaLabel(place) }} first, then the highest rated.</template>
      <template v-else>The highest rated providers across Kampala.</template>
    </p>

    <ul class="mt-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5 sm:gap-3 lg:gap-4">
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
import { providers, areaLabel } from '@/stores/catalog.js';
import { selectedArea, currentAreaId } from '@/stores/location.js';
import { recommendProviders } from '@/utils/recommend.js';

// A place picked by hand, else where they are now
const place = computed(() => selectedArea.value || currentAreaId.value);
const list = computed(() => recommendProviders(providers, place.value));
</script>
