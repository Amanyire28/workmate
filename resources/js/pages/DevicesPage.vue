<template>
  <div class="wm-container wm-page max-w-3xl">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">Devices</h1>
    <p class="wm-lead mt-1.5">Where you’re signed in to WorkMate. Sign out any device you don’t recognise, then change your password.</p>

    <div v-if="loading" class="mt-5 flex flex-col gap-3" aria-busy="true">
      <div v-for="n in 2" :key="n" class="wm-skeleton h-[72px] !rounded-[20px]"></div>
    </div>

    <EmptyState v-else-if="error" class="mt-4" icon="bi-cloud-slash" title="Your devices couldn’t load" :message="error">
      <button type="button" class="wm-btn wm-btn-secondary" @click="load">Try again</button>
    </EmptyState>

    <p v-else-if="!available" class="wm-notice wm-notice-info mt-5">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>The list of devices isn’t switched on yet. You can still sign out everywhere else by changing your password.</span>
    </p>

    <template v-else>
      <ul class="wm-card !p-0 mt-5 divide-y divide-line overflow-hidden">
        <li v-for="d in devices" :key="d.key" class="flex items-center gap-3 px-4 py-3">
          <span class="wm-icon-tile shrink-0" aria-hidden="true"><i :class="['bi', ICONS[d.kind] || 'bi-display']"></i></span>
          <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-semibold text-ink">
              {{ d.browser }}<template v-if="d.platform"> on {{ d.platform }}</template>
              <span v-if="d.current" class="wm-pill wm-pill-success !py-0 !text-[11px] ml-1">This device</span>
            </span>
            <span class="block wm-meta">
              {{ d.current ? 'Active now' : `Last active ${agoLabel(d.last_active_at).toLowerCase()}` }}<template v-if="d.ip_address"> · {{ d.ip_address }}</template>
            </span>
          </span>
          <button v-if="!d.current" type="button" class="wm-btn wm-btn-ghost !min-h-10 shrink-0" :disabled="busy === d.key" @click="signOutDevice(d)">
            {{ busy === d.key ? 'Signing out…' : 'Sign out' }}
          </button>
        </li>
      </ul>

      <p v-if="actionError" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ actionError }}</span></p>
      <p v-if="done" class="wm-notice wm-notice-success mt-3" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><span>{{ done }}</span></p>

      <button v-if="others.length" type="button" class="wm-btn wm-btn-secondary wm-btn-block mt-4" :disabled="busy === 'others'" @click="signOutOthers">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ busy === 'others' ? 'Signing out…' : `Sign out of all other devices (${others.length})` }}
      </button>
    </template>

    <p class="wm-meta mt-6 px-1">
      “Keep me signed in on this device” keeps you signed in for 90 days without using WorkMate. Untick it when you sign in on a shared phone or computer:
      you’ll then be signed out when the browser closes. Changing your password signs out every other device.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import { api } from '@/api/client.js';
import { agoLabel } from '@/stores/catalog.js';

const ICONS = { phone: 'bi-phone', tablet: 'bi-tablet', computer: 'bi-laptop' };

const devices = ref([]);
const available = ref(true);
const loading = ref(true);
const error = ref('');
const busy = ref('');
const actionError = ref('');
const done = ref('');
const others = computed(() => devices.value.filter(d => !d.current));

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await api('/me/devices');
    available.value = data.available;
    devices.value = data.devices;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

async function signOutDevice(d) {
  busy.value = d.key;
  actionError.value = '';
  done.value = '';
  try {
    await api(`/me/devices/${d.key}`, { method: 'DELETE' });
    devices.value = devices.value.filter(x => x.key !== d.key);
    done.value = `${d.browser}${d.platform ? ` on ${d.platform}` : ''} is signed out.`;
  } catch (e) {
    actionError.value = e.message || 'That device couldn’t be signed out. Try again.';
    if (e.status === 404) load();
  } finally {
    busy.value = '';
  }
}

async function signOutOthers() {
  busy.value = 'others';
  actionError.value = '';
  done.value = '';
  try {
    await api('/me/devices/sign-out-others', { method: 'POST' });
    done.value = 'Every other device is signed out.';
    await load();
  } catch (e) {
    actionError.value = e.message || 'The other devices couldn’t be signed out. Try again.';
  } finally {
    busy.value = '';
  }
}
</script>
