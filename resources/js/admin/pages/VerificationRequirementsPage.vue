<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Document Requirements" description="Which documents each profession must provide for the WorkMate Verified badge." demo>
      <template v-if="canEdit" #actions>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" @click="previewOpen = true"><i class="bi bi-eye" aria-hidden="true"></i>Preview for providers</button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!changes.length" @click="resetDraft">Discard changes</button>
        <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!changes.length" @click="startPublish">Publish version {{ current.version + 1 }}</button>
      </template>
    </AdminPageHeader>

    <p class="wm-notice wm-notice-info">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Sample requirements, not legal ones: set what each profession genuinely needs. Publishing creates a new version for new applications; submitted applications keep the version they were made under.</span>
    </p>
    <p class="wm-notice wm-notice-warning">
      <i class="bi bi-plug" aria-hidden="true"></i>
      <span><strong class="font-semibold">Not connected yet.</strong> Document requirements aren’t stored by the server, so changes here are kept only until you reload.</span>
    </p>
    <p v-if="!canEdit" class="wm-notice wm-notice-info"><i class="bi bi-lock" aria-hidden="true"></i><span>Your role can view requirements but not change them.</span></p>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[260px_minmax(0,1fr)]">
      <!-- Professions -->
      <nav class="rounded-xl wm-surface p-2" aria-label="Professions">
        <ul class="flex gap-1 overflow-x-auto xl:flex-col">
          <li v-for="c in categories" :key="c.id" class="shrink-0">
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left"
              :class="profession === c.id ? 'bg-[#EFF6FF]' : 'hover:bg-[#F8FAFC]'"
              :aria-current="profession === c.id ? 'true' : undefined"
              @click="selectProfession(c.id)"
            >
              <span class="min-w-0 flex-1">
                <span class="block text-[13.5px] font-medium whitespace-nowrap" :class="profession === c.id ? 'text-primary' : 'text-ink'">{{ c.label }}</span>
                <span class="block text-[11.5px] text-[#64748B] whitespace-nowrap">v{{ currentVersion(c.id).version }} · {{ currentVersion(c.id).documents.filter(d => d.enabled).length }} documents</span>
              </span>
            </button>
          </li>
        </ul>
      </nav>

      <div class="flex min-w-0 flex-col gap-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-[16px] font-semibold text-ink">{{ categoryLabel(profession) }}</h2>
          <p class="text-[12.5px] text-[#64748B]">Current: v{{ current.version }}, published {{ formatDateTime(current.publishedAt) }} by {{ current.publishedBy }}</p>
        </div>

        <!-- Document requirements -->
        <fieldset v-for="(d, i) in draft" :key="d.id" class="rounded-xl border bg-white p-4" :class="d.enabled ? 'border-[#E2E8F0]' : 'border-dashed border-[#CBD5E1] bg-[#F8FAFC]'" :disabled="!canEdit">
          <legend class="sr-only">{{ d.name }}</legend>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <label :for="`${uid}-${i}-name`" class="text-[12px] font-medium text-[#64748B]">Document</label>
              <input :id="`${uid}-${i}-name`" v-model="d.name" type="text" class="mt-0.5 h-9 w-full max-w-[360px] rounded-lg border border-[#CBD5E1] px-2.5 text-[14px] font-medium text-ink focus:outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <div class="flex items-center gap-2">
              <label class="inline-flex items-center gap-2 text-[13px] text-ink"><input v-model="d.enabled" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />Enabled</label>
              <button v-if="canEdit" type="button" class="wm-icon-btn !w-9 !h-9 !text-base text-[#B91C1C]" :aria-label="`Remove ${d.name}`" @click="remove(i)"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </div>
          </div>

          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block text-[12px] font-medium text-[#64748B]">Requirement
              <select v-model="d.requirement" class="mt-0.5 h-9 w-full rounded-lg border border-[#CBD5E1] bg-white px-2 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary">
                <option value="mandatory">Mandatory</option><option value="conditional">Conditional</option><option value="optional">Optional</option>
              </select>
            </label>
            <label v-if="d.requirement === 'conditional'" class="block text-[12px] font-medium text-[#64748B] sm:col-span-1 lg:col-span-2">Required when
              <select v-model="d.condition" class="mt-0.5 h-9 w-full rounded-lg border bg-white px-2 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary" :class="errors[`${i}.condition`] ? 'border-[#B91C1C]' : 'border-[#CBD5E1]'">
                <option :value="null" disabled>Choose a condition</option>
                <option v-for="(c, key) in CONDITIONS" :key="key" :value="key">{{ c.label }}</option>
              </select>
            </label>
            <label class="block text-[12px] font-medium text-[#64748B]">Number of documents
              <input v-model.number="d.count" type="number" min="1" max="10" class="mt-0.5 h-9 w-full rounded-lg border border-[#CBD5E1] px-2 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary" />
            </label>
            <label class="block text-[12px] font-medium text-[#64748B]">Max file size (MB)
              <input v-model.number="d.maxSizeMb" type="number" min="1" max="50" class="mt-0.5 h-9 w-full rounded-lg border border-[#CBD5E1] px-2 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary" />
            </label>
          </div>

          <label class="mt-3 block text-[12px] font-medium text-[#64748B]">Instructions for the provider
            <textarea v-model="d.instructions" rows="2" class="mt-0.5 w-full rounded-lg border border-[#CBD5E1] px-2.5 py-1.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
          </label>

          <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px] text-ink">
            <span class="flex flex-wrap items-center gap-2" role="group" :aria-label="`Accepted formats for ${d.name}`">
              <span class="text-[12px] font-medium text-[#64748B]">Formats</span>
              <label v-for="f in FORMAT_OPTIONS" :key="f" class="inline-flex items-center gap-1.5 uppercase">
                <input type="checkbox" class="h-4 w-4 accent-[#1769D2]" :checked="d.formats.includes(f)" @change="toggleFormat(d, f, $event.target.checked)" />{{ f }}
              </label>
            </span>
            <label class="inline-flex items-center gap-1.5"><input v-model="d.frontBack" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />Front and back</label>
            <label class="inline-flex items-center gap-1.5"><input v-model="d.multiPage" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />Multiple pages or supporting files</label>
            <label class="inline-flex items-center gap-1.5"><input v-model="d.expiry.required" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />Has an expiry date</label>
            <label v-if="d.expiry.required" class="inline-flex items-center gap-1.5">valid for at least
              <input v-model.number="d.expiry.minMonthsValid" type="number" min="0" max="24" class="h-8 w-16 rounded-lg border border-[#CBD5E1] px-2 text-[13px] focus:outline-none focus:ring-2 focus:ring-primary" />months
            </label>
          </div>
          <p v-for="key in Object.keys(errors).filter(k => k.startsWith(`${i}.`))" :key="key" class="mt-2 text-[12.5px] text-[#B91C1C]">{{ errors[key] }}</p>
        </fieldset>

        <div v-if="canEdit" class="flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-[#CBD5E1] bg-white p-3">
          <label :for="`${uid}-add`" class="text-[13px] font-medium text-ink">Add a document</label>
          <select :id="`${uid}-add`" v-model="addType" class="h-9 rounded-lg border border-[#CBD5E1] bg-white px-2 text-[13.5px] focus:outline-none focus:ring-2 focus:ring-primary">
            <option v-for="(lib, key) in available" :key="key" :value="key">{{ lib.name }}</option>
          </select>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-9 !rounded-lg !px-3 !text-[13px]" :disabled="!addType" @click="add"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add</button>
        </div>

        <section v-if="changes.length" class="rounded-xl border border-[#FDE7C2] bg-[#FFFBF2] p-4" aria-labelledby="changes-heading">
          <h3 id="changes-heading" class="text-[13.5px] font-semibold text-[#92400E]">Unpublished changes</h3>
          <ul class="mt-1 list-disc pl-5 text-[13px] text-[#92400E]"><li v-for="c in changes" :key="c">{{ c }}</li></ul>
        </section>

        <section class="rounded-xl wm-surface p-4" aria-labelledby="versions-heading">
          <h3 id="versions-heading" class="text-[14px] font-semibold text-ink">Version history</h3>
          <ol class="mt-2 flex flex-col divide-y divide-[#F1F5F9]">
            <li v-for="v in versionHistory(profession)" :key="v.version" class="py-2 text-[13px]">
              <span class="font-medium text-ink">v{{ v.version }}</span>
              <span class="text-[#64748B]"> · {{ formatDateTime(v.publishedAt) }} · {{ v.publishedBy }}</span>
              <span v-if="appsPerVersion[v.version]" class="text-[#64748B]"> · {{ appsPerVersion[v.version] }} applications</span>
              <span class="block text-[#475569]">{{ v.note }}</span>
            </li>
          </ol>
        </section>
      </div>
    </div>

    <!-- Provider-facing checklist preview -->
    <DetailDrawer :open="previewOpen" :title="`What a ${categoryLabel(profession).toLowerCase()} provider sees`" eyebrow="Provider preview" @close="previewOpen = false">
      <p class="text-[13px] text-[#64748B]">Preview of these (unpublished) requirements. Toggle the answers to see conditional documents appear.</p>
      <div v-for="key in previewConditions" :key="key" class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-[#F8FAFC] px-3 py-2 text-[13px]">
        <span>{{ CONDITIONS[key].question }}</span>
        <label class="inline-flex items-center gap-1.5"><input v-model="previewAnswers[key]" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />Yes</label>
      </div>
      <ul class="mt-4 flex flex-col divide-y divide-[#F1F5F9]">
        <li v-for="d in previewDocs" :key="d.id" class="py-3">
          <p class="flex flex-wrap items-center gap-2 text-[14px] font-medium text-ink">
            {{ d.name }}
            <span :class="['wm-pill wm-pill-plain !h-6 !text-[11.5px]', isRequired(d, previewAnswers) ? 'wm-pill-info' : 'wm-pill-neutral']">{{ isRequired(d, previewAnswers) ? 'Required' : 'Optional' }}</span>
          </p>
          <p class="mt-0.5 text-[13px] text-[#64748B]">{{ d.instructions }}</p>
          <p class="mt-0.5 text-[12px] text-[#64748B]">{{ d.formats.map(f => f.toUpperCase()).join(', ') }} · up to {{ d.maxSizeMb }} MB<template v-if="d.frontBack"> · front and back</template><template v-if="d.multiPage"> · all pages</template><template v-if="d.expiry.required"> · valid for {{ d.expiry.minMonthsValid }}+ months</template></p>
        </li>
      </ul>
    </DetailDrawer>

    <ActionDialog
      :open="publishing"
      :title="`Publish ${categoryLabel(profession)} requirements v${current.version + 1}?`"
      :message="`${changes.join('; ')}. New applications use v${current.version + 1}; submitted ones keep their version.`"
      confirm-label="Publish"
      :fields="[{ key: 'reason', label: 'What changed and why', type: 'textarea', required: true, minLength: 5, help: 'Shown in the version history and the audit log.' }]"
      @close="publishing = false"
      @confirm="doPublish"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, useId } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import DetailDrawer from '../components/DetailDrawer.vue';
