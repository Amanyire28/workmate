<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Blue Tick Rules" description="The rules that award the blue tick automatically. Providers never apply for it, and it can’t be bought." demo>
      <template v-if="canEdit" #actions>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!dirty" @click="runPreview"><i class="bi bi-people" aria-hidden="true"></i>Preview effect</button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!dirty" @click="keepDraft">Save draft</button>
        <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg !text-[13.5px]" :disabled="!dirty" @click="publishing = true">Publish version {{ published.version + 1 }}</button>
      </template>
    </AdminPageHeader>

    <p class="wm-notice wm-notice-info">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Published: <strong>v{{ published.version }}</strong> by {{ published.publishedBy }}, {{ formatDateTime(published.publishedAt) }}: “{{ published.note }}”.
        Publishing re-evaluates every provider; Laravel will also re-evaluate on performance events and nightly.</span>
    </p>
    <p class="wm-notice wm-notice-warning">
      <i class="bi bi-plug" aria-hidden="true"></i>
      <span><strong class="font-semibold">Not connected yet.</strong> Blue tick rules aren’t stored by the server, so changes here are kept only until you reload and don’t affect any provider.</span>
    </p>
    <p v-if="blueTickRules.draft" class="wm-notice wm-notice-warning">
      <i class="bi bi-pencil-square" aria-hidden="true"></i>
      <span>Draft saved by {{ blueTickRules.draft.savedBy }} {{ formatDateTime(blueTickRules.draft.savedAt) }}, not yet published.
        <button v-if="canEdit" type="button" class="font-semibold underline" @click="discard">Discard draft</button></span>
    </p>
    <p v-if="!canEdit" class="wm-notice wm-notice-info"><i class="bi bi-lock" aria-hidden="true"></i><span>Your role can view the rules but not change them.</span></p>

    <!-- Mode -->
    <fieldset class="rounded-xl wm-surface p-4" :disabled="!canEdit">
      <legend class="sr-only">Evaluation mode</legend>
      <h2 class="text-[15px] font-semibold text-ink" aria-hidden="true">Evaluation mode</h2>
      <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
        <label v-for="(m, key) in MODES" :key="key" class="flex cursor-pointer items-start gap-3 rounded-xl border p-3" :class="working.mode === key ? 'border-primary bg-[#EFF6FF]' : 'border-[#E2E8F0]'">
          <input v-model="working.mode" type="radio" name="mode" :value="key" class="mt-1 h-4 w-4 accent-[#1769D2]" />
          <span>
            <span class="block text-[14px] font-semibold text-ink">{{ m.label }}</span>
            <span class="block text-[13px] text-[#475569]">{{ m.help }}</span>
          </span>
        </label>
      </div>
      <div v-if="working.mode === 'weighted'" class="mt-3 flex flex-wrap items-center gap-3 text-[13.5px]">
        <label :for="`${uid}-thr`" class="font-medium text-ink">Minimum score</label>
        <input :id="`${uid}-thr`" v-model.number="working.threshold" type="number" min="1" max="100" class="h-9 w-20 rounded-lg border border-[#CBD5E1] px-2 focus:outline-none focus:ring-2 focus:ring-primary" />
        <span class="text-[#64748B]">out of 100. Score = points from passed performance rules ÷ points available from rules that could be evaluated.</span>
      </div>
    </fieldset>

    <!-- Rules by category -->
    <fieldset v-for="(cat, key) in CATEGORIES" :key="key" class="rounded-xl wm-surface p-4" :disabled="!canEdit">
      <legend class="sr-only">{{ cat.label }}</legend>
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-[15px] font-semibold text-ink" aria-hidden="true">{{ cat.label }}</h2>
        <span v-if="cat.standing" class="text-[12px] text-[#64748B]">Always required when enabled, in both modes</span>
        <span v-else-if="working.mode === 'weighted'" class="text-[12px] text-[#64748B]">Earns points in weighted mode</span>
      </div>
      <ul class="mt-2 divide-y divide-[#F1F5F9]">
        <li v-for="def in RULE_DEFS.filter(d => d.category === key)" :key="def.id" class="grid grid-cols-1 gap-2 py-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
          <label class="flex items-start gap-3">
            <input v-model="working.rules[def.id].enabled" type="checkbox" class="mt-0.5 h-4 w-4 accent-[#1769D2]" />
            <span class="min-w-0">
              <span class="block text-[13.5px] font-medium" :class="working.rules[def.id].enabled ? 'text-ink' : 'text-[#94A3B8]'">
                {{ def.label }}
                <span v-if="def.collected === false" class="ml-1 rounded bg-[#FEF3E2] px-1.5 text-[11px] font-semibold text-[#92400E]">Not collected yet</span>
              </span>
              <span v-if="def.help" class="block text-[12.5px] text-[#64748B]">{{ def.help }}</span>
              <span v-if="def.collected === false && working.rules[def.id].enabled" class="block text-[12.5px] text-[#B45309]">
                No provider can pass this until the data is recorded{{ working.mode === 'all_mandatory' ? ', so nobody would qualify.' : '; it is left out of the score.' }}
              </span>
            </span>
          </label>
          <div class="flex flex-wrap items-center gap-2 pl-7 text-[13px] md:pl-0">
            <template v-if="def.kind === 'min' || def.kind === 'max'">
              <span class="text-[#64748B]">{{ def.kind === 'min' ? 'At least' : 'At most' }}</span>
              <input
                type="number" step="any" class="h-8 w-20 rounded-lg border border-[#CBD5E1] px-2 focus:outline-none focus:ring-2 focus:ring-primary"
                :aria-label="`${def.label} threshold`"
                :value="def.percent ? Math.round(working.rules[def.id].value * 100) : working.rules[def.id].value"
                @input="setValue(def, $event.target.value)"
              />
              <span class="text-[#64748B]">{{ def.percent ? '%' : def.unit }}</span>
            </template>
            <span v-else class="text-[#64748B]">{{ def.kind === 'zero' ? 'Must be none' : 'Must be true' }}</span>
            <select v-if="def.periodOptions" v-model.number="working.rules[def.id].period" class="h-8 rounded-lg border border-[#CBD5E1] bg-white px-1.5 focus:outline-none focus:ring-2 focus:ring-primary" :aria-label="`${def.label} period`">
              <option v-for="p in def.periodOptions" :key="p" :value="p">in {{ p }} days</option>
            </select>
            <template v-if="working.mode === 'weighted' && !cat.standing">
              <span class="ml-1 text-[#64748B]">Points</span>
              <input v-model.number="working.rules[def.id].weight" type="number" min="0" max="50" class="h-8 w-16 rounded-lg border border-[#CBD5E1] px-2 focus:outline-none focus:ring-2 focus:ring-primary" :aria-label="`${def.label} points`" />
            </template>
          </div>
        </li>
      </ul>
    </fieldset>

    <!-- Preview -->
    <section v-if="preview || previewing" class="rounded-xl wm-surface p-4" aria-labelledby="preview-heading" aria-live="polite">
      <h2 id="preview-heading" class="text-[15px] font-semibold text-ink">Effect of these changes</h2>
      <p v-if="previewing" class="mt-2 text-[13px] text-[#64748B]">Evaluating every provider…</p>
      <template v-else>
        <p class="mt-1 text-[13px] text-[#475569]">
          {{ preview.currentHolders }} providers hold the blue tick now; {{ preview.nextQualifiers }} would qualify under these rules.
          <strong class="text-[#15803D]">{{ preview.gainCount }} would gain it</strong>, <strong class="text-[#B91C1C]">{{ preview.loseCount }} would lose it</strong>.
        </p>
        <p v-if="preview.notEvaluable" class="wm-notice wm-notice-warning mt-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>A rule that can’t be evaluated yet is enabled in all-mandatory mode, so nobody can qualify.</span></p>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
          <div>
            <h3 class="text-[13px] font-semibold text-ink">Would gain</h3>
            <ul class="mt-1 text-[13px] text-[#475569]"><li v-for="p in preview.gain" :key="p.id">{{ p.name }} <span class="text-[#94A3B8]">({{ p.id }})</span></li><li v-if="!preview.gain.length">Nobody</li></ul>
          </div>
          <div>
            <h3 class="text-[13px] font-semibold text-ink">Would lose</h3>
            <ul class="mt-1 text-[13px] text-[#475569]"><li v-for="p in preview.lose" :key="p.id">{{ p.name }} <span class="text-[#94A3B8]">— {{ p.reason }}</span></li><li v-if="!preview.lose.length">Nobody</li></ul>
          </div>
        </div>
      </template>
    </section>

    <section class="rounded-xl wm-surface p-4" aria-labelledby="hist-heading">
      <h2 id="hist-heading" class="text-[15px] font-semibold text-ink">Version history</h2>
      <ol class="mt-2 divide-y divide-[#F1F5F9]">
        <li v-for="v in [...blueTickRules.versions].reverse()" :key="v.version" class="py-2 text-[13px]">
          <span class="font-medium text-ink">v{{ v.version }}</span>
          <span class="text-[#64748B]"> · {{ MODES[v.mode].label }} · {{ formatDateTime(v.publishedAt) }} · {{ v.publishedBy }}</span>
          <span class="block text-[#475569]">{{ v.note }}</span>
        </li>
      </ol>
    </section>

    <ActionDialog
      :open="publishing"
      :title="`Publish blue tick rules v${published.version + 1}?`"
      message="Every provider is re-evaluated against the new rules. Awards and removals are recorded with this version number."
      confirm-label="Publish"
      :fields="[{ key: 'reason', label: 'What changed and why', type: 'textarea', required: true, minLength: 5 }]"
      @close="publishing = false"
      @confirm="doPublish"
    />
  </div>
