<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Payment Disputes" description="Cases where a customer said a payment the provider reported isn’t right, and the two sides asked WorkMate to step in." demo />

    <p class="wm-notice wm-notice-info" role="note">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <span>WorkMate does not collect or hold these payments. A decision here is a reasoned, attributed record: it can withdraw a provider’s report, but it can never confirm a payment for a customer, and it creates no charge, debt or commission.</span>
    </p>

    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="loadQueue">Try again</button></StateBlock>

    <!-- ================= The queue ================= -->
    <template v-if="!current">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricCard label="Between the sides" :value="formatNumber(counts.open)" hint="Open cases the customer and provider are still discussing. WorkMate has not been asked yet." />
        <MetricCard label="Waiting for review" :value="formatNumber(counts.escalated)" hint="Escalated to WorkMate and not yet taken by an administrator." :primary="true" />
        <MetricCard label="In review" :value="formatNumber(counts.under_review)" hint="An administrator has taken the case." />
        <MetricCard label="Closed" :value="formatNumber(counts.resolved)" hint="Settled by the two sides, withdrawn by the provider, or decided by WorkMate." />
      </div>

      <div class="wm-segmented flex flex-wrap" role="tablist" aria-label="Cases">
        <button v-for="t in QUEUE_TABS" :key="t.id || 'all'" type="button" role="tab" class="wm-segment !min-h-11" :class="{ 'is-active': filters.status === t.id }"
          :aria-selected="filters.status === t.id" @click="setStatus(t.id)">{{ t.label }} <span class="text-[12px] opacity-70">{{ t.count(counts) }}</span></button>
      </div>

      <form class="flex flex-wrap items-end gap-3" @submit.prevent="loadQueue(1)">
        <label class="flex min-w-[10rem] flex-1 flex-col gap-1 text-[13px] font-medium text-ink">Search
          <input v-model="filters.q" type="search" maxlength="80" placeholder="Case number, name or job" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Opened from<input v-model="filters.from" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">to<input v-model="filters.to" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Search</button>
      </form>

      <StateBlock v-if="!loaded && !error" kind="loading" title="Loading cases…" />
      <StateBlock v-else-if="loaded && !items.length" title="No cases here" message="When a customer and a provider can’t settle a payment question and one of them asks for help, it appears here." />
      <ul v-else class="flex flex-col gap-2">
        <li v-for="c in items" :key="c.id">
          <button type="button" class="wm-surface flex w-full flex-col gap-1 rounded-xl p-3.5 text-left hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" @click="open(c.id)">
            <span class="flex flex-wrap items-center justify-between gap-2">
              <span class="text-[14.5px] font-semibold text-ink">Case #{{ c.id }} · {{ c.provider || 'Provider' }} and {{ c.customer || 'customer' }}</span>
              <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="BADGE[c.status]">{{ STATUS_LABEL[c.status] }}</span>
            </span>
            <span class="text-[13px] text-[#475569]">Reported payment {{ formatUGX(c.amount_ugx) }} · opened {{ formatDateTime(c.opened_at) }}<template v-if="c.escalated_at"> · asked WorkMate {{ formatDateTime(c.escalated_at) }}</template></span>
            <span v-if="c.financial_correction_required" class="inline-flex w-fit items-center gap-1 rounded-full bg-[#FEF3C7] px-2 py-0.5 text-[12px] font-medium text-[#92400E]"><i class="bi bi-hourglass-split" aria-hidden="true"></i>{{ CORRECTION_LABEL }}</span>
          </button>
        </li>
      </ul>
      <div v-if="total > perPage" class="flex items-center justify-between text-[13px]">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="page <= 1 || busy" @click="loadQueue(page - 1)">Previous</button>
        <span class="text-[#64748B]">Page {{ page }} of {{ Math.ceil(total / perPage) }}</span>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="page * perPage >= total || busy" @click="loadQueue(page + 1)">Next</button>
      </div>
    </template>

    <!-- ================= One case ================= -->
    <template v-else>
      <div><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="close"><i class="bi bi-arrow-left" aria-hidden="true"></i>All cases</button></div>

      <section class="wm-surface flex flex-col gap-1 rounded-xl p-4" aria-label="Case">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-[16px] font-semibold text-ink">Case #{{ current.id }}</h2>
          <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="BADGE[current.status]">{{ STATUS_LABEL[current.status] }}</span>
        </div>
        <p class="text-[13.5px] text-[#475569]"><strong class="text-ink">{{ current.parties.provider }}</strong> (provider) and <strong class="text-ink">{{ current.parties.customer }}</strong> (customer) · {{ current.job && current.job.service }} · opened {{ formatDateTime(current.opened_at) }}</p>
        <p v-if="current.admin && current.admin.reviewer" class="text-[13px] text-[#475569]">Reviewer: {{ current.admin.reviewer }}</p>
        <dl v-if="current.payment" class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-[13.5px] sm:grid-cols-4">
          <div><dt class="text-[#64748B]">Reported payment</dt><dd class="font-semibold text-ink">{{ formatUGX(current.payment.amount) }}</dd></div>
          <div><dt class="text-[#64748B]">Agreed price</dt><dd class="text-ink">{{ current.job && current.job.final_price ? formatUGX(current.job.final_price) : 'None recorded' }}</dd></div>
          <div><dt class="text-[#64748B]">How paid</dt><dd class="text-ink">{{ current.payment.method.replace('_', ' ') }}<template v-if="current.payment.paid_on"> · {{ current.payment.paid_on }}</template></dd></div>
          <div><dt class="text-[#64748B]">The payment report itself is</dt><dd class="text-ink">{{ reportStateWords(current.payment.status) }}</dd></div>
          <div v-if="current.payment.reference" class="col-span-2"><dt class="text-[#64748B]">Reference given</dt><dd class="text-ink">{{ current.payment.reference }}</dd></div>
          <div v-if="current.payment.note" class="col-span-2"><dt class="text-[#64748B]">Provider’s note</dt><dd class="text-ink">“{{ current.payment.note }}”</dd></div>
          <div v-if="current.payment.response_note" class="col-span-2"><dt class="text-[#64748B]">Customer’s answer</dt><dd class="text-ink">“{{ current.payment.response_note }}”</dd></div>
        </dl>
        <p v-if="current.payments && current.payments.totals" class="mt-1 text-[12.5px] text-[#64748B]">
          On this job: confirmed {{ formatUGX(current.payments.totals.confirmed) }} · waiting {{ formatUGX(current.payments.totals.pending) }} · disputed {{ formatUGX(current.payments.totals.disputed) }}
        </p>
      </section>

      <p v-if="current.escalation" class="wm-notice wm-notice-warning"><i class="bi bi-flag" aria-hidden="true"></i>
        <span>Asked for WorkMate’s help by the {{ current.escalation.by }} on {{ formatDateTime(current.escalation.at) }} ({{ escalationReasonLabel(current.escalation.reason) }}): “{{ current.escalation.note }}”</span></p>
      <p v-if="current.proposal" class="wm-notice wm-notice-info"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i>
        <span>The {{ current.proposal.by }} suggested “{{ agreementLabel(current.proposal.outcome) }}”. It is not agreed until the other side accepts.</span></p>

      <section v-if="current.resolution" class="rounded-xl border border-[#86EFAC] bg-[#F0FDF4] p-4" aria-label="Outcome">
        <p class="text-[14.5px] font-semibold text-ink">{{ resolutionWords(current.resolution, 'admin') }}</p>
        <p v-if="current.resolution.reason" class="text-[13.5px] text-ink">Reason: “{{ current.resolution.reason }}”</p>
        <template v-if="current.resolution.financial_correction_required">
          <p class="mt-1 inline-flex w-fit items-center gap-1.5 rounded-full bg-[#FEF3C7] px-2.5 py-0.5 text-[12.5px] font-medium text-[#92400E]"><i class="bi bi-hourglass-split" aria-hidden="true"></i>{{ CORRECTION_LABEL }}</p>
          <p class="text-[13px] text-[#475569]">{{ correctionWords(current.resolution, current.payment && current.payment.status) }} No commission is created from this decision.</p>
        </template>
      </section>

      <!-- Actions -->
      <section v-if="current.status !== 'resolved'" class="wm-surface flex flex-col gap-3 rounded-xl p-4" aria-label="Actions">
        <p v-if="current.status === 'open' || current.status === 'discussion'" class="text-[13.5px] text-[#475569]">The two sides haven’t asked WorkMate to step in yet, so there is nothing to review.</p>
        <template v-else>
          <div v-if="current.status === 'escalated'">
            <button v-if="canReview" type="button" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy" @click="startReview">Take this case into review</button>
            <p v-else class="text-[13.5px] text-[#475569]">You can read this case. Taking it into review needs the disputes review permission.</p>
          </div>

          <form v-if="canReview" class="flex flex-col gap-2" novalidate @submit.prevent="askInfo">
            <h3 class="text-[14px] font-semibold text-ink">Ask for more information</h3>
            <div class="flex flex-wrap gap-2">
              <select v-model="info.to" class="h-10 rounded-lg border border-[#CBD5E1] px-2" aria-label="Who to ask"><option value="both">Both sides</option><option value="customer">The customer</option><option value="provider">The provider</option></select>
            </div>
            <textarea v-model="info.message" rows="2" maxlength="1000" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5" aria-label="What you need to know" placeholder="e.g. Please send the mobile money receipt."></textarea>
            <p v-if="info.error" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ info.error }}</p>
            <div><button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Send the question</button></div>
          </form>

          <form v-if="current.status === 'under_review' && canResolve" class="flex flex-col gap-3 border-t border-[#E2E8F0] pt-3" novalidate @submit.prevent="askConfirm">
            <h3 class="text-[14px] font-semibold text-ink">Record the decision</h3>
            <fieldset class="flex flex-col gap-2">
              <legend class="sr-only">Decision</legend>
              <label v-for="d in DECISIONS" :key="d.id" class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-[13.5px]" :class="decision.outcome === d.id ? 'border-primary bg-[#EFF6FF]' : 'border-[#E2E8F0]'">
                <input v-model="decision.outcome" type="radio" name="decision" :value="d.id" class="mt-0.5 h-4 w-4 accent-[#1769D2]" />
                <span><span class="block font-medium text-ink">{{ d.label }}</span><span class="block text-[12.5px] text-[#64748B]">{{ d.effect }}</span></span>
              </label>
              <p v-if="errors.outcome" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ errors.outcome }}</p>
            </fieldset>
            <label v-if="needsAmount" class="flex flex-col gap-1 text-[13px] font-medium text-ink">Amount established as received (UGX)
              <input v-model="decision.amount" type="text" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" />
              <span v-if="errors.amount" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ errors.amount }}</span></label>
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Reason <span class="font-normal text-[#64748B]">(both sides will read it, and it is kept in the audit log)</span>
              <textarea v-model="decision.reason" rows="3" maxlength="500" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea>
              <span v-if="errors.reason" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ errors.reason }}</span></label>
            <div><button type="submit" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy">Record decision</button></div>
          </form>
          <p v-else-if="current.status === 'under_review'" class="text-[13px] text-[#64748B]">Deciding a case needs the disputes resolve permission.</p>
        </template>
        <p v-if="actionError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ actionError }}</span></p>
      </section>

      <!-- Messages and evidence -->
      <section class="wm-surface flex flex-col gap-2 rounded-xl p-4" aria-label="Messages and evidence">
        <h3 class="text-[14px] font-semibold text-ink">What the two sides said and sent</h3>
        <p v-if="!current.messages.length" class="text-[13.5px] text-[#64748B]">Nothing yet.</p>
        <ul class="flex flex-col gap-2">
          <li v-for="m in current.messages" :key="m.id" class="rounded-lg px-3 py-2 text-[13.5px]" :class="m.kind === 'info_request' ? 'bg-[#FEF3C7]' : 'bg-[#F8FAFC]'">
            <p class="text-[12px] font-medium text-[#64748B]">{{ m.name }} ({{ m.by }})<template v-if="m.kind === 'info_request'"> asked {{ m.addressed_to }}</template> · {{ formatDateTime(m.at) }}</p>
            <p v-if="m.body" class="whitespace-pre-line break-words text-ink">{{ m.body }}</p>
            <ul v-if="m.files.length" class="mt-1 flex flex-wrap gap-2">
              <li v-for="f in m.files" :key="f.uuid"><a :href="f.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-[#CBD5E1] bg-white px-2 py-1 text-primary">
                <i :class="['bi', isImage(f.mime) ? 'bi-image' : 'bi-file-earmark-pdf']" aria-hidden="true"></i>{{ f.name || 'Photo' }} <span class="text-[#64748B]">{{ fileSize(f.size) }}</span></a></li>
            </ul>
          </li>
        </ul>
        <p class="text-[12px] text-[#64748B]">Opening a file is recorded in the audit log.</p>
      </section>

      <section class="wm-surface rounded-xl p-4" aria-label="Timeline">
        <h3 class="text-[14px] font-semibold text-ink">Timeline</h3>
        <ol class="mt-2 flex flex-col gap-1.5 border-l border-[#E2E8F0] pl-3 text-[13.5px]">
          <li v-for="(e, i) in current.timeline" :key="i"><span class="text-ink">{{ eventWords(e) }}</span> <span class="text-[#64748B]">· {{ formatDateTime(e.at) }}</span>
            <span v-if="e.note && e.event !== 'message'" class="block text-[#64748B]">{{ e.note }}</span></li>
        </ol>
      </section>
    </template>

    <ActionDialog :open="confirming" title="Record this decision?" :message="confirmMessage" confirm-label="Record decision" :busy="busy" :error="dialogError" @close="confirming = false" @confirm="decide" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import ActionDialog from '../components/ActionDialog.vue';
