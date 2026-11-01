<template>
  <section v-if="visible" class="mt-6" aria-labelledby="account-notifications-heading">
    <h2 id="account-notifications-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Notifications</h2>
    <div class="wm-card flex items-start gap-3">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-bell"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">WorkMate announcements</p>
        <p class="wm-meta mt-0.5">{{ description }}</p>
        <p v-if="push.error" class="mt-2 text-[13px] text-[#B91C1C]" role="alert">{{ push.error }}</p>
        <button
          v-if="push.state === 'install-first'"
          type="button"
          class="wm-btn wm-btn-secondary mt-3"
          @click="openInstall"
        ><i class="bi bi-phone" aria-hidden="true"></i>Install WorkMate</button>
      </div>
      <button
        v-if="push.state === 'on' || push.state === 'off'"
        type="button"
        role="switch"
        :aria-checked="push.state === 'on' ? 'true' : 'false'"
        aria-labelledby="account-notifications-heading"
        :disabled="push.busy"
        class="relative mt-1 h-7 w-12 shrink-0 rounded-full transition-colors disabled:opacity-60"
        :class="push.state === 'on' ? 'bg-primary' : 'bg-[#CBD5E1]'"
        @click="toggle"
      >
        <span
          class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform"
          :class="push.state === 'on' ? 'translate-x-5' : ''"
          aria-hidden="true"
        ></span>
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { push, refreshPush, enablePush, disablePush } from '@/stores/push.js';
import { openInstall } from '@/stores/pwa.js';

const DESCRIPTIONS = {
  on: 'On for this device. You’ll get news and updates from WorkMate.',
  off: 'Get news and updates from WorkMate on this device.',
  denied: 'Blocked in your browser settings. Allow notifications for this site there, then come back.',
  'install-first': 'On iPhone and iPad, add WorkMate to your Home Screen first, then turn notifications on in the app.',
};

// Hidden where notifications can't work at all, or before the server is set up
const visible = computed(() => push.state in DESCRIPTIONS);
const description = computed(() => DESCRIPTIONS[push.state]);

function toggle() {
  if (push.state === 'on') disablePush();
  else enablePush();
}

onMounted(refreshPush);
</script>
