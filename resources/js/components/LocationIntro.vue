<template>
  <!-- Once per device, before the browser's own location question: why WorkMate asks.
       The browser only asks after "Enable Location"; "Not now" never asks. -->
  <BottomSheet :open="visible" title="📍 Find services wherever you are" @close="notNow">
    <div class="flex flex-col items-center gap-3 pb-1 text-center">
      <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-primary-tint text-[30px] text-primary" aria-hidden="true">
        <i class="bi bi-geo-alt-fill"></i>
      </span>
      <p class="max-w-[420px] text-[15px] leading-relaxed text-ink">
        WorkMate uses your current location to connect you with nearby service providers, wherever you go.
        Enable location to automatically discover relevant services around you.
      </p>
      <p class="max-w-[420px] text-[13px] text-muted">
        Your location isn’t stored or tracked, and you can choose an area by name at any time.
      </p>
    </div>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="notNow">Not now</button>
      <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" @click="enable">
        <i class="bi bi-crosshair" aria-hidden="true"></i>Enable Location
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { computed, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import { locationIntro, enableLocationFromIntro, dismissLocationIntro, markLocationIntroShown } from '@/stores/location.js';
import { legal, legalGate, permissionsAsked } from '@/stores/legal.js';
import { appSettings } from '@/stores/catalog.js';

// Not on top of the welcome and terms screen, and only once it's clear that
// screen won't open (its own permissions step already explains location)
const settled = computed(() => !appSettings.askLegal || legal.loaded);
const visible = computed(() => locationIntro.open && settled.value && !legalGate.open);

// The welcome screen's permissions step covered location: nothing more to explain
watch(() => [legalGate.open, settled.value], ([open, ready]) => {
  if (!open && ready && locationIntro.open && permissionsAsked()) {
    markLocationIntroShown();
    dismissLocationIntro();
  }
}, { immediate: true });

// Recorded as shown once it's actually on screen: it isn't shown again on this device
watch(visible, (shown) => { if (shown) markLocationIntroShown(); }, { immediate: true });

function enable() {
  enableLocationFromIntro();
}

function notNow() {
  dismissLocationIntro();
}
</script>
