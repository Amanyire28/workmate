<template>
  <div class="wm-container wm-page">
    <h1 class="wm-h1">My work</h1>
    <p class="wm-lead mt-1.5">{{ isProvider ? "Requests customers sent you, providers you’ve contacted, jobs you’ve posted and jobs you’ve applied for." : "Providers you’ve contacted, jobs you’ve posted and jobs you’ve applied for." }}</p>

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
        <ul v-if="received.length" class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
          <li v-for="req in received" :key="req.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <UserAvatar :name="req.customer.name" :src="req.customer.avatar_url || ''" :size="44" />
                <div class="min-w-0">
                  <h2 class="text-base font-semibold text-ink leading-snug truncate">{{ req.customer.name }}</h2>
                  <p class="wm-meta">{{ categoryLabel(req.category) }}</p>
                </div>
              </div>
              <StatusBadge :status="RECEIVED_STATUS[req.status] || req.status" class="shrink-0" />
            </div>
            <p class="text-sm text-ink whitespace-pre-line">{{ req.description }}</p>
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ req.area_name }}</span>
              <span class="inline-flex items-center gap-1"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ req.preferred_time }}</span>
              <span v-if="req.budget" class="inline-flex items-center gap-1"><i class="bi bi-cash" aria-hidden="true"></i>{{ formatPrice(req.budget) }}</span>
              <span>Received {{ sentLabel(req.received_at) }}</span>
            </p>
            <p v-if="req.status === 'cancelled' && req.provider_note" class="text-sm text-muted">
              <i class="bi bi-reply" aria-hidden="true"></i> You declined: {{ req.provider_note }}
            </p>

            <!-- Declining asks why; the customer sees the reason -->
            <form v-if="declining === req.id" class="flex flex-col gap-2" @submit.prevent="decline(req)">
              <FormField v-model="declineReason" type="textarea" label="Why can’t you take it?" :id="`decline-${req.id}`" required
                placeholder="e.g. I’m fully booked this week" :min-height="72" hint="The customer sees this." />
              <div class="flex gap-2">
                <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="declining = null">Back</button>
                <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy === req.id || declineReason.trim().length < 3">Send</button>
              </div>
            </form>

            <div v-else class="mt-auto pt-3 border-t border-line flex flex-wrap gap-2">
              <template v-if="req.status === 'open'">
                <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy === req.id" @click="act(req, 'accept')">
                  <i class="bi bi-check2" aria-hidden="true"></i>Accept
                </button>
                <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy === req.id" @click="startDecline(req)">Decline</button>
              </template>
              <button v-else-if="req.status === 'in_progress'" type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy === req.id" @click="act(req, 'complete')">
                <i class="bi bi-check2-all" aria-hidden="true"></i>Mark as done
              </button>
              <button v-if="req.status !== 'cancelled'" type="button" class="wm-btn wm-btn-ghost !min-h-11" :disabled="busy === req.id" @click="messageCustomer(req)">
                <i class="bi bi-chat-dots" aria-hidden="true"></i>Message
              </button>
            </div>
          </li>
        </ul>
        <EmptyState
          v-else
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
          <li v-for="req in requests" :key="req.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-center gap-3 min-w-0">
                <UserAvatar :name="req.providerName" :src="providerPhoto(req.providerId)" :size="44" />
                <div class="min-w-0">
                  <h2 class="text-base font-semibold text-ink leading-snug truncate">{{ req.providerName }}</h2>
                  <p class="wm-meta">{{ categoryLabel(req.service) }}</p>
                </div>
              </div>
              <StatusBadge :status="req.status" class="shrink-0" />
            </div>
            <p class="text-sm text-ink line-clamp-2">{{ req.details }}</p>
            <p v-if="req.status === 'request_declined' && req.providerNote" class="text-sm text-muted">
              <i class="bi bi-reply" aria-hidden="true"></i> {{ req.providerName }}: {{ req.providerNote }}
            </p>
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ req.area }}</span>
              <span class="inline-flex items-center gap-1"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ req.when }}</span>
              <span>Sent {{ sentLabel(req.sentAt) }}</span>
            </p>
            <div class="mt-auto pt-3 border-t border-line">
              <router-link :to="`/provider/${req.providerId}`" class="wm-link inline-flex items-center gap-1 min-h-11">
                View provider <i class="bi bi-chevron-right text-[11px]" aria-hidden="true"></i>
              </router-link>
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
          <li v-for="job in postedJobs" :key="job.id" class="wm-card flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-base font-semibold text-ink leading-snug">{{ job.title }}</h2>
              <StatusBadge :status="job.status" class="shrink-0" />
            </div>
            <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
              <span class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ job.area }}</span>
              <span>Posted {{ job.postedAt }}</span>
            </p>
            <p class="mt-auto pt-3 border-t border-line text-sm text-ink">
              <template v-if="job.workerName">
                <i class="bi bi-person-check text-muted mr-1.5" aria-hidden="true"></i>Worker: <strong class="font-semibold">{{ job.workerName }}</strong>
              </template>
              <template v-else-if="job.applicants">
                <i class="bi bi-people text-muted mr-1.5" aria-hidden="true"></i>{{ job.applicants }} {{ job.applicants === 1 ? 'person has' : 'people have' }} applied
              </template>
              <span v-else class="text-muted">No applications yet</span>
            </p>
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
            <p v-if="app.budget" class="text-sm">
              <span class="font-semibold text-ink">{{ formatPrice(app.budget) }}</span>
              <span class="text-muted"> · {{ app.budgetBasis }}</span>
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import FormField from '@/components/FormField.vue';
import { auth } from '@/stores/auth.js';
import { useRoute, useRouter } from 'vue-router';
import StatusBadge from '@/components/StatusBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { agoLabel, providers } from '@/stores/catalog.js';
import { api } from '@/api/client.js';
import { formatPrice, categoryLabel } from '@/utils/ui.js';

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
const requests = ref([]);
const postedJobs = ref([]);
const applications = ref([]);

