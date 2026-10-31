<template>
  <BottomSheet :open="pwa.installOpen" title="Get the WorkMate app" @close="closeInstall">
    <div class="flex items-center gap-3 mb-4">
      <img src="/icons/icon-192.png" alt="" width="56" height="56" class="w-14 h-14 rounded-2xl shadow-sm" />
      <div>
        <p class="font-semibold text-ink">WorkMate</p>
        <p class="text-sm text-muted">Opens full screen from your home screen, like any other app.</p>
      </div>
    </div>

    <!-- Already installed -->
    <p v-if="pwa.standalone || pwa.installed" class="wm-notice wm-notice-success" role="status">
      <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
      <span>WorkMate is installed. Open it from your home screen or app list.</span>
    </p>

    <!-- Android, desktop Chrome and Edge: the browser's own install dialog -->
    <template v-else-if="pwa.canPrompt">
      <p class="text-[15px] text-ink">Tap <strong>Install</strong> and confirm. WorkMate is added to your home screen and keeps itself up to date.</p>
    </template>

    <!-- iPhone and iPad in Safari -->
    <ol v-else-if="pwa.platform === 'ios-safari'" class="wm-install-steps">
      <li><span>Tap the <strong>Share</strong> button <i class="bi bi-box-arrow-up text-primary" aria-label="Share icon"></i> {{ isIpad ? 'at the top of Safari' : 'at the bottom of Safari' }}.</span></li>
      <li><span>Scroll down and tap <strong>Add to Home Screen</strong> <i class="bi bi-plus-square" aria-hidden="true"></i>.</span></li>
      <li><span>Tap <strong>Add</strong> in the top corner.</span></li>
    </ol>

    <!-- iPhone and iPad in Chrome, Firefox or Edge -->
    <template v-else-if="pwa.platform === 'ios-other'">
      <ol class="wm-install-steps">
        <li><span>Tap the <strong>Share</strong> button <i class="bi bi-box-arrow-up text-primary" aria-label="Share icon"></i> in the address bar or menu.</span></li>
        <li><span>Tap <strong>Add to Home Screen</strong>. You may need to tap <strong>More</strong> first.</span></li>
        <li><span>Tap <strong>Add</strong>.</span></li>
      </ol>
      <p class="mt-3 text-sm text-muted">Don’t see it? Open this page in Safari and follow the same steps.</p>
    </template>

    <!-- Inside Facebook, Instagram, TikTok and other apps -->
    <template v-else-if="pwa.platform === 'ios-in-app' || pwa.platform === 'android-in-app'">
      <p class="text-[15px] text-ink">To install, first open WorkMate in your browser.</p>
      <ol class="wm-install-steps mt-3">
        <li><span>Tap the menu <i class="bi bi-three-dots" aria-hidden="true"></i> in this app.</span></li>
        <li><span>Choose <strong>{{ pwa.platform === 'ios-in-app' ? 'Open in Safari' : 'Open in browser' }}</strong>, or copy the link below and paste it into your browser.</span></li>
      </ol>
    </template>

    <!-- Android browsers without an install dialog (Firefox, Samsung Internet, …) -->
    <ol v-else-if="pwa.platform === 'android'" class="wm-install-steps">
      <li><span>Open your browser’s menu <i class="bi bi-three-dots-vertical" aria-hidden="true"></i> (in Samsung Internet, <i class="bi bi-list" aria-hidden="true"></i>).</span></li>
      <li><span>Tap <strong>Install app</strong> or <strong>Add to Home screen</strong>.</span></li>
      <li><span>Confirm with <strong>Install</strong> or <strong>Add</strong>.</span></li>
    </ol>

    <!-- Computers -->
    <template v-else>
      <p class="text-[15px] text-ink">In Chrome or Edge, click the install icon <i class="bi bi-window-plus" aria-hidden="true"></i> in the address bar, or open the browser menu and choose <strong>Install WorkMate</strong>. On a Mac in Safari, choose <strong>File › Add to Dock</strong>.</p>
      <p class="mt-3 text-sm text-muted">On your phone, open {{ host }} and choose Install.</p>
    </template>

    <p v-if="note" class="mt-3 text-sm text-muted" role="status">{{ note }}</p>

    <template #footer>
      <PrimaryButton v-if="pwa.canPrompt && !pwa.installed" class="flex-1" @click="install">
        <i class="bi bi-download" aria-hidden="true"></i> Install
      </PrimaryButton>
      <PrimaryButton v-else-if="showCopy" variant="secondary" class="flex-1" @click="copyLink">
        <i :class="['bi', copied ? 'bi-check-lg' : 'bi-link-45deg']" aria-hidden="true"></i>
        {{ copied ? 'Link copied' : 'Copy link' }}
      </PrimaryButton>
      <PrimaryButton variant="ghost" class="flex-1" @click="closeInstall">
        {{ pwa.canPrompt && !pwa.installed ? 'Not now' : 'Done' }}
      </PrimaryButton>
    </template>
  </BottomSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import PrimaryButton from './PrimaryButton.vue';
import { pwa, closeInstall, promptInstall } from '@/stores/pwa.js';

const host = window.location.host;
const isIpad = /iPad/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const note = ref('');
const copied = ref(false);

const showCopy = computed(() => !pwa.standalone && !pwa.installed
  && ['ios-other', 'ios-in-app', 'android-in-app'].includes(pwa.platform));

watch(() => pwa.installOpen, (open) => {
  if (open) {
    note.value = '';
    copied.value = false;
  }
});

async function install() {
  const outcome = await promptInstall();
  if (outcome === 'dismissed') note.value = 'No problem. You can install WorkMate any time from here.';
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(window.location.origin);
    copied.value = true;
  } catch {
    note.value = `Copy this address into your browser: ${window.location.origin}`;
  }
}
</script>
