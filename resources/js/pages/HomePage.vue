<template>
  <div class="wm-home">

    <!-- ============ Hero ============
         Mobile: rounded photo card, text + stacked search over a dark gradient.
         Desktop: full-width navy band, photo on the right fading into the copy. -->
    <section class="relative overflow-hidden bg-[#0B1B33] rounded-b-[28px] lg:rounded-none" aria-labelledby="home-heading">
      <!-- Photo -->
      <!-- Mobile: photo fills the top of the card; desktop: right 62% of the band -->
      <div class="absolute inset-x-0 top-0 h-[210px] sm:h-[260px] lg:inset-y-0 lg:h-auto lg:left-auto lg:w-[62%]" aria-hidden="true">
        <img
          src="/images/hero/workmate-hero-1600.webp"
          srcset="/images/hero/workmate-hero-900.webp 900w, /images/hero/workmate-hero-1600.webp 1600w"
          sizes="(min-width: 1024px) 62vw, 100vw"
          width="1600"
          height="1067"
          alt=""
          class="w-full h-full object-cover object-[8%_28%] sm:object-[45%_30%] lg:object-[45%_35%] lg:[mask-image:linear-gradient(to_right,transparent,#000_32%)]"
          loading="eager"
          fetchpriority="high"
          decoding="async"
        />
      </div>
      <!-- Readability overlay (mobile: bottom-heavy; desktop: the image mask does the work) -->
      <div class="absolute inset-x-0 top-0 h-[211px] sm:h-[261px] lg:hidden bg-[linear-gradient(180deg,rgba(11,27,51,0.35)_0%,rgba(11,27,51,0)_25%,rgba(11,27,51,0.15)_50%,#0B1B33_100%)]" aria-hidden="true"></div>

      <div class="relative wm-container pt-4 pb-5 lg:py-11 xl:py-12">
        <!-- Location lives in the search form below, so the hero needs no separate picker -->
        <div class="mt-[112px] sm:mt-[150px] lg:mt-0 max-w-[560px] lg:max-w-[620px]">
          <p v-if="firstName" class="wm-rise mb-2 lg:mb-3 font-bold text-white leading-tight">
            <span class="block text-[16px] lg:text-[18px]">Welcome back,</span>
            <span class="block text-[22px] lg:text-[28px] break-words">{{ firstName }}</span>
          </p>
          <h1 id="home-heading" class="wm-rise text-[26px] sm:text-[32px] lg:text-[40px] leading-[1.12] font-bold tracking-[-0.025em] text-white">
            Trusted local services, right in <span class="text-[#4FA3FF]">Kampala</span>
          </h1>

          <!-- Service + location search: results appear just below the hero -->
          <ServiceSearchForm class="wm-rise wm-rise-delay-2 mt-4 lg:mt-5" on-dark @search="onSearch" />

          <!-- A quiet invitation to offer services; not shown to providers or to people who answered already -->
          <p v-if="earnLine" class="wm-rise wm-rise-delay-2 mt-3 text-[13.5px] text-white/70">
            {{ earnLine.ask }}
            <router-link :to="earnLine.to" class="ml-0.5 font-medium text-white underline-offset-4 hover:underline">{{ earnLine.link }} <span aria-hidden="true">→</span></router-link>
          </p>
        </div>
      </div>
    </section>

    <!-- ============ A suggestion for this person, while it applies ============ -->
    <div v-if="auth.user" class="wm-container pt-5 empty:hidden">
      <SuggestionCard page="home" />
    </div>

    <!-- ============ Nearby search: radar while searching, then results ============
         Renders nothing until the customer searches. -->
    <NearbySearchPanel ref="searchPanel" />

    <!-- ============ Recommended workers: near the chosen place first ============ -->
    <HomeRecommendedWorkers />

    <!-- ============ Recent jobs: providers apply from here ============ -->
    <HomeRecentJobs />

    <!-- ============ Categories ============ -->
    <section class="wm-container pt-6 lg:pt-9" aria-labelledby="home-categories">
      <div class="flex items-center justify-between gap-4 mb-3 lg:mb-4">
        <h2 id="home-categories" class="text-xl font-semibold text-ink">Browse by category</h2>
        <router-link to="/explore" class="wm-link !text-sm inline-flex items-center gap-1 min-h-11">
          See all <i class="bi bi-arrow-right hidden lg:inline" aria-hidden="true"></i>
        </router-link>
      </div>
      <ul class="grid grid-cols-4 sm:grid-cols-8 gap-2.5 lg:gap-4">
        <li v-for="c in popular" :key="c.id">
          <router-link
            :to="c.to"
            class="flex flex-col items-center justify-center text-center gap-2 h-[96px] sm:h-[108px] lg:h-[120px] px-1 wm-glass-card rounded-[14px] transition-[box-shadow,border-color,transform] hover:-translate-y-0.5 hover:border-[#CBD5E1] hover:shadow-[0_8px_20px_rgba(15,23,42,0.08)]"
          >
            <span
              class="inline-flex items-center justify-center w-11 h-11 lg:w-12 lg:h-12 rounded-full text-[20px] lg:text-[22px]"
              :style="{ background: c.color.bg, color: c.color.fg }"
              aria-hidden="true"
            ><i :class="['bi', c.icon]"></i></span>
            <span class="text-[12px] sm:text-[13px] lg:text-sm font-medium text-ink leading-tight">{{ c.label }}</span>
            <span v-if="c.count !== null" class="hidden lg:block -mt-1.5 text-xs text-[#64748B]">
              {{ c.count }} {{ c.count === 1 ? 'provider' : 'providers' }}
            </span>
          </router-link>
        </li>
      </ul>
    </section>

    <!-- ============ Recent community posts (hidden until there are some) ============ -->
    <HomeRecentPosts />

    <!-- Footer belongs to the website; the mobile app ends above the tab bar -->
    <AppFooter class="hidden lg:block" />
    <div class="h-3 lg:hidden" aria-hidden="true"></div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import ServiceSearchForm from '@/components/ServiceSearchForm.vue';
