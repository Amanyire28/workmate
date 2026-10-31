<template>
  <!-- Phone/tablet: off-canvas drawer with a backdrop -->
  <div v-if="mobileOpen" class="fixed inset-0 z-[90] bg-[#0F172A]/30 lg:hidden" aria-hidden="true" @click="$emit('close-mobile')"></div>

  <aside
    class="wm-liquid fixed inset-y-0 left-0 z-[95] flex flex-col transition-[width,transform] duration-200"
    :class="[
      collapsed ? 'lg:w-[68px]' : 'lg:w-64',
      mobileOpen ? 'translate-x-0 w-72' : '-translate-x-full w-72 lg:translate-x-0',
    ]"
    aria-label="Admin"
  >
    <!-- Brand -->
    <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-white/60 px-4" :class="{ 'lg:justify-center lg:px-0': collapsed }">
      <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-[linear-gradient(160deg,#2B7BE6,#1257B3)] text-[15px] font-bold text-white shadow-[0_4px_12px_rgba(23,105,210,0.35),inset_0_1px_0_rgba(255,255,255,0.35)]" aria-hidden="true">W</span>
      <span class="min-w-0" :class="{ 'lg:hidden': collapsed }">
        <span class="block text-[15px] font-semibold leading-tight text-navy">WorkMate</span>
        <span class="block text-[11.5px] leading-tight text-nav-icon">Operations admin</span>
      </span>
      <button type="button" class="wm-icon-btn ml-auto !w-10 !h-10 lg:hidden" aria-label="Close menu" @click="$emit('close-mobile')">
        <i class="bi bi-x-lg text-base" aria-hidden="true"></i>
      </button>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto overscroll-contain px-2.5 py-3" aria-label="Admin sections">
      <ul class="flex flex-col gap-0.5">
        <li v-for="g in groups" :key="g.id">
          <button
            type="button"
            class="wm-nav-item flex w-full items-center gap-2.5 rounded-[10px] px-2.5 h-10 text-[13.5px] font-semibold"
            :class="[
              groupActive(g) && !(collapsed && isDesktop) ? '!text-navy' : '',
              collapsed ? 'lg:justify-center lg:px-0' : '',
              // Collapsed rail: the section icon stands in for its active page
              collapsed && isDesktop && groupActive(g) ? 'is-active' : '',
            ]"
            :aria-expanded="collapsed && isDesktop ? undefined : isOpen(g)"
            :aria-controls="`nav-${g.id}`"
            :title="collapsed ? g.label : undefined"
            @click="onGroupClick(g)"
          >
            <i :class="['bi', g.icon, 'text-[17px] leading-none']" aria-hidden="true"></i>
            <span class="flex-1 text-left truncate" :class="{ 'lg:sr-only': collapsed }">{{ g.label }}</span>
            <i
              :class="['bi bi-chevron-down text-[11px] opacity-70 transition-transform', isOpen(g) ? 'rotate-180' : '', collapsed ? 'lg:hidden' : '']"
              aria-hidden="true"
            ></i>
          </button>

          <ul v-show="isOpen(g)" :id="`nav-${g.id}`" class="mb-1.5 mt-0.5 flex flex-col gap-px" :class="{ 'lg:hidden': collapsed }">
            <li v-for="item in g.items" :key="item.id">
              <router-link
                :to="item.path"
                class="wm-nav-item relative flex items-center gap-2 rounded-[10px] pl-9 pr-2.5 h-9 text-[13px]"
                :class="{ 'is-active': isActive(item) }"
                :aria-current="isActive(item) ? 'page' : undefined"
                @click="$emit('navigate')"
              >
                <span class="flex-1 truncate">{{ item.label }}</span>
              </router-link>
            </li>
          </ul>
        </li>
      </ul>
    </nav>

    <!-- Identity -->
    <div class="shrink-0 border-t border-white/60 p-2.5">
      <div class="flex items-center gap-2.5 rounded-lg px-1.5 py-1.5" :class="{ 'lg:justify-center lg:px-0': collapsed }">
        <UserAvatar :name="auth.user?.name || 'Administrator'" :src="auth.user?.avatar_url || ''" :size="32" />
        <span class="min-w-0 flex-1" :class="{ 'lg:hidden': collapsed }">
          <span class="block truncate text-[13px] font-semibold text-navy">{{ auth.user?.name || 'Administrator' }}</span>
          <span class="block truncate text-[11.5px] text-nav-icon">{{ role.label }}</span>
        </span>
        <router-link
          to="/"
          class="wm-icon-btn !w-9 !h-9 !text-base text-nav-icon hover:!bg-white/50"
          :class="{ 'lg:hidden': collapsed }"
          title="Back to the WorkMate site"
          aria-label="Back to the WorkMate site"
        ><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></router-link>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { navGroups } from '../navigation.js';
import { can, currentRole } from '../permissions.js';
import { auth } from '@/stores/auth.js';
import UserAvatar from '@/components/UserAvatar.vue';

const props = defineProps({
  collapsed:  { type: Boolean, default: false },
  mobileOpen: { type: Boolean, default: false },
});
const emit = defineEmits(['close-mobile', 'navigate', 'expand']);

const route = useRoute();
const role = computed(() => currentRole());

// Only sections and pages the role may open
const groups = computed(() => navGroups
  .map(g => ({ ...g, items: g.items.filter(i => can(i.permission)) }))
  .filter(g => g.items.length));

const isActive = (item) => route.path === item.path || (item.path !== '/admin' && route.path.startsWith(`${item.path}/`));
const groupActive = (g) => g.items.some(isActive);

// Overview and the section holding the current page start open; the rest stay collapsed
const open = ref(new Set(['overview']));
watch(() => route.path, () => {
  const g = groups.value.find(groupActive);
  if (g) open.value = new Set([...open.value, g.id]);
}, { immediate: true });

const isOpen = (g) => open.value.has(g.id);

const isDesktop = ref(true);
let mq = null;
const syncMq = () => { isDesktop.value = mq.matches; };
onMounted(() => { mq = window.matchMedia('(min-width: 1024px)'); syncMq(); mq.addEventListener('change', syncMq); });
onBeforeUnmount(() => mq?.removeEventListener('change', syncMq));

function onGroupClick(g) {
  // Collapsed rail: a group icon expands the sidebar with that section open
  if (props.collapsed && isDesktop.value) {
    open.value = new Set([...open.value, g.id]);
    emit('expand');
    return;
  }
  const next = new Set(open.value);
  next.has(g.id) ? next.delete(g.id) : next.add(g.id);
  open.value = next;
}
</script>
