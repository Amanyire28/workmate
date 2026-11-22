<template>
  <div class="wm-container wm-page">
    <h1 class="wm-h1">My work</h1>
    <SuggestionCard page="work" class="mt-4" />

    <!-- Tabs -->
    <div class="mt-5 flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div :class="['wm-segmented grid sm:inline-grid', tabs.length === 4 ? 'grid-cols-4' : 'grid-cols-3']" role="tablist" aria-label="Activity">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          :id="`tab-${tab.id}`"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.id"
          :aria-controls="`panel-${tab.id}`"
          :tabindex="activeTab === tab.id ? 0 : -1"
          class="wm-hit wm-segment !px-2 sm:!px-4"
          @click="selectTab(tab.id)"
          @keydown.right.prevent="switchTab(1)"
          @keydown.left.prevent="switchTab(-1)"
        >
          {{ tab.label }}
          <span v-if="state === 'ready'" class="hidden sm:inline ml-1 font-normal text-muted">{{ tab.count() }}</span>
          <span v-if="tab.id === 'received' && waitingCount" class="ml-1 inline-flex min-w-5 h-5 px-1.5 items-center justify-center rounded-full bg-primary text-white text-[11px] font-semibold" :aria-label="`${waitingCount} waiting for your reply`">{{ waitingCount }}</span>
        </button>
      </div>
      <router-link v-if="currentTab.action" :to="currentTab.action.to" class="wm-btn wm-btn-ghost !px-2 self-end sm:self-auto">
        <i :class="['bi', currentTab.action.icon]" aria-hidden="true"></i>
        {{ currentTab.action.label }}
      </router-link>
    </div>

    <div
      :id="`panel-${activeTab}`"
      role="tabpanel"
      :aria-labelledby="`tab-${activeTab}`"
      :aria-busy="state === 'loading'"
      class="mt-4"
    >
      <!-- Loading -->
      <ul v-if="state === 'loading'" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4" aria-label="Loading">
        <li v-for="n in 3" :key="n" class="wm-card flex flex-col gap-3">
          <div class="wm-skeleton h-5 w-3/4"></div>
          <div class="wm-skeleton h-4 w-1/2"></div>
          <div class="wm-skeleton h-7 w-28 rounded-full"></div>
        </li>
      </ul>

      <!-- Error -->
      <EmptyState
        v-else-if="state === 'error'"
        tone="error"
        icon="bi-wifi-off"
        title="Couldn’t load your activity"
        message="Check your connection and try again."
      >
        <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load">Try again</button>
      </EmptyState>

      <!-- Requests customers sent to this provider -->
      <template v-else-if="activeTab === 'received'">
        <p v-if="actionError" class="wm-notice wm-notice-error mb-3" role="alert">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ actionError }}</span>
        </p>
        <MyWorkPanel v-model:stage="stage" :requests="received" class="mb-4" />
        <RecommendedForYou />
        <ul v-if="shownReceived.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          <ReceivedRequestCard v-for="req in shownReceived" :key="req.id" :req="req"
            @updated="replace" @message="messageCustomer"
            @report="reportFor = { about: 'customer', name: $event.customer.name.split(' ')[0], requestId: $event.id }" />
        </ul>
        <p v-else-if="received.length" class="wm-card wm-meta">Nothing here right now. <button type="button" class="wm-link" @click="stage = ''">Show everything</button></p>
        <EmptyState
          v-if="!received.length"
          icon="bi-inbox"
          title="No requests yet"
          message="When a customer asks you for a service, it shows up here so you can accept it or reply."
        >
          <router-link to="/find-work" class="wm-btn wm-btn-primary !min-h-12">Find work</router-link>
        </EmptyState>
      </template>

      <!-- Service requests sent to providers -->
      <template v-else-if="activeTab === 'requests'">
        <ul v-if="requests.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          <li v-for="req in requests" :id="`request-${req.id}`" :key="req.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <UserAvatar :name="req.providerName" :src="providerPhoto(req.providerId)" :size="44" />
                <div class="min-w-0">
                  <h2 class="text-base font-semibold text-ink leading-snug truncate">{{ req.providerName }}</h2>
                  <p class="wm-meta">{{ req.title || categoryLabel(req.service) }}</p>
                </div>
              </div>
            </div>
            <CustomerRequestStatus :req="req.raw" @updated="onRequestUpdated" @refresh="load({ quiet: true })" />
            <p class="text-sm text-ink line-clamp-2">{{ req.details }}</p>
            <RequestPhotos :photos="req.photos" label="Your photos of the job" />
            <p v-if="req.status === 'request_declined' && req.providerNote" class="text-sm text-muted">
              <i class="bi bi-reply" aria-hidden="true"></i> {{ req.providerName }}: {{ req.providerNote }}
            </p>
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ req.area }}</span>
              <span>Sent {{ sentLabel(req.sentAt) }}</span>
            </p>
            <p v-if="req.review" class="text-sm text-ink flex flex-wrap items-center gap-x-2">
              <span class="text-[#F59E0B]" :aria-label="`You gave ${req.review.rating} out of 5 stars`">
                <i v-for="n in 5" :key="n" :class="['bi', n <= req.review.rating ? 'bi-star-fill' : 'bi-star']" aria-hidden="true"></i>
              </span>
              <span class="text-muted">Your rating</span>
            </p>
            <div class="mt-auto pt-3 border-t border-line flex flex-wrap items-center justify-between gap-2">
              <router-link :to="`/provider/${req.providerId}`" class="wm-link inline-flex items-center gap-1 min-h-11">
                View provider <i class="bi bi-chevron-right text-[11px]" aria-hidden="true"></i>
              </router-link>
              <span class="flex gap-2">
                <button type="button" class="wm-btn wm-btn-ghost !min-h-11 text-muted" @click="reportFor = { about: 'provider', name: req.providerName, providerId: req.providerId, requestId: req.id }">
                  <i class="bi bi-flag" aria-hidden="true"></i>Report
                </button>
                <button v-if="req.status === 'completed' && !req.review" type="button" class="wm-btn wm-btn-primary !min-h-11" @click="rate(req)">
                  <i class="bi bi-star" aria-hidden="true"></i>Rate this job
                </button>
              </span>
            </div>
          </li>
        </ul>
        <EmptyState
          v-else
          icon="bi-people"
          title="No service requests yet"
          message="Find a provider for the job and send them a request. It will show up here."
        >
          <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Find a provider</router-link>
        </EmptyState>
      </template>

      <!-- Posted jobs -->
      <template v-else-if="activeTab === 'posted'">
        <ul v-if="postedJobs.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          <li v-for="job in postedJobs" :id="`request-${job.id}`" :key="job.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-base font-semibold text-ink leading-snug">{{ job.title }}</h2>
              <StatusBadge :status="job.status" class="shrink-0" />
            </div>
            <RequestPhotos :photos="job.photos" label="Your photos of the job" />
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ job.area }}</span>
              <span>Posted {{ job.postedAt }}</span>
            </p>
            <p v-if="job.review" class="text-sm text-ink flex flex-wrap items-center gap-x-2">
              <span class="text-[#F59E0B]" :aria-label="`You gave ${job.review.rating} out of 5 stars`">
                <i v-for="n in 5" :key="n" :class="['bi', n <= job.review.rating ? 'bi-star-fill' : 'bi-star']" aria-hidden="true"></i>
              </span>
              <span class="text-muted">Your rating</span>
            </p>
            <!-- The proposed and agreed price of the job someone was hired for (Phase B2) -->
            <div v-if="job.workerId && job.raw.pricing" class="flex flex-col gap-2">
              <PriceCard :pricing="job.raw.pricing" role="customer" />
              <PriceDecision :req="job.raw" :provider-name="job.workerName" @updated="onPostedUpdated" @refresh="load({ quiet: true })" />
              <PriceHistory :path="`/me/requests/${job.id}/pricing`" />
            </div>
            <!-- The final price and the payments the hired provider reported (Phase B3) -->
            <div v-if="job.workerId && job.raw.payments" class="flex flex-col gap-2">
              <PaymentSummary :payments="job.raw.payments" role="customer" />
              <PaymentAnswer :req="job.raw" :provider-name="job.workerName || 'The provider'" @updated="onPostedUpdated" @refresh="load({ quiet: true })" />
            </div>
            <div class="mt-auto pt-3 border-t border-line flex flex-wrap items-center justify-between gap-2 text-sm text-ink">
              <template v-if="job.workerId">
                <router-link :to="`/provider/${job.workerId}`" class="inline-flex items-center min-h-11">
                  <i class="bi bi-person-check text-muted mr-1.5" aria-hidden="true"></i>Worker:&nbsp;<strong class="font-semibold">{{ job.workerName }}</strong>
                </router-link>
                <span class="flex gap-2">
                  <router-link v-if="job.status === 'in_progress'" :to="`/messages/${job.workerId}`" class="wm-btn wm-btn-ghost !min-h-11">
                    <i class="bi bi-chat-dots" aria-hidden="true"></i>Message
                  </router-link>
                  <button v-if="job.status === 'completed' && !job.review" type="button" class="wm-btn wm-btn-primary !min-h-11" @click="rateJob(job)">
                    <i class="bi bi-star" aria-hidden="true"></i>Rate this job
                  </button>
                  <button type="button" class="wm-btn wm-btn-ghost !min-h-11 text-muted" :aria-label="`Report ${job.workerName}`" @click="reportFor = { about: 'provider', name: job.workerName, providerId: job.workerId, requestId: job.id }">
                    <i class="bi bi-flag" aria-hidden="true"></i>
                  </button>
                </span>
              </template>
              <template v-else-if="job.applicants">
                <span><i class="bi bi-people text-muted mr-1.5" aria-hidden="true"></i>{{ job.applicants }} {{ job.applicants === 1 ? 'person has' : 'people have' }} applied</span>
                <button type="button" class="wm-btn wm-btn-primary !min-h-11" @click="applicantsFor = job">See applicants</button>
              </template>
              <span v-else class="text-muted">No applications yet</span>
            </div>
          </li>
        </ul>
        <EmptyState
          v-else
          icon="bi-clipboard"
          title="No jobs posted yet"
          message="Most people find a provider directly. If you can’t, post the job and let providers apply."
        >
          <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Find a provider</router-link>
          <router-link to="/post-job" class="wm-btn wm-btn-secondary !min-h-12">Post a job</router-link>
        </EmptyState>
      </template>

      <!-- Applications -->
      <template v-else>
        <ul v-if="applications.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          <li v-for="app in applications" :key="app.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-base font-semibold text-ink leading-snug">{{ app.jobTitle }}</h2>
              <StatusBadge :status="app.status" class="shrink-0" />
            </div>
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ app.area }}</span>
              <span>Applied {{ app.appliedAt }}</span>
            </p>
            <p v-if="app.priceText" class="text-sm">
              <span class="font-semibold text-ink">{{ app.priceText }}</span>
              <span v-if="app.priceNote" class="text-muted"> · {{ app.priceNote }}</span>
            </p>
            <p v-if="app.status === 'accepted'" class="wm-notice wm-notice-success mt-auto">
              <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
              <span>Accepted<template v-if="app.clientName"> by {{ app.clientName }}</template>. Confirm the details with them before you start.</span>
            </p>
          </li>
        </ul>
        <EmptyState
          v-else
          icon="bi-send"
          title="No applications yet"
          message="Browse open jobs and apply for ones that match your skills."
        >
          <router-link to="/find-work" class="wm-btn wm-btn-primary !min-h-12">Find work</router-link>
        </EmptyState>
      </template>
    </div>
    <JobApplicantsSheet :job="applicantsFor" @close="applicantsFor = null" @hired="onHired" />
  <ReportSheet :open="!!reportFor" :about="reportFor?.about || 'provider'" :name="reportFor?.name || ''"
    :provider-id="reportFor?.providerId || null" :request-id="reportFor?.requestId || null" @close="reportFor = null" />
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import FormField from '@/components/FormField.vue';
import JobApplicantsSheet from '@/components/JobApplicantsSheet.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import { auth } from '@/stores/auth.js';
import { openRating } from '@/stores/reviews.js';
import { useRoute, useRouter } from 'vue-router';
import StatusBadge from '@/components/StatusBadge.vue';
import RequestPhotos from '@/components/RequestPhotos.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import MyWorkPanel from '@/components/work/MyWorkPanel.vue';
import SuggestionCard from '@/components/engagement/SuggestionCard.vue';
import RecommendedForYou from '@/components/engagement/RecommendedForYou.vue';
import ReceivedRequestCard from '@/components/work/ReceivedRequestCard.vue';
import CustomerRequestStatus from '@/components/work/CustomerRequestStatus.vue';
import PriceCard from '@/components/pricing/PriceCard.vue';
import PriceDecision from '@/components/pricing/PriceDecision.vue';
import PriceHistory from '@/components/pricing/PriceHistory.vue';
import PaymentSummary from '@/components/pricing/PaymentSummary.vue';
import PaymentAnswer from '@/components/pricing/PaymentAnswer.vue';
import { agoLabel, providers, appSettings } from '@/stores/catalog.js';
import { api } from '@/api/client.js';
import { formatPrice, categoryLabel } from '@/utils/ui.js';
import { budgetParts, budgetFromApi, estimateParts, estimateFromApi } from '@/utils/pricing.js';

