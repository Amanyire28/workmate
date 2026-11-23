<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Commission Settlements" description="Cycles of recorded commission, the payments providers report paying WorkMate, and the checks that turn a report into a credit." demo />

    <p class="wm-notice wm-notice-info" role="note">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <span>A provider’s report is only a statement. Nothing is credited until you confirm the money arrived in WorkMate’s own records. Nothing is collected automatically, and no penalty, suspension or deduction exists.</span>
    </p>
    <p v-if="loaded && !enabled" class="wm-notice wm-notice-warning"><i class="bi bi-toggle-off" aria-hidden="true"></i><span>Commission settlement is switched off, so providers can’t report payments and nothing new is grouped into cycles. Existing records stay readable.</span></p>

    <div class="wm-segmented grid grid-cols-3 sm:inline-grid" role="tablist" aria-label="Settlements">
      <button v-for="t in ADMIN_TABS" :key="t.id" type="button" role="tab" class="wm-segment !min-h-11" :class="{ 'is-active': tab === t.id }" :aria-selected="tab === t.id" @click="setTab(t.id)">{{ t.label }}</button>
    </div>
    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="reload">Try again</button></StateBlock>

    <!-- ================= Payments to verify ================= -->
    <section v-show="tab === 'payments'" class="flex flex-col gap-3" aria-label="Reported payments">
      <div class="wm-segmented flex flex-wrap" role="tablist" aria-label="Payment status">
        <button v-for="s in PAYMENT_FILTERS" :key="s.id" type="button" role="tab" class="wm-segment !min-h-10" :class="{ 'is-active': payStatus === s.id }" @click="setPayStatus(s.id)">{{ s.label }} <span class="text-[12px] opacity-70">{{ payCounts[s.id] || 0 }}</span></button>
      </div>
      <StateBlock v-if="!paymentsLoaded && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="!payments.length" title="Nothing here" message="Payments providers report paying WorkMate appear here to be checked." />
      <ul v-else class="flex flex-col gap-3">
        <li v-for="p in payments" :key="p.id" class="wm-surface flex flex-col gap-2 rounded-xl p-4" :class="{ 'ring-2 ring-primary': focus === p.id }">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div><p class="text-[14.5px] font-semibold text-ink">{{ p.provider || 'Provider' }} · {{ formatUGX(p.amount_ugx) }} reported</p>
              <p class="text-[13px] text-[#475569]">{{ channelLabel(p.channel) }}<template v-if="p.reference"> · ref {{ p.reference }}</template><template v-if="p.paid_on"> · paid {{ p.paid_on }}</template> · reported {{ formatDateTime(p.reported_at) }}</p></div>
            <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="PAY_BADGE[p.status]">{{ paymentWords(p.status).label }}</span>
          </div>
          <p class="text-[13px] text-[#475569]">Settlement #{{ p.cycle_id }} is <strong class="text-ink">{{ cycleLabel(p.cycle_status) }}</strong> with {{ formatUGX(Math.max(p.cycle_outstanding_ugx, 0)) }} still to pay.</p>
          <p v-if="p.note" class="text-[13px] text-ink">Provider’s note: “{{ p.note }}”</p>
          <p v-if="p.evidence"><a :href="`/api/v1/admin/settlements/payments/${p.id}/evidence`" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-[13px] text-primary"><i class="bi bi-paperclip" aria-hidden="true"></i>{{ p.evidence.name || 'Proof' }} ({{ fileSize(p.evidence.size) }}). Opening it is recorded.</a></p>
          <p v-else class="text-[12.5px] text-[#64748B]">No proof attached. A reference or screenshot is not verification: check WorkMate’s own records.</p>
          <template v-if="p.status !== 'pending'">
            <p class="text-[13px] text-ink">{{ p.status === 'verified' ? `Verified ${formatUGX(p.verified_amount_ugx)}: credited ${formatUGX(p.credited_ugx)}${p.excess_ugx ? `, ${formatUGX(p.excess_ugx)} not applied (exception)` : ''}.` : 'Rejected.' }} {{ formatDateTime(p.decided_at) }}<template v-if="p.decision_note"> · “{{ p.decision_note }}”</template></p>
          </template>
          <template v-else-if="canVerify">
            <div v-if="active !== p.id" class="flex flex-wrap gap-2">
              <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" @click="begin(p, 'verify')">Verify…</button>
              <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="begin(p, 'reject')">Reject…</button>
            </div>
            <form v-else class="flex flex-col gap-2 rounded-lg border border-[#E2E8F0] p-3" novalidate @submit.prevent="submit(p)">
              <template v-if="mode === 'verify'">
                <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Amount actually received (UGX)<input v-model="form.amount" type="text" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
                <label class="flex items-start gap-2 text-[13px] text-ink"><input v-model="form.confirmed" type="checkbox" class="mt-0.5 h-4 w-4 accent-[#1769D2]" />I checked that this money arrived in WorkMate’s own records (not just the provider’s message or screenshot).</label>
                <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Note <span class="font-normal text-[#64748B]">(needed if the amount differs from what was reported)</span><textarea v-model="form.note" rows="2" maxlength="300" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
                <p class="text-[12.5px] text-[#64748B]">It is credited up to what the settlement still owes. Any extra is recorded as an exception and is not applied anywhere.</p>
              </template>
              <label v-else class="flex flex-col gap-1 text-[13px] font-medium text-ink">Why it is not accepted <span class="font-normal text-[#64748B]">(the provider will read it)</span><textarea v-model="form.reason" rows="2" maxlength="300" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
              <p v-if="formError" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ formError }}</p>
              <div class="flex gap-2"><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy" @click="active = null">Back</button>
                <button type="submit" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" :disabled="busy">{{ mode === 'verify' ? 'Verify and credit' : 'Reject payment' }}</button></div>
            </form>
          </template>
          <p v-else class="text-[12.5px] text-[#64748B]">You can read this. Verifying needs the settlements verify permission.</p>
        </li>
      </ul>
    </section>

    <!-- ================= Cycles ================= -->
    <section v-show="tab === 'cycles'" class="flex flex-col gap-3" aria-label="Cycles">
      <div v-if="!openCycle" class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">State
          <select v-model="cycleStatus" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="loadCycles(1)"><option value="">All</option><option v-for="(v, k) in CYCLE_STATUS" :key="k" :value="k">{{ v.label }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Provider ID<input v-model="cycleProvider" type="number" min="1" class="h-10 w-28 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="loadCycles(1)" /></label>
        <p class="pb-2 text-[12.5px] text-[#64748B]">{{ Object.entries(cycleCounts).map(([k, n]) => `${cycleLabel(k)} ${n}`).join(' · ') }}</p>
      </div>
      <StateBlock v-if="!cyclesLoaded && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="!openCycle && !cycles.length" title="No cycles" message="Cycles appear once commission obligations are recorded with settlement switched on." />
      <ul v-else-if="!openCycle" class="flex flex-col gap-2">
        <li v-for="c in cycles" :key="c.id"><button type="button" class="wm-surface flex w-full flex-col gap-0.5 rounded-xl p-3.5 text-left hover:shadow-md" @click="showCycle(c.id)">
          <span class="flex flex-wrap items-center justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink">#{{ c.id }} · {{ c.provider || 'Provider' }}</span><span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="CYCLE_BADGE[c.status]">{{ cycleLabel(c.status) }}</span></span>
          <span class="text-[13px] text-[#475569]">{{ c.jobs }} {{ c.jobs === 1 ? 'job' : 'jobs' }} · payable {{ formatUGX(c.payable_ugx) }} · verified {{ formatUGX(c.credits_ugx) }} · to pay {{ formatUGX(Math.max(c.outstanding_ugx, 0)) }}<template v-if="c.due_at"> · due {{ formatDateTime(c.due_at) }}</template></span></button></li>
      </ul>
      <div v-if="cycleTotal > 25 && !openCycle" class="flex items-center justify-between text-[13px]">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="cyclePage <= 1" @click="loadCycles(cyclePage - 1)">Newer</button><span class="text-[#64748B]">Page {{ cyclePage }}</span>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="cyclePage * 25 >= cycleTotal" @click="loadCycles(cyclePage + 1)">Older</button></div>
      <template v-if="openCycle">
        <div><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="openCycle = null"><i class="bi bi-arrow-left" aria-hidden="true"></i>All cycles</button></div>
        <section class="wm-surface flex flex-col gap-2 rounded-xl p-4">
          <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-[16px] font-semibold text-ink">Settlement #{{ openCycle.id }} · {{ openCycle.provider }}</h2><span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="CYCLE_BADGE[openCycle.status]">{{ cycleLabel(openCycle.status) }}</span></div>
          <p class="text-[13.5px] text-[#475569]">{{ openCycle.jobs }} jobs · payable {{ formatUGX(openCycle.payable_ugx) }} · verified {{ formatUGX(openCycle.credits_ugx) }} · <strong class="text-ink">to pay {{ formatUGX(openCycle.outstanding_ugx) }}</strong></p>
          <p v-for="h in openCycle.history" :key="h.event" class="text-[13px] text-[#475569]">Record: three jobs reached on {{ formatDateTime(h.at) }}<template v-if="!h.in_effect"> (since taken back below three by a reversal; kept for the record)</template>.</p>
          <p class="text-[13px] text-[#475569]">{{ dueWords(openCycle, formatDateTime) || 'Settled.' }}<template v-if="openCycle.first_obligation_at"> First obligation {{ formatDateTime(openCycle.first_obligation_at) }}.</template></p>
        </section>
        <section class="wm-surface rounded-xl p-4"><h3 class="text-[14px] font-semibold text-ink">Ledger</h3>
          <ul class="mt-2 divide-y divide-[#F1F5F9] text-[13px]"><li v-for="(e, i) in openCycle.ledger" :key="i" class="flex justify-between gap-3 py-1.5"><span class="text-[#475569]">{{ e.type }}<template v-if="e.reason"> · {{ e.reason.replace(/_/g, ' ') }}</template> · {{ formatDateTime(e.at) }}</span><span class="font-medium" :class="e.amount_ugx < 0 ? 'text-[#166534]' : 'text-ink'">{{ e.amount_ugx < 0 ? '−' : '+' }}{{ formatUGX(Math.abs(e.amount_ugx)) }}</span></li></ul></section>
        <section class="wm-surface rounded-xl p-4"><h3 class="text-[14px] font-semibold text-ink">Obligations ({{ openCycle.obligations.length }})</h3>
          <ul class="mt-2 divide-y divide-[#F1F5F9] text-[13px]"><li v-for="o in openCycle.obligations" :key="o.id" class="flex justify-between gap-3 py-1.5"><span class="text-[#475569]">job #{{ o.request_id }} · {{ formatDateTime(o.assessed_at) }}</span><span class="text-ink">{{ formatUGX(o.commission_ugx) }}</span></li></ul></section>
        <section class="wm-surface rounded-xl p-4"><h3 class="text-[14px] font-semibold text-ink">Reported payments ({{ openCycle.payments.length }})</h3>
          <p v-if="!openCycle.payments.length" class="mt-1 text-[13px] text-[#64748B]">None.</p>
          <ul class="mt-2 divide-y divide-[#F1F5F9] text-[13px]"><li v-for="p in openCycle.payments" :key="p.id" class="flex justify-between gap-3 py-1.5"><span class="text-[#475569]">{{ channelLabel(p.channel) }}<template v-if="p.reference"> · {{ p.reference }}</template></span><span class="text-ink">{{ formatUGX(p.amount_ugx) }} · {{ paymentWords(p.status).label }}</span></li></ul></section>
      </template>
    </section>

    <!-- ================= Exceptions ================= -->
    <section v-show="tab === 'exceptions'" class="flex flex-col gap-3" aria-label="Exceptions">
      <StateBlock v-if="!exceptionsLoaded && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="!exceptions.length" title="No exceptions" message="Overpayments and excess credits waiting for an approved reconciliation decision appear here." />
      <ul v-else class="flex flex-col gap-2">
        <li v-for="e in exceptions" :key="e.id" class="wm-surface flex flex-col gap-0.5 rounded-xl p-3.5">
          <span class="flex flex-wrap items-center justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink">{{ e.provider || 'Provider' }} · {{ formatUGX(e.amount_ugx) }}</span>
            <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="e.status === 'open' ? 'bg-[#FEF3C7] text-[#92400E]' : 'bg-[#F1F5F9] text-[#475569]'">{{ e.status === 'open' ? 'Needs a decision' : 'Resolved' }}</span></span>
          <span class="text-[13px] text-[#475569]">{{ EXCEPTION_WORDS[e.kind] }} {{ formatDateTime(e.at) }}</span>
        </li>
      </ul>
      <p class="text-[12.5px] text-[#64748B]">No reconciliation decision is built yet: these are recorded and shown, and nothing is refunded, moved or allocated.</p>
    </section>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import StateBlock from '../components/StateBlock.vue';
import { api } from '@/api/client.js';
import { can } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatUGX, formatDateTime } from '../utils/format.js';
import { fileSize } from '@/utils/disputes.js';
import { ADMIN_TABS, CYCLE_STATUS, cycleLabel, paymentWords, channelLabel, dueWords, EXCEPTION_WORDS } from '@/utils/settlement.js';

