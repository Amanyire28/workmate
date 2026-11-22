<template>
  <BottomSheet :open="open" :title="corrects ? 'Report the right amount' : 'Report a payment'" @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="wm-notice wm-notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>Tell WorkMate about money the customer paid you directly. It counts as paid only once the customer confirms it.
          <template v-if="left !== null"> Left to report: {{ ugx(left) }}.</template></span></p>

      <FormField v-model="form.amount" type="number" inputmode="numeric" min="0" step="100" label="Amount the customer paid" required prefix="UGX"
        placeholder="e.g. 50000" :error-message="errors.amount" />

      <OptionCards v-model="form.method" :options="PAYMENT_METHODS" legend="How did they pay?" :columns="4" />
      <p v-if="errors.method" class="wm-field-error" role="alert"><i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ errors.method }}</p>

      <FormField v-model="form.paid_on" type="date" label="Date paid" optional :max="today" :error-message="errors.paid_on" />
      <FormField v-model="form.reference" label="Reference" optional maxlength="60" placeholder="e.g. the mobile money transaction ID"
        hint="Never type a PIN, password or full account number." :error-message="errors.reference" />
      <FormField v-model="form.note" type="textarea" label="Note" optional maxlength="300" :min-height="72" placeholder="e.g. Deposit before starting" :error-message="errors.note" />

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Sending…</template>
        <template v-else>Send report</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, reactive, watch, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import OptionCards from '@/components/pricing/OptionCards.vue';
import { api, fieldErrors } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { ugx, PAYMENT_METHODS, blankPayment, validatePayment, hasErrors, paymentBody, todayInKampala } from '@/utils/pricing.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  // The request as the received-requests API sent it
  req: { type: Object, required: true },
  // A report the provider took back, when this one corrects it
  corrects: { type: Object, default: null },
  // What can still be reported against the final price (null: no final price)
  left: { type: Number, default: null },
});
const emit = defineEmits(['close', 'updated']);

const formId = useId();
const form = reactive(blankPayment());
const errors = reactive({ amount: '', method: '', paid_on: '', reference: '', note: '' });
const error = ref('');
const busy = ref(false);
const today = todayInKampala();
// One key per try: sending again after a dropped connection can't report it twice
let token = newClientToken();

watch(() => props.open, (open) => {
  if (!open) return;
  // A correction starts from the report it replaces, so changing one thing is quick
  Object.assign(form, blankPayment(props.corrects ? props.corrects.amount : ''), props.corrects ? { method: props.corrects.method, paid_on: props.corrects.paid_on || '', reference: props.corrects.reference || '' } : {});
  Object.assign(errors, { amount: '', method: '', paid_on: '', reference: '', note: '' });
  error.value = '';
  token = newClientToken();
});

function cancel() {
  if (!busy.value) emit('close');
}

async function submit() {
  if (busy.value) return;
  Object.assign(errors, validatePayment(form));
  if (hasErrors(errors)) return;
  busy.value = true;
  error.value = '';
  try {
    const res = await api(`/me/received-requests/${props.req.id}/payments`, { method: 'POST', body: paymentBody(form, token, props.corrects ? props.corrects.id : null) });
    emit('updated', res.data);
    emit('close');
  } catch (e) {
    const fields = fieldErrors(e);
    Object.assign(errors, { amount: fields.amount || '', method: fields.method || '', paid_on: fields.paid_on || '', reference: fields.reference || '', note: fields.note || '' });
    if (!Object.keys(fields).length) error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
