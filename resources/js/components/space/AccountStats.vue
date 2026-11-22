<template>
  <!-- Four real numbers from the member's own community activity, each a link to where it is explained -->
  <section aria-labelledby="account-stats-heading">
    <h2 id="account-stats-heading" class="sr-only">Your numbers</h2>

    <!-- Two by two on narrow screens or with larger text, four across when there is room for the labels
         (the width is measured in rem, so larger text switches to two by two on its own) -->
    <div class="@container">
      <ul class="grid grid-cols-2 gap-2 @min-[24rem]:grid-cols-4" role="list">
        <li v-for="tile in tiles" :key="tile.key" class="min-w-0">
          <StatTile :to="tile.to" :value="tile.value" :label="tile.label" :loading="loading" />
        </li>
      </ul>
    </div>

    <p v-if="error" class="wm-notice wm-notice-warning mt-2" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>We couldn’t load your numbers. <button type="button" class="wm-link" @click="loadSummary({ force: true })">Try again</button></span>
    </p>
    <p v-else-if="isEmpty" class="wm-meta mt-2 px-1">
      Nothing here yet. <router-link to="/community?compose=1" class="wm-link">Share your first post</router-link> and your numbers will start to grow.
    </p>
  </section>
</template>

<script setup>
import { computed, watch } from 'vue';
import StatTile from '@/components/space/StatTile.vue';
import { auth } from '@/stores/auth.js';
import { stats, loadSummary } from '@/stores/communityStats.js';

// Fetched for whoever is signed in now (and again if the account changes)
watch(() => auth.user && auth.user.id, (id) => { if (id) loadSummary(); }, { immediate: true });

const s = computed(() => stats.summary);
const loading = computed(() => !s.value && stats.summaryLoading);
const error = computed(() => !s.value && !!stats.summaryError);
const isEmpty = computed(() => !!s.value && s.value.posts === 0 && s.value.likes_received === 0 && s.value.followers === 0);

const tiles = computed(() => [
  { key: 'posts', label: 'All posts', value: s.value ? s.value.posts : null, to: '/my-space?tab=posts' },
  { key: 'videos', label: 'Video posts', value: s.value ? s.value.videos : null, to: '/my-space?tab=videos' },
  { key: 'likes', label: 'Likes received', value: s.value ? s.value.likes_received : null, to: '/my-space?tab=performance' },
  { key: 'followers', label: 'Followers', value: s.value ? s.value.followers : null, to: auth.user ? `/community/people/${auth.user.id}` : '/community/people' },
]);
</script>
