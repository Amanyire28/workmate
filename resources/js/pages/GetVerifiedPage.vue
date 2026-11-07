<template>
  <!-- Also used inside Start earning (embedded): just the documents, no title or Send -->
  <div :class="embedded ? '' : 'wm-container wm-container-sm wm-page'">
    <template v-if="!embedded">
      <BackLink to="/account">Account</BackLink>
      <h1 class="wm-h1 mt-1">{{ state && state.status !== 'draft' ? 'Your verification' : 'Get verified' }}</h1>
    </template>

    <div v-if="loading" class="mt-5 flex items-center gap-2 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Loading your documents…
    </div>

    <p v-else-if="loadError" class="wm-notice wm-notice-error mt-4" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ loadError }} <button type="button" class="wm-link" @click="load">Try again</button></span>
    </p>

    <!-- Documents belong to a provider profile -->
    <section v-else-if="!state" class="wm-card mt-5">
      <h2 class="wm-h3">Add your service details first</h2>
      <p class="mt-1 text-sm text-muted">Tell WorkMate which skills you offer, then come back here to add your documents.</p>
      <router-link to="/account" class="wm-btn wm-btn-primary mt-4">Go to my service details</router-link>
    </section>

    <template v-else>
      <p v-if="embedded && state.decision_reason" class="wm-notice wm-notice-warning mt-1">
        <i class="bi bi-chat-left-text" aria-hidden="true"></i><span><strong class="font-semibold">WorkMate asked:</strong> {{ state.decision_reason }}</span>
      </p>

      <!-- Where it stands -->
      <section v-if="!embedded" class="wm-card mt-4" aria-labelledby="status-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 id="status-heading" class="text-lg font-semibold text-ink">{{ statusInfo.label }}</h2>
            <p class="mt-1 text-sm text-muted">{{ statusInfo.detail }}</p>
          </div>
          <span :class="['wm-pill', `wm-pill-${statusInfo.tone}`]">{{ statusInfo.label }}</span>
        </div>
        <p v-if="state.decision_reason" class="wm-notice wm-notice-warning mt-3">
          <i class="bi bi-chat-left-text" aria-hidden="true"></i><span>{{ state.decision_reason }}</span>
        </p>
      </section>

      <p v-if="message" class="wm-notice wm-notice-success mt-4" role="status">
        <i class="bi bi-check2-circle" aria-hidden="true"></i><span>{{ message }}</span>
      </p>
      <p v-if="error" class="wm-notice wm-notice-error mt-4" role="alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span>
      </p>

      <!-- Documents a reviewer asked for -->
      <section v-if="state.requests.length" class="mt-5" aria-labelledby="requests-heading">
        <h2 id="requests-heading" class="wm-h3 mb-2">WorkMate asked for</h2>
        <ul class="flex flex-col gap-3">
          <li v-for="r in state.requests" :key="r.id" class="wm-card !border-[#FCD34D]">
            <p class="text-[15px] font-semibold text-ink">{{ r.label }}</p>
            <p v-if="r.instructions" class="mt-1 text-sm text-muted">{{ r.instructions }}</p>
            <FileChooser :key="formKey" class="mt-3" :id="`request-${r.id}`" multiple v-model="drafts[`request-${r.id}`]" />
            <PrimaryButton class="mt-3" :loading="busy === `request-${r.id}`" :disabled="!!busy || !drafts[`request-${r.id}`]?.length"
              @click="upload({ kind: 'requested', request: r.id }, { files: drafts[`request-${r.id}`] }, `request-${r.id}`)">
              Send this document
            </PrimaryButton>
          </li>
        </ul>
      </section>

      <!-- National ID -->
      <section class="mt-5" aria-labelledby="id-heading">
        <h2 id="id-heading" class="wm-h3">National ID <span class="text-error" aria-hidden="true">*</span></h2>
        <p class="wm-meta mb-2">Clear photos of the front and back, with all four corners visible.</p>

        <DocumentCard v-if="nationalId" :doc="nationalId" :file-url="fileUrl">
          <template v-if="canReplace(nationalId)">
            <IdChooser :key="formKey" v-model="drafts.idReplace" />
            <PrimaryButton class="mt-3" :loading="busy === 'id-replace'" :disabled="!!busy || !idComplete(drafts.idReplace)"
              @click="replace(nationalId, { front: drafts.idReplace.front, back: drafts.idReplace.back }, 'id-replace')">
              Send new photos
            </PrimaryButton>
          </template>
          <template #actions>
            <button v-if="offerReplace(nationalId)" type="button" class="wm-link min-h-11 text-sm" @click="replacing[nationalId.id] = true">Replace</button>
          </template>
        </DocumentCard>

        <div v-else class="wm-card">
          <IdChooser :key="formKey" v-model="drafts.id" />
          <FormField v-model="drafts.idExpiry" class="mt-3" type="date" label="Expiry date" optional :min="tomorrow" />
          <PrimaryButton class="mt-3" :loading="busy === 'id'" :disabled="!!busy || !state.can_edit || !idComplete(drafts.id)"
            @click="upload({ kind: 'national_id', expires_on: drafts.idExpiry || null }, { front: drafts.id.front, back: drafts.id.back }, 'id')">
            Add my ID
          </PrimaryButton>
        </div>
      </section>

      <!-- What their main profession needs -->
      <section v-if="rules" class="mt-6" aria-labelledby="profession-heading">
        <h2 id="profession-heading" class="wm-h3">Documents for {{ rules.profession }}</h2>
        <p class="wm-meta mb-2">What WorkMate checks for providers whose main skill is in {{ rules.profession }}.</p>

        <fieldset v-for="q in rules.questions" :key="q.key" class="wm-card mb-3" :disabled="!state.can_edit || !!busy">
          <legend class="sr-only">{{ q.question }}</legend>
          <p class="text-[15px] font-medium text-ink">{{ q.question }}</p>
          <div class="mt-2 flex gap-4">
            <label v-for="opt in [{ v: true, t: 'Yes' }, { v: false, t: 'No' }]" :key="opt.t" class="flex min-h-11 items-center gap-2 text-sm text-ink">
              <input type="radio" class="wm-check" :name="`q-${q.key}`" :checked="q.answer === opt.v" @change="answer(q.key, opt.v)" />{{ opt.t }}
            </label>
          </div>
        </fieldset>

        <ul class="flex flex-col gap-3">
          <li v-for="r in rules.documents" :key="r.key" class="wm-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <p class="text-[15px] font-semibold text-ink">{{ r.name }}<span v-if="r.required" class="text-error" aria-hidden="true"> *</span></p>
              <span :class="['wm-pill', r.required ? 'wm-pill-info' : 'wm-pill-neutral']">{{ r.required ? 'Required' : 'Optional' }}</span>
            </div>
            <p v-if="r.instructions" class="mt-1 text-sm text-muted">{{ r.instructions }}</p>
            <p class="wm-meta mt-1">{{ ruleHint(r) }}</p>

            <ul v-if="professionDocs(r.key).length" class="mt-3 flex flex-col gap-3">
              <li v-for="d in professionDocs(r.key)" :key="d.id">
                <DocumentCard :doc="d" :file-url="fileUrl">
                  <template v-if="canReplace(d)">
                    <IdChooser v-if="r.front_back" :key="formKey" :prefix="`replace-${d.id}`" :formats="r.formats" :max-mb="r.max_size_mb" v-model="drafts[`replace-${d.id}`]" />
                    <FileChooser v-else :key="formKey" :id="`replace-${d.id}`" :multiple="r.multi_page" :formats="r.formats" :max-mb="r.max_size_mb" v-model="drafts[`replace-${d.id}`]" />
                    <FormField v-if="r.expiry_required" v-model="drafts[`exp-replace-${d.id}`]" class="mt-3" type="date" label="Expiry date" :min="validFrom(r)" />
                    <PrimaryButton class="mt-3" :loading="busy === `replace-${d.id}`" :disabled="!!busy || !ready(r, drafts[`replace-${d.id}`], drafts[`exp-replace-${d.id}`])"
                      @click="replace(d, filesFor(r, drafts[`replace-${d.id}`]), `replace-${d.id}`, { expires_on: drafts[`exp-replace-${d.id}`] || null })">
                      Send replacement
                    </PrimaryButton>
                  </template>
                  <template #actions>
                    <button v-if="offerReplace(d)" type="button" class="wm-link min-h-11 px-2 text-sm" @click="replacing[d.id] = true">Replace</button>
                    <button v-if="state.can_edit && d.status !== 'accepted'" type="button" class="wm-link min-h-11 px-2 text-sm text-error" :disabled="!!busy" @click="remove(d)">Remove</button>
                  </template>
                </DocumentCard>
              </li>
            </ul>

            <div v-if="state.can_edit && r.added < r.count" class="mt-3 flex flex-col gap-3">
              <IdChooser v-if="r.front_back" :key="formKey" :prefix="`prof-${r.key}`" :formats="r.formats" :max-mb="r.max_size_mb" v-model="drafts[`prof-${r.key}`]" />
              <FileChooser v-else :key="formKey" :id="`prof-${r.key}`" :multiple="r.multi_page" :formats="r.formats" :max-mb="r.max_size_mb" v-model="drafts[`prof-${r.key}`]" />
              <FormField v-if="r.expiry_required" v-model="drafts[`exp-${r.key}`]" type="date" label="Expiry date" :min="validFrom(r)" />
              <PrimaryButton :variant="r.required ? 'primary' : 'secondary'" :loading="busy === `prof-${r.key}`" :disabled="!!busy || !ready(r, drafts[`prof-${r.key}`], drafts[`exp-${r.key}`])"
                @click="upload({ kind: 'profession', requirement: r.key, expires_on: drafts[`exp-${r.key}`] || null }, filesFor(r, drafts[`prof-${r.key}`]), `prof-${r.key}`)">
                Add {{ r.count > 1 && r.added ? 'another' : r.name.toLowerCase() }}
              </PrimaryButton>
            </div>
          </li>
        </ul>

        <!-- Added under an earlier answer or version; no longer needed -->
        <ul v-if="staleDocs.length" class="mt-3 flex flex-col gap-3">
          <li v-for="d in staleDocs" :key="d.id">
            <DocumentCard :doc="d" :file-url="fileUrl">
              <template #actions>
                <button v-if="state.can_edit && d.status !== 'accepted'" type="button" class="wm-link min-h-11 px-2 text-sm text-error" :disabled="!!busy" @click="remove(d)">Remove (no longer needed)</button>
              </template>
            </DocumentCard>
          </li>
        </ul>
      </section>

      <!-- Supporting documents -->
      <section class="mt-6" aria-labelledby="supporting-heading">
        <h2 id="supporting-heading" class="wm-h3">Supporting documents <span class="font-normal text-muted text-sm">(optional, up to {{ state.max_supporting }})</span></h2>
        <p class="wm-meta mb-2">Anything that shows your skills or that you can be trusted: a trade certificate, permit, recommendation letter or business registration.</p>

        <ul v-if="supporting.length" class="flex flex-col gap-3">
          <li v-for="d in supporting" :key="d.id">
            <DocumentCard :doc="d" :file-url="fileUrl">
              <template v-if="canReplace(d)">
                <FileChooser :key="formKey" :id="`replace-${d.id}`" multiple v-model="drafts[`replace-${d.id}`]" />
                <PrimaryButton class="mt-3" :loading="busy === `replace-${d.id}`" :disabled="!!busy || !drafts[`replace-${d.id}`]?.length"
                  @click="replace(d, { files: drafts[`replace-${d.id}`] }, `replace-${d.id}`)">
                  Send replacement
                </PrimaryButton>
              </template>
              <template #actions>
                <button v-if="offerReplace(d)" type="button" class="wm-link min-h-11 px-2 text-sm" @click="replacing[d.id] = true">Replace</button>
                <button v-if="state.can_edit && d.status !== 'accepted'" type="button" class="wm-link min-h-11 px-2 text-sm text-error" :disabled="!!busy" @click="remove(d)">Remove</button>
              </template>
            </DocumentCard>
          </li>
        </ul>

        <div v-if="state.can_edit && supporting.length < state.max_supporting" class="wm-card mt-3 flex flex-col gap-3">
          <FormField v-model="drafts.label" label="What is this document?" placeholder="e.g. Plumbing trade certificate" maxlength="80" />
          <FileChooser :key="formKey" id="supporting-new" multiple v-model="drafts.supporting" />
          <PrimaryButton variant="secondary" :loading="busy === 'supporting'" :disabled="!!busy || drafts.label.trim().length < 3 || !drafts.supporting?.length"
            @click="upload({ kind: 'supporting', label: drafts.label.trim() }, { files: drafts.supporting }, 'supporting')">
            Add document
          </PrimaryButton>
        </div>
      </section>

      <!-- Send for review -->
      <section v-if="state.can_submit && !embedded" class="mt-6 flex flex-col gap-3" aria-labelledby="send-heading">
        <h2 id="send-heading" class="sr-only">Send for review</h2>
        <label class="flex items-start gap-3 text-sm text-ink">
          <input v-model="confirmed" type="checkbox" class="wm-check mt-0.5" />
          <span>These documents are mine, current and genuine. I understand WorkMate checks them before approving them.</span>
        </label>
        <PrimaryButton :loading="busy === 'submit'" :disabled="!!busy || !confirmed || !nationalId || !emailVerified || !!stillNeeded" @click="submit">
          {{ state.status === 'info_requested' ? 'Send my changes for review' : 'Send for review' }}
        </PrimaryButton>
        <p v-if="!nationalId" class="wm-meta text-center">Add your National ID first.</p>
        <p v-else-if="stillNeeded" class="wm-meta text-center">{{ stillNeeded }}</p>
        <p v-else-if="!emailVerified" class="wm-meta text-center">Confirm your email address first: use the link we sent you.</p>
      </section>

      <p class="mt-6 flex items-start gap-2 text-[12.5px] text-muted">
        <i class="bi bi-lock mt-0.5" aria-hidden="true"></i>
        <span>Your documents are stored privately. Only you and the WorkMate reviewers who check them can open them, and every time a reviewer opens one it’s recorded.</span>
      </p>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, h, Comment } from 'vue';