import StateBlock from '../components/StateBlock.vue';
import MetricCard from '../components/MetricCard.vue';
import { api } from '@/api/client.js';
import { can } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatNumber, formatUGX, formatDateTime } from '../utils/format.js';
import {
  CORRECTION_LABEL, correctionWords, reportStateWords, DECISIONS, QUEUE_TABS, agreementLabel, escalationReasonLabel, resolutionWords, eventWords, fileSize, isImage, validateDecision, hasErrors, decisionLabel,
} from '@/utils/disputes.js';

const STATUS_LABEL = { open: 'Between the sides', discussion: 'Between the sides', escalated: 'Waiting for review', under_review: 'In review', resolved: 'Closed' };
const BADGE = {
  open: 'bg-[#F1F5F9] text-[#475569]', discussion: 'bg-[#F1F5F9] text-[#475569]', escalated: 'bg-[#FEF3C7] text-[#92400E]',
  under_review: 'bg-[#DBEAFE] text-[#1E40AF]', resolved: 'bg-[#DCFCE7] text-[#166534]',
};

const canReview = computed(() => can('disputes.review'));
const canResolve = computed(() => can('disputes.resolve'));

const error = ref('');
const busy = ref(false);
const loaded = ref(false);
const counts = ref({ open: 0, escalated: 0, under_review: 0, resolved: 0 });
const items = ref([]);
const page = ref(1);
const total = ref(0);
const perPage = ref(25);
const filters = reactive({ status: '', q: '', from: '', to: '' });

