<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader :title="item.label" :description="def.description || item.summary" demo>
      <template #actions>
        <button
          v-if="def.create?.run && can(def.create.permission || item.permission)"
          type="button"
          class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]"
          @click="openCreate"
        ><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ def.create.label }}</button>
        <button
          v-if="def.exportable && can(def.exportPermission || 'analytics.view')"
          type="button"
          class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]"
          :disabled="!filtered.length"
          @click="exportCsv"
        ><i class="bi bi-download" aria-hidden="true"></i>Export CSV</button>
      </template>
    </AdminPageHeader>

    <p v-if="def.notice" class="wm-notice" :class="def.noticeTone === 'warning' ? 'wm-notice-warning' : 'wm-notice-info'">
      <i :class="['bi', def.noticeTone === 'warning' ? 'bi-exclamation-triangle' : 'bi-info-circle']" aria-hidden="true"></i>
      <span>{{ def.notice }}</span>
    </p>

    <div v-if="error" class="rounded-xl wm-surface">
      <StateBlock kind="error" :title="`${item.label} couldn’t load`" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>
    <div v-else-if="!rows" class="flex flex-col gap-3" aria-busy="true">
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3"><div v-for="n in 4" :key="n" class="wm-skeleton h-[92px] !rounded-xl"></div></div>
      <div class="wm-skeleton h-[320px] !rounded-xl"></div>
      <span class="sr-only" role="status">Loading {{ item.label }}…</span>
    </div>

    <template v-else>
      <!-- Summary figures -->
      <section v-if="kpis.length" aria-label="Summary" class="grid grid-cols-2 lg:grid-cols-4 gap-2.5 sm:gap-3">
        <MetricCard v-for="k in kpis" :key="k.label" v-bind="k" />
      </section>

      <ChartGrid v-if="charts.length" :charts="charts" :columns="charts.length > 1 ? 2 : 1" />

      <!-- Queue tabs -->
      <div v-if="def.tabs" class="wm-scroll-row !mx-0 !px-0 lg:!flex-nowrap" role="group" :aria-label="`${item.label} views`">
        <button
          v-for="t in tabCounts"
          :key="t.id"
          type="button"
          class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-[13px] font-medium transition-colors"
          :class="tab === t.id ? 'border-primary bg-[#EFF6FF] text-primary' : 'border-[#E2E8F0] bg-white text-[#475569] hover:border-[#CBD5E1]'"
          :aria-pressed="tab === t.id"
          @click="tab = t.id"
        >
          {{ t.label }}
          <span class="rounded-md px-1.5 text-[11.5px] tabular-nums" :class="tab === t.id ? 'bg-primary text-white' : 'bg-[#F1F5F9] text-[#475569]'">{{ formatNumber(t.count) }}</span>
        </button>
      </div>

      <!-- Search and filters, applied immediately -->
      <form role="search" class="flex flex-col gap-2 rounded-xl wm-surface p-3 lg:flex-row lg:flex-wrap lg:items-center" @submit.prevent>
        <div class="relative min-w-0 lg:flex-1 lg:min-w-[240px]">
          <label :for="`${uid}-q`" class="sr-only">Search {{ item.label.toLowerCase() }}</label>
          <i class="bi bi-search pointer-events-none absolute z-[1] left-3 top-1/2 -translate-y-1/2 text-[14px] text-[#94A3B8]" aria-hidden="true"></i>
          <input :id="`${uid}-q`" v-model="query" type="search" :placeholder="def.search?.placeholder || 'Search'" class="h-10 w-full rounded-lg wm-surface pl-9 pr-3 text-[13.5px] focus:outline-none focus:ring-2 focus:ring-primary" />
        </div>
        <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
          <label v-for="f in def.filters || []" :key="f.key" class="block min-w-0 sm:w-auto">
            <span class="sr-only">{{ f.label }}</span>
            <select v-model="filterValues[f.key]" class="h-10 w-full rounded-lg wm-surface px-2.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto sm:max-w-[200px]">
              <option value="">{{ f.anyLabel || `Any ${f.label.toLowerCase()}` }}</option>
              <option v-for="[v, l] in f.options" :key="v" :value="v">{{ l }}</option>
            </select>
          </label>
          <label v-if="def.period" class="block">
            <span class="sr-only">{{ def.period.label || 'Period' }}</span>
            <select v-model="period" class="h-10 w-full rounded-lg wm-surface px-2.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary sm:w-auto">
              <option value="">Any time</option>
              <option value="1">Last 24 hours</option>
              <option value="7">Last 7 days</option>
              <option value="30">Last 30 days</option>
              <option value="90">Last 90 days</option>
            </select>
          </label>
          <button
            type="button"
            class="col-span-2 h-10 rounded-lg px-3 text-[13px] font-medium text-primary hover:bg-[#EFF6FF] disabled:text-[#94A3B8] disabled:hover:bg-transparent sm:col-span-1"
            :disabled="!activeFilters"
            @click="clearFilters"
          >Clear filters</button>
        </div>
      </form>

      <DataTable
        v-model:sort="sort"
        :caption="item.label"
        :columns="columns"
        :rows="pageRows"
        clickable
        :selected-key="selected?.id"
        :row-label="row => `Open ${titleOf(row)}`"
        @row-click="openRow"
      >
        <template #cell="{ row, column }">
          <span :class="column === columns[0] ? 'block font-medium text-ink leading-snug' : 'text-[#334155]'">
            <RecordValue :value="cellValue(row, column)" :format="column.format" :yes="column.yes" :no="column.no" />
          </span>
          <span v-if="column.sub" class="block text-[12px] text-[#64748B] leading-snug">{{ column.sub(row) }}</span>
        </template>
        <template #empty>
          <StateBlock
            kind="empty"
            :title="rows.length ? 'No records match' : `No ${item.label.toLowerCase()} yet`"
            :message="rows.length ? 'Try clearing a filter or searching for something else.' : (def.emptyMessage || 'Records will appear here once they exist.')"
          >
            <button v-if="activeFilters" type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="clearFilters">Clear filters</button>
          </StateBlock>
        </template>
      </DataTable>

      <TablePagination v-model:page="page" :page-size="pageSize" :total="filtered.length" :label="item.label" />
    </template>

    <!-- Record detail -->
    <DetailDrawer :open="!!selected" :title="selected ? titleOf(selected) : ''" :eyebrow="def.entity || item.label" @close="selected = null">
      <template #subtitle>
        <div v-if="selected" class="mt-1.5 flex flex-wrap items-center gap-2 text-[12.5px] text-[#64748B]">
          <AdminStatusBadge v-if="statusOf(selected)" :status="statusOf(selected)" />
          <span>{{ selected.id }}</span>
          <span v-if="selected.isSample" class="text-[#92400E]">· Sample record</span>
        </div>
      </template>

      <template v-if="selected">
        <section v-for="(s, i) in sections" :key="i" class="mb-5 last:mb-0">
          <h3 v-if="s.title" class="mb-2 text-[13px] font-semibold text-ink">{{ s.title }}</h3>

          <p v-if="s.permission && !can(s.permission)" class="flex items-center gap-2 rounded-lg bg-[#F8FAFC] p-3 text-[12.5px] text-[#64748B]">
            <i class="bi bi-lock" aria-hidden="true"></i>Restricted: your role doesn’t include “{{ PERMISSIONS[s.permission] }}”.
          </p>

          <dl v-else-if="s.fields" class="grid grid-cols-[minmax(120px,auto)_1fr] gap-x-4 gap-y-2 text-[13.5px]">
            <template v-for="[label, value, format] in s.fields" :key="label">
              <dt class="text-[#64748B]">{{ label }}</dt>
              <dd class="min-w-0 break-words text-ink"><RecordValue :value="value" :format="format || 'text'" /></dd>
            </template>
          </dl>

          <div v-else-if="s.table">
            <p v-if="!s.table.rows.length" class="text-[13px] text-[#64748B]">{{ s.table.empty || 'None.' }}</p>
            <div v-else class="overflow-x-auto rounded-lg border border-[#E2E8F0]">
              <table class="w-full text-[12.5px]">
                <caption class="sr-only">{{ s.title }}</caption>
                <thead class="bg-[#F8FAFC] text-[#475569]">
                  <tr><th v-for="c in s.table.columns" :key="c.key" scope="col" class="px-2.5 py-1.5 text-left font-semibold whitespace-nowrap">{{ c.label }}</th></tr>
                </thead>
                <tbody>
                  <tr v-for="(r, ri) in s.table.rows.slice(0, s.table.limit || 8)" :key="ri" class="border-t border-[#F1F5F9]">
                    <td v-for="c in s.table.columns" :key="c.key" class="px-2.5 py-1.5 align-top"><RecordValue :value="r[c.key]" :format="c.format || 'text'" /></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="s.table.rows.length > (s.table.limit || 8)" class="mt-1 text-[12px] text-[#64748B]">
              Showing {{ s.table.limit || 8 }} of {{ formatNumber(s.table.rows.length) }}.
            </p>
          </div>

          <router-link v-else-if="s.link" :to="s.link.to" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]">
            {{ s.link.label }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </router-link>
          <p v-else-if="s.text" class="text-[13.5px] leading-relaxed text-[#334155]">{{ s.text }}</p>
          <p v-else-if="s.note" class="rounded-lg bg-[#F8FAFC] p-3 text-[12.5px] leading-relaxed text-[#64748B]">{{ s.note }}</p>

          <div v-else-if="s.preview" class="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-lg bg-[#0F172A] text-white/80">
            <i :class="['bi', s.preview.icon || 'bi-play-circle', 'text-4xl']" aria-hidden="true"></i>
            <p class="px-4 text-center text-[12.5px]">{{ s.preview.text }}</p>
          </div>

          <ol v-else-if="s.timeline" class="flex flex-col gap-2.5">
            <li v-if="!historyOf(selected).length" class="text-[13px] text-[#64748B]">No recorded actions yet.</li>
            <li v-for="(h, hi) in [...historyOf(selected)].reverse()" :key="hi" class="flex gap-2.5 text-[13px]">
              <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="h.session ? 'bg-[#F59E0B]' : 'bg-[#CBD5E1]'" aria-hidden="true"></span>
              <span class="min-w-0">
                <span class="font-medium text-ink">{{ h.label }}</span>
                <span v-if="h.reason" class="text-[#475569]"> — {{ h.reason }}</span>
                <span v-if="h.detail" class="block text-[#475569]">{{ h.detail }}</span>
                <span class="block text-[12px] text-[#64748B]">{{ formatDateTime(h.at) }} · {{ h.actor }}</span>
              </span>
            </li>
          </ol>
        </section>
      </template>

      <template v-if="selected && (visibleActions.length || hiddenActionCount || notConnectedCount)" #footer>
        <div class="flex w-full flex-col gap-2">
          <div v-if="visibleActions.length" class="flex flex-wrap gap-2">
            <button
              v-for="a in visibleActions"
              :key="a.id"
              type="button"
              class="wm-btn !min-h-10 !rounded-lg !px-3.5 !text-[13.5px]"
              :class="a.tone === 'danger' ? 'border-[#FCA5A5] bg-white text-[#B91C1C] hover:bg-[#FEF2F2]' : a.tone === 'primary' ? 'wm-btn-primary' : 'wm-btn-secondary'"
              @click="startAction(a)"
            ><i v-if="a.icon" :class="['bi', a.icon]" aria-hidden="true"></i>{{ a.label }}</button>
          </div>
          <p v-if="hiddenActionCount" class="text-[12px] text-[#64748B]">
            <i class="bi bi-lock" aria-hidden="true"></i> Some actions need a role with more access.
          </p>
          <p v-if="notConnectedCount" class="text-[12px] text-[#64748B]">
            <i class="bi bi-plug" aria-hidden="true"></i>
            {{ notConnectedCount === 1 ? 'One more action for this record isn’t' : `${notConnectedCount} more actions for this record aren’t` }} connected yet.
          </p>
        </div>
      </template>
    </DetailDrawer>

    <ActionDialog
      :open="!!dialog"
      :title="dialog?.title || ''"
      :message="dialog?.message || ''"
      :confirm-label="dialog?.confirmLabel || 'Confirm'"
      :tone="dialog?.tone || 'primary'"
      :fields="dialog?.fields || []"
      :busy="!!dialog?.busy"
      :error="dialog?.error || ''"
      @close="dialog = null"
      @confirm="confirmDialog"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, useId } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminPageHeader from './AdminPageHeader.vue';
