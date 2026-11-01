<template>
  <BottomSheet :open="open" :title="done ? 'Notifications are on' : 'Turn on notifications?'" @close="close">
    <!-- Turned on -->
    <div v-if="done" class="flex flex-col items-center text-center gap-3 py-4" role="status">
      <span class="w-14 h-14 rounded-full bg-[#DCFCE7] text-[#15803D] flex items-center justify-center text-2xl" aria-hidden="true">
        <i class="bi bi-bell-fill"></i>
      </span>
      <p class="text-[15px] text-ink">You’ll hear about requests, replies and messages on this device. You can turn them off any time on your Account page.</p>
    </div>

    <!-- Blocked in the browser after all -->
    <div v-else-if="push.state === 'denied'" class="flex flex-col gap-3">
      <p class="text-[15px] text-ink">Notifications are blocked for WorkMate in this browser.</p>
      <p class="text-sm text-muted">To allow them, open your browser’s site settings for WorkMate and set Notifications to Allow. Account → Notifications explains how.</p>
    </div>

    <div v-else class="flex flex-col gap-4">
      <div class="flex items-start gap-3">
        <span class="w-12 h-12 shrink-0 rounded-2xl bg-primary-tint text-primary flex items-center justify-center text-[22px]" aria-hidden="true">
          <i class="bi bi-bell"></i>
        </span>
        <p class="text-[15px] text-ink leading-relaxed">
          Notifications are off on this device, so you could miss new requests, replies and messages. Turn them on to hear about them even when the app is closed.
        </p>
      </div>
      <p class="text-[13px] text-muted">Your browser will ask you to allow them. You can turn them off any time on your Account page.</p>
      <p v-if="push.error" class="wm-notice wm-notice-error" role="alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ push.error }}</span>
      </p>
    </div>

    <template #footer>
      <button v-if="done || push.state === 'denied'" type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="close">Continue</button>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="push.busy" @click="close">Not now</button>
        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" :disabled="push.busy" @click="turnOn">
          {{ push.busy ? 'Turning on…' : 'Turn on' }}
        </button>
      </template>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import BottomSheet from './BottomSheet.vue';
import { push, refreshPush, enablePush, reminderDue, reminderShown } from '@/stores/push.js';
import { ratePrompt } from '@/stores/reviews.js';
import { auth } from '@/stores/auth.js';

/**
 * Reminds signed-in people whose notifications are off on this device to
 * turn them on. Only when the browser can still ask (not blocked), never over
 * the rating pop-up, and at most once a week, three times per device.
 */
const DELAY_MS = 6000;

const route = useRoute();
const open = ref(false);
const done = ref(false);
let timer = null;

function schedule() {
  clearTimeout(timer);
  if (!auth.user || !reminderDue()) return;
  timer = setTimeout(async () => {
    await refreshPush();
    // Checked again after the wait: things may have changed meanwhile
    if (!auth.user || push.state !== 'off' || !reminderDue() || route.path.startsWith('/admin')) return;
    if (ratePrompt.current) { schedule(); return; }   // after the rating pop-up closes
    done.value = false;
    open.value = true;
    reminderShown();
  }, DELAY_MS);
}

watch(() => auth.user?.id, (id) => { if (id) schedule(); else { clearTimeout(timer); open.value = false; } }, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

async function turnOn() {
  await enablePush();
  if (push.state === 'on') done.value = true;
}

function close() {
  open.value = false;
}
</script>