/** What an application shows as price: the provider's own estimate, else the job's budget (as before until pricing options are on) */
function applicationPrice(a) {
  if (!appSettings.pricingEnabled) {
    return { priceText: formatPrice(a.amount || a.job.budget) || '', priceNote: a.amount ? 'your price' : (a.job.budget_basis || '') };
  }
  if (a.estimate_type || a.amount) {
    const e = estimateParts(estimateFromApi(a), true);
    return { priceText: e.main, priceNote: e.basis === 'estimate' ? 'your estimate' : e.basis };
  }
  const b = budgetParts(budgetFromApi(a.job), true);
  return { priceText: b.main || '', priceNote: b.main ? 'their budget' : '' };
}

const providerPhoto = id => providers.find(p => p.id === id)?.avatarUrl || '';

const route = useRoute();
const router = useRouter();

// Anyone with a provider profile sees the requests customers sent them
const isProvider = computed(() => !!auth.user && auth.user.provider_status && auth.user.provider_status !== 'not_applied');

const allTabs = [
  { id: 'received',     label: 'Received',    count: () => received.value.length, providerOnly: true, action: null },
  { id: 'requests',     label: 'Requests',    count: () => requests.value.length,
    action: { to: '/browse',    icon: 'bi-search',  label: 'Find a provider' } },
  { id: 'posted',       label: 'Jobs posted', count: () => postedJobs.value.length,
    action: { to: '/post-job',  icon: 'bi-plus-lg', label: 'Post a job' } },
  { id: 'applications', label: 'Applied',     count: () => applications.value.length,
    action: { to: '/find-work', icon: 'bi-search',  label: 'Find work' } },
];
const tabs = computed(() => allTabs.filter(t => !t.providerOnly || isProvider.value));

