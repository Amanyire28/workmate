<template>
  <BottomSheet :open="locationSheetOpen" title="Search location" @close="close">
    <!-- Device location: asked for only when this is tapped -->
    <button
      type="button"
      class="w-full flex items-center gap-3 p-3.5 rounded-[16px] border text-left transition-colors"
      :class="usingGps ? 'border-primary bg-primary-tint' : 'border-line-strong bg-white hover:bg-[#F8FAFC]'"
      :aria-busy="gps.status === 'locating'"
      :disabled="gps.status === 'locating'"
      @click="useDeviceLocation"
    >
      <span class="wm-icon-tile !w-11 !h-11 !text-[20px]" aria-hidden="true">
        <span v-if="gps.status === 'locating'" class="wm-spinner !w-5 !h-5"></span>
        <i v-else class="bi bi-crosshair"></i>
      </span>
      <span class="flex-1 min-w-0">
        <span class="block text-[15px] font-semibold text-ink">Use my current location</span>
        <span class="block text-[13px] text-muted">
          <template v-if="gps.status === 'locating'">Finding your location…</template>
          <template v-else-if="usingGps">Searching near where you are now</template>
          <template v-else>Find professionals closest to where you are</template>
        </span>
      </span>
      <i v-if="usingGps" class="bi bi-check-circle-fill text-primary text-lg" aria-hidden="true"></i>
    </button>
    <p v-if="gps.status === 'error'" class="wm-notice wm-notice-error mt-3" role="alert">
      <i class="bi bi-exclamation-circle" aria-hidden="true"></i>{{ gpsMessages[gps.error] }}
    </p>

    <div class="mt-5">
      <label for="area-filter" class="wm-label">Or choose a place</label>
      <input
        id="area-filter"
        v-model="filter"
        type="search"
        class="wm-input"
        placeholder="Type a place, e.g. Kyebando"
        autocomplete="off"
        autocapitalize="words"
        enterkeyhint="search"
        aria-describedby="area-filter-hint"
        @input="search"
      />
      <p id="area-filter-hint" class="wm-field-hint">Any town, trading centre or village in Uganda.</p>
    </div>

    <p v-if="!query" class="mt-4 px-1 text-[12px] font-medium uppercase tracking-wide text-muted">Popular</p>
    <p v-if="query && loading && !visible.length" class="mt-4 text-sm text-muted" role="status">Searching…</p>
    <ul v-else-if="visible.length" class="mt-3 flex flex-col gap-1" :aria-label="query ? 'Matching places' : 'Popular places'">
      <li v-for="loc in visible" :key="loc.id">
        <button
          type="button"
          class="w-full flex items-center gap-3 min-h-[52px] px-3 rounded-[14px] text-left transition-colors"
          :class="isSelected(loc) ? 'bg-primary-tint' : 'hover:bg-[#F1F5F9]'"
          :aria-current="isSelected(loc) ? 'true' : undefined"
          @click="choose(loc)"
        >
          <i class="bi bi-geo-alt text-[17px]" :class="isSelected(loc) ? 'text-primary' : 'text-muted'" aria-hidden="true"></i>
          <span class="flex-1 min-w-0">
            <span class="block text-[15px] font-medium text-ink">{{ loc.wholeTown ? `All of ${loc.name}` : loc.name }}</span>
            <span v-if="(loc.district || loc.town) !== loc.name" class="block text-[13px] text-muted">{{ loc.district || loc.town }}</span>
          </span>
          <i v-if="isSelected(loc)" class="bi bi-check-lg text-primary text-lg" aria-hidden="true"></i>
        </button>
      </li>
    </ul>
    <p v-else-if="query" class="mt-4 text-sm text-muted" role="status">
      {{ failed ? 'Couldn’t search places. Check your connection and try again.' : `No place called “${query}”. Try a nearby town or trading centre.` }}
    </p>
    <p class="mt-5 text-[11.5px] text-muted">Place names: <a href="https://www.geonames.org/" target="_blank" rel="noopener" class="underline">GeoNames</a> (CC BY 4.0)</p>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import { searchLocations } from '@/data/searchLocations.js';
import { searchPlaces, areaById } from '@/stores/catalog.js';
import { resolveOrigin } from '@/utils/nearbySearch.js';
import {
  searchOrigin, locationSheetOpen, chooseSearchArea,
  gps, gpsMessages, requestDeviceLocation,
} from '@/stores/location.js';

const filter = ref('');
const results = ref([]);
const loading = ref(false);
const failed = ref(false);
watch(locationSheetOpen, (open) => { if (open) { filter.value = ''; results.value = []; } });

const query = computed(() => (filter.value.trim().length >= 2 ? filter.value.trim() : ''));
const visible = computed(() => (query.value ? results.value : searchLocations.filter(l => l.featured)));

// Every place in Uganda, searched as the letters come in; closest to the current search first
let timer = null;
let latest = 0;
function search() {
  clearTimeout(timer);
  if (!query.value) { results.value = []; return; }
  loading.value = true;
  timer = setTimeout(async () => {
    const mine = ++latest;
    try {
      const origin = resolveOrigin(searchOrigin.value);
      const found = await searchPlaces(query.value, origin);
      if (mine === latest) { results.value = found; failed.value = false; }
    } catch {
      if (mine === latest) { results.value = []; failed.value = true; }
    } finally {
      if (mine === latest) loading.value = false;
    }
  }, 220);
}

const usingGps = computed(() => searchOrigin.value.kind === 'gps');
const isSelected = loc => searchOrigin.value.kind === 'area' && searchOrigin.value.areaId === loc.id;

function close() { locationSheetOpen.value = false; }

function choose(loc) {
  if (!areaById(loc.id)) searchLocations.push(loc);
  chooseSearchArea(loc.id);
  close();
}

async function useDeviceLocation() {
  try {
    await requestDeviceLocation();
    close();
  } catch {
    // The message is shown above; area choices stay available
  }
}
</script>
