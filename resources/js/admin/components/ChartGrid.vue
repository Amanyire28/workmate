<template>
  <div class="grid grid-cols-1 gap-3" :class="{ 'lg:grid-cols-2': columns === 2 }">
    <ChartCard
      v-for="c in charts"
      :key="c.id"
      :title="c.title"
      :summary="c.summary"
      :legend="c.legend || []"
      :table="c.table"
      :state="state === 'ready' && c.empty ? 'empty' : state"
      :refreshing="refreshing"
      :error-message="errorMessage"
      :min-height="c.minHeight || 220"
      :class="{ 'lg:col-span-2': c.wide && columns === 2 }"
    >
      <LineChart v-if="c.kind === 'line'" v-bind="c.props" />
      <BarChart v-else-if="c.kind === 'bar'" v-bind="c.props" />
      <RankBars v-else-if="c.kind === 'rank'" v-bind="c.props" />
      <!-- Two measures on different scales: two panels, never a dual axis -->
      <div v-else-if="c.kind === 'panels'" class="flex flex-col gap-4">
        <div v-for="panel in c.panels" :key="panel.title">
          <p class="mb-1 text-[12.5px] font-medium text-[#475569]">{{ panel.title }}</p>
          <LineChart v-bind="panel.props" />
        </div>
      </div>
    </ChartCard>
  </div>
</template>

<script setup>
import ChartCard from './ChartCard.vue';
import LineChart from './charts/LineChart.vue';
import BarChart from './charts/BarChart.vue';
import RankBars from './charts/RankBars.vue';

defineProps({
  charts:       { type: Array, required: true },   // configs from charts/builders.js
  state:        { type: String, default: 'ready' },
  refreshing:   { type: Boolean, default: false },
  errorMessage: { type: String, default: '' },
  columns:      { type: Number, default: 2 },
});
</script>
