<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader :title="item.label" :description="def.description || item.summary" demo>
      <template #actions>
        <DateRangeControl v-model="days" v-model:compare="compare" :label="`${item.label} date range`" />
      </template>
    </AdminPageHeader>

    <p v-if="def.notice" class="wm-notice wm-notice-info">
      <i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ def.notice }}</span>
    </p>

    <div v-if="error && !view" class="rounded-xl wm-surface">
      <StateBlock kind="error" :title="`${item.label} couldn’t load`" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>

    <template v-else>
      <section aria-label="Key figures">
        <div v-if="!view" class="grid grid-cols-2 lg:grid-cols-4 gap-3" aria-busy="true">
          <div v-for="n in 4" :key="n" class="wm-skeleton h-[104px] !rounded-xl"></div>
        </div>
        <div v-else class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-4 gap-3 transition-opacity" :class="{ 'opacity-60': loading }">
          <MetricCard v-for="k in view.kpis" :key="k.label" v-bind="k" />
        </div>
      </section>

      <ChartGrid
        :charts="view ? view.charts : placeholders"
        :state="view ? 'ready' : 'loading'"
        :refreshing="loading"
      />

      <section v-if="view?.table" class="rounded-xl wm-surface" :aria-label="view.table.title">
        <h2 class="px-4 pt-4 text-[15px] font-semibold text-ink">{{ view.table.title }}</h2>
        <p v-if="view.table.summary" class="px-4 text-[13px] text-[#64748B]">{{ view.table.summary }}</p>
        <div class="mt-3 overflow-x-auto">
          <table class="w-full text-[13px]">
            <caption class="sr-only">{{ view.table.title }}</caption>
            <thead class="bg-[#F8FAFC] text-[#475569]">
              <tr>
                <th v-for="(c, i) in view.table.columns" :key="c.key" scope="col" class="px-4 py-2 font-semibold whitespace-nowrap" :class="i ? 'text-right' : 'text-left'">{{ c.label }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, ri) in view.table.rows" :key="ri" class="border-t border-[#F1F5F9]">
                <td v-for="(c, i) in view.table.columns" :key="c.key" class="px-4 py-2" :class="i ? 'text-right tabular-nums' : 'text-ink'">
                  <RecordValue :value="r[c.key]" :format="c.format || 'text'" :empty="c.empty || '—'" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import AdminPageHeader from './AdminPageHeader.vue';
import DateRangeControl from './DateRangeControl.vue';
import MetricCard from './MetricCard.vue';
import ChartGrid from './ChartGrid.vue';
import StateBlock from './StateBlock.vue';
import RecordValue from './RecordValue.vue';
import { getSeries, getUsage, fetchDataset, relations } from '../data/adminApi.js';
import { can } from '../permissions.js';

const props = defineProps({
  def:  { type: Object, required: true },
  item: { type: Object, required: true },
});

const days = ref(props.def.defaultDays || 30);
const compare = ref(true);
const series = ref(null);
const sets = ref(null);
const loading = ref(false);
const error = ref('');
let request = 0;

async function load() {
  const id = ++request;
  loading.value = true;
  error.value = '';
  try {
    const [s, ...lists] = await Promise.all([
      (props.def.series === 'usage' ? getUsage : getSeries)({ days: days.value, compare: compare.value }),
      ...(props.def.datasets || []).map(ds => fetchDataset(ds)),
    ]);
    if (id !== request) return;
    series.value = s;
    sets.value = Object.fromEntries((props.def.datasets || []).map((ds, i) => [ds, lists[i].rows]));
  } catch (e) {
    if (id === request) error.value = e.message;
  } finally {
    if (id === request) loading.value = false;
  }
}
onMounted(load);
watch([days, compare], load);

const view = computed(() => {
  if (!series.value) return null;
  const v = props.def.analytics(series.value, { sets: sets.value, days: days.value, rel: relations, can });
  return { ...v, kpis: v.kpis.filter(k => !k.permission || can(k.permission)).map(({ permission, ...k }) => k) };
});

const placeholders = computed(() => (props.def.placeholderTitles || ['', '']).map((title, i) => ({ id: `ph${i}`, title: title || 'Loading…', summary: '' })));
</script>
