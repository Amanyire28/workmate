<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader :title="item.label" :description="def.description || item.summary" demo>
      <template v-if="values && canEdit" #actions>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="isDefault" @click="resetDefaults">Reset to defaults</button>
        <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!changes.length" @click="review">
          Review {{ changes.length || '' }} {{ changes.length === 1 ? 'change' : 'changes' }}
        </button>
      </template>
    </AdminPageHeader>

    <p v-if="values && !hasConnected" class="wm-notice wm-notice-warning">
      <i class="bi bi-plug" aria-hidden="true"></i>
      <span>These settings aren’t connected to the platform yet, so they can’t be changed. The values shown are the planned defaults.</span>
    </p>
    <p v-else-if="values" class="wm-notice wm-notice-info">
      <i class="bi bi-database" aria-hidden="true"></i>
      <span>Settings marked <strong class="font-semibold">Live</strong> apply to the whole platform as soon as you save. Those marked <strong class="font-semibold">Not available yet</strong> can’t work today; each says why.</span>
    </p>
    <p v-if="values && hasConnected && !canEdit" class="wm-notice wm-notice-info">
      <i class="bi bi-lock" aria-hidden="true"></i><span>Your role can view these settings but not change them.</span>
    </p>

    <div v-if="error" class="rounded-xl wm-surface">
      <StateBlock kind="error" :title="`${item.label} couldn’t load`" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>
    <div v-else-if="!values" class="wm-skeleton h-[360px] !rounded-xl" aria-busy="true"></div>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="review">
      <fieldset v-for="s in def.sections" :key="s.title" class="rounded-xl wm-surface p-4 lg:p-5">
        <legend class="sr-only">{{ s.title }}</legend>
        <h2 class="text-[15px] font-semibold text-ink" aria-hidden="true">{{ s.title }}</h2>
        <p v-if="s.description" class="mt-0.5 text-[13px] text-[#64748B]">{{ s.description }}</p>

        <div class="mt-4 grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
          <fieldset v-for="f in s.fields" :key="f.key" :class="{ 'md:col-span-2': f.wide }" :disabled="!editable(f)">
            <!-- Toggle -->
            <label v-if="f.type === 'toggle'" class="flex items-start gap-3" :class="editable(f) ? 'cursor-pointer' : 'opacity-70'">
              <input v-model="values[f.key]" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#1769D2]" :aria-describedby="f.help ? `${uid}-${f.key}-h` : undefined" />
              <span>
                <span class="block text-[13.5px] font-medium text-ink">{{ f.label }}<ChangedMark :on="changed(f.key)" /><SourceMark :live="!!connected[f.key]" :unavailable="!!f.unavailable" /></span>
                <span v-if="f.unavailable || f.help" :id="`${uid}-${f.key}-h`" class="block text-[12.5px] text-[#64748B]">{{ f.unavailable || f.help }}</span>
              </span>
            </label>

            <template v-else>
              <label :for="`${uid}-${f.key}`" class="block text-[13.5px] font-medium text-ink">{{ f.label }}<ChangedMark :on="changed(f.key)" /><SourceMark :live="!!connected[f.key]" :unavailable="!!f.unavailable" /></label>
              <div class="mt-1.5 flex items-center gap-2">
                <select
                  v-if="f.type === 'select'"
                  :id="`${uid}-${f.key}`"
                  v-model="values[f.key]"
                  class="h-10 w-full max-w-[320px] rounded-lg border border-[#CBD5E1] bg-white px-2.5 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary disabled:bg-[#F8FAFC]"
                  :aria-describedby="`${uid}-${f.key}-h`"
                >
                  <option v-for="[v, l] in f.options" :key="v" :value="v">{{ l }}</option>
                </select>
                <input
                  v-else
                  :id="`${uid}-${f.key}`"
                  v-model="values[f.key]"
                  :type="f.type === 'number' ? 'number' : f.type === 'time' ? 'time' : 'text'"
                  :min="f.min" :max="f.max" :step="f.step || (f.type === 'number' ? 1 : undefined)"
                  class="h-10 rounded-lg border bg-white px-3 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary disabled:bg-[#F8FAFC]"
                  :class="[errors[f.key] ? 'border-[#B91C1C]' : 'border-[#CBD5E1]', f.type === 'number' || f.type === 'time' ? 'w-32' : 'w-full max-w-[360px]']"
                  :aria-invalid="errors[f.key] ? 'true' : undefined"
                  :aria-describedby="`${uid}-${f.key}-h`"
                />
                <span v-if="f.unit" class="text-[13px] text-[#64748B]">{{ f.unit }}</span>
              </div>
              <p v-if="errors[f.key]" :id="`${uid}-${f.key}-h`" class="mt-1 text-[12.5px] text-[#B91C1C]">{{ errors[f.key] }}</p>
              <p v-else-if="f.unavailable || f.help" :id="`${uid}-${f.key}-h`" class="mt-1 text-[12.5px] text-[#64748B]">{{ f.unavailable || f.help }}</p>
            </template>
          </fieldset>
        </div>
      </fieldset>
    </form>

    <ActionDialog
      :open="reviewing"
      :title="`Save ${changes.length} ${changes.length === 1 ? 'change' : 'changes'} to ${item.label}?`"
      :message="changeSummary"
      confirm-label="Save changes"
      :fields="[{ key: 'reason', label: 'Reason for the change', type: 'textarea', required: true, minLength: 5, help: 'Recorded in the audit log with your name.' }]"
      :busy="saving"
      :error="saveError"
      @close="reviewing = false"
      @confirm="save"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, useId, h } from 'vue';
