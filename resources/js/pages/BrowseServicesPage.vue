<template>
  <div class="wm-container wm-page">
    <h1 class="wm-h1">Find a service provider</h1>

    <div class="mt-4">
      <SearchBar
        v-model="searchText"
        id="browse-search"
        label="Search service providers"
        placeholder="Search a service, skill or name"
      />
    </div>

    <!-- Service type is chosen from one dropdown; on phones the filters sit beside it -->
    <div class="mt-4 flex items-center justify-between gap-3">
      <label class="wm-chip !h-11 !pr-9 min-w-0 max-w-[65%] lg:max-w-[320px] cursor-pointer">
        <i :class="['bi', filters.category ? categoryIcon(filters.category) : 'bi-grid']" aria-hidden="true"></i>
        <span class="truncate">{{ filters.category ? categoryLabel(filters.category) : 'All services' }}</span>
        <i class="bi bi-chevron-down absolute right-3.5 text-[12px]" aria-hidden="true"></i>
        <select
          class="absolute inset-0 z-10 w-full opacity-0 cursor-pointer"
          aria-label="Service type"
          :value="filters.category || ''"
          @change="setQuery({ category: $event.target.value })"
        >
          <option value="">All services</option>
          <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.label }}</option>
        </select>
      </label>
      <button
        type="button"
        class="lg:hidden wm-btn wm-btn-secondary !min-h-11 !px-3.5 relative shrink-0"
        :aria-label="`Filters${activeFilterCount ? `, ${activeFilterCount} active` : ''}`"
        @click="openSheet"
      >
        <i class="bi bi-sliders" aria-hidden="true"></i>
        <span aria-hidden="true">Filters</span>
        <span
          v-if="activeFilterCount"
          class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-primary text-white text-[11px]"
          aria-hidden="true"
        >{{ activeFilterCount }}</span>
      </button>
    </div>

    <div class="mt-4 lg:mt-6 lg:grid lg:grid-cols-[248px_minmax(0,1fr)] lg:gap-6 lg:items-start">
      <!-- Desktop filter sidebar: changes apply immediately -->
      <aside class="hidden lg:block wm-card !p-5 sticky top-[calc(var(--wm-header-h)+16px)]" aria-labelledby="sidebar-filters-title">
        <div class="flex items-center justify-between mb-4">
          <h2 id="sidebar-filters-title" class="text-base font-semibold text-ink">Filters</h2>
          <button
            v-if="activeFilterCount"
            type="button"
            class="wm-link min-h-11 px-1"
            @click="setQuery(emptyFilters)"
          >Clear all</button>
        </div>
        <BrowseFilters :model-value="sidebarValue" id-prefix="sidebar" @update:model-value="setQuery" />
      </aside>

      <section aria-labelledby="results-count">
        <!-- Read out to screen readers only -->
        <p id="results-count" class="sr-only" aria-live="polite">
          {{ results.length }} {{ results.length === 1 ? 'provider' : 'providers' }}
          <template v-if="filters.category"> for {{ categoryLabel(filters.category) }}</template>
          <template v-if="filters.area"> near {{ areaLabel(filters.area) || filters.area }}</template>
        </p>

        <ul v-if="results.length" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2.5 sm:gap-3 lg:gap-4">
          <li v-for="p in results" :key="p.id">
            <ProviderCard :provider="p" />
          </li>
        </ul>

        <EmptyState
          v-else
          icon="bi-search"
          title="No providers found"
          message="Try a different search, choose another area, or clear your filters. You can also post the job and let providers come to you."
        >
          <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="clearAll">Clear filters</button>
          <router-link to="/post-job" class="wm-btn wm-btn-secondary !min-h-12">Post a job</router-link>
        </EmptyState>
      </section>
    </div>

    <!-- Mobile/tablet filter sheet: edits a draft until "Apply" -->
    <BottomSheet :open="sheetOpen" title="Filters" @close="sheetOpen = false">
      <BrowseFilters v-model="draft" id-prefix="sheet" />
      <template #footer>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="draft = { ...emptyFilters }">Clear all</button>
        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" @click="applyDraft">Show results</button>
      </template>
    </BottomSheet>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import SearchBar from '@/components/SearchBar.vue';