import ActionDialog from '../components/ActionDialog.vue';
import { categories } from '../data/liveData.js';
import { CONDITIONS, FORMAT_OPTIONS, DOCUMENT_LIBRARY, requirement, applicableDocuments, isRequired } from '@/data/verificationRequirements.js';
import { currentVersion, versionHistory, publishVersion } from '@/stores/verification.js';
import { fetchDataset } from '../data/adminApi.js';
import { can } from '../permissions.js';
import { recordAudit, actorName, toast } from '../stores/prototypeChanges.js';
import { categoryLabel } from '@/utils/ui.js';
import { formatDateTime } from '../utils/format.js';

const uid = useId();
const copy = (x) => JSON.parse(JSON.stringify(x));
const canEdit = computed(() => can('verification.configure'));

const profession = ref('driving');
const current = computed(() => currentVersion(profession.value));
const draft = ref(copy(current.value.documents));
const errors = reactive({});

function selectProfession(id) {
  if (changes.value.length && !window.confirm('Discard unpublished changes to this profession?')) return;
  profession.value = id;
  resetDraft();
}
function resetDraft() {
  draft.value = copy(current.value.documents);
  for (const k of Object.keys(errors)) delete errors[k];
}

const available = computed(() => Object.fromEntries(Object.entries(DOCUMENT_LIBRARY).filter(([key]) => !draft.value.some(d => d.id === key))));
const addType = ref('');
function add() {
  draft.value.push(requirement(addType.value, 'optional'));
  addType.value = '';
}
function remove(i) { draft.value.splice(i, 1); }
function toggleFormat(d, f, on) { d.formats = on ? [...d.formats, f] : d.formats.filter(x => x !== f); }

