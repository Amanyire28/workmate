<template>
  <BottomSheet :open="open" title="Report a commission payment" @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p class="wm-notice wm-notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>Tell WorkMate you paid your commission. It only counts after WorkMate checks the money arrived. Until then it is shown as awaiting verification.
          <template v-if="cycle"> Still to pay on this settlement: {{ ugx(cycle.outstanding_ugx) }}.</template></span></p>

      <div v-if="instructions" class="rounded-xl border border-line bg-white/70 px-3.5 py-3 text-[13.5px]">
        <p class="font-medium text-ink">How to pay WorkMate</p>
        <p class="mt-0.5 whitespace-pre-line text-muted">{{ instructions }}</p>
      </div>

      <FormField v-model="form.amount" type="number" inputmode="numeric" min="0" step="100" label="Amount you paid" required prefix="UGX" placeholder="e.g. 10000" :error-message="errors.amount" />

      <label class="flex flex-col gap-1 text-[14px] font-medium text-ink">How did you pay?
        <select v-model="form.channel" class="h-12 rounded-xl border border-line bg-white px-3 font-normal">
          <option value="">Choose…</option>
          <option v-for="c in channels" :key="c" :value="c">{{ channelLabel(c) }}</option>
        </select>
        <span v-if="errors.channel" class="wm-field-error" role="alert">{{ errors.channel }}</span>
      </label>

      <FormField v-model="form.reference" :label="form.channel === 'cash' ? 'Receipt number' : 'Transaction reference'" :optional="form.channel === 'cash'" maxlength="60"
        placeholder="e.g. the mobile money transaction ID" hint="Never type a PIN, password or full account number." :error-message="errors.reference" />
      <FormField v-model="form.paid_on" type="date" label="Date paid" optional :max="today" :error-message="errors.paid_on" />
      <FormField v-model="form.note" type="textarea" label="Note" optional maxlength="300" :min-height="64" />

      <div class="flex flex-col gap-1">
        <label class="wm-btn wm-btn-tint !min-h-11 w-fit cursor-pointer"><i class="bi bi-paperclip" aria-hidden="true"></i>{{ file ? 'Change proof' : 'Add a photo or PDF of the receipt' }}
          <input type="file" class="sr-only" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" @change="pick" /></label>
        <p v-if="file" class="text-[13px] text-muted">{{ file.name }} · {{ fileSize(file.size) }}</p>
        <p v-if="fileError" class="wm-field-error" role="alert"><i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ fileError }}</p>
      </div>

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Sending…</template><template v-else>Send report</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, reactive, watch, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import { upload } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { ugx } from '@/utils/pricing.js';
import { fileProblem, fileSize } from '@/utils/disputes.js';
import { blankReport, validateReport, hasErrors, reportBody, channelLabel } from '@/utils/settlement.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  cycle: { type: Object, default: null },
  channels: { type: Array, default: () => [] },
  instructions: { type: String, default: null },
});
const emit = defineEmits(['close', 'done']);

const formId = useId();
const form = reactive(blankReport());
const errors = reactive({ amount: '', channel: '', reference: '', paid_on: '' });
const file = ref(null);
const fileError = ref('');
const error = ref('');
const busy = ref(false);
const today = new Date().toISOString().slice(0, 10);
let token = newClientToken();

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    Object.assign(form, blankReport(), { amount: props.cycle ? String(props.cycle.outstanding_ugx) : '' });
    Object.assign(errors, { amount: '', channel: '', reference: '', paid_on: '' });
    file.value = null;
    fileError.value = '';
    error.value = '';
    token = newClientToken();
  }
});

function pick(event) {
  const f = event.target.files && event.target.files[0];
  event.target.value = '';
  fileError.value = '';
  if (!f) return;
  const problem = fileProblem(f);
  if (problem) fileError.value = problem;
  else file.value = f;
}

function cancel() {
  if (!busy.value) emit('close');
}

async function submit() {
  if (busy.value) return;
  Object.assign(errors, validateReport(form, props.channels, today));
  if (hasErrors(errors)) return;
  busy.value = true;
  error.value = '';
  try {
    const body = reportBody(form, props.cycle.id, token);
    const data = new FormData();
    Object.entries(body).forEach(([k, v]) => data.append(k, v));
    if (file.value) data.append('evidence', file.value);
    await upload('/me/commission/payments', data);
    emit('done');
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
