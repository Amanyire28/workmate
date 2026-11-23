<template>
  <BottomSheet :open="open" title="Payment question" @close="emit('close')">
    <p v-if="loading" class="text-[14px] text-muted" role="status"><span class="wm-spinner mr-2" aria-hidden="true"></span>Loading…</p>
    <p v-else-if="loadError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ loadError }}</span></p>

    <div v-else-if="d" class="flex flex-col gap-4">
      <!-- Where it stands -->
      <section class="flex flex-col gap-1 rounded-xl px-3.5 py-3" :class="d.status === 'resolved' ? 'bg-[#F0FDF4]' : 'bg-primary-tint'" aria-label="Status">
        <p class="text-[15px] font-semibold text-ink">{{ stage.title }}</p>
        <p v-if="d.status !== 'resolved'" class="text-[13.5px] text-muted">{{ stage.next }}</p>
        <template v-else>
          <p class="text-[13.5px] text-ink">{{ resolutionWords(d.resolution, d.you) }}</p>
          <p v-if="d.resolution.reason" class="text-[13.5px] text-ink">WorkMate’s reason: “{{ d.resolution.reason }}”</p>
          <template v-if="d.resolution.financial_correction_required">
            <p class="inline-flex w-fit items-center gap-1.5 rounded-full bg-[#FEF3C7] px-2.5 py-0.5 text-[12.5px] font-medium text-[#92400E]"><i class="bi bi-hourglass-split" aria-hidden="true"></i>{{ CORRECTION_LABEL }}</p>
            <p class="text-[13px] text-muted">{{ correctionWords(d.resolution, d.payment && d.payment.status) }}</p>
          </template>
          <p v-if="d.payment" class="text-[13px] text-muted">The payment report itself is <strong class="text-ink">{{ reportStateWords(d.payment.status) }}</strong>.</p>
        </template>
      </section>

      <!-- The payment in question -->
      <section v-if="d.payment" class="flex flex-col gap-0.5 rounded-xl border border-line bg-white/70 px-3.5 py-3 text-[14px]" aria-label="The payment">
        <p class="flex items-baseline justify-between gap-3"><span class="text-muted">Payment reported</span><strong class="text-[16px] text-ink">{{ ugx(d.payment.amount) }}</strong></p>
        <p class="text-muted">{{ methodLabel(d.payment.method) }}<template v-if="d.payment.paid_on"> · {{ shortDate(d.payment.paid_on) }}</template><template v-if="d.payment.reference"> · {{ d.payment.reference }}</template></p>
        <p v-if="d.payment.note" class="text-ink">“{{ d.payment.note }}”</p>
        <p v-if="d.job && d.job.final_price" class="text-muted">Agreed price for the job: {{ ugx(d.job.final_price) }}</p>
      </section>

      <!-- Settling it between the two of you: both have to agree -->
      <section v-if="d.can.propose || d.proposal" class="flex flex-col gap-2" aria-label="Settle it between you">
        <h3 class="text-[14px] font-semibold text-ink">Settle it between you</h3>
        <template v-if="d.proposal">
          <p class="rounded-xl bg-[#F8FAFC] px-3 py-2.5 text-[14px] text-ink">
            <template v-if="d.proposal.mine">You suggested: <strong>{{ agreementLabel(d.proposal.outcome) }}</strong>. It only counts if the other side agrees.</template>
            <template v-else>The other side suggested: <strong>{{ agreementLabel(d.proposal.outcome) }}</strong>. It only counts if you agree too.</template>
          </p>
          <div class="flex gap-2">
            <button v-if="d.can.accept" type="button" class="wm-btn wm-btn-primary !min-h-12 flex-[2]" :disabled="busy" @click="accept">I agree</button>
            <button v-if="d.can.decline" type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="decline">{{ d.proposal.mine ? 'Take it back' : 'No' }}</button>
          </div>
        </template>
        <template v-else-if="d.can.propose">
          <p class="text-[13px] text-muted">If you both agree, the case closes. Nobody can settle it for the other side.</p>
          <div class="flex flex-col gap-2">
            <button v-for="a in AGREEMENTS" :key="a.id" type="button" class="wm-btn wm-btn-secondary !min-h-12 w-full !justify-start text-left" :disabled="busy" @click="propose(a.id)">
              <span><span class="block text-[14px] font-medium">Suggest: {{ a.label }}</span><span class="block text-[12.5px] font-normal text-muted">{{ a.hint }}</span></span>
            </button>
          </div>
        </template>
      </section>

      <!-- What has been said -->
      <section class="flex flex-col gap-2" aria-label="Messages">
        <h3 class="text-[14px] font-semibold text-ink">Messages and proof</h3>
        <p v-if="!d.messages.length" class="text-[13.5px] text-muted">Nothing has been written yet.</p>
        <ul v-else class="flex flex-col gap-2">
          <li v-for="m in d.messages" :key="m.id" class="flex gap-2" :class="m.mine ? 'flex-row-reverse' : ''">
            <UserAvatar :name="m.name" :src="m.avatar_url" :size="32" class="shrink-0" />
            <div class="min-w-0 max-w-[85%] rounded-2xl px-3 py-2 text-[14px]" :class="m.kind === 'info_request' ? 'bg-[#FEF3C7]' : m.mine ? 'bg-primary-tint' : 'bg-[#F1F5F9]'">
              <p class="text-[12px] font-medium text-muted">{{ m.name }}<template v-if="m.kind === 'info_request'"> asks<template v-if="m.addressed_to !== 'both'"> the {{ m.addressed_to }}</template></template> · {{ shortDate(m.at) }}</p>
              <p v-if="m.body" class="whitespace-pre-line break-words text-ink">{{ m.body }}</p>
              <ul v-if="m.files.length" class="mt-1 flex flex-wrap gap-2">
                <li v-for="f in m.files" :key="f.uuid">
                  <a :href="f.url" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-lg border border-line bg-white px-2 py-1.5 text-[13px] text-primary">
                    <img v-if="isImage(f.mime)" :src="f.url" alt="" class="h-10 w-10 rounded object-cover" loading="lazy" />
                    <i v-else class="bi bi-file-earmark-pdf text-[20px]" aria-hidden="true"></i>
                    <span class="max-w-[9rem] truncate">{{ f.name || 'Photo' }}</span><span class="text-muted">{{ fileSize(f.size) }}</span>
                  </a>
                </li>
              </ul>
            </div>
          </li>
        </ul>

        <form v-if="d.can.message" class="flex flex-col gap-2" novalidate @submit.prevent="send">
          <FormField v-model="body" type="textarea" label="Write a message" :id="`dispute-body-${d.id}`" maxlength="1000" :min-height="72" placeholder="Say what happened. Don’t share PINs or passwords." />
          <div class="flex flex-wrap items-center gap-2">
            <label class="wm-btn wm-btn-tint !min-h-11 cursor-pointer">
              <i class="bi bi-paperclip" aria-hidden="true"></i>Add photo or PDF
              <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" @change="pick" />
            </label>
            <span v-for="(f, i) in files" :key="`${f.name}-${i}`" class="inline-flex items-center gap-1 rounded-full bg-[#F1F5F9] px-2.5 py-1 text-[12.5px] text-ink">
              {{ f.name }}<button type="button" class="wm-icon-btn !min-h-6 !min-w-6" :aria-label="`Remove ${f.name}`" @click="files.splice(i, 1)"><i class="bi bi-x" aria-hidden="true"></i></button>
            </span>
          </div>
          <p v-if="fileError" class="wm-field-error" role="alert"><i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ fileError }}</p>
          <button type="submit" class="wm-btn wm-btn-primary !min-h-12 w-full" :disabled="busy || (!body.trim() && !files.length)">
            <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Sending…</template><template v-else>Send</template>
          </button>
        </form>
      </section>

      <!-- Ask WorkMate -->
      <section v-if="d.can.escalate" class="flex flex-col gap-2" aria-label="Ask WorkMate to step in">
        <button v-if="!escalating" type="button" class="wm-btn wm-btn-ghost !min-h-12 w-full" @click="escalating = true">Can’t agree? Ask WorkMate to step in</button>
        <form v-else class="flex flex-col gap-2 rounded-xl border border-line px-3.5 py-3" novalidate @submit.prevent="escalate">
          <p class="text-[13.5px] text-muted">WorkMate will read what you both wrote and sent, and may ask for more. You can still add to the case.</p>
          <label class="flex flex-col gap-1 text-[13.5px] font-medium text-ink">Why?
            <select v-model="reason" class="h-12 rounded-xl border border-line bg-white px-3 font-normal">
              <option value="">Choose…</option>
              <option v-for="r in ESCALATION_REASONS" :key="r.id" :value="r.id">{{ r.label }}</option>
            </select>
            <span v-if="escErrors.reason" class="wm-field-error" role="alert">{{ escErrors.reason }}</span>
          </label>
          <FormField v-model="note" type="textarea" label="In a few words" :id="`dispute-esc-${d.id}`" maxlength="500" :min-height="64" :error-message="escErrors.note" />
          <div class="flex gap-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="escalating = false">Back</button>
            <button type="submit" class="wm-btn wm-btn-primary !min-h-12 flex-[2]" :disabled="busy">Ask WorkMate</button>
          </div>
        </form>
      </section>

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>

      <details class="text-[13.5px]">
        <summary class="inline-flex min-h-9 cursor-pointer items-center font-medium text-muted">What happened, step by step</summary>
        <ol class="mt-1 flex flex-col gap-1.5 border-l border-line pl-3">
          <li v-for="(e, i) in d.timeline" :key="i"><span class="text-ink">{{ eventWords(e) }}</span> <span class="text-muted">· {{ shortDate(e.at) }}</span>
            <span v-if="e.note && ['escalated', 'decided', 'resolved', 'report_withdrawn'].includes(e.event)" class="block text-muted">“{{ noteText(e) }}”</span></li>
        </ol>
      </details>
    </div>
  </BottomSheet>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { api, upload } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { ugx, methodLabel, shortDate } from '@/utils/pricing.js';
