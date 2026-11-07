<template>
  <div class="wm-container wm-page max-w-3xl">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">Legal and privacy</h1>
    <p class="wm-lead mt-1.5">What you agreed to, the choices you can change, and what WorkMate may use on this device.</p>

    <div v-if="loading" class="mt-5 flex flex-col gap-3" aria-busy="true">
      <div class="wm-skeleton h-[120px] !rounded-[20px]"></div>
      <div class="wm-skeleton h-[90px] !rounded-[20px]"></div>
    </div>

    <EmptyState v-else-if="error" class="mt-4" icon="bi-cloud-slash" title="This couldn’t load" :message="error">
      <button type="button" class="wm-btn wm-btn-secondary" @click="load">Try again</button>
    </EmptyState>

    <template v-else-if="mine">
      <!-- Documents -->
      <section class="mt-6" aria-labelledby="docs-heading">
        <div class="flex items-baseline justify-between gap-2 mb-2 px-1">
          <h2 id="docs-heading" class="wm-meta font-medium uppercase tracking-wide">Documents you agreed to</h2>
          <router-link to="/legal" class="wm-link text-[13px] font-medium">Read all</router-link>
        </div>
        <p v-if="mine.pending.length" class="wm-notice wm-notice-warning mb-3" role="status">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
          <span>{{ mine.pending.length === 1 ? 'One document is' : `${mine.pending.length} documents are` }} waiting for you to agree. You’ll be asked when you continue using WorkMate.</span>
        </p>
        <ul v-if="latest.length" class="wm-card !p-0 divide-y divide-line overflow-hidden">
          <li v-for="a in latest" :key="a.version_id">
            <router-link :to="{ path: `/legal/${a.key}`, query: { version: a.version_id } }" class="flex items-center gap-3 min-h-[56px] px-4 py-2 hover:bg-white/60">
              <span class="flex-1 min-w-0">
                <span class="block text-[15px] font-medium text-ink">{{ a.title }}</span>
                <span class="block wm-meta">Version {{ a.version }} · agreed {{ formatDate(a.accepted_at) }} {{ METHODS[a.method] || '' }}</span>
              </span>
              <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
            </router-link>
          </li>
        </ul>
        <p v-else class="wm-card wm-meta">No agreements recorded on this account yet.</p>
      </section>

      <!-- Optional choices -->
      <section class="mt-6" aria-labelledby="choices-heading">
        <h2 id="choices-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Your choices</h2>
        <div class="wm-card flex items-start gap-3">
          <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
          <div class="min-w-0 flex-1">
            <p class="text-[15px] font-semibold text-ink" :id="marketingId">News and offers</p>
            <p class="wm-meta">Occasional news about WorkMate and offers. Off unless you turn it on. Messages about your account, requests and safety are always sent.</p>
            <p v-if="consentError" class="mt-1 text-[13px] text-[#B91C1C]" role="alert">{{ consentError }}</p>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="mine.consents.marketing"
            :aria-labelledby="marketingId"
            :disabled="savingConsent"
            class="relative h-7 w-12 shrink-0 rounded-full transition-colors"
            :class="mine.consents.marketing ? 'bg-primary' : 'bg-[#CBD5E1]'"
            @click="toggleMarketing"
          >
            <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="mine.consents.marketing ? 'translate-x-5' : ''"></span>
          </button>
        </div>
      </section>

      <!-- Device permissions -->
      <section class="mt-6" aria-labelledby="permissions-heading">
        <h2 id="permissions-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">On this device</h2>
        <div class="wm-card !py-1">
          <PermissionList />
        </div>
        <p class="wm-meta mt-2 px-1">Agreeing to the Privacy Policy doesn’t switch any of these on: your phone or browser always asks first. To turn one off, use your browser’s or phone’s settings for WorkMate.</p>
      </section>

      <!-- Privacy contact -->
      <section class="mt-6" aria-labelledby="privacy-contact-heading">
        <h2 id="privacy-contact-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Your information</h2>
        <div class="wm-card">
          <p class="text-[14px] text-ink-soft">To get a copy of your information, correct it, or ask us to delete it, contact our data protection officer as explained in the <router-link to="/legal/privacy" class="wm-link font-medium">Privacy Policy</router-link>.</p>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, useId } from 'vue';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import PermissionList from '@/components/PermissionList.vue';
import { fetchMyLegal, setConsent } from '@/stores/legal.js';

const METHODS = { sign_up: 'when you signed up', prompt: '', provider_application: 'when you applied as a provider' };

const marketingId = useId();
const mine = ref(null);
const loading = ref(true);
const error = ref('');

// The newest agreement for each document
const latest = computed(() => {
  const seen = new Set();
  return (mine.value?.accepted || []).filter((a) => (seen.has(a.key) ? false : seen.add(a.key)));
});

const formatDate = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

async function load() {
  loading.value = true;
  error.value = '';
  try {
    mine.value = await fetchMyLegal();
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const savingConsent = ref(false);
const consentError = ref('');
async function toggleMarketing() {
  savingConsent.value = true;
  consentError.value = '';
  try {
    mine.value.consents = await setConsent('marketing', !mine.value.consents.marketing);
  } catch (e) {
    consentError.value = e.message || 'Your choice didn’t save. Try again.';
  } finally {
    savingConsent.value = false;
  }
}
</script>