import BackLink from '@/components/BackLink.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { VERIFICATION_STATUS, DOCUMENT_STATUS } from '@/utils/verificationStatus.js';

const props = defineProps({
  // Inside Start earning: the wizard shows the title and does the sending
  embedded: { type: Boolean, default: false },
});
const emit = defineEmits(['update']);

const MAX_MB = 8;

const loading = ref(true);
const loadError = ref('');
const state = ref(null);
const busy = ref('');
const error = ref('');
const message = ref('');
const confirmed = ref(false);
// Bumped after each upload so the file inputs start empty again
const formKey = ref(0);
const drafts = reactive({ id: {}, idReplace: {}, idExpiry: '', label: '', supporting: [] });

const emailVerified = computed(() => !!auth.user?.email_verified);
const statusInfo = computed(() => VERIFICATION_STATUS[state.value?.status] || VERIFICATION_STATUS.draft);
const nationalId = computed(() => state.value?.documents.find(d => d.kind === 'national_id') || null);
const supporting = computed(() => state.value?.documents.filter(d => d.kind === 'supporting' || d.kind === 'requested') || []);
const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

/* ---------------- The documents their profession needs ---------------- */
const rules = computed(() => state.value?.requirements || null);
const professionDocs = (key) => state.value.documents.filter(d => d.kind === 'profession' && d.requirement === key);
const staleDocs = computed(() => (state.value?.documents || []).filter(d => d.kind === 'profession' && !rules.value?.documents.some(r => r.key === d.requirement)));
const stillNeeded = computed(() => {
  if (!rules.value) return '';
  const q = rules.value.questions.find(x => x.answer === null);
  if (q) return `Answer “${q.question}” first.`;
  return rules.value.missing.length ? `Add your ${rules.value.missing.join(', ')} first.` : '';
});
// The earliest expiry date the profession accepts
function validFrom(r) {
  if (!r.min_months_valid) return tomorrow;
  const d = new Date();
  d.setMonth(d.getMonth() + r.min_months_valid);
  return d.toISOString().slice(0, 10);
}
function ruleHint(r) {
  const parts = [r.formats.map(f => f.toUpperCase()).join(', '), `up to ${r.max_size_mb} MB`];
  if (r.front_back) parts.push('front and back');
  else if (r.multi_page) parts.push('all pages');
  if (r.expiry_required) parts.push(r.min_months_valid ? `valid for at least ${r.min_months_valid} ${r.min_months_valid === 1 ? 'month' : 'months'}` : 'with its expiry date');
  if (r.count > 1) parts.push(`${r.added} of ${r.count} added`);
  return parts.join(' · ');
}
const filesFor = (r, draft) => (r.front_back ? { front: draft?.front, back: draft?.back } : { files: draft });
const ready = (r, draft, expiry) => (r.front_back ? idComplete(draft) : !!draft?.length) && (!r.expiry_required || !!expiry);