import AdminStatusBadge from './AdminStatusBadge.vue';
import MetricCard from './MetricCard.vue';
import ChartGrid from './ChartGrid.vue';
import DataTable from './DataTable.vue';
import TablePagination from './TablePagination.vue';
import DetailDrawer from './DetailDrawer.vue';
import ActionDialog from './ActionDialog.vue';
import StateBlock from './StateBlock.vue';
import RecordValue, { formatValue } from './RecordValue.vue';
import { fetchDataset, relations, reloadAdminData } from '../data/adminApi.js';
import { can, PERMISSIONS } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatNumber, formatDateTime } from '../utils/format.js';

const props = defineProps({
  def:  { type: Object, required: true },   // module definition (admin/modules/*)
  item: { type: Object, required: true },   // navigation item
});

const route = useRoute();
const router = useRouter();
const uid = useId();
const def = props.def;

/* ---------------- Data ---------------- */
const rows = ref(null);
const error = ref('');

async function load() {
  error.value = '';
  try {
    rows.value = (await fetchDataset(props.item.id)).rows;
    openFromQuery();
  } catch (e) {
    error.value = e.message;
  }
}
onMounted(load);

const ctx = { rel: relations, can };
const kpis = computed(() => (rows.value && def.kpis ? def.kpis(rows.value, ctx).filter(k => !k.permission || can(k.permission)).map(({ permission, ...k }) => k) : []));
const charts = computed(() => (rows.value && def.charts ? def.charts(rows.value, ctx) : []));

