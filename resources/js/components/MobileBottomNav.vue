<template>
  <!-- Floating glass tab bar -->
  <nav
    class="lg:hidden fixed inset-x-2 min-[400px]:inset-x-3 z-40 bottom-[calc(8px+var(--wm-safe-bottom))] wm-glass-strong rounded-[28px] !shadow-[0_12px_40px_rgba(15,23,42,0.16),inset_0_1px_0_rgba(255,255,255,0.9)]"
    aria-label="Main"
  >
    <ul class="grid grid-cols-5 h-[var(--wm-bottom-nav-h)] px-1">
      <li v-for="item in navItems" :key="item.to" class="flex py-1.5">
        <router-link
          :to="item.to"
          class="relative flex-1 flex flex-col items-center justify-center gap-[3px] min-w-0 rounded-[20px] transition-colors"
          :class="isActive(item) ? 'text-primary bg-primary/10' : 'text-muted hover:text-ink'"
          :aria-current="isActive(item) ? 'page' : undefined"
          :aria-label="item.badge?.() ? `${item.label}, ${item.badge()} unread` : undefined"
        >
          <span class="relative">
            <i
              :class="['bi', isActive(item) ? item.iconActive : item.icon, 'text-[21px] leading-none']"
              aria-hidden="true"
            ></i>
            <span v-if="item.badge?.()" class="wm-count-badge !-top-1.5 !-right-3" aria-hidden="true">{{ item.badge() }}</span>
          </span>
          <span class="text-[10px] min-[400px]:text-[11px] tracking-[-0.01em] leading-tight font-medium truncate max-w-full">{{ item.label }}</span>
        </router-link>
      </li>
    </ul>
  </nav>
</template>

<script setup>
import { useRoute } from 'vue-router';
import { navIsActive } from './navItems.js';
import { unreadTotal } from '@/stores/messages.js';

const route = useRoute();

const navItems = [
  { to: '/',         label: 'Home',      icon: 'bi-house',         iconActive: 'bi-house-fill',         match: ['/'] },
  { to: '/browse',   label: 'Providers', icon: 'bi-people',        iconActive: 'bi-people-fill',        match: ['/browse', '/workers', '/provider', '/explore'] },
  { to: '/community', label: 'Community', icon: 'bi-compass',       iconActive: 'bi-compass-fill',       match: ['/community'] },
  { to: '/messages', label: 'Messages',  icon: 'bi-chat',          iconActive: 'bi-chat-fill',          match: ['/messages'], badge: () => unreadTotal.value },
  { to: '/activity', label: 'My Work',   icon: 'bi-briefcase',     iconActive: 'bi-briefcase-fill',     match: ['/activity', '/find-work', '/job', '/post-job'] },
];
// Account is in the top bar on phones

const isActive = (item) => navIsActive(route.path, item.match);
</script>
