<template>
  <header class="wm-glass-bar sticky top-0 z-40 flex h-14 items-center gap-2 px-3 sm:gap-3 sm:px-4 lg:px-6">
    <button
      type="button"
      class="wm-icon-btn !w-10 !h-10 !text-[19px] text-[#475569]"
      :aria-label="sidebarLabel"
      :aria-expanded="sidebarExpanded"
      @click="$emit('toggle-sidebar')"
    ><i class="bi bi-layout-sidebar" aria-hidden="true"></i></button>

    <!-- Breadcrumbs (current page only on small screens) -->
    <nav aria-label="Breadcrumb" class="min-w-0 shrink lg:shrink-0">
      <ol class="flex items-center gap-1.5 text-[13px]">
        <li class="hidden md:flex items-center gap-1.5 text-[#64748B]">
          <router-link to="/admin" class="hover:text-ink">Admin</router-link>
          <i class="bi bi-chevron-right text-[10px] text-[#CBD5E1]" aria-hidden="true"></i>
        </li>
        <li v-if="crumbs.group" class="hidden md:flex items-center gap-1.5 text-[#64748B]">
          <span>{{ crumbs.group }}</span>
          <i class="bi bi-chevron-right text-[10px] text-[#CBD5E1]" aria-hidden="true"></i>
        </li>
        <li class="truncate font-semibold text-ink" aria-current="page">{{ crumbs.page }}</li>
      </ol>
    </nav>

    <!-- Global search: inline on desktop, an overlay on small screens -->
    <div class="ml-auto hidden md:block min-w-0 flex-1 max-w-[420px] lg:mx-auto lg:ml-6">
      <AdminGlobalSearch />
    </div>
    <button type="button" class="wm-icon-btn ml-auto !w-10 !h-10 !text-[17px] text-[#475569] md:hidden" aria-label="Search" @click="mobileSearch = true">
      <i class="bi bi-search" aria-hidden="true"></i>
    </button>

    <div class="flex shrink-0 items-center gap-1 sm:gap-1.5 md:ml-auto lg:ml-0">
      <!-- Monitoring isn't wired up: say so instead of showing a green light -->
      <span
        class="hidden xl:inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg wm-surface text-[12px] text-[#64748B]"
        title="No monitoring source is connected yet, so system status is not shown."
      >
        <span class="h-2 w-2 rounded-full border border-[#94A3B8]" aria-hidden="true"></span>Status: not connected
      </span>

      <!-- Quick create -->
      <div v-if="createItems.length" :ref="el => (createMenu.root = el)" class="relative">
        <button
          type="button"
          class="wm-btn wm-btn-primary !min-h-9 !h-9 !px-3 !rounded-lg !text-[13px]"
          aria-haspopup="menu"
          :aria-expanded="createMenu.open"
          @click="createMenu.toggle()"
        ><i class="bi bi-plus-lg" aria-hidden="true"></i><span class="hidden sm:inline">Create</span><span class="sm:hidden sr-only">Create</span></button>
        <div v-if="createMenu.open" role="menu" class="absolute right-0 top-[calc(100%+6px)] z-50 w-64 rounded-xl wm-glass-pop p-1.5">
          <router-link
            v-for="c in createItems" :key="c.label"
            :to="{ path: c.path, query: { create: '1' } }"
            role="menuitem"
            class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-ink hover:bg-[#F1F5F9]"
            @click="createMenu.close()"
          >
            <i :class="['bi', c.icon, 'text-[15px] text-[#64748B]']" aria-hidden="true"></i>
            <span class="flex-1">{{ c.label }}</span>
          </router-link>
        </div>
      </div>

      <!-- Alerts -->
      <div :ref="el => (alertMenu.root = el)" class="relative">
        <button
          type="button"
          class="wm-icon-btn !w-10 !h-10 !text-[18px] text-[#475569]"
          aria-haspopup="true"
          :aria-expanded="alertMenu.open"
          :aria-label="urgentCount ? `Alerts, ${urgentCount} need attention` : 'Alerts'"
          @click="alertMenu.toggle()"
        >
          <i class="bi bi-bell" aria-hidden="true"></i>
          <span v-if="urgentCount" class="wm-count-badge !top-1 !right-0.5" aria-hidden="true">{{ urgentCount }}</span>
        </button>
        <div v-if="alertMenu.open" class="absolute right-0 top-[calc(100%+6px)] z-50 w-[min(340px,calc(100vw-24px))] rounded-xl wm-glass-pop p-1.5">
          <p class="flex items-center justify-between px-2.5 pt-1.5 pb-1 text-[12px] text-[#64748B]">
            <span class="font-semibold text-ink">Needs attention</span>
          </p>
          <p v-if="alertsError" class="px-2.5 py-2 text-[13px] text-error">{{ alertsError }}</p>
          <p v-else-if="!alerts.length" class="px-2.5 py-2 text-[13px] text-[#64748B]">Nothing needs attention right now.</p>
          <ul v-else>
            <li v-for="a in alerts" :key="a.id">
              <router-link :to="a.to" class="flex items-start gap-2.5 rounded-lg px-2.5 py-2 hover:bg-[#F8FAFC]" @click="alertMenu.close()">
                <i :class="['bi mt-0.5 text-[15px]', a.severity === 'critical' ? 'bi-exclamation-octagon text-[#B91C1C]' : a.severity === 'warning' ? 'bi-exclamation-triangle text-[#B45309]' : 'bi-info-circle text-[#64748B]']" aria-hidden="true"></i>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] text-ink">{{ a.title }}</span>
                  <span class="block text-[12px] text-[#64748B]">{{ a.detail }}</span>
                </span>
                <span class="text-[13px] font-semibold text-ink tabular-nums">{{ a.count }}</span>
              </router-link>
            </li>
          </ul>
        </div>
      </div>

      <!-- Admin menu -->
      <div :ref="el => (userMenu.root = el)" class="relative">
        <button
          type="button"
          class="flex h-10 items-center gap-2 rounded-lg pl-1 pr-1 sm:pr-2 hover:bg-[#F1F5F9]"
          aria-haspopup="true"
          :aria-expanded="userMenu.open"
          aria-label="Admin account menu"
          @click="userMenu.toggle()"
        >
          <UserAvatar :name="userName" :src="auth.user?.avatar_url || ''" :size="32" />
          <i class="bi bi-chevron-down hidden sm:inline text-[11px] text-[#94A3B8]" aria-hidden="true"></i>
        </button>
        <div v-if="userMenu.open" class="absolute right-0 top-[calc(100%+6px)] z-50 w-[min(300px,calc(100vw-24px))] rounded-xl wm-glass-pop p-3">
          <p class="text-[13.5px] font-semibold text-ink">{{ userName }}</p>
          <p class="text-[12px] text-[#64748B] truncate">{{ auth.user?.email }}</p>
          <p class="mt-2 text-[12px] text-[#475569]"><span class="font-medium">Role:</span> {{ role.label }}</p>
          <p class="mt-1.5 text-[11.5px] leading-relaxed text-[#64748B]">
            Menus show what your role allows. The server checks your permissions on every request too.
          </p>

          <div class="mt-3 flex flex-col gap-1 border-t border-[#F1F5F9] pt-2">
            <router-link to="/" class="flex items-center gap-2 rounded-lg px-2 py-2 text-[13px] text-ink hover:bg-[#F8FAFC]" @click="userMenu.close()">
              <i class="bi bi-box-arrow-up-right text-[#64748B]" aria-hidden="true"></i>Open the WorkMate site
            </router-link>
            <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-2 text-left text-[13px] text-ink hover:bg-[#F8FAFC]" @click="doSignOut">
              <i class="bi bi-box-arrow-right text-[#64748B]" aria-hidden="true"></i>Sign out
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Small-screen search overlay -->
    <div v-if="mobileSearch" class="wm-glass-bar fixed inset-x-0 top-0 z-[110] flex items-center gap-2 p-3 md:hidden">
      <AdminGlobalSearch ref="mobileSearchRef" class="flex-1" />
      <button type="button" class="wm-btn wm-btn-ghost !min-h-10 !px-2" @click="mobileSearch = false">Close</button>
    </div>
  </header>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminGlobalSearch from './AdminGlobalSearch.vue';
import { usePopover } from './usePopover.js';
import { currentRole, can } from '../permissions.js';
import { auth, signOut } from '@/stores/auth.js';
import UserAvatar from '@/components/UserAvatar.vue';
import { getPriorities } from '../data/adminApi.js';

defineProps({
  sidebarLabel:    { type: String, default: 'Toggle sidebar' },
  sidebarExpanded: { type: Boolean, default: true },
});
defineEmits(['toggle-sidebar']);

const route = useRoute();
const role = computed(() => currentRole());
const userName = computed(() => auth.user?.name || 'Administrator');

const router = useRouter();
async function doSignOut() {
  userMenu.close();
  try { await signOut(); } finally { router.push('/sign-in'); }
}
const crumbs = computed(() => ({ group: route.meta.groupLabel || '', page: route.meta.pageLabel || 'Admin' }));

// Popovers kept in reactive wrappers so template refs bind to their roots
const createMenu = reactive(usePopover());
const alertMenu = reactive(usePopover());
const userMenu = reactive(usePopover());

// Each opens the module with its create form
const createItems = computed(() => [
  { label: 'Announcement', icon: 'bi-megaphone', permission: 'content.view', path: '/admin/content/announcements' },
  { label: 'Help article', icon: 'bi-question-circle', permission: 'content.view', path: '/admin/content/help' },
  { label: 'Campaign', icon: 'bi-send', permission: 'comms.view', path: '/admin/comms/campaigns' },
  { label: 'Saved report', icon: 'bi-file-earmark-bar-graph', permission: 'analytics.view', path: '/admin/analytics/reports' },
  { label: 'Service category', icon: 'bi-grid', permission: 'admin.manage', path: '/admin/marketplace/categories' },
  { label: 'Admin user', icon: 'bi-person-plus', permission: 'admin.manage', path: '/admin/settings/users' },
].filter(c => can(c.permission)));

// Alerts, limited to what this role may act on
const allAlerts = ref([]);
const alertsError = ref('');
onMounted(async () => {
  try { allAlerts.value = (await getPriorities()).priorities; } catch (e) { alertsError.value = e.message; }
});
const alerts = computed(() => allAlerts.value.filter(a => can(a.permission)));
const urgentCount = computed(() => alerts.value.filter(a => a.severity !== 'info').length);

const mobileSearch = ref(false);
const mobileSearchRef = ref(null);
watch(mobileSearch, async (v) => { if (v) { await nextTick(); mobileSearchRef.value?.focus(); } });
watch(() => route.fullPath, () => {
  mobileSearch.value = false;
  createMenu.close(); alertMenu.close(); userMenu.close();
});
</script>
