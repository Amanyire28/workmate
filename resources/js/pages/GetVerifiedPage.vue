<template>
  <div class="wm-container wm-container-sm wm-page">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">{{ application && !startingOver ? 'Your verification' : 'Get verified' }}</h1>

    <p class="wm-notice wm-notice-info mt-3">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Prototype: files you choose stay on this device and are never uploaded. Sign-in and secure storage aren’t connected yet.</span>
    </p>

    <!-- ===================== Existing application: status ===================== -->
    <template v-if="application && !startingOver">
      <p v-if="justSubmitted" class="wm-notice wm-notice-success mt-4" role="status">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>
        <span>Application submitted. Your reference is <strong>{{ application.id }}</strong>. Nothing is approved automatically: a reviewer checks each document first.</span>
      </p>
      <section class="wm-card mt-4" aria-labelledby="status-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="wm-meta">Application {{ application.id }} · {{ categoryLabel(application.profession) }}</p>
            <h2 id="status-heading" class="mt-1 text-lg font-semibold text-ink">{{ statusInfo.label }}</h2>
            <p class="mt-1 text-sm text-muted">{{ statusInfo.detail }}</p>
          </div>
          <span :class="['wm-pill', `wm-pill-${statusInfo.tone}`]">{{ statusInfo.label }}</span>
        </div>
        <p v-if="application.decisionReason" class="wm-notice wm-notice-warning mt-3">
          <i class="bi bi-chat-left-text" aria-hidden="true"></i><span>{{ application.decisionReason }}</span>
        </p>
      </section>

      <section class="mt-4" aria-labelledby="docs-status-heading">
        <h2 id="docs-status-heading" class="wm-h3 mb-2">Documents</h2>
        <ul class="flex flex-col gap-3">
          <li v-for="d in application.documents" :key="d.reqId" class="wm-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-[15px] font-semibold text-ink">{{ d.name }}</p>
                <p class="wm-meta">{{ d.files.length ? d.files.map(f => f.name).join(', ') : 'No file' }}</p>
              </div>
              <span :class="['wm-pill', `wm-pill-${docStatus(d).tone}`]">{{ docStatus(d).label }}</span>
            </div>
            <p v-if="d.note" class="mt-2 text-sm text-[#92400E]"><i class="bi bi-arrow-return-right" aria-hidden="true"></i> {{ d.note }}</p>
            <!-- Replace a document the reviewer sent back -->
            <div v-if="d.status === 'rejected' || d.status === 'replacement_required'" class="mt-3">
              <DocumentUpload
                :doc="requirementFor(d.reqId, application)"
                :model-value="replacements[d.reqId]"
                @update:model-value="replacements[d.reqId] = $event"
              />
              <button
                type="button"
                class="wm-btn wm-btn-primary mt-3"
                :disabled="!uploadComplete(requirementFor(d.reqId, application), replacements[d.reqId])"
                @click="sendReplacement(d)"
              >Send replacement</button>
            </div>
          </li>
        </ul>
      </section>

      <button v-if="application.status === 'rejected'" type="button" class="wm-btn wm-btn-secondary mt-4" @click="startOver">Start a new application</button>
    </template>

    <!-- ===================== New application: guided steps ===================== -->
    <template v-else>
      <ol class="mt-5 grid grid-cols-4 gap-2" aria-label="Progress">
        <li v-for="(s, i) in steps" :key="s" class="flex flex-col gap-1.5">
          <span class="h-1.5 rounded-full" :class="i <= step ? 'bg-primary' : 'bg-[#E2E8F0]'" aria-hidden="true"></span>
          <span class="text-[12px] leading-tight" :class="i === step ? 'font-semibold text-ink' : 'text-muted'" :aria-current="i === step ? 'step' : undefined">{{ s }}</span>
        </li>
      </ol>

      <!-- Step 1: requirements -->
      <section v-if="step === 0" class="mt-5 flex flex-col gap-4" aria-labelledby="step1">
        <h2 id="step1" class="sr-only">Profession requirements</h2>
        <FormField v-model="profession" type="select" label="Your profession" id="gv-profession">
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
        </FormField>

        <fieldset v-for="key in conditionKeys" :key="key" class="wm-card">
          <legend class="text-[15px] font-medium text-ink">{{ CONDITIONS[key].question }}</legend>
          <div class="mt-2 flex gap-2">
            <label v-for="opt in [true, false]" :key="String(opt)" class="wm-chip cursor-pointer" :class="{ '!border-primary !bg-primary-tint': answers[key] === opt }">
              <input v-model="answers[key]" type="radio" :name="`cond-${key}`" :value="opt" class="sr-only" />{{ opt ? 'Yes' : 'No' }}
            </label>
          </div>
        </fieldset>

        <div class="wm-card">
          <h3 class="wm-h3">What you’ll need</h3>
          <p class="wm-meta">Requirements version {{ version.version }} for {{ categoryLabel(profession) }}.</p>
          <ul class="mt-3 flex flex-col divide-y divide-line">
            <li v-for="d in applicable" :key="d.id" class="py-2.5">
              <p class="flex flex-wrap items-center gap-2 text-[15px] font-medium text-ink">
                {{ d.name }}
                <span :class="['wm-pill wm-pill-plain', isRequired(d, answers) ? 'wm-pill-info' : 'wm-pill-neutral']">{{ isRequired(d, answers) ? 'Required' : 'Optional' }}</span>
              </p>
              <p class="mt-0.5 text-sm text-muted">{{ d.instructions }}</p>
              <p class="mt-0.5 text-[12.5px] text-muted">{{ formatRules(d) }}</p>
            </li>
          </ul>
        </div>

        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg" :disabled="conditionKeys.some(k => answers[k] == null)" @click="step = 1">
          Continue to documents
        </button>
        <p v-if="conditionKeys.some(k => answers[k] == null)" class="-mt-2 text-sm text-muted">Answer the questions above to continue.</p>
      </section>

      <!-- Step 2: uploads -->
      <section v-else-if="step === 1" class="mt-5 flex flex-col gap-3" aria-labelledby="step2">
        <h2 id="step2" class="wm-h2">Upload your documents</h2>
        <div v-for="d in applicable" :key="d.id" class="wm-card">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-[15px] font-semibold text-ink">{{ d.name }}</p>
            <span :class="['wm-pill', isRequired(d, answers) ? (uploadComplete(d, uploads[d.id]) ? 'wm-pill-success' : 'wm-pill-warning') : 'wm-pill-neutral']">
              {{ uploads[d.id]?.files.length ? 'Uploaded' : 'Not uploaded' }}{{ isRequired(d, answers) ? '' : ' · optional' }}
            </span>
          </div>
          <p class="mt-1 text-sm text-muted">{{ d.instructions }}</p>
          <DocumentUpload class="mt-3" :doc="d" :model-value="uploads[d.id]" @update:model-value="uploads[d.id] = $event" />
        </div>

        <p v-if="showMissing && missing.length" class="wm-notice wm-notice-error" role="alert">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>Still needed: {{ missing.map(d => d.name).join(', ') }}.</span>
        </p>
        <div class="flex gap-2">
          <button type="button" class="wm-btn wm-btn-secondary" @click="step = 0">Back</button>
          <button type="button" class="wm-btn wm-btn-primary flex-1" @click="toReview">Review application</button>
        </div>
      </section>

      <!-- Step 3: review -->
      <section v-else-if="step === 2" class="mt-5 flex flex-col gap-3" aria-labelledby="step3">
        <h2 id="step3" class="wm-h2">Check and confirm</h2>
        <div class="wm-card">
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted">Profession</dt><dd class="text-ink">{{ categoryLabel(profession) }}</dd>
            <dt class="text-muted">Name on profile</dt><dd class="text-ink">{{ providerName }}</dd>
            <template v-for="key in conditionKeys" :key="key">
              <dt class="text-muted">{{ CONDITIONS[key].label }}</dt><dd class="text-ink">{{ answers[key] ? 'Yes' : 'No' }}</dd>
            </template>
          </dl>
        </div>
        <ul class="wm-card !p-0 divide-y divide-line">
          <li v-for="d in applicable" :key="d.id" class="flex items-start justify-between gap-3 px-4 py-3">
            <span class="min-w-0">
              <span class="block text-[15px] font-medium text-ink">{{ d.name }}</span>
              <span class="block wm-meta truncate">{{ uploads[d.id]?.files.map(f => f.name).join(', ') || 'Not provided (optional)' }}</span>
              <span v-if="uploads[d.id]?.expiresAt" class="block wm-meta">Expires {{ uploads[d.id].expiresAt }}</span>
            </span>
            <button type="button" class="wm-link min-h-11 shrink-0" @click="step = 1">Change</button>
          </li>
        </ul>
        <label class="flex items-start gap-3 text-sm text-ink">
          <input v-model="confirmed" type="checkbox" class="wm-check mt-0.5" />
          <span>These documents are mine, current and genuine. I understand WorkMate reviews them before approving them.</span>
        </label>
        <p v-if="submitError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ submitError }}</span></p>
        <div class="flex gap-2">
          <button type="button" class="wm-btn wm-btn-secondary" @click="step = 1">Back</button>
          <button type="button" class="wm-btn wm-btn-primary flex-1" :disabled="!confirmed" @click="submit">Submit application</button>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onBeforeUnmount } from 'vue';
