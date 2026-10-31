<template>
  <header class="sticky top-0 z-40 wm-glass-bar">
    <div class="wm-container flex items-center gap-4 h-[var(--wm-header-h)]">
      <AppLogo />

      <!-- Desktop navigation -->
      <nav class="hidden lg:flex items-center gap-1 ml-4" aria-label="Main">
        <router-link
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="flex items-center h-11 px-4 rounded-full text-[15px] font-medium transition-colors"
          :class="isActive(item) ? 'text-primary bg-primary/10' : 'text-muted hover:text-ink hover:bg-ink/5'"
          :aria-current="isActive(item) ? 'page' : undefined"
        >
          {{ item.label }}
        </router-link>
      </nav>

      <div class="ml-auto flex items-center gap-1.5">
        <router-link to="/post-job" class="hidden lg:inline-flex wm-btn wm-btn-primary mr-1">
          <i class="bi bi-plus-lg" aria-hidden="true"></i> Post a Job
        </router-link>
        <router-link
          to="/messages"
          class="hidden lg:inline-flex wm-icon-btn"
          :class="{ 'text-primary bg-primary/10': route.path.startsWith('/messages') }"
          :aria-label="unreadTotal ? `Messages, ${unreadTotal} unread` : 'Messages'"
        >
          <i class="bi bi-chat" aria-hidden="true"></i>
          <span v-if="unreadTotal" class="wm-count-badge" aria-hidden="true">{{ unreadTotal }}</span>
        </router-link>
        <router-link
          v-if="auth.status === 'ready' && !auth.user"
          :to="signInLink"
          class="hidden lg:inline-flex items-center h-11 px-4 rounded-full text-[15px] font-medium text-primary hover:bg-primary/10"
        >
          Sign in
        </router-link>
        <router-link
          v-else
          to="/account"
          class="hidden lg:inline-flex wm-icon-btn"
          :class="{ 'text-primary bg-primary/10': route.path.startsWith('/account') }"
          aria-label="Account"
        >
          <UserAvatar v-if="auth.user?.avatar_url" :name="auth.user.name" :src="auth.user.avatar_url" :size="30" />
          <i v-else class="bi bi-person-circle" aria-hidden="true"></i>
        </router-link>

        <!-- Mobile: quick search -->
        <router-link to="/browse" class="lg:hidden wm-icon-btn -mr-2" aria-label="Search service providers">
          <i class="bi bi-search" aria-hidden="true"></i>
        </router-link>
      </div>
    </div>
  </header>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppLogo from './AppLogo.vue';
import UserAvatar from './UserAvatar.vue';
import { navIsActive } from './navItems.js';
import { unreadTotal } from '@/stores/messages.js';
import { auth } from '@/stores/auth.js';

const route = useRoute();

const navItems = [
  { to: '/',          label: 'Home',             match: ['/'] },
  { to: '/browse',    label: 'Find Providers',   match: ['/browse', '/workers', '/provider'] },
  { to: '/explore',   label: 'Explore Services', match: ['/explore'] },
  { to: '/find-work', label: 'Find Work',        match: ['/find-work', '/job'] },
  { to: '/activity',  label: 'My Work',          match: ['/activity'] },
];

const isActive = (item) => navIsActive(route.path, item.match);

// Come back to the current page after signing in, except from home and the
// sign-in pages themselves (which would nest redirects)
const signInLink = computed(() => {
  const plain = route.path === '/' || route.meta.guestOnly || route.path === '/reset-password';
  return plain ? '/sign-in' : { path: '/sign-in', query: { redirect: route.fullPath } };
});
</script>
