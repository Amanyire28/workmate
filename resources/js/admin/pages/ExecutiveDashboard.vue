<template>
  <div class="flex flex-col gap-5">
    <AdminPageHeader
      title="Executive Dashboard"
      description="Marketplace health at a glance: growth, bookings, money, matching and the issues that need an administrator."
      demo
    >
      <template #actions>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <span class="inline-flex items-center gap-1.5 text-[12px] text-[#64748B]" aria-live="polite">
            <span v-if="loading" class="wm-spinner !h-3 !w-3 text-primary" aria-hidden="true"></span>
            {{ loading ? 'Updating…' : updatedAt ? `Updated ${formatRelative(updatedAt, now).toLowerCase()}` : '' }}
          </span>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-9 !rounded-lg !px-3 !text-[13px]" :disabled="loading" @click="load">
            <i class="bi bi-arrow-clockwise" :class="{ 'animate-spin': loading }" aria-hidden="true"></i>Refresh
          </button>
          <DateRangeControl v-model="days" v-model:compare="compare" label="Dashboard date range" />
        </div>
      </template>
    </AdminPageHeader>

    <p class="flex items-start gap-2 rounded-lg border border-[#DCE7F5] bg-[#F5F9FF] px-3.5 py-2.5 text-[13px] text-[#1E4E8C]">
      <i class="bi bi-database mt-px" aria-hidden="true"></i>
      <span>
        Figures come from the WorkMate database and leave out sample records.
        Payments, bookings, searches, disputes and app usage aren’t recorded yet, so they show as <strong class="font-semibold">Not tracked yet</strong> rather than zero.
      </span>
    </p>

    <!-- First load failed: nothing to show -->
    <div v-if="error && !data" class="rounded-xl wm-surface">
      <StateBlock kind="error" title="The dashboard couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>

    <template v-else>
      <!-- Refetch failed: keep the last figures, say they're stale -->
      <p v-if="error" class="wm-notice wm-notice-error" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span>Couldn’t refresh: {{ error }} Showing the previous figures. <button type="button" class="font-semibold underline" @click="load">Try again</button></span>
      </p>

      <!-- ============ Primary KPIs ============ -->
      <section aria-label="Key figures">
        <div v-if="!data" class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3" aria-busy="true">
          <div v-for="n in 5" :key="n" class="wm-skeleton h-[118px] !rounded-xl"></div>
        </div>
        <div v-else class="grid grid-cols-1 min-[420px]:grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 transition-opacity" :class="{ 'opacity-60': loading }">
          <MetricCard v-for="k in kpis" :key="k.label" primary v-bind="k" />
        </div>
      </section>

      <!-- ============ Grouped secondary metrics ============ -->
      <div v-if="data" class="grid grid-cols-1 md:grid-cols-3 gap-3 transition-opacity" :class="{ 'opacity-60': loading }">
        <MetricGroup v-for="g in metricGroups" :key="g.title" :title="g.title" :items="g.items" />
      </div>

      <!-- ============ Priorities + recent activity ============ -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-3">
        <section class="xl:col-span-3 rounded-xl wm-glass-card" aria-labelledby="priorities-heading">
          <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
            <h2 id="priorities-heading" class="text-[15px] font-semibold text-ink">Needs attention</h2>
            <span class="text-[12px] text-[#64748B]">Most urgent first</span>
          </div>
          <div v-if="!data" class="px-4 pb-4 flex flex-col gap-2" aria-busy="true">
            <div v-for="n in 4" :key="n" class="wm-skeleton h-14"></div>
          </div>
          <StateBlock v-else-if="!priorities.length" kind="empty" compact icon="bi-check2-circle" title="Nothing needs attention" message="No open items for the areas your role covers." />
          <ul v-else class="px-2 pb-2">
            <li v-for="p in priorities" :key="p.id">
              <router-link :to="p.to" class="wm-hover-row group flex items-center gap-3 rounded-lg px-2 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                <span
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[16px]"
                  :class="{ 'bg-[#FDECEC] text-[#B91C1C]': p.severity === 'critical', 'bg-[#FEF3E2] text-[#B45309]': p.severity === 'warning', 'bg-[#F1F5F9] text-[#475569]': p.severity === 'info' }"
                  aria-hidden="true"
                ><i :class="['bi', severityIcon[p.severity]]"></i></span>
                <span class="min-w-0 flex-1">
                  <span class="flex flex-wrap items-center gap-x-2 text-[13.5px] font-medium text-ink">
                    {{ p.title }}
                    <span class="text-[11.5px] font-semibold" :class="{ 'text-[#B91C1C]': p.severity === 'critical', 'text-[#B45309]': p.severity === 'warning', 'text-[#64748B]': p.severity === 'info' }">{{ severityLabel[p.severity] }}</span>
                  </span>
                  <span class="block text-[12.5px] text-[#64748B]">
                    {{ p.detail }}<template v-if="p.since"> · oldest waiting {{ formatAge(p.since) }}</template>
                  </span>
                </span>
                <span class="text-lg font-semibold text-ink tabular-nums">{{ formatNumber(p.count) }}</span>
                <i class="bi bi-chevron-right text-[#CBD5E1] group-hover:text-[#64748B]" aria-hidden="true"></i>
              </router-link>
            </li>
          </ul>
        </section>

        <section class="xl:col-span-2 flex flex-col rounded-xl wm-glass-card" aria-labelledby="activity-heading">
          <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-3">
            <h2 id="activity-heading" class="text-[15px] font-semibold text-ink">Recent activity</h2>
            <router-link v-if="can('activity.view')" to="/admin/activity" class="text-[12.5px] font-medium text-primary hover:underline">View all</router-link>
          </div>
          <div class="px-4 pb-4">
            <div v-if="!data" class="flex flex-col gap-3" aria-busy="true">
              <div v-for="n in 5" :key="n" class="wm-skeleton h-10"></div>
            </div>
            <StateBlock v-else-if="!data.activity.length" kind="empty" compact title="No recent activity" />
            <ActivityTimeline v-else :events="data.activity" :linkable="can('activity.view')" @select="openEvent" />
          </div>
        </section>
      </div>

      <!-- ============ Trends ============ -->
      <section aria-labelledby="trends-heading">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h2 id="trends-heading" class="text-[15px] font-semibold text-ink">Trends</h2>
          <p class="text-[12.5px] text-[#64748B]">{{ rangeText }}<template v-if="data && data.size === 7"> · bars grouped by week</template></p>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <ChartCard
            v-for="c in charts"
            :key="c.id"
            :title="c.title"
            :summary="c.summary"
            :legend="c.legend"
            :table="c.table"
            :state="chartState(c)"
            :refreshing="loading"
            :error-message="error"
            :min-height="c.minHeight || 220"
            :to="chartLinks[c.id] && can(chartLinks[c.id].permission) ? chartLinks[c.id].to : ''"
            v-bind="UNTRACKED_CHARTS[c.id] ? { emptyTitle: 'Not tracked yet', emptyMessage: UNTRACKED_CHARTS[c.id] } : {}"
          >
            <template v-if="c.kind === 'line'">
              <LineChart v-bind="c.props" />
            </template>
            <template v-else-if="c.kind === 'bar'">
              <BarChart v-bind="c.props" />
            </template>
            <template v-else-if="c.kind === 'money'">
              <div class="flex flex-col gap-4">
                <div v-for="panel in c.panels" :key="panel.title">
                  <p class="mb-1 text-[12.5px] font-medium text-[#475569]">{{ panel.title }}</p>
                  <LineChart v-bind="panel.props" />
                </div>
              </div>
            </template>
            <template v-else-if="c.kind === 'rank'">
              <RankBars v-bind="c.props" />
            </template>
          </ChartCard>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import DateRangeControl from '../components/DateRangeControl.vue';
