<template>
  <BottomSheet :open="open" :title="title" full @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p v-if="mode === 'amend'" class="wm-notice wm-notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>The price already agreed ({{ ugx(req.pricing.agreed.amount) }}) stays until the customer accepts the new one.</span></p>
      <p v-else-if="changeAsked" class="wm-notice wm-notice-info"><i class="bi bi-chat-left-text" aria-hidden="true"></i>
        <span>The customer asked for a change<template v-if="req.pricing.open.response_note">: “{{ req.pricing.open.response_note }}”</template>.
          <template v-if="req.pricing.open.suggested_amount"> They would like about {{ ugx(req.pricing.open.suggested_amount) }}.</template></span></p>

      <FormField v-model="form.amount" type="number" inputmode="numeric" min="0" step="1000" label="Price" required prefix="UGX"
        placeholder="e.g. 150000" :error-message="errors.amount" />
      <FormField v-model="form.scope" type="textarea" label="What the price includes" required maxlength="2000" :min-height="110"
        placeholder="e.g. Replace the kitchen sink pipes and fit a new tap, including materials." :error-message="errors.scope" />
      <FormField v-model="form.exclusions" type="textarea" label="What is not included" optional maxlength="1000" :min-height="72"
        placeholder="e.g. Tiles and wall repairs." :error-message="errors.exclusions" />

      <p class="text-[12.5px] text-muted">The customer sees this and decides. Nothing is agreed, and nothing is charged, until they accept it.</p>
      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Sending…</template>
        <template v-else>Send price</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, reactive, computed, watch, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import { api, fieldErrors } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { ugx, blankProposal, validateProposal, hasErrors, proposalBody } from '@/utils/pricing.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  // The request as the received-requests API sent it (with its `pricing` block)
  req: { type: Object, required: true },
});
const emit = defineEmits(['close', 'updated']);

const formId = useId();
const form = reactive(blankProposal());
const errors = reactive({ amount: '', scope: '', exclusions: '' });
const error = ref('');
const busy = ref(false);
// One key per try: sending again after a dropped connection can't send it twice
let token = newClientToken();

// A first price, a new version of one still waiting, or an amendment to an agreed price
const mode = computed(() => (props.req.pricing && props.req.pricing.agreed ? 'amend' : props.req.pricing && props.req.pricing.open ? 'revise' : 'propose'));
const changeAsked = computed(() => props.req.pricing && props.req.pricing.open && props.req.pricing.open.status === 'revision_requested');
const title = computed(() => ({ propose: 'Propose a price', revise: 'Send a new price', amend: 'Change the agreed price' }[mode.value]));

watch(() => props.open, (open) => {
  if (!open) return;
  // Starts from what was proposed or agreed last, so changing one thing is quick
  const last = (props.req.pricing && (props.req.pricing.open || props.req.pricing.agreed)) || null;
  Object.assign(form, blankProposal(last && last.status !== 'expired' ? last.amount : ''), { scope: last ? last.scope || '' : '', exclusions: last ? last.exclusions || '' : '' });
  Object.assign(errors, { amount: '', scope: '', exclusions: '' });
  error.value = '';
  token = newClientToken();
});

function cancel() {
  if (!busy.value) emit('close');
}

async function submit() {
  if (busy.value) return;
  Object.assign(errors, validateProposal(form));
  if (hasErrors(errors)) return;
  busy.value = true;
  error.value = '';
  try {
    const res = await api(`/me/received-requests/${props.req.id}/agreements`, { method: 'POST', body: proposalBody(form, token) });
    emit('updated', res.data);
    emit('close');
  } catch (e) {
    const fields = fieldErrors(e);
    Object.assign(errors, { amount: fields.amount || '', scope: fields.scope || '', exclusions: fields.exclusions || '' });
    if (!Object.keys(fields).length) error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