function answer(key, value) {
  run(`answer-${key}`, () => api('/me/verification/answers', { method: 'PUT', body: { [key]: value } }), 'Answer saved.');
}

const fileUrl = (doc, index) => `/api/v1/me/verification/documents/${doc.id}/files/${index}`;
const idComplete = (d) => !!(d?.front && d?.back);
// Sent back by a reviewer, or the provider chose to replace one not reviewed yet
const replacing = reactive({});
const canReplace = (d) => d.status === 'replacement_required' || (replacing[d.id] && state.value.can_edit && d.status === 'awaiting_review');
const offerReplace = (d) => state.value.can_edit && d.status === 'awaiting_review' && !replacing[d.id];

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    state.value = (await api('/me/verification')).data;
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

function formData(fields, files) {
  const body = new FormData();
  for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined) body.append(k, v);
  if (files.front) body.append('front', files.front);
  if (files.back) body.append('back', files.back);
  for (const f of files.files || []) body.append('files[]', f);
  return body;
}

async function run(key, request, done) {
  busy.value = key;
  error.value = '';
  message.value = '';
  try {
    state.value = (await request()).data;
    message.value = done;
    formKey.value++;
    return true;
  } catch (e) {
    const first = Object.values(fieldErrors(e))[0];
    error.value = first || e.message;
    return false;
  } finally {
    busy.value = '';
  }
}