const PAYMENT_FILTERS = [{ id: 'pending', label: 'Awaiting' }, { id: 'verified', label: 'Verified' }, { id: 'rejected', label: 'Rejected' }, { id: 'all', label: 'All' }];
const PAY_BADGE = { pending: 'bg-[#FEF3C7] text-[#92400E]', verified: 'bg-[#DCFCE7] text-[#166534]', rejected: 'bg-[#FEE2E2] text-[#991B1B]' };
const CYCLE_BADGE = {
  open: 'bg-[#F1F5F9] text-[#475569]', due: 'bg-[#FEF3C7] text-[#92400E]', partially_paid: 'bg-[#DBEAFE] text-[#1E40AF]',
  overdue: 'bg-[#FEE2E2] text-[#991B1B]', settled: 'bg-[#DCFCE7] text-[#166534]', void: 'bg-[#F1F5F9] text-[#475569]',
};

const canVerify = computed(() => can('settlements.verify'));
const route = useRoute();
const tab = ref('payments');
const error = ref('');
const busy = ref(false);
const loaded = ref(false);
const enabled = ref(false);

/* payments */
const payStatus = ref('pending');
const payments = ref([]);
const payCounts = ref({});
const paymentsLoaded = ref(false);
const focus = ref(null);
const active = ref(null);
const mode = ref('verify');
const form = reactive({ amount: '', confirmed: false, note: '', reason: '' });
const formError = ref('');