/* Plain-language list of differences from the published version */
const FIELD_LABELS = { name: 'name', requirement: 'requirement', condition: 'condition', count: 'number of documents', maxSizeMb: 'maximum size', instructions: 'instructions', formats: 'formats', frontBack: 'front and back', multiPage: 'multiple pages', enabled: 'enabled', expiry: 'expiry rule' };
const changes = computed(() => {
  const out = [];
  const before = new Map(current.value.documents.map(d => [d.id, d]));
  const after = new Map(draft.value.map(d => [d.id, d]));
  for (const d of draft.value) {
    const old = before.get(d.id);
    if (!old) { out.push(`Added “${d.name}” (${d.requirement})`); continue; }
    const fields = Object.keys(FIELD_LABELS).filter(k => JSON.stringify(old[k]) !== JSON.stringify(d[k]));
    if (fields.length) out.push(`Changed “${d.name}”: ${fields.map(k => FIELD_LABELS[k]).join(', ')}`);
  }
  for (const d of current.value.documents) if (!after.has(d.id)) out.push(`Removed “${d.name}”`);
  return out;
});

function validate() {
  for (const k of Object.keys(errors)) delete errors[k];
  draft.value.forEach((d, i) => {
    if (!d.name.trim()) errors[`${i}.name`] = 'Give the document a name.';
    if (d.requirement === 'conditional' && !d.condition) errors[`${i}.condition`] = 'Choose when this document is required.';
    if (!(d.count >= 1)) errors[`${i}.count`] = 'At least one document.';
    if (!(d.maxSizeMb >= 1 && d.maxSizeMb <= 50)) errors[`${i}.size`] = 'Maximum size must be between 1 and 50 MB.';
    if (!d.formats.length) errors[`${i}.formats`] = 'Accept at least one file format.';
  });
  if (!draft.value.some(d => d.enabled && d.requirement === 'mandatory')) errors['all.mandatory'] = 'At least one enabled mandatory document is needed.';
  return !Object.keys(errors).length;
}