/* ---------------- Columns, search, filters, sort ---------------- */
const columns = computed(() => def.columns.filter(c => !c.permission || can(c.permission)));
const cellValue = (row, c) => (c.value ? c.value(row, ctx) : row[c.key]);
const titleOf = (row) => (def.detail?.title ? def.detail.title(row, ctx) : row.name || row.title || row.id);
const statusOf = (row) => (def.statusKey === false ? null : row[def.statusKey || 'status']);
const historyOf = (row) => row.history || [];

const query = ref('');
const filterValues = reactive(Object.fromEntries((def.filters || []).map(f => [f.key, ''])));
const period = ref('');
const tab = ref(def.tabs?.[0]?.id || '');
const sort = ref(def.defaultSort || { key: def.columns[0].key, dir: 'asc' });
const page = ref(1);
const pageSize = 20;

const searchText = new WeakMap();
function textOf(row) {
  let t = searchText.get(row);
  if (t == null) {
    t = (def.search?.text ? def.search.text(row, ctx) : (def.search?.keys || ['id', 'name']).map(k => row[k] ?? '').join(' ')).toLowerCase();
    searchText.set(row, t);
  }
  return t;
}

const activeFilters = computed(() => !!query.value.trim() || !!period.value || Object.values(filterValues).some(Boolean));
function clearFilters() {
  query.value = '';
  period.value = '';
  for (const k of Object.keys(filterValues)) filterValues[k] = '';
}

