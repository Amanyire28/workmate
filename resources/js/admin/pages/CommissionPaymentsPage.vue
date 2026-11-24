<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Commission Payments" description="Payments providers make to WorkMate: proofs to check, mobile money payments and their receipts.">
      <template v-if="canExport" #actions>
        <a :href="exportUrl" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" download><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a>
      </template>
    </AdminPageHeader>

    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="reload">Try again</button></StateBlock>

    <!-- Summary -->
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <MetricCard label="Verified collections" :value="formatUGX(summary.verified_ugx)" :context="`${formatNumber(summary.verified_count)} payments`" :primary="true" />
      <MetricCard label="Outstanding" :value="formatUGX(summary.outstanding_ugx)" context="commission" />
      <MetricCard label="Awaiting verification" :value="formatNumber(summary.awaiting_count)" :context="formatUGX(summary.awaiting_ugx)" />
      <MetricCard label="Pending gateway" :value="formatNumber(summary.gateway_pending_count)" :context="formatUGX(summary.gateway_pending_ugx)" />
      <MetricCard label="Failed payments" :value="formatNumber(summary.failed_count)" />
    </div>

    <!-- One payment -->
    <section v-if="open" class="wm-surface flex flex-col gap-3 rounded-xl p-4" aria-label="Payment details">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-9 !rounded-lg !text-[13px]" @click="closeDetail"><i class="bi bi-arrow-left" aria-hidden="true"></i>All payments</button>
          <h2 class="mt-3 text-[16px] font-semibold text-ink">{{ open.payment_ref }}</h2>
          <p class="text-[13px] text-[#475569]">{{ open.provider.name || 'Provider' }} · owes {{ formatUGX(open.provider.outstanding_ugx) }} in total</p>
          <button v-if="open.provider.id" type="button" class="mt-1 text-[13px] text-primary" @click="providerHistory(open.provider.id)">All payments from this provider</button>
        </div>
        <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="TONE_CLASS[statusWords(open.status).tone]">{{ statusWords(open.status).label }}</span>
      </div>

      <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-[13.5px] sm:grid-cols-2">
        <div><dt class="text-[#64748B]">Amount</dt><dd class="font-medium text-ink">{{ formatUGX(open.payment.amount_ugx) }}</dd></div>
        <div><dt class="text-[#64748B]">Method</dt><dd class="text-ink">{{ methodLabel(open.payment.method) }}<template v-if="open.payment.phone"> · {{ open.payment.phone }}</template></dd></div>
        <div v-if="open.payment.reference || open.payment.gateway_reference"><dt class="text-[#64748B]">Transaction reference</dt><dd class="break-all text-ink">{{ open.payment.reference || open.payment.gateway_reference }}</dd></div>
        <div v-if="open.payment.paid_on"><dt class="text-[#64748B]">Date paid</dt><dd class="text-ink">{{ open.payment.paid_on }}</dd></div>
        <div v-if="open.payment.submitted_at"><dt class="text-[#64748B]">Submitted</dt><dd class="text-ink">{{ formatDateTime(open.payment.submitted_at) }}</dd></div>
        <div v-if="open.payment.settlement_id"><dt class="text-[#64748B]">Settlement</dt><dd class="text-ink">#{{ open.payment.settlement_id }} · {{ cycleLabel(open.payment.settlement_status) }} · {{ formatUGX(open.payment.settlement_outstanding_ugx) }} to pay</dd></div>
        <div v-if="open.payment.note" class="sm:col-span-2"><dt class="text-[#64748B]">Provider’s note</dt><dd class="text-ink">“{{ open.payment.note }}”</dd></div>
      </dl>

      <p v-if="open.evidence"><a :href="`/api/v1/admin/settlements/payments/${open.id}/evidence`" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-[13.5px] text-primary"><i class="bi bi-paperclip" aria-hidden="true"></i>Open proof of payment ({{ open.evidence.name }} · {{ fileSize(open.evidence.size) }})</a></p>
      <p v-else-if="open.kind === 'report' && open.status === 'awaiting_verification'" class="text-[13px] text-[#64748B]">No proof attached.</p>

      <!-- Decide -->
      <template v-if="canDecide(openRow, canVerify)">
        <div v-if="!mode" class="flex flex-wrap gap-2">
          <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" @click="begin('verify')">Verify…</button>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="begin('reject')">Reject…</button>
        </div>
        <form v-else class="flex flex-col gap-2 rounded-lg border border-[#E2E8F0] p-3" novalidate @submit.prevent="submit">
          <template v-if="mode === 'verify'">
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Amount actually received (UGX)<input v-model="form.amount" type="text" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
            <label class="flex items-start gap-2 text-[13px] text-ink"><input v-model="form.confirmed" type="checkbox" class="mt-0.5 h-4 w-4 accent-[#1769D2]" />I checked that this money arrived in WorkMate’s own records.</label>
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Note <span class="font-normal text-[#64748B]">(needed if the amount differs)</span><textarea v-model="form.note" rows="2" maxlength="300" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
          </template>
          <label v-else class="flex flex-col gap-1 text-[13px] font-medium text-ink">Why it is not accepted <span class="font-normal text-[#64748B]">(the provider will read it)</span><textarea v-model="form.reason" rows="2" maxlength="300" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
          <p v-if="formError" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ formError }}</p>
          <div class="flex gap-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy" @click="mode = ''">Back</button>
            <button type="submit" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" :disabled="busy">{{ mode === 'verify' ? 'Verify and credit' : 'Reject payment' }}</button>
          </div>
        </form>
      </template>
      <p v-else-if="open.kind === 'report' && open.status === 'awaiting_verification'" class="text-[12.5px] text-[#64748B]">Verifying needs the settlements verify permission.</p>

      <div v-if="open.verification.length" class="rounded-lg bg-[#F8FAFC] p-3 text-[13px]">
        <h3 class="font-semibold text-ink">Verification</h3>
        <p v-for="(v, i) in open.verification" :key="i" class="mt-1 text-[#475569]">
          {{ v.decision === 'verified' ? 'Verified' : 'Rejected' }} by {{ v.by || 'finance' }} · {{ formatDateTime(v.at) }}<template v-if="v.decision === 'verified'">: received {{ formatUGX(v.received_ugx) }}, credited {{ formatUGX(v.credited_ugx) }}<template v-if="v.not_applied_ugx"> ({{ formatUGX(v.not_applied_ugx) }} held for review)</template></template><template v-if="v.note"> · “{{ v.note }}”</template>
        </p>
      </div>
      <div v-if="open.allocations.length" class="rounded-lg bg-[#F8FAFC] p-3 text-[13px]">
        <h3 class="font-semibold text-ink">Applied to settlements</h3>
        <p v-for="a in open.allocations" :key="a.reference" class="mt-1 flex justify-between gap-3 text-[#475569]"><span>Settlement #{{ a.cycle_id }}</span><span class="font-medium text-ink">{{ formatUGX(a.amount_ugx) }}</span></p>
      </div>
      <div v-if="open.transactions.length" class="rounded-lg bg-[#F8FAFC] p-3 text-[13px]">
        <h3 class="font-semibold text-ink">Transaction history</h3>
        <p v-for="(t, i) in open.transactions" :key="i" class="mt-1 flex justify-between gap-3 text-[#475569]"><span>{{ eventWords(t.event) }}</span><span>{{ formatDateTime(t.at) }}</span></p>
      </div>
      <div v-if="open.audit.length" class="rounded-lg bg-[#F8FAFC] p-3 text-[13px]">
        <h3 class="font-semibold text-ink">Audit timeline</h3>
        <p v-for="(a, i) in open.audit" :key="i" class="mt-1 flex justify-between gap-3 text-[#475569]"><span>{{ auditWords(a.action) }}<template v-if="a.reason"> · “{{ a.reason }}”</template></span><span>{{ formatDateTime(a.at) }}</span></p>
      </div>
    </section>

    <template v-else>
      <!-- Filters -->
      <form class="flex flex-wrap items-end gap-3" role="search" @submit.prevent="load(1)">
        <label class="flex min-w-[180px] flex-1 flex-col gap-1 text-[13px] font-medium text-ink">Search<input v-model="filters.q" type="search" placeholder="Reference, provider…" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Status
          <select v-model="filters.status" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="load(1)"><option value="">All</option><option v-for="s in FILTER_STATUSES" :key="s" :value="s">{{ statusWords(s).label }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Method
          <select v-model="filters.method" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="load(1)"><option value="">All</option><option v-for="(l, id) in METHODS" :key="id" :value="id">{{ l }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">From<input v-model="filters.from" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="load(1)" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">To<input v-model="filters.to" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="load(1)" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Sort
          <select v-model="sortKey" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="applySort"><option value="new">Newest first</option><option value="old">Oldest first</option><option value="big">Largest first</option><option value="small">Smallest first</option></select></label>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg">Search</button>
      </form>

      <StateBlock v-if="!loaded && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="!items.length" title="No payments" message="Payments providers make to WorkMate appear here." />
      <template v-else>
        <div class="hidden overflow-x-auto rounded-xl wm-surface md:block">
          <table class="w-full text-left text-[13px]">
            <thead class="border-b border-[#E2E8F0] text-[#64748B]"><tr>
              <th class="px-3 py-2 font-medium">Payment</th><th class="px-3 py-2 font-medium">Provider</th><th class="px-3 py-2 text-right font-medium">Amount</th><th class="px-3 py-2 font-medium">Method</th>
              <th class="px-3 py-2 font-medium">Transaction ref</th><th class="px-3 py-2 font-medium">Submitted</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium">Verification</th>
            </tr></thead>
            <tbody>
              <tr v-for="r in items" :key="r.kind + r.id" class="cursor-pointer border-b border-[#F1F5F9] last:border-0 hover:bg-[#F8FAFC]" tabindex="0" @click="show(r)" @keydown.enter="show(r)">
                <td class="px-3 py-2 font-medium text-ink">{{ r.payment_ref }}</td>
                <td class="px-3 py-2 text-[#475569]">{{ r.provider || '-' }}</td>
                <td class="px-3 py-2 text-right text-ink">{{ formatUGX(r.amount_ugx) }}</td>
                <td class="px-3 py-2 text-[#475569]">{{ methodLabel(r.method) }}</td>
                <td class="max-w-[160px] truncate px-3 py-2 text-[#475569]">{{ r.txn_reference || '-' }}</td>
                <td class="px-3 py-2 text-[#475569]">{{ formatDateTime(r.submitted_at) }}</td>
                <td class="px-3 py-2"><span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="TONE_CLASS[statusWords(r.status).tone]">{{ statusWords(r.status).label }}</span></td>
                <td class="px-3 py-2 text-[#475569]">{{ verificationLabel(r.verification) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="flex flex-col gap-2 md:hidden">
          <li v-for="r in items" :key="r.kind + r.id"><button type="button" class="wm-surface flex w-full flex-col gap-0.5 rounded-xl p-3.5 text-left" @click="show(r)">
            <span class="flex flex-wrap items-center justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink">{{ formatUGX(r.amount_ugx) }} · {{ r.provider || 'Provider' }}</span>
              <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="TONE_CLASS[statusWords(r.status).tone]">{{ statusWords(r.status).label }}</span></span>
            <span class="text-[13px] text-[#475569]">{{ r.payment_ref }} · {{ methodLabel(r.method) }} · {{ formatDateTime(r.submitted_at) }}</span>
          </button></li>
        </ul>
        <div v-if="total > perPage" class="flex items-center justify-between text-[13px]">
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="page <= 1" @click="load(page - 1)">Newer</button><span class="text-[#64748B]">Page {{ page }} of {{ Math.ceil(total / perPage) }}</span>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="page * perPage >= total" @click="load(page + 1)">Older</button>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import StateBlock from '../components/StateBlock.vue';
import MetricCard from '../components/MetricCard.vue';
import { api } from '@/api/client.js';
import { can } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatUGX, formatDateTime, formatNumber } from '../utils/format.js';
import { fileSize } from '@/utils/disputes.js';
import { cycleLabel } from '@/utils/settlement.js';
import { TONE_CLASS, METHODS, FILTER_STATUSES, statusWords, methodLabel, verificationLabel, buildQuery, canDecide, verifyProblem, rejectProblem, eventWords, auditWords } from '../utils/commissionPayments.js';

const route = useRoute();
const canVerify = computed(() => can('settlements.verify'));
const canExport = computed(() => can('finance.reports.view'));

const error = ref('');
const loaded = ref(false);
const summary = reactive({ verified_ugx: 0, verified_count: 0, outstanding_ugx: 0, awaiting_count: 0, awaiting_ugx: 0, gateway_pending_count: 0, gateway_pending_ugx: 0, failed_count: 0 });
const items = ref([]);
const page = ref(1);
const total = ref(0);
const perPage = ref(25);
const filters = reactive({ q: '', status: '', method: '', source: '', provider: '', from: '', to: '', sort: '', dir: '' });
const sortKey = ref('new');
const exportUrl = computed(() => `/api/v1/admin/commission-payments-export?${buildQuery(filters)}`);

const open = ref(null);
const openRow = ref(null);
const mode = ref('');
const busy = ref(false);
const formError = ref('');
const form = reactive({ amount: '', confirmed: false, note: '', reason: '' });

function applySort() {
  const map = { new: ['submitted_at', 'desc'], old: ['submitted_at', 'asc'], big: ['amount_ugx', 'desc'], small: ['amount_ugx', 'asc'] };
  [filters.sort, filters.dir] = map[sortKey.value];
  load(1);
}

async function loadSummary() {
  Object.assign(summary, (await api('/admin/commission-payments/summary')).data);
}

async function load(p = 1) {
  try {
    const data = (await api(`/admin/commission-payments?${buildQuery(filters, p)}`)).data;
    items.value = data.items;
    page.value = data.page;
    total.value = data.total;
    perPage.value = data.per_page;
    loaded.value = true;
  } catch (e) {
    error.value = e.message;
  }
}

async function show(row) {
  try {
    open.value = (await api(`/admin/commission-payments/${row.kind}/${row.id}`)).data;
    openRow.value = { kind: open.value.kind, status: open.value.status };
    mode.value = '';
  } catch (e) {
    error.value = e.message;
  }
}

function providerHistory(id) {
  filters.provider = String(id);
  closeDetail();
  load(1);
}

function closeDetail() {
  open.value = null;
  openRow.value = null;
  mode.value = '';
}

function begin(m) {
  mode.value = m;
  formError.value = '';
  Object.assign(form, { amount: String(open.value.payment.amount_ugx), confirmed: false, note: '', reason: '' });
}

async function submit() {
  if (busy.value) return;
  formError.value = mode.value === 'verify' ? verifyProblem(form) : rejectProblem(form.reason);
  if (formError.value) return;
  busy.value = true;
  try {
    const body = mode.value === 'verify' ? { amount_ugx: Number(form.amount), receipt_confirmed: true, note: form.note.trim() || null } : { reason: form.reason.trim() };
    await api(`/admin/settlements/payments/${open.value.id}/${mode.value}`, { method: 'POST', body });
    toast(mode.value === 'verify' ? 'Payment verified.' : 'Payment rejected.', 'success');
    const id = open.value.id;
    await Promise.all([loadSummary(), load(page.value)]);
    await show({ kind: 'report', id });
  } catch (e) {
    formError.value = e.message;
  } finally {
    busy.value = false;
  }
}

function reload() {
  error.value = '';
  loadSummary().catch((e) => { error.value = e.message; });
  load(1);
}

onMounted(async () => {
  reload();
  // A notification points straight at a payment: /admin/finance/payments?payment=12
  const wanted = Number(route.query.payment);
  if (wanted) await show({ kind: 'report', id: wanted });
});
</script>