async function upload(fields, files, key) {
  const ok = await run(key, () => api('/me/verification/documents', { method: 'POST', body: formData(fields, files) }), 'Document added.');
  if (!ok) return;
  if (key === 'id') { drafts.id = {}; drafts.idExpiry = ''; }
  if (key === 'supporting') { drafts.label = ''; drafts.supporting = []; }
  delete drafts[key];
  delete drafts[key.replace(/^prof-/, 'exp-')];
}

async function replace(doc, files, key, fields = {}) {
  const ok = await run(key, () => api(`/me/verification/documents/${doc.id}/replace`, { method: 'POST', body: formData(fields, files) }), 'Replacement sent.');
  if (!ok) return;
  if (key === 'id-replace') drafts.idReplace = {};
  else { delete drafts[key]; delete drafts[`exp-${key}`]; }
}

function remove(doc) {
  run(`remove-${doc.id}`, () => api(`/me/verification/documents/${doc.id}`, { method: 'DELETE' }), 'Document removed.');
}

async function submit() {
  const ok = await run('submit', () => api('/me/verification/submit', { method: 'POST', body: { confirm: true } }), 'Sent. A WorkMate reviewer will check each document.');
  if (ok) confirmed.value = false;
}

onMounted(load);
// Start earning needs to know when the ID and required documents are in
watch(state, (s) => emit('update', s));

