<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-[150] overflow-y-auto overscroll-contain bg-gradient-to-b from-[#EAF3FF] via-white to-white"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="headingId"
    >
      <div class="mx-auto flex min-h-full w-full max-w-lg flex-col px-5 pt-[calc(24px+env(safe-area-inset-top,0px))] pb-[calc(24px+var(--wm-safe-bottom,0px))]">
        <div class="flex items-center justify-between gap-3">
          <AppLogo />
          <span v-if="steps.length > 1" class="wm-meta">Step {{ stepIndex + 1 }} of {{ steps.length }}</span>
        </div>

        <!-- 1. Welcome (first opening on this device) -->
        <section v-if="step === 'welcome'" class="mt-8 flex flex-1 flex-col">
          <h1 :id="headingId" ref="heading" tabindex="-1" class="wm-h1 focus:outline-none">Welcome to WorkMate</h1>
          <p class="wm-lead mt-2">Find trusted people for the work you need done, or get hired for your skills, close to home in Uganda.</p>
          <ul class="mt-6 flex flex-col gap-4">
            <li class="flex items-start gap-3">
              <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-search"></i></span>
              <span><span class="block font-semibold text-ink">Find providers near you</span><span class="wm-meta">Compare profiles, reviews and the verification tick, then message or send a request.</span></span>
            </li>
            <li class="flex items-start gap-3">
              <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
              <span><span class="block font-semibold text-ink">You pay the provider directly</span><span class="wm-meta">WorkMate never collects or holds money for jobs. Agree the price before work begins.</span></span>
            </li>
            <li class="flex items-start gap-3">
              <span class="wm-icon-tile shrink-0" aria-hidden="true"><i class="bi bi-tools"></i></span>
              <span><span class="block font-semibold text-ink">Offer your own skills</span><span class="wm-meta">Apply as a provider, get verified and receive requests from customers.</span></span>
            </li>
          </ul>
          <div class="mt-auto pt-8">
            <button type="button" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block" @click="next">Get started</button>
          </div>
        </section>

        <!-- 2. Terms and Privacy -->
        <section v-else-if="step === 'terms'" class="mt-8 flex flex-1 flex-col">
          <h1 :id="headingId" ref="heading" tabindex="-1" class="wm-h1 focus:outline-none">{{ isUpdate ? 'We’ve updated our terms' : 'Before you start' }}</h1>
          <p class="wm-lead mt-2">
            {{ isUpdate ? 'Please read what changed and agree to continue using WorkMate.' : 'Please read and agree to how WorkMate works. Tap a document to read it in full.' }}
          </p>

          <ul class="mt-5 flex flex-col gap-3">
            <li v-for="d in mainDocuments" :key="d.key" class="wm-card !p-4">
              <p class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-[15px] font-semibold text-ink">{{ d.title }}</span>
                <span class="wm-meta">Version {{ d.version }}</span>
              </p>
              <p class="mt-1 text-[14px] text-ink-soft">{{ isUpdate && d.change_note ? d.change_note : d.summary }}</p>
              <router-link :to="`/legal/${d.key}`" class="wm-link mt-2 inline-flex min-h-11 items-center gap-1 text-[14px] font-medium">Read the {{ shortName(d) }}<i class="bi bi-chevron-right" aria-hidden="true"></i></router-link>
            </li>
          </ul>

          <div v-if="otherDocuments.length" class="mt-4">
            <p class="wm-meta font-medium">{{ mainDocuments.length ? 'These are part of the agreement too:' : 'Please read:' }}</p>
            <ul class="mt-1 flex flex-col">
              <li v-for="d in otherDocuments" :key="d.key">
                <router-link :to="`/legal/${d.key}`" class="wm-link inline-flex min-h-11 items-center gap-1 text-[14px]">
                  {{ d.title }}<span v-if="isUpdate && d.change_note" class="text-muted"> · {{ d.change_note }}</span>
                </router-link>
              </li>
            </ul>
          </div>

          <label class="mt-5 flex items-start gap-3 rounded-2xl border border-line bg-white p-4">
            <input v-model="agreed" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#1769D2]" />
            <span class="text-[14px] text-ink">
              I am {{ appSettings.minimumAge }} or older. I agree to the {{ agreementNames }}, and I have read the Privacy Policy.
            </span>
          </label>

          <p v-if="error" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>

          <div class="mt-auto flex flex-col gap-2 pt-6">
            <button type="button" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block" :disabled="!agreed || busy" @click="agree">
              {{ busy ? 'Saving…' : 'I agree' }}
            </button>
            <button v-if="auth.user" type="button" class="wm-btn wm-btn-ghost wm-btn-block" :disabled="busy" @click="leave">Not now, sign me out</button>
          </div>
        </section>

        <!-- 3. Device permissions -->
        <section v-else-if="step === 'permissions'" class="mt-8 flex flex-1 flex-col">
          <h1 :id="headingId" ref="heading" tabindex="-1" class="wm-h1 focus:outline-none">Allow what helps you</h1>
          <p class="wm-lead mt-2">Each one is optional. Your phone asks you to confirm, and you can change your mind later in Account → Legal and privacy.</p>
          <div class="wm-card mt-5 !py-1">
            <PermissionList />
          </div>
          <div class="mt-auto pt-6">
            <button type="button" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block" @click="finish">Continue to WorkMate</button>
          </div>
        </section>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, useId } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppLogo from './AppLogo.vue';