const tabCounts = computed(() => (def.tabs || []).map(t => ({ ...t, count: rows.value ? rows.value.filter(t.filter || (() => true)).length : 0 })));

const filtered = computed(() => {
  if (!rows.value) return [];
  const needle = query.value.trim().toLowerCase();
  const tabDef = def.tabs?.find(t => t.id === tab.value);
  const cutoff = period.value ? Date.now() - Number(period.value) * 86400000 : null;
  const list = rows.value.filter(r =>
    (!tabDef?.filter || tabDef.filter(r)) &&
    (!needle || textOf(r).includes(needle)) &&
    (!cutoff || new Date(r[def.period.key]).getTime() >= cutoff) &&
    (def.filters || []).every(f => !filterValues[f.key] || (f.match ? f.match(r, filterValues[f.key]) : String(r[f.key]) === filterValues[f.key]))
  );
  const col = def.columns.find(c => c.key === sort.value.key);
  if (!col) return list;
  const get = col.sortValue || ((r) => cellValue(r, col));
  const mul = sort.value.dir === 'asc' ? 1 : -1;
  return [...list].sort((a, b) => {
    const x = get(a, ctx);
    const y = get(b, ctx);
    if (x == null && y == null) return 0;
    if (x == null) return 1;
    if (y == null) return -1;
    return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * mul;
  });
});

