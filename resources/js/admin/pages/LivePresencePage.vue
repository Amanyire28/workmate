<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Live Presence" description="Who has WorkMate open now, recently, or not at all, for customers and providers separately." demo>
      <template #actions>
        <div class="inline-flex rounded-lg wm-surface p-0.5" role="group" aria-label="Show">
          <button v-for="t in [['customer', 'Customers'], ['provider', 'Providers']]" :key="t[0]" type="button"
            class="h-9 rounded-md px-3 text-[13px] font-medium" :class="view === t[0] ? 'bg-primary text-white' : 'text-[#475569] hover:bg-[#F1F5F9]'"
            :aria-pressed="view === t[0]" @click="view = t[0]">{{ t[1] }}</button>
        </div>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" @click="load"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Recheck</button>
      </template>
    </AdminPageHeader>

    <p class="wm-notice wm-notice-warning">
      <i class="bi bi-broadcast" aria-hidden="true"></i>
      <span>No presence source (heartbeat or WebSocket) is connected, so these are sample last-activity times.
        Online means active in the last {{ data?.settings.onlineMin ?? 5 }} minutes; recently active, the last {{ data?.settings.recentMin ?? 30 }}.
        <router-link v-if="can('admin.manage')" to="/admin/settings/platform" class="font-semibold underline">Change the timeouts</router-link></span>
    </p>

    <div v-if="error" class="rounded-xl wm-surface">
      <StateBlock kind="error" title="Presence couldn’t load" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button></StateBlock>
    </div>
    <div v-else-if="!data" class="wm-skeleton h-[360px] !rounded-xl" aria-busy="true"></div>

    <template v-else>
      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" :aria-label="`${view === 'customer' ? 'Customer' : 'Provider'} presence`">
        <MetricCard label="Online now" :value="formatNumber(counts.online)" :context="`Active in the last ${data.settings.onlineMin} min`" />
        <MetricCard label="Recently active" :value="formatNumber(counts.recently_active)" :context="`Within ${data.settings.recentMin} min`" />
        <MetricCard label="Offline" :value="formatNumber(counts.offline)" />
        <MetricCard label="Unknown" :value="formatNumber(counts.unknown)" hint="No activity recorded since tracking began." />
      </section>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section aria-labelledby="active-heading" class="flex min-w-0 flex-col gap-2">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="active-heading" class="text-[15px] font-semibold text-ink">Active now and recently</h2>
            <router-link to="/admin/analytics/user-activity" class="text-[12.5px] font-medium text-primary hover:underline">Search everyone in User Activity</router-link>
          </div>
          <DataTable :caption="`${view === 'customer' ? 'Customers' : 'Providers'} active now`" :columns="columns" :rows="activeRows">
            <template #cell="{ row, column }">
              <template v-if="column.key === 'name'">
                <span class="block font-medium text-ink">{{ row.name }}</span><span class="block text-[12px] text-[#64748B]">{{ row.id }}</span>
              </template>
              <template v-else-if="column.key === 'availability'">
                <AdminStatusBadge :status="row.availability" />
                <span v-if="row.acceptsWhileOffline" class="block text-[11.5px] text-[#64748B]">Accepts requests while offline</span>
              </template>
              <RecordValue v-else :value="row[column.key]" :format="column.format || 'text'" />
            </template>
            <template #empty><StateBlock kind="empty" compact title="Nobody is active right now" /></template>
          </DataTable>
          <p class="text-[12px] text-[#64748B]">
            Showing {{ activeRows.length }} of {{ formatNumber(counts.online + counts.recently_active) }}, most recent first. Areas are registered areas, never live locations.
            <template v-if="view === 'provider'"> Presence (app open) and availability (taking work) are separate.</template>
          </p>
        </section>

        <aside class="flex flex-col gap-3">
          <ChartGrid :columns="1" :charts="[areaChart]" />

          <section class="rounded-xl wm-surface p-4" aria-labelledby="feed-heading">
            <div class="flex items-center justify-between gap-2">
              <h2 id="feed-heading" class="text-[15px] font-semibold text-ink">Activity feed</h2>
              <button type="button" class="wm-btn wm-btn-secondary !min-h-9 !rounded-lg !px-3 !text-[13px]" :aria-pressed="playing" @click="toggleSim">
                <i :class="['bi', playing ? 'bi-pause-fill' : 'bi-play-fill']" aria-hidden="true"></i>{{ playing ? 'Pause' : 'Play' }} simulation
              </button>
            </div>
            <p class="mt-1 text-[12px] text-[#92400E]">Simulated events for demonstration. No live data source is connected.</p>
            <ol class="mt-3 flex flex-col gap-2.5" aria-live="off">
              <li v-if="!feed.length" class="text-[13px] text-[#64748B]">Press Play to show sample events.</li>
              <li v-for="e in feed" :key="e.id" class="flex gap-2.5 text-[13px]">
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#EFF6FF] text-primary" aria-hidden="true"><i :class="['bi', e.icon]"></i></span>
                <span class="min-w-0">
                  <span class="block text-ink">{{ e.label }}</span>
                  <span class="block text-[12px] text-[#64748B]">{{ e.area }} · {{ e.platform }} · {{ formatRelative(e.at) }} · <span class="text-[#92400E]">simulated</span></span>
                </span>
              </li>
            </ol>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import AdminStatusBadge from '../components/AdminStatusBadge.vue';