import MetricCard from '../components/MetricCard.vue';
import MetricGroup from '../components/MetricGroup.vue';
import StateBlock from '../components/StateBlock.vue';
import ActivityTimeline from '../components/ActivityTimeline.vue';
import ChartCard from '../components/ChartCard.vue';
import LineChart from '../components/charts/LineChart.vue';
import BarChart from '../components/charts/BarChart.vue';
import RankBars from '../components/charts/RankBars.vue';
import { SERIES_COLORS } from '../components/charts/chartUtils.js';
import { getDashboard } from '../data/adminApi.js';
import { can } from '../permissions.js';
import {
  formatNumber, formatUGX, formatUGXCompact, formatCompact, formatPercent,
  percentChange, formatDay, formatAge, formatRelative,
} from '../utils/format.js';

/* ---------------- Data ---------------- */
const router = useRouter();
const days = ref(30);
const updatedAt = ref(null);
// Ticks so "Updated 2 min ago" stays true without reloading
const now = ref(Date.now());
const clock = setInterval(() => { now.value = Date.now(); }, 30000);
onBeforeUnmount(() => clearInterval(clock));

// Each chart opens the module it summarises
const chartLinks = {
  registrations: { to: '/admin/analytics/customers', permission: 'analytics.view' },
  jobs: { to: '/admin/marketplace/jobs', permission: 'marketplace.view' },
  conversion: { to: '/admin/analytics/conversion', permission: 'analytics.view' },
  money: { to: '/admin/finance/overview', permission: 'finance.view' },
  demand: { to: '/admin/analytics/demand', permission: 'analytics.view' },
  areas: { to: '/admin/location/overview', permission: 'location.view' },
  search: { to: '/admin/location/search-analytics', permission: 'location.view' },
  disputes: { to: '/admin/trust/disputes', permission: 'trust.view' },
};

