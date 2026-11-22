<template>
  <!-- The customer's answer to a payment the provider reported: confirm it (the amount is on the button) or say it isn't right.
       Nothing is confirmed by waiting. -->
  <div v-if="waiting.length" class="flex flex-col gap-2">
    <div v-for="r in waiting" :key="r.id" class="flex flex-col gap-2 rounded-xl bg-primary-tint px-3.5 py-3">
      <p class="text-[14px] text-ink">
        <strong>{{ providerName }}</strong> says you paid <strong>{{ ugx(r.amount) }}</strong> by {{ methodLabel(r.method).toLowerCase() }}<template v-if="r.paid_on"> on {{ shortDate(r.paid_on) }}</template>.
        <span v-if="r.reference" class="block text-[13px] text-muted">Reference: {{ r.reference }}</span>
        <span v-if="r.note" class="block text-[13px] text-muted">“{{ r.note }}”</span>
      </p>

      <form v-if="disputing === r.id" class="flex flex-col gap-2" @submit.prevent="dispute(r)">
        <FormField v-model="note" type="textarea" label="What isn’t right?" optional :id="`dispute-note-${r.id}`" maxlength="300" :min-height="72"
          placeholder="e.g. I paid 30,000, not 50,000" hint="The provider sees this. Nothing is changed for you until you confirm a payment." />
        <div class="flex gap-2">
          <button type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="disputing = null">Back</button>
          <button type="submit" class="wm-btn wm-btn-primary !min-h-12 flex-1" :disabled="busy">Send</button>
        </div>
      </form>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-primary !min-h-12 w-full" :disabled="busy" @click="confirm(r)">
          <i class="bi bi-check2-circle" aria-hidden="true"></i>Confirm payment · {{ ugx(r.amount) }}
        </button>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-12 w-full" :disabled="busy" @click="start(r)">It isn’t right</button>
      </template>
    </div>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import FormField from '@/components/FormField.vue';
import { api } from '@/api/client.js';
import { ugx, methodLabel, shortDate, waitingReports, PAYMENT_STALE_CODES } from '@/utils/pricing.js';

const props = defineProps({
  // The request as the API sent it (a customer's own request, with its `payments` block)
  req: { type: Object, required: true },
  providerName: { type: String, default: 'The provider' },
});
const emit = defineEmits(['updated', 'refresh']);

const waiting = computed(() => waitingReports(props.req.payments));
const disputing = ref(null);
const note = ref('');
const busy = ref(false);
const error = ref('');

/** One answer at a time, however many taps; the server checks every one. A payment that changed meanwhile is shown again, never confirmed. */
async function answer(report, action, body) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('updated', (await api(`/me/requests/${props.req.id}/payments/${report.id}/${action}`, { method: 'POST', body })).data);
    disputing.value = null;
    note.value = '';
  } catch (e) {
    error.value = e.message;
    if (PAYMENT_STALE_CODES.includes(e.code)) {
      disputing.value = null;
      emit('refresh');
    }
  } finally {
    busy.value = false;
  }
}

// The amount on the button is what is sent, so the server can tell if the report moved on
const confirm = (report) => answer(report, 'confirm', { amount: report.amount });
const dispute = (report) => answer(report, 'dispute', { note: note.value.trim() || null });

function start(report) {
  disputing.value = report.id;
  note.value = '';
  error.value = '';
}
</script>