import BackLink from '@/components/BackLink.vue';
import FormField from '@/components/FormField.vue';
import DocumentUpload from '@/components/DocumentUpload.vue';
import { categories } from '@/stores/catalog.js';
import { CONDITIONS, applicableDocuments, isRequired } from '@/data/verificationRequirements.js';
import { verification, currentVersion, submitApplication, replaceDocument, uploadComplete } from '@/stores/verification.js';
import { VERIFICATION_STATUS, DOCUMENT_STATUS } from '@/utils/verificationStatus.js';
import { categoryLabel } from '@/utils/ui.js';

const steps = ['Requirements', 'Documents', 'Review', 'Submitted'];
const step = ref(0);
const profession = ref('driving');
const answers = reactive({});
const uploads = reactive({});
const confirmed = ref(false);
const showMissing = ref(false);
const submitError = ref('');
const startingOver = ref(false);
const justSubmitted = ref(false);
const providerName = 'Demo User';

const application = computed(() => verification.submissions[0] || null);
const statusInfo = computed(() => VERIFICATION_STATUS[application.value?.status] || VERIFICATION_STATUS.pending_review);
const docStatus = (d) => DOCUMENT_STATUS[d.status] || DOCUMENT_STATUS.not_uploaded;

const version = computed(() => currentVersion(profession.value));
const conditionKeys = computed(() => [...new Set(version.value.documents.filter(d => d.enabled && d.requirement === 'conditional').map(d => d.condition))]);
const applicable = computed(() => applicableDocuments(version.value, answers));
const missing = computed(() => applicable.value.filter(d => isRequired(d, answers) && !uploadComplete(d, uploads[d.id])));

