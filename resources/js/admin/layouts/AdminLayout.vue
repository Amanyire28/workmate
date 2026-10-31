<template>
  <!-- Blue colour field behind the admin: what the liquid glass refracts -->
  <div class="wm-admin-field relative min-h-dvh text-ink">
    <a href="#admin-main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[200] wm-btn wm-btn-primary">Skip to content</a>

    <AdminSidebar
      :collapsed="collapsed"
      :mobile-open="mobileOpen"
      @close-mobile="mobileOpen = false"
      @navigate="mobileOpen = false"
      @expand="collapsed = false"
    />

    <div class="transition-[padding] duration-200" :class="collapsed ? 'lg:pl-[68px]' : 'lg:pl-64'">
      <AdminTopBar
        :sidebar-label="sidebarLabel"
        :sidebar-expanded="isDesktop ? !collapsed : mobileOpen"
        @toggle-sidebar="toggleSidebar"
      />

      <main id="admin-main" tabindex="-1" class="mx-auto w-full max-w-[1600px] px-4 py-5 sm:px-6 lg:px-8 lg:py-6 focus:outline-none">
        <!-- Shows only what the role allows; the server enforces the same rule on every request -->
        <div v-if="!allowed" class="rounded-xl wm-surface">
          <StateBlock
            kind="restricted"
            title="Your role can’t open this page"
            :message="`${role.label} doesn’t include access to ${route.meta.pageLabel}. Ask a Super Administrator if you need it.`"
          >
            <router-link to="/admin" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg">Go to the dashboard</router-link>
          </StateBlock>
        </div>
        <router-view v-else :key="route.path" />
      </main>
    </div>

    <ToastHost />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import AdminSidebar from '../components/AdminSidebar.vue';
import AdminTopBar from '../components/AdminTopBar.vue';
import StateBlock from '../components/StateBlock.vue';
import ToastHost from '../components/ToastHost.vue';
import { can, currentRole } from '../permissions.js';

const route = useRoute();
const role = computed(() => currentRole());
const allowed = computed(() => can(route.meta.permission));

// Desktop: collapse to an icon rail (remembered). Smaller screens: off-canvas drawer.
const KEY = 'workmate.admin.sidebarCollapsed';
const collapsed = ref((() => { try { return localStorage.getItem(KEY) === '1'; } catch { return false; } })());
watch(collapsed, (v) => { try { localStorage.setItem(KEY, v ? '1' : '0'); } catch { /* storage unavailable */ } });
const mobileOpen = ref(false);

const isDesktop = ref(true);
let mq = null;
const syncMq = () => { isDesktop.value = mq.matches; if (mq.matches) mobileOpen.value = false; };
onMounted(() => { mq = window.matchMedia('(min-width: 1024px)'); syncMq(); mq.addEventListener('change', syncMq); });
onBeforeUnmount(() => mq?.removeEventListener('change', syncMq));

const sidebarLabel = computed(() => (isDesktop.value
  ? (collapsed.value ? 'Expand sidebar' : 'Collapse sidebar')
  : (mobileOpen.value ? 'Close menu' : 'Open menu')));

function toggleSidebar() {
  if (isDesktop.value) collapsed.value = !collapsed.value;
  else mobileOpen.value = !mobileOpen.value;
}

// Escape closes the mobile drawer
function onKey(e) { if (e.key === 'Escape' && mobileOpen.value) mobileOpen.value = false; }
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>
