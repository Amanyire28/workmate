<template>
  <!-- A friendly nudge for signed-in people who haven't confirmed their email. Nothing is blocked by it. -->
  <section v-if="visible" class="wm-container pt-3" aria-labelledby="verify-reminder-title">
    <div class="relative flex flex-col gap-3 rounded-[18px] border border-[#BAE6FD] bg-gradient-to-br from-[#F0F9FF] to-[#E0F2FE] p-4 sm:flex-row sm:items-center sm:gap-4">
      <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-[22px] shadow-sm" aria-hidden="true">✉️</span>
      <div class="min-w-0 flex-1 pr-8 sm:pr-0">
        <h2 id="verify-reminder-title" class="text-[16px] font-semibold text-ink">One more step to enjoy WorkMate! ✉️</h2>
        <p class="text-[14px] text-[#334155]">
          Please verify your email address to secure your account and enjoy all the features available to you.
          We sent a link to <strong class="font-semibold break-all">{{ auth.user.email }}</strong>.
        </p>
        <p v-if="message" class="mt-1.5 text-[13.5px]" :class="messageTone === 'error' ? 'text-error' : 'text-success'" role="status">{{ message }}</p>
      </div>
      <div class="flex flex-wrap gap-2 sm:shrink-0">
        <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1 sm:flex-none" :disabled="busy" @click="check">
          <i class="bi bi-envelope-check" aria-hidden="true"></i>Verify Email
        </button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1 sm:flex-none" :disabled="busy || cooldown > 0" @click="resend">
          {{ cooldown > 0 ? `Resend in ${cooldown}s` : 'Resend Verification Link' }}
        </button>
      </div>
      <button type="button" class="absolute right-2 top-2 wm-icon-btn !w-9 !h-9 text-muted" aria-label="Hide this reminder for now" @click="dismiss">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
  </section>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { auth, loadSession } from '@/stores/auth.js';
import { api } from '@/api/client.js';

const KEY = 'workmate.verifyReminderHidden';
const route = useRoute();

function readHidden() {
  try { return sessionStorage.getItem(KEY) === '1'; } catch { return false; }
}
const hidden = ref(readHidden());
const busy = ref(false);
const message = ref('');
const messageTone = ref('success');
const cooldown = ref(0);
let timer = null;

// Not on pages with their own focus (admin, chat, the email outcome on Account), and not once hidden this visit
const visible = computed(() => !!auth.user && !auth.user.email_verified && !hidden.value
  && route.meta.layout !== 'admin' && !route.meta.immersive && !route.query.email_verification);

/** "Verify Email": they may have tapped the link on another device; check, else explain */
async function check() {
  busy.value = true;
  message.value = '';
  try {
    const user = await loadSession({ force: true });
    if (user?.email_verified) return;   // the reminder goes away by itself
    messageTone.value = 'success';
    message.value = `Open the email from WorkMate sent to ${auth.user.email} and tap “Confirm my email”. Can’t find it? Check spam, or resend it.`;
  } finally {
    busy.value = false;
  }
}

async function resend() {
  busy.value = true;
  message.value = '';
  try {
    message.value = (await api('/me/email/verification-notification', { method: 'POST', body: {} })).data.message;
    messageTone.value = 'success';
    startCooldown(60);
  } catch (e) {
    messageTone.value = 'error';
    message.value = e.status === 429 ? 'You’ve asked a few times already. Please wait a minute and try again.' : e.message;
    if (e.status === 429) startCooldown(60);
  } finally {
    busy.value = false;
  }
}

function startCooldown(seconds) {
  cooldown.value = seconds;
  clearInterval(timer);
  timer = setInterval(() => {
    cooldown.value -= 1;
    if (cooldown.value <= 0) clearInterval(timer);
  }, 1000);
}

function dismiss() {
  hidden.value = true;
  try { sessionStorage.setItem(KEY, '1'); } catch { /* storage blocked: hidden until reload */ }
}

// Confirmed in the email app, then back here: notice it (at most once a minute)
let lastCheck = 0;
function onVisible() {
  if (document.visibilityState !== 'visible' || !visible.value || Date.now() - lastCheck < 60000) return;
  lastCheck = Date.now();
  loadSession({ force: true }).catch(() => {});
}
document.addEventListener('visibilitychange', onVisible);

onBeforeUnmount(() => {
  clearInterval(timer);
  document.removeEventListener('visibilitychange', onVisible);
});
</script>
