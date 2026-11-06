<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader
      title="Activity Centre"
      description="Operational events across WorkMate: accounts, providers, searches, bookings, payments, cases, content, notifications and system."
      demo
    />

    <p class="flex items-start gap-2 text-[12.5px] text-[#64748B]">
      <i class="bi bi-info-circle mt-px" aria-hidden="true"></i>
      <span>
        This is the operational event log. Administrator actions for compliance live in the separate
        <router-link v-if="can('security.view')" to="/admin/system/audit" class="text-primary hover:underline">audit log</router-link><template v-else>audit log</template>,
        with its own access and retention rules.<template v-if="!can('security.view')"> Security events are hidden for your role.</template>
      </span>
    </p>

    <!-- Filters: one row, applied immediately -->
    <form role="search" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[minmax(220px,1fr)_repeat(4,minmax(0,170px))_auto] gap-2 rounded-xl wm-surface p-3" @submit.prevent>
      <div class="relative sm:col-span-2 lg:col-span-1">
        <label for="ev-q" class="sr-only">Search events</label>
        <i class="bi bi-search pointer-events-none absolute z-[1] left-3 top-1/2 -translate-y-1/2 text-[14px] text-[#94A3B8]" aria-hidden="true"></i>
        <input id="ev-q" v-model="filters.q" type="search" placeholder="Search summary, reference or record ID" class="h-10 w-full rounded-lg wm-surface pl-9 pr-3 text-[13.5px] focus:outline-none focus:ring-2 focus:ring-primary" />
      </div>
      <FilterSelect v-model="filters.category" label="Category" :options="categoryOptions" />
      <FilterSelect v-model="filters.severity" label="Severity" :options="[['', 'Any severity'], ['critical', 'Critical'], ['warning', 'Warning'], ['info', 'Info']]" />
      <FilterSelect v-model="filters.outcome" label="Outcome" :options="[['', 'Any outcome'], ['success', 'Succeeded'], ['problem', 'Failed or no match']]" />
      <FilterSelect v-model="filters.days" label="Period" :options="[[1, 'Last 24 hours'], [7, 'Last 7 days'], [14, 'Last 14 days']]" number />
      <button
        type="button"
        class="h-10 rounded-lg px-3 text-[13px] font-medium text-primary hover:bg-[#EFF6FF] disabled:text-[#94A3B8] disabled:hover:bg-transparent"
        :disabled="!activeFilters"
        @click="resetFilters"
      >Clear filters</button>
    </form>

    <div v-if="error" class="rounded-xl wm-surface">
      <StateBlock kind="error" title="Events couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>
    <div v-else-if="!result" class="rounded-xl wm-surface">
      <StateBlock kind="loading" title="Loading events…" />
    </div>

    <template v-else>
      <DataTable
        v-model:sort="sort"
        caption="Operational events"
        :columns="columns"
        :rows="result.rows"
        :loading="loading"
        clickable
        :selected-key="selected?.id"
        :row-label="row => `Open event: ${row.summary}`"
        @row-click="selected = $event"
      >
        <template #cell-summary="{ row }">
          <span class="block font-medium text-ink leading-snug">{{ row.summary }}</span>
          <span class="block text-[12px] text-[#64748B]">{{ row.type }}</span>
        </template>
        <template #cell-timestamp="{ row }">
          <time :datetime="row.timestamp" :title="formatDateTime(row.timestamp)" class="whitespace-nowrap text-[#475569]">{{ formatRelative(row.timestamp) }}</time>
        </template>
        <template #cell-category="{ row }">
          <span class="whitespace-nowrap text-[#475569]">{{ EVENT_CATEGORIES[row.category] }}</span>
        </template>
        <template #cell-entity="{ row }">
          <span class="block truncate max-w-[220px] text-ink">{{ row.entity.label }}</span>
          <span class="block text-[12px] text-[#64748B]">{{ row.entity.id }}</span>
        </template>
        <template #cell-actorName="{ row }">
          <span class="text-[#475569]">{{ row.actorName }}</span>
        </template>
        <template #cell-severity="{ row }">
          <span class="inline-flex flex-wrap gap-1">
            <AdminStatusBadge :status="row.severity" />
            <AdminStatusBadge v-if="row.outcome !== 'success'" :status="row.outcome" />
          </span>
        </template>

        <template #empty>
          <StateBlock kind="empty" title="No events match" :message="activeFilters ? 'Try clearing a filter or widening the period.' : 'Nothing was recorded in this period.'">
            <button v-if="activeFilters" type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="resetFilters">Clear filters</button>
          </StateBlock>
        </template>
      </DataTable>

      <TablePagination v-model:page="page" :page-size="pageSize" :total="result.total" label="Events" />
    </template>

    <!-- Event detail -->
    <DetailDrawer :open="!!selected" :title="selected?.summary || ''" :eyebrow="selected ? EVENT_CATEGORIES[selected.category] : ''" @close="selected = null">
      <template v-if="selected">
        <div class="flex flex-wrap items-center gap-2">
          <AdminStatusBadge :status="selected.severity" />
          <AdminStatusBadge :status="selected.outcome" />
          <span v-if="selected.demo" class="text-[12px] text-[#92400E]">Demo event</span>
        </div>

        <dl class="mt-4 grid grid-cols-[minmax(120px,auto)_1fr] gap-x-4 gap-y-2.5 text-[13.5px]">
          <dt class="text-[#64748B]">Event type</dt><dd class="font-mono text-[12.5px] text-ink">{{ selected.type }}</dd>
          <dt class="text-[#64748B]">Time</dt><dd class="text-ink">{{ formatDateTime(selected.timestamp) }}</dd>
          <dt class="text-[#64748B]">Initiated by</dt><dd class="text-ink">{{ selected.actorName }}</dd>
          <dt class="text-[#64748B]">Related record</dt>
          <dd class="text-ink">
            {{ selected.entity.label }} <span class="text-[#64748B]">({{ selected.entity.id }})</span>
            <a v-if="selected.entity.href" :href="selected.entity.href" target="_blank" rel="noopener" class="mt-0.5 flex items-center gap-1 text-[13px] font-medium text-primary hover:underline">
              Open it in WorkMate<i class="bi bi-box-arrow-up-right text-[11px]" aria-hidden="true"></i>
            </a>
          </dd>
          <dt class="text-[#64748B]">Event ID</dt><dd class="font-mono text-[12.5px] text-ink">{{ selected.id }}</dd>
          <dt class="text-[#64748B]">Correlation ID</dt><dd class="font-mono text-[12.5px] text-ink">{{ selected.correlationId }}</dd>
        </dl>

        <h3 class="mt-5 text-[13px] font-semibold text-ink">Details</h3>
        <dl class="mt-2 grid grid-cols-[minmax(120px,auto)_1fr] gap-x-4 gap-y-2 rounded-lg bg-[#F8FAFC] p-3 text-[13px]">
          <template v-for="(value, key) in selected.details" :key="key">
            <dt class="text-[#64748B]">{{ key }}</dt>
            <dd class="text-ink">{{ key === 'Amount' ? formatUGX(value) : value }}</dd>
          </template>
        </dl>
        <p class="mt-4 text-[12px] leading-relaxed text-[#64748B]">
          Events keep only what operations needs: no passwords, tokens, message contents or precise locations.
        </p>
      </template>
    </DetailDrawer>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, h } from 'vue';