import ProviderCard from '@/components/ProviderCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import BrowseFilters from '@/components/BrowseFilters.vue';
import { categories, providers, areaLabel } from '@/stores/catalog.js';
import { categoryIcon, categoryLabel, displayName, tradeLabel } from '@/utils/ui.js';
import { recommendProviders, preferMainSkill } from '@/utils/recommend.js';
import { categoriesMatching } from '@/utils/search.js';
import { tickInfo } from '@/utils/trust.js';
import { selectedArea } from '@/stores/location.js';
import { isNearPlace } from '@/utils/nearbySearch.js';
import { getSearchLocation, findSearchLocationByName } from '@/data/searchLocations.js';

const route = useRoute();
const router = useRouter();

const emptyFilters = { available: '', verified: '', area: '', type: '', rating: '', sort: '' };
const queryKeys = ['q', 'category', ...Object.keys(emptyFilters)];

// The URL query is the single source of truth for filters
const filters = computed(() => {
  const out = {};
  for (const key of queryKeys) out[key] = typeof route.query[key] === 'string' ? route.query[key] : '';
  return out;
});

const sidebarValue = computed(() => pick(filters.value, Object.keys(emptyFilters)));
const activeFilterCount = computed(() => Object.keys(emptyFilters).filter(k => filters.value[k]).length);

function setQuery(patch) {
  const next = { ...filters.value, ...patch };
  const query = {};
  for (const key of queryKeys) if (next[key]) query[key] = next[key];
  router.replace({ query });
}

function pick(obj, keys) {
  return Object.fromEntries(keys.map(k => [k, obj[k]]));
}

// Search box: local text kept in sync with ?q=
const searchText = ref(filters.value.q);
watch(searchText, (q) => { if (q !== filters.value.q) setQuery({ q }); });
watch(() => filters.value.q, (q) => { if (q !== searchText.value) searchText.value = q; });

// Mobile sheet
const sheetOpen = ref(false);
const draft = ref({ ...emptyFilters });

function openSheet() {
  draft.value = { ...sidebarValue.value };
  sheetOpen.value = true;
}
function applyDraft() {
  setQuery(draft.value);
  sheetOpen.value = false;
}

function clearAll() {
  router.replace({ query: {} });
}

const results = computed(() => {
  const f = filters.value;
  let list = providers;

  if (f.category)  list = list.filter(p => p.services.includes(f.category));
  if (f.available) list = list.filter(p => p.availabilityStatus === 'available');
  if (f.verified)  list = list.filter(p => tickInfo(p));
  // Near the place: based within 10 km of it, or they travel there
  if (f.area)     list = list.filter(p => isNearPlace(p, getSearchLocation(f.area) || findSearchLocationByName(f.area)));
  if (f.type)     list = list.filter(p => p.type === f.type);
  if (f.rating)   list = list.filter(p => (p.rating || 0) >= Number(f.rating));

  const q = f.q.trim().toLowerCase();
  let wanted = f.category ? [f.category] : [];
  if (q) {
    // "plumber" should find Plumbing providers, not just profiles containing the word
    const matchedCategories = categoriesMatching(q, categories);
    list = list.filter(p =>
      p.services.some(s => matchedCategories.includes(s)) ||
      [displayName(p), p.name, tradeLabel(p, 99), p.skills.join(' '), p.description, p.area]
        .join(' ').toLowerCase().includes(q)
    );
    if (!wanted.length) wanted = matchedCategories;
  }
  // Main skills first: other-skill providers only where nobody has it as their main one
  list = preferMainSkill(list, wanted);

  if (f.sort === 'rating') return [...list].sort((a, b) => (b.rating || 0) - (a.rating || 0));
  if (f.sort === 'price')  return [...list].sort((a, b) => (a.priceFrom ?? Infinity) - (b.priceFrom ?? Infinity));
  // Default order: providers in the user's area first, then by rating
  return recommendProviders(list, f.area || selectedArea.value);
});
</script>
