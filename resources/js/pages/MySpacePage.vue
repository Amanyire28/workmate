<template>
  <div class="wm-container wm-container-sm wm-page">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">My Space</h1>
    <p class="wm-meta mt-1">Your posts and videos, and how they’re doing. Only you can see this page.</p>

    <!-- Where other people see you -->
    <div v-if="user" class="mt-4 flex flex-col gap-2 sm:flex-row">
      <router-link :to="`/community/people/${user.id}`" class="wm-btn wm-btn-secondary !min-h-12 flex-1 sm:flex-none">
        <i class="bi bi-person-circle" aria-hidden="true"></i>View my public profile
      </router-link>
      <router-link v-if="user.provider_status === 'approved' && user.provider_profile_id" :to="`/provider/${user.provider_profile_id}`" class="wm-btn wm-btn-secondary !min-h-12 flex-1 sm:flex-none">
        <i class="bi bi-person-vcard" aria-hidden="true"></i>My provider profile
      </router-link>
    </div>

    <!-- Tabs: the address says which one (?tab=posts|videos|performance) -->
    <div class="mt-5">
      <div class="wm-segmented grid grid-cols-3" role="tablist" aria-label="My Space">
        <button
          v-for="t in tabs"
          :key="t.id"
          :id="`space-tab-${t.id}`"
          type="button"
          role="tab"
          :aria-selected="active === t.id"
          :aria-controls="`space-panel-${t.id}`"
          :tabindex="active === t.id ? 0 : -1"
          class="wm-hit wm-segment !min-h-11 !px-2 sm:!px-4"
          @click="select(t.id)"
          @keydown.right.prevent="move(1)"
          @keydown.left.prevent="move(-1)"
          @keydown.home.prevent="select(tabs[0].id, true)"
          @keydown.end.prevent="select(tabs[tabs.length - 1].id, true)"
        >{{ t.label }}</button>
      </div>
    </div>

    <!-- A tab is built the first time it is opened, then kept (so going back doesn't reload it) -->
    <div class="mt-4">
      <div v-for="t in tabs" v-show="active === t.id" :id="`space-panel-${t.id}`" :key="t.id" role="tabpanel" :aria-labelledby="`space-tab-${t.id}`" tabindex="0" class="focus:outline-none">
        <template v-if="visited.has(t.id)">
          <MyContentList v-if="t.id === 'posts'" type="all" />
          <MyContentList v-else-if="t.id === 'videos'" type="video" />
          <MyPerformance v-else />
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import MyContentList from '@/components/space/MyContentList.vue';
import MyPerformance from '@/components/space/MyPerformance.vue';
import { auth } from '@/stores/auth.js';

const route = useRoute();
const router = useRouter();
const user = computed(() => auth.user);

const tabs = [
  { id: 'posts', label: 'Posts' },
  { id: 'videos', label: 'Videos' },
  { id: 'performance', label: 'Performance' },
];

// An unknown or missing ?tab= is Posts
const active = computed(() => (tabs.some(t => t.id === route.query.tab) ? route.query.tab : 'posts'));

const visited = reactive(new Set());
watch(active, (id) => { visited.add(id); }, { immediate: true });

function select(id, focus = false) {
  if (id !== active.value) router.replace({ query: { ...route.query, tab: id } });
  if (focus) nextTick(() => document.getElementById(`space-tab-${id}`)?.focus());
}

function move(step) {
  const i = tabs.findIndex(t => t.id === active.value);
  select(tabs[(i + step + tabs.length) % tabs.length].id, true);
}
</script>
