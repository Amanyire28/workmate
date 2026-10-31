<template>
  <!-- App-level notices: offline, new release, "you're all set" and the install offer -->
  <div
    class="fixed z-[90] inset-x-3 sm:inset-x-auto sm:right-6 sm:w-[380px] flex flex-col gap-2 pointer-events-none"
    :class="withNav ? 'bottom-[calc(var(--wm-nav-clearance)+8px)] lg:bottom-6' : 'bottom-[calc(16px+var(--wm-safe-bottom))]'"
    aria-live="polite"
  >
    <Transition name="wm-pop">
      <div v-if="pwa.offline" class="wm-app-notice">
        <i class="bi bi-wifi-off text-muted text-lg" aria-hidden="true"></i>
        <p class="flex-1 text-sm text-ink">You’re offline. Some things won’t load until you reconnect.</p>
      </div>
    </Transition>

    <Transition name="wm-pop">
      <div v-if="pwa.updateReady" class="wm-app-notice" role="alert">
        <i class="bi bi-arrow-repeat text-primary text-xl" aria-hidden="true"></i>
        <p class="flex-1 text-sm text-ink"><strong class="font-semibold">A new version of WorkMate is ready.</strong></p>
        <PrimaryButton size="md" :block="false" :loading="pwa.updating" @click="applyUpdate">Update</PrimaryButton>
        <button type="button" class="wm-icon-btn -mr-1" aria-label="Later" @click="dismissUpdate">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </Transition>

    <Transition name="wm-pop">
      <div v-if="pwa.justUpdated" class="wm-app-notice" role="status">
        <i class="bi bi-check-circle-fill text-[#16A34A] text-xl" aria-hidden="true"></i>
        <p class="flex-1 text-sm text-ink"><strong class="font-semibold">You’re all set.</strong> WorkMate is up to date.</p>
        <button type="button" class="wm-icon-btn -mr-1" aria-label="Close" @click="dismissUpdated">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </Transition>

    <Transition name="wm-pop">
      <div v-if="pwa.installed && !installedSeen" class="wm-app-notice" role="status">
        <i class="bi bi-check-circle-fill text-[#16A34A] text-xl" aria-hidden="true"></i>
        <p class="flex-1 text-sm text-ink"><strong class="font-semibold">WorkMate is installed.</strong> Find it on your home screen.</p>
        <button type="button" class="wm-icon-btn -mr-1" aria-label="Close" @click="installedSeen = true">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </Transition>

    <Transition name="wm-pop">
      <div v-if="showInstallBanner" class="wm-app-notice">
        <img src="/icons/icon-192.png" alt="" width="40" height="40" class="hidden min-[400px]:block w-10 h-10 rounded-xl" />
        <p class="flex-1 min-w-0 text-sm leading-snug text-ink"><strong class="font-semibold">Get the WorkMate app</strong><br><span class="text-muted">Open it from your home screen</span></p>
        <PrimaryButton size="md" :block="false" @click="install">Install</PrimaryButton>
        <button type="button" class="wm-icon-btn -mr-1" aria-label="Not now" @click="hideInstallBanner">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </Transition>
  </div>

  <InstallAppSheet />
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import PrimaryButton from './PrimaryButton.vue';
import InstallAppSheet from './InstallAppSheet.vue';
import {
  pwa, isMobile, applyUpdate, dismissUpdate, dismissUpdated,
  openInstall, promptInstall, installBannerDismissed, dismissInstallBanner,
} from '@/stores/pwa.js';

const props = defineProps({
  withNav:       { type: Boolean, default: true },   // the mobile tab bar is on screen
  offerInstall:  { type: Boolean, default: true },
});

const INSTALL_BANNER_DELAY_MS = 4_000;
const UPDATED_NOTICE_MS = 5_000;

const installedSeen = ref(false);
const bannerReady = ref(false);
const bannerHidden = ref(installBannerDismissed());
let bannerTimer = null;
let updatedTimer = null;

const showInstallBanner = computed(() => props.offerInstall && bannerReady.value && !bannerHidden.value
  && isMobile() && !pwa.standalone && !pwa.installed && !pwa.updateReady && !pwa.installOpen);

function install() {
  if (pwa.canPrompt) promptInstall();
  else openInstall();
}

function hideInstallBanner() {
  bannerHidden.value = true;
  dismissInstallBanner();
}

watch(() => pwa.justUpdated, (shown) => {
  clearTimeout(updatedTimer);
  if (shown) updatedTimer = setTimeout(dismissUpdated, UPDATED_NOTICE_MS);
}, { immediate: true });

onMounted(() => {
  bannerTimer = setTimeout(() => { bannerReady.value = true; }, INSTALL_BANNER_DELAY_MS);
});
onBeforeUnmount(() => {
  clearTimeout(bannerTimer);
  clearTimeout(updatedTimer);
});
</script>
