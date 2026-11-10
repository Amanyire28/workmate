<template>
  <article class="wm-card wm-card-link relative flex flex-col h-full !p-0 overflow-hidden">
    <!-- Their cover photo, or the WorkMate blue banner -->
    <div class="relative h-16 sm:h-20 wm-provider-cover">
      <img v-if="provider.coverUrl" :src="provider.coverUrl" alt="" loading="lazy" decoding="async" class="absolute inset-0 h-full w-full object-cover" />
      <SaveProviderButton
        :provider-id="provider.id"
        :name="displayName(provider)"
        class="!absolute right-1.5 top-1.5 !h-9 !w-9 !min-h-9 rounded-full bg-white/90 shadow-sm"
      />
    </div>

    <div class="flex flex-1 flex-col items-center px-3 pb-3 text-center">
      <span class="relative -mt-8 rounded-full bg-white p-0.5 shadow-[0_4px_12px_rgba(15,23,42,0.12)]">
        <UserAvatar :name="displayName(provider)" :src="provider.avatarUrl" :size="60" />
      </span>

      <h3 class="mt-1.5 w-full text-[14.5px] font-semibold leading-snug text-ink line-clamp-2">
        <!-- Stretched link makes the whole card clickable -->
        <router-link :to="`/provider/${provider.id}`" class="after:absolute after:inset-0 after:rounded-[20px] focus:outline-none focus-visible:after:outline-2 focus-visible:after:outline-primary focus-visible:after:outline-offset-2">
          {{ displayName(provider) }}
        </router-link>
        <VerifiedBadge :provider="provider" class="ml-0.5" />
      </h3>
      <p class="w-full truncate text-[13px] text-ink-soft">{{ tradeLabel(provider) }}</p>
      <p class="mt-0.5 flex flex-wrap items-center justify-center gap-x-2 text-[12.5px] text-muted">
        <span class="inline-flex items-center gap-0.5"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ provider.area }}</span>
        <span v-if="provider.rating" class="inline-flex items-center gap-0.5">
          <i class="bi bi-star-fill text-[#D97706]" aria-hidden="true"></i>
          <span class="font-medium text-ink">{{ provider.rating }}</span>
          <span v-if="detailed">({{ provider.reviewCount }})</span>
          <span class="sr-only">rating from {{ provider.reviewCount }} reviews</span>
        </span>
      </p>
      <p v-if="detailed && provider.priceFrom" class="mt-1 text-[12.5px] text-muted">
        From <span class="font-semibold text-ink">{{ formatPrice(provider.priceFrom) }}</span><template v-if="provider.priceBasis"> / {{ provider.priceBasis }}</template>
      </p>
      <p v-if="provider.workCount" class="mt-1 inline-flex items-center gap-1 text-[12px] font-medium text-primary">
        <i class="bi bi-images" aria-hidden="true"></i>{{ provider.workCount }} {{ provider.workCount === 1 ? 'job' : 'jobs' }} shown
      </p>

      <div class="mt-auto flex w-full items-center gap-1.5 pt-3">
        <!-- Sits above the stretched link so it gets its own click -->
        <router-link
          v-if="!isOwnProvider(provider.id)"
          :to="`/messages/${provider.id}`"
          class="relative z-10 wm-btn wm-btn-secondary !min-h-10 !w-10 !px-0 shrink-0"
          :aria-label="`Message ${displayName(provider)}`"
        >
          <i class="bi bi-chat-dots text-base" aria-hidden="true"></i>
        </router-link>
        <span class="wm-btn wm-btn-tint !min-h-10 flex-1 !px-2 text-[13px] pointer-events-none" aria-hidden="true">View profile</span>
      </div>
    </div>
  </article>
</template>

<script setup>
import UserAvatar from './UserAvatar.vue';
import SaveProviderButton from './SaveProviderButton.vue';
import VerifiedBadge from './VerifiedBadge.vue';
import { displayName, tradeLabel, formatPrice } from '@/utils/ui.js';
import { isOwnProvider } from '@/stores/auth.js';

defineProps({
  provider: { type: Object, required: true },
  // Also the review count and starting price (home page)
  detailed: { type: Boolean, default: false },
});
</script>