async function loadPayments() {
  try {
    const data = (await api(`/admin/settlements/payments?status=${payStatus.value}`)).data;
    payments.value = data.items;
    payCounts.value = { ...data.counts, all: Object.values(data.counts).reduce((a, b) => a + Number(b), 0) };
    paymentsLoaded.value = true;
  } catch (e) {
    error.value = e.message;
  }
}

function setPayStatus(id) {
  payStatus.value = id;
  loadPayments();
}

function begin(p, m) {
  active.value = p.id;
  mode.value = m;
  formError.value = '';
  Object.assign(form, { amount: String(p.amount_ugx), confirmed: false, note: '', reason: '' });
}

async function submit(p) {
  if (busy.value) return;
  formError.value = '';
  let path;
  let body;
  if (mode.value === 'verify') {
    if (!/^\d+$/.test(form.amount.trim()) || Number(form.amount) < 100) { formError.value = 'Enter the amount actually received, in whole UGX.'; return; }
    if (!form.confirmed) { formError.value = 'Confirm that you checked the money arrived in WorkMate’s own records.'; return; }
    path = 'verify';
    body = { amount_ugx: Number(form.amount), receipt_confirmed: true, note: form.note.trim() || null };
  } else {
    if (form.reason.trim().length < 5) { formError.value = 'Say why (at least 5 characters). The provider will read it.'; return; }
    path = 'reject';
    body = { reason: form.reason.trim() };
  }
  busy.value = true;
  try {
    await api(`/admin/settlements/payments/${p.id}/${path}`, { method: 'POST', body });
    toast(mode.value === 'verify' ? 'Payment verified and credited.' : 'Payment rejected.', 'success');
    active.value = null;
    await Promise.all([loadPayments(), loadCycles(cyclePage.value)]);
  } catch (e) {
    formError.value = e.message;
  } finally {
    busy.value = false;
  }
}