// An open request sent to one provider is waiting for their reply;
// an open posted job is waiting for applications
const REQUEST_STATUS = { open: 'pending', in_progress: 'accepted', cancelled: 'request_declined' };
// The provider's side of the same request
const RECEIVED_STATUS = { open: 'new_request', in_progress: 'accepted', cancelled: 'request_declined' };
const waitingCount = computed(() => received.value.filter(r => r.status === 'open').length);
const JOB_STATUS = { open: 'waiting' };

async function load() {
  state.value = 'loading';
  try {
    const [mine, applied, incoming] = await Promise.all([
      api('/me/requests'), api('/me/applications'),
      isProvider.value ? api('/me/received-requests') : Promise.resolve({ data: [] }),
    ]);
    received.value = incoming.data;

    requests.value = mine.data.filter(r => r.kind === 'direct').map(r => ({
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
    }));

    postedJobs.value = mine.data.filter(r => r.kind === 'posted').map(j => ({
      id: j.id,
      title: j.title,
      area: j.area_name,
      postedAt: agoLabel(j.posted_at).toLowerCase(),
      status: JOB_STATUS[j.status] || j.status,
      workerName: null,
      applicants: j.applicants,
    }));

    applications.value = applied.data.map(a => ({
      id: a.id,
      jobTitle: a.job.title,
      area: a.job.area_name,
      appliedAt: agoLabel(a.applied_at).toLowerCase(),
      status: a.status,
      clientName: null,
      budget: a.amount || a.job.budget,
      budgetBasis: a.amount ? 'your price' : a.job.budget_basis,
    }));

    state.value = 'ready';
  } catch {
    state.value = 'error';
  }
}

onMounted(load);

/* ---------------- Answering received requests ---------------- */
const busy = ref(null);
const actionError = ref('');
const declining = ref(null);
const declineReason = ref('');

function replace(updated) {
  received.value = received.value.map(r => (r.id === updated.id ? { ...updated, conversation_id: updated.conversation_id ?? r.conversation_id } : r));
}

async function act(req, action) {
  busy.value = req.id;
  actionError.value = '';
  try {
    replace((await api(`/me/received-requests/${req.id}/${action}`, { method: 'POST', body: {} })).data);
  } catch (e) {
    actionError.value = e.message;
  } finally {
    busy.value = null;
  }
}

function startDecline(req) {
  declining.value = req.id;
  declineReason.value = '';
}

async function decline(req) {
  busy.value = req.id;
  actionError.value = '';
  try {
    replace((await api(`/me/received-requests/${req.id}/decline`, { method: 'POST', body: { reason: declineReason.value.trim() } })).data);
    declining.value = null;
  } catch (e) {
    actionError.value = e.message;
  } finally {
    busy.value = null;
  }
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
