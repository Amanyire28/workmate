<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Commission Settlements" description="What each provider owes, in settlement cycles, and the reconciliation exceptions that need a decision." />

    <p v-if="loaded && !enabled" class="wm-notice wm-notice-info"><i class="bi bi-toggle-off" aria-hidden="true"></i><span>Settlements are off. Existing records stay readable.</span></p>

    <div class="wm-segmented grid grid-cols-2 sm:inline-grid" role="tablist" aria-label="Settlements">
      <button v-for="t in ADMIN_TABS" :key="t.id" type="button" role="tab" class="wm-segment !min-h-11" :class="{ 'is-active': tab === t.id }" :aria-selected="tab === t.id" @click="setTab(t.id)">{{ t.label }}</button>
    </div>
    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="reload">Try again</button></StateBlock>

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
      <StateBlock v-else-if="!exceptions.length" title="No exceptions" message="Money that could not be applied to a settlement appears here until it is reviewed." />
      <ul v-else class="flex flex-col gap-2">
        <li v-for="e in exceptions" :key="e.id" class="wm-surface flex flex-col gap-0.5 rounded-xl p-3.5">
          <span class="flex flex-wrap items-center justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink">{{ e.provider || 'Provider' }} · {{ formatUGX(e.amount_ugx) }}</span>
            <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="e.status === 'open' ? 'bg-[#FEF3C7] text-[#92400E]' : 'bg-[#F1F5F9] text-[#475569]'">{{ e.status === 'open' ? 'Needs a decision' : 'Resolved' }}</span></span>
          <span class="text-[13px] text-[#475569]">{{ EXCEPTION_WORDS[e.kind] }} {{ formatDateTime(e.at) }}</span>
        </li>
      </ul>
      <p class="text-[12.5px] text-[#64748B]">Decisions on these are made in Financial Administration.</p>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import StateBlock from '../components/StateBlock.vue';
import { api } from '@/api/client.js';
import { formatUGX, formatDateTime } from '../utils/format.js';
import { ADMIN_TABS, CYCLE_STATUS, cycleLabel, paymentWords, channelLabel, dueWords, EXCEPTION_WORDS } from '@/utils/settlement.js';

const CYCLE_BADGE = {
  open: 'bg-[#F1F5F9] text-[#475569]', due: 'bg-[#FEF3C7] text-[#92400E]', partially_paid: 'bg-[#DBEAFE] text-[#1E40AF]',
  overdue: 'bg-[#FEE2E2] text-[#991B1B]', settled: 'bg-[#DCFCE7] text-[#166534]', void: 'bg-[#F1F5F9] text-[#475569]',
};

const tab = ref('cycles');
const error = ref('');
const busy = ref(false);
const loaded = ref(false);
const enabled = ref(false);

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
  loadExceptions();
}

onMounted(reload);
</script>