const activeTab = ref(allTabs.some(t => t.id === route.query.tab) ? route.query.tab : (isProvider.value ? 'received' : 'requests'));
const currentTab = computed(() => tabs.value.find(t => t.id === activeTab.value) || tabs.value[0]);

function selectTab(id) {
  activeTab.value = id;
  router.replace({ query: { ...route.query, tab: id } });
}

function switchTab(dir) {
  const list = tabs.value;
  const i = list.findIndex(t => t.id === activeTab.value);
  selectTab(list[(i + dir + list.length) % list.length].id);
  document.getElementById(`tab-${activeTab.value}`)?.focus();
}

function sentLabel(iso) {
  const d = new Date(iso);
  return d.toDateString() === new Date().toDateString()
    ? 'today'
    : d.toLocaleDateString('en-UG', { day: 'numeric', month: 'short' });
}

const state = ref('loading');
const received = ref([]);
// The provider's stage filter: new | upcoming | working | done, or '' for all
const stage = ref('');
const STAGE_STATUS = { new: 'open', upcoming: 'scheduled', working: 'in_progress', done: 'completed' };
// Upcoming bookings soonest first; everything else newest first
const shownReceived = computed(() => {
  const list = stage.value ? received.value.filter(r => r.status === STAGE_STATUS[stage.value]) : received.value;
  if (stage.value !== 'upcoming') return list;
  return [...list].sort((a, b) => (a.schedule?.start || '').localeCompare(b.schedule?.start || ''));
});
const requests = ref([]);
const postedJobs = ref([]);
const applications = ref([]);