import {
  CORRECTION_LABEL, correctionWords, reportStateWords, stageWords, AGREEMENTS, agreementLabel, ESCALATION_REASONS, resolutionWords, eventWords, fileProblem, fileSize, isImage, validateEscalation, hasErrors, MAX_FILES,
} from '@/utils/disputes.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  disputeId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'changed']);

const d = ref(null);
const loading = ref(false);
const loadError = ref('');
const busy = ref(false);
const error = ref('');
const body = ref('');
const files = ref([]);
const fileError = ref('');
const escalating = ref(false);
const reason = ref('');
const note = ref('');
const escErrors = reactive({ reason: '', note: '' });
let token = newClientToken();

const stage = computed(() => stageWords(d.value ? d.value.status : 'open'));
const noteText = (e) => String(e.note || '').replace(/^[a-z_]+: /, '');

async function load() {
  if (!props.disputeId) return;
  loading.value = !d.value;
  loadError.value = '';
  try {
    d.value = (await api(`/me/disputes/${props.disputeId}`)).data;
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.disputeId], ([isOpen]) => {
  if (isOpen) {
    d.value = null;
    error.value = '';
    escalating.value = false;
    load();
  }
}, { immediate: true });

/** Runs one action; the case comes back as the server now has it. One at a time. */
async function act(run) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    d.value = (await run()).data;
    emit('changed', d.value);
  } catch (e) {
    error.value = e.message;
    load();
  } finally {
    busy.value = false;
  }
}