</template>

<script setup>
import { ref, computed, useId } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import ActionDialog from '../components/ActionDialog.vue';
import { CATEGORIES, MODES, RULE_DEFS } from '../utils/blueTick.js';
import { blueTickRules, publishedRules, saveDraft, discardDraft, publish } from '../stores/blueTickRules.js';
import { previewRules } from '../data/adminApi.js';
import { can } from '../permissions.js';
import { recordAudit, actorName, toast } from '../stores/prototypeChanges.js';
import { formatDateTime } from '../utils/format.js';

const uid = useId();
const copy = (x) => JSON.parse(JSON.stringify(x));
const canEdit = computed(() => can('bluetick.configure'));
const published = computed(() => publishedRules());

const working = ref(copy(blueTickRules.draft?.ruleset || published.value));
const comparable = (r) => JSON.stringify({ mode: r.mode, threshold: r.threshold, rules: r.rules });
const dirty = computed(() => comparable(working.value) !== comparable(published.value));

function setValue(def, raw) {
  const n = Number(raw);
  if (raw === '' || Number.isNaN(n)) return;
  working.value.rules[def.id].value = def.percent ? n / 100 : n;
}

const preview = ref(null);
const previewing = ref(false);
async function runPreview() {
  previewing.value = true;
  try { preview.value = await previewRules(copy(working.value)); } catch (e) { toast(e.message, 'warning'); } finally { previewing.value = false; }
}

function keepDraft() {
  saveDraft(working.value, actorName());
  recordAudit('bluetick.rules_draft_saved', 'Blue tick rules draft saved', 'bluetick:rules');
  toast('Draft kept on this screen only. Blue tick rules aren’t saved to the server yet.');
}
function discard() {
  discardDraft();
  working.value = copy(published.value);
  preview.value = null;
}

const publishing = ref(false);
function doPublish({ reason }) {
  const v = publish(working.value, { by: actorName(), note: reason });
  recordAudit('bluetick.rules_published', `Blue tick rules v${v.version} published (${MODES[v.mode].label})`, 'bluetick:rules', reason);
  publishing.value = false;
  working.value = copy(v);
  preview.value = null;
  toast(`Rules v${v.version} published. Providers were re-evaluated; see Blue Tick Management.`);
}
</script>
