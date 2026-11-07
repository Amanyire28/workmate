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
          <h1 id="home-heading" class="wm-rise text-[26px] sm:text-[32px] lg:text-[40px] leading-[1.12] font-bold tracking-[-0.025em] text-white">
            Trusted local services, right in <span class="text-[#4FA3FF]">Kampala</span>
          </h1>
          <p class="wm-rise wm-rise-delay-1 mt-2 lg:mt-3 text-[14px] lg:text-[17px] text-white/85 leading-relaxed max-w-[460px]">
            Find skilled workers or get hired for your next job. Simple, local and on your phone.
          </p>

          <!-- Service + location search: results appear just below the hero -->
          <ServiceSearchForm class="wm-rise wm-rise-delay-2 mt-4 lg:mt-6" on-dark @search="onSearch" />
        </div>
      </div>
    </section>

    <!-- ============ Nearby search: radar while searching, then results ============
         Renders nothing until the customer searches. -->
    <NearbySearchPanel ref="searchPanel" />

    <!-- ============ Trust strip (website) ============ -->
    <section class="hidden lg:block wm-glass-bar" aria-label="Why WorkMate">
      <ul class="wm-container grid grid-cols-3 py-5">
        <li v-for="t in trust" :key="t.title" class="flex items-center justify-center gap-3">
          <i :class="['bi', t.icon, 'text-[26px] text-primary']" aria-hidden="true"></i>
          <div>
            <p class="text-sm font-semibold text-ink">{{ t.title }}</p>
            <p class="text-[13px] text-[#64748B]">{{ t.text }}</p>
          </div>
        </li>
      </ul>
    </section>

    <!-- ============ Recent community posts ============ -->
    <HomeRecentPosts />

    <!-- ============ Top rated workers ============ -->
    <section class="wm-container pt-8 lg:pt-12" aria-labelledby="home-top">
      <div class="flex items-center justify-between gap-4 mb-3 lg:mb-4">
        <h2 id="home-top" class="text-xl font-semibold text-ink">Top Rated Workers</h2>
        <router-link to="/workers" class="wm-link !text-sm inline-flex items-center gap-1 min-h-11">
          See all <i class="bi bi-arrow-right hidden lg:inline" aria-hidden="true"></i>
        </router-link>
      </div>

      <!-- Mobile app: list rows (3 on phones, 4 in two columns on tablets) -->
      <ul class="lg:hidden grid grid-cols-1 sm:grid-cols-2 gap-3">
        <li v-for="p in topRated" :key="p.id" class="[&:nth-child(4)]:hidden sm:[&:nth-child(4)]:block">
          <router-link :to="`/provider/${p.id}`" class="flex items-center gap-3.5 p-4 wm-glass-card rounded-[14px] hover:border-[#CBD5E1]">
            <UserAvatar :name="displayName(p)" :src="p.avatarUrl" :size="56" />
            <div class="min-w-0 flex-1">
              <p class="text-[15px] font-semibold text-ink truncate">
                {{ displayName(p) }} <VerifiedBadge :provider="p" />
              </p>
              <p class="text-[13px] text-[#64748B] truncate">{{ tradeLabel(p, 1) }} • {{ p.area }}</p>
              <p class="mt-1 text-[13px] text-[#64748B]">
                <i class="bi bi-star-fill text-[#F59E0B]" aria-hidden="true"></i>
                <span class="font-medium text-ink"> {{ p.rating }}</span> ({{ p.reviewCount }} reviews)
              </p>
              <p class="mt-1 text-[15px] font-semibold text-ink">
                {{ formatPrice(p.priceFrom) }}<span class="text-[13px] font-normal text-[#64748B]"> / {{ p.priceBasis }}</span>
              </p>
            </div>
            <i class="bi bi-chevron-right text-[#94A3B8]" aria-hidden="true"></i>
          </router-link>
        </li>
      </ul>

      <!-- Website: cards -->
      <ul class="hidden lg:grid grid-cols-4 gap-4">
        <li v-for="p in topRated" :key="p.id" class="flex flex-col wm-glass-card rounded-[14px] p-4 transition-shadow hover:shadow-[0_12px_28px_rgba(15,23,42,0.08)]">
          <div class="relative flex items-center justify-center h-[150px] rounded-[10px] overflow-hidden" :style="{ background: tintFor(p) }">
            <UserAvatar :name="displayName(p)" :src="p.avatarUrl" :size="84" class="!bg-white shadow-[0_6px_18px_rgba(15,23,42,0.12)]" />
            <span class="absolute top-2.5 left-2.5"><AvailabilityBadge :provider="p" /></span>
          </div>
          <p class="mt-3.5 text-base font-semibold text-ink truncate">{{ displayName(p) }} <VerifiedBadge :provider="p" /></p>
          <p class="text-[13px] text-[#64748B] truncate">{{ tradeLabel(p, 1) }} • {{ p.area }}</p>
          <p class="mt-2 text-[13px] text-[#64748B]">
            <i class="bi bi-star-fill text-[#F59E0B]" aria-hidden="true"></i>
            <span class="font-medium text-ink"> {{ p.rating }}</span> ({{ p.reviewCount }} reviews)
          </p>
          <p class="mt-2 text-[15px] font-semibold text-ink">
            {{ formatPrice(p.priceFrom) }}<span class="text-[13px] font-normal text-[#64748B]"> / {{ p.priceBasis }}</span>
          </p>
          <router-link :to="`/provider/${p.id}`" class="wm-btn wm-btn-primary !rounded-[10px] mt-4">
            View Profile<span class="sr-only">: {{ displayName(p) }}</span>
          </router-link>
        </li>
      </ul>
      <p class="mt-3 text-xs text-[#64748B]">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>Sample profiles shown while WorkMate is in development.
      </p>
    </section>

    <!-- ============ Categories ============ -->
    <section class="wm-container pt-8 lg:pt-12" aria-labelledby="home-categories">
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

    <!-- ============ Post a job CTA ============ -->
    <section class="wm-container pt-8 lg:pt-12" aria-labelledby="home-cta">
      <div class="flex flex-col sm:flex-row sm:items-center gap-5 lg:gap-8 rounded-[18px] bg-[#EFF6FF] border border-[#DBEAFE] p-6 lg:px-10 lg:py-8">
        <div class="flex items-start sm:items-center gap-4 lg:gap-6 flex-1">
          <span class="relative inline-flex items-center justify-center shrink-0 w-16 h-16 lg:w-20 lg:h-20 rounded-2xl bg-white text-primary text-[32px] lg:text-[40px] shadow-[0_6px_18px_rgba(23,105,210,0.15)]" aria-hidden="true">
            <i class="bi bi-clipboard-check"></i>
          </span>
          <div>
            <h2 id="home-cta" class="text-lg lg:text-xl font-semibold text-ink">Need a skilled worker?</h2>
            <p class="mt-1 text-sm text-[#475569] leading-relaxed max-w-[440px]">
              Post a job and get responses from professionals in your area.
            </p>
          </div>
        </div>
        <router-link to="/post-job" class="wm-btn wm-btn-primary wm-btn-lg !rounded-[12px] self-start sm:self-auto shrink-0 ml-20 sm:ml-0">
          Post a Job
        </router-link>
      </div>
    </section>

    <!-- ============ How it works (website) ============ -->
    <section class="hidden lg:block wm-container pt-14" aria-labelledby="home-how">
      <h2 id="home-how" class="text-xl font-semibold text-ink">How WorkMate Works</h2>
      <p class="mt-1 text-[13px] text-[#64748B]">Get started in 3 simple steps</p>
      <ol class="mt-6 grid grid-cols-3 gap-10">
        <li v-for="(s, i) in steps" :key="s.title">
          <span class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-[#EFF6FF] text-primary text-[22px]" aria-hidden="true">
            <i :class="['bi', s.icon]"></i>
          </span>
          <h3 class="mt-3 text-base font-semibold text-ink">{{ i + 1 }}. {{ s.title }}</h3>
          <p class="mt-1 text-sm text-[#64748B] leading-relaxed">{{ s.text }}</p>
        </li>
      </ol>
    </section>

    <!-- ============ Client feedback (website) ============ -->
    <section v-if="feedback.length" class="hidden lg:block wm-container pt-14 pb-16" aria-labelledby="home-feedback">
      <h2 id="home-feedback" class="text-xl font-semibold text-ink">What Clients Say</h2>
      <p class="mt-1 text-[13px] text-[#64748B]">Feedback left on completed jobs. Sample data while WorkMate is in development.</p>

      <div class="mt-6 flex items-center gap-4">
        <button type="button" class="wm-icon-btn wm-glass-card shrink-0" aria-label="Previous feedback" @click="go(-1)">
          <i class="bi bi-chevron-left text-base" aria-hidden="true"></i>
        </button>

        <figure class="flex-1 flex items-start gap-5 wm-glass-card rounded-[14px] p-6" aria-live="polite">
          <UserAvatar :name="current.clientName" :size="52" />
          <div class="min-w-0">
            <blockquote class="text-[15px] text-ink leading-relaxed">“{{ current.comment }}”</blockquote>
            <figcaption class="mt-2 text-[13px] text-[#64748B]">
              {{ current.clientName }}, {{ current.area }} · for
              <router-link :to="`/provider/${current.providerId}`" class="wm-hit inline-block text-primary font-medium hover:underline">{{ current.providerName }}</router-link>
            </figcaption>
            <p class="mt-1.5 text-[#F59E0B] text-sm" role="img" :aria-label="`${current.rating} out of 5`">
              <i v-for="n in 5" :key="n" :class="['bi', n <= current.rating ? 'bi-star-fill' : 'bi-star']" aria-hidden="true"></i>
            </p>
          </div>
        </figure>

        <button type="button" class="wm-icon-btn wm-glass-card shrink-0" aria-label="Next feedback" @click="go(1)">
          <i class="bi bi-chevron-right text-base" aria-hidden="true"></i>
        </button>
      </div>

      <div class="mt-3 flex justify-center" role="group" aria-label="Choose feedback">
        <button
          v-for="(f, i) in feedback"
          :key="f.id"
          type="button"
          class="inline-flex items-center justify-center w-11 h-11"
          :aria-label="`Show feedback ${i + 1} of ${feedback.length}`"
          :aria-current="i === slide ? 'true' : undefined"
          @click="slide = i"
        >
          <span class="block h-2 rounded-full transition-all" :class="i === slide ? 'w-5 bg-primary' : 'w-2 bg-[#CBD5E1]'"></span>
        </button>
      </div>
    </section>

    <!-- Footer belongs to the website; the mobile app ends above the tab bar -->
    <AppFooter class="hidden lg:block" />
    <div class="h-8 lg:hidden" aria-hidden="true"></div>
  </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import ServiceSearchForm from '@/components/ServiceSearchForm.vue';
import NearbySearchPanel from '@/components/NearbySearchPanel.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import AvailabilityBadge from '@/components/AvailabilityBadge.vue';
import AppFooter from '@/components/AppFooter.vue';
import HomeRecentPosts from '@/components/HomeRecentPosts.vue';
import { providers, providerReviews } from '@/stores/catalog.js';
import { startNearbySearch } from '@/stores/nearbySearch.js';
import { categoryIcon, categoryColor, displayName, tradeLabel, formatPrice, categoryLabel } from '@/utils/ui.js';

// Nearby-first search runs in place; the radar and results show under the hero
const searchPanel = ref(null);
async function onSearch(query) {
  startNearbySearch(query);
  await nextTick();
  searchPanel.value?.reveal();
}

const trust = [
  { icon: 'bi-patch-check', title: 'Verified providers',   text: 'Look for the tick on profiles' },
  { icon: 'bi-chat-dots',   title: 'Chat before you hire', text: 'Agree price and details first' },
  { icon: 'bi-people',      title: 'Local community',      text: 'Support local talent' },
];

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

const topRated = [...providers].sort((a, b) => (b.rating || 0) - (a.rating || 0)).slice(0, 4);

function tintFor(p) {
  return `linear-gradient(160deg, ${categoryColor(p.services[0]).bg}, #FFFFFF)`;
}

const steps = [
  { icon: 'bi-search',           title: 'Search or Post', text: 'Find the right service, or post your job in a few minutes.' },
  { icon: 'bi-chat-square-dots', title: 'Connect',        text: 'Message providers and agree on price and details.' },
  { icon: 'bi-tools',            title: 'Get It Done',    text: 'Meet, check the work, and pay the provider directly.' },
];

// Client feedback from completed jobs (the same data shown on provider profiles)
const feedback = providerReviews
  .filter(r => r.rating === 5 && r.wouldHireAgain)
  .slice(0, 5)
  .map(r => ({ ...r, providerName: displayName(providers.find(p => p.id === r.providerId)) }));
const slide = ref(0);
const current = computed(() => feedback[slide.value]);
function go(dir) {
  slide.value = (slide.value + dir + feedback.length) % feedback.length;
}
</script>