/* cycles */
const cycles = ref([]);
const cycleCounts = ref({});
const cyclesLoaded = ref(false);
const cyclePage = ref(1);
const cycleTotal = ref(0);
const cycleStatus = ref('');
const cycleProvider = ref('');
const openCycle = ref(null);

async function loadCycles(page = 1) {
  try {
    const q = new URLSearchParams({ page: String(page) });
    if (cycleStatus.value) q.set('status', cycleStatus.value);
    if (cycleProvider.value) q.set('provider', String(cycleProvider.value));
    const data = (await api(`/admin/settlements/cycles?${q}`)).data;
    cycles.value = data.items;
    cycleCounts.value = data.counts;
    cyclePage.value = data.page;
    cycleTotal.value = data.total;
    enabled.value = data.enabled;
    cyclesLoaded.value = true;
    loaded.value = true;
  } catch (e) {
    error.value = e.message;
  }
}

async function showCycle(id) {
  try {
    openCycle.value = (await api(`/admin/settlements/cycles/${id}`)).data;
  } catch (e) {
    error.value = e.message;
  }
}

/* exceptions */
const exceptions = ref([]);
const exceptionsLoaded = ref(false);

async function loadExceptions() {
  try {
    exceptions.value = (await api('/admin/settlements/exceptions')).data;
    exceptionsLoaded.value = true;
  } catch (e) {
    error.value = e.message;
  }
}

function setTab(id) {
  tab.value = id;
  openCycle.value = null;
}

function reload() {
  error.value = '';
  loadCycles(1);
  loadPayments();
  loadExceptions();
}

onMounted(() => {
  // A notification points straight at a payment: /admin/finance/settlements?payment=12
  const wanted = Number(route.query.payment);
  if (wanted) {
    focus.value = wanted;
    payStatus.value = 'all';
  }
  reload();
});
</script>