function openEvent(e) {
  router.push({ path: '/admin/activity', query: { q: e.id } });
}
const compare = ref(true);
const data = ref(null);
const loading = ref(false);
const error = ref('');
let request = 0;

async function load() {
  const id = ++request;
  loading.value = true;
  error.value = '';
  try {
    const res = await getDashboard({ days: days.value, compare: compare.value });
    if (id === request) { data.value = res; updatedAt.value = new Date(); now.value = Date.now(); }
  } catch (e) {
    if (id === request) error.value = e.message;
  } finally {
    if (id === request) loading.value = false;
  }
}
onMounted(load);
watch([days, compare], load);

const t = computed(() => data.value.totals);
const prev = computed(() => data.value.previous);
const s = computed(() => data.value.snapshot);
const delta = (key) => (prev.value ? percentChange(t.value[key], prev.value[key]) : null);
const isEmpty = computed(() => data.value && data.value.daily.every(d => d.searches === 0 && d.jobsCreated === 0));

const rangeText = computed(() => (data.value
  ? `${formatDay(data.value.range.start)} – ${formatDay(data.value.range.end)} (${data.value.range.days} days)`
  : ''));

const severityIcon = { critical: 'bi-exclamation-octagon', warning: 'bi-exclamation-triangle', info: 'bi-info-circle' };
const severityLabel = { critical: 'Critical', warning: 'Needs action', info: 'Monitor' };

