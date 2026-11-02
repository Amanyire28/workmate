<template>
  <div class="wm-container wm-container-md wm-page !pt-3 lg:!pt-6">
    <BackLink to="/browse">Back to providers</BackLink>

    <EmptyState
      v-if="!provider"
      icon="bi-person-x"
      title="Provider not found"
      message="This profile doesn’t exist or is no longer available."
    >
      <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Browse providers</router-link>
    </EmptyState>

    <div v-else class="mt-2 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-4 lg:gap-x-8 lg:gap-y-6">

      <!-- Identity -->
      <section class="wm-card wm-card-lg" aria-labelledby="provider-name">
        <div class="flex flex-col items-center text-center sm:flex-row sm:items-center sm:text-left gap-4 lg:gap-6">
          <UserAvatar :name="name" :src="provider.avatarUrl" :size="96" class="lg:!w-28 lg:!h-28 lg:!text-[40px]" />
          <div class="min-w-0 flex flex-col gap-2 items-center sm:items-start">
            <h1 id="provider-name" class="text-2xl lg:text-[30px] font-bold leading-tight text-ink">
              {{ name }}
              <VerifiedBadge :provider="provider" class="text-[0.8em] ml-0.5" />
            </h1>
            <p v-if="provider.businessName" class="wm-meta -mt-1">{{ provider.name }}</p>
            <p class="text-[15px] text-ink">{{ tradeLabel(provider, 99) }}</p>
            <span v-if="provider.isSample" class="wm-pill wm-pill-neutral" title="An invented profile shown while WorkMate is in development">
              Sample profile
            </span>
            <p class="flex flex-wrap items-center justify-center sm:justify-start gap-x-4 gap-y-1 text-sm text-muted">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ provider.area }}</span>
              <span v-if="provider.rating" class="inline-flex items-center gap-1">
                <i class="bi bi-star-fill text-[#D97706]" aria-hidden="true"></i>
                <span class="font-semibold text-ink">{{ provider.rating }}</span>
                <span>· {{ provider.reviewCount }} reviews</span>
              </span>
              <span class="inline-flex items-center gap-1">
                <i :class="['bi', typeIcon]" aria-hidden="true"></i>{{ providerTypeLabels[provider.type] }}
              </span>
            </p>
            <div class="flex flex-wrap justify-center sm:justify-start gap-1.5 mt-1">
              <AvailabilityBadge :provider="provider" show-detail />
            </div>
          </div>
        </div>
      </section>

      <!-- Action panel: after identity on mobile, sticky right column on desktop -->
      <aside
        class="wm-card wm-card-lg !bg-white/75 lg:col-start-2 lg:row-start-1 lg:row-span-4 lg:self-start lg:sticky lg:top-[calc(var(--wm-header-h)+24px)]"
        aria-label="Hire this provider"
      >
        <dl class="flex flex-col gap-3">
          <div v-if="provider.priceFrom">
            <dt class="wm-meta">Starting price</dt>
            <dd class="text-lg font-semibold text-ink">
              {{ formatPrice(provider.priceFrom) }}
              <span class="text-sm font-normal text-muted">/ {{ provider.priceBasis }}</span>
            </dd>
          </div>
          <div>
            <dt class="wm-meta">Availability</dt>
            <dd class="mt-1 flex flex-col items-start gap-1.5">
              <AvailabilityBadge :provider="provider" show-detail />
              <span v-if="provider.availability" class="text-sm text-ink">{{ provider.availability }}</span>
            </dd>
          </div>
        </dl>

        <!-- Your own profile: how customers see it, with your tools instead of theirs -->
        <div v-if="isOwn" class="mt-4 flex flex-col gap-2">
          <p class="wm-notice wm-notice-info !text-[13px]">
            <i class="bi bi-person-badge" aria-hidden="true"></i><span>This is your profile, as customers see it.</span>
          </p>
          <router-link to="/account" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block">
            <i class="bi bi-pencil" aria-hidden="true"></i> Edit your profile
          </router-link>
          <router-link to="/activity?tab=received" class="wm-btn wm-btn-secondary wm-btn-lg wm-btn-block">
            <i class="bi bi-inbox" aria-hidden="true"></i> Requests you’ve received
          </router-link>
        </div>
        <div v-else class="mt-4 flex flex-col gap-2">
          <router-link :to="`/provider/${provider.id}/request`" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block">
            Request this service
          </router-link>
          <router-link :to="`/messages/${provider.id}`" class="wm-btn wm-btn-secondary wm-btn-lg wm-btn-block">
            <i class="bi bi-chat-dots" aria-hidden="true"></i> Message
          </router-link>
        </div>

        <p class="wm-notice wm-notice-warning mt-4 !text-[13px]">
          <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
          <span>Agree the price in WorkMate chat. Don’t pay before the work is agreed, and never send money to someone you haven’t met.</span>
        </p>
      </aside>

      <!-- About -->
      <section class="wm-card wm-card-lg flex flex-col gap-6" aria-label="About this provider">
        <div>
          <h2 class="text-[19px] lg:text-xl font-semibold text-ink">About</h2>
          <p class="mt-2 text-[15px] leading-relaxed text-ink/85">{{ provider.description }}</p>
        </div>

        <hr class="wm-divider" />

        <div>
          <h2 class="text-[19px] lg:text-xl font-semibold text-ink">Services</h2>
          <ul class="mt-3 flex flex-wrap gap-2">
            <li v-for="id in provider.services" :key="id">
              <router-link :to="{ path: '/browse', query: { category: id } }" class="wm-tag gap-1.5 hover:bg-primary/15">
                <i :class="['bi', categoryIcon(id)]" aria-hidden="true"></i>{{ categoryLabel(id) }}
              </router-link>
            </li>
          </ul>
        </div>

        <hr class="wm-divider" />

        <div>
          <h2 class="text-[19px] lg:text-xl font-semibold text-ink">Skills</h2>
          <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2.5">
            <li v-for="skill in provider.skills" :key="skill" class="flex items-start gap-2 text-[15px] text-ink">
              <i class="bi bi-check2 text-primary mt-0.5" aria-hidden="true"></i>{{ skill }}
            </li>
          </ul>
        </div>
      </section>

      <!-- Recent jobs & client feedback -->
      <section class="wm-card wm-card-lg" aria-labelledby="recent-jobs-heading">
        <h2 id="recent-jobs-heading" class="text-[19px] lg:text-xl font-semibold text-ink">Recent jobs</h2>

        <template v-if="summary">
          <p class="wm-meta mt-1">Feedback from the {{ summary.count }} most recent clients</p>

          <dl class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-2xl bg-success/8 px-4 py-3">
              <dt class="wm-meta">Would hire again</dt>
              <dd class="text-xl font-bold text-success">{{ summary.hireAgainPercent }}%</dd>
              <dd class="wm-meta">{{ summary.hireAgainCount }} of {{ summary.count }} clients</dd>
            </div>
            <div class="rounded-2xl bg-primary/8 px-4 py-3">
              <dt class="wm-meta">Client satisfaction</dt>
              <dd class="text-xl font-bold text-ink">{{ summary.averageSatisfaction }}<span class="text-sm font-medium text-muted"> / 5</span></dd>
              <dd class="wm-meta">{{ satisfactionLabels[Math.round(summary.averageSatisfaction)] }} on average</dd>
            </div>
          </dl>

          <ul class="mt-5 flex flex-col divide-y divide-line">
            <li v-for="r in reviews" :key="r.id" class="py-4 first:pt-0 last:pb-0">
              <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                <h3 class="text-[15px] font-semibold text-ink">{{ r.jobTitle }}</h3>
                <span :class="['wm-pill wm-pill-plain', r.wouldHireAgain ? 'wm-pill-success' : 'wm-pill-error']">
                  <i :class="['bi', r.wouldHireAgain ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-down-fill']" aria-hidden="true"></i>
                  {{ r.wouldHireAgain ? 'Would hire again' : 'Wouldn’t hire again' }}
                </span>
              </div>
              <p class="wm-meta mt-0.5">{{ r.clientName }} · {{ r.area }} · {{ r.completedAt }}</p>
              <p class="mt-2 flex items-center gap-2 text-sm">
                <span class="text-[#D97706] tracking-tight" aria-hidden="true">
                  <i v-for="n in 5" :key="n" :class="['bi', n <= r.rating ? 'bi-star-fill' : 'bi-star']"></i>
                </span>
                <span class="font-medium text-ink">{{ satisfactionLabels[r.rating] }}</span>
                <span class="sr-only">, {{ r.rating }} out of 5</span>
              </p>
              <p class="mt-1.5 text-[15px] text-ink/85">“{{ r.comment }}”</p>
            </li>
          </ul>
        </template>
        <p v-else class="mt-2 text-sm text-muted">No completed jobs on WorkMate yet.</p>
      </section>

      <!-- Trust & safety -->
      <section class="wm-card wm-card-lg" aria-labelledby="trust-heading">
        <h2 id="trust-heading" class="text-[19px] lg:text-xl font-semibold text-ink">Trust &amp; safety</h2>
        <p class="text-sm text-muted mt-2">
          For any job: agree the price in WorkMate chat, check the work before you pay, and meet in a safe place.
        </p>
        <template v-if="!isOwn">
          <hr class="wm-divider my-4" />
          <button type="button" class="wm-btn wm-btn-danger-ghost -ml-3" @click="reportOpen = true">
            <i class="bi bi-flag" aria-hidden="true"></i> Report this provider
          </button>
        </template>
      </section>
    </div>

    <ReportSheet v-if="provider && !isOwn" :open="reportOpen" about="provider" :provider-id="provider.id" :name="name" @close="reportOpen = false" />
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { isOwnProvider } from '@/stores/auth.js';
import { useRoute } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import AvailabilityBadge from '@/components/AvailabilityBadge.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import { providers, loadProviderReviews } from '@/stores/catalog.js';
import {
  displayName, tradeLabel, formatPrice, categoryIcon, categoryLabel, providerTypeLabels,
} from '@/utils/ui.js';
import { reviewsFor, feedbackSummary, satisfactionLabels } from '@/utils/trust.js';

const route = useRoute();

const provider = computed(() => providers.find(p => p.id === Number(route.params.id)));
const name = computed(() => (provider.value ? displayName(provider.value) : ''));
const reviews = computed(() => (provider.value ? reviewsFor(provider.value.id) : []));
const summary = computed(() => feedbackSummary(reviews.value));
const reportOpen = ref(false);
const isOwn = computed(() => !!provider.value && isOwnProvider(provider.value.id));

// The shared catalog holds only recent reviews; fetch this provider's full list
watch(() => route.params.id, (id) => {
  if (id) loadProviderReviews(Number(id)).catch(() => { /* the recent reviews still show */ });
}, { immediate: true });

const typeIcon = computed(() => ({
  individual: 'bi-person', team: 'bi-people', business: 'bi-shop',
}[provider.value?.type] || 'bi-person'));
</script>