// A different profession has different documents: start the checklist again
watch(profession, () => {
  for (const k of Object.keys(answers)) delete answers[k];
  for (const k of Object.keys(uploads)) delete uploads[k];
});

function formatRules(d) {
  const parts = [d.formats.map(f => f.toUpperCase()).join(', '), `up to ${d.maxSizeMb} MB`];
  if (d.frontBack) parts.push('front and back');
  if (d.multiPage) parts.push('all pages');
  if (d.expiry.required) parts.push(d.expiry.minMonthsValid ? `valid for ${d.expiry.minMonthsValid}+ months` : 'not expired');
  return parts.join(' · ');
}

function toReview() {
  showMissing.value = true;
  if (!missing.value.length) step.value = 2;
}

function submit() {
  submitError.value = '';
  try {
    submitApplication({ profession: profession.value, professionId: profession.value, providerName, area: 'Ntinda', answers: { ...answers }, uploads });
    startingOver.value = false;
    justSubmitted.value = true;
    step.value = 0;
  } catch (e) {
    submitError.value = e.message;
  }
}

/* Replacing a document the reviewer sent back */
const replacements = reactive({});
function requirementFor(reqId, app) {
  const v = verification.versions[app.profession]?.find(x => x.version === app.snapshot.version);
  return v?.documents.find(d => d.id === reqId);
}
function sendReplacement(d) {
  replaceDocument(application.value.id, d.reqId, replacements[d.reqId]);
  delete replacements[d.reqId];
}
function startOver() {
  startingOver.value = true;
  step.value = 0;
}

// Local previews only: release them when leaving (files in a submitted application keep theirs)
onBeforeUnmount(() => {
  for (const u of Object.values(uploads)) for (const f of u?.files || []) if (!verification.submissions.some(a => a.documents.some(doc => doc.files.some(x => x.url === f.url)))) URL.revokeObjectURL(f.url);
});
</script>
