<template>
  <div class="flex flex-col gap-4">
    <div v-if="tabs.length > 1" class="wm-segmented inline-grid self-start" :style="{ gridTemplateColumns: `repeat(${tabs.length}, minmax(0, auto))` }" role="tablist" :aria-label="item?.label">
      <button
        v-for="t in tabs"
        :key="t.id"
        :id="`admin-tab-${t.id}`"
        type="button"
        role="tab"
        :aria-selected="active?.id === t.id"
        :aria-controls="`admin-tabpanel-${t.id}`"
        :tabindex="active?.id === t.id ? 0 : -1"
        class="wm-segment px-4"
        @click="select(t.id)"
      >{{ t.label }}</button>
    </div>

    <div v-if="active" :id="`admin-tabpanel-${active.id}`" role="tabpanel" :aria-labelledby="tabs.length > 1 ? `admin-tab-${active.id}` : undefined">
      <component :is="pageFor(active.id)" v-if="pageFor(active.id)" :key="active.id" />
      <ModulePage v-else :key="active.id" :module-id="active.id" />
    </div>
  </div>
</template>

<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModulePage from './ModulePage.vue';
import { findNavItem } from '../navigation.js';
import { can } from '../permissions.js';

/**
 * A screen made of several others, one per tab (navigation item `tabs`).
 * Only the tabs this role may open are shown; ?tab= picks one.
 */
const PAGES = {
  activity: defineAsyncComponent(() => import('./ActivityCentre.vue')),
};

// The first tab's own name, where the screen's name now covers all tabs
const TAB_LABELS = { activity: 'Activity', 'location-overview': 'By place', platform: 'Platform' };

const route = useRoute();
const router = useRouter();

const item = computed(() => findNavItem(route.meta.navId));
const tabs = computed(() => (item.value?.tabs || [])
  .map(id => findNavItem(id))
  .filter(t => t && can(t.permission))
  .map(t => ({ id: t.id, label: t.id === item.value.id ? (TAB_LABELS[t.id] || t.label) : t.label })));
const active = computed(() => tabs.value.find(t => t.id === route.query.tab) || tabs.value[0] || null);

const pageFor = (id) => PAGES[id] || null;

function select(id) {
  router.replace({ query: id === tabs.value[0]?.id ? {} : { tab: id } });
}
</script>