/* ---------------- Small pieces ---------------- */

const ALL_FORMATS = ['jpg', 'png', 'pdf'];
const EXTENSIONS = { jpg: ['jpg', 'jpeg'], png: ['png'], pdf: ['pdf'] };
const acceptFor = (formats) => formats.flatMap(f => EXTENSIONS[f].map(x => `.${x}`)).join(',');
const formatNames = (formats) => formats.map(f => f.toUpperCase()).join(', ').replace(/, ([^,]*)$/, ' or $1');

function checkFiles(list, formats = ALL_FORMATS, maxMb = MAX_MB) {
  const allowed = formats.flatMap(f => EXTENSIONS[f]);
  for (const f of list) {
    if (f.size > maxMb * 1024 * 1024) return `${f.name} is over ${maxMb} MB.`;
    if (!allowed.includes((f.name.split('.').pop() || '').toLowerCase())) return `${f.name} isn’t a ${formatNames(formats)} file.`;
  }
  return '';
}

/** File input with a list of what's chosen (up to 4 pages) */
const FileChooser = {
  props: {
    modelValue: { type: Array, default: () => [] }, id: String, multiple: Boolean, label: { type: String, default: 'Files' },
    formats: { type: Array, default: () => ALL_FORMATS }, maxMb: { type: Number, default: MAX_MB },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const problem = ref('');
    const onChange = (e) => {
      const list = [...e.target.files].slice(0, props.multiple ? 4 : 1);
      problem.value = checkFiles(list, props.formats, props.maxMb);
      emit('update:modelValue', problem.value ? [] : list);
    };
    return () => h('div', { class: 'flex flex-col gap-1.5' }, [
      h('label', { class: 'wm-label', for: props.id }, [props.label, h('span', { class: 'font-normal text-muted' }, ` (${formatNames(props.formats)}, up to ${props.maxMb} MB${props.multiple ? ', up to 4 pages' : ''})`)]),
      h('input', { id: props.id, type: 'file', accept: acceptFor(props.formats), multiple: props.multiple, class: 'wm-input !py-2', onChange }),
      problem.value ? h('p', { class: 'wm-field-error', role: 'alert' }, problem.value) : null,
      props.modelValue?.length ? h('p', { class: 'wm-meta' }, props.modelValue.map(f => f.name).join(', ')) : null,
    ]);
  },
};

