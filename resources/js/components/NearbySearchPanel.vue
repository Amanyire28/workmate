<template>
  <div ref="el" class="scroll-mt-[calc(var(--wm-header-h)+12px)]">
    <!-- One polite announcement per step, never per animation frame -->
    <p class="sr-only" aria-live="polite">{{ announcement }}</p>

    <Transition name="wm-swap" mode="out-in">
      <!-- Searching: radar + status -->
      <section
        v-if="view === 'searching'"
        key="searching"
        class="wm-container pt-6 lg:pt-8 lg:pb-10"
        aria-label="Search progress"
      >
        <div class="wm-scan-panel relative overflow-hidden rounded-[24px] p-5 sm:p-7 lg:px-10 lg:py-8 lg:max-w-[880px] lg:mx-auto
                    flex flex-col items-center text-center gap-5 lg:flex-row lg:text-left lg:gap-12">
          <LocationRadar :active="nearbySearch.stage !== 'done'" :range="range" :markers="markers" />

          <div class="relative min-w-0 flex-1 max-w-[440px]">
            <p class="text-[13px] font-medium text-[#7DD3FC] truncate">{{ eyebrow }}</p>
            <h2 class="mt-1.5 text-xl lg:text-2xl font-semibold text-white leading-snug">{{ statusText }}</h2>
            <p class="mt-1.5 text-sm text-white/70 min-h-[1.5em]">{{ detailText }}</p>

            <div class="mt-4 h-1 rounded-full bg-white/10 overflow-hidden" aria-hidden="true">
              <div class="wm-scan-progress h-full rounded-full" :style="{ width: `${progress}%` }"></div>
            </div>

            <!-- Hidden (not removed) once done, so the layout doesn't jump before the results -->
            <button
              type="button"
              class="mt-5 wm-btn !min-h-11 !px-5 text-white border-white/15 bg-white/10 hover:bg-white/15"
              :class="{ invisible: nearbySearch.stage === 'done' }"
              @click="cancelNearbySearch"
            >Cancel search</button>
          </div>
        </div>
      </section>

      <!-- Results (or a clear "nothing found") -->
      <section
        v-else-if="view === 'results'"
        key="results"
        class="wm-container pt-6 lg:pt-8 lg:pb-10"
        aria-label="Search results"
      >
        <NearbyProviderResults
          :result="nearbySearch.result"
          :show-wider="nearbySearch.showWider"
          @close="cancelNearbySearch"
          @show-wider="nearbySearch.showWider = true"
        />
      </section>

      <!-- Could not finish: location problem or failure -->
      <section v-else-if="view === 'error'" key="error" class="wm-container pt-6 lg:pt-8 lg:pb-10" aria-label="Search problem">
        <div class="wm-card">
          <EmptyState
            tone="error"
            :icon="isLocationError ? 'bi-geo-alt' : 'bi-exclamation-triangle'"
            :title="isLocationError ? 'We couldn’t get your location' : 'Your search didn’t finish'"
            :message="nearbySearch.error.message"
            class="!py-8"
          >
            <button type="button" class="wm-btn wm-btn-primary" @click="openLocationSheet">Choose an area</button>
            <button type="button" class="wm-btn wm-btn-secondary" @click="retryNearbySearch">Try again</button>
            <button type="button" class="wm-btn wm-btn-ghost" @click="cancelNearbySearch">Close</button>
          </EmptyState>
        </div>
      </section>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import LocationRadar from './LocationRadar.vue';
import NearbyProviderResults from './NearbyProviderResults.vue';
import EmptyState from './EmptyState.vue';
import { nearbySearch, STAGES, cancelNearbySearch, retryNearbySearch } from '@/stores/nearbySearch.js';
import { openLocationSheet } from '@/stores/location.js';
import { SEARCH_CONFIG, radarPosition, radarFraction, searchHeading, visibleGroups, capitalise, originPlaceName } from '@/utils/nearbySearch.js';
import { displayName, initials } from '@/utils/ui.js';

const el = ref(null);

const view = computed(() => ({ searching: 'searching', done: 'results', error: 'error' })[nearbySearch.status] || null);
const result = computed(() => nearbySearch.result);
const stageIndex = computed(() => STAGES.indexOf(nearbySearch.stage));
const isLocationError = computed(() => nearbySearch.error && nearbySearch.error.code !== 'failed');

/* ---------- Status copy ---------- */
const eyebrow = computed(() => (result.value ? searchHeading(result.value) : `Searching for “${nearbySearch.query}”`));