import MetricCard from '../components/MetricCard.vue';
import DataTable from '../components/DataTable.vue';
import ChartGrid from '../components/ChartGrid.vue';
import StateBlock from '../components/StateBlock.vue';
import RecordValue from '../components/RecordValue.vue';
import { ranking } from '../components/charts/builders.js';
import { getPresence, simulatedEvent } from '../data/adminApi.js';
import { can } from '../permissions.js';
import { formatNumber, formatRelative } from '../utils/format.js';

const view = ref('customer');
const data = ref(null);
const error = ref('');

async function load() {
  error.value = '';
  try { data.value = await getPresence(); } catch (e) { error.value = e.message; }
}
onMounted(load);

const counts = computed(() => data.value.counts[view.value]);
const columns = computed(() => [
  { key: 'name', label: view.value === 'customer' ? 'Customer' : 'Provider' },
  { key: 'presence', label: 'Presence', format: 'status' },
  { key: 'lastSeenAt', label: 'Last seen', format: 'relative' },
  { key: 'platform', label: 'Device', hideBelow: 'lg' },
  { key: 'area', label: 'Area' },
  ...(view.value === 'provider' ? [{ key: 'availability', label: 'Availability' }] : []),
]);
const activeRows = computed(() => data.value.people
  .filter(p => p.type === view.value && (p.presence === 'online' || p.presence === 'recently_active'))
  .sort((a, b) => b.lastSeenAt.localeCompare(a.lastSeenAt))
  .slice(0, 50));

const areaChart = computed(() => {
  const m = new Map();
  for (const p of data.value.people) if (p.type === view.value && (p.presence === 'online' || p.presence === 'recently_active')) m.set(p.area, (m.get(p.area) || 0) + 1);
  const rows = [...m.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 8);
  return ranking({ id: 'areas', title: 'Active by area', summary: 'Online or recently active, by registered area.', rows, columnLabel: 'People' });
});

/* Simulated feed: off until asked for, always labelled */
const feed = ref([]);
const playing = ref(false);
let timer = null;
let seq = 0;
function tick() {
  feed.value = [simulatedEvent(++seq), ...feed.value].slice(0, 12);
}
function toggleSim() {
  playing.value = !playing.value;
  if (playing.value) { tick(); timer = setInterval(tick, 3500); } else clearInterval(timer);
}
onBeforeUnmount(() => clearInterval(timer));
</script>
