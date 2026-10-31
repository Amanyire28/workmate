<template>
  <div class="wm-container wm-container-md wm-page !pt-3 lg:!pt-6">
    <BackLink to="/find-work">Back to jobs</BackLink>

    <EmptyState
      v-if="!job"
      icon="bi-briefcase"
      title="Job not found"
      message="This job doesn’t exist or has been removed."
    >
      <router-link to="/find-work" class="wm-btn wm-btn-primary !min-h-12">Browse open jobs</router-link>
    </EmptyState>

    <div v-else class="mt-2 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-4 lg:gap-x-8 lg:gap-y-6">

      <!-- Title -->
      <section class="wm-card wm-card-lg" aria-labelledby="job-title">
        <div class="flex flex-wrap items-center gap-2">
          <StatusBadge :status="job.status" />
          <span class="wm-meta">Posted {{ job.postedAt }}</span>
        </div>
        <h1 id="job-title" class="mt-3 text-[22px] sm:text-[26px] lg:text-[32px] font-bold leading-tight text-ink">
          {{ job.title }}
        </h1>
        <p class="mt-2 inline-flex items-center gap-2 text-sm text-muted">
          <span class="wm-icon-tile !w-8 !h-8 !text-base !rounded-lg" aria-hidden="true">
            <i :class="['bi', categoryIcon(job.category)]"></i>
          </span>
          {{ categoryLabel(job.category) }}
        </p>
      </section>

      <!-- Summary + apply: after title on mobile, sticky side panel on desktop -->
      <aside
        class="wm-card wm-card-lg lg:col-start-2 lg:row-start-1 lg:row-span-2 lg:self-start lg:sticky lg:top-[calc(var(--wm-header-h)+24px)]"
        aria-label="Job summary"
      >
        <JobSummaryList :job="job" />
        <router-link
          v-if="job.status === 'open'"
          :to="`/job/${job.id}/apply`"
          class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block mt-5"
        >
          Apply for this job
        </router-link>
        <p class="wm-meta mt-3 text-center">
          {{ job.applicants }} {{ job.applicants === 1 ? 'person has' : 'people have' }} applied so far
        </p>
      </aside>

      <!-- Description -->
      <section class="wm-card wm-card-lg flex flex-col gap-6" aria-label="Job details">
        <div>
          <h2 class="text-lg lg:text-xl font-semibold text-ink">About this job</h2>
          <p class="mt-2 text-[15px] leading-relaxed text-ink/85">{{ job.description }}</p>
        </div>
        <hr class="wm-divider" />
        <div>
          <h2 class="text-lg lg:text-xl font-semibold text-ink">Before you apply</h2>
          <ul class="mt-3 flex flex-col gap-2.5 text-[15px] text-ink">
            <li class="flex gap-2"><i class="bi bi-check2 text-primary mt-0.5" aria-hidden="true"></i>Agree the price and start time before any work begins.</li>
            <li class="flex gap-2"><i class="bi bi-check2 text-primary mt-0.5" aria-hidden="true"></i>Keep your personal contact details private until you’ve agreed to do the job.</li>
          </ul>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import JobSummaryList from '@/components/JobSummaryList.vue';
import { jobs } from '@/stores/catalog.js';
import { categoryIcon, categoryLabel } from '@/utils/ui.js';

const route = useRoute();
const job = computed(() => jobs.find(j => j.id === Number(route.params.id)));
</script>
