<template>
  <div>
    <div v-if="loading && !d" class="mt-1 flex flex-col gap-3" aria-busy="true" role="status">
      <span class="sr-only">Loading your numbers</span>
      <div v-for="n in 3" :key="n" class="grid grid-cols-2 gap-2"><div class="wm-card wm-skeleton h-[76px]"></div><div class="wm-card wm-skeleton h-[76px]"></div></div>
    </div>

    <p v-else-if="error && !d" class="wm-notice wm-notice-error" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ error }} <button type="button" class="wm-link" @click="loadDetail({ force: true })">Try again</button></span>
    </p>

    <template v-else-if="d">
      <section v-for="g in groups" :key="g.key" class="mb-6" :aria-labelledby="`perf-${g.key}`">
        <h2 :id="`perf-${g.key}`" class="text-[17px] font-semibold text-ink">{{ g.title }}</h2>
        <p class="wm-meta mt-0.5">{{ g.note }}</p>
        <div class="@container mt-3">
          <ul class="grid grid-cols-2 gap-2 @min-[28rem]:grid-cols-3" role="list">
            <li v-for="t in g.tiles" :key="t.label" class="min-w-0">
              <StatTile :to="t.to" :value="t.value" :label="t.label" />
            </li>
          </ul>
        </div>
      </section>

      <p class="wm-meta px-1">These are counted from real activity on WorkMate. Views and plays aren’t counted yet, so they aren’t shown.</p>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import StatTile from '@/components/space/StatTile.vue';
import { auth } from '@/stores/auth.js';
import { stats, loadDetail } from '@/stores/communityStats.js';

loadDetail();

const d = computed(() => stats.detail);
const loading = computed(() => stats.detailLoading);
const error = computed(() => stats.detailError);

const profile = computed(() => (auth.user ? `/community/people/${auth.user.id}` : '/community/people'));

const groups = computed(() => {
  const a = d.value.audience;
  const m = d.value.activity;
  return [
    {
      key: 'content', title: 'Your content', note: 'What you have published.',
      tiles: [
        { label: 'All posts', value: a.posts, to: '/my-space?tab=posts' },
        { label: 'Video posts', value: a.videos, to: '/my-space?tab=videos' },
      ],
    },
    {
      key: 'audience', title: 'Your audience', note: 'What other people did. Your own likes, comments and saves aren’t counted here.',
      tiles: [
        { label: 'Likes received', value: a.likes_received },
        { label: 'Comments received', value: a.comments_received },
        { label: 'Saves received', value: a.saves_received },
        { label: 'Followers', value: a.followers, to: profile.value },
      ],
    },
    {
      key: 'activity', title: 'Your own activity', note: 'What you did.',
      tiles: [
        { label: 'Following', value: m.following, to: profile.value },
        { label: 'Likes given', value: m.likes_given },
        { label: 'Comments written', value: m.comments_written },
        { label: 'Posts saved', value: m.posts_saved, to: '/community/saved' },
        { label: 'Providers saved', value: m.providers_saved, to: '/saved' },
      ],
    },
  ];
});
</script>
