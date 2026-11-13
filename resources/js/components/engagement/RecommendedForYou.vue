<template>
  <!-- Suggestions that suit this person: open jobs for providers, and posts on their topics -->
  <section v-if="jobs.length || posts.length" class="mb-5" aria-labelledby="for-you-heading">
    <h2 id="for-you-heading" class="text-lg font-semibold text-ink mb-2.5">Recommended for you</h2>

    <template v-if="jobs.length">
      <p class="text-sm text-muted mb-2">Open jobs in your line of work, near where you work, that you haven’t applied to yet.</p>
      <ul class="wm-home-row">
        <li v-for="job in jobs" :key="job.id" class="wm-home-row-item">
          <JobCard :job="job" />
        </li>
      </ul>
    </template>

    <template v-if="posts.length">
      <p class="text-sm text-muted mt-4 mb-2">From the community, on topics you care about</p>
      <ul class="flex flex-col gap-2">
        <li v-for="post in posts" :key="post.id">
          <router-link :to="post.url" class="wm-glass-card is-interactive rounded-[14px] p-3 flex items-start gap-3">
            <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-chat-square-text"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm text-ink line-clamp-2">{{ post.excerpt }}</span>
              <span class="block text-[12px] text-muted mt-0.5">{{ post.author }} · {{ agoLabel(post.created_at) }}</span>
            </span>
          </router-link>
        </li>
      </ul>
    </template>
  </section>
</template>

<script setup>
import { computed, watch } from 'vue';
import JobCard from '@/components/JobCard.vue';
import { auth } from '@/stores/auth.js';
import { engagement, loadRecommendations } from '@/stores/engagement.js';
import { toJob, agoLabel } from '@/stores/catalog.js';

// The server picks them (and never sends a job's exact location)
watch(() => auth.user?.id, (id) => { if (id) loadRecommendations(); }, { immediate: true });

const jobs = computed(() => (engagement.recommendations?.jobs || []).map(toJob));
const posts = computed(() => engagement.recommendations?.posts || []);
</script>
