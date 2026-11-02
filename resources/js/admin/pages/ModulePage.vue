<template>
  <component :is="workspace" v-if="def && item" :def="def" :item="item" />
  <div v-else class="rounded-xl wm-surface">
    <StateBlock kind="error" title="This page has no definition" message="Add it to admin/modules/." />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import RecordsWorkspace from '../components/RecordsWorkspace.vue';
import AnalyticsWorkspace from '../components/AnalyticsWorkspace.vue';
import SettingsWorkspace from '../components/SettingsWorkspace.vue';
import RolesMatrix from '../components/RolesMatrix.vue';
import StateBlock from '../components/StateBlock.vue';
import { findNavItem } from '../navigation.js';
import { modules } from '../modules/index.js';

/** One route component for every module: picks the workspace for the module's type */
const props = defineProps({
  // Set when shown as a tab of another screen; otherwise the route's module
  moduleId: { type: String, default: '' },
});
const route = useRoute();
const id = computed(() => props.moduleId || route.meta.navId);
const item = computed(() => findNavItem(id.value));
const def = computed(() => modules[id.value]);
const workspace = computed(() => ({
  records: RecordsWorkspace, analytics: AnalyticsWorkspace, settings: SettingsWorkspace, roles: RolesMatrix,
})[def.value?.type] || RecordsWorkspace);
</script>
