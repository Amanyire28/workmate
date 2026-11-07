<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Legal Documents" description="The terms, policies and agreements people accept, with their versions, who accepted them, and the company facts they show." />

    <div class="flex gap-1 rounded-xl wm-surface p-1 self-start" role="tablist" aria-label="Legal documents">
      <button v-for="t in TABS" :key="t.id" type="button" role="tab" :aria-selected="tab === t.id"
        class="rounded-lg px-3.5 py-2 text-[13.5px] font-medium" :class="tab === t.id ? 'bg-[#EFF6FF] text-primary' : 'text-[#475569] hover:bg-[#F8FAFC]'"
        @click="tab = t.id">{{ t.label }}</button>
    </div>

    <div v-if="loadError" class="rounded-xl wm-surface">
      <StateBlock kind="error" title="Legal documents couldn’t load" :message="loadError">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>
    <div v-else-if="loading" class="wm-skeleton h-[420px] !rounded-xl" aria-busy="true"></div>

    <!-- ================= Documents ================= -->
    <div v-else-if="tab === 'documents'" class="grid grid-cols-1 gap-4 xl:grid-cols-[280px_minmax(0,1fr)]">
      <nav class="rounded-xl wm-surface p-2" aria-label="Documents">
        <ul class="flex gap-1 overflow-x-auto xl:flex-col">
          <li v-for="d in documents" :key="d.key" class="shrink-0">
            <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left"
              :class="selectedKey === d.key ? 'bg-[#EFF6FF]' : 'hover:bg-[#F8FAFC]'" :aria-current="selectedKey === d.key ? 'true' : undefined"
              @click="selectedKey = d.key">
              <span class="min-w-0 flex-1">
                <span class="block text-[13.5px] font-medium whitespace-nowrap" :class="selectedKey === d.key ? 'text-primary' : 'text-ink'">{{ d.title }}</span>
                <span class="block text-[11.5px] text-[#64748B] whitespace-nowrap">
                  v{{ currentOf(d)?.version || '–' }}<template v-if="draftOf(d)"> · draft v{{ draftOf(d).version }}</template> · {{ d.audience === 'providers' ? 'providers' : 'everyone' }}
                </span>
              </span>
            </button>
          </li>
        </ul>
      </nav>

      <div v-if="selected" class="flex min-w-0 flex-col gap-3">
        <!-- Where it stands -->
        <section class="rounded-xl wm-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-[16px] font-semibold text-ink">{{ selected.title }}</h2>
              <p v-if="current" class="text-[12.5px] text-[#64748B]">
                Current: v{{ current.version }}, published {{ formatDateTime(current.published_at) }}<template v-if="current.published_by"> by {{ current.published_by }}</template>
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <a :href="`/legal/${selected.key}`" target="_blank" rel="noopener" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open public page</a>
              <button v-if="!draft" type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="busy" @click="startDraft"><i class="bi bi-pencil" aria-hidden="true"></i>Start a new version</button>
            </div>
          </div>
          <p class="mt-3 text-[13.5px] text-ink">
            <span class="font-semibold">{{ selected.up_to_date }}</span> of {{ selected.active_users }} active {{ selected.active_users === 1 ? 'account has' : 'accounts have' }} agreed to the latest material version{{ selected.audience === 'providers' ? ' (only providers are asked)' : '' }}.
          </p>
        </section>

        <!-- Draft editor -->
        <section v-if="draft" class="rounded-xl border border-[#FDE7C2] bg-[#FFFBF2] p-4" aria-labelledby="draft-heading">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 id="draft-heading" class="text-[14px] font-semibold text-[#92400E]">Draft v{{ draft.version }} · not published</h3>
            <p v-if="dirty" class="text-[12px] text-[#92400E]">Unsaved changes</p>
          </div>

          <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[160px_minmax(0,1fr)]">
            <label class="block text-[12px] font-medium text-[#64748B]">Version
              <input v-model="editor.version" type="text" maxlength="20" class="mt-0.5 h-9 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary" />
            </label>
            <label class="flex items-end gap-2 pb-1.5 text-[13px] text-ink">
              <input v-model="editor.is_material" type="checkbox" class="h-4 w-4 accent-[#1769D2]" />
              Material change: ask everyone to agree again
            </label>
          </div>
          <label class="mt-3 block text-[12px] font-medium text-[#64748B]">“In short” summary
            <textarea v-model="editor.summary" rows="2" maxlength="500" class="mt-0.5 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 py-1.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
          </label>
          <label class="mt-3 block text-[12px] font-medium text-[#64748B]">What changed (people see this when asked to agree)
            <textarea v-model="editor.change_note" rows="2" maxlength="1000" class="mt-0.5 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 py-1.5 text-[13.5px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
          </label>
          <label class="mt-3 block text-[12px] font-medium text-[#64748B]">Text (Markdown)
            <textarea v-model="editor.body" rows="22" spellcheck="true" class="mt-0.5 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 py-2 font-mono text-[12.5px] leading-relaxed text-ink focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
          </label>
          <details class="mt-2 text-[12.5px] text-[#475569]">
            <summary class="cursor-pointer font-medium">Formatting and placeholders</summary>
            <p class="mt-1">“## 1. Heading” for a section, “- ” for a bullet, “**bold**”. A <code>{{ '{' + '{fact}' + '}' }}</code> placeholder shows a company fact from the next tab; a missing one shows highlighted as “to be confirmed”. <code>[[note]]</code> is a drafting note, highlighted until you remove it.</p>
            <p class="mt-1">Placeholders: <code v-for="p in placeholders" :key="p" class="mr-1.5">{{ '{' + '{' + p + '}' + '}' }}</code></p>
          </details>

          <p v-if="draftError" class="mt-3 text-[13px] text-[#B91C1C]" role="alert">{{ draftError }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="busy || !dirty" @click="saveDraft()">Save draft</button>
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="busy" @click="previewVersion(draft, true)"><i class="bi bi-eye" aria-hidden="true"></i>Preview</button>
            <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="busy" @click="startPublish">Publish v{{ editor.version }}</button>
            <button type="button" class="wm-btn wm-btn-ghost !min-h-10 !rounded-lg !text-[13.5px] text-[#B91C1C]" :disabled="busy" @click="discarding = true">Discard draft</button>
          </div>
        </section>

        <!-- History -->
        <section class="rounded-xl wm-surface p-4" aria-labelledby="history-heading">
          <h3 id="history-heading" class="text-[14px] font-semibold text-ink">Published versions</h3>
          <p class="text-[12.5px] text-[#64748B]">Published versions never change: people agreed to exactly that text.</p>
          <ol class="mt-2 flex flex-col divide-y divide-[#F1F5F9]">
            <li v-for="v in published" :key="v.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-[13px]">
              <span class="min-w-0 flex-1">
                <span class="font-medium text-ink">v{{ v.version }}</span>
                <span class="text-[#64748B]"> · {{ formatDateTime(v.published_at) }}<template v-if="v.published_by"> · {{ v.published_by }}</template> · {{ v.is_material ? 'material' : 'minor' }} · {{ v.acceptances }} {{ v.acceptances === 1 ? 'acceptance' : 'acceptances' }}</span>
                <span v-if="v.change_note" class="block text-[#475569]">{{ v.change_note }}</span>
              </span>
              <button type="button" class="wm-btn wm-btn-ghost !min-h-9 !rounded-lg !px-2.5 !text-[12.5px]" @click="previewVersion(v)">Preview</button>
              <a :href="`/api/v1/admin/legal/versions/${v.id}/acceptances.csv`" class="wm-btn wm-btn-ghost !min-h-9 !rounded-lg !px-2.5 !text-[12.5px]" download><i class="bi bi-download" aria-hidden="true"></i>Acceptances (CSV)</a>
            </li>
          </ol>
        </section>
      </div>
    </div>

    <!-- ================= Company facts ================= -->
    <section v-else class="rounded-xl wm-surface p-4 max-w-3xl" aria-labelledby="facts-heading">
      <h2 id="facts-heading" class="text-[16px] font-semibold text-ink">Company facts</h2>
      <p class="text-[13px] text-[#64748B]">Filled into every document where its placeholder appears, straight away and without a new version. Support phone and email come from Settings → Platform.</p>
      <form v-for="s in facts" :key="s.key" class="mt-4 grid grid-cols-1 gap-2 border-t border-[#F1F5F9] pt-4 sm:grid-cols-[220px_minmax(0,1fr)_auto] sm:items-start" @submit.prevent="saveFact(s)">
        <label :for="`fact-${s.key}`" class="text-[13.5px] font-medium text-ink">{{ s.label }}<span class="block text-[12px] font-normal text-[#64748B]">{{ s.description }}</span></label>
        <div>
          <label v-if="s.type === 'boolean'" class="inline-flex items-center gap-2 text-[13.5px] text-ink">
            <input :id="`fact-${s.key}`" v-model="factValues[s.key]" type="checkbox" class="h-4 w-4 accent-[#1769D2]" :disabled="!s.can_change" />
            {{ factValues[s.key] ? 'On' : 'Off' }}
          </label>
          <input v-else :id="`fact-${s.key}`" v-model="factValues[s.key]" :type="s.type === 'integer' ? 'number' : s.type === 'email' ? 'email' : 'text'"
            :min="s.min" :max="s.max" :disabled="!s.can_change" placeholder="Not set: shown as “to be confirmed”"
            class="h-9 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary" />
          <p v-if="factErrors[s.key]" class="mt-1 text-[12.5px] text-[#B91C1C]">{{ factErrors[s.key] }}</p>
        </div>
        <button type="submit" class="wm-btn wm-btn-secondary !min-h-9 !rounded-lg !px-3 !text-[13px]" :disabled="!s.can_change || factBusy === s.key || same(s)">
          {{ factBusy === s.key ? 'Saving…' : 'Save' }}
        </button>
      </form>
    </section>

    <!-- Preview -->
    <DetailDrawer :open="!!preview" :title="preview ? `${preview.title} v${preview.version}` : ''" :eyebrow="preview?.status === 'draft' ? 'Draft preview' : 'Published version'" @close="preview = null">
      <template v-if="preview">
        <p v-if="preview.summary" class="wm-notice wm-notice-info"><i class="bi bi-lightbulb" aria-hidden="true"></i><span><strong>In short:</strong> {{ preview.summary }}</span></p>
        <div class="wm-legal-body mt-4" v-html="preview.html"></div>
      </template>
    </DetailDrawer>

    <ActionDialog
      :open="publishing"
      :title="`Publish ${selected?.title} v${editor.version}?`"
      :message="editor.is_material ? 'Everyone it applies to will be asked to agree again the next time they open WorkMate.' : 'A minor change: nobody is asked to agree again. It shows as the current version straight away.'"
      confirm-label="Publish"
      :fields="[{ key: 'reason', label: 'Why it’s being published', type: 'textarea', required: true, minLength: 5, help: 'Kept in the audit log.' }]"
      @close="publishing = false"
      @confirm="doPublish"
    />
    <ActionDialog
      :open="discarding"
      :title="`Discard draft v${draft?.version}?`"
      message="The draft text is deleted. Published versions aren’t affected."
      confirm-label="Discard draft"
      tone="danger"
      @close="discarding = false"
      @confirm="doDiscard"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import DetailDrawer from '../components/DetailDrawer.vue';
import ActionDialog from '../components/ActionDialog.vue';
import StateBlock from '../components/StateBlock.vue';
import { api, fieldErrors } from '@/api/client.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatDateTime } from '../utils/format.js';

const TABS = [{ id: 'documents', label: 'Documents' }, { id: 'facts', label: 'Company facts' }];
const tab = ref('documents');

const documents = ref([]);
const placeholders = ref([]);
const loading = ref(true);
const loadError = ref('');
const busy = ref(false);

const selectedKey = ref('terms');
const selected = computed(() => documents.value.find(d => d.key === selectedKey.value) || null);
const draftOf = (d) => d.versions.find(v => v.status === 'draft') || null;
const currentOf = (d) => d.versions.find(v => v.status === 'published') || null;
const draft = computed(() => (selected.value ? draftOf(selected.value) : null));
const current = computed(() => (selected.value ? currentOf(selected.value) : null));
const published = computed(() => (selected.value ? selected.value.versions.filter(v => v.status === 'published') : []));

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const [legalRes, settingsRes] = await Promise.all([api('/admin/legal'), api('/admin/settings')]);
    documents.value = legalRes.data.documents;
    placeholders.value = legalRes.data.placeholders;
    facts.value = settingsRes.data.filter(s => s.group === 'legal');
    for (const s of facts.value) factValues[s.key] = s.value ?? (s.type === 'boolean' ? false : '');
    resetEditor();
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

/* ---------------- Draft editor ---------------- */
const editor = reactive({ version: '', summary: '', body: '', change_note: '', is_material: true });
const draftError = ref('');
const FIELDS = ['version', 'summary', 'body', 'change_note', 'is_material'];
const dirty = computed(() => !!draft.value && FIELDS.some(k => (editor[k] ?? '') !== (draft.value[k] ?? '')));

function resetEditor() {
  draftError.value = '';
  for (const k of FIELDS) editor[k] = draft.value ? draft.value[k] ?? (k === 'is_material' ? true : '') : (k === 'is_material' ? true : '');
}
watch(selectedKey, (next, prev) => {
  if (dirty.value && !window.confirm('You have unsaved changes to this draft. Leave them?')) {
    selectedKey.value = prev;
    return;
  }
  resetEditor();
});
onBeforeRouteLeave(() => (dirty.value ? window.confirm('You have unsaved changes to a draft. Leave without saving?') : true));

async function startDraft() {
  busy.value = true;
  try {
    await api(`/admin/legal/${selectedKey.value}/drafts`, { method: 'POST' });
    await load();
    toast('Draft started from the current text.');
  } catch (e) {
    toast(e.message || 'The draft couldn’t be started.', 'warning');
  } finally {
    busy.value = false;
  }
}

async function saveDraft({ quiet = false } = {}) {
  busy.value = true;
  draftError.value = '';
  try {
    const body = {};
    for (const k of FIELDS) if ((editor[k] ?? '') !== (draft.value[k] ?? '')) body[k] = editor[k];
    if (Object.keys(body).length) await api(`/admin/legal/versions/${draft.value.id}`, { method: 'PATCH', body });
    Object.assign(draft.value, { ...body });
    if (!quiet) toast('Draft saved.');
    return true;
  } catch (e) {
    draftError.value = Object.values(fieldErrors(e))[0] || e.message;
    return false;
  } finally {
    busy.value = false;
  }
}

const publishing = ref(false);
async function startPublish() {
  if (!editor.change_note?.trim()) {
    draftError.value = 'Say what changed first: people see it when they’re asked to agree.';
    return;
  }
  if (dirty.value && !(await saveDraft({ quiet: true }))) return;
  publishing.value = true;
}
async function doPublish({ reason }) {
  publishing.value = false;
  busy.value = true;
  try {
    await api(`/admin/legal/versions/${draft.value.id}/publish`, { method: 'POST', body: { reason } });
    const version = editor.version;
    await load();
    toast(`Published v${version}.`);
  } catch (e) {
    draftError.value = e.message;
  } finally {
    busy.value = false;
  }
}

const discarding = ref(false);
async function doDiscard() {
  discarding.value = false;
  busy.value = true;
  try {
    await api(`/admin/legal/versions/${draft.value.id}`, { method: 'DELETE' });
    await load();
    toast('Draft discarded.');
  } catch (e) {
    toast(e.message || 'The draft couldn’t be discarded.', 'warning');
  } finally {
    busy.value = false;
  }
}

/* ---------------- Preview ---------------- */
const preview = ref(null);
async function previewVersion(v, saveFirst = false) {
  if (saveFirst && dirty.value && !(await saveDraft({ quiet: true }))) return;
  try {
    preview.value = (await api(`/admin/legal/versions/${v.id}/preview`)).data;
  } catch (e) {
    toast(e.message || 'The preview couldn’t load.', 'warning');
  }
}

/* ---------------- Company facts ---------------- */
const facts = ref([]);
const factValues = reactive({});
const factErrors = reactive({});
const factBusy = ref('');
const same = (s) => (factValues[s.key] ?? '') === (s.value ?? (s.type === 'boolean' ? false : ''));

async function saveFact(s) {
  factBusy.value = s.key;
  factErrors[s.key] = '';
  try {
    let value = factValues[s.key];
    if (s.type === 'integer') value = Number(value);
    const res = await api(`/admin/settings/${s.key}`, { method: 'PATCH', body: { value } });
    Object.assign(s, res.data);
    factValues[s.key] = res.data.value ?? (s.type === 'boolean' ? false : '');
    toast(`${s.label} saved.`);
  } catch (e) {
    factErrors[s.key] = fieldErrors(e).value || e.message;
  } finally {
    factBusy.value = '';
  }
}
</script>