import AdminPageHeader from './AdminPageHeader.vue';
import StateBlock from './StateBlock.vue';
import ActionDialog from './ActionDialog.vue';
import { getSettings, saveSettings } from '../data/adminApi.js';
import { toast } from '../stores/prototypeChanges.js';

const props = defineProps({
  def:  { type: Object, required: true },
  item: { type: Object, required: true },
});

const ChangedMark = (p) => (p.on ? h('span', { class: 'ml-1.5 rounded bg-[#FEF3E2] px-1.5 text-[11px] font-semibold text-[#92400E]' }, 'Changed') : null);
ChangedMark.props = ['on'];

// "Live": saved on the server for the whole platform. "Not available yet": can't work today.
const SourceMark = (p) => h('span', {
  class: p.live
    ? 'ml-1.5 rounded bg-[#EAF3FF] px-1.5 text-[11px] font-semibold text-[#1E5AA8]'
    : 'ml-1.5 rounded bg-[#F1F5F9] px-1.5 text-[11px] font-medium text-[#64748B]',
}, p.live ? 'Live' : (p.unavailable ? 'Not available yet' : 'Not connected yet'));
SourceMark.props = ['live', 'unavailable'];

const uid = useId();
const values = ref(null);
const saved = ref(null);
const defaults = ref(null);
const error = ref('');
const errors = reactive({});
const reviewing = ref(false);
const saving = ref(false);
const saveError = ref('');
const connected = ref({});   // field key → { key, canChange } for settings that exist on the server

// The server says per setting whether this role may change it
const editable = (f) => !!connected.value[f.key]?.canChange;
const hasConnected = computed(() => Object.keys(connected.value).length > 0);
const canEdit = computed(() => Object.values(connected.value).some(c => c.canChange));

const fields = computed(() => props.def.sections.flatMap(s => s.fields));

async function load() {
  error.value = '';
  try {
    const res = await getSettings(props.item.id);
    defaults.value = res.defaults;
    connected.value = res.connected || {};
    saved.value = { ...res.values };
    values.value = { ...res.values };
  } catch (e) { error.value = e.message; }
}
onMounted(load);

const norm = (f, v) => (f.type === 'number' ? Number(v) : v);
const changed = (key) => {
  const f = fields.value.find(x => x.key === key);
  return values.value && norm(f, values.value[key]) !== norm(f, saved.value[key]);
};
const changes = computed(() => (values.value ? fields.value.filter(f => changed(f.key)) : []));
const isDefault = computed(() => values.value && fields.value.every(f => norm(f, values.value[f.key]) === norm(f, defaults.value[f.key])));

const show = (f, v) => (f.type === 'toggle' ? (v ? 'On' : 'Off') : f.type === 'select' ? (f.options.find(o => o[0] === v)?.[1] ?? v) : `${v}${f.unit ? ` ${f.unit}` : ''}`);
const changeSummary = computed(() => changes.value.map(f => `${f.label}: ${show(f, saved.value[f.key])} → ${show(f, norm(f, values.value[f.key]))}`).join('; '));

function validate() {
  for (const k of Object.keys(errors)) delete errors[k];
  for (const f of fields.value) {
    if (f.type !== 'number') continue;
    const v = Number(values.value[f.key]);
    if (values.value[f.key] === '' || Number.isNaN(v)) errors[f.key] = 'Enter a number.';
    else if (f.min != null && v < f.min) errors[f.key] = `Must be at least ${f.min}.`;
    else if (f.max != null && v > f.max) errors[f.key] = `Must be ${f.max} or less.`;
  }
  const extra = props.def.validate ? props.def.validate(Object.fromEntries(fields.value.map(f => [f.key, norm(f, values.value[f.key])]))) : {};
  Object.assign(errors, extra);
  return !Object.keys(errors).length;
}

function review() {
  if (!changes.value.length) return;
  if (!validate()) {
    document.getElementById(`${uid}-${Object.keys(errors)[0]}`)?.focus();
    return;
  }
  reviewing.value = true;
}

/** Saves the changed live settings; the server validates, applies and audits each one */
async function save({ reason }) {
  saving.value = true;
  saveError.value = '';
  try {
    const changed = Object.fromEntries(changes.value.filter(editable).map(f => [f.key, norm(f, values.value[f.key])]));
    await saveSettings(props.item.id, changed, reason);
    reviewing.value = false;
    await load();
    toast(`${props.item.label} saved. The change applies to the whole platform and is in the audit log.`);
  } catch (e) {
    const first = Object.values(e?.errors || {})[0];
    saveError.value = (first && first[0]) || e.message;
  } finally {
    saving.value = false;
  }
}

// Only live settings can be reset; the rest aren't connected
function resetDefaults() {
  const next = { ...values.value };
  for (const f of fields.value) if (editable(f)) next[f.key] = defaults.value[f.key];
  values.value = next;
}
</script>
