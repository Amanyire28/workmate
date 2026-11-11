<template>
  <div>
    <!-- Summary + quick changes, without going back to the top -->
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
      <div class="min-w-0">
        <p class="text-[13px] font-medium text-primary inline-flex items-center gap-1.5">
          <i :class="['bi', origin.kind === 'gps' ? 'bi-crosshair' : 'bi-geo-alt-fill']" aria-hidden="true"></i>
          Nearby search
        </p>
        <h2 :id="headingId" class="mt-1 text-xl lg:text-2xl font-semibold text-ink leading-tight">{{ searchHeading(result) }}</h2>
        <p v-if="total" class="mt-1 wm-meta">
          {{ total }} {{ total === 1 ? 'professional' : 'professionals' }} found, closest first
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 !px-4" @click="openLocationSheet">
          <i class="bi bi-geo-alt" aria-hidden="true"></i>Change location
        </button>
        <button type="button" class="wm-icon-btn wm-glass-card" aria-label="Close search results" @click="$emit('close')">
          <i class="bi bi-x-lg text-base" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <template v-if="total">
      <p v-if="!result.groups.nearby.length" class="wm-notice wm-notice-info mt-4">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>
          No {{ result.people }} found within {{ SEARCH_CONFIG.nearbyKm }} km yet.
          Showing the closest matches around {{ origin.town || 'you' }}<template v-if="hasServing">, including people who travel to {{ originPlaceName(origin) }}</template>.
        </span>
      </p>

      <section
        v-for="g in groups"
        :key="g.tier"
        class="mt-5"
        :aria-labelledby="showHeadings ? `${headingId}-${g.tier}` : headingId"
      >
        <h3 v-if="showHeadings" :id="`${headingId}-${g.tier}`" class="mb-3 text-base font-semibold text-ink">{{ groupHeading(g.tier) }}</h3>
        <ul class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 lg:gap-4">
          <li v-for="item in g.items" :key="item.provider.id">
            <ProviderResultCard :item="item" :origin="origin" />
          </li>
        </ul>
      </section>

      <div v-if="canShowMore" class="mt-4">
        <button type="button" class="wm-btn wm-btn-tint" @click="$emit('show-wider')">
          <i class="bi bi-arrows-angle-expand" aria-hidden="true"></i>
          Show more {{ result.people }} around {{ origin.town || 'you' }}
        </button>
      </div>

      <p class="mt-4 text-xs text-[#64748B] leading-relaxed">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>
        Distances are straight-line estimates to each provider’s registered base, not their live location<template v-if="origin.kind === 'area'">, measured from the centre of {{ origin.area.name }}</template>.
        <template v-if="hasSample">Includes sample providers.</template>
      </p>
    </template>

    <!-- Nothing to show: say so plainly and offer next steps -->
    <EmptyState v-else :icon="empty.icon" :title="empty.title" :message="empty.message" class="!py-8">
      <template v-if="result.matchedTotal">
        <button v-if="origin.area?.id !== 'kampala'" type="button" class="wm-btn wm-btn-primary" @click="chooseSearchArea('kampala')">
          Search all of Kampala
        </button>
        <button type="button" class="wm-btn wm-btn-secondary" @click="openLocationSheet">Change location</button>
        <router-link :to="browseLink" class="wm-btn wm-btn-secondary">Browse all {{ result.people }}</router-link>
      </template>
      <template v-else>
        <router-link to="/post-job" class="wm-btn wm-btn-primary">Post a job</router-link>
        <router-link to="/explore" class="wm-btn wm-btn-secondary">Explore services</router-link>
      </template>
    </EmptyState>
  </div>
</template>

<script setup>
import { computed, useId } from 'vue';
import ProviderResultCard from './ProviderResultCard.vue';
import EmptyState from './EmptyState.vue';
import { SEARCH_CONFIG, visibleGroups, searchHeading, originPlaceName } from '@/utils/nearbySearch.js';
import { openLocationSheet, chooseSearchArea } from '@/stores/location.js';

const props = defineProps({
  result:    { type: Object, required: true },
  showWider: { type: Boolean, default: false },
});
defineEmits(['close', 'show-wider']);

const headingId = useId();
const origin = computed(() => props.result.origin);
const groups = computed(() => visibleGroups(props.result, props.showWider));
const total = computed(() => groups.value.reduce((n, g) => n + g.items.length, 0));
const hasServing = computed(() => groups.value.some(g => g.tier === 'serving'));
const hasSample = computed(() => groups.value.some(g => g.items.some(i => i.provider.isSample)));

// Headings only help when there's more than one group and enough to group
const showHeadings = computed(() => groups.value.length > 1 && total.value > 2);

const canShowMore = computed(() => {
  const { groups: all, needsWider } = props.result;
  return !needsWider && !props.showWider && all.extended.length + all.serving.length > 0;
});

function groupHeading(tier) {
  const o = origin.value;
  const people = props.result.people;
  if (tier === 'nearby')   return o.kind === 'gps' ? 'Closest to you' : `Closest to ${o.name}`;
  if (tier === 'extended') return `More ${people} around ${o.town || 'you'}`;
  return `Providers serving ${originPlaceName(o)}`;
}

const browseLink = computed(() => {
  const ids = props.result.serviceIds;
  return { path: '/browse', query: ids.length === 1 ? { category: ids[0] } : { q: props.result.query } };
});

const empty = computed(() => {
  const r = props.result;
  if (r.matchedTotal) {
    return {
      icon: 'bi-geo',
      title: `No ${r.people} found near ${origin.value.name}`,
      message: `WorkMate has ${r.matchedTotal} ${r.matchedTotal === 1 ? 'match' : 'matches'} in other areas, but none based near ${origin.value.name} or listing it as an area they serve.`,
    };
  }
  if (r.serviceIds.length) {
    return {
      icon: 'bi-person-dash',
      title: `No ${r.people} on WorkMate yet`,
      message: `Nobody offers this yet. Post a job and let ${r.people} find you when they join.`,
    };
  }
  return {
    icon: 'bi-search',
    title: `No results for “${r.query}”`,
    message: 'Try a service such as plumber, electrician or cleaner.',
  };
});
</script>