const publishing = ref(false);
function startPublish() {
  if (!validate()) {
    toast(errors['all.mandatory'] || 'Fix the highlighted requirements first.', 'warning');
    return;
  }
  publishing.value = true;
}
function doPublish({ reason }) {
  const v = publishVersion(profession.value, draft.value, { by: actorName(), note: reason });
  recordAudit('verification.requirements_published', `${categoryLabel(profession.value)} requirements v${v.version}: ${changes.value.join('; ')}`, `requirements:${profession.value}`, reason);
  publishing.value = false;
  resetDraft();
  toast(`Published ${categoryLabel(profession.value)} requirements v${v.version} on this screen only. Not saved to the server yet.`);
}

/* Preview */
const previewOpen = ref(false);
const previewAnswers = reactive({});
const previewConditions = computed(() => [...new Set(draft.value.filter(d => d.enabled && d.requirement === 'conditional' && d.condition).map(d => d.condition))]);
const previewDocs = computed(() => applicableDocuments({ documents: draft.value }, previewAnswers));

/* How many applications were made under each version */
const apps = ref([]);
onMounted(async () => { try { apps.value = (await fetchDataset('verification-applications')).rows; } catch { /* history counts are optional */ } });
const appsPerVersion = computed(() => {
  const out = {};
  for (const a of apps.value) if (a.profession === profession.value) out[a.snapshot.version] = (out[a.snapshot.version] || 0) + 1;
  return out;
});
</script>