// An open request sent to one provider is waiting for their reply;
// an open posted job is waiting for applications
function toPostedJob(j) {
  return {
    id: j.id,
    title: j.title,
    category: j.category,
    area: j.area_name,
    postedAt: agoLabel(j.posted_at).toLowerCase(),
    status: JOB_STATUS[j.status] || j.status,
    // Who was hired, once someone is
    workerId: j.provider?.id || null,
    workerName: j.provider?.display_name || null,
    applicants: j.applicants,
    review: j.review || null,
    photos: j.photos || [],
    raw: j,
  };
}


/** A request the customer sent, for its card (raw: as the API sent it, for the status panel) */
function toRequest(r) {
  return {
    id: r.id,
    providerId: r.provider.id,
    providerName: r.provider.display_name,
    service: r.category,
    details: r.description,
    area: r.area_name,
    when: r.preferred_time,
    sentAt: r.posted_at,
    // A cancelled request with a note was declined by the provider
    status: r.status === 'cancelled' && !r.provider_note ? 'cancelled' : (REQUEST_STATUS[r.status] || r.status),
    providerNote: r.provider_note || '',
    category: r.category,
    review: r.review || null,
    photos: r.photos || [],
    raw: r,
    title: r.title,
  };
}

function onRequestUpdated(r) {
  requests.value = requests.value.map(x => (x.id === r.id ? { ...toRequest(r), review: x.review } : x));
}