const post = (path, payload) => api(`/me/disputes/${props.disputeId}/${path}`, { method: 'POST', body: payload });
const propose = (outcome) => act(() => post('proposal', { outcome }));
const accept = () => act(() => post('proposal/accept', { outcome: d.value.proposal.outcome }));
const decline = () => act(() => post('proposal/decline', {}));

function pick(event) {
  fileError.value = '';
  for (const f of Array.from(event.target.files || [])) {
    const problem = fileProblem(f);
    if (problem) fileError.value = problem;
    else if (files.value.length < MAX_FILES) files.value.push(f);
    else fileError.value = `Up to ${MAX_FILES} files at a time.`;
  }
  event.target.value = '';
}

async function send() {
  if (busy.value || (!body.value.trim() && !files.value.length)) return;
  const form = new FormData();
  if (body.value.trim()) form.append('body', body.value.trim());
  form.append('client_token', token);
  files.value.forEach((f) => form.append('files[]', f));
  await act(async () => {
    const result = await upload(`/me/disputes/${props.disputeId}/messages`, form);
    body.value = '';
    files.value = [];
    token = newClientToken();
    return result;
  });
}

async function escalate() {
  Object.assign(escErrors, validateEscalation(reason.value, note.value));
  if (hasErrors(escErrors)) return;
  await act(async () => {
    const result = await post('escalate', { reason: reason.value, note: note.value.trim() });
    escalating.value = false;
    return result;
  });
}
</script>
