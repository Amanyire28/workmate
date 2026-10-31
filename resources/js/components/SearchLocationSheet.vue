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
      <label for="area-filter" class="wm-label">Or choose an area</label>
      <input
        id="area-filter"
        v-model="filter"
        type="search"
        class="wm-input"
        placeholder="Search areas, e.g. Ntinda"
        autocomplete="off"
        enterkeyhint="search"
        aria-describedby="area-filter-hint"
      />
      <p id="area-filter-hint" class="wm-field-hint">Sample areas for the WorkMate preview, not a full list of places.</p>
    </div>

    <ul v-if="visible.length" class="mt-3 flex flex-col gap-1" aria-label="Sample areas">
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
            <span v-if="loc.town !== loc.name" class="block text-[13px] text-muted">{{ loc.town }}</span>
          </span>
          <i v-if="isSelected(loc)" class="bi bi-check-lg text-primary text-lg" aria-hidden="true"></i>
        </button>
      </li>
    </ul>
    <p v-else class="mt-4 text-sm text-muted" role="status">
      No sample area matches “{{ filter.trim() }}”. Try a nearby area or town.
    </p>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import { searchLocations } from '@/data/searchLocations.js';
import {
  searchOrigin, locationSheetOpen, chooseSearchArea,
  gps, gpsMessages, requestDeviceLocation,
} from '@/stores/location.js';

const filter = ref('');
watch(locationSheetOpen, (open) => { if (open) filter.value = ''; });

const visible = computed(() => {
  const q = filter.value.trim().toLowerCase();
  if (!q) return searchLocations;
  return searchLocations.filter(l => `${l.name} ${l.town}`.toLowerCase().includes(q));
});

const usingGps = computed(() => searchOrigin.value.kind === 'gps');
const isSelected = loc => searchOrigin.value.kind === 'area' && searchOrigin.value.areaId === loc.id;

function close() { locationSheetOpen.value = false; }

function choose(loc) {
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