import PermissionList from './PermissionList.vue';
import { auth, loadSession, signOut } from '@/stores/auth.js';
import { appSettings } from '@/stores/catalog.js';
import { refreshPush } from '@/stores/push.js';
import {
  legal, loadLegalDocuments, guestPending, agreeOnThisDevice,
  permissionsAsked, markPermissionsAsked, acceptDocuments, legalGate,
} from '@/stores/legal.js';

/**
 * Shown over the app until the legal documents are agreed to:
 * - first opening on a device: welcome → Terms and Privacy → permissions
 * - signed in with new or materially changed documents: agree to continue
 * Reading a document (/legal/…) shows that page; coming back returns here.
 */
const route = useRoute();
const router = useRouter();
const headingId = useId();
const heading = ref(null);

const checked = ref(false);
const flow = ref(null);        // { steps, documents, isUpdate } while showing
const stepIndex = ref(0);
const agreed = ref(false);
const busy = ref(false);
const error = ref('');

const step = computed(() => flow.value?.steps[stepIndex.value] || null);
const steps = computed(() => flow.value?.steps || []);
const isUpdate = computed(() => !!flow.value?.isUpdate);
const documents = computed(() => flow.value?.documents || []);

const MAIN = ['terms', 'privacy', 'provider'];
const mainDocuments = computed(() => documents.value.filter(d => MAIN.includes(d.key)));
const otherDocuments = computed(() => documents.value.filter(d => !MAIN.includes(d.key)));
const agreementNames = computed(() => {
  const names = documents.value.filter(d => d.key !== 'privacy').map(d => d.title.replace(/^WorkMate /, ''));
  if (!names.length) return 'updated documents';
  return names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
});

const shortName = (d) => (d.key === 'terms' ? 'Terms' : d.key === 'privacy' ? 'Privacy Policy' : d.title);

// Out of the way while reading a document, and in the admin area
const show = computed(() => !!flow.value && !route.path.startsWith('/legal') && route.meta.layout !== 'admin');

function decide() {
  if (busy.value) return;
  if (!appSettings.askLegal || !legal.loaded || !['ready', 'unavailable'].includes(auth.status)) {
    flow.value = null;
    return;
  }
  // Already part-way through: keep going
  if (flow.value && flow.value.account === !!auth.user) return;

  if (auth.user) {
    const pending = auth.user.legal_pending || [];
    if (!pending.length) { flow.value = null; return; }
    const update = pending.every(d => d.is_update);
    flow.value = { account: true, isUpdate: update, documents: pending, steps: permissionsAsked() ? ['terms'] : ['terms', 'permissions'] };
  } else {
    const pending = guestPending();
    if (!pending.length) { flow.value = null; return; }
    const firstTime = pending.length === legal.documents.filter(d => d.audience === 'everyone').length && !permissionsAsked();
    flow.value = {
      account: false,
      isUpdate: !firstTime,
      documents: pending,
      steps: firstTime ? ['welcome', 'terms', 'permissions'] : ['terms'],
    };
  }
  stepIndex.value = 0;
  agreed.value = false;
  error.value = '';
}

async function focusHeading() {
  await nextTick();
  heading.value?.focus?.();
}

function next() {
  if (stepIndex.value < steps.value.length - 1) {
    stepIndex.value += 1;
    agreed.value = false;
    focusHeading();
  } else {
    finish();
  }
}

async function agree() {
  busy.value = true;
  error.value = '';
  try {
    if (auth.user) {
      await acceptDocuments(documents.value.map(d => d.version_id));
    }
    // Remembered on this device too, so signing out doesn't ask again
    agreeOnThisDevice(documents.value.filter(d => d.audience === 'everyone'));
    busy.value = false;
    next();
  } catch (e) {
    busy.value = false;
    error.value = e.message || 'That didn’t save. Check your connection and try again.';
    // A document changed meanwhile: show the latest
    if (e.code === 'legal_version_not_current') {
      await loadSession({ force: true });
      flow.value = null;
      decide();
    }
  }
}

function finish() {
  if (steps.value.includes('permissions')) markPermissionsAsked();
  flow.value = null;
  document.documentElement.style.overflow = '';
}

async function leave() {
  await signOut().catch(() => {});
  flow.value = null;
  router.push('/');
}

watch(show, (open) => {
  legalGate.open = open;
  document.documentElement.style.overflow = open ? 'hidden' : '';
  if (open) focusHeading();
});

watch(() => [auth.status, auth.user?.id, (auth.user?.legal_pending || []).length, legal.loaded, appSettings.askLegal], () => {
  if (flow.value && flow.value.account !== !!auth.user) flow.value = null;
  decide();
});

// A device allowed notifications before signing in: link it to the account
watch(() => auth.user?.id, (id) => { if (id) refreshPush(); });

onMounted(async () => {
  await Promise.allSettled([loadSession(), loadLegalDocuments()]);
  checked.value = true;
  decide();
});
</script>