const pageRows = computed(() => filtered.value.slice((page.value - 1) * pageSize, page.value * pageSize));
watch([query, period, tab, sort, () => ({ ...filterValues })], () => { page.value = 1; });

/* ---------------- Detail ---------------- */
const selected = ref(null);
const sections = computed(() => (selected.value && def.detail ? def.detail.sections(selected.value, ctx) : []));

// Some modules have a dedicated page per record (e.g. the verification review workspace)
function openRow(row) {
  if (def.rowLink) router.push(def.rowLink(row));
  else selected.value = row;
}

// Search results and Create links can target the page that's already open
watch(() => [route.query.open, route.query.create], ([open, create]) => { if ((open || create) && rows.value) openFromQuery(); });

function openFromQuery() {
  const id = route.query.open;
  if (id) {
    const row = rows.value.find(r => r.id === id);
    if (row) openRow(row);
  }
  if (route.query.create && def.create) openCreate();
  if (route.query.open || route.query.create) router.replace({ query: {} });
}

/* ---------------- Actions ---------------- */
// Only actions with a server endpoint (`run`) are offered; the rest aren't built yet
const applicableActions = computed(() => (selected.value ? (def.actions || []).filter(a => !a.when || a.when(selected.value, ctx)) : []));
const allowedActions = computed(() => applicableActions.value.filter(a => a.run));
const notConnectedCount = computed(() => applicableActions.value.length - allowedActions.value.length);
const visibleActions = computed(() => allowedActions.value.filter(a => can(a.permission || props.item.permission)));
const hiddenActionCount = computed(() => allowedActions.value.length - visibleActions.value.length);

const dialog = ref(null);

function startAction(a) {
  const row = selected.value;
  const fields = a.fields ? a.fields(row, ctx) : a.reason
    ? [{ key: 'reason', label: a.reasonLabel || 'Reason', type: 'textarea', required: a.reason === 'required', minLength: a.reason === 'required' ? 5 : 0, help: a.reasonHelp }]
    : [];
  dialog.value = {
    kind: 'action', action: a, row,
    title: a.confirmTitle ? a.confirmTitle(row, ctx) : `${a.label}?`,
    message: a.message ? a.message(row, ctx) : '',
    confirmLabel: a.confirmLabel || a.label,
    tone: a.tone === 'danger' ? 'danger' : 'primary',
    fields,
  };
}

function openCreate() {
  dialog.value = {
    kind: 'create', title: def.create.label,
    message: (typeof def.create.message === 'function' ? def.create.message() : def.create.message) || '',
    confirmLabel: def.create.confirmLabel || 'Create', tone: 'primary', fields: def.create.fields,
  };
}

/**
 * Runs the action on the server, then reloads the records from the database.
 * Errors stay in the dialog so nothing looks done when it isn't.
 */
async function confirmDialog(values) {
  const d = dialog.value;
  d.busy = true;
  d.error = '';
  try {
    let label;
    if (d.kind === 'create') {
      await def.create.run(values);
      label = def.create.done || 'Created';
    } else {
      await d.action.run(d.row, values, ctx);
      label = `${d.action.done || d.action.label}: ${titleOf(d.row)}`;
    }
    await reloadAdminData();
    await load();
    // Keep the drawer open on the same record, now showing its new state
    if (d.kind !== 'create') selected.value = rows.value.find(r => r.id === d.row.id) || null;
    dialog.value = null;
    toast(`${label}. Saved and recorded in the audit log.`);
  } catch (e) {
    d.error = fieldMessage(e);
  } finally {
    d.busy = false;
  }
}

function fieldMessage(e) {
  const first = Object.values(e?.errors || {})[0];
  return (first && first[0]) || e?.message || 'Something went wrong. Please try again.';
}

/* ---------------- Export ---------------- */
function exportCsv() {
  const cols = def.columns.filter(c => !c.permission || can(c.permission));
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const lines = [
    cols.map(c => esc(c.label)).join(','),
    ...filtered.value.map(r => cols.map(c => esc(formatValue(cellValue(r, c), c.format))).join(',')),
  ];
  const blob = new Blob([`# WorkMate ${props.item.label}: exported ${new Date().toISOString()}. Contact details are masked.\n${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `workmate-${props.item.id}-${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
  toast(`Exported ${formatNumber(filtered.value.length)} records to CSV.`);
}
</script>