/* ---------------- KPIs ---------------- */
const kpis = computed(() => {
  const d = data.value;
  const regs = t.value.customersNew + t.value.providersNew;
  const prevRegs = prev.value ? prev.value.customersNew + prev.value.providersNew : null;
  const list = [
    {
      label: 'New registrations', value: formatNumber(regs),
      delta: prev.value ? percentChange(regs, prevRegs) : null,
      context: `${formatNumber(t.value.customersNew)} customers · ${formatNumber(t.value.providersNew)} providers`,
      trend: d.daily.map(r => r.customersNew + r.providersNew),
    },
    {
      label: 'Open job requests', value: formatNumber(s.value.openJobs),
      context: 'Posted jobs and requests still waiting',
      hint: 'A point-in-time count, so it has no period comparison.',
      to: can('marketplace.view') ? '/admin/marketplace/jobs' : '',
    },
    {
      label: 'Gross transaction value', value: '', available: false,
      unavailableReason: 'Payments aren’t connected yet, so no transaction value is recorded.',
      permission: 'finance.summary',
    },
    {
      label: 'Platform commission', value: '', available: false,
      unavailableReason: 'Payments aren’t connected yet, so no commission is recorded.',
      permission: 'finance.summary',
    },
    {
      label: 'Searches with no match', value: '', available: false,
      unavailableReason: 'Search tracking isn’t connected yet.',
    },
  ];
  return list.filter(k => can(k.permission)).map(({ permission, ...k }) => k);
});

/* ---------------- Grouped metrics ---------------- */
const metricGroups = computed(() => {
  const groups = [
    {
      title: 'People',
      items: [
        { label: 'Registered customers', value: formatNumber(s.value.totalCustomers), to: can('marketplace.view') ? '/admin/marketplace/customers' : '' },
        { label: 'Registered providers', value: formatNumber(s.value.totalProviders), to: can('marketplace.view') ? '/admin/marketplace/providers' : '' },
        { label: 'Providers awaiting approval', value: formatNumber(s.value.providersAwaitingApproval), to: can('providers.review') ? '/admin/marketplace/applications' : '' },
        { label: 'Active users (daily average)', value: NOT_TRACKED, hint: 'App usage tracking isn’t connected yet.' },
      ],
    },
    {
      title: 'Jobs and bookings',
      items: [
        { label: 'Open job requests', value: formatNumber(s.value.openJobs), to: can('marketplace.view') ? '/admin/marketplace/jobs' : '' },
        { label: 'Jobs posted in the period', value: formatNumber(t.value.jobsCreated), delta: delta('jobsCreated') },
        { label: 'Requests sent to providers', value: formatNumber(t.value.enquiries), delta: delta('enquiries') },
        { label: 'Completed bookings', value: NOT_TRACKED, hint: 'Bookings aren’t built yet.' },
      ],
    },
    {
      title: 'Risk and money owed',
      items: [
        { label: 'Unresolved safety reports', value: formatNumber(s.value.unresolvedReports), to: can('trust.view') ? '/admin/trust/reports' : '' },
        { label: 'Open disputes', value: NOT_TRACKED, hint: 'Disputes need bookings, which aren’t built yet.' },
        ...(can('finance.summary') ? [{ label: 'Pending provider payouts', value: NOT_TRACKED, hint: 'Payouts aren’t connected yet.' }] : []),
      ],
    },
  ];
  // Document verification, the automatic blue tick and app usage are separate systems: separate groups
  // These three systems aren't running yet: say so rather than show zeros
  if (can('documents.review')) groups.push({
    title: 'Verification',
    items: [
      { label: 'WorkMate Verified providers', value: NOT_TRACKED, hint: 'Document verification needs secure file storage, which isn’t built yet.' },
      { label: 'Applications awaiting review', value: NOT_TRACKED },
      { label: 'Documents awaiting review', value: NOT_TRACKED },
    ],
  });
  if (can('reputation.view')) groups.push({
    title: 'Blue tick',
    items: [
      { label: 'Blue tick providers', value: NOT_TRACKED, hint: 'Automatic evaluation needs completed bookings and response data, which aren’t recorded yet.' },
      { label: 'Approaching eligibility', value: NOT_TRACKED },
    ],
  });
  if (can('usage.view')) groups.push({
    title: 'App usage',
    items: [
      { label: 'Recorded web app installs', value: NOT_TRACKED, hint: 'Install and usage tracking isn’t connected yet.' },
      { label: 'Daily active users', value: NOT_TRACKED },
      { label: 'Online now', value: NOT_TRACKED },
    ],
  });
  return groups;
});

/* ---------------- Priorities ---------------- */
const priorities = computed(() => (data.value?.priorities || []).filter(p => can(p.permission)));