import NearbySearchPanel from '@/components/NearbySearchPanel.vue';
import AppFooter from '@/components/AppFooter.vue';
import HomeRecentPosts from '@/components/HomeRecentPosts.vue';
import HomeRecentJobs from '@/components/HomeRecentJobs.vue';
import HomeRecommendedWorkers from '@/components/HomeRecommendedWorkers.vue';
import SuggestionCard from '@/components/engagement/SuggestionCard.vue';
import { providers } from '@/stores/catalog.js';
import { startNearbySearch } from '@/stores/nearbySearch.js';
import { auth } from '@/stores/auth.js';
import { answeredHire } from '@/utils/getStarted.js';
import { categoryIcon, categoryColor, categoryLabel } from '@/utils/ui.js';

// Nearby-first search runs in place; the radar and results show under the hero
const searchPanel = ref(null);
async function onSearch(query) {
  startNearbySearch(query);
  await nextTick();
  searchPanel.value?.reveal();
}

// Offering services: a light line under the search, depending on where the person stands
// Signed in: greet them by their first name
const firstName = computed(() => (auth.user?.name || '').trim().split(/\s+/)[0] || '');

const earnLine = computed(() => {
  const status = auth.user ? auth.user.provider_status : null;
  if (status === 'draft') return { ask: 'Your provider details are saved.', link: 'Finish becoming a provider', to: '/start-earning' };
  if (status === 'more_info_required') return { ask: 'WorkMate asked you to fix something.', link: 'See what to change', to: '/start-earning' };
  // Providers, people waiting for a decision, and anyone who said they're here to hire see nothing
  if (status && status !== 'not_applied') return null;
  if (answeredHire()) return null;
  return { ask: 'Offer a service?', link: 'Start earning on WorkMate', to: auth.user ? '/start-earning' : '/register?redirect=/start-earning' };
});

// Seven categories that have providers today, then "More" for the full list
const popularIds = ['plumbing', 'electrical', 'cleaning', 'moving', 'carpentry', 'painting', 'repairs'];
const popular = [
  ...popularIds.map(id => ({
    id,
    label: id === 'moving' ? 'Moving' : categoryLabel(id),
    icon: categoryIcon(id),
    color: categoryColor(id),
    to: { path: '/browse', query: { category: id } },
    count: providers.filter(p => p.services.includes(id)).length,
  })),
  { id: 'more', label: 'More', icon: 'bi-grid', color: categoryColor(''), to: '/explore', count: null },
];
</script>
