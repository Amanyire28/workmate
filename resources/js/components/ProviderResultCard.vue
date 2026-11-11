<template>
  <article class="wm-card wm-card-link relative flex flex-col gap-3 h-full active:scale-[0.99] transition-transform">
    <div class="flex items-start gap-3">
      <UserAvatar :name="displayName(provider)" :src="provider.avatarUrl" :size="48" />
      <div class="min-w-0 flex-1">
        <h3 class="text-[15px] lg:text-base font-semibold text-ink leading-snug">
          <!-- Stretched link makes the whole card clickable -->
          <router-link :to="`/provider/${provider.id}`" class="after:absolute after:inset-0 after:rounded-[20px] focus:outline-none focus-visible:after:outline-2 focus-visible:after:outline-primary focus-visible:after:outline-offset-2">
            {{ displayName(provider) }}
          </router-link>
        </h3>
        <p class="wm-meta truncate">{{ tradeLabel(provider) }}</p>
      </div>
      <span
        v-if="item.distanceKm != null"
        class="shrink-0 inline-flex items-center h-7 px-2.5 rounded-full bg-primary-tint text-primary-hover text-[13px] font-semibold whitespace-nowrap"
      >
        {{ distanceText }}<span v-if="origin.kind !== 'gps'" class="sr-only"> from {{ origin.name }}</span>
      </span>
      <SaveProviderButton :provider-id="provider.id" :name="displayName(provider)" class="-mr-2 -mt-1.5" />
    </div>

    <ul class="wm-meta flex flex-col gap-1">
      <li class="flex items-center gap-1.5">
        <i class="bi bi-geo-alt w-4 text-center" aria-hidden="true"></i>
        <span>Based in {{ provider.area }}</span>
      </li>
      <li v-if="item.distanceKm == null" class="flex items-center gap-1.5">
        <i class="bi bi-dash-circle w-4 text-center" aria-hidden="true"></i>
        <span>Distance unknown: no map location on this profile</span>
      </li>
      <li v-if="item.serves && origin.area" class="flex items-center gap-1.5 text-success">
        <i class="bi bi-check2-circle w-4 text-center" aria-hidden="true"></i>
        <span>Serves {{ origin.kind === 'gps' ? `your area (${originPlaceName(origin)})` : origin.area.name }}</span>
      </li>
    </ul>

    <p class="text-sm text-[#475569] leading-relaxed line-clamp-2">{{ provider.description }}</p>

    <div class="mt-auto pt-1 flex items-center justify-between gap-3">
      <AvailabilityBadge :provider="provider" />
      <span class="wm-btn wm-btn-tint !min-h-10 pointer-events-none" aria-hidden="true">View profile</span>
    </div>
  </article>
</template>

<script setup>
import { computed } from 'vue';
import UserAvatar from './UserAvatar.vue';
import SaveProviderButton from './SaveProviderButton.vue';
import AvailabilityBadge from './AvailabilityBadge.vue';
import { displayName, tradeLabel } from '@/utils/ui.js';
import { formatKm, originPlaceName } from '@/utils/nearbySearch.js';

const props = defineProps({
  /** { provider, distanceKm, serves, tier } from searchNearby() */
  item:   { type: Object, required: true },
  origin: { type: Object, required: true },
});

const provider = computed(() => props.item.provider);

// Always an estimate: it's measured to the centre of the provider's registered
// area (rounded for their privacy), never their real or live position
const distanceText = computed(() => {
  const km = props.item.distanceKm;
  if (km < 1) return props.origin.kind === 'gps' ? '≈ under 1 km away' : 'Under 1 km';
  return props.origin.kind === 'gps' ? `≈ ${formatKm(km)} away` : `≈ ${formatKm(km)}`;
});
</script>
