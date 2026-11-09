<template>
  <div class="wm-container wm-page">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">Saved providers</h1>

    <div v-if="!saved.loaded" class="mt-5 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3" aria-busy="true">
      <div v-for="n in 3" :key="n" class="wm-skeleton h-[150px] !rounded-[20px]"></div>
    </div>

    <EmptyState v-else-if="!saved.list.length" class="mt-4" icon="bi-heart" title="No saved providers yet"
      message="Tap the heart on a provider to keep them here for later.">
      <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Find providers</router-link>
    </EmptyState>

    <template v-else>
      <ul v-if="available.length" class="mt-5 grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2.5 sm:gap-3 lg:gap-4">
        <li v-for="p in available" :key="p.id"><ProviderCard :provider="p" /></li>
      </ul>

      <!-- Saved, but no longer on WorkMate (suspended or stopped offering services) -->
      <section v-if="gone.length" class="mt-6" aria-labelledby="gone-heading">
        <h2 id="gone-heading" class="wm-meta mb-2 px-1 font-medium uppercase tracking-wide">No longer available</h2>
        <ul class="wm-card !p-1.5 flex flex-col">
          <li v-for="s in gone" :key="s.provider_id" class="flex items-center justify-between gap-3 px-3 py-2.5">
            <span class="text-sm text-muted">A provider you saved {{ agoLabel(s.saved_at).toLowerCase() }} isn’t on WorkMate any more.</span>
            <button type="button" class="wm-btn wm-btn-ghost !min-h-10 shrink-0" @click="toggleSaved(s.provider_id, 'them')">Remove</button>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import ProviderCard from '@/components/ProviderCard.vue';
import { providers, agoLabel, loadCatalog } from '@/stores/catalog.js';
import { saved, loadSaved, toggleSaved } from '@/stores/saved.js';

// Newest saved first; the cards come from the providers WorkMate lists
const available = computed(() => saved.list
  .filter(s => s.available && saved.ids.has(s.provider_id))
  .map(s => providers.find(p => p.id === s.provider_id))
  .filter(Boolean));
const gone = computed(() => saved.list.filter(s => !s.available || !providers.some(p => p.id === s.provider_id)));

onMounted(() => {
  loadCatalog().catch(() => {});
  loadSaved();
});
</script>
