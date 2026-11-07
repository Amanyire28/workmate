<template>
  <!-- Newest open jobs, so providers see what customers posted and can apply -->
  <section class="wm-container pt-8 lg:pt-12" aria-labelledby="home-jobs">
    <div class="flex items-center justify-between gap-4 mb-3 lg:mb-4">
      <div>
        <h2 id="home-jobs" class="text-xl font-semibold text-ink">Recent jobs</h2>
        <p class="text-[13px] text-[#64748B]">Posted by customers. Open one to see the details and apply.</p>
      </div>
      <router-link to="/find-work" class="wm-link !text-sm inline-flex items-center gap-1 min-h-11 shrink-0">
        See all <i class="bi bi-arrow-right hidden lg:inline" aria-hidden="true"></i>
      </router-link>
    </div>

    <div v-if="!recent.length" class="wm-glass-card rounded-[14px] p-5 flex flex-col sm:flex-row sm:items-center gap-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
      <p class="flex-1 text-sm text-[#475569]">No open jobs right now. Need something done? Post it and providers nearby can apply.</p>
      <router-link to="/post-job" class="wm-btn wm-btn-primary !rounded-[10px] shrink-0">Post a job</router-link>
    </div>

    <ul v-else class="wm-home-row">
      <li v-for="job in recent" :key="job.id" class="wm-home-row-item">
        <JobCard :job="job" />
      </li>
    </ul>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import JobCard from './JobCard.vue';
import { jobs } from '@/stores/catalog.js';

// Open jobs, newest first (the catalogue already loaded them for the app)
const recent = computed(() => jobs
  .filter(j => j.status === 'open')
  .sort((a, b) => String(b.postedAtIso || '').localeCompare(String(a.postedAtIso || '')))
  .slice(0, 6));
</script>
