<template>
  <article class="wm-card wm-card-link relative flex flex-col gap-3 h-full">
    <div class="flex items-start gap-3">
      <UserAvatar :name="displayName(provider)" :src="provider.avatarUrl" :size="52" class="lg:!w-14 lg:!h-14" />
      <div class="min-w-0 flex-1">
        <h3 class="text-[15px] lg:text-base font-semibold text-ink leading-snug">
          <!-- Stretched link makes the whole card clickable -->
          <router-link :to="`/provider/${provider.id}`" class="after:absolute after:inset-0 after:rounded-[20px] focus:outline-none focus-visible:after:outline-2 focus-visible:after:outline-primary focus-visible:after:outline-offset-2">
            {{ displayName(provider) }}
          </router-link>
          <VerifiedBadge :provider="provider" class="ml-1" />
        </h3>
        <p v-if="provider.businessName" class="wm-meta truncate">{{ provider.name }}</p>
        <p class="text-sm text-ink mt-1">{{ tradeLabel(provider) }}</p>
        <p class="wm-meta mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="inline-flex items-center gap-1">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>{{ provider.area }}
          </span>
          <span v-if="provider.rating" class="inline-flex items-center gap-1">
            <i class="bi bi-star-fill text-[#D97706]" aria-hidden="true"></i>
            <span class="text-ink font-medium">{{ provider.rating }}</span>
            <span>({{ provider.reviewCount }})</span>
            <span class="sr-only">rating from {{ provider.reviewCount }} reviews</span>
          </span>
        </p>
      </div>
      <SaveProviderButton :provider-id="provider.id" :name="displayName(provider)" class="-mr-2 -mt-1.5" />
    </div>

    <div class="flex flex-wrap gap-1.5">
      <AvailabilityBadge :provider="provider" show-detail />
    </div>

    <div class="mt-auto pt-1 flex flex-wrap items-center justify-between gap-x-2 gap-y-3">
      <p v-if="provider.priceFrom" class="text-sm min-w-0">
        <span class="wm-meta">From </span>
        <span class="font-semibold text-ink whitespace-nowrap">{{ formatPrice(provider.priceFrom) }}</span>
        <span class="wm-meta block truncate">{{ provider.priceBasis }}</span>
      </p>
      <span v-else class="wm-meta">Price on request</span>
      <div class="flex items-center gap-1.5 shrink-0">
        <!-- Sits above the stretched link so it gets its own click -->
        <router-link
          v-if="!isOwnProvider(provider.id)"
          :to="`/messages/${provider.id}`"
          class="relative z-10 wm-btn wm-btn-secondary !w-11 !px-0"
          :aria-label="`Message ${displayName(provider)}`"
        >
          <i class="bi bi-chat-dots text-base" aria-hidden="true"></i>
        </router-link>
        <span class="wm-btn wm-btn-tint pointer-events-none" aria-hidden="true">View Profile</span>
      </div>
    </div>
  </article>
</template>

<script setup>
import UserAvatar from './UserAvatar.vue';
import SaveProviderButton from './SaveProviderButton.vue';
import AvailabilityBadge from './AvailabilityBadge.vue';
import VerifiedBadge from './VerifiedBadge.vue';
import { displayName, tradeLabel, formatPrice } from '@/utils/ui.js';
import { isOwnProvider } from '@/stores/auth.js';

defineProps({
  provider: { type: Object, required: true },
});
</script>
