<template>
  <div class="wm-container wm-page">
    <h1 class="wm-h1">Find work</h1>
    <p class="wm-lead mt-1.5">Open jobs posted by people and businesses across Kampala.</p>

    <div class="mt-4">
      <SearchBar
        v-model="searchText"
        id="find-work-search"
        label="Search jobs"
        placeholder="Search jobs, e.g. painting or Ntinda"
      />
    </div>

    <div class="mt-4 wm-scroll-row" role="group" aria-label="Job type">
      <button type="button" class="wm-chip" :aria-pressed="!filters.category" @click="setQuery({ category: '' })">
        All jobs
      </button>
      <button
        v-for="cat in jobCategories"
        :key="cat.id"
        type="button"
        class="wm-chip"
        :aria-pressed="filters.category === cat.id"
        @click="setQuery({ category: filters.category === cat.id ? '' : cat.id })"
      >
        <i :class="['bi', categoryIcon(cat.id)]" aria-hidden="true"></i>{{ cat.label }}
      </button>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
      <p class="wm-meta" aria-live="polite">
        {{ results.length }} open {{ results.length === 1 ? 'job' : 'jobs' }}<template v-if="filters.area"> in {{ filters.area }}</template>
      </p>
      <div class="flex items-center gap-2">
        <label for="find-work-area" class="sr-only">Area</label>
        <select
          id="find-work-area"
          class="wm-input !h-11 !w-auto !text-sm !pl-3 font-medium"
          :value="filters.area"
          @change="setQuery({ area: $event.target.value })"
        >
          <option value="">All areas</option>
          <option v-for="area in kampalaAreas" :key="area" :value="area">{{ area }}</option>
        </select>
      </div>
    </div>

    <ul v-if="results.length" class="mt-3 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
      <li v-for="job in results" :key="job.id">
        <JobCard :job="job" />
      </li>
    </ul>

    <EmptyState
      v-else
      icon="bi-briefcase"
      title="No jobs match"
      message="Try another job type or area, or clear your search. New jobs are posted every day."
    >
      <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="router.replace({ query: {} })">Clear filters</button>
    </EmptyState>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SearchBar from '@/components/SearchBar.vue';
import JobCard from '@/components/JobCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { categories, jobs, kampalaAreas } from '@/stores/catalog.js';
import { categoryIcon } from '@/utils/ui.js';

const route = useRoute();
const router = useRouter();

const queryKeys = ['q', 'category', 'area'];
const filters = computed(() =>
  Object.fromEntries(queryKeys.map(k => [k, typeof route.query[k] === 'string' ? route.query[k] : '']))
);

function setQuery(patch) {
  const next = { ...filters.value, ...patch };
  router.replace({ query: Object.fromEntries(Object.entries(next).filter(([, v]) => v)) });
}

const searchText = ref(filters.value.q);
watch(searchText, (q) => { if (q !== filters.value.q) setQuery({ q }); });
watch(() => filters.value.q, (q) => { if (q !== searchText.value) searchText.value = q; });

// Only offer chips for job types that currently have open jobs
const jobCategories = categories.filter(c => jobs.some(j => j.category === c.id));

const results = computed(() => {
  const f = filters.value;
  let list = jobs.filter(j => j.status === 'open');
  if (f.category) list = list.filter(j => j.category === f.category);
  if (f.area)     list = list.filter(j => j.area === f.area);
  const q = f.q.trim().toLowerCase();
  if (q) list = list.filter(j => `${j.title} ${j.description} ${j.area}`.toLowerCase().includes(q));
  return list;
});
</script>