const statusText = computed(() => {
  const r = result.value;
  switch (nearbySearch.stage) {
    case 'locating': return 'Finding the right area…';
    case 'nearby':   return `Looking for professionals near ${r.origin.name}…`;
    case 'wider':    return 'Expanding your search area…';
    case 'done': {
      const n = visibleGroups(r, false).reduce((s, g) => s + g.items.length, 0);
      return n ? 'We found professionals for your search.' : 'Search complete.';
    }
    default:         return 'Getting your search ready…';
  }
});

const detailText = computed(() => {
  const r = result.value;
  if (!r) return nearbySearch.stage === 'locating' ? 'Checking the location you shared' : '';
  const p = r.origin.place;
  const where = r.origin.kind === 'gps'
    ? (p && (p.confidence === 'near' || p.confidence === 'around') ? `you (around ${p.name})` : 'you')
    : r.origin.name;
  if (nearbySearch.stage === 'nearby') return `Within ${SEARCH_CONFIG.nearbyKm} km of ${where}`;
  if (nearbySearch.stage === 'wider') return `Up to ${SEARCH_CONFIG.extendedKm} km, plus providers who travel to ${originPlaceName(r.origin)}`;
  return '';
});

const progress = computed(() => ({ init: 12, locating: 30, nearby: 55, wider: 82, done: 100 })[nearbySearch.stage] ?? 0);

/* ---------- Radar ---------- */
// Same scale as the markers, so the dashed ring really is the 5 km / 10 km line
const range = computed(() => {
  const { nearbyKm, extendedKm } = SEARCH_CONFIG;
  if (stageIndex.value >= STAGES.indexOf('wider') && result.value?.needsWider) return radarFraction(extendedKm);
  if (stageIndex.value >= STAGES.indexOf('nearby')) return radarFraction(nearbyKm);
  return 0.3;
});

// Providers with a known base appear as faint dots when their step starts, then light up as "found"
const markers = computed(() => {
  const r = result.value;
  if (!r) return [];
  const wider = r.needsWider ? [...r.groups.extended, ...r.groups.serving] : [];
  return [...r.groups.nearby, ...wider]
    .filter(item => item.distanceKm != null)
    .slice(0, 8)
    .filter(item => stageIndex.value >= STAGES.indexOf(item.tier === 'nearby' ? 'nearby' : 'wider'))
    .map(item => ({
      id: item.provider.id,
      ...radarPosition(r.origin, item.provider),
      initials: initials(displayName(item.provider)),
      state: nearbySearch.found.includes(item.provider.id) ? 'found' : 'scanning',
      far: item.tier !== 'nearby',
    }));
});

/* ---------- Screen reader announcements ---------- */
const announcement = computed(() => {
  if (nearbySearch.status === 'searching') return statusText.value;
  if (nearbySearch.status === 'done') {
    const r = result.value;
    const n = visibleGroups(r, nearbySearch.showWider).reduce((s, g) => s + g.items.length, 0);
    // The empty state announces itself
    return n ? `${n} ${n === 1 ? 'professional' : 'professionals'} found. ${capitalise(searchHeading(r))}.` : '';
  }
  if (nearbySearch.cancelled) return 'Search cancelled.';
  return '';
});

/* ---------- Page integration ---------- */
// Bring the panel into view when a search starts below the fold (e.g. under the hero on phones)
function reveal() {
  const node = el.value;
  if (!node) return;
  const top = node.getBoundingClientRect().top;
  if (top > window.innerHeight * 0.45 || top < 0) {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    node.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
  }
}

// Leaving the page mid-search stops it; finished results stay for when the user comes back
onBeforeUnmount(() => {
  if (nearbySearch.status === 'searching') cancelNearbySearch();
});

defineExpose({ reveal });
</script>

<style scoped>
.wm-scan-panel {
  background:
    radial-gradient(70% 90% at 0% 0%, rgba(23, 105, 210, 0.35), transparent 60%),
    radial-gradient(60% 80% at 100% 100%, rgba(34, 211, 238, 0.12), transparent 60%),
    linear-gradient(160deg, #0F2547 0%, #0A1A33 100%);
  border: 1px solid rgba(255, 255, 255, 0.08);
  box-shadow:
    0 20px 48px rgba(2, 12, 27, 0.22),
    inset 0 1px 0 rgba(255, 255, 255, 0.08);
}
.wm-scan-progress {
  background: linear-gradient(90deg, #1769D2, #38BDF8, #67E8F9);
  transition: width 0.6s cubic-bezier(0.2, 0.7, 0.2, 1);
}

.wm-swap-enter-active, .wm-swap-leave-active { transition: opacity 0.28s ease, transform 0.28s ease; }
.wm-swap-enter-from { opacity: 0; transform: translateY(10px); }
.wm-swap-leave-to   { opacity: 0; transform: translateY(-6px); }
</style>
