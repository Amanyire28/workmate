<template>
  <ul class="flex flex-col divide-y divide-line">
    <!-- Notifications -->
    <li class="flex items-start gap-3 py-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-bell"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">Notifications</p>
        <p class="wm-meta">New requests, replies and messages, even when WorkMate is closed. You choose which kinds in Account → Notifications.</p>
        <p v-if="push.error" class="mt-1 text-[13px] text-[#B91C1C]">{{ push.error }}</p>
        <p v-else-if="push.state === 'denied'" class="mt-1 text-[13px] text-muted">Blocked in this browser. To allow them, open the browser’s site settings for WorkMate and set Notifications to Allow.</p>
        <p v-else-if="push.state === 'install-first'" class="mt-1 text-[13px] text-muted">On iPhone, add WorkMate to your Home Screen first, then allow notifications from the app.</p>
      </div>
      <span v-if="push.state === 'on'" class="wm-pill wm-pill-success shrink-0"><i class="bi bi-check-lg" aria-hidden="true"></i>Allowed</span>
      <button v-else-if="push.state === 'off'" type="button" class="wm-btn wm-btn-secondary !min-h-10 shrink-0" :disabled="push.busy" @click="enablePush">
        {{ push.busy ? 'Asking…' : 'Allow' }}
      </button>
      <span v-else-if="push.state === 'denied'" class="wm-pill wm-pill-neutral shrink-0">Blocked</span>
      <span v-else-if="push.state === 'checking'" class="wm-pill wm-pill-neutral shrink-0">Checking…</span>
      <span v-else class="wm-pill wm-pill-neutral shrink-0">Not available</span>
    </li>

    <!-- Location -->
    <li class="flex items-start gap-3 py-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">Location</p>
        <p class="wm-meta">When you open WorkMate, to show providers and jobs near where you are. It isn’t stored or tracked, and you can choose a different area by name at any time.</p>
        <p v-if="location === 'denied'" class="mt-1 text-[13px] text-muted">Blocked in this browser. Allow it in the site settings, or keep choosing your area by name.</p>
      </div>
      <span v-if="location === 'granted'" class="wm-pill wm-pill-success shrink-0"><i class="bi bi-check-lg" aria-hidden="true"></i>Allowed</span>
      <span v-else-if="location === 'denied'" class="wm-pill wm-pill-neutral shrink-0">Blocked</span>
      <span v-else-if="location === 'unsupported'" class="wm-pill wm-pill-neutral shrink-0">Not available</span>
      <button v-else type="button" class="wm-btn wm-btn-secondary !min-h-10 shrink-0" :disabled="locating" @click="allowLocation">
        {{ locating ? 'Asking…' : 'Allow' }}
      </button>
    </li>

    <!-- Photos and camera: the phone's own picker, nothing to grant in advance -->
    <li class="flex items-start gap-3 py-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-camera"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">Photos and camera</p>
        <p class="wm-meta">When you add a photo, your phone lets you take one or pick from your gallery. WorkMate only receives the photos you choose, never the rest of your gallery, and removes their location data.</p>
      </div>
      <span class="wm-pill wm-pill-neutral shrink-0">Asked when needed</span>
    </li>

    <!-- Not used -->
    <li class="flex items-start gap-3 py-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">Microphone and contacts</p>
        <p class="wm-meta">WorkMate doesn’t use them and never asks for them.</p>
      </div>
    </li>
  </ul>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { push, refreshPush, enablePush } from '@/stores/push.js';
import { requestDeviceLocation } from '@/stores/location.js';

/**
 * Each device permission WorkMate may use, with why, and an Allow button that
 * makes the browser ask. Nothing is asked for until the person taps Allow.
 */
const location = ref('prompt');
const locating = ref(false);

async function checkLocation() {
  if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
    location.value = 'unsupported';
    return;
  }
  try {
    const status = await navigator.permissions?.query({ name: 'geolocation' });
    if (status) {
      location.value = status.state;
      status.onchange = () => { location.value = status.state; };
    }
  } catch { /* the browser can't say; the button asks */ }
}

async function allowLocation() {
  locating.value = true;
  try {
    await requestDeviceLocation();
    location.value = 'granted';
  } catch (e) {
    if (e?.code === 'denied') location.value = 'denied';
  } finally {
    locating.value = false;
    checkLocation();
  }
}

onMounted(() => {
  refreshPush();
  checkLocation();
});
</script>
