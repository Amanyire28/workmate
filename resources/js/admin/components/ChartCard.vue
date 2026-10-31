<template>
  <figure class="flex flex-col rounded-xl wm-glass-card p-4" :aria-labelledby="titleId" :aria-describedby="summaryId">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 :id="titleId" class="text-[15px] font-semibold text-ink">{{ title }}</h3>
        <!-- Plain-language summary: the accessible description and the takeaway -->
        <p :id="summaryId" class="mt-0.5 text-[13px] text-[#64748B]">{{ state === 'ready' ? summary : stateSummary }}</p>
      </div>
      <slot name="actions">
        <router-link
          v-if="to"
          :to="to"
          class="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-[12.5px] font-medium text-primary transition-colors hover:bg-[#EAF3FF] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >{{ toLabel }} <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="sr-only">: {{ title }}</span></router-link>
      </slot>
    </div>

    <ul v-if="legend.length > 1 && state === 'ready'" class="mt-3 flex flex-wrap gap-x-4 gap-y-1" aria-label="Legend">
      <li v-for="l in legend" :key="l.label" class="inline-flex items-center gap-1.5 text-[12.5px] text-[#475569]">
        <span class="h-2.5 w-2.5 rounded-[3px]" :style="{ background: l.color }" aria-hidden="true"></span>{{ l.label }}
      </li>
    </ul>

    <div class="mt-3 flex-1" :style="{ minHeight: `${minHeight}px` }">
      <div v-if="state === 'loading'" class="h-full flex flex-col justify-end gap-2" :style="{ height: `${minHeight}px` }" aria-hidden="true">
        <div class="wm-skeleton h-full !rounded-md"></div>
      </div>
      <StateBlock v-else-if="state === 'error'" kind="error" compact title="Couldn’t load this chart" :message="errorMessage" />
      <StateBlock v-else-if="state === 'empty'" kind="empty" compact :title="emptyTitle" :message="emptyMessage" />
      <!-- Refetch: keep the previous render, dimmed, instead of flashing a skeleton -->
      <div v-else class="transition-opacity" :class="{ 'opacity-50': refreshing }" :aria-busy="refreshing ? 'true' : undefined">
        <slot />
      </div>
    </div>

    <div v-if="table && state === 'ready'" class="mt-3 border-t border-[#F1F5F9] pt-2">
      <button
        type="button"
        class="inline-flex items-center gap-1 min-h-9 text-[12.5px] font-medium text-primary hover:underline"
        :aria-expanded="showTable"
        :aria-controls="tableId"
        @click="showTable = !showTable"
      >
        <i :class="['bi', showTable ? 'bi-chevron-up' : 'bi-table']" aria-hidden="true"></i>
        {{ showTable ? 'Hide data table' : 'Show data table' }}
      </button>
      <div v-if="showTable" :id="tableId" class="mt-2 max-h-64 overflow-auto rounded-md border border-[#E2E8F0]">
        <table class="w-full text-[12.5px]">
          <caption class="sr-only">{{ title }}</caption>
          <thead class="sticky top-0 bg-[#F8FAFC]">
            <tr>
              <th v-for="(c, i) in table.columns" :key="c.key" scope="col" class="px-3 py-2 font-semibold text-[#475569]" :class="i ? 'text-right' : 'text-left'">{{ c.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, r) in table.rows" :key="r" class="border-t border-[#F1F5F9]">
              <component
                :is="i ? 'td' : 'th'"
                v-for="(c, i) in table.columns"
                :key="c.key"
                :scope="i ? undefined : 'row'"
                class="px-3 py-1.5 tabular-nums"
                :class="i ? 'text-right text-ink' : 'text-left font-normal text-[#475569]'"
              >{{ c.format ? c.format(row[c.key]) : row[c.key] }}</component>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </figure>
</template>

<script setup>
import { ref, computed, useId } from 'vue';
import StateBlock from './StateBlock.vue';

const props = defineProps({
  title:        { type: String, required: true },
  to:           { type: [String, Object], default: '' },   // module this chart summarises
  toLabel:      { type: String, default: 'Open' },
  summary:      { type: String, default: '' },
  legend:       { type: Array, default: () => [] },     // [{ label, color }]
  table:        { type: Object, default: null },        // { columns: [{ key, label, format? }], rows }
  state:        { type: String, default: 'ready' },     // loading | ready | empty | error
  refreshing:   { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  emptyTitle:   { type: String, default: 'No data for this period' },
  emptyMessage: { type: String, default: 'Nothing was recorded in the selected date range.' },
  minHeight:    { type: Number, default: 220 },
});

const titleId = useId();
const summaryId = useId();
const tableId = useId();
const showTable = ref(false);

const stateSummary = computed(() => ({
  loading: 'Loading…', error: 'Data unavailable.', empty: props.emptyTitle,
})[props.state] || '');
</script>
