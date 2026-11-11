<template>
  <!-- "What's New on WorkMate": a small card once after a release. Never a full-screen
       cover; it waits while a dialog, the welcome screen or a chat is open. -->
  <Transition
    enter-active-class="transition duration-500 ease-out motion-reduce:transition-none"
    enter-from-class="opacity-0 translate-y-6 lg:translate-y-0 lg:translate-x-8"
    leave-active-class="transition duration-300 ease-in motion-reduce:transition-none"
    leave-to-class="opacity-0 translate-y-4 lg:translate-y-0 lg:translate-x-8"
  >
    <section
      v-if="a && phase === 'card' && !blocked"
      class="fixed inset-x-3 z-[60] bottom-[calc(var(--wm-nav-clearance)+6px)] lg:inset-x-auto lg:right-6 lg:bottom-6 lg:w-[380px]"
      role="status" aria-live="polite" aria-labelledby="whats-new-title"
      @mouseenter="hold(true)" @mouseleave="hold(false)" @focusin="hold(true)" @focusout="hold(false)"
    >
      <div class="relative overflow-hidden rounded-[22px] border border-white/70 bg-gradient-to-br from-white/95 via-[#F0F9FF]/95 to-[#E0F2FE]/95 p-4 pr-11 shadow-[0_18px_48px_rgba(23,105,210,0.22)] backdrop-blur-xl">
        <span class="pointer-events-none absolute -right-6 -top-8 h-24 w-24 rounded-full bg-[#38BDF8]/25 blur-2xl" aria-hidden="true"></span>
        <div class="flex items-start gap-3">
          <span class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#38BDF8] to-primary text-white shadow-[0_6px_16px_rgba(23,105,210,0.35)]" aria-hidden="true">
            <i class="bi bi-stars text-[18px]"></i>
          </span>
          <div class="min-w-0">
            <p class="text-[12.5px] font-semibold uppercase tracking-wide text-primary">{{ a.header }}</p>
            <h2 id="whats-new-title" class="mt-0.5 text-[16px] font-bold leading-snug text-ink">{{ a.title }}</h2>
            <p class="mt-1 text-[13.5px] leading-snug text-[#334155]">{{ a.summary }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" class="wm-btn wm-btn-primary !min-h-10 !px-4 !rounded-full" @click="explore">{{ a.primary_action }}</button>
              <button type="button" class="wm-btn wm-btn-ghost !min-h-10 !px-3" @click="close('dismissed')">{{ a.secondary_action }}</button>
            </div>
          </div>
        </div>
        <button type="button" class="absolute right-2 top-2 inline-flex h-9 w-9 items-center justify-center rounded-full text-[#475569] hover:bg-white/70" aria-label="Close what’s new" @click="close('dismissed')">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <!-- Time left before it goes by itself (it pauses while you point at it or use it) -->
        <div class="absolute inset-x-0 bottom-0 h-1 bg-primary/10" aria-hidden="true">
          <div class="h-full bg-gradient-to-r from-[#38BDF8] to-primary" :style="{ width: `${Math.round(left * 100)}%` }"></div>
        </div>
      </div>
    </section>
  </Transition>

  <!-- The full update: stays until they close it -->
  <BottomSheet :open="!!a && phase === 'details'" :title="a ? a.header : ''" @close="close('read')">
    <div v-if="a" class="flex flex-col gap-4">
      <div>
        <p class="text-[18px] font-bold leading-snug text-ink">{{ a.title }}</p>
        <p class="mt-1 text-[14.5px] text-[#334155]">{{ a.summary }}</p>
      </div>
      <ol class="flex flex-col gap-3">
        <li v-for="(item, i) in a.items" :key="item.title" class="flex items-start gap-3 rounded-2xl bg-[#F0F9FF] p-3">
          <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-primary shadow-sm" aria-hidden="true">
            <i :class="['bi', item.icon, item.icon === 'bi-circle-fill' ? 'text-[10px] text-[#16A34A]' : 'text-[17px]']"></i>
          </span>
          <span class="min-w-0">
            <span class="block text-[15px] font-semibold text-ink">{{ i + 1 }}. {{ item.title }}</span>
            <span class="block text-[13.5px] leading-snug text-[#475569]">{{ item.text }}</span>
          </span>
        </li>
      </ol>
      <p class="text-center text-[15px] font-semibold text-primary">{{ a.closing }}</p>
    </div>
    <template #footer>
      <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="close('read')">Close</button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import BottomSheet from './BottomSheet.vue';
import { auth } from '@/stores/auth.js';
import { legalGate } from '@/stores/legal.js';
import { locationIntro } from '@/stores/location.js';
import { loadAnnouncement, acknowledge } from '@/stores/announcement.js';

const route = useRoute();
const a = ref(null);
const phase = ref('idle');     // idle | card | details | done
const left = ref(1);           // share of the display time still to go
let shownMs = 0;               // time the card has been on screen
let last = 0;
let frame = 0;
let held = false;

/** Not now: the welcome screen, the location question, a chat, the admin, or another dialog open */
const dialogOpen = ref(false);
const blocked = computed(() => legalGate.open || locationIntro.open || dialogOpen.value
  || !!route.meta.immersive || route.meta.layout === 'admin');

function checkDialogs() {
  // Another modal dialog on screen (a confirmation, a sheet): wait for it to close
  dialogOpen.value = !!document.querySelector('[role="dialog"][aria-modal="true"]');
}

let dialogTimer = 0;
onMounted(async () => {
  // Once per app load: changing pages never starts it again
  // Wait until we know whether they're signed in (it may already be known)
  if (auth.status !== 'ready' && auth.status !== 'unavailable') {
    await new Promise(r => {
      const stop = watch(() => auth.status, (s) => { if (s === 'ready' || s === 'unavailable') { stop(); r(); } });
    });
  }
  try {
    a.value = await loadAnnouncement();
  } catch {
    a.value = null;   // no announcement is better than an error
  }
  if (!a.value) { phase.value = 'done'; return; }
  setTimeout(() => {
    if (phase.value !== 'idle') return;
    phase.value = 'card';
    last = performance.now();
    frame = requestAnimationFrame(tick);
  }, Math.max(0, a.value.delay_seconds) * 1000);
  dialogTimer = setInterval(checkDialogs, 500);
});

function tick(now) {
  if (phase.value !== 'card') return;
  // The clock only runs while the card is actually on screen and not being used
  if (!blocked.value && !held && document.visibilityState === 'visible') shownMs += now - last;
  last = now;
  const total = Math.max(1, a.value.display_seconds) * 1000;
  left.value = Math.max(0, 1 - shownMs / total);
  if (shownMs >= total) { close('expired'); return; }
  frame = requestAnimationFrame(tick);
}

function hold(on) { held = on; }

function explore() {
  cancelAnimationFrame(frame);
  phase.value = 'details';
}

function close(how) {
  if (!a.value || phase.value === 'done') return;
  cancelAnimationFrame(frame);
  acknowledge(a.value.id, how);
  phase.value = 'done';
  clearInterval(dialogTimer);
}

onBeforeUnmount(() => { cancelAnimationFrame(frame); clearInterval(dialogTimer); });
</script>