import { useRoute } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import AdminStatusBadge from '../components/AdminStatusBadge.vue';
import DataTable from '../components/DataTable.vue';
import TablePagination from '../components/TablePagination.vue';
import DetailDrawer from '../components/DetailDrawer.vue';
import StateBlock from '../components/StateBlock.vue';
import { listEvents, EVENT_CATEGORIES } from '../data/adminApi.js';
import { can } from '../permissions.js';
import { formatRelative, formatDateTime, formatUGX } from '../utils/format.js';

/** Small labelled select used by the filter row */
const FilterSelect = (props, { emit }) => h('label', { class: 'block' }, [
  h('span', { class: 'sr-only' }, props.label),
  h('select', {
    class: 'h-10 w-full rounded-lg wm-surface px-2.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary',
    value: props.modelValue,
    onChange: e => emit('update:modelValue', props.number ? Number(e.target.value) : e.target.value),
  }, props.options.map(([value, text]) => h('option', { value }, text))),
]);
FilterSelect.props = ['modelValue', 'label', 'options', 'number'];
FilterSelect.emits = ['update:modelValue'];

const columns = [
  { key: 'summary', label: 'Event', class: 'min-w-[240px]' },
  { key: 'timestamp', label: 'Time', sortable: true, width: '120px' },
  { key: 'category', label: 'Category', sortable: true, hideBelow: 'lg' },
  { key: 'entity', label: 'Related record', hideBelow: 'lg' },
  { key: 'actorName', label: 'Initiated by' },
  { key: 'severity', label: 'Severity', sortable: true },
];

const defaults = { q: '', category: '', severity: '', outcome: '', days: 14 };
const route = useRoute();
// Opened from the dashboard feed with ?q=<event id>, or from a priority with ?category=
const filters = reactive({
  ...defaults,
  q: typeof route.query.q === 'string' ? route.query.q : '',
  category: typeof route.query.category === 'string' && EVENT_CATEGORIES[route.query.category] ? route.query.category : '',
});
const sort = ref({ key: 'timestamp', dir: 'desc' });
const page = ref(1);
const pageSize = 15;

const categoryOptions = computed(() => [
  ['', 'All categories'],
  ...Object.entries(EVENT_CATEGORIES).filter(([id]) => id !== 'security' || can('security.view')),
]);
const activeFilters = computed(() => Object.keys(defaults).some(k => filters[k] !== defaults[k]));
function resetFilters() { Object.assign(filters, defaults); }

const result = ref(null);
const loading = ref(false);
const error = ref('');
const selected = ref(null);
let request = 0;
let debounce = null;

async function load() {
  const id = ++request;
  loading.value = true;
  error.value = '';
  try {
    const res = await listEvents({
      ...filters,
      exclude: can('security.view') ? [] : ['security'],
      sort: sort.value.key, dir: sort.value.dir, page: page.value, pageSize,
    });
    if (id === request) result.value = res;
  } catch (e) {
    if (id === request) error.value = e.message;
  } finally {
    if (id === request) loading.value = false;
  }
}
onMounted(load);

// Any filter or sort change goes back to page 1 (one load either way); typing is debounced
function firstPage() {
  if (page.value !== 1) page.value = 1;   // the page watcher loads
  else load();
}
watch(() => ({ ...filters }), (now, before) => {
  clearTimeout(debounce);
  debounce = setTimeout(firstPage, now.q !== before.q ? 250 : 0);
});
watch(sort, firstPage);
watch(page, load);
</script>