/* ---------------- Charts ---------------- */
const [C1, C2, C3] = SERIES_COLORS;
const weekdayFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });

const customerChange = computed(() => {
  const c = delta('customersNew');
  return c == null ? '' : `; customer sign-ups ${c >= 0 ? 'up' : 'down'} ${formatPercent(Math.abs(c))} on the previous period`;
});

const NOT_TRACKED = 'Not tracked yet';

// Charts built only from figures nobody records yet: shown as "not tracked", never as zeros
const UNTRACKED_CHARTS = {
  conversion: 'Bookings aren’t built yet, so there’s no conversion to measure.',
  money: 'Payments aren’t connected yet, so no transaction value or commission is recorded.',
  search: 'Search tracking isn’t connected yet.',
  disputes: 'Disputes need bookings, which aren’t built yet.',
};

function chartState(c) {
  if (data.value && UNTRACKED_CHARTS[c.id]) return 'empty';
  if (!data.value) return error.value ? 'error' : 'loading';
  return isEmpty.value || c.empty ? 'empty' : 'ready';
}

const charts = computed(() => {
  if (!data.value) return placeholderCharts.filter(c => can(c.permission));
  const d = data.value;
  const tot = t.value;
  const dayLabels = d.daily.map(r => formatDay(r.date));
  const dayTitles = d.daily.map(r => weekdayFmt.format(new Date(r.date)));
  const bLabels = d.buckets.map(b => formatDay(d.size === 7 ? b.start : b.end));
  const bTitles = d.buckets.map(b => (b.days > 1 ? `${formatDay(b.start)} – ${formatDay(b.end)}` : weekdayFmt.format(new Date(b.end))));
  const bNotes = d.buckets.map(b => (b.partial ? `Partial week (${b.days} days)` : ''));
  const bucketCol = { key: 'label', label: d.size === 7 ? 'Week' : 'Day' };
  const bucketRows = (fn) => d.buckets.map((b, i) => ({ label: bTitles[i], ...fn(b) }));
  const pctOf = (a, b) => (b ? a / b : 0);
  const change = (key) => {
    const c = delta(key);
    return c == null ? '' : ` (${c >= 0 ? 'up' : 'down'} ${formatPercent(Math.abs(c))} on the previous period)`;
  };

  const topDemand = d.categoryDemand[0];
  const demandTotal = d.categoryDemand.reduce((n, r) => n + r.value, 0);
  const areas = d.providerAreas;
  const noLocation = areas.find(a => a.label === 'No area set');
  const topArea = areas.filter(a => !a.muted).sort((a, b) => b.value - a.value)[0];

  return [
    {
      id: 'registrations', kind: 'line', title: 'Registrations',
      summary: `${formatNumber(tot.customersNew)} customers and ${formatNumber(tot.providersNew)} providers registered${customerChange.value}.`,
      legend: [{ label: 'Customers', color: C1 }, { label: 'Providers', color: C2 }],
      props: {
        labels: dayLabels, tooltipTitles: dayTitles, ariaLabel: 'Daily customer and provider registrations',
        series: [
          { key: 'c', label: 'Customers', color: C1, values: d.daily.map(r => r.customersNew) },
          { key: 'p', label: 'Providers', color: C2, values: d.daily.map(r => r.providersNew) },
        ],
        formatTick: formatCompact, formatValue: formatNumber,
      },
      table: { columns: [{ key: 'label', label: 'Day' }, { key: 'c', label: 'Customers', format: formatNumber }, { key: 'p', label: 'Providers', format: formatNumber }],
        rows: d.daily.map((r, i) => ({ label: dayTitles[i], c: r.customersNew, p: r.providersNew })) },
    },
    {
      id: 'jobs', kind: 'bar', title: 'Jobs posted and requests sent',
      summary: `${formatNumber(tot.jobsCreated)} jobs posted for any provider and ${formatNumber(tot.enquiries)} requests sent to a chosen provider.`,
      legend: [{ label: 'Jobs posted', color: C1 }, { label: 'Requests to a provider', color: C2 }],
      props: {
        labels: bLabels, tooltipTitles: bTitles, tooltipNotes: bNotes, ariaLabel: 'Jobs posted and requests sent',
        series: [
          { key: 'cr', label: 'Jobs posted', color: C1, values: d.buckets.map(b => b.jobsCreated) },
          { key: 'co', label: 'Requests to a provider', color: C2, values: d.buckets.map(b => b.enquiries) },
        ],
        formatTick: formatCompact, formatValue: formatNumber,
      },
      table: { columns: [bucketCol, { key: 'cr', label: 'Jobs posted', format: formatNumber }, { key: 'co', label: 'Requests to a provider', format: formatNumber }],
        rows: bucketRows(b => ({ cr: b.jobsCreated, co: b.enquiries })) },
    },
    {
      id: 'conversion', kind: 'line', title: 'Booking conversion and cancellation',
      summary: `${formatPercent(pctOf(tot.bookingsCreated, tot.jobsCreated))} of job requests became bookings; ${formatPercent(pctOf(tot.bookingsCancelled, tot.bookingsCreated))} of bookings were cancelled.`,
      legend: [{ label: 'Converted to booking', color: C1 }, { label: 'Cancelled', color: C2 }],
      props: {
        labels: bLabels, tooltipTitles: bTitles, ariaLabel: 'Booking conversion and cancellation rates',
        series: [
          { key: 'cv', label: 'Converted to booking', color: C1, values: d.buckets.map(b => pctOf(b.bookingsCreated, b.jobsCreated)) },
          { key: 'ca', label: 'Cancelled', color: C2, values: d.buckets.map(b => pctOf(b.bookingsCancelled, b.bookingsCreated)) },
        ],
        formatTick: v => formatPercent(v, 0), formatValue: v => formatPercent(v),
      },
      table: { columns: [bucketCol, { key: 'cv', label: 'Converted', format: v => formatPercent(v) }, { key: 'ca', label: 'Cancelled', format: v => formatPercent(v) }],
        rows: bucketRows(b => ({ cv: pctOf(b.bookingsCreated, b.jobsCreated), ca: pctOf(b.bookingsCancelled, b.bookingsCreated) })) },
      empty: false,
    },
    ...(can('finance.summary') ? [{
      // Two panels, two scales: never a dual axis
      id: 'money', kind: 'money', title: 'Transaction value and platform commission', minHeight: 330,
      summary: `${formatUGXCompact(tot.gtv)} gross transaction value${change('gtv')}; ${formatUGXCompact(tot.commission)} platform commission. These are separate figures: GTV is not revenue.`,
      panels: [
        { title: 'Gross transaction value', props: {
          labels: dayLabels, tooltipTitles: dayTitles, height: 112, ariaLabel: 'Daily gross transaction value',
          series: [{ key: 'g', label: 'GTV', color: C1, values: d.daily.map(r => r.gtv) }],
          formatTick: v => formatCompact(v), formatValue: formatUGX } },
        { title: 'Platform commission', props: {
          labels: dayLabels, tooltipTitles: dayTitles, height: 112, ariaLabel: 'Daily platform commission',
          series: [{ key: 'm', label: 'Commission', color: C1, values: d.daily.map(r => r.commission) }],
          formatTick: v => formatCompact(v), formatValue: formatUGX } },
      ],
      table: { columns: [{ key: 'label', label: 'Day' }, { key: 'g', label: 'GTV (UGX)', format: formatNumber }, { key: 'm', label: 'Commission (UGX)', format: formatNumber }],
        rows: d.daily.map((r, i) => ({ label: dayTitles[i], g: r.gtv, m: r.commission })) },
    }] : []),
    {
      id: 'demand', kind: 'rank', title: 'Service demand by category',
      summary: demandTotal ? `${topDemand.label} was the most requested service, ${formatPercent(topDemand.value / demandTotal, 0)} of ${formatNumber(demandTotal)} job requests.` : '',
      props: { rows: d.categoryDemand, formatValue: formatNumber, showShare: true, ariaLabel: 'Job requests by service category' },
      table: { columns: [{ key: 'label', label: 'Category' }, { key: 'value', label: 'Job requests', format: formatNumber }], rows: d.categoryDemand },
      empty: !demandTotal,
    },
    {
      id: 'areas', kind: 'rank', title: 'Providers by registered area',
      summary: topArea?.value ? `${topArea.label} has the most registered providers. ${formatNumber(noLocation.value)} providers have no area set.` : '',
      props: { rows: areas, formatValue: formatNumber, ariaLabel: 'Registered providers by base area' },
      table: { columns: [{ key: 'label', label: 'Area' }, { key: 'value', label: 'Providers', format: formatNumber }], rows: areas },
      empty: !topArea?.value,
    },
    {
      id: 'search', kind: 'bar', title: 'Search outcomes',
      summary: `${formatPercent(pctOf(tot.searchNearby, tot.searches))} matched nearby, ${formatPercent(pctOf(tot.searchWider, tot.searches))} after widening the search, ${formatPercent(pctOf(tot.searchNone, tot.searches))} found no suitable provider.`,
      legend: [{ label: 'Nearby match', color: C1 }, { label: 'Wider-area match', color: C2 }, { label: 'No match', color: C3 }],
      props: {
        stacked: true, labels: bLabels, tooltipTitles: bTitles, tooltipNotes: bNotes, ariaLabel: 'Search outcomes',
        series: [
          { key: 'n', label: 'Nearby match', color: C1, values: d.buckets.map(b => b.searchNearby) },
          { key: 'w', label: 'Wider-area match', color: C2, values: d.buckets.map(b => b.searchWider) },
          { key: 'x', label: 'No match', color: C3, values: d.buckets.map(b => b.searchNone) },
        ],
        formatTick: formatCompact, formatValue: formatNumber,
      },
      table: { columns: [bucketCol, { key: 'n', label: 'Nearby', format: formatNumber }, { key: 'w', label: 'Wider area', format: formatNumber }, { key: 'x', label: 'No match', format: formatNumber }],
        rows: bucketRows(b => ({ n: b.searchNearby, w: b.searchWider, x: b.searchNone })) },
    },
    {
      id: 'disputes', kind: 'bar', title: 'Disputes opened and resolved',
      summary: `${formatNumber(tot.disputesOpened)} disputes opened and ${formatNumber(tot.disputesResolved)} resolved in the period.`,
      legend: [{ label: 'Opened', color: C1 }, { label: 'Resolved', color: C2 }],
      props: {
        labels: bLabels, tooltipTitles: bTitles, tooltipNotes: bNotes, ariaLabel: 'Disputes opened and resolved',
        series: [
          { key: 'o', label: 'Opened', color: C1, values: d.buckets.map(b => b.disputesOpened) },
          { key: 'r', label: 'Resolved', color: C2, values: d.buckets.map(b => b.disputesResolved) },
        ],
        formatTick: formatCompact, formatValue: formatNumber,
      },
      table: { columns: [bucketCol, { key: 'o', label: 'Opened', format: formatNumber }, { key: 'r', label: 'Resolved', format: formatNumber }],
        rows: bucketRows(b => ({ o: b.disputesOpened, r: b.disputesResolved })) },
    },
  ];
});

// Card frames shown while the first load is in flight
const placeholderCharts = [
  'Registrations', 'Jobs created and completed', 'Booking conversion and cancellation',
  'Transaction value and platform commission', 'Service demand by category', 'Providers by registered area',
  'Search outcomes', 'Disputes opened and resolved',
].map((title, i) => ({
  id: `ph${i}`, title, summary: '', legend: [], table: null, kind: 'none',
  permission: title.startsWith('Transaction') ? 'finance.summary' : undefined,
}));
</script>
