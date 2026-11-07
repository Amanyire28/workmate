<template>
  <!-- The admin area brings its own shell (sidebar + top bar) -->
  <template v-if="isAdmin">
    <router-view />
    <AppStatusLayer :with-nav="false" :offer-install="false" />
  </template>

  <template v-else>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[200] wm-btn wm-btn-primary">
      Skip to content
    </a>

    <AppHeader />

    <div v-if="catalog.status === 'error'" class="wm-container mt-3">
      <p class="wm-notice wm-notice-warning" role="alert">
        <i class="bi bi-cloud-slash" aria-hidden="true"></i>
        <span>
          We couldn’t load providers and jobs just now. {{ catalog.error }}
          <button type="button" class="wm-link" @click="loadCatalog({ force: true })">Try again</button>
        </span>
      </p>
    </div>

    <!-- Admin → Settings → Maintenance mode -->
    <div v-if="appSettings.maintenanceMode" class="wm-container mt-3">
      <p class="wm-notice wm-notice-warning" role="status">
        <i class="bi bi-tools" aria-hidden="true"></i>
        <span><strong class="font-semibold">WorkMate is having maintenance.</strong> You can browse and message people, but new jobs, requests and applications are paused for now.</span>
      </p>
    </div>

    <main id="main" tabindex="-1" class="focus:outline-none">
      <router-view />
    </main>

    <div class="wm-bottom-nav-spacer" aria-hidden="true"></div>
    <MobileBottomNav />
    <AppStatusLayer />
    <RateProviderDialog />
    <PushReminder />
    <LegalGate />
  </template>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppHeader from '@/components/AppHeader.vue';
import MobileBottomNav from '@/components/MobileBottomNav.vue';
import AppStatusLayer from '@/components/AppStatusLayer.vue';
import RateProviderDialog from '@/components/RateProviderDialog.vue';
import PushReminder from '@/components/PushReminder.vue';
import LegalGate from '@/components/LegalGate.vue';
import { catalog, loadCatalog, appSettings, refreshSettings } from '@/stores/catalog.js';
import { startPresence } from '@/stores/presence.js';

// While the app is open, a signed-in account checks in (Admin → Settings → presence)
startPresence();

// Settings changed in the admin (maintenance mode, support contact…) reach open
// apps within a minute, and straight away when the app comes back on screen
setInterval(() => { if (document.visibilityState === 'visible') refreshSettings(); }, 60 * 1000);
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshSettings(); });

const route = useRoute();
const isAdmin = computed(() => route.meta.layout === 'admin');
</script>