/** Front and back of the ID, or of another card such as a driving permit */
const IdChooser = {
  props: {
    modelValue: { type: Object, default: () => ({}) }, prefix: { type: String, default: 'id' },
    formats: { type: Array, default: () => ALL_FORMATS }, maxMb: { type: Number, default: MAX_MB },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const problem = ref('');
    const isId = props.prefix === 'id';
    const side = (key, text) => h('div', { class: 'flex flex-col gap-1.5' }, [
      h('label', { class: 'wm-label', for: `${props.prefix}-${key}` }, text),
      h('input', {
        id: `${props.prefix}-${key}`, type: 'file', accept: acceptFor(props.formats), class: 'wm-input !py-2',
        onChange: (e) => {
          const f = e.target.files[0];
          problem.value = f ? checkFiles([f], props.formats, props.maxMb) : '';
          emit('update:modelValue', { ...props.modelValue, [key]: f && !problem.value ? f : null });
        },
      }),
    ]);
    return () => h('div', { class: 'grid grid-cols-1 gap-3 sm:grid-cols-2' }, [
      side('front', isId ? 'Front of your ID' : 'Front'),
      side('back', isId ? 'Back of your ID' : 'Back'),
      problem.value ? h('p', { class: 'wm-field-error sm:col-span-2', role: 'alert' }, problem.value) : null,
    ]);
  },
};

// A slot that renders something other than v-if placeholders
const filled = (slot) => !!slot && slot().some(n => n.type !== Comment);

/** One uploaded document, its review state and its files */
const DocumentCard = {
  props: { doc: Object, fileUrl: Function },
  setup(props, { slots }) {
    return () => {
      const d = props.doc;
      const status = DOCUMENT_STATUS[d.status] || DOCUMENT_STATUS.awaiting_review;
      return h('div', { class: 'wm-card' }, [
        h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
          h('div', { class: 'min-w-0' }, [
            h('p', { class: 'text-[15px] font-semibold text-ink' }, d.label),
            h('p', { class: 'wm-meta flex flex-wrap gap-x-3' }, d.files.map(f => h('a', {
              href: props.fileUrl(d, f.index), target: '_blank', rel: 'noopener', class: 'wm-link',
            }, `${f.side ? `${f.side[0].toUpperCase()}${f.side.slice(1)}` : f.name} · ${f.size_kb} KB`))),
            d.expires_on ? h('p', { class: 'wm-meta' }, `Expires ${new Date(d.expires_on).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}`) : null,
          ]),
          h('span', { class: ['wm-pill', `wm-pill-${status.tone}`] }, status.label),
        ]),
        d.note ? h('p', { class: 'mt-2 text-sm text-[#92400E]' }, [h('i', { class: 'bi bi-arrow-return-right', 'aria-hidden': 'true' }), ` ${d.note}`]) : null,
        filled(slots.default) ? h('div', { class: 'mt-3' }, slots.default()) : null,
        filled(slots.actions) ? h('div', { class: 'mt-1 flex justify-end' }, slots.actions()) : null,
      ]);
    };
  },
};
</script>