async function loadQueue(p = 1) {
  busy.value = true;
  try {
    const q = new URLSearchParams({ page: String(p) });
    if (filters.status) q.set('status', filters.status);
    if (filters.status === 'open') q.set('scope', 'all');
    for (const k of ['q', 'from', 'to']) if (filters[k]) q.set(k, filters[k]);
    const data = (await api(`/admin/disputes?${q}`)).data;
    counts.value = data.counts;
    items.value = data.items;
    page.value = data.page;
    total.value = data.total;
    perPage.value = data.per_page;
    loaded.value = true;
    error.value = '';
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

const setStatus = (id) => { filters.status = id; loadQueue(1); };

/* ---- One case ---- */
const current = ref(null);
const actionError = ref('');
const info = reactive({ to: 'both', message: '', error: '' });
const decision = reactive({ outcome: '', amount: '', reason: '' });
const errors = reactive({ outcome: '', reason: '', amount: '' });
const confirming = ref(false);
const dialogError = ref('');
const needsAmount = computed(() => decision.outcome === 'partial');
const confirmMessage = computed(() => `${decisionLabel(decision.outcome)}. This closes the case and both the customer and the provider are told and can read your reason. It can’t be undone.`);

async function open(id) {
  busy.value = true;
  actionError.value = '';
  try {
    current.value = (await api(`/admin/disputes/${id}`)).data;
    Object.assign(decision, { outcome: '', amount: '', reason: '' });
    Object.assign(info, { to: 'both', message: '', error: '' });
    Object.assign(errors, { outcome: '', reason: '', amount: '' });
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function close() {
  current.value = null;
  loadQueue(page.value);
}

/** One action at a time; the case comes back as the server now has it */
async function run(path, body, okMessage) {
  if (busy.value) return null;
  busy.value = true;
  actionError.value = '';
  try {
    current.value = (await api(`/admin/disputes/${current.value.id}/${path}`, { method: 'POST', body })).data;
    toast(okMessage, 'success');
    return current.value;
  } catch (e) {
    actionError.value = e.message;
    throw e;
  } finally {
    busy.value = false;
  }
}

const startReview = () => run('review', {}, 'The case is now in review.').catch(() => {});

async function askInfo() {
  info.error = '';
  if (info.message.trim().length < 5) { info.error = 'Say what you need to know (at least 5 characters).'; return; }
  try {
    await run('info-requests', { to: info.to, message: info.message.trim() }, 'Your question was sent.');
    info.message = '';
  } catch { /* shown above */ }
}

function askConfirm() {
  Object.assign(errors, validateDecision(decision.outcome, decision.reason, decision.amount, current.value.payment ? current.value.payment.amount : 0));
  if (hasErrors(errors)) return;
  dialogError.value = '';
  confirming.value = true;
}

async function decide() {
  try {
    const body = { outcome: decision.outcome, reason: decision.reason.trim() };
    if (needsAmount.value) body.amount = Number(decision.amount);
    await run('decision', body, 'Decision recorded.');
    confirming.value = false;
  } catch (e) {
    dialogError.value = e.message;
  }
}

const route = useRoute();
// A notification points straight at a case: /admin/finance/payment-disputes?case=12
onMounted(async () => {
  await loadQueue();
  const wanted = Number(route.query.case);
  if (wanted) open(wanted);
});
</script>
