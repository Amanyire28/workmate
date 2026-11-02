<template>
  <div class="wm-container wm-container-sm wm-page">
    <BackLink to="/account">Account</BackLink>
    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
      <h1 class="wm-h1">Reports and complaints</h1>
      <button type="button" class="wm-btn wm-btn-secondary !min-h-11" @click="reportOpen = true">
        <i class="bi bi-flag" aria-hidden="true"></i>Report a problem
      </button>
    </div>
    <p class="wm-lead mt-1.5">Problems you’ve reported to the WorkMate safety team, and notices WorkMate has sent you.</p>

    <div v-if="loading" class="mt-5 flex items-center gap-2 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Loading…
    </div>
    <p v-else-if="loadError" class="wm-notice wm-notice-error mt-4" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ loadError }} <button type="button" class="wm-link" @click="load">Try again</button></span>
    </p>

    <template v-else>
      <!-- Warnings sent to this account -->
      <section v-if="warnings.length" class="mt-5" aria-labelledby="notices-heading">
        <h2 id="notices-heading" class="wm-h3 mb-2">Notices from WorkMate</h2>
        <ul class="flex flex-col gap-3">
          <li v-for="w in warnings" :key="w.id" class="wm-card !border-[#FCD34D]">
            <p class="flex items-center gap-2 text-[15px] font-semibold text-ink">
              <i class="bi bi-exclamation-triangle text-[#B45309]" aria-hidden="true"></i>Notice
              <span v-if="!w.read" class="wm-pill wm-pill-warning !py-0 !text-[11px]">New</span>
            </p>
            <p class="mt-1.5 text-[15px] text-ink whitespace-pre-line">{{ w.message }}</p>
            <p class="mt-1.5 wm-meta">Sent {{ dateLabel(w.sent_at) }}. If you think this is a mistake, report a problem and tell us why.</p>
          </li>
        </ul>
      </section>

      <!-- The account's own reports -->
      <section class="mt-6" aria-labelledby="mine-heading">
        <h2 id="mine-heading" class="wm-h3 mb-2">Your reports</h2>
        <ul v-if="reports.length" class="flex flex-col gap-3">
          <li v-for="r in reports" :key="r.id" class="wm-card flex flex-col gap-2.5">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-[15px] font-semibold text-ink">{{ REASON_LABELS[r.reason] || r.reason }}</p>
                <p class="wm-meta">
                  {{ r.about_name ? `About ${r.about_name}` : 'Something else' }}<template v-if="r.request"> · {{ r.request.title }}</template> · sent {{ dateLabel(r.sent_at) }}
                </p>
              </div>
              <span :class="['wm-pill shrink-0', `wm-pill-${STATUS[r.status]?.tone || 'neutral'}`]">{{ STATUS[r.status]?.label || r.status }}</span>
            </div>
            <p class="text-sm text-ink whitespace-pre-line line-clamp-3">{{ r.details }}</p>
            <p v-if="r.photos.length" class="wm-meta flex flex-wrap gap-x-3">
              <a v-for="p in r.photos" :key="p.index" :href="`/api/v1/me/reports/${r.id}/files/${p.index}`" target="_blank" rel="noopener" class="wm-link">
                <i class="bi bi-image" aria-hidden="true"></i> {{ p.name }}
              </a>
            </p>

            <!-- The safety team asked something -->
            <div v-if="r.status === 'info_requested'" class="rounded-xl bg-[#FFFBEB] p-3 flex flex-col gap-2.5">
              <p class="text-sm text-ink"><strong class="font-semibold">WorkMate asks:</strong> {{ r.question }}</p>
              <form class="flex flex-col gap-2" @submit.prevent="reply(r)">
                <FormField v-model="replies[r.id]" type="textarea" label="Your answer" :id="`reply-${r.id}`" :min-height="80" maxlength="2000" />
                <div class="flex flex-col gap-1.5">
                  <label :for="`reply-photos-${r.id}`" class="wm-label">Add photos <span class="font-normal text-muted">(optional)</span></label>
                  <input :id="`reply-photos-${r.id}`" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple class="wm-input !py-2" @change="e => (replyPhotos[r.id] = [...e.target.files])" />
                </div>
                <p v-if="replyErrors[r.id]" class="wm-field-error" role="alert">{{ replyErrors[r.id] }}</p>
                <button type="submit" class="wm-btn wm-btn-primary !min-h-11 self-start" :disabled="busy === r.id || (replies[r.id] || '').trim().length < 3">
                  {{ busy === r.id ? 'Sending…' : 'Send answer' }}
                </button>
              </form>
            </div>
            <p v-else-if="r.your_reply && !r.outcome_message" class="text-sm text-muted"><i class="bi bi-reply" aria-hidden="true"></i> You answered: {{ r.your_reply }}</p>

            <p v-if="r.outcome_message" class="wm-notice wm-notice-info !text-sm">
              <i class="bi bi-chat-left-text" aria-hidden="true"></i><span><strong class="font-semibold">WorkMate:</strong> {{ r.outcome_message }}</span>
            </p>
          </li>
        </ul>
        <EmptyState v-else icon="bi-flag" title="No reports" message="If something goes wrong with a provider, a customer or WorkMate itself, report it and follow it here." />
      </section>
    </template>

    <ReportSheet :open="reportOpen" about="general" @close="reportOpen = false" @sent="load" />
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import BackLink from '@/components/BackLink.vue';
import FormField from '@/components/FormField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import { api, fieldErrors } from '@/api/client.js';

const STATUS = {
  open: { label: 'Received', tone: 'info' },
  under_review: { label: 'Being looked at', tone: 'info' },
  info_requested: { label: 'Your answer needed', tone: 'warning' },
  resolved: { label: 'Resolved', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
};
const REASON_LABELS = {
  scam: 'Scam or fraud', no_show: 'Didn’t show up', offline: 'Asked to pay outside WorkMate', abusive: 'Rude or abusive',
  unsafe: 'Unsafe behaviour', fake: 'Fake profile or request', quality: 'Poor work', not_paid: 'Didn’t pay',
  payment: 'Payment problem', safety: 'Safety concern', app_problem: 'Problem with WorkMate', other: 'Something else',
};

const loading = ref(true);
const loadError = ref('');
const reports = ref([]);
const warnings = ref([]);
const reportOpen = ref(false);
const replies = reactive({});
const replyPhotos = reactive({});
const replyErrors = reactive({});
const busy = ref(null);

const dateLabel = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

async function load() {
  loading.value = !reports.value.length;
  loadError.value = '';
  try {
    const [mine, notices] = await Promise.all([api('/me/reports'), api('/me/warnings')]);
    reports.value = mine.data;
    warnings.value = notices.data;
    // Seeing a notice here counts as reading it
    for (const w of notices.data.filter(n => !n.read)) api(`/me/warnings/${w.id}/read`, { method: 'POST', body: {} }).catch(() => {});
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function reply(r) {
  const body = new FormData();
  body.append('reply', replies[r.id].trim());
  for (const f of replyPhotos[r.id] || []) body.append('photos[]', f);
  busy.value = r.id;
  replyErrors[r.id] = '';
  try {
    const updated = (await api(`/me/reports/${r.id}/reply`, { method: 'POST', body })).data;
    reports.value = reports.value.map(x => (x.id === r.id ? updated : x));
  } catch (e) {
    replyErrors[r.id] = Object.values(fieldErrors(e))[0] || e.message;
  } finally {
    busy.value = null;
  }
}

onMounted(load);
</script>
