<template>
  <div>
    <!-- Stacked card on mobile, single pill on desktop -->
    <form
      role="search"
      novalidate
      class="bg-white shadow-[0_12px_32px_rgba(2,12,27,0.25)] rounded-[20px] p-1.5
             lg:rounded-full lg:flex lg:items-center lg:p-1.5 lg:max-w-[640px]"
      @submit.prevent="submit"
    >
      <div class="flex items-center h-[50px] lg:flex-1 min-w-0 pl-3.5 lg:pl-5 rounded-[14px] lg:rounded-full">
        <i class="bi bi-search text-[#64748B] text-[18px] mr-3" aria-hidden="true"></i>
        <label :for="inputId" class="sr-only">What service do you need?</label>
        <input
          :id="inputId"
          ref="input"
          v-model="query"
          type="search"
          enterkeyhint="search"
          autocomplete="off"
          placeholder="What service do you need?"
          class="flex-1 min-w-0 h-full bg-transparent text-base text-ink placeholder:text-[#64748B] placeholder:text-sm focus:outline-none"
          :aria-invalid="showEmptyError ? 'true' : undefined"
          :aria-describedby="showEmptyError ? errorId : undefined"
          @input="showEmptyError = false"
        />
      </div>

      <div class="h-px mx-3 bg-[#E2E8F0] lg:h-7 lg:w-px lg:mx-0" aria-hidden="true"></div>

      <!-- Location: opens the area sheet; the crosshair asks for the device position -->
      <div class="flex items-center gap-1 h-[50px] lg:w-[228px] pl-1.5 pr-1 lg:pl-2">
        <button
          type="button"
          class="flex-1 min-w-0 flex items-center h-11 px-2 rounded-xl text-left hover:bg-[#F1F5F9] transition-colors"
          aria-haspopup="dialog"
          @click="openLocationSheet"
        >
          <i
            class="bi text-[16px] mr-2.5"
            :class="usingGps ? 'bi-crosshair text-primary' : 'bi-geo-alt-fill text-ink'"
            aria-hidden="true"
          ></i>
          <span class="flex-1 min-w-0 text-[15px] font-medium truncate" :class="usingGps ? 'text-primary' : 'text-ink'">
            <span class="sr-only">Search location: </span>{{ originName }}<span class="sr-only">. Change</span>
          </span>
          <i class="bi bi-chevron-down text-xs text-[#64748B] ml-1.5" aria-hidden="true"></i>
        </button>
        <button
          type="button"
          class="wm-icon-btn !w-11 !h-11 !text-[18px]"
          :class="usingGps ? 'text-primary bg-primary-tint' : 'text-[#475569]'"
          :aria-label="gps.status === 'locating' ? 'Finding your location' : 'Use my location'"
          :aria-pressed="usingGps"
          :aria-busy="gps.status === 'locating'"
          :disabled="gps.status === 'locating'"
          @click="useDeviceLocation"
        >
          <span v-if="gps.status === 'locating'" class="wm-spinner !w-[18px] !h-[18px] text-primary" aria-hidden="true"></span>
          <i v-else class="bi bi-crosshair" aria-hidden="true"></i>
        </button>
      </div>

      <button type="submit" class="wm-btn wm-btn-primary wm-btn-block !min-h-[48px] mt-1 lg:mt-0 lg:!w-auto !rounded-[14px] lg:!rounded-full lg:!px-6 shrink-0">
        Find help
      </button>
    </form>

    <!-- Messages sit outside the card so the desktop pill keeps its shape -->
    <p
      v-if="showEmptyError"
      :id="errorId"
      class="mt-2.5 inline-flex items-center gap-2 text-sm"
      :class="onDark ? 'text-white px-3 py-2 rounded-xl bg-white/10' : 'text-error'"
      role="alert"
    >
      <i class="bi bi-info-circle" aria-hidden="true"></i>Type the service you need, for example “plumber”.
    </p>
    <p
      v-else-if="gps.status === 'error' && !locationSheetOpen"
      class="mt-2.5 flex items-start gap-2 text-sm max-w-[640px]"
      :class="onDark ? 'text-white px-3 py-2 rounded-xl bg-white/10' : 'wm-notice wm-notice-warning'"
      role="alert"
    >
      <i class="bi bi-geo-alt mt-px" aria-hidden="true"></i>
      <span>
        {{ gpsMessages[gps.error] }}
        <button type="button" class="wm-hit font-semibold underline underline-offset-2" @click="openLocationSheet">Choose an area</button>
      </span>
    </p>

    <SearchLocationSheet />
  </div>
</template>

<script setup>
import { ref, computed, useId } from 'vue';
import SearchLocationSheet from './SearchLocationSheet.vue';
import { getSearchLocation } from '@/data/searchLocations.js';
import {
  searchOrigin, openLocationSheet, locationSheetOpen,
  gps, gpsMessages, requestDeviceLocation,
} from '@/stores/location.js';

defineProps({
  /** Light message styling for use over the dark hero */
  onDark: { type: Boolean, default: false },
});
const emit = defineEmits(['search']);

const inputId = useId();
const errorId = useId();
const input = ref(null);
const query = ref('');
const showEmptyError = ref(false);

const usingGps = computed(() => searchOrigin.value.kind === 'gps');
const originName = computed(() =>
  usingGps.value ? 'Near me' : (getSearchLocation(searchOrigin.value.areaId)?.name || 'Kampala')
);

function submit() {
  const q = query.value.trim();
  if (!q) {
    showEmptyError.value = true;
    input.value?.focus();
    return;
  }
  input.value?.blur();   // closes the mobile keyboard so the radar is visible
  emit('search', q);
}

function useDeviceLocation() {
  // Errors are shown below the form; manual areas stay available
  requestDeviceLocation().catch(() => {});
}
</script>