const REQUEST_STATUS = { open: 'pending', in_progress: 'accepted', cancelled: 'request_declined' };
// The provider's side of the same request
const RECEIVED_STATUS = { open: 'new_request', in_progress: 'accepted', cancelled: 'request_declined' };
const waitingCount = computed(() => received.value.filter(r => r.status === 'open').length);
const JOB_STATUS = { open: 'waiting' };

// quiet: reload the lists without the loading skeleton, so a message on a card (a price that changed) stays readable
async function load({ quiet = false } = {}) {
  if (!quiet) state.value = 'loading';
  try {
    const [mine, applied, incoming] = await Promise.all([
      api('/me/requests'), api('/me/applications'),
      isProvider.value ? api('/me/received-requests') : Promise.resolve({ data: [] }),
    ]);
    received.value = incoming.data;

    requests.value = mine.data.filter(r => r.kind === 'direct').map(toRequest);

    postedJobs.value = mine.data.filter(r => r.kind === 'posted').map(toPostedJob);

    applications.value = applied.data.map(a => ({
      id: a.id,
      jobTitle: a.job.title,
      area: a.job.area_name,
      appliedAt: agoLabel(a.applied_at).toLowerCase(),
      status: a.status,
      clientName: null,
      ...applicationPrice(a),
    }));

    state.value = 'ready';
    showLinkedRequest();
  } catch {
    state.value = 'error';
  }
}

// A notification opens the tab with ?request=ID: bring that request into view, once
let linkedShown = false;
async function showLinkedRequest() {
  const id = Number(route.query.request);
  if (linkedShown || !id) return;
  linkedShown = true;
  await nextTick();
  const el = document.getElementById(`request-${id}`);
  if (el) el.scrollIntoView({ block: 'center' });
}

onMounted(load);

/* ---------------- Reporting someone about a request or job ---------------- */
const reportFor = ref(null);   // { about, name, providerId?, requestId }

/* ---------------- Choosing someone for a posted job ---------------- */
const applicantsFor = ref(null);
// An answer to a price on a posted job comes back as the whole job
function onPostedUpdated(job) {
  postedJobs.value = postedJobs.value.map(j => (j.id === job.id ? toPostedJob(job) : j));
}
function onHired(job) {
  postedJobs.value = postedJobs.value.map(j => (j.id === job.id ? toPostedJob(job) : j));
}
function rateJob(job) {
  openRating({
    request_id: job.id, category: job.category,
    provider: { id: job.workerId, display_name: job.workerName, avatar_url: providerPhoto(job.workerId) },
  });
}

/* ---------------- Rating finished jobs ---------------- */
function rate(req) {
  openRating({
    request_id: req.id, category: req.category,
    provider: { id: req.providerId, display_name: req.providerName, avatar_url: providerPhoto(req.providerId) },
  });
}
// A rating posted from the pop-up shows on the card straight away
function onRated(e) {
  requests.value = requests.value.map(r => (r.id === e.detail.requestId ? { ...r, review: e.detail.review } : r));
  postedJobs.value = postedJobs.value.map(j => (j.id === e.detail.requestId ? { ...j, review: e.detail.review } : j));
}
onMounted(() => window.addEventListener('workmate:rated', onRated));
onBeforeUnmount(() => window.removeEventListener('workmate:rated', onRated));

/* ---------------- Answering received requests ---------------- */
const busy = ref(null);
const actionError = ref('');

function replace(updated) {
  received.value = received.value.map(r => (r.id === updated.id ? { ...updated, conversation_id: updated.conversation_id ?? r.conversation_id } : r));
}




async function messageCustomer(req) {
  busy.value = req.id;
  actionError.value = '';
  try {
    const id = req.conversation_id || (await api(`/me/received-requests/${req.id}/message`, { method: 'POST', body: {} })).data.conversation_id;
    router.push(`/messages/c/${id}`);
  } catch (e) {
    actionError.value = e.message;
  } finally {
    busy.value = null;
  }
}
</script>
