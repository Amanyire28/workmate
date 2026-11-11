<template>
  <!-- Workers worth a look, nearest and most trusted first (ProviderRecommendations) -->
  <section v-if="status !== 'empty'" class="wm-container pt-6 lg:pt-9" aria-labelledby="home-workers">
    <div class="flex items-center justify-between gap-4 mb-3 lg:mb-4">
      <div class="min-w-0">
        <h2 id="home-workers" class="text-xl font-semibold text-ink">{{ heading.title }}</h2>
        <p v-if="heading.note" class="mt-0.5 text-[13px] text-muted">
          {{ heading.note }}
          <button v-if="!originChosen" type="button" class="wm-link !text-[13px] font-medium min-h-8" @click="openLocationSheet">Choose your area</button>
        </p>
      </div>
      <router-link :to="seeAll" class="wm-link !text-sm inline-flex items-center gap-1 min-h-11 shrink-0">
        See all <i class="bi bi-arrow-right hidden lg:inline" aria-hidden="true"></i>
      </router-link>
    </div>

    <ul v-if="status === 'loading'" class="wm-worker-row" aria-busy="true" aria-label="Loading workers">
      <li v-for="n in 4" :key="n" class="wm-worker-row-item"><div class="wm-skeleton h-[236px] !rounded-[20px]"></div></li>
    </ul>

    <p v-else-if="status === 'error'" class="wm-glass-card rounded-[14px] p-4 text-sm text-[#64748B]">
      Workers couldn’t load.
      <button type="button" class="wm-link font-medium" @click="load">Try again</button>
    </p>

    <ul v-else class="wm-worker-row">
      <li v-for="p in workers" :key="p.id" class="wm-worker-row-item">
        <ProviderCard :provider="p" detailed />
      </li>
    </ul>
  </section>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import ProviderCard from './ProviderCard.vue';
import { api } from '@/api/client.js';
import { providers, catalog, appSettings } from '@/stores/catalog.js';
import { searchOrigin, originChosen, openLocationSheet, gpsPlaceName, currentAreaId } from '@/stores/location.js';
import { nearbySearch } from '@/stores/nearbySearch.js';
import { getSearchLocation } from '@/data/searchLocations.js';
import { capitalise } from '@/utils/nearbySearch.js';

const status = ref('loading');   // loading | ready | error | empty
const result = ref(null);

const gpsOn = computed(() => searchOrigin.value.kind === 'gps');
// Current location: its place only when it can be named surely; a picked place; else the default town
const areaId = computed(() => (gpsOn.value ? currentAreaId.value : searchOrigin.value.areaId) || (gpsOn.value ? '' : appSettings.defaultArea) || '');
// Current location: measured from where they are, like the nearby search. Rounded to
// about 1 km: enough to rank by, and it keeps their exact position out of the request.
const point = computed(() => (gpsOn.value
  ? { latitude: searchOrigin.value.latitude.toFixed(2), longitude: searchOrigin.value.longitude.toFixed(2) }
  : null));
const pointKey = computed(() => (point.value ? `${point.value.latitude},${point.value.longitude}` : ''));
// A service they searched for counts as what they're looking for
const category = computed(() => nearbySearch.result?.serviceIds?.[0] || '');

const workers = computed(() => (result.value?.ids || [])
  .map(id => providers.find(p => p.id === id))
  .filter(Boolean));

const placeName = computed(() => result.value?.area?.name || getSearchLocation(areaId.value)?.name || '');

/** Only "near you" when the place is theirs and the workers really are close */
const heading = computed(() => {
  const reach = result.value?.reach;
  const close = ['area', '5km', '15km'].includes(reach);
  if (!originChosen.value) {
    return { title: 'Discover workers', note: placeName.value ? `Around ${placeName.value}.` : '' };
  }
  const near = gpsOn.value ? 'near you' : `near ${placeName.value}`;
  // Current location: say which place that is, only as surely as the reading allows
  if (close) return { title: `Recommended workers ${near}`, note: gpsOn.value && gpsPlaceName.value ? `${capitalise(gpsPlaceName.value)}, where you are now.` : '' };
  const town = result.value?.area?.town;
  return {
    title: 'Recommended workers',
    note: reach === 'town' && town ? `Nobody close to ${whereText.value} yet, so these are from across ${town}.` : `Nobody close to ${whereText.value} yet, so these are from across WorkMate.`,
  };
});

// "you" for the current location, else the place
const whereText = computed(() => (gpsOn.value ? 'you' : placeName.value));

const seeAll = computed(() => ({ path: '/browse', query: category.value ? { category: category.value } : {} }));

let request = 0;
async function load() {
  const id = ++request;
  status.value = 'loading';
  try {
    const q = new URLSearchParams();
    if (areaId.value) q.set('area', areaId.value);
    if (point.value) { q.set('latitude', point.value.latitude); q.set('longitude', point.value.longitude); }
    if (category.value) q.set('category', category.value);
    const data = (await api(`/providers/recommended?${q}`)).data;
    if (id !== request) return;
    result.value = data;
    status.value = data.ids.length ? 'ready' : 'empty';
  } catch {
    if (id === request) status.value = 'error';
  }
}

// Again when the place or the service changes, once the providers have loaded
watch([areaId, pointKey, category, () => catalog.status], () => {
  if (catalog.status === 'ready') load();
}, { immediate: true });
</script>
