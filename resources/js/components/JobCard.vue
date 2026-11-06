<template>
  <article class="wm-card wm-card-link relative flex flex-col gap-3 h-full">
    <div class="flex items-start gap-3">
      <span class="wm-icon-tile !w-10 !h-10 !text-lg" aria-hidden="true">
        <i :class="['bi', categoryIcon(job.category)]"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h3 class="text-[17px] font-semibold text-ink leading-snug">
          <router-link :to="`/job/${job.id}`" class="after:absolute after:inset-0 after:rounded-[20px] focus:outline-none focus-visible:after:outline-2 focus-visible:after:outline-primary focus-visible:after:outline-offset-2">
            {{ job.title }}
          </router-link>
        </h3>
        <p class="wm-meta mt-0.5">{{ categoryLabel(job.category) }} · {{ job.postedAt }}</p>
      </div>
    </div>

    <p class="text-sm text-muted leading-normal line-clamp-2">{{ job.description }}</p>
    <p v-if="job.photoCount" class="wm-meta inline-flex items-center gap-1.5"><i class="bi bi-camera" aria-hidden="true"></i>{{ job.photoCount }} {{ job.photoCount === 1 ? 'photo' : 'photos' }}</p>

    <p class="wm-meta flex flex-wrap items-center gap-x-4 gap-y-2">
      <span class="inline-flex items-center gap-1.5"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ job.area }}</span>
      <span class="inline-flex items-center gap-1.5"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ job.date }}</span>
      <span v-if="job.workersNeeded > 1" class="inline-flex items-center gap-1.5"><i class="bi bi-people" aria-hidden="true"></i>{{ job.workersNeeded }} workers</span>
    </p>

    <div class="mt-auto pt-3 border-t border-line flex items-center justify-between gap-3">
      <p class="min-w-0">
        <template v-if="job.budget">
          <span class="text-base font-semibold text-ink">{{ formatPrice(job.budget) }}</span>
          <span class="wm-meta"> · {{ job.budgetBasis }}</span>
        </template>
        <span v-else class="wm-meta">Budget to be discussed</span>
      </p>
      <span class="wm-btn wm-btn-tint shrink-0 pointer-events-none" aria-hidden="true">View Job</span>
    </div>
  </article>
</template>

<script setup>
import { categoryIcon, categoryLabel, formatPrice } from '@/utils/ui.js';

defineProps({
  job: { type: Object, required: true },
});
</script>
